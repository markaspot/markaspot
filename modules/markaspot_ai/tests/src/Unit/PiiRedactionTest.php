<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Tests\UnitTestCase;

// Load the procedural helpers from the module file. The regex redaction
// helper is deliberately factored out of hook_node_presave so it can be
// exercised without a full Drupal kernel.
require_once __DIR__ . '/../../../markaspot_ai.module';

/**
 * Tests regex-based PII redaction ordering and replacement behaviour.
 *
 * @group markaspot_ai
 */
class PiiRedactionTest extends UnitTestCase {

  /**
   * An IBAN must be redacted before the DE local phone regex sees it.
   *
   * The tail of a German IBAN (4-digit groups starting after the country
   * code) overlaps with the "starts with 0, then digits and separators"
   * shape of a local phone number. If phones run first, the IBAN is
   * corrupted into a mix of "[REDACTED phone]" and digits.
   */
  public function testIbanIsNotConfusedWithPhone(): void {
    $result = _markaspot_ai_redact_pii_regex('Meine IBAN ist DE89 3704 0044 0532 0130 00.');

    $this->assertTrue($result['pii_found']);
    $this->assertSame('Meine IBAN ist [REDACTED IBAN].', $result['text']);
  }

  /**
   * Email "@" must not bleed into the phone regex.
   */
  public function testEmailDoesNotTriggerPhoneRegex(): void {
    $result = _markaspot_ai_redact_pii_regex('Mail: max@example.de, Tel: 0228-1234567');

    $this->assertTrue($result['pii_found']);
    $this->assertSame('Mail: [REDACTED email], Tel: [REDACTED phone]', $result['text']);
  }

  /**
   * All three structured PII types redact cleanly in one pass.
   */
  public function testMultiplePiiTypesInSameText(): void {
    $result = _markaspot_ai_redact_pii_regex('IBAN DE89 3704 0044 0532 0130 00, Tel 0228-1234567, Mail max@ex.de');

    $this->assertTrue($result['pii_found']);
    $this->assertSame('IBAN [REDACTED IBAN], Tel [REDACTED phone], Mail [REDACTED email]', $result['text']);
  }

  /**
   * Text without any PII is returned unchanged with pii_found FALSE.
   */
  public function testCleanTextIsNotFlagged(): void {
    $result = _markaspot_ai_redact_pii_regex('Ein Schlagloch auf der Hauptstrasse.');

    $this->assertFalse($result['pii_found']);
    $this->assertSame('Ein Schlagloch auf der Hauptstrasse.', $result['text']);
  }

}
