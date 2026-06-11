<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Commands;

use Drupal\markaspot_mail_inbound\Exception\MailParseException;
use Drupal\markaspot_mail_inbound\IngestResult;
use Drupal\markaspot_mail_inbound\Service\MailIngestOrchestrator;
use Drupal\markaspot_mail_inbound\Service\MailboxFetcher;
use Drupal\markaspot_mail_inbound\Service\MailboxResolver;
use Drupal\markaspot_mail_inbound\Service\MailParserService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the inbound mail pipeline.
 *
 * Markaspot:mail-inbound:ingest is the local test and ops path: it runs a
 * raw .eml file through parser and the orchestrator synchronously, no IMAP
 * needed. The mail is staged (or threaded / discarded); promotion to a
 * service request happens in the triage inbox.
 */
class MailInboundCommands extends DrushCommands {

  /**
   * Constructs MailInboundCommands.
   */
  public function __construct(
    protected MailParserService $parser,
    protected MailboxResolver $mailboxResolver,
    protected MailIngestOrchestrator $orchestrator,
    protected MailboxFetcher $fetcher,
  ) {
    parent::__construct();
  }

  /**
   * Ingests a raw .eml file as if it had been fetched from a mailbox.
   *
   * @param string $eml_file
   *   Path to the raw RFC822 .eml file.
   * @param array $options
   *   Command options.
   */
  #[CLI\Command(name: 'markaspot:mail-inbound:ingest', aliases: ['mas:mail-ingest'])]
  #[CLI\Argument(name: 'eml_file', description: 'Path to a raw .eml file.')]
  #[CLI\Option(name: 'mailbox', description: 'Mailbox id from markaspot_mail_inbound.settings. Without it, the mailbox is resolved by recipient address.')]
  #[CLI\Usage(name: 'drush markaspot:mail-inbound:ingest report.eml --mailbox=city_main', description: 'Ingest report.eml through the city_main mailbox.')]
  public function ingest(string $eml_file, array $options = ['mailbox' => NULL]): int {
    if (!is_file($eml_file) || !is_readable($eml_file)) {
      $this->logger()->error(dt('File @file does not exist or is not readable.', ['@file' => $eml_file]));
      return self::EXIT_FAILURE;
    }
    $raw = (string) file_get_contents($eml_file);

    try {
      $message = $this->parser->parse($raw);
    }
    catch (MailParseException $e) {
      $this->logger()->error(dt('Could not parse @file: @message', [
        '@file' => $eml_file,
        '@message' => $e->getMessage(),
      ]));
      return self::EXIT_FAILURE;
    }

    $mailboxId = $options['mailbox'] ?? NULL;
    if ($mailboxId !== NULL && $mailboxId !== '') {
      $mailbox = $this->mailboxResolver->getMailbox((string) $mailboxId);
      if ($mailbox === NULL) {
        $this->logger()->error(dt('Mailbox @id is not configured.', ['@id' => $mailboxId]));
        return self::EXIT_FAILURE;
      }
    }
    else {
      $mailbox = $this->mailboxResolver->resolveForMessage($message);
      if ($mailbox === NULL) {
        $this->logger()->error(dt('No configured mailbox matches the recipients. Pass --mailbox=<id>.'));
        return self::EXIT_FAILURE;
      }
    }

    $result = $this->orchestrator->ingest($message, $mailbox);
    $suffix = $result->entityId !== NULL
      ? dt(' (@kind #@id)', ['@kind' => $result->kind, '@id' => $result->entityId])
      : '';
    $reason = $result->reason !== '' ? ': ' . $result->reason : '';
    $this->io()->writeln(dt('Result: @status@suffix@reason', [
      '@status' => $result->status,
      '@suffix' => $suffix,
      '@reason' => $reason,
    ]));

    return in_array($result->status, [
      IngestResult::STAGED,
      IngestResult::PROMOTED,
      IngestResult::DUPLICATE,
      IngestResult::REPLY,
      IngestResult::DISCARDED,
    ], TRUE)
      ? self::EXIT_SUCCESS
      : self::EXIT_FAILURE;
  }

  /**
   * Triggers one IMAP fetch cycle (all enabled mailboxes or one).
   *
   * @param array $options
   *   Command options.
   */
  #[CLI\Command(name: 'markaspot:mail-inbound:fetch', aliases: ['mas:mail-fetch'])]
  #[CLI\Option(name: 'mailbox', description: 'Fetch only this mailbox id.')]
  #[CLI\Usage(name: 'drush markaspot:mail-inbound:fetch', description: 'Fetch all enabled mailboxes into the processing queue.')]
  public function fetch(array $options = ['mailbox' => NULL]): int {
    $mailboxId = $options['mailbox'] ?? NULL;
    if ($mailboxId !== NULL && $mailboxId !== '') {
      $mailbox = $this->mailboxResolver->getMailbox((string) $mailboxId, TRUE);
      if ($mailbox === NULL) {
        $this->logger()->error(dt('Mailbox @id is not configured or disabled.', ['@id' => $mailboxId]));
        return self::EXIT_FAILURE;
      }
      $mailboxes = [$mailbox];
    }
    else {
      $mailboxes = $this->mailboxResolver->getMailboxes();
    }

    if ($mailboxes === []) {
      $this->io()->writeln(dt('No enabled mailboxes configured.'));
      return self::EXIT_SUCCESS;
    }

    $total = 0;
    foreach ($mailboxes as $mailbox) {
      $count = $this->fetcher->fetchMailbox($mailbox);
      $this->io()->writeln(dt('Mailbox @id: @count message(s) enqueued.', [
        '@id' => $mailbox['id'],
        '@count' => $count,
      ]));
      $total += $count;
    }
    $this->io()->writeln(dt('Total: @total message(s) enqueued. Run cron or drush queue:run markaspot_mail_inbound to process.', ['@total' => $total]));
    return self::EXIT_SUCCESS;
  }

}
