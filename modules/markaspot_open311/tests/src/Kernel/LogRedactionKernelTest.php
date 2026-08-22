<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\YamlFileLoader;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_open311\Logger\RedactingLoggerDecorator;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

require_once dirname(__DIR__, 3) . '/markaspot_open311.install';
require_once dirname(__DIR__, 3) . '/src/Logger/LogRedactor.php';
require_once dirname(__DIR__, 3) . '/src/Logger/RedactingLoggerDecorator.php';

/**
 * Tests dblog decoration and legacy watchdog credential scrubbing.
 *
 * @group markaspot_open311
 */
#[RunTestsInSeparateProcesses]
final class LogRedactionKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'node',
    'taxonomy',
    'serialization',
    'rest',
    'token',
    'dblog',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'markaspot_open311',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    // The running Drupal root resolves markaspot_open311 from the main
    // checkout. Load this worktree's real service file last so the test covers
    // the definitions that will ship in the commit under test.
    $module_root = dirname(__DIR__, 3);
    $loader = new YamlFileLoader($container);
    $loader->load($module_root . '/markaspot_open311.services.yml');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('dblog', ['watchdog']);
  }

  /**
   * Tests dblog is decorated and request URI credentials are not persisted.
   */
  public function testDblogDecoratorRedactsLoggerChannelRequestUri(): void {
    $this->assertInstanceOf(
      RedactingLoggerDecorator::class,
      $this->container->get('logger.dblog'),
    );

    $request = Request::create('/georeport/v2/requests.json?api_key=secret123');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    \Drupal::logger('test')->notice('Redaction integration test.');

    $location = $this->database()
      ->select('watchdog', 'w')
      ->fields('w', ['location'])
      ->condition('type', 'test')
      ->orderBy('wid', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $this->assertIsString($location);
    $this->assertStringContainsString('api_key=[REDACTED]', $location);
    $this->assertStringNotContainsString('secret123', $location);
  }

  /**
   * Tests update 11812 scrubs legacy rows in sandbox batches.
   */
  public function testUpdate11812ScrubsExistingRowsInBatches(): void {
    for ($index = 1; $index <= 51; $index++) {
      $location = $index % 2 === 0
        ? "/path?api_key=legacy-secret-$index&foo=bar"
        : "/path?foo=bar&TOKEN=legacy-secret-$index";
      $this->insertWatchdogRow(
        $location,
        "/source?access-token%3Dreferer-secret-$index%26page%3D1",
      );
    }
    $clean_wid = $this->insertWatchdogRow('/path?foo=bar', '/source?page=1');

    $sandbox = [];
    $summary = markaspot_open311_update_11812($sandbox);
    $this->assertInstanceOf(TranslatableMarkup::class, $summary);
    $this->assertLessThan(1, $sandbox['#finished']);

    while ($sandbox['#finished'] < 1) {
      $summary = markaspot_open311_update_11812($sandbox);
    }

    $this->assertSame(51, $sandbox['updated']);
    $this->assertStringContainsString('51', (string) $summary);

    $rows = $this->database()
      ->select('watchdog', 'w')
      ->fields('w', ['wid', 'location', 'referer'])
      ->orderBy('wid', 'ASC')
      ->execute()
      ->fetchAllAssoc('wid');
    foreach ($rows as $wid => $row) {
      if ((int) $wid === $clean_wid) {
        $this->assertSame('/path?foo=bar', $row->location);
        $this->assertSame('/source?page=1', $row->referer);
        continue;
      }

      $this->assertStringContainsString('[REDACTED]', $row->location);
      $this->assertStringContainsString('[REDACTED]', $row->referer);
      $this->assertStringNotContainsString('legacy-secret', $row->location);
      $this->assertStringNotContainsString('referer-secret', $row->referer);
    }
  }

  /**
   * Tests update 11812 skips sites without the dblog table.
   */
  public function testUpdate11812SkipsMissingWatchdogTable(): void {
    $this->database()->schema()->dropTable('watchdog');

    $sandbox = [];
    $summary = markaspot_open311_update_11812($sandbox);

    $this->assertSame(1, $sandbox['#finished']);
    $this->assertSame(0, $sandbox['updated']);
    $this->assertStringContainsString('does not exist', (string) $summary);
  }

  /**
   * Returns the test database connection.
   */
  private function database(): Connection {
    return $this->container->get('database');
  }

  /**
   * Inserts a legacy watchdog row.
   */
  private function insertWatchdogRow(string $location, ?string $referer): int {
    return (int) $this->database()
      ->insert('watchdog')
      ->fields([
        'uid' => 0,
        'type' => 'legacy',
        'message' => 'Legacy row.',
        'variables' => serialize([]),
        'severity' => 5,
        'link' => '',
        'location' => $location,
        'referer' => $referer,
        'hostname' => '127.0.0.1',
        'timestamp' => 1,
      ])
      ->execute();
  }

}
