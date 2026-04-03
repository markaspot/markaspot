<?php

declare(strict_types=1);

namespace Drupal\markaspot_escalation\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\markaspot_escalation\Service\EscalationServiceInterface;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Form for delegating a service request to another organisation.
 */
class DelegateForm extends FormBase {

  /**
   * The escalation service.
   *
   * @var \Drupal\markaspot_escalation\Service\EscalationServiceInterface
   */
  protected EscalationServiceInterface $escalationService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The GeoReport processor service.
   *
   * @var \Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface
   */
  protected GeoreportProcessorServiceInterface $processor;

  /**
   * The service request node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected NodeInterface $node;

  /**
   * Constructs a DelegateForm.
   *
   * @param \Drupal\markaspot_escalation\Service\EscalationServiceInterface $escalationService
   *   The escalation service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface $processor
   *   The GeoReport processor service.
   */
  public function __construct(
    EscalationServiceInterface $escalationService,
    EntityTypeManagerInterface $entityTypeManager,
    GeoreportProcessorServiceInterface $processor,
  ) {
    $this->escalationService = $escalationService;
    $this->entityTypeManager = $entityTypeManager;
    $this->processor = $processor;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_escalation.service'),
      $container->get('entity_type.manager'),
      $container->get('markaspot_open311.processor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_escalation_delegate';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node === NULL) {
      throw new NotFoundHttpException();
    }
    $this->node = $node;

    // Verify jurisdiction membership before allowing delegation.
    if (!$this->escalationService->canDelegate($node, $this->currentUser())) {
      throw new AccessDeniedHttpException();
    }

    $form['#title'] = $this->t('Delegate service request %title', [
      '%title' => $node->getTitle(),
    ]);

    // Resolve the node's effective jurisdiction to determine valid orgs.
    $nodeJurId = $this->getEffectiveNodeJurisdictionId($node);

    $options = [];
    if ($nodeJurId !== NULL) {
      $options = $this->buildOrganisationOptions($nodeJurId);
    }

    if (empty($options)) {
      $this->messenger()->addWarning($this->t('No organisations are available for delegation within this jurisdiction.'));
    }

    // Exclude the current organisation from the options.
    $currentOrgId = NULL;
    if ($node->hasField('field_organisation') && !$node->get('field_organisation')->isEmpty()) {
      $currentOrgId = (int) $node->get('field_organisation')->target_id;
      unset($options[$currentOrgId]);
    }

    $form['target_organisation'] = [
      '#type' => 'select',
      '#title' => $this->t('Target organisation'),
      '#description' => $this->t('Select the organisation to delegate this request to.'),
      '#options' => $options,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
    ];

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Delegation notes'),
      '#description' => $this->t('Optionally provide a reason for the delegation. This will be added as an internal remark.'),
      '#maxlength' => 2000,
      '#attributes' => [
        'maxlength' => 2000,
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delegate'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $node->toUrl(),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $notes = trim((string) $form_state->getValue('notes'));
    if (mb_strlen($notes) > 2000) {
      $form_state->setErrorByName('notes', $this->t('Delegation notes must not exceed 2000 characters.'));
    }

    // Validate target org exists and is within scope.
    $targetOrgId = (int) $form_state->getValue('target_organisation');
    if ($targetOrgId > 0) {
      $targetOrg = $this->entityTypeManager->getStorage('group')->load($targetOrgId);
      if (!$targetOrg || $targetOrg->bundle() !== 'org') {
        $form_state->setErrorByName('target_organisation', $this->t('Invalid target organisation.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $targetOrgId = (int) $form_state->getValue('target_organisation');
    $notes = trim(strip_tags((string) $form_state->getValue('notes')));

    try {
      $this->escalationService->delegateRequest($this->node, $targetOrgId, $notes);
      $targetOrg = $this->entityTypeManager->getStorage('group')->load($targetOrgId);
      $this->messenger()->addStatus($this->t('Service request %title has been delegated to %org.', [
        '%title' => $this->node->getTitle(),
        '%org' => $targetOrg?->label() ?? $targetOrgId,
      ]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Delegation failed: @message', [
        '@message' => $e->getMessage(),
      ]));
    }

    $form_state->setRedirectUrl($this->node->toUrl());
  }

  /**
   * Gets the effective jurisdiction ID for a node.
   *
   * Escalated nodes use field_escalation; non-escalated nodes fall back
   * to the processor-based jurisdiction lookup.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return int|null
   *   The jurisdiction group ID, or NULL if not determined.
   */
  protected function getEffectiveNodeJurisdictionId(NodeInterface $node): ?int {
    if ($node->hasField('field_escalation') && !$node->get('field_escalation')->isEmpty()) {
      return (int) $node->get('field_escalation')->target_id;
    }
    return $this->processor->getJurisdictionIdFromNode($node);
  }

  /**
   * Builds the organisation options for the select element.
   *
   * Returns all org groups whose field_jurisdiction matches the given
   * jurisdiction or any of its child jurisdictions. This mirrors the
   * scope validation in EscalationController::validateDelegationScope().
   *
   * @param int $nodeJurId
   *   The node's effective jurisdiction group ID.
   *
   * @return array
   *   An associative array of org group ID => label.
   */
  protected function buildOrganisationOptions(int $nodeJurId): array {
    $groupStorage = $this->entityTypeManager->getStorage('group');

    // Collect the jurisdiction and all its child jurisdictions.
    $jurIds = [$nodeJurId];
    $this->collectChildJurisdictions($nodeJurId, $jurIds);

    // Load all org groups that belong to any of these jurisdictions.
    $options = [];
    $orgGroups = $groupStorage->loadByProperties(['type' => 'org']);
    foreach ($orgGroups as $orgGroup) {
      if (!$orgGroup->hasField('field_jurisdiction') || $orgGroup->get('field_jurisdiction')->isEmpty()) {
        continue;
      }
      $orgJurId = (int) $orgGroup->get('field_jurisdiction')->target_id;
      if (in_array($orgJurId, $jurIds, TRUE)) {
        $options[(int) $orgGroup->id()] = $orgGroup->label();
      }
    }

    asort($options);
    return $options;
  }

  /**
   * Recursively collects child jurisdiction IDs.
   *
   * @param int $parentJurId
   *   The parent jurisdiction group ID.
   * @param array $jurIds
   *   Reference to the array collecting jurisdiction IDs.
   * @param int $maxDepth
   *   Maximum recursion depth to prevent infinite loops.
   */
  protected function collectChildJurisdictions(int $parentJurId, array &$jurIds, int $maxDepth = 10): void {
    if ($maxDepth <= 0) {
      return;
    }

    $groupStorage = $this->entityTypeManager->getStorage('group');
    $children = $groupStorage->loadByProperties([
      'type' => 'jur',
      'field_parent_jurisdiction' => $parentJurId,
    ]);

    foreach ($children as $child) {
      $childId = (int) $child->id();
      if (!in_array($childId, $jurIds, TRUE)) {
        $jurIds[] = $childId;
        $this->collectChildJurisdictions($childId, $jurIds, $maxDepth - 1);
      }
    }
  }

}
