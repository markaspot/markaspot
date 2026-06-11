<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Plugin\QueueWorker;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\markaspot_mail_inbound\Exception\MailParseException;
use Drupal\markaspot_mail_inbound\Service\MailboxResolver;
use Drupal\markaspot_mail_inbound\Service\MailIngestOrchestrator;
use Drupal\markaspot_mail_inbound\Service\MailParserService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued raw inbound mails into staged inbound_mail entities.
 *
 * Items are enqueued by MailboxFetcher (cron) as
 * ['mailbox_id' => string, 'raw' => string].
 *
 * Failure policy distinguishes permanent from transient errors:
 * - Unparsable (MailParseException) or unroutable items are PERMANENT: they
 *   are logged and dropped, because requeueing them would poison the queue.
 * - Any other Throwable (DB deadlock, full disk, a missing paragraph type
 *   mid-deploy) is treated as TRANSIENT and rethrown so Drupal requeues the
 *   item for a later retry instead of losing the citizen's email.
 */
#[QueueWorker(
  id: 'markaspot_mail_inbound',
  title: new TranslatableMarkup('Inbound email to service request'),
  cron: ['time' => 60],
)]
final class MailInboundQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a MailInboundQueueWorker.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\markaspot_mail_inbound\Service\MailParserService $parser
   *   The mail parser.
   * @param \Drupal\markaspot_mail_inbound\Service\MailboxResolver $mailboxResolver
   *   The mailbox resolver.
   * @param \Drupal\markaspot_mail_inbound\Service\MailIngestOrchestrator $orchestrator
   *   The ingest orchestrator (staging spine).
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected MailParserService $parser,
    protected MailboxResolver $mailboxResolver,
    protected MailIngestOrchestrator $orchestrator,
    protected LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('markaspot_mail_inbound.parser'),
      $container->get('markaspot_mail_inbound.mailbox_resolver'),
      $container->get('markaspot_mail_inbound.orchestrator'),
      $container->get('logger.factory')->get('markaspot_mail_inbound'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $mailboxId = is_array($data) ? (string) ($data['mailbox_id'] ?? '') : '';
    $raw = is_array($data) ? (string) ($data['raw'] ?? '') : '';

    if ($raw === '') {
      $this->logger->warning('Dropping queue item without raw message (mailbox @id).', ['@id' => $mailboxId]);
      return;
    }

    $mailbox = $mailboxId !== '' ? $this->mailboxResolver->getMailbox($mailboxId) : NULL;
    if ($mailbox === NULL) {
      $this->logger->warning('Dropping queue item: mailbox @id is no longer configured.', ['@id' => $mailboxId]);
      return;
    }

    try {
      $message = $this->parser->parse($raw);
    }
    catch (MailParseException $e) {
      $this->logger->error('Dropping unparsable inbound mail (mailbox @id): @message', [
        '@id' => $mailboxId,
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    try {
      $result = $this->orchestrator->ingest($message, $mailbox);
      $this->logger->info('Inbound mail processed (mailbox @id): @status', [
        '@id' => $mailboxId,
        '@status' => $result->status . ($result->reason !== '' ? ' (' . $result->reason . ')' : ''),
      ]);
    }
    catch (MailParseException $e) {
      // Permanent: the message itself is structurally broken. Requeueing it
      // would poison the queue forever, so drop it (logged). The raw mail
      // stays in the IMAP processed folder for manual inspection.
      $this->logger->error('Dropping structurally invalid inbound mail (mailbox @id): @message', [
        '@id' => $mailboxId,
        '@message' => $e->getMessage(),
      ]);
    }
    catch (\Throwable $e) {
      // Transient or environmental (DB deadlock, full disk, a paragraph type
      // missing mid-deploy): the SAME item may succeed on a later attempt.
      // Rethrow so Drupal's queue keeps the item and retries it instead of
      // permanently losing the citizen's email (already flagged Seen at
      // enqueue, so it will not be re-fetched from IMAP).
      $this->logger->warning('Inbound mail ingestion failed transiently; requeueing (mailbox @id): @message', [
        '@id' => $mailboxId,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

}
