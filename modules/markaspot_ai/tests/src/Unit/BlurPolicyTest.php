<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\markaspot_ai\Utility\BlurPolicy;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests fail-closed environment parsing without loading Vision classes.
 */
#[CoversClass(BlurPolicy::class)]
#[Group('markaspot_ai')]
class BlurPolicyTest extends UnitTestCase {

  /**
   * Values are normalized identically for every caller; warnings are bounded.
   */
  #[DataProvider('values')]
  public function testRequired(?string $value, bool $required, bool $warning): void {
    $old = getenv('MARKASPOT_BLUR_REQUIRED');
    $url = getenv('MARKASPOT_BLUR_URL');
    putenv('MARKASPOT_BLUR_URL');
    $property = new \ReflectionProperty(BlurPolicy::class, 'warned');
    $property->setValue(NULL, FALSE);
    putenv($value === NULL ? 'MARKASPOT_BLUR_REQUIRED' : 'MARKASPOT_BLUR_REQUIRED=' . $value);
    try {
      $logger = $this->createMock(LoggerInterface::class);
      $logger->expects($warning ? $this->once() : $this->never())->method('warning')->with($this->stringContains('MARKASPOT_BLUR_REQUIRED'));
      self::assertSame($required, BlurPolicy::isRequired($logger));
      self::assertSame($required, BlurPolicy::isRequired($logger));
    }
    finally {
      putenv($old === FALSE ? 'MARKASPOT_BLUR_REQUIRED' : 'MARKASPOT_BLUR_REQUIRED=' . $old);
      $property->setValue(NULL, FALSE);
      putenv($url === FALSE ? 'MARKASPOT_BLUR_URL' : 'MARKASPOT_BLUR_URL=' . $url);
    }
  }

  /**
   * Explicit false values and unknown nonempty values, including quoted ENV.
   */
  public static function values(): iterable {
    yield ['"true"', TRUE, FALSE];
    yield ["'true'", TRUE, FALSE];
    yield ['enabled', TRUE, TRUE];
    yield [' TRUE ', TRUE, FALSE];
    yield ['0', FALSE, FALSE];
    yield ['off', FALSE, FALSE];
    yield ['"false"', FALSE, FALSE];
    yield ['', FALSE, FALSE];
    yield [NULL, FALSE, FALSE];
  }

  /**
   * Only canonical environment configuration opts auto mode into enforcement.
   */
  #[DataProvider('modes')]
  public function testModes(?string $required, string $url, string $legacy, string $mode): void {
    $values = ['MARKASPOT_BLUR_REQUIRED' => $required, 'MARKASPOT_BLUR_URL' => $url, 'VISION_BLUR_URL' => $legacy];
    $previous = [];
    foreach ($values as $key => $value) {
      $previous[$key] = getenv($key);
      putenv($value === NULL ? $key : "$key=$value");
    }
    try {
      $logger = $this->createMock(LoggerInterface::class);
      self::assertSame($mode, BlurPolicy::mode($logger));
      self::assertSame(in_array($mode, ['strict', 'auto-required'], TRUE), BlurPolicy::isRequired($logger));
    }
    finally {
      foreach ($previous as $key => $value) {
        putenv($value === FALSE ? $key : "$key=$value");
      }
    }
  }

  /**
   * Tri-state mode combinations, independent of install-config URL defaults.
   */
  public static function modes(): iterable {
    yield [NULL, 'https://blur.example', '', 'auto-required'];
    yield ['', 'https://blur.example', '', 'auto-required'];
    yield [NULL, '', '', 'auto-unprotected'];
    yield [NULL, '  ', 'https://legacy.example', 'auto-unprotected'];
    yield ['0', 'https://blur.example', '', 'off'];
    yield ['1', '', '', 'strict'];
  }

}
