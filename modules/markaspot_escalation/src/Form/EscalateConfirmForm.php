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
   * The resolved target group ID.
   *
   * @var int|null
   */
  protected ?int $targetGroupId = NULL;

  /**
   * The resolved target label.
   *
   * @var string|null
   */
  protected ?string $targetLabel = NULL;

  /**
   * Whether the resolved target is an organisation group.
   *
   * @var bool
   */
  protected bool $targetIsOrganisation = FALSE;

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
    if ($this->targetIsOrganisation) {
      return (string) $this->t('Delegate service request %title to parent organisation?', [
        '%title' => $this->node->getTitle(),
      ]);
    }

    return (string) $this->t('Escalate service request %title?', [
      '%title' => $this->node->getTitle(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    if ($this->targetLabel !== NULL) {
      if ($this->targetIsOrganisation) {
        return (string) $this->t('This will delegate the request to parent organisation %target. This action cannot be undone.', [
          '%target' => $this->targetLabel,
        ]);
      }

      return (string) $this->t('This will escalate the request to %target. This action cannot be undone.', [
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
    if ($this->targetIsOrganisation) {
      return (string) $this->t('Delegate');
    }

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
    $this->targetGroupId = $this->escalationService->resolveEscalationTarget($node);
    if ($this->targetGroupId !== NULL) {
      $groupStorage = $this->entityTypeManager()->getStorage('group');
      $targetGroup = $groupStorage->load($this->targetGroupId);
      $this->targetLabel = $targetGroup?->label();
      $this->targetIsOrganisation = $targetGroup?->bundle() === 'org';
    }

    $form = parent::buildForm($form, $form_state);

    // If no target, disable the submit button and show an error.
    if ($this->targetGroupId === NULL) {
      $this->messenger()->addError($this->t('No escalation target could be determined for this request. The request may already be at the top of the jurisdiction hierarchy.'));
      $form['actions']['submit']['#disabled'] = TRUE;
      return $form;
    }

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->targetIsOrganisation
        ? $this->t('Delegation notes')
        : $this->t('Escalation notes'),
      '#description' => $this->targetIsOrganisation
        ? $this->t('Provide a reason for the delegation. This will be added as an internal remark.')
        : $this->t('Provide a reason for the escalation. This will be added as an internal remark.'),
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
      $message = $this->targetIsOrganisation
        ? $this->t('Delegation notes must not exceed 2000 characters.')
        : $this->t('Escalation notes must not exceed 2000 characters.');
      $form_state->setErrorByName('notes', $message);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $notes = trim(strip_tags((string) $form_state->getValue('notes')));

    if ($this->targetGroupId === NULL) {
      $this->messenger()->addError($this->t('No escalation target could be determined for this request.'));
      $form_state->setRedirectUrl($this->node->toUrl());
      return;
    }

    try {
      $this->escalationService->escalateRequest($this->node, $this->targetGroupId, $notes);
      if ($this->targetIsOrganisation) {
        $this->messenger()->addStatus($this->t('Service request %title has been delegated to parent organisation %target.', [
          '%title' => $this->node->getTitle(),
          '%target' => $this->targetLabel,
        ]));
      }
      else {
        $this->messenger()->addStatus($this->t('Service request %title has been escalated to %target.', [
          '%title' => $this->node->getTitle(),
          '%target' => $this->targetLabel,
        ]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Escalation failed: @message', [
        '@message' => $e->getMessage(),
      ]));
    }

    $form_state->setRedirectUrl($this->node->toUrl());
  }

}
