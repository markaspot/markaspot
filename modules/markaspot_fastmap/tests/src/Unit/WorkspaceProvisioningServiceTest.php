<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService;
use Drupal\node\NodeInterface;
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
   * The mocked language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LanguageManagerInterface $languageManager;

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
   * The mocked node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $nodeStorage;

  /**
   * The mocked configurable language storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $langStorage;

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
    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);

    $this->langStorage = $this->createMock(EntityStorageInterface::class);
    $langEntity = $this->createMock(\Drupal\Core\Entity\EntityInterface::class);
    $langEntity->method('save')->willReturn(1);
    $this->langStorage->method('create')->willReturn($langEntity);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $this->groupStorage,
        'taxonomy_term' => $this->termStorage,
        'user' => $this->userStorage,
        'node' => $this->nodeStorage,
        'group_relationship' => $this->relationshipStorage,
        'configurable_language' => $this->langStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $this->database = $this->createMock(Connection::class);
    $transaction = $this->createTransactionStub();
    $this->database->method('startTransaction')->willReturn($transaction);

    $this->logger = $this->createMock(LoggerInterface::class);

    // Language manager: return all supported languages as "installed".
    // This prevents ensureLanguagesExist() from calling the static
    // ConfigurableLanguage::createFromLangcode() which needs the container.
    $this->languageManager = $this->createMock(LanguageManagerInterface::class);
    $langMock = $this->createMock(LanguageInterface::class);
    $allLangs = [];
    foreach (['en', 'de', 'nl', 'fr', 'es', 'ar', 'da', 'it', 'pl', 'pt', 'tr', 'uk'] as $code) {
      $allLangs[$code] = $langMock;
    }
    $this->languageManager->method('getLanguages')
      ->willReturn($allLangs);

    $this->service = new WorkspaceProvisioningService(
      $this->entityTypeManager,
      $this->database,
      $this->logger,
      $this->languageManager,
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

    // Node storage: for demo request creation.
    $this->nodeStorage->method('create')
      ->willReturnCallback(function () {
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        return $node;
      });

    // Term storage query: for resolveStatusTermIds().
    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);
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

    // Node storage for demo requests.
    $this->nodeStorage->method('create')
      ->willReturnCallback(function () {
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        return $node;
      });
    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

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
   * Tests that provisioning creates 5 demo service request nodes.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceCreatesDemoRequests(): void {
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

    // Status term query: return two status term IDs.
    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([100, 101]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    // Mock status terms with Open311 mappings.
    $initialTerm = $this->createMock(TermInterface::class);
    $initialTerm->method('id')->willReturn(100);
    $initialTerm->method('hasField')->willReturn(TRUE);
    $initialField = $this->createMock(\Drupal\Core\Field\FieldItemListInterface::class);
    $initialField->method('isEmpty')->willReturn(FALSE);
    $initialField->__set('value', 'initial');
    $initialField->method('__get')->with('value')->willReturn('initial');
    $initialTerm->method('get')->willReturn($initialField);

    $closedTerm = $this->createMock(TermInterface::class);
    $closedTerm->method('id')->willReturn(101);
    $closedTerm->method('hasField')->willReturn(TRUE);
    $closedField = $this->createMock(\Drupal\Core\Field\FieldItemListInterface::class);
    $closedField->method('isEmpty')->willReturn(FALSE);
    $closedField->__set('value', 'closed');
    $closedField->method('__get')->with('value')->willReturn('closed');
    $closedTerm->method('get')->willReturn($closedField);

    $this->termStorage->method('loadMultiple')
      ->with([100, 101])
      ->willReturn([100 => $initialTerm, 101 => $closedTerm]);

    // User storage.
    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);

    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    // Node storage: expect 5 demo nodes + 1 start page to be created.
    $demoCount = 0;
    $pageCount = 0;
    $this->nodeStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$demoCount, &$pageCount) {
        if ($values['type'] === 'service_request') {
          $demoCount++;
          $this->assertArrayHasKey('field_category', $values);
          $this->assertArrayHasKey('field_geolocation', $values);
          $this->assertStringContainsString('[demo-content]', $values['body']['value']);
        }
        elseif ($values['type'] === 'page') {
          $pageCount++;
          $this->assertTrue($values['promote']);
          $this->assertTrue($values['sticky']);
          $this->assertArrayHasKey('field_jurisdiction', $values);
        }

        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        return $node;
      });

    $this->service->provisionWorkspace($this->validData([
      'lat' => 50.9,
      'lng' => 6.9,
    ]));

    $this->assertEquals(5, $demoCount, 'Expected 5 demo requests to be created.');
    $this->assertEquals(1, $pageCount, 'Expected 1 start page to be created.');
  }

  /**
   * Tests demo requests use coordinates within boundary bbox.
   *
   * The boundary is stored in field_boundary (FeatureCollection) and the
   * map center is stored in field_nuxt_config. The provisioning service
   * reads both from the group entity rather than from $data, so the mock
   * must expose these fields.
   *
   * @covers ::provisionWorkspace
   */
  public function testDemoRequestsUseBoundaryBbox(): void {
    // Group storage.
    $this->groupStorage->method('loadByProperties')->willReturn([]);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);
    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);
    $this->groupStorage->method('create')->willReturn($group);

    // Boundary polygon covering lat 10-11, lng 20-21.
    $boundaryGeometry = [
      'type' => 'Polygon',
      'coordinates' => [[[20, 10], [21, 10], [21, 11], [20, 11], [20, 10]]],
    ];

    // Mock field_nuxt_config: center at [lng=20.5, lat=10.5] (inside boundary).
    $nuxtConfigJson = json_encode([
      'map' => ['center' => [20.5, 10.5], 'zoomInitial' => 13],
    ]);
    $nuxtConfigField = $this->createMock(\Drupal\Core\Field\FieldItemListInterface::class);
    $nuxtConfigField->method('isEmpty')->willReturn(FALSE);
    $nuxtConfigField->method('__get')->with('value')->willReturn($nuxtConfigJson);

    // Mock field_boundary: FeatureCollection wrapping the boundary polygon.
    $boundaryJson = json_encode([
      'type' => 'FeatureCollection',
      'features' => [
        [
          'type' => 'Feature',
          'properties' => ['name' => 'Test'],
          'geometry' => $boundaryGeometry,
        ],
      ],
    ]);
    $boundaryField = $this->createMock(\Drupal\Core\Field\FieldItemListInterface::class);
    $boundaryField->method('isEmpty')->willReturn(FALSE);
    $boundaryField->method('__get')->with('value')->willReturn($boundaryJson);

    $group->method('hasField')->willReturn(TRUE);
    $group->method('get')->willReturnCallback(
      function (string $fieldName) use ($nuxtConfigField, $boundaryField) {
        return match ($fieldName) {
          'field_nuxt_config' => $nuxtConfigField,
          'field_boundary' => $boundaryField,
          default => $this->createMock(\Drupal\Core\Field\FieldItemListInterface::class),
        };
      }
    );

    // Terms.
    $this->termStorage->method('create')
      ->willReturnCallback(function () {
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn(1);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        return $term;
      });
    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    // User.
    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    // Track coordinates from created demo nodes (skip page node).
    $coords = [];
    $this->nodeStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$coords) {
        if ($values['type'] === 'service_request') {
          $coords[] = $values['field_geolocation'];
        }
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        return $node;
      });

    $this->service->provisionWorkspace($this->validData([
      'boundary' => $boundaryGeometry,
    ]));

    $this->assertCount(5, $coords);
    foreach ($coords as $coord) {
      $this->assertGreaterThanOrEqual(10.0, $coord['lat'], 'Lat should be >= 10');
      $this->assertLessThanOrEqual(11.0, $coord['lat'], 'Lat should be <= 11');
      $this->assertGreaterThanOrEqual(20.0, $coord['lng'], 'Lng should be >= 20');
      $this->assertLessThanOrEqual(21.0, $coord['lng'], 'Lng should be <= 21');
    }
  }

  /**
   * Tests demo requests use German templates when language is 'de'.
   *
   * @covers ::provisionWorkspace
   */
  public function testDemoRequestsUseLanguageTemplates(): void {
    // Group storage.
    $this->groupStorage->method('loadByProperties')->willReturn([]);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);
    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);
    $this->groupStorage->method('create')->willReturn($group);

    // Terms.
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
    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    // User.
    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    // Node storage: track created demo titles and langcodes (skip page).
    $titles = [];
    $this->nodeStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$titles) {
        if ($values['type'] === 'service_request') {
          $titles[] = $values['title'];
          $this->assertEquals('de', $values['langcode']);
        }
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        return $node;
      });

    $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'de' => ['Strassenschaden', 'Hochwasser'],
        'en' => ['Road Damage', 'Flood'],
      ],
      'language' => 'de',
    ]));

    $this->assertCount(5, $titles);
    // First title should be German.
    $this->assertEquals('Defekte Straßenlaterne', $titles[0]);
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
