<?php

declare(strict_types=1);

namespace Drupal\markaspot_escalation\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\markaspot_escalation\Service\EscalationServiceInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation form for escalating a service request.
 */
class EscalateConfirmForm extends ConfirmFormBase {

  /**
   * The escalation service.
   *
   * @var \Drupal\markaspot_escalation\Service\EscalationServiceInterface
   */
  protected EscalationServiceInterface $escalationService;

  /**
   * The service request node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected NodeInterface $node;

  /**
   * The resolved target jurisdiction group ID.
   *
   * @var int|null
   */
  protected ?int $targetJurId = NULL;

  /**
   * The resolved target jurisdiction label.
   *
   * @var string|null
   */
  protected ?string $targetLabel = NULL;

  /**
   * Constructs an EscalateConfirmForm.
   *
   * @param \Drupal\markaspot_escalation\Service\EscalationServiceInterface $escalationService
   *   The escalation service.
   */
  public function __construct(EscalationServiceInterface $escalationService) {
    $this->escalationService = $escalationService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_escalation.service'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_escalation_escalate_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return (string) $this->t('Escalate service request %title?', [
      '%title' => $this->node->getTitle(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    if ($this->targetLabel !== NULL) {
      return (string) $this->t('This will escalate the request to jurisdiction %target. This action cannot be undone.', [
        '%target' => $this->targetLabel,
      ]);
    }
    return (string) $this->t('No escalation target could be determined for this request.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->node->toUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return (string) $this->t('Escalate');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node === NULL) {
      throw new NotFoundHttpException();
    }
    $this->node = $node;

    // Verify jurisdiction membership before allowing escalation.
    if (!$this->escalationService->canEscalate($node, $this->currentUser())) {
      throw new AccessDeniedHttpException();
    }

    // Resolve the escalation target.
    $this->targetJurId = $this->escalationService->resolveEscalationTarget($node);
    if ($this->targetJurId !== NULL) {
      $groupStorage = $this->entityTypeManager()->getStorage('group');
      $targetGroup = $groupStorage->load($this->targetJurId);
      $this->targetLabel = $targetGroup?->label();
    }

    $form = parent::buildForm($form, $form_state);

    // If no target, disable the submit button and show an error.
    if ($this->targetJurId === NULL) {
      $this->messenger()->addError($this->t('No escalation target could be determined for this request. The request may already be at the top of the jurisdiction hierarchy.'));
      $form['actions']['submit']['#disabled'] = TRUE;
      return $form;
    }

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Escalation notes'),
      '#description' => $this->t('Provide a reason for the escalation. This will be added as an internal remark.'),
      '#required' => TRUE,
      '#maxlength' => 2000,
      '#attributes' => [
        'maxlength' => 2000,
      ],
      '#weight' => -10,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $notes = trim((string) $form_state->getValue('notes'));
    if (mb_strlen($notes) > 2000) {
      $form_state->setErrorByName('notes', $this->t('Escalation notes must not exceed 2000 characters.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $notes = trim(strip_tags((string) $form_state->getValue('notes')));

    try {
      $this->escalationService->escalateRequest($this->node, $this->targetJurId, $notes);
      $this->messenger()->addStatus($this->t('Service request %title has been escalated to %target.', [
        '%title' => $this->node->getTitle(),
        '%target' => $this->targetLabel,
      ]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Escalation failed: @message', [
        '@message' => $e->getMessage(),
      ]));
    }

    $form_state->setRedirectUrl($this->node->toUrl());
  }

}
