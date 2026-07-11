<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_cap\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Attribute\LegacyHook;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\markaspot_cap\Hook\CapEntityHooks;
use Drupal\markaspot_cap\Service\CapFeedMutationTrackerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests CAP attribute hooks and their legacy compatibility bridges.
 *
 * @group markaspot_cap
 */
class CapModuleHooksTest extends UnitTestCase {

  /**
   * Loads the Drupal 10 compatibility bridges.
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    require_once dirname(__DIR__, 3) . '/markaspot_cap.module';
    require_once dirname(__DIR__, 3) . '/markaspot_cap.install';
  }

  /**
   * Staff receive an explicit approval control on service requests.
   */
  public function testServiceRequestFormDisplaysCapApproval(): void {
    $display = $this->createMock(EntityFormDisplayInterface::class);
    $display->method('getTargetEntityTypeId')->willReturn('node');
    $display->method('getTargetBundle')->willReturn('service_request');
    $display->expects($this->once())
      ->method('setComponent')
      ->with('field_cap_publish', [
        'type' => 'boolean_checkbox',
        'weight' => 95,
        'region' => 'content',
        'settings' => ['display_label' => TRUE],
        'third_party_settings' => [],
      ])
      ->willReturnSelf();

    $hooks = new CapEntityHooks(
      $this->createMock(CapFeedMutationTrackerInterface::class),
    );
    $hooks->entityFormDisplayAlter($display);
  }

  /**
   * Install config keeps citizen-created approval values fail-closed.
   */
  public function testCapApprovalStorageUsesCustomFieldPermissions(): void {
    $path = dirname(__DIR__, 3)
      . '/config/install/field.storage.node.field_cap_publish.yml';
    $config = Yaml::decode((string) file_get_contents($path));

    $this->assertSame('boolean', $config['type'] ?? NULL);
    $this->assertSame(
      'custom',
      $config['third_party_settings']['field_permissions']['permission_type'] ?? NULL,
    );
  }

  /**
   * The shipped CAP example documents the restricted, redacted contract.
   */
  public function testReadmeDoesNotPublishCitizenReportData(): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/README.md');

    $this->assertStringContainsString('<scope>Restricted</scope>', $readme);
    $this->assertStringContainsString(
      '<restriction>Operational emergency responders only</restriction>',
      $readme,
    );
    $this->assertStringNotContainsString('<scope>Public</scope>', $readme);
    $this->assertStringNotContainsString('<description>', $readme);
    $this->assertStringNotContainsString('John Doe', $readme);
    $this->assertStringNotContainsString('123 Main St', $readme);
    $this->assertStringNotContainsString('`description` → `description`', $readme);
    $this->assertStringNotContainsString('`address` → `areaDesc`', $readme);
  }

  /**
   * Existing compatible fields are adopted with a fail-closed default.
   */
  public function testUpdateHookForcesApprovalDefaultOff(): void {
    $source = (string) file_get_contents(
      dirname(__DIR__, 3) . '/markaspot_cap.install',
    );

    $this->assertStringContainsString(
      "->setDefaultValue([['value' => 0]])",
      $source,
    );
    $this->assertStringContainsString(
      "->setThirdPartySetting('field_permissions', 'permission_type', 'custom')",
      $source,
    );
  }

  /**
   * The scope migration changes a legacy public setting to restricted.
   */
  public function testScopeUpdateMigratesLegacyPublicSetting(): void {
    $config = $this->createMock(Config::class);
    $config->expects($this->once())
      ->method('get')
      ->with('defaults.scope')
      ->willReturn('Public');
    $config->expects($this->once())
      ->method('set')
      ->with('defaults.scope', 'Restricted')
      ->willReturnSelf();
    $config->expects($this->once())->method('save');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->expects($this->once())
      ->method('getEditable')
      ->with('markaspot_cap.settings')
      ->willReturn($config);
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')
      ->willReturnCallback(static fn(string $string): string => $string);
    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('string_translation', $translation);
    \Drupal::setContainer($container);

    $this->assertIsString(\markaspot_cap_update_11901());
  }

  /**
   * Drupal 11 discovers each CAP hook without loading the module file.
   */
  public function testDrupal11HooksAreAttributed(): void {
    $expected = [
      'entityFormDisplayAlter' => 'entity_form_display_alter',
      'entityInsert' => 'entity_insert',
      'entityUpdate' => 'entity_update',
      'entityDelete' => 'entity_delete',
      'cron' => 'cron',
    ];

    foreach ($expected as $method => $hook) {
      $attributes = (new \ReflectionMethod(CapEntityHooks::class, $method))
        ->getAttributes(Hook::class);
      $this->assertCount(1, $attributes);
      $this->assertSame($hook, $attributes[0]->newInstance()->hook);
    }
  }

  /**
   * The update hook carries the original entity into the feed invalidation.
   */
  public function testEntityUpdatePassesTheOriginalContentEntity(): void {
    $tracker = $this->createMock(CapFeedMutationTrackerInterface::class);
    $hooks = new CapEntityHooks($tracker);
    $entity = $this->createMock(ContentEntityInterface::class);
    $original = $this->createMock(ContentEntityInterface::class);
    $entity->method('getOriginal')->willReturn($original);
    $tracker->expects($this->once())
      ->method('markEntityChanged')
      ->with($entity, $original);

    $hooks->entityUpdate($entity);
  }

  /**
   * Drupal 10 wrappers delegate to the same hook service.
   *
   * Drupal 11 does not double-register them.
   */
  public function testLegacyInsertBridgeDelegatesToTheHookService(): void {
    $tracker = $this->createMock(CapFeedMutationTrackerInterface::class);
    $hooks = new CapEntityHooks($tracker);
    $container = new ContainerBuilder();
    $container->set(CapEntityHooks::class, $hooks);
    \Drupal::setContainer($container);

    $entity = $this->createMock(ContentEntityInterface::class);
    $tracker->expects($this->once())
      ->method('markEntityChanged')
      ->with($entity);

    \markaspot_cap_entity_insert($entity);

    foreach ([
      'markaspot_cap_entity_form_display_alter',
      'markaspot_cap_entity_insert',
      'markaspot_cap_entity_update',
      'markaspot_cap_entity_delete',
      'markaspot_cap_cron',
    ] as $function) {
      $this->assertCount(
        1,
        (new \ReflectionFunction($function))->getAttributes(LegacyHook::class),
      );
    }
  }

  /**
   * Non-content entities never touch CAP feed state.
   */
  public function testNonContentEntityDoesNotTouchTheTracker(): void {
    $tracker = $this->createMock(CapFeedMutationTrackerInterface::class);
    $tracker->expects($this->never())->method('markEntityChanged');
    $hooks = new CapEntityHooks($tracker);

    $hooks->entityInsert($this->createMock(EntityInterface::class));
    $hooks->entityUpdate($this->createMock(EntityInterface::class));
    $hooks->entityDelete($this->createMock(EntityInterface::class));
  }

}
