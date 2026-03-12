<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the WorkspaceProvisioningService.
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService
 * @group markaspot_fastmap
 */
class WorkspaceProvisioningServiceTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Connection $database;

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerInterface $logger;

  /**
   * The mocked group storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $groupStorage;

  /**
   * The mocked taxonomy term storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $termStorage;

  /**
   * The mocked user storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $userStorage;

  /**
   * The mocked group relationship storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $relationshipStorage;

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService
   */
  protected WorkspaceProvisioningService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->termStorage = $this->createMock(EntityStorageInterface::class);
    $this->userStorage = $this->createMock(EntityStorageInterface::class);
    $this->relationshipStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $this->groupStorage,
        'taxonomy_term' => $this->termStorage,
        'user' => $this->userStorage,
        'group_relationship' => $this->relationshipStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $this->database = $this->createMock(Connection::class);
    $transaction = $this->createTransactionStub();
    $this->database->method('startTransaction')->willReturn($transaction);

    $this->logger = $this->createMock(LoggerInterface::class);

    $this->service = new WorkspaceProvisioningService(
      $this->entityTypeManager,
      $this->database,
      $this->logger,
    );
  }

  /**
   * Returns valid workspace data for provisioning.
   *
   * @param array $overrides
   *   Optional overrides for specific keys.
   *
   * @return array
   *   Workspace data array.
   */
  protected function validData(array $overrides = []): array {
    return array_merge([
      'name' => 'Test Workspace',
      'slug' => 'test-ws',
      'email' => 'admin@example.com',
      'categories' => ['Road Damage', 'Flood'],
      'lat' => 50.9,
      'lng' => 6.9,
      'zoom' => 14,
      'template' => 'civic-report',
      'language' => '',
      'boundary' => NULL,
    ], $overrides);
  }

  /**
   * Configures mocks for a successful provisioning flow.
   *
   * @param int $groupId
   *   The group ID to assign.
   * @param int $userId
   *   The user ID to assign.
   */
  protected function setupSuccessfulProvisioning(int $groupId = 42, int $userId = 10): void {
    // Group storage: slug not taken.
    $this->groupStorage->method('loadByProperties')
      ->willReturn([]);

    // Group entity mock.
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($groupId);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);

    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);

    $this->groupStorage->method('create')->willReturn($group);

    // Term storage: create terms that return incremental IDs.
    $termIdCounter = 0;
    $this->termStorage->method('create')
      ->willReturnCallback(function () use (&$termIdCounter) {
        $termIdCounter++;
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn($termIdCounter);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        return $term;
      });

    // User storage: no existing user, create new.
    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn($userId);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);

    // Relationship storage: no existing membership.
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);
  }

  /**
   * Tests successful workspace provisioning.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceSuccess(): void {
    $this->setupSuccessfulProvisioning(42, 10);

    $result = $this->service->provisionWorkspace($this->validData());

    $this->assertEquals(42, $result['group_id']);
    $this->assertEquals('test-ws', $result['slug']);
    $this->assertEquals('Test Workspace', $result['name']);
    $this->assertEquals('/test-ws', $result['url']);
    // 2 categories (Road Damage, Flood).
    $this->assertEquals(2, $result['categories']);
    $this->assertEquals(10, $result['user_id']);
  }

  /**
   * Tests that missing name throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRequiresName(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('name, slug and email are required');

    $this->service->provisionWorkspace($this->validData(['name' => '']));
  }

  /**
   * Tests that missing slug throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRequiresSlug(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('name, slug and email are required');

    $this->service->provisionWorkspace($this->validData(['slug' => '']));
  }

  /**
   * Tests that missing email throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRequiresEmail(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('name, slug and email are required');

    $this->service->provisionWorkspace($this->validData(['email' => '']));
  }

  /**
   * Tests that invalid slug format throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   * @dataProvider invalidSlugProvider
   */
  public function testProvisionWorkspaceRejectsInvalidSlug(string $slug): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('slug must be 2-30 chars');

    $this->service->provisionWorkspace($this->validData(['slug' => $slug]));
  }

  /**
   * Data provider for invalid slugs.
   *
   * @return array
   *   Test cases with invalid slug values.
   */
  public static function invalidSlugProvider(): array {
    return [
      'too short' => ['a'],
      'uppercase' => ['TestSlug'],
      'spaces' => ['test slug'],
      'special chars' => ['test_slug!'],
      'too long' => [str_repeat('a', 31)],
    ];
  }

  /**
   * Tests that empty categories throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRequiresCategories(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('categories must be provided');

    $this->service->provisionWorkspace($this->validData(['categories' => []]));
  }

  /**
   * Tests that categories with only empty strings throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRejectsEmptyStringCategories(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('categories must contain at least one non-empty string');

    $this->service->provisionWorkspace($this->validData(['categories' => ['', '  ']]));
  }

  /**
   * Tests that duplicate slug throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRejectsDuplicateSlug(): void {
    $existingGroup = $this->createMock(GroupInterface::class);
    $this->groupStorage->method('loadByProperties')
      ->with(['field_slug' => 'test-ws'])
      ->willReturn([$existingGroup]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Slug already taken');

    $this->service->provisionWorkspace($this->validData());
  }

  /**
   * Tests that too many categories throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRejectsTooManyCategories(): void {
    $categories = [];
    for ($i = 0; $i < 31; $i++) {
      $categories[] = 'Category ' . $i;
    }

    $this->groupStorage->method('loadByProperties')->willReturn([]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Maximum 30 categories allowed');

    $this->service->provisionWorkspace($this->validData(['categories' => $categories]));
  }

  /**
   * Tests provisioning with multilingual categories.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceMultilingualCategories(): void {
    $this->setupSuccessfulProvisioning();

    $result = $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'en' => ['Road Damage', 'Flood'],
        'de' => ['Strassenschaden', 'Hochwasser'],
      ],
      'language' => 'en',
    ]));

    $this->assertEquals(2, $result['categories']);
    $this->assertEquals(42, $result['group_id']);
  }

  /**
   * Tests that lat is clamped to valid range.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceClampLatitude(): void {
    $this->setupSuccessfulProvisioning();

    // Should not throw, lat is clamped to [-90, 90].
    $result = $this->service->provisionWorkspace($this->validData(['lat' => 200.0]));
    $this->assertEquals(42, $result['group_id']);
  }

  /**
   * Tests that lng is clamped to valid range.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceClampLongitude(): void {
    $this->setupSuccessfulProvisioning();

    // Should not throw, lng is clamped to [-180, 180].
    $result = $this->service->provisionWorkspace($this->validData(['lng' => -999.0]));
    $this->assertEquals(42, $result['group_id']);
  }

  /**
   * Tests provisioning with a known template.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceWithTemplate(): void {
    $this->setupSuccessfulProvisioning();

    $result = $this->service->provisionWorkspace($this->validData([
      'template' => 'crisis-map',
    ]));

    $this->assertEquals(42, $result['group_id']);
  }

  /**
   * Tests that unknown template falls back to civic-report.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceUnknownTemplateFallback(): void {
    $this->setupSuccessfulProvisioning();

    // Should not throw, unknown template falls back to civic-report.
    $result = $this->service->provisionWorkspace($this->validData([
      'template' => 'nonexistent-template',
    ]));

    $this->assertEquals(42, $result['group_id']);
  }

  /**
   * Tests provisioning with a Polygon boundary.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceWithBoundary(): void {
    $this->setupSuccessfulProvisioning();

    $boundary = [
      'type' => 'Polygon',
      'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]],
    ];

    $result = $this->service->provisionWorkspace($this->validData([
      'boundary' => $boundary,
    ]));

    $this->assertEquals(42, $result['group_id']);
  }

  /**
   * Tests that existing user is reused during provisioning.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceReusesExistingUser(): void {
    // Group storage: slug not taken.
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    // Group entity mock.
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);

    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);

    $this->groupStorage->method('create')->willReturn($group);

    // Term storage.
    $this->termStorage->method('create')
      ->willReturnCallback(function () {
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn(1);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        return $term;
      });

    // User storage: existing user found.
    $existingUser = $this->createMock(UserInterface::class);
    $existingUser->method('id')->willReturn(99);
    $this->userStorage->method('loadByProperties')
      ->with(['mail' => 'admin@example.com'])
      ->willReturn([$existingUser]);
    // create() should NOT be called since user exists.
    $this->userStorage->expects($this->never())->method('create');

    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    $result = $this->service->provisionWorkspace($this->validData());
    $this->assertEquals(99, $result['user_id']);
  }

  /**
   * Tests that provisioning failure rolls back and throws.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRollsBackOnFailure(): void {
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    // Group creation throws an exception.
    $this->groupStorage->method('create')
      ->willThrowException(new \Exception('DB error'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Workspace provisioning failed'),
        $this->anything()
      );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Workspace provisioning failed');

    $this->service->provisionWorkspace($this->validData());
  }

  /**
   * Tests teardown of a workspace.
   *
   * @covers ::teardownWorkspace
   */
  public function testTeardownWorkspaceSuccess(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $this->groupStorage->method('load')->with(42)->willReturn($group);

    // Memberships.
    $memberUser = $this->createMock(UserInterface::class);
    $memberUser->method('id')->willReturn(10);

    $membershipEntity = $this->createMock(GroupRelationshipInterface::class);
    $membershipEntity->method('getEntity')->willReturn($memberUser);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturnCallback(function (array $props) use ($membershipEntity) {
        if (isset($props['gid'])) {
          return [$membershipEntity];
        }
        return [];
      });

    // Query for terms to delete.
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($query);

    // Query for other memberships.
    $countQuery = $this->createMock(QueryInterface::class);
    $countQuery->method('accessCheck')->willReturnSelf();
    $countQuery->method('condition')->willReturnSelf();
    $countQuery->method('count')->willReturnSelf();
    $countQuery->method('execute')->willReturn(0);
    $this->relationshipStorage->method('getQuery')->willReturn($countQuery);

    // User with no other memberships should be deleted.
    $deletableUser = $this->createMock(UserInterface::class);
    $deletableUser->expects($this->once())->method('delete');
    $this->userStorage->method('load')->with(10)->willReturn($deletableUser);

    $group->expects($this->once())->method('delete');

    $this->service->teardownWorkspace(42);
  }

  /**
   * Tests that teardown of nonexistent group throws RuntimeException.
   *
   * @covers ::teardownWorkspace
   */
  public function testTeardownWorkspaceGroupNotFound(): void {
    $this->groupStorage->method('load')->with(999)->willReturn(NULL);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Group not found: 999');

    $this->service->teardownWorkspace(999);
  }

  /**
   * Tests that teardown skips user with uid <= 1.
   *
   * @covers ::teardownWorkspace
   */
  public function testTeardownWorkspaceSkipsAdminUser(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $this->groupStorage->method('load')->with(42)->willReturn($group);

    // Membership with admin user (uid=1).
    $adminUser = $this->createMock(UserInterface::class);
    $adminUser->method('id')->willReturn(1);

    $membershipEntity = $this->createMock(GroupRelationshipInterface::class);
    $membershipEntity->method('getEntity')->willReturn($adminUser);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturnCallback(function (array $props) use ($membershipEntity) {
        if (isset($props['gid'])) {
          return [$membershipEntity];
        }
        return [];
      });

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($query);

    // Admin user should never be loaded for deletion.
    $this->userStorage->expects($this->never())->method('load');

    $this->service->teardownWorkspace(42);
  }

  /**
   * Tests that teardown rolls back on failure.
   *
   * @covers ::teardownWorkspace
   */
  public function testTeardownWorkspaceRollsBackOnFailure(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('delete')
      ->willThrowException(new \Exception('Delete failed'));
    $this->groupStorage->method('load')->with(42)->willReturn($group);

    // Empty memberships so we get to group->delete().
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($query);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Workspace teardown failed'),
        $this->anything()
      );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Workspace teardown failed');

    $this->service->teardownWorkspace(42);
  }

  /**
   * Tests provisioning with a valid language selection.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceWithSpecificLanguage(): void {
    $this->setupSuccessfulProvisioning();

    $result = $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'de' => ['Strassenschaden', 'Hochwasser'],
        'en' => ['Road Damage', 'Flood'],
      ],
      'language' => 'de',
    ]));

    $this->assertEquals(42, $result['group_id']);
    $this->assertEquals(2, $result['categories']);
  }

  /**
   * Tests provisioning with invalid language falls back to first available.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceInvalidLanguageFallback(): void {
    $this->setupSuccessfulProvisioning();

    $result = $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'en' => ['Road Damage'],
      ],
      'language' => 'xx',
    ]));

    $this->assertEquals(42, $result['group_id']);
  }

  /**
   * Tests that name is truncated to 255 characters.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceTruncatesLongName(): void {
    $this->setupSuccessfulProvisioning();

    $longName = str_repeat('A', 300);
    $result = $this->service->provisionWorkspace($this->validData([
      'name' => $longName,
    ]));

    // Should succeed (name is truncated internally).
    $this->assertEquals(42, $result['group_id']);
    $this->assertEquals(255, mb_strlen($result['name']));
  }

  /**
   * Creates a Transaction stub that avoids readonly property issues.
   *
   * The Drupal Transaction class uses readonly promoted constructor properties,
   * which cannot be mocked with disableOriginalConstructor(). This creates
   * an anonymous class that extends Transaction without calling the parent
   * constructor.
   *
   * @return \Drupal\Core\Database\Transaction
   *   A transaction stub.
   */
  protected function createTransactionStub(): Transaction {
    return new class () extends Transaction {

      /**
       * {@inheritdoc}
       */
      public function __construct() {
        // Intentionally empty: skip parent constructor to avoid readonly
        // property initialization and Database::commitAllOnShutdown().
      }

      /**
       * {@inheritdoc}
       */
      public function __destruct() {
        // Intentionally empty: prevent access to uninitialized properties.
      }

      /**
       * {@inheritdoc}
       */
      public function rollBack() {
        // No-op for testing.
      }

    };
  }

}
