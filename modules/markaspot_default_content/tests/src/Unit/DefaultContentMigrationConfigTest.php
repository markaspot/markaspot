<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_default_content\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests default content migration definitions.
 *
 */
#[Group('markaspot_default_content')]
class DefaultContentMigrationConfigTest extends UnitTestCase {

  /**
   * Tests organisation group migration uses the current org bundle.
   */
  public function testOrganisationMigrationUsesOrgBundle(): void {
    $migration = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/migrations/migrate_plus.migration.markaspot_migrate_default_content_group_organisation.yml'));

    $this->assertSame('entity:group', $migration['destination']['plugin']);
    $this->assertSame('org', $migration['destination']['default_bundle']);
    $this->assertSame([
      'plugin' => 'default_value',
      'default_value' => 'org',
    ], $migration['process']['type']);
  }

  /**
   * Tests plugin alter normalizes stale active migration definitions.
   */
  public function testPluginAlterNormalizesOrganisationBundle(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_default_content.module';

    $container = new ContainerBuilder();
    $container->set('extension.list.module', new DefaultContentModuleListStub());
    \Drupal::setContainer($container);

    $definitions = [
      'markaspot_migrate_default_content_group_organisation' => [
        'source' => [
          'path' => '/artifacts/group_organisation.csv',
        ],
        'process' => [
          'type' => 'type',
        ],
        'destination' => [
          'default_bundle' => 'organisation',
        ],
      ],
    ];

    markaspot_default_content_migration_plugins_alter($definitions);

    $definition = $definitions['markaspot_migrate_default_content_group_organisation'];
    $this->assertSame('modules/markaspot_default_content/artifacts/group_organisation.csv', $definition['source']['path']);
    $this->assertSame('org', $definition['destination']['default_bundle']);
    $this->assertSame([
      'plugin' => 'default_value',
      'default_value' => 'org',
    ], $definition['process']['type']);
  }

}

/**
 * Minimal module list stub for exercising the migration alter hook.
 */
final class DefaultContentModuleListStub {

  /**
   * Gets the module path.
   *
   * @param string $module
   *   The module name.
   *
   * @return string
   *   The module path.
   */
  public function getPath(string $module): string {
    if ($module !== 'markaspot_default_content') {
      throw new \InvalidArgumentException(sprintf('Unexpected module %s.', $module));
    }

    return 'modules/markaspot_default_content';
  }

}
