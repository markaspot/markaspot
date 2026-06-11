<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Webklex\PHPIMAP\ClientManager;

/**
 * Fetches unseen messages from an IMAP mailbox into the processing queue.
 *
 * Transport only: no parsing happens here. Each fetched message is enqueued
 * as a raw RFC822 string together with its mailbox id; the queue worker
 * parses and ingests it. IMAP failures are logged and never escalate, so a
 * broken mailbox cannot crash cron.
 */
class MailboxFetcher {

  public const QUEUE_NAME = 'markaspot_mail_inbound';

  /**
   * Maximum messages fetched per mailbox per run.
   *
   * Caps memory use on a large backlog. Remaining messages stay Unseen and
   * are picked up on the next cron run. Override per mailbox via the optional
   * imap.fetch_limit setting.
   */
  public const DEFAULT_FETCH_LIMIT = 50;

  /**
   * Maximum raw message size enqueued, in bytes (25 MB).
   *
   * A single oversized mail (e.g. a huge inline image) must not blow up
   * queue worker memory. Oversized messages are skipped and logged; they are
   * still flagged Seen so they do not re-trigger the guard every run.
   */
  public const MAX_RAW_BYTES = 26214400;

  /**
   * Constructs a MailboxFetcher.
   */
  public function __construct(
    protected QueueFactory $queueFactory,
    protected LoggerChannelInterface $logger,
  ) {
  }

  /**
   * Fetches unseen messages for one mailbox and enqueues them.
   *
   * @param array<string, mixed> $mailbox
   *   A normalized mailbox definition (see MailboxResolver).
   *
   * @return int
   *   The number of enqueued messages.
   */
  public function fetchMailbox(array $mailbox): int {
    $imap = $mailbox['imap'] ?? [];
    if (empty($imap['host']) || empty($imap['username'])) {
      $this->logger->notice('Mailbox @id has no IMAP connection configured; skipping fetch.', ['@id' => $mailbox['id'] ?? '']);
      return 0;
    }

    $enqueued = 0;
    $client = NULL;
    $fetchLimit = (int) ($imap['fetch_limit'] ?? self::DEFAULT_FETCH_LIMIT);
    if ($fetchLimit < 1) {
      $fetchLimit = self::DEFAULT_FETCH_LIMIT;
    }
    try {
      // soft_fail + fallback_date are top-level webklex options, not
      // per-account settings: a single message with an unparsable Date header
      // would otherwise throw InvalidMessageDateException and abort the whole
      // fetch, leaving every message Unseen and silently breaking ingestion.
      // With soft_fail the bad message is skipped; fallback_date lets most
      // odd dates still parse.
      $clientManager = new ClientManager([
        'options' => [
          'soft_fail' => TRUE,
          'fallback_date' => '01.01.1970 00:00:00',
        ],
      ]);
      $client = $clientManager->make([
        'host' => (string) $imap['host'],
        'port' => (int) ($imap['port'] ?? 993),
        'encryption' => $this->mapEncryption((string) ($imap['encryption'] ?? 'ssl')),
        'validate_cert' => TRUE,
        'username' => (string) $imap['username'],
        'password' => (string) $imap['password'],
        'protocol' => 'imap',
      ]);
      $client->connect();

      $folder = $client->getFolder((string) ($imap['folder'] ?? 'INBOX'));
      if ($folder === NULL) {
        $this->logger->error('IMAP folder @folder not found for mailbox @id.', [
          '@folder' => $imap['folder'] ?? 'INBOX',
          '@id' => $mailbox['id'] ?? '',
        ]);
        return 0;
      }

      $messages = $folder->messages()
        ->whereUnseen()
        ->leaveUnread()
        ->setFetchBody(TRUE)
        ->limit($fetchLimit)
        ->get();

      $queue = $this->queueFactory->get(self::QUEUE_NAME);
      $processedFolder = trim((string) ($imap['processed_folder'] ?? ''));

      foreach ($messages as $message) {
        try {
          $raw = $message->getHeader()->raw . "\r\n\r\n" . $message->getRawBody();

          // Size guard BEFORE enqueue: an oversized mail must never reach the
          // queue worker and exhaust its memory. Flag it Seen so it does not
          // re-trigger every run, and (when configured) move it aside.
          if (strlen($raw) > self::MAX_RAW_BYTES) {
            $this->logger->warning('Inbound mail from mailbox @id skipped: @size bytes exceeds the @max byte cap.', [
              '@id' => $mailbox['id'] ?? '',
              '@size' => strlen($raw),
              '@max' => self::MAX_RAW_BYTES,
            ]);
            $message->setFlag('Seen');
            if ($processedFolder !== '') {
              $message->move($processedFolder);
            }
            continue;
          }

          $queue->createItem([
            'mailbox_id' => (string) $mailbox['id'],
            'raw' => $raw,
          ]);
          $enqueued++;

          // Mark as handled on the server only AFTER successful enqueue.
          $message->setFlag('Seen');
          if ($processedFolder !== '') {
            $message->move($processedFolder);
          }
        }
        catch (\Throwable $e) {
          $this->logger->error('Failed to enqueue a message from mailbox @id: @message', [
            '@id' => $mailbox['id'] ?? '',
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('IMAP fetch failed for mailbox @id: @message', [
        '@id' => $mailbox['id'] ?? '',
        '@message' => $e->getMessage(),
      ]);
    }
    finally {
      try {
        $client?->disconnect();
      }
      catch (\Throwable) {
        // Disconnect failures are irrelevant.
      }
    }

    if ($enqueued > 0) {
      $this->logger->notice('Enqueued @count inbound mails from mailbox @id.', [
        '@count' => $enqueued,
        '@id' => $mailbox['id'] ?? '',
      ]);
    }
    return $enqueued;
  }

  /**
   * Maps a configured encryption value to a webklex-understood one.
   *
   * The schema offers "none" for unencrypted connections, but webklex expects
   * a falsy value (FALSE) to disable encryption; the string "none" would be
   * treated as an unknown truthy transport. Known transports are passed
   * through lowercased.
   *
   * @param string $encryption
   *   The configured encryption value.
   *
   * @return string|false
   *   A webklex encryption value: 'ssl', 'tls', 'starttls', or FALSE.
   */
  protected function mapEncryption(string $encryption): string|false {
    $encryption = strtolower(trim($encryption));
    return match ($encryption) {
      '', 'none', 'false', 'notls' => FALSE,
      'ssl', 'tls', 'starttls' => $encryption,
      default => 'ssl',
    };
  }

}
