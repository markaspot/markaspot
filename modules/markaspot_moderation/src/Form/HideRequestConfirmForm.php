<?php

declare(strict_types=1);

namespace Drupal\markaspot_moderation\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\markaspot_group\Service\TenantAdminHelper;
use Drupal\markaspot_moderation\Service\ModerationServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Confirmation form for hiding (unpublishing) a flagged service request.
 */
class HideRequestConfirmForm extends ConfirmFormBase {

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
   * The node ID.
   *
   * @var int
   */
  protected int $nid;

  /**
   * Constructs a HideRequestConfirmForm.
   *
   * @param \Drupal\markaspot_moderation\Service\ModerationServiceInterface $moderationService
   *   The moderation service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    ModerationServiceInterface $moderationService,
    EntityTypeManagerInterface $entityTypeManager,
    AccountInterface $currentUser,
  ) {
    $this->moderationService = $moderationService;
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_moderation.service'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_moderation_hide_request_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $node = $this->entityTypeManager->getStorage('node')->load($this->nid);
    $title = $node ? $node->getTitle() : (string) $this->nid;
    return $this->t('Hide (unpublish) request %title?', ['%title' => $title]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will unpublish the request and dismiss all active flags. The request will no longer be visible to the public.');
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
    $this->assertJurisdictionAccess($this->nid);
    return parent::buildForm($form, $form_state);
  }

  /**
   * Verifies the current user has jurisdiction access for the given node.
   *
   * @param int $nid
   *   The node ID to check.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the user does not have jurisdiction access.
   */
  protected function assertJurisdictionAccess(int $nid): void {
    if ($this->currentUser->hasPermission('administer all flags')) {
      return;
    }

    $userJurIds = TenantAdminHelper::getUserJurisdictionIds($this->currentUser);
    $flags = $this->moderationService->getFlagsForRequest($nid);
    if (empty($flags)) {
      return;
    }

    $flagJurId = (int) ($flags[0]['jurisdiction_id'] ?? 0);
    if (!in_array($flagJurId, $userJurIds, TRUE)) {
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->moderationService->hideRequest($this->nid, (int) $this->currentUser->id());

    $this->messenger()->addStatus($this->t('The request has been hidden.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
