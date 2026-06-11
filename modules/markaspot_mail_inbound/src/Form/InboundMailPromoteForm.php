<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Service\InboundMailPromoter;
use Drupal\markaspot_mail_inbound\Service\PromotableCategoryRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Categorize-and-promote form for a staged inbound mail.
 *
 * Shows the mail (read-only) and a category select scoped to the mail's
 * jurisdiction. Submitting promotes the mail to a service request through the
 * Open311 processor. Functional Phase 1 form; dashboard polish is later.
 *
 * @phpstan-consistent-constructor
 */
class InboundMailPromoteForm extends FormBase {

  /**
   * Constructs the promote form.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected InboundMailPromoter $promoter,
    protected PromotableCategoryRepository $categoryRepository,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('markaspot_mail_inbound.promoter'),
      $container->get('markaspot_mail_inbound.category_repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_mail_inbound_promote_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?InboundMail $inbound_mail = NULL): array {
    if ($inbound_mail === NULL || $inbound_mail->getState() !== InboundMail::STATE_STAGED) {
      $this->messenger()->addError($this->t('This inbound mail cannot be promoted (it is not staged).'));
      $form['#access'] = FALSE;
      return $form;
    }

    $form_state->set('inbound_mail_id', $inbound_mail->id());

    $form['preview'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Email'),
      'from' => [
        '#type' => 'item',
        '#title' => $this->t('From'),
        '#markup' => '<code>' . $this->escape($inbound_mail->getFromAddress()) . '</code>',
      ],
      'subject' => [
        '#type' => 'item',
        '#title' => $this->t('Subject'),
        '#plain_text' => $inbound_mail->getSubject() !== '' ? $inbound_mail->getSubject() : $this->t('(no subject)'),
      ],
      'body' => [
        '#type' => 'item',
        '#title' => $this->t('Body'),
        '#markup' => '<pre style="white-space:pre-wrap;max-height:20em;overflow:auto">' . $this->escape($inbound_mail->getBody()) . '</pre>',
      ],
    ];

    $options = $this->categoryOptions($inbound_mail->getJurisdictionId());
    if ($options === []) {
      $this->messenger()->addWarning($this->t('No service categories are available for this jurisdiction. Configure service categories before promoting.'));
    }

    $form['category_tid'] = [
      '#type' => 'select',
      '#title' => $this->t('Category'),
      '#description' => $this->t('The category determines the report type. This is the promotion gate; location and remaining detail are completed in moderation.'),
      '#options' => $options,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select a category -'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Promote to service request'),
        '#disabled' => $options === [],
      ],
      'cancel' => [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => Url::fromRoute('entity.inbound_mail.collection'),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $form;
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

    $categoryTid = (int) $form_state->getValue('category_tid');
    try {
      $node = $this->promoter->promoteToServiceRequest($mail, $categoryTid);
      $this->messenger()->addStatus($this->t('Promoted to service request @nid (unpublished, awaiting moderation).', ['@nid' => $node->id()]));
    }
    catch (\Throwable $e) {
      // The exception message can carry internal entity ids and module names
      // (review LOW 9): log the detail for operators, show the moderator a
      // generic, non-disclosing message.
      $this->logger('markaspot_mail_inbound')->error('Promoting inbound mail @id failed: @message', [
        '@id' => $mail->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Could not promote the mail. The error has been logged; check the report log for details.'));
    }
    $form_state->setRedirect('entity.inbound_mail.collection');
  }

  /**
   * Builds the category select options scoped to a jurisdiction.
   *
   * Delegates to the shared PromotableCategoryRepository (#482) so the admin
   * form and the dashboard API enforce ONE definition of "promotable".
   *
   * @param int $jurisdictionGid
   *   The jurisdiction group id, or 0 when unassigned.
   *
   * @return array<int, string>
   *   Term id keyed option labels.
   */
  protected function categoryOptions(int $jurisdictionGid): array {
    return $this->categoryRepository->getOptions($jurisdictionGid);
  }

  /**
   * Plain-text escape helper for read-only previews.
   */
  protected function escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }

}
