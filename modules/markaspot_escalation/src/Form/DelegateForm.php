<?php

declare(strict_types=1);

namespace Drupal\markaspot_escalation\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\markaspot_escalation\Service\EscalationServiceInterface;
use Drupal\markaspot_group\Service\OrgHierarchyResolverInterface;
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
   * The organisation hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\OrgHierarchyResolverInterface
   */
  protected OrgHierarchyResolverInterface $orgHierarchyResolver;

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\markaspot_group\Service\OrgHierarchyResolverInterface $orgHierarchyResolver
   *   The organisation hierarchy resolver.
   */
  public function __construct(
    EscalationServiceInterface $escalationService,
    EntityTypeManagerInterface $entityTypeManager,
    GeoreportProcessorServiceInterface $processor,
    ConfigFactoryInterface $configFactory,
    OrgHierarchyResolverInterface $orgHierarchyResolver,
  ) {
    $this->escalationService = $escalationService;
    $this->entityTypeManager = $entityTypeManager;
    $this->processor = $processor;
    $this->configFactory = $configFactory;
    $this->orgHierarchyResolver = $orgHierarchyResolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_escalation.service'),
      $container->get('entity_type.manager'),
      $container->get('markaspot_open311.processor'),
      $container->get('config.factory'),
      $container->get('markaspot_group.org_hierarchy_resolver'),
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

    $currentOrgIds = $this->getCurrentOrganisationIds($node);
    $currentParentOrgId = $this->getCurrentParentOrganisationId($node);

    $options = [];
    if ($nodeJurId !== NULL) {
      $options = $this->buildOrganisationOptions($nodeJurId, $currentParentOrgId, $currentOrgIds);
    }

    if (empty($options)) {
      $this->messenger()->addWarning($this->t('No organisations are available for delegation within this jurisdiction.'));
    }

    $form['target_organisation'] = [
      '#type' => 'select',
      '#title' => $this->t('Target organisation'),
      '#description' => $this->t('Select the organisation to delegate this request to.'),
      '#options' => $options,
      '#required' => !empty($options),
      '#disabled' => empty($options),
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
      '#access' => !empty($options),
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
      if (!$targetOrg || $targetOrg->bundle() !== 'org' || !$targetOrg->isPublished()) {
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
   * @param int|null $currentParentOrgId
   *   The current organisation's nearest parent org ID.
   * @param int[] $excludedOrgIds
   *   Organisation IDs to exclude from the selectable options.
   *
   * @return array
   *   A grouped associative array of org group ID => label options.
   */
  protected function buildOrganisationOptions(
    int $nodeJurId,
    ?int $currentParentOrgId = NULL,
    array $excludedOrgIds = [],
  ): array {
    $groupStorage = $this->entityTypeManager->getStorage('group');

    // Collect the jurisdiction and all its child jurisdictions.
    $jurIds = [$nodeJurId];
    $this->collectChildJurisdictions($nodeJurId, $jurIds);

    // Load all org groups that belong to any of these jurisdictions.
    $orgGroupsById = [];
    $orgGroups = $groupStorage->loadByProperties([
      'type' => 'org',
      'status' => 1,
    ]);
    foreach ($orgGroups as $orgGroup) {
      if (!$orgGroup->hasField('field_jurisdiction') || $orgGroup->get('field_jurisdiction')->isEmpty()) {
        continue;
      }
      $orgJurId = (int) $orgGroup->get('field_jurisdiction')->target_id;
      if (in_array($orgJurId, $jurIds, TRUE)) {
        $orgGroupsById[(int) $orgGroup->id()] = $orgGroup;
      }
    }

    if (empty($orgGroupsById)) {
      return [];
    }

    uasort($orgGroupsById, static function ($a, $b): int {
      $comparison = strnatcasecmp($a->label(), $b->label());
      return $comparison !== 0 ? $comparison : ((int) $a->id() <=> (int) $b->id());
    });

    $rootIds = [];
    foreach (array_keys($orgGroupsById) as $orgId) {
      // Re-root the visible tree at its highest active ancestor. This keeps
      // active descendants selectable and human-readable when an ancestor was
      // deactivated.
      $activeAncestorIds = array_values(array_filter(
        $this->orgHierarchyResolver->getAncestorIds($orgId),
        static fn(int $ancestorId): bool => isset($orgGroupsById[$ancestorId]),
      ));
      $rootId = $activeAncestorIds === []
        ? $orgId
        : (int) end($activeAncestorIds);
      $rootIds[$rootId] = $rootId;
    }

    uasort($rootIds, function (int $a, int $b) use ($orgGroupsById): int {
      $labelA = isset($orgGroupsById[$a]) ? $orgGroupsById[$a]->label() : (string) $a;
      $labelB = isset($orgGroupsById[$b]) ? $orgGroupsById[$b]->label() : (string) $b;
      $comparison = strnatcasecmp($labelA, $labelB);
      return $comparison !== 0 ? $comparison : ($a <=> $b);
    });

    $options = [];
    $optionGroupLabels = [];
    $usedOrgIds = [];
    foreach ($rootIds as $rootId) {
      $treeOptions = $this->buildOrganisationTreeOptions(
        $rootId,
        $orgGroupsById,
        $currentParentOrgId,
        $excludedOrgIds,
        $usedOrgIds,
      );

      if (!empty($treeOptions)) {
        $rootLabel = isset($orgGroupsById[$rootId])
          ? $orgGroupsById[$rootId]->label()
          : (string) $this->t('Organisation tree @id', ['@id' => $rootId]);
        $optionGroupLabel = $this->buildUniqueOptionGroupLabel(
          $rootLabel,
          $rootId,
          $optionGroupLabels,
        );
        $options[$optionGroupLabel] = $treeOptions;
      }
    }

    $remainingOrgIds = array_diff(array_keys($orgGroupsById), $usedOrgIds);
    if (!empty($remainingOrgIds)) {
      $remainingOptions = [];
      foreach ($remainingOrgIds as $orgId) {
        if (in_array($orgId, $excludedOrgIds, TRUE)) {
          continue;
        }
        $remainingOptions[$orgId] = $this->formatOrganisationOptionLabel(
          $orgGroupsById[$orgId],
          $currentParentOrgId,
          $orgGroupsById,
        );
      }
      if (!empty($remainingOptions)) {
        $otherLabel = (string) $this->t('Other organisations');
        $optionGroupLabel = $this->buildUniqueOptionGroupLabel(
          $otherLabel,
          'other',
          $optionGroupLabels,
        );
        $options[$optionGroupLabel] = $remainingOptions;
      }
    }

    return $options;
  }

  /**
   * Builds options for one organisation tree.
   *
   * @param int $rootId
   *   The root organisation group ID.
   * @param array $orgGroupsById
   *   Eligible organisation groups keyed by group ID.
   * @param int|null $currentParentOrgId
   *   The current organisation's nearest parent org ID.
   * @param int[] $excludedOrgIds
   *   Organisation IDs to exclude from the selectable options.
   * @param int[] $usedOrgIds
   *   Reference collecting organisation IDs covered by a tree.
   *
   * @return array
   *   Select options for the tree.
   */
  protected function buildOrganisationTreeOptions(
    int $rootId,
    array $orgGroupsById,
    ?int $currentParentOrgId,
    array $excludedOrgIds,
    array &$usedOrgIds,
  ): array {
    $descendantIds = $this->orgHierarchyResolver->getDescendantIds($rootId);
    if (empty($descendantIds)) {
      $descendantIds = [$rootId];
    }

    $treeOptions = [];
    foreach ($descendantIds as $orgId) {
      $orgId = (int) $orgId;
      if (!isset($orgGroupsById[$orgId])) {
        continue;
      }

      $usedOrgIds[] = $orgId;
      if (in_array($orgId, $excludedOrgIds, TRUE)) {
        continue;
      }

      $treeOptions[$orgId] = $this->formatOrganisationOptionLabel(
        $orgGroupsById[$orgId],
        $currentParentOrgId,
        $orgGroupsById,
      );
    }

    return $treeOptions;
  }

  /**
   * Builds a unique option group label.
   *
   * @param string $label
   *   The preferred option group label.
   * @param int|string $identifier
   *   A stable identifier appended when the label already exists.
   * @param array $usedLabels
   *   Option group labels already used, keyed by label.
   *
   * @return string
   *   A unique option group label.
   */
  protected function buildUniqueOptionGroupLabel(
    string $label,
    int|string $identifier,
    array &$usedLabels,
  ): string {
    $candidate = $label;
    if (isset($usedLabels[$candidate])) {
      $candidate = (string) $this->t('@label (#@identifier)', [
        '@label' => $label,
        '@identifier' => (string) $identifier,
      ]);
    }

    $suffix = 2;
    while (isset($usedLabels[$candidate])) {
      $candidate = (string) $this->t('@label (#@identifier, @suffix)', [
        '@label' => $label,
        '@identifier' => (string) $identifier,
        '@suffix' => $suffix++,
      ]);
    }

    $usedLabels[$candidate] = TRUE;
    return $candidate;
  }

  /**
   * Formats an organisation option label with hierarchy indentation.
   *
   * @param object $orgGroup
   *   The organisation group entity.
   * @param int|null $currentParentOrgId
   *   The current organisation's nearest parent org ID.
   * @param array $orgGroupsById
   *   Eligible organisation groups keyed by group ID.
   *
   * @return string
   *   The formatted option label.
   */
  protected function formatOrganisationOptionLabel(
    object $orgGroup,
    ?int $currentParentOrgId,
    array $orgGroupsById,
  ): string {
    $ancestorIds = $this->orgHierarchyResolver->getAncestorIds((int) $orgGroup->id());
    $labelParts = [];
    foreach (array_reverse($ancestorIds) as $ancestorId) {
      if (isset($orgGroupsById[(int) $ancestorId])) {
        $labelParts[] = $orgGroupsById[(int) $ancestorId]->label();
      }
    }
    $visibleDepth = count($labelParts);
    $labelParts[] = $orgGroup->label();
    $label = str_repeat('  ', $visibleDepth) . implode(' > ', $labelParts);

    if ($currentParentOrgId !== NULL && (int) $orgGroup->id() === $currentParentOrgId) {
      $label .= ' ' . (string) $this->t('(übergeordnet)');
    }

    return $label;
  }

  /**
   * Gets the currently assigned organisation IDs.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return int[]
   *   The current organisation IDs.
   */
  protected function getCurrentOrganisationIds(NodeInterface $node): array {
    if (!$node->hasField('field_organisation') || $node->get('field_organisation')->isEmpty()) {
      return [];
    }

    return array_map('intval', array_column($node->get('field_organisation')->getValue(), 'target_id'));
  }

  /**
   * Gets the nearest parent of the current organisation.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return int|null
   *   The nearest parent organisation ID, or NULL.
   */
  protected function getCurrentParentOrganisationId(NodeInterface $node): ?int {
    if (!$node->hasField('field_organisation') || $node->get('field_organisation')->isEmpty()) {
      return NULL;
    }

    foreach ($node->get('field_organisation')->referencedEntities() as $orgGroup) {
      if (!is_object($orgGroup) || !method_exists($orgGroup, 'bundle') || $orgGroup->bundle() !== 'org') {
        continue;
      }

      $ancestorIds = $this->orgHierarchyResolver->getAncestorIds((int) $orgGroup->id());
      if (!empty($ancestorIds)) {
        return (int) reset($ancestorIds);
      }
    }

    return NULL;
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
      'type' => $this->jurisdictionGroupType(),
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

  /**
   * Gets the configured jurisdiction group type.
   */
  protected function jurisdictionGroupType(): string {
    $configured = $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type');

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

}
