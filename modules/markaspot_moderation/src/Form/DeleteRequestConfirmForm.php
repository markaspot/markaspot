<?php

declare(strict_types=1);

namespace Drupal\markaspot_moderation\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\markaspot_moderation\Service\ModerationServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for permanently deleting a flagged service request.
 *
 * This action is restricted to users with the 'administer all flags'
 * permission as it permanently removes the node entity.
 */
class DeleteRequestConfirmForm extends ConfirmFormBase {

  /**
   * The moderation service.
   *
   * @var \Drupal\markaspot_moderation\Service\ModerationServiceInterface
   */
  protected ModerationServiceInterface $moderationService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The node ID.
   *
   * @var int
   */
  protected int $nid;

  /**
   * Constructs a DeleteRequestConfirmForm.
   *
   * @param \Drupal\markaspot_moderation\Service\ModerationServiceInterface $moderationService
   *   The moderation service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    ModerationServiceInterface $moderationService,
    EntityTypeManagerInterface $entityTypeManager,
    AccountInterface $currentUser,
    LoggerInterface $logger,
  ) {
    $this->moderationService = $moderationService;
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_moderation.service'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('logger.channel.markaspot_moderation'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_moderation_delete_request_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $node = $this->entityTypeManager->getStorage('node')->load($this->nid);
    $title = $node ? $node->getTitle() : (string) $this->nid;
    return $this->t('Permanently delete request %title?', ['%title' => $title]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will permanently delete the service request and dismiss all associated flags. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('markaspot_moderation.admin');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $nid = NULL): array {
    $this->nid = $nid ?? 0;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $node = $nodeStorage->load($this->nid);

    if (!$node) {
      $this->messenger()->addError($this->t('The request could not be found.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    if ($node->bundle() !== 'service_request') {
      $this->messenger()->addError($this->t('Only service requests can be deleted through moderation.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    // Dismiss flags before deleting the node entity.
    $this->moderationService->dismissFlags($this->nid, (int) $this->currentUser->id());

    $title = $node->getTitle();
    $node->delete();

    $this->logger->notice('Service request nid=@nid (%title) deleted by uid=@uid via moderation admin.', [
      '@nid' => $this->nid,
      '%title' => $title,
      '@uid' => $this->currentUser->id(),
    ]);

    $this->messenger()->addStatus($this->t('The request %title has been deleted.', ['%title' => $title]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
