<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\markaspot_fastmap\Demo\DemoSeed;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests parsing and validation of template demo seeds.
 */
#[CoversClass(DemoSeed::class)]
#[Group('markaspot_fastmap')]
final class DemoSeedTest extends UnitTestCase {

  /**
   * Path of the shipped reference seed.
   */
  private const EXAMPLE = __DIR__ . '/../../../demo_seeds/demo-school-routes.yml';

  /**
   * The shipped example is a valid, complete four-language seed.
   */
  public function testShippedExampleIsValid(): void {
    $seed = DemoSeed::fromFile(self::EXAMPLE);

    $this->assertSame('demo-school-routes', $seed->data['slug']);
    $this->assertSame('safe-routes', $seed->data['template']);
    $this->assertSame(['en', 'es', 'fr', 'de'], $seed->data['languages']);
    $this->assertGreaterThanOrEqual(6, count($seed->data['reports']));
    $this->assertLessThanOrEqual(8, count($seed->data['reports']));

    $finalMappings = [];
    $statuses = array_column($seed->data['statuses'], 'mapping', 'key');
    $hasQuestion = FALSE;
    foreach ($seed->data['reports'] as $report) {
      $finalMappings[] = $statuses[end($report['history'])['status']];
      $hasQuestion = $hasQuestion || in_array('question', array_column($report['history'], 'status'), TRUE);
      foreach (['en', 'es', 'fr', 'de'] as $langcode) {
        $this->assertNotSame('', $report['title'][$langcode]);
        $this->assertNotSame('', $report['description'][$langcode]);
      }
    }
    $this->assertContains('closed', $finalMappings, 'The example needs a resolved case.');
    $this->assertTrue($hasQuestion, 'The example needs a question back to the reporter.');
    $this->assertArrayHasKey('de', $seed->data['task']);
  }

  /**
   * Stored seeds rebuild to the same data, also without photo files.
   */
  public function testStorageRoundTrip(): void {
    $raw = $this->example();
    $directory = sys_get_temp_dir() . '/demo-seed-' . bin2hex(random_bytes(4));
    mkdir($directory . '/images', 0777, TRUE);
    $image = imagecreatetruecolor(4, 4);
    imagepng($image, $directory . '/images/crossing.png');
    $raw['reports'][0]['photo'] = [
      'file' => 'images/crossing.png',
      'alt' => ['en' => 'Crossing', 'es' => 'Cruce', 'fr' => 'Traversée', 'de' => 'Querung'],
    ];

    $seed = DemoSeed::fromArray($raw, $directory);
    unlink($directory . '/images/crossing.png');
    $rebuilt = DemoSeed::fromStorage($seed->toStorage());

    $this->assertSame($seed->data, $rebuilt->data);
    $this->assertSame($directory . '/images/crossing.png', $rebuilt->data['reports'][0]['photo']['path']);
  }

  /**
   * Invalid seeds are rejected before anything is written.
   */
  #[DataProvider('invalidSeeds')]
  public function testInvalidSeedIsRejected(callable $mutate, string $message): void {
    $raw = $this->example();
    $mutate($raw);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($message);
    DemoSeed::fromArray($raw, sys_get_temp_dir());
  }

  /**
   * Seed mutations that must fail validation.
   */
  public static function invalidSeeds(): array {
    return [
      'customer slug' => [
        static function (array &$raw): void {
          $raw['slug'] = 'amsterdam';
        },
        'demo-',
      ],
      'missing translation' => [
        static function (array &$raw): void {
          unset($raw['reports'][0]['title']['fr']);
        },
        'missing the "fr" text',
      ],
      'markup in a report' => [
        static function (array &$raw): void {
          $raw['reports'][0]['description']['en'] = '<script>x</script>';
        },
        'plain text',
      ],
      'unknown category' => [
        static function (array &$raw): void {
          $raw['reports'][0]['category'] = 'nope';
        },
        'unknown category',
      ],
      'history out of order' => [
        static function (array &$raw): void {
          $raw['reports'][0]['history'][1]['days_ago'] = 30;
        },
        'chronological',
      ],
      'no closed status' => [
        static function (array &$raw): void {
          $raw['statuses'] = array_values(array_filter($raw['statuses'], static fn(array $s): bool => $s['mapping'] !== 'closed'));
          $raw['reports'] = array_values(array_filter($raw['reports'], static fn(array $r): bool => end($r['history'])['status'] !== 'resolved'));
          foreach ($raw['reports'] as &$report) {
            $report['history'] = array_values(array_filter($report['history'], static fn(array $h): bool => $h['status'] !== 'resolved'));
          }
        },
        'initial, open and closed',
      ],
      'photo outside the seed directory' => [
        static function (array &$raw): void {
          $raw['reports'][0]['photo'] = ['file' => '../etc/x.jpg', 'alt' => []];
        },
        'relative to the seed file',
      ],
      'missing photo file' => [
        static function (array &$raw): void {
          $raw['reports'][0]['photo'] = [
            'file' => 'does-not-exist.jpg',
            'alt' => ['en' => 'a', 'es' => 'a', 'fr' => 'a', 'de' => 'a'],
          ];
        },
        'missing, too large',
      ],
      'unknown key' => [
        static function (array &$raw): void {
          $raw['reports'][0]['e_mail'] = 'someone@example.com';
        },
        'unknown keys',
      ],
      'unsupported language' => [
        static function (array &$raw): void {
          $raw['languages'][] = 'xx';
        },
        'Unsupported seed language',
      ],
    ];
  }

  /**
   * Returns the decoded reference seed.
   */
  private function example(): array {
    return Yaml::decode((string) file_get_contents(self::EXAMPLE));
  }

}
