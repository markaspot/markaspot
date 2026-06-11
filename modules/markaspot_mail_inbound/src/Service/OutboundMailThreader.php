<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Util\MailTextUtils;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Threads outgoing node mails into an email citizen's conversation (#482).
 *
 * An email-origin citizen has no web account: the reply mail IS their
 * interface. Status notifications about their request (markaspot_notification
 * builders, ECA action_send_email) should land in the SAME mail-client
 * conversation as their original report. This service backs
 * markaspot_mail_inbound_mail_alter(): when an outgoing message demonstrably
 * relates to a promoted email-origin service request AND goes to the
 * verified reporter, it adds Message-ID / In-Reply-To / References headers
 * from the inbound mail's thread chain and records the outbound id so a
 * citizen reply to the status mail threads back.
 *
 * CONSERVATIVE BY CONTRACT: it only acts when every condition resolves
 * cleanly — a node in the mail params (params['node'], the
 * markaspot_notification convention, or params['context']['node'], the ECA
 * EmailAction convention), bundle service_request, field_source=email, a
 * matching promoted inbound_mail, and a recipient equal to the mail's
 * verified from_address. Staff/org notifications about the same node carry
 * a different recipient and are left untouched (the citizen's Message-IDs
 * do not belong in staff mail). Any error is swallowed and logged: a
 * threading nicety must never break mail delivery.
 */
class OutboundMailThreader {

  /**
   * Constructs the threader.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected UuidInterface $uuid,
    protected RequestStack $requestStack,
    protected LoggerChannelInterface $logger,
  ) {
  }

  /**
   * Adds threading headers to a qualifying outgoing $message (by reference).
   *
   * @param array<string, mixed> $message
   *   The hook_mail_alter $message array.
   */
  public function alter(array &$message): void {
    try {
      // Our own keys already thread via InboundMailReplyService's params.
      if (($message['module'] ?? '') === 'markaspot_mail_inbound') {
        return;
      }

      $node = $this->resolveNode($message['params'] ?? []);
      if ($node === NULL) {
        return;
      }
      if ($node->bundle() !== 'service_request' || $node->isNew()) {
        return;
      }
      if (!$node->hasField('field_source') || (string) $node->get('field_source')->value !== 'email') {
        return;
      }

      $mail = $this->findPromotedMailForNode((int) $node->id());
      if ($mail === NULL) {
        return;
      }

      // Only thread mails going to the verified reporter. $message['to'] may
      // be "Name <addr>": extract the addr-spec and compare EXACTLY — a
      // substring check would also match e.g. "fakecitizen@example.org" for
      // reporter "citizen@example.org" and leak the conversation's
      // Message-IDs to a third party.
      $reporter = mb_strtolower(trim($mail->getFromAddress()));
      $to = $this->extractAddress((string) ($message['to'] ?? ''));
      if ($reporter === '' || $to === '' || $to !== $reporter) {
        return;
      }

      $threadIds = $mail->getThreadMessageIds();
      if ($threadIds === []) {
        return;
      }

      // Respect a Message-ID some other layer already set; otherwise
      // generate ours (phpmailer_smtp honors the preset header, see
      // InboundMailReplyService's class docblock).
      $outboundId = $this->existingMessageId($message['headers'] ?? []);
      if ($outboundId === '') {
        $outboundId = $this->generateMessageId();
        $message['headers']['Message-ID'] = '<' . $outboundId . '>';
      }

      $message['headers']['In-Reply-To'] = '<' . end($threadIds) . '>';
      $message['headers']['References'] = implode(' ', array_map(
        static fn(string $id): string => '<' . $id . '>',
        $threadIds
      ));

      // Record the outbound id so a citizen reply to this status mail
      // threads back onto the conversation (matched by the orchestrator).
      // Re-load the mail UNCHANGED right before appending: two notifications
      // about the same node can fire concurrently, and appending to an
      // entity loaded earlier in this method would silently drop the id the
      // other process recorded in between (lost update). The fresh load
      // shrinks that window to the read-modify-write below.
      if (!in_array($outboundId, $threadIds, TRUE)) {
        $fresh = $this->entityTypeManager->getStorage('inbound_mail')->loadUnchanged($mail->id());
        if ($fresh instanceof InboundMail) {
          $mail = $fresh;
        }
        $threadIds = $mail->getThreadMessageIds();
        if (!in_array($outboundId, $threadIds, TRUE)) {
          $threadIds[] = mb_substr($outboundId, 0, 998);
          $mail->set('thread_message_ids', $threadIds);
          $mail->save();
        }
      }

      $this->logger->info('Threaded outgoing @module:@key mail for node @nid into the inbound conversation of mail @id.', [
        '@module' => (string) ($message['module'] ?? ''),
        '@key' => (string) ($message['key'] ?? ''),
        '@nid' => $node->id(),
        '@id' => $mail->id(),
      ]);
    }
    catch (\Throwable $e) {
      // Never break mail delivery over a threading nicety.
      $this->logger->warning('Outbound mail threading skipped due to an error (@type).', ['@type' => get_class($e)]);
    }
  }

  /**
   * Resolves a node from the known mail param conventions.
   *
   * @param array<string, mixed> $params
   *   The $message['params'] array.
   */
  protected function resolveNode(array $params): ?NodeInterface {
    // markaspot_notification convention (NotifyMailManager::resolveNode()).
    if (($params['node'] ?? NULL) instanceof NodeInterface) {
      return $params['node'];
    }
    // ECA EmailAction convention: EcaActionEmailBuilder reads BOTH
    // params['context']['node'] and params['context']['entity'], so this
    // resolver mirrors both keys.
    $context = $params['context'] ?? NULL;
    if (is_array($context)) {
      $candidate = $context['node'] ?? $context['entity'] ?? NULL;
      if ($candidate instanceof NodeInterface) {
        return $candidate;
      }
    }
    return NULL;
  }

  /**
   * Finds the promoted inbound mail behind a service request node.
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
   * Extracts the lowercased addr-spec from a To header value.
   *
   * Handles both a bare address and the "Display Name <addr>" form. The
   * candidate is validated as a single RFC-compliant address; anything that
   * does not validate — including a multi-recipient comma list, which is
   * never "the single verified reporter" — returns '' and therefore never
   * matches the reporter comparison in alter().
   */
  protected function extractAddress(string $to): string {
    $to = trim($to);
    if (preg_match('/<([^>]+)>/', $to, $matches)) {
      $to = $matches[1];
    }
    $to = mb_strtolower(trim($to));
    return filter_var($to, FILTER_VALIDATE_EMAIL) !== FALSE ? $to : '';
  }

  /**
   * Returns a normalized Message-ID already present on the headers, if any.
   *
   * @param array<string, string> $headers
   *   The message headers (mixed-case keys).
   */
  protected function existingMessageId(array $headers): string {
    foreach ($headers as $name => $value) {
      if (strcasecmp((string) $name, 'Message-ID') === 0) {
        return MailTextUtils::normalizeMessageId((string) $value);
      }
    }
    return '';
  }

  /**
   * Generates a normalized RFC 5322 Message-ID (without angle brackets).
   */
  protected function generateMessageId(): string {
    $host = $this->requestStack->getCurrentRequest()?->getHost()
      ?: (gethostname() ?: 'localhost');
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?: 'localhost';
    return $this->uuid->generate() . '@' . $host;
  }

}
