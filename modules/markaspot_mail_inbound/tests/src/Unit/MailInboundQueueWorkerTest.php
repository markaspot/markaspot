<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Unit;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\markaspot_mail_inbound\Dto\InboundMessage;
use Drupal\markaspot_mail_inbound\Exception\MailParseException;
use Drupal\markaspot_mail_inbound\IngestResult;
use Drupal\markaspot_mail_inbound\Plugin\QueueWorker\MailInboundQueueWorker;
use Drupal\markaspot_mail_inbound\Service\MailboxResolver;
use Drupal\markaspot_mail_inbound\Service\MailIngestOrchestrator;
use Drupal\markaspot_mail_inbound\Service\MailParserService;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the queue worker failure policy (H3).
 *
 * A parse failure is permanent and consumed (the item is dropped); any other
 * Throwable is transient and rethrown so Drupal requeues the item instead of
 * permanently losing the citizen's email.
 *
 * @group markaspot_mail_inbound
 * @coversDefaultClass \Drupal\markaspot_mail_inbound\Plugin\QueueWorker\MailInboundQueueWorker
 */
class MailInboundQueueWorkerTest extends UnitTestCase {

  /**
   * Builds a worker with the given (mocked) parser and creator.
   */
  protected function createWorker(MailParserService $parser, MailIngestOrchestrator $orchestrator): MailInboundQueueWorker {
    $resolver = $this->createMock(MailboxResolver::class);
    $resolver->method('getMailbox')->willReturn(['id' => 'city_main']);

    $logger = $this->createMock(LoggerChannelInterface::class);

    return new MailInboundQueueWorker(
      [],
      'markaspot_mail_inbound',
      [],
      $parser,
      $resolver,
      $orchestrator,
      $logger,
    );
  }

  /**
   * Returns a minimal parsed message stub.
   */
  protected function dummyMessage(): InboundMessage {
    return new InboundMessage(
      fromAddress: 'citizen@example.org',
      fromName: 'Citizen',
      toAddresses: ['report@city.example'],
      subject: 'Subject',
      textBody: 'Body',
      htmlBody: '',
      messageId: 'id@example.org',
      inReplyTo: '',
      references: [],
      date: NULL,
      attachments: [],
    );
  }

  /**
   * A transient ingestion error must propagate so the item is requeued.
   *
   * @covers ::processItem
   */
  public function testTransientErrorIsRethrown(): void {
    $parser = $this->createMock(MailParserService::class);
    $parser->method('parse')->willReturn($this->dummyMessage());

    $orchestrator = $this->createMock(MailIngestOrchestrator::class);
    $orchestrator->method('ingest')->willThrowException(new \RuntimeException('DB deadlock'));

    $worker = $this->createWorker($parser, $orchestrator);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('DB deadlock');
    $worker->processItem(['mailbox_id' => 'city_main', 'raw' => 'raw message']);
  }

  /**
   * A parse failure is permanent: consumed, never rethrown.
   *
   * @covers ::processItem
   */
  public function testParseExceptionIsConsumed(): void {
    $parser = $this->createMock(MailParserService::class);
    $parser->method('parse')->willThrowException(new MailParseException('garbled'));

    $orchestrator = $this->createMock(MailIngestOrchestrator::class);
    // Ingest must never be reached when parsing fails.
    $orchestrator->expects($this->never())->method('ingest');

    $worker = $this->createWorker($parser, $orchestrator);

    // No exception escapes: the broken item is dropped.
    $worker->processItem(['mailbox_id' => 'city_main', 'raw' => 'garbled']);
    $this->addToAssertionCount(1);
  }

  /**
   * A successful ingest consumes the item without throwing.
   *
   * @covers ::processItem
   */
  public function testSuccessfulIngestConsumesItem(): void {
    $parser = $this->createMock(MailParserService::class);
    $parser->method('parse')->willReturn($this->dummyMessage());

    $orchestrator = $this->createMock(MailIngestOrchestrator::class);
    $orchestrator->method('ingest')->willReturn(IngestResult::staged(42));

    $worker = $this->createWorker($parser, $orchestrator);

    $worker->processItem(['mailbox_id' => 'city_main', 'raw' => 'raw message']);
    $this->addToAssertionCount(1);
  }

}
