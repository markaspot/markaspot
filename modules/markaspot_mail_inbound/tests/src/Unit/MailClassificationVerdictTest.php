<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Unit;

use Drupal\markaspot_mail_inbound\Service\MailClassificationVerdict;
use Drupal\Tests\UnitTestCase;

/**
 * Tests local validation of the mail text-classification verdict.
 *
 * @coversDefaultClass \Drupal\markaspot_mail_inbound\Service\MailClassificationVerdict
 * @group markaspot_mail_inbound
 */
class MailClassificationVerdictTest extends UnitTestCase {

  /**
   * A complete, valid provider answer.
   */
  protected static function valid(array $overrides = []): array {
    return $overrides + [
      'category_tid' => 7,
      'confidence' => 0.9,
      'address' => 'Hauptstrasse 12',
      'is_report' => TRUE,
      'summary' => 'A streetlight is out.',
    ];
  }

  /**
   * Valid verdicts are accepted unchanged.
   *
   * @covers ::fromProviderData
   * @covers ::promotionConfidence
   */
  public function testValidVerdicts(): void {
    $report = MailClassificationVerdict::fromProviderData(self::valid());
    $this->assertInstanceOf(MailClassificationVerdict::class, $report);
    $this->assertTrue($report->isReport);
    $this->assertSame(7, $report->categoryTid);
    $this->assertSame(0.9, $report->promotionConfidence());

    $nonReport = MailClassificationVerdict::fromProviderData(self::valid(['is_report' => FALSE]));
    $this->assertInstanceOf(MailClassificationVerdict::class, $nonReport);
    $this->assertFalse($nonReport->isReport);
    $this->assertNull($nonReport->promotionConfidence());

    // Integer bounds and omitted nullable fields are fine.
    $edge = MailClassificationVerdict::fromProviderData([
      'category_tid' => 3,
      'confidence' => 1,
      'is_report' => TRUE,
    ]);
    $this->assertInstanceOf(MailClassificationVerdict::class, $edge);
    $this->assertSame(1.0, $edge->confidence);
    $this->assertNull($edge->address);
    $this->assertNull($edge->summary);
  }

  /**
   * Malformed answers are rejected with a content-free reason code.
   *
   * @dataProvider invalidProvider
   * @covers ::fromProviderData
   */
  public function testInvalidVerdicts(mixed $data, string $reason): void {
    $this->assertSame($reason, MailClassificationVerdict::fromProviderData($data));
  }

  /**
   * Malformed provider answers.
   */
  public static function invalidProvider(): array {
    $missing = self::valid();
    unset($missing['is_report']);
    return [
      'not an object' => ['true', 'not_an_object'],
      'missing is_report' => [$missing, 'invalid_is_report'],
      'null is_report' => [self::valid(['is_report' => NULL]), 'invalid_is_report'],
      'string "false"' => [self::valid(['is_report' => 'false']), 'invalid_is_report'],
      'string "true"' => [self::valid(['is_report' => 'true']), 'invalid_is_report'],
      'numeric 0' => [self::valid(['is_report' => 0]), 'invalid_is_report'],
      'numeric 1' => [self::valid(['is_report' => 1]), 'invalid_is_report'],
      'array is_report' => [self::valid(['is_report' => [TRUE]]), 'invalid_is_report'],
      'string tid' => [self::valid(['category_tid' => '7']), 'invalid_category_tid'],
      'zero tid' => [self::valid(['category_tid' => 0]), 'invalid_category_tid'],
      'missing confidence' => [array_diff_key(self::valid(), ['confidence' => 1]), 'invalid_confidence'],
      'string confidence' => [self::valid(['confidence' => '0.95']), 'invalid_confidence'],
      'inflated confidence' => [self::valid(['confidence' => 1.7]), 'invalid_confidence'],
      'negative confidence' => [self::valid(['confidence' => -0.1]), 'invalid_confidence'],
      'NAN confidence' => [self::valid(['confidence' => NAN]), 'invalid_confidence'],
      'array address' => [self::valid(['address' => ['x']]), 'invalid_address'],
      'numeric summary' => [self::valid(['summary' => 42]), 'invalid_summary'],
    ];
  }

}
