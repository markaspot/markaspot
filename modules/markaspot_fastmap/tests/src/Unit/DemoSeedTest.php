<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\markaspot_fastmap\Demo\DemoSeed;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService;
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

    // The demo addresses visitors informally like the marketing pages: du in
    // German, tú in Spanish, vous in French.
    $texts = ['de' => [], 'es' => [], 'fr' => []];
    $data = $seed->data;
    array_walk_recursive($data, static function (mixed $value, int|string $key) use (&$texts): void {
      if (is_string($value) && isset($texts[$key])) {
        $texts[$key][] = strip_tags($value);
      }
    });
    foreach ($texts['de'] as $text) {
      // Formal pronouns, outside the start of a sentence.
      $this->assertDoesNotMatchRegularExpression('/(?<![.!?:]\s)(?<!^)\b(Sie|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihnen)\b/u', $text);
    }
    foreach ($texts['es'] as $text) {
      $this->assertDoesNotMatchRegularExpression('/\b(usted|ustedes|su aviso|su respuesta|su camino)\b/iu', $text);
    }
    foreach ($texts['fr'] as $text) {
      $this->assertDoesNotMatchRegularExpression('/\b(tu|ton|ta|tes|toi)\b/iu', $text);
    }

    $photos = array_filter(array_column($seed->data['reports'], 'photo', 'key'));
    $this->assertCount(count($seed->data['reports']), $photos, 'Every report of the example has a photo.');
    foreach ($photos as $photo) {
      $this->assertFileExists($photo['path']);
      $this->assertSame([1280, 960], array_slice(getimagesize($photo['path']), 0, 2));
      $this->assertSame(['en', 'es', 'fr', 'de'], array_keys($photo['alt']));
    }
  }

  /**
   * The example mirrors the statuses a real workspace of its template gets.
   *
   * A demo must not promise a workflow the template does not provision.
   */
  public function testShippedExampleMirrorsTemplateStatuses(): void {
    $seed = DemoSeed::fromFile(self::EXAMPLE);
    $template = WorkspaceProvisioningService::STATUS_TEMPLATES[WorkspaceProvisioningService::resolveTemplate($seed->data['template'])];

    $expected = [];
    foreach ($template as $status) {
      $expected[] = [
        'key' => $status['key'],
        'mapping' => $status['mapping'],
        'name' => array_combine($seed->data['languages'], array_map(
          static fn(string $langcode): string => $status['name'][$langcode],
          $seed->data['languages'],
        )),
        'hex' => $status['hex'],
        'icon' => $status['icon'],
      ];
    }
    $this->assertSame($expected, $seed->data['statuses']);
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

    foreach (array_slice($raw['reports'], 1) as $report) {
      if (isset($report['photo'])) {
        copy(dirname(self::EXAMPLE) . '/' . $report['photo']['file'], $directory . '/' . $report['photo']['file']);
      }
    }

    $seed = DemoSeed::fromArray($raw, $directory);
    array_map('unlink', glob($directory . '/images/*') ?: []);
    rmdir($directory . '/images');
    rmdir($directory);
    $rebuilt = DemoSeed::fromStorage($seed->toStorage());

    $this->assertSame($seed->data, $rebuilt->data);
    $this->assertSame($directory . '/images/crossing.png', $rebuilt->data['reports'][0]['photo']['path']);
  }

  /**
   * Explicit times are kept; the report time is the first entry's time.
   */
  public function testExplicitTimes(): void {
    $raw = $this->example();
    $raw['reports'][0]['time'] = '07:52';
    $raw['reports'][0]['history'][1]['time'] = '20:40';
    $raw['reports'][0]['history'][2]['time'] = '06:05';
    unset($raw['reports'][0]['history'][3]['time']);
    $report = DemoSeed::fromArray($raw, dirname(self::EXAMPLE))->data['reports'][0];

    $this->assertArrayNotHasKey('time', $report);
    $this->assertSame(['07:52', '20:40', '06:05'], array_slice(array_column($report['history'], 'time'), 0, 3));
    $this->assertSame(DemoSeed::derivedTime($report['key'], 3), $report['history'][3]['time']);
  }

  /**
   * Missing times are derived: plausible, stable and in order.
   */
  public function testDerivedTimes(): void {
    $times = [];
    foreach (['dangerous-crossing', 'blocked-sidewalk', 'hedge-corner', 'speeding'] as $key) {
      foreach (range(0, 3) as $index) {
        $time = DemoSeed::derivedTime($key, $index);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $time);
        $this->assertGreaterThanOrEqual('07:00', $time);
        $this->assertLessThanOrEqual('18:30', $time);
        $this->assertSame($time, DemoSeed::derivedTime($key, $index), 'The same entry always gets the same time.');
        $times[] = $time;
      }
    }
    $this->assertGreaterThan(8, count(array_unique($times)), 'Times differ between entries.');

    // A later entry on the same day comes at least 15 minutes later, also
    // when the earlier one is late in the day.
    $after = DemoSeed::derivedTime('speeding', 1, '09:00');
    $this->assertGreaterThanOrEqual('09:15', $after);
    $this->assertLessThanOrEqual('18:30', $after);
    $this->assertSame('20:15', DemoSeed::derivedTime('speeding', 1, '20:00'));

    // Seed parsing applies the same rule.
    $raw = $this->example();
    unset($raw['reports'][0]['time'], $raw['reports'][0]['history'][1]['time']);
    $raw['reports'][0]['history'][1]['days_ago'] = $raw['reports'][0]['history'][0]['days_ago'];
    $raw['reports'][0]['history'][0]['time'] = '18:20';
    $history = DemoSeed::fromArray($raw, dirname(self::EXAMPLE))->data['reports'][0]['history'];
    $this->assertSame('18:35', $history[1]['time']);
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
    DemoSeed::fromArray($raw, dirname(self::EXAMPLE));
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
      'malformed time' => [
        static function (array &$raw): void {
          $raw['reports'][0]['history'][1]['time'] = '7:5';
        },
        'needs a time as HH:MM',
      ],
      'time on the report and its first entry' => [
        static function (array &$raw): void {
          $raw['reports'][0]['time'] = '07:30';
          $raw['reports'][0]['history'][0]['time'] = '07:40';
        },
        'sets its time twice',
      ],
      'earlier time on the same day' => [
        static function (array &$raw): void {
          unset($raw['reports'][0]['time']);
          $raw['reports'][0]['history'][1]['days_ago'] = $raw['reports'][0]['history'][0]['days_ago'];
          $raw['reports'][0]['history'][0]['time'] = '18:00';
          $raw['reports'][0]['history'][1]['time'] = '09:00';
        },
        'not later than the entry before it',
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
