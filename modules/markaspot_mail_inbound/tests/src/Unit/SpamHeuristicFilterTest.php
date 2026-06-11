<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Unit;

use Drupal\markaspot_mail_inbound\Dto\InboundMessage;
use Drupal\markaspot_mail_inbound\Service\SpamHeuristicFilter;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the spam / not-a-report heuristic.
 *
 * The filter must be conservative: it only flags unambiguous machine markers,
 * and stages everything else (a false positive silently drops a real report).
 *
 * @group markaspot_mail_inbound
 * @coversDefaultClass \Drupal\markaspot_mail_inbound\Service\SpamHeuristicFilter
 */
class SpamHeuristicFilterTest extends UnitTestCase {

  /**
   * The filter under test.
   */
  protected SpamHeuristicFilter $filter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->filter = new SpamHeuristicFilter();
  }

  /**
   * Builds a message with the given headers, subject and body.
   *
   * @param array<string, string> $headers
   *   Auto-response headers keyed by lowercased header name.
   * @param string $subject
   *   The subject line.
   * @param string $textBody
   *   The plain-text body.
   * @param string $htmlBody
   *   The HTML body.
   *
   * @return \Drupal\markaspot_mail_inbound\Dto\InboundMessage
   *   The message.
   */
  protected function message(array $headers = [], string $subject = 'Broken light', string $textBody = 'A streetlight is broken.', string $htmlBody = ''): InboundMessage {
    return new InboundMessage(
      fromAddress: 'citizen@example.org',
      fromName: 'Citizen',
      toAddresses: ['report@city.example'],
      subject: $subject,
      textBody: $textBody,
      htmlBody: $htmlBody,
      messageId: 'id@example.org',
      inReplyTo: '',
      references: [],
      date: NULL,
      attachments: [],
      autoResponseHeaders: $headers,
    );
  }

  /**
   * A normal citizen report is never flagged.
   *
   * @covers ::classify
   */
  public function testGenuineReportPasses(): void {
    $this->assertNull($this->filter->classify($this->message()));
  }

  /**
   * Auto-Submitted other than "no" is flagged.
   *
   * @covers ::classify
   * @dataProvider autoSubmittedProvider
   */
  public function testAutoSubmittedFlagged(string $value, bool $expectFlag): void {
    $reason = $this->filter->classify($this->message(['auto-submitted' => $value]));
    if ($expectFlag) {
      $this->assertSame(SpamHeuristicFilter::REASON_AUTO_SUBMITTED, $reason);
    }
    else {
      $this->assertNull($reason);
    }
  }

  /**
   * Data provider for Auto-Submitted values.
   *
   * @return array<string, array{string, bool}>
   *   Header value and whether it should be flagged.
   */
  public static function autoSubmittedProvider(): array {
    return [
      'auto-replied' => ['auto-replied', TRUE],
      'auto-generated' => ['auto-generated', TRUE],
      'auto-notified' => ['auto-notified', TRUE],
      'no is human' => ['no', FALSE],
    ];
  }

  /**
   * X-Auto-Response-Suppress presence is flagged.
   *
   * @covers ::classify
   */
  public function testAutoResponseSuppressFlagged(): void {
    $this->assertSame(
      SpamHeuristicFilter::REASON_AUTO_RESPONSE_SUPPRESS,
      $this->filter->classify($this->message(['x-auto-response-suppress' => 'oof, autoreply']))
    );
  }

  /**
   * Precedence bulk/list/junk is flagged; other values pass.
   *
   * @covers ::classify
   * @dataProvider precedenceProvider
   */
  public function testPrecedence(string $value, bool $expectFlag): void {
    $reason = $this->filter->classify($this->message(['precedence' => $value]));
    if ($expectFlag) {
      $this->assertSame(SpamHeuristicFilter::REASON_PRECEDENCE, $reason);
    }
    else {
      $this->assertNull($reason);
    }
  }

  /**
   * Data provider for Precedence values.
   *
   * @return array<string, array{string, bool}>
   *   Header value and whether it should be flagged.
   */
  public static function precedenceProvider(): array {
    return [
      'bulk' => ['bulk', TRUE],
      'list' => ['list', TRUE],
      'junk' => ['junk', TRUE],
      'first-class is human' => ['first-class', FALSE],
      'normal is human' => ['normal', FALSE],
    ];
  }

  /**
   * Mailing-list headers are flagged.
   *
   * @covers ::classify
   */
  public function testMailingListFlagged(): void {
    $this->assertSame(
      SpamHeuristicFilter::REASON_MAILING_LIST,
      $this->filter->classify($this->message(['list-id' => '<list.example.org>']))
    );
    $this->assertSame(
      SpamHeuristicFilter::REASON_MAILING_LIST,
      $this->filter->classify($this->message(['list-unsubscribe' => '<mailto:unsub@example.org>']))
    );
  }

  /**
   * An empty subject AND empty body is flagged; either alone passes.
   *
   * @covers ::classify
   */
  public function testEmptyMail(): void {
    $this->assertSame(
      SpamHeuristicFilter::REASON_EMPTY,
      $this->filter->classify($this->message([], '', '', ''))
    );
    // Subject only: still a (terse) report, stage it.
    $this->assertNull($this->filter->classify($this->message([], 'Pothole on Main St', '', '')));
    // Body only: stage it.
    $this->assertNull($this->filter->classify($this->message([], '', 'There is a pothole.', '')));
    // HTML-only body counts as content.
    $this->assertNull($this->filter->classify($this->message([], '', '', '<p>pothole</p>')));
  }

}
