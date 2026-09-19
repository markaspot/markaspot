<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests runtime blur status without contacting services or writing site state.
 */
#[Group('markaspot_vision')]
class BlurRequirementsTest extends UnitTestCase {

  /**
   * Every severity is selected from the same effective mode as requests.
   */
  #[DataProvider('statuses')]
  public function testRequirements(?string $required, string $url, string $key, bool $enabled, int $severity, string $mode): void {
    require_once dirname(__DIR__, 3) . '/markaspot_vision.install';
    foreach (['REQUIREMENT_INFO' => -1, 'REQUIREMENT_OK' => 0, 'REQUIREMENT_WARNING' => 1, 'REQUIREMENT_ERROR' => 2] as $constant => $value) {
      if (!defined($constant)) {
        define($constant, $value);
      }
    }
    $env = [
      'MARKASPOT_BLUR_REQUIRED' => $required,
      'MARKASPOT_BLUR_URL' => $url,
      'MARKASPOT_BLUR_API_KEY' => $key,
      'AI_API_KEY' => '',
    ];
    $previous = [];
    foreach ($env as $name => $value) {
      $previous[$name] = getenv($name);
      putenv($value === NULL ? $name : "$name=$value");
    }
    try {
      $vision = $this->getMockBuilder(ImageProcessingService::class)->disableOriginalConstructor()->onlyMethods(['selfTest'])->getMock();
      (new \ReflectionProperty($vision, 'logger'))->setValue($vision, new NullLogger());
      $container = new ContainerBuilder();
      $container->set('markaspot_vision.image_processing', $vision);
      $settings = [
        'api_url' => 'https://vision.example/path?secret=value',
        'enable_blur_preprocessing' => $enabled,
      ];
      $container->set('config.factory', $this->getConfigFactoryStub(['markaspot_vision.settings' => $settings]));
      $container->set('string_translation', $this->getStringTranslationStub());
      \Drupal::setContainer($container);
      self::assertSame([], markaspot_vision_requirements('install'));
      $rows = markaspot_vision_requirements('runtime');
      self::assertCount(1, $rows);
      $row = $rows['markaspot_vision_blur'];
      self::assertSame($severity, $row['severity']);
      self::assertSame($mode, $row['value']);
      self::assertStringNotContainsString('secret', (string) $row['description']);
      self::assertStringNotContainsString('https://', (string) $row['description']);
    }
    finally {
      foreach ($previous as $name => $value) {
        putenv($value === FALSE ? $name : "$name=$value");
      }
    }
  }

  /**
   * Required credentials, unprotected defaults and acknowledged opt-out.
   */
  public static function statuses(): iterable {
    yield ['1', 'https://blur.example', 'key', FALSE, 0, 'strict'];
    yield [NULL, 'https://blur.example', 'key', FALSE, 0, 'auto-required'];
    yield ['1', '', 'key', FALSE, 2, 'strict'];
    yield [NULL, 'https://blur.example', '', FALSE, 2, 'auto-required'];
    yield [NULL, '', '', FALSE, 1, 'auto-unprotected'];
    yield [NULL, '', '', TRUE, 0, 'auto-unprotected'];
    yield ['0', 'https://blur.example', '', FALSE, -1, 'off'];
  }

}
