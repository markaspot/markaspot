<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\markaspot_fastmap\Controller\FastMapWorkspaceController;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningServiceInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests FastMap create-workspace pending payload persistence.
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Controller\FastMapWorkspaceController
 * @group markaspot_fastmap
 */
class FastMapWorkspaceCreateWorkspaceTest extends UnitTestCase {

  /**
   * Tests selected_tier is persisted for delayed provisioning.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceStoresSelectedTier(): void {
    $insertedFields = NULL;

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('where')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $insert = $this->createMock(Insert::class);
    $insert->method('fields')
      ->willReturnCallback(function (array $fields) use (&$insertedFields, $insert) {
        $insertedFields = $fields;
        return $insert;
      });
    $insert->method('execute')->willReturn('1');

    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);
    $database->method('insert')->willReturn($insert);
    $database->method('delete')->willReturn($delete);

    $controller = $this->buildController($database);
    $request = Request::create(
      '/api/fastmap/create-workspace',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode([
        'service_key' => 'test-service-key-123',
        'name' => 'Paid Workspace',
        'slug' => 'paid-workspace',
        'email' => 'user@example.com',
        'categories' => ['Road Damage'],
        'selected_tier' => 'pro',
      ])
    );

    $response = $controller->createWorkspace($request);

    $this->assertSame(202, $response->getStatusCode());
    $this->assertIsArray($insertedFields);
    $workspaceData = json_decode($insertedFields['workspace_data'], TRUE);
    $this->assertSame('pro', $workspaceData['selected_tier']);
  }

  /**
   * Tests a valid wording preset is persisted for delayed provisioning.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceStoresValidWording(): void {
    $insertedFields = $this->createWorkspaceAndCaptureInsertedFields([
      'wording' => 'suggestion',
    ]);

    $workspaceData = json_decode($insertedFields['workspace_data'], TRUE);
    $this->assertSame('suggestion', $workspaceData['wording']);
  }

  /**
   * Tests an invalid wording preset is silently dropped, not rejected.
   *
   * Onboarding must never fail on this purely cosmetic choice: an
   * unrecognized value is stored as NULL instead of causing a 4xx response.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceSilentlyDropsInvalidWording(): void {
    $insertedFields = $this->createWorkspaceAndCaptureInsertedFields([
      'wording' => 'not-a-preset',
    ]);

    $workspaceData = json_decode($insertedFields['workspace_data'], TRUE);
    $this->assertArrayHasKey('wording', $workspaceData);
    $this->assertNull($workspaceData['wording']);
  }

  /**
   * Runs createWorkspace() and captures the pending-record insert fields.
   *
   * @param array $extraPayload
   *   Additional key/value pairs merged into the base request payload.
   *
   * @return array
   *   The fields array passed to Insert::fields().
   */
  private function createWorkspaceAndCaptureInsertedFields(array $extraPayload): array {
    $insertedFields = NULL;

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('where')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $insert = $this->createMock(Insert::class);
    $insert->method('fields')
      ->willReturnCallback(function (array $fields) use (&$insertedFields, $insert) {
        $insertedFields = $fields;
        return $insert;
      });
    $insert->method('execute')->willReturn('1');

    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);
    $database->method('insert')->willReturn($insert);
    $database->method('delete')->willReturn($delete);

    $controller = $this->buildController($database);
    $payload = array_merge([
      'service_key' => 'test-service-key-123',
      'name' => 'Test Workspace',
      'slug' => 'test-workspace',
      'email' => 'user@example.com',
      'categories' => ['Road Damage'],
    ], $extraPayload);

    $request = Request::create(
      '/api/fastmap/create-workspace',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($payload)
    );

    $response = $controller->createWorkspace($request);

    $this->assertSame(202, $response->getStatusCode());
    $this->assertIsArray($insertedFields);

    return $insertedFields;
  }

  /**
   * Builds a controller with only createWorkspace dependencies configured.
   */
  private function buildController(Connection $database): FastMapWorkspaceController {
    $fastmapConfig = $this->createMock(ImmutableConfig::class);
    $fastmapConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'service_key' => 'test-service-key-123',
        'verify_base_url' => 'https://example.com',
        'cleanup_days' => 7,
        default => NULL,
      });

    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')
      ->willReturnCallback(fn(string $key) => $key === 'name' ? 'FastMap Test' : NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'markaspot_fastmap.settings' => $fastmapConfig,
        'system.site' => $siteConfig,
        default => $this->createMock(ImmutableConfig::class),
      });

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('loadByProperties')->willReturn([]);

    // FieldStorageConfig::loadByName('group', 'field_tier') resolves through
    // entity_type.manager -> storage('field_storage_config') -> load(). The
    // controller's tier whitelist is derived from this allowed_values map.
    $fieldStorageConfig = $this->createMock(FieldStorageConfig::class);
    $fieldStorageConfig->method('getSetting')
      ->with('allowed_values')
      ->willReturn([
        'free' => 'Free',
        'starter' => 'Starter',
        'pro' => 'Pro',
        'heart' => 'Heart',
      ]);
    $fieldStorageConfigStorage = $this->createMock(EntityStorageInterface::class);
    $fieldStorageConfigStorage->method('load')
      ->with('group.field_tier')
      ->willReturn($fieldStorageConfig);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['group', $groupStorage],
        ['field_storage_config', $fieldStorageConfigStorage],
      ]);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->method('mail')->willReturn(['result' => TRUE]);

    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('database', $database);
    $container->set('entity_type.manager', $entityTypeManager);
    $container->set('markaspot_fastmap.workspace_provisioning', $this->createMock(WorkspaceProvisioningServiceInterface::class));
    $container->set('plugin.manager.mail', $mailManager);
    $container->set('logger.channel.markaspot_fastmap', $this->createMock(LoggerInterface::class));
    $container->set('keyvalue.expirable', $this->createMock(KeyValueExpirableFactoryInterface::class));
    $container->set('request_stack', new RequestStack());
    $container->set('flood', $flood);
    $container->set('lock', $lock);
    \Drupal::setContainer($container);

    return FastMapWorkspaceController::create($container);
  }

}
