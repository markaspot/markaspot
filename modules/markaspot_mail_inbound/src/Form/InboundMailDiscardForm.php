<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Service\InboundMailPromoter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form to discard a staged inbound mail.
 *
 * Discarding sets state=discarded and deletes the persisted attachment files.
 * The record itself is kept for audit (the operator can see what was rejected).
 *
 * @phpstan-consistent-constructor
 */
class InboundMailDiscardForm extends ConfirmFormBase {

  /**
   * The mail being discarded.
   */
  protected ?InboundMail $mail = NULL;

  /**
   * Constructs the discard form.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected InboundMailPromoter $promoter,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('markaspot_mail_inbound.promoter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_mail_inbound_discard_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?InboundMail $inbound_mail = NULL): array {
    $this->mail = $inbound_mail;
    if ($inbound_mail === NULL || $inbound_mail->getState() !== InboundMail::STATE_STAGED) {
      $this->messenger()->addError($this->t('This inbound mail cannot be discarded (it is not staged).'));
      $form['#access'] = FALSE;
      return $form;
    }
    $form_state->set('inbound_mail_id', $inbound_mail->id());
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): \Stringable {
    $subject = $this->mail?->getSubject() ?: $this->t('(no subject)');
    return $this->t('Discard the inbound mail "@subject"?', ['@subject' => $subject]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): \Stringable {
    return $this->t('The mail is marked as discarded and its attachments are deleted. This does not create a service request.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): \Stringable {
    return $this->t('Discard');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.inbound_mail.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $mailId = $form_state->get('inbound_mail_id');
    /** @var \Drupal\markaspot_mail_inbound\Entity\InboundMail|null $mail */
    $mail = $mailId ? $this->entityTypeManager->getStorage('inbound_mail')->load($mailId) : NULL;
    if ($mail === NULL) {
      $this->messenger()->addError($this->t('The inbound mail no longer exists.'));
      $form_state->setRedirect('entity.inbound_mail.collection');
      return;
    }
    $this->promoter->discard($mail);
    $this->messenger()->addStatus($this->t('Inbound mail discarded.'));
    $form_state->setRedirect('entity.inbound_mail.collection');
  }

}
