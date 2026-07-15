<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests passwordless mail update coverage.
 */
#[Group('markaspot_passwordless')]
final class PasswordlessMailUpdateTest extends UnitTestCase {

  /**
   * Tests sites that skipped the German repair receive a new update path.
   */
  public function testGermanMailRepairHasNewUpdatePath(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_passwordless.install');
    $this->assertIsString($source);

    $this->assertStringContainsString('function markaspot_passwordless_update_11908()', $source);
    $this->assertStringContainsString('return markaspot_passwordless_update_11907();', $source);
  }

}
