<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_cap\Kernel;

use Drupal\Core\KeyValueStore\DatabaseStorage;
use Drupal\Core\State\State;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_cap\Service\CapFeedStateStore;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests transaction-current CAP feed State reads.
 *
 * @group markaspot_cap
 */
#[RunTestsInSeparateProcesses]
final class CapFeedStateStoreKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $database = $this->container->get('database');
    $database->schema()->createTable(
      'key_value',
      DatabaseStorage::schemaDefinition(),
    );
  }

  /**
   * A cached State value cannot overwrite a newer committed timestamp.
   */
  public function testAdvanceIgnoresTheRequestStateCache(): void {
    $key = 'markaspot_cap.feed_changed.7';
    $database = $this->container->get('database');
    $serializer = $this->container->get('serialization.phpserialize');
    $state = new State(
      $this->container->get('keyvalue.database'),
      $this->container->get('cache.bootstrap'),
      $this->container->get('lock'),
    );
    $state->set($key, 10);
    $this->assertSame(10, $state->get($key));
    $database->update('key_value')
      ->fields(['value' => $serializer->encode(50)])
      ->condition('collection', 'state')
      ->condition('name', $key)
      ->execute();

    $store = new CapFeedStateStore($database, $serializer, $state);
    $this->assertSame(51, $store->advance($key, 10));

    $state->resetCache();
    $this->assertSame(51, $state->get($key));
  }

}
