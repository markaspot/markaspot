<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Event\InboundRequestCreatedEvent;
use Drupal\markaspot_mail_inbound\Service\InboundMailReplyService;
use Drupal\markaspot_mail_inbound\Service\MailboxResolver;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Auto-replies to the citizen when a promoted email report has no location.
 *
 * Location is deliberately NOT a promotion gate (triage answers "what is
 * it", moderation completes "a known report"). The missing piece is asked of
 * the citizen right at promotion time: this subscriber listens to the
 * existing InboundRequestCreatedEvent and, when the promoted node has an
 * EMPTY field_geolocation, sends a threading-aware auto-reply (its own mail
 * key, same conversation chain) that includes the request id.
 *
 * Gated by the markaspot_mail_inbound.settings:auto_reply_missing_location
 * flag (default TRUE) and degrades silently when outbound mail is
 * unavailable (the reply service records the text on the conversation log
 * either way). The reply goes only to the mail's verified sender; the text
 * is a fixed translatable template — the boilerplate/ECA-configurable
 * variant is a later phase.
 */
class MissingLocationAutoReplySubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected InboundMailReplyService $replyService,
    protected LoggerChannelInterface $logger,
    protected ?CitizenWordingResolver $citizenWordingResolver = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      InboundRequestCreatedEvent::EVENT_NAME => 'onRequestCreated',
    ];
  }

  /**
   * Sends the missing-location auto-reply for an ungeolocated promotion.
   */
  public function onRequestCreated(InboundRequestCreatedEvent $event): void {
    try {
      if (!$this->configFactory->get(MailboxResolver::CONFIG_NAME)->get('auto_reply_missing_location')) {
        return;
      }

      $node = $event->getNode();
      // Only act on a demonstrably ungeolocated report. When the install has
      // no field_geolocation at all, there is no location workflow to ask
      // the citizen to feed — skip.
      if (!$node->hasField('field_geolocation') || !$node->get('field_geolocation')->isEmpty()) {
        return;
      }

      $mail = $this->findPromotedMailForNode((int) $node->id());
      if ($mail === NULL || trim($mail->getFromAddress()) === '') {
        return;
      }

      $requestId = $this->resolveRequestId($node);
      $term = $this->resolveCitizenTerm($node);
      $body = (string) $this->t(
        "Thank you for your @term. It has been registered@request_id and will be reviewed by our team.\n\nTo process it we still need the exact location. Please reply to this email with the address or a description of the place (street and house number, or a nearby landmark).",
        [
          '@term' => $term,
          '@request_id' => $requestId !== '' ? ' as ' . $requestId : '',
        ],
        ['langcode' => $node->language()->getId()]
      );

      $this->replyService->sendReply(
        $mail,
        $body,
        InboundMailReplyService::KEY_AUTO_REPLY_MISSING_LOCATION,
        'Auto-reply (missing location)'
      );
    }
    catch (\Throwable $e) {
      // The auto-reply is a courtesy; promotion must never fail over it.
      $this->logger->warning('Missing-location auto-reply skipped due to an error (@type).', ['@type' => get_class($e)]);
    }
  }

  /**
   * Finds the promoted inbound mail behind the freshly created node.
   */
  protected function findPromotedMailForNode(int $nid): ?InboundMail {
    if ($nid <= 0) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('inbound_mail');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('nid', $nid)
      ->condition('state', InboundMail::STATE_PROMOTED)
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $mail = $storage->load((int) reset($ids));
    return $mail instanceof InboundMail ? $mail : NULL;
  }

  /**
   * Resolves the citizen-facing request id of the promoted node.
   */
  protected function resolveRequestId(NodeInterface $node): string {
    if ($node->hasField('request_id') && !$node->get('request_id')->isEmpty()) {
      return '#' . trim((string) $node->get('request_id')->value, '# ');
    }
    return '';
  }

  /**
   * Resolves the configured citizen term for the promoted request.
   */
  protected function resolveCitizenTerm(NodeInterface $node): string {
    if ($this->citizenWordingResolver === NULL
      || !$node->hasField('field_jurisdiction')) {
      return 'report';
    }

    $field = $node->get('field_jurisdiction');
    if (!$field instanceof EntityReferenceFieldItemListInterface || $field->isEmpty()) {
      return 'report';
    }

    $group = $field->referencedEntities()[0] ?? NULL;
    if (!$group instanceof GroupInterface) {
      return 'report';
    }

    $wording = $this->citizenWordingResolver->resolve($group, $node->language()->getId());
    return $wording['singular'];
  }

}
