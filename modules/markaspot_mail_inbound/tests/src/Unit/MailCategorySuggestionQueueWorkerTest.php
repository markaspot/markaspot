<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Unit;

use Drupal\node\NodeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Plugin\QueueWorker\MailCategorySuggestionQueueWorker;
use Drupal\markaspot_mail_inbound\Service\InboundMailPromoter;
use Drupal\markaspot_mail_inbound\Service\MailCategorySuggestionService;
use Drupal\markaspot_mail_inbound\Service\PromotableCategoryRepository;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the MailCategorySuggestionQueueWorker auto-promote gate.
 *
 * All AI calls are mocked. Tests cover:
 * - auto_promote_enabled=FALSE -> no promotion regardless of confidence
 * - confidence below threshold -> no promotion
 * - confidence at threshold -> promotion triggered
 * - vision path (NULL confidence) -> never auto-promotes
 * - foreign/unknown category (not jurisdiction-scoped) -> no promotion
 * - missing mail_id in item -> drops without error
 * - mail no longer staged -> drops without error.
 *
 * @group markaspot_mail_inbound
 * @coversDefaultClass \Drupal\markaspot_mail_inbound\Plugin\QueueWorker\MailCategorySuggestionQueueWorker
 */
class MailCategorySuggestionQueueWorkerTest extends UnitTestCase {

  /**
   * Builds a worker with the given settings and mocks.
   */
  protected function worker(
    bool $autoPromoteEnabled = FALSE,
    float $threshold = 0.85,
    bool $promotable = TRUE,
    ?InboundMailPromoter $promoter = NULL,
  ): MailCategorySuggestionQueueWorker {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['auto_promote_enabled', $autoPromoteEnabled],
      ['auto_promote_confidence_threshold', $threshold],
      ['ai_suggestions_enabled', TRUE],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $categoryRepo = $this->createMock(PromotableCategoryRepository::class);
    $categoryRepo->method('isPromotable')->willReturn($promotable);

    $logger = $this->createMock(LoggerChannelInterface::class);

    if ($promoter === NULL) {
      $promoter = $this->createMock(InboundMailPromoter::class);
    }

    $suggestionService = $this->createMock(MailCategorySuggestionService::class);

    return new MailCategorySuggestionQueueWorker(
      [],
      'markaspot_mail_inbound_suggest',
      [],
      $this->createMock(EntityTypeManagerInterface::class),
      $suggestionService,
      $promoter,
      $categoryRepo,
      $configFactory,
      $logger,
    );
  }

  /**
   * Builds a mail mock with the given suggestion state.
   */
  protected function mailMock(
    string $state = InboundMail::STATE_STAGED,
    string $suggestionStatus = InboundMail::SUGGESTION_DONE,
    ?int $tid = 5,
    ?float $confidence = 0.9,
    ?string $suggestedAddress = NULL,
  ): InboundMail {
    $mail = $this->getMockBuilder(InboundMail::class)
      ->disableOriginalConstructor()
      ->onlyMethods([
        'id',
        'getState',
        'getSuggestionStatus',
        'getSuggestedCategoryTid',
        'getSuggestionConfidence',
        'getSuggestedAddress',
        'getJurisdictionId',
        'save',
      ])
      ->getMock();

    $mail->method('id')->willReturn(42);
    $mail->method('getState')->willReturn($state);
    $mail->method('getSuggestionStatus')->willReturn($suggestionStatus);
    $mail->method('getSuggestedCategoryTid')->willReturn($tid);
    $mail->method('getSuggestionConfidence')->willReturn($confidence);
    $mail->method('getSuggestedAddress')->willReturn($suggestedAddress);
    $mail->method('getJurisdictionId')->willReturn(1);

    return $mail;
  }

  /**
   * Auto-promote disabled -> promoter never called.
   *
   * @covers ::maybeAutoPromote
   */
  public function testAutoPromoteDisabledDoesNotPromote(): void {
    $promoter = $this->createMock(InboundMailPromoter::class);
    $promoter->expects($this->never())->method('promoteToServiceRequest');

    $worker = $this->worker(autoPromoteEnabled: FALSE, promoter: $promoter);
    $mail = $this->mailMock(confidence: 0.99);

    // Access the protected method via reflection.
    $ref = new \ReflectionMethod($worker, 'maybeAutoPromote');
    $ref->setAccessible(TRUE);
    $ref->invoke($worker, $mail);
  }

  /**
   * Confidence below threshold -> no promotion.
   *
   * @covers ::maybeAutoPromote
   */
  public function testConfidenceBelowThresholdDoesNotPromote(): void {
    $promoter = $this->createMock(InboundMailPromoter::class);
    $promoter->expects($this->never())->method('promoteToServiceRequest');

    // threshold=0.85, confidence=0.84.
    $worker = $this->worker(autoPromoteEnabled: TRUE, threshold: 0.85, promoter: $promoter);
    $mail = $this->mailMock(confidence: 0.84);

    $ref = new \ReflectionMethod($worker, 'maybeAutoPromote');
    $ref->setAccessible(TRUE);
    $ref->invoke($worker, $mail);
  }

  /**
   * Confidence exactly at threshold -> promotes.
   *
   * @covers ::maybeAutoPromote
   */
  public function testConfidenceAtThresholdPromotes(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(99);

    $promoter = $this->createMock(InboundMailPromoter::class);
    $promoter->expects($this->once())
      ->method('promoteToServiceRequest')
      ->willReturn($node);

    // threshold=0.85, confidence=0.85.
    $worker = $this->worker(autoPromoteEnabled: TRUE, threshold: 0.85, promotable: TRUE, promoter: $promoter);
    $mail = $this->mailMock(confidence: 0.85);

    $ref = new \ReflectionMethod($worker, 'maybeAutoPromote');
    $ref->setAccessible(TRUE);
    $ref->invoke($worker, $mail);
  }

  /**
   * Vision path (NULL confidence) -> never auto-promotes.
   *
   * @covers ::maybeAutoPromote
   */
  public function testVisionPathNullConfidenceDoesNotPromote(): void {
    $promoter = $this->createMock(InboundMailPromoter::class);
    $promoter->expects($this->never())->method('promoteToServiceRequest');

    $worker = $this->worker(autoPromoteEnabled: TRUE, threshold: 0.0, promoter: $promoter);
    $mail = $this->mailMock(confidence: NULL);

    $ref = new \ReflectionMethod($worker, 'maybeAutoPromote');
    $ref->setAccessible(TRUE);
    $ref->invoke($worker, $mail);
  }

  /**
   * Foreign/unknown category (not jurisdiction-scoped) -> no promotion.
   *
   * IsPromotable() returns FALSE for categories outside the jurisdiction's
   * scoped list (e.g. another jurisdiction's tid or a stale tid). Coded and
   * codeless categories are both promotable when jurisdiction-scoped; this
   * test covers the rejection of categories that are not.
   *
   * @covers ::maybeAutoPromote
   */
  public function testUnknownCategoryNotPromotable(): void {
    $promoter = $this->createMock(InboundMailPromoter::class);
    $promoter->expects($this->never())->method('promoteToServiceRequest');

    $worker = $this->worker(autoPromoteEnabled: TRUE, threshold: 0.5, promotable: FALSE, promoter: $promoter);
    $mail = $this->mailMock(confidence: 0.95);

    $ref = new \ReflectionMethod($worker, 'maybeAutoPromote');
    $ref->setAccessible(TRUE);
    $ref->invoke($worker, $mail);
  }

  /**
   * Missing mail_id in queue item -> drops without exception.
   *
   * @covers ::processItem
   */
  public function testMissingMailIdDropsSilently(): void {
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->expects($this->never())->method('getStorage');

    $worker = $this->worker();

    // Should not throw.
    $worker->processItem(['mail_id' => 0]);
    $worker->processItem([]);
  }

  /**
   * Mail no longer staged (promoted) -> drops without calling suggestion.
   *
   * @covers ::processItem
   */
  public function testAlreadyPromotedMailDropsSilently(): void {
    $mail = $this->mailMock(state: InboundMail::STATE_PROMOTED);

    $mailStorage = $this->createMock(EntityStorageInterface::class);
    $mailStorage->method('load')->willReturn($mail);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('inbound_mail')->willReturn($mailStorage);

    $suggestionService = $this->createMock(MailCategorySuggestionService::class);
    $suggestionService->expects($this->never())->method('suggest');

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['auto_promote_enabled', FALSE],
      ['auto_promote_confidence_threshold', 0.85],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $worker = new MailCategorySuggestionQueueWorker(
      [],
      'markaspot_mail_inbound_suggest',
      [],
      $etm,
      $suggestionService,
      $this->createMock(InboundMailPromoter::class),
      $this->createMock(PromotableCategoryRepository::class),
      $configFactory,
      $this->createMock(LoggerChannelInterface::class),
    );

    // Should not throw.
    $worker->processItem(['mail_id' => 42]);
  }

  /**
   * Promoter receives the suggested address as address hint.
   *
   * @covers ::maybeAutoPromote
   */
  public function testAddressHintPassedToPromoter(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(77);

    $promoter = $this->createMock(InboundMailPromoter::class);
    $promoter->expects($this->once())
      ->method('promoteToServiceRequest')
      ->with(
        $this->anything(),
        5,
        'Hauptstrasse 12, 50667 Koeln'
      )
      ->willReturn($node);

    $worker = $this->worker(autoPromoteEnabled: TRUE, threshold: 0.85, promotable: TRUE, promoter: $promoter);
    $mail = $this->mailMock(confidence: 0.95, suggestedAddress: 'Hauptstrasse 12, 50667 Koeln');

    $ref = new \ReflectionMethod($worker, 'maybeAutoPromote');
    $ref->setAccessible(TRUE);
    $ref->invoke($worker, $mail);
  }

}
