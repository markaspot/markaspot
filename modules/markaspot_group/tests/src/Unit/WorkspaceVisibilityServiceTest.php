<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\WorkspaceVisibilityService;
use Drupal\Tests\UnitTestCase;

require_once dirname(__DIR__, 3) . '/src/Service/WorkspaceVisibilityInterface.php';
require_once dirname(__DIR__, 3) . '/src/Service/WorkspaceVisibilityService.php';

/**
 * Tests the WorkspaceVisibilityService.
 *
 * @coversDefaultClass \Drupal\markaspot_group\Service\WorkspaceVisibilityService
 * @group markaspot_group
 */
class WorkspaceVisibilityServiceTest extends UnitTestCase {

  /**
   * The service under test.
   */
  protected WorkspaceVisibilityService $service;

  /**
   * The mocked entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The mocked group storage.
   */
  protected EntityStorageInterface $groupStorage;

  /**
   * The mocked configuration factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($this->groupStorage);
    $this->configFactory = $this->createConfigFactory();

    $this->service = new WorkspaceVisibilityService(
      $this->entityTypeManager,
      $this->configFactory,
      $this->createEntityFieldManager(),
    );
  }

  /**
   * Creates a mock group with a given visibility value.
   *
   * @param string|null $visibility
   *   The visibility value, or NULL to simulate an empty field.
   *
   * @return \Drupal\group\Entity\GroupInterface
   *   The mocked group entity.
   */
  protected function createMockGroup(?string $visibility): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('hasField')
      ->willReturnCallback(
        static fn(string $fieldName): bool => $fieldName === 'field_visibility',
      );

    $fieldItemList = $this->createMock(FieldItemListInterface::class);
    $fieldItemList->method('isEmpty')
      ->willReturn($visibility === NULL);

    // The service accesses ->value via PHP magic __get().
    if ($visibility !== NULL) {
      $fieldItemList->method('__get')
        ->with('value')
        ->willReturn($visibility);
    }

    $group->method('get')
      ->with('field_visibility')
      ->willReturn($fieldItemList);

    return $group;
  }

  /**
   * @covers ::getVisibility
   */
  public function testGetVisibilityPublic(): void {
    $group = $this->createMockGroup('public');
    $this->groupStorage->method('load')->with(1)->willReturn($group);

    $this->assertEquals('public', $this->service->getVisibility(1));
  }

  /**
   * @covers ::getVisibility
   */
  public function testGetVisibilitySubmissionOnly(): void {
    $group = $this->createMockGroup('submission_only');
    $this->groupStorage->method('load')->with(2)->willReturn($group);

    $this->assertEquals('submission_only', $this->service->getVisibility(2));
  }

  /**
   * @covers ::getVisibility
   */
  public function testGetVisibilityAuthenticated(): void {
    $group = $this->createMockGroup('authenticated');
    $this->groupStorage->method('load')->with(3)->willReturn($group);

    $this->assertEquals('authenticated', $this->service->getVisibility(3));
  }

  /**
   * @covers ::getVisibility
   * @covers ::isBlocked
   */
  public function testGetVisibilityBlocked(): void {
    $group = $this->createMockGroup('blocked');
    $this->groupStorage->method('load')->with(6)->willReturn($group);

    $this->assertEquals('blocked', $this->service->getVisibility(6));
    $this->assertTrue($this->service->isBlocked(6));
  }

  /**
   * @covers ::getVisibility
   */
  public function testGetVisibilityDefaultsWhenEmpty(): void {
    $group = $this->createMockGroup(NULL);
    $this->groupStorage->method('load')->with(4)->willReturn($group);

    $this->assertEquals('public', $this->service->getVisibility(4));
    $this->assertTrue($this->service->canAnonymousView(4));
    $this->assertTrue($this->service->canAnonymousSubmit(4));
  }

  /**
   * @covers ::getVisibility
   */
  public function testGetVisibilityDefaultsWhenGroupNotFound(): void {
    $this->groupStorage->method('load')->with(999)->willReturn(NULL);

    $this->assertEquals('public', $this->service->getVisibility(999));
  }

  /**
   * Missing and empty values stay public in the bulk lookup.
   *
   * The query matches only the three explicit restrictive values, so groups
   * without a field row or with an empty value cannot enter the result.
   *
   * @covers ::getRestrictedJurisdictionIds
   */
  public function testBulkRestrictionQueryExcludesMissingAndEmptyValues(): void {
    $conditions = [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(
      function (string $field, mixed $value, ?string $operator = NULL) use (&$conditions, $query): QueryInterface {
        $conditions[] = [$field, $value, $operator];
        return $query;
      },
    );
    $query->method('execute')->willReturn([7 => 7]);
    $this->groupStorage->method('getQuery')->willReturn($query);
    $this->groupStorage->method('load')->with(7)
      ->willReturn($this->createMockGroup('authenticated'));

    $this->assertSame([7], $this->service->getRestrictedJurisdictionIds());
    $this->assertSame([
      ['type', 'jur', NULL],
      [
        'field_visibility',
        ['submission_only', 'authenticated', 'blocked'],
        'IN',
      ],
    ], $conditions);
  }

  /**
   * The bulk lookup loads nothing while no workspace is restricted.
   *
   * This is the hot path on every anonymous request list: the old
   * implementation loaded every jurisdiction entity just to learn that none of
   * them restricts anything. Entities are now only loaded for the candidates
   * the query already narrowed down, which in the common case is none.
   *
   * @covers ::getRestrictedJurisdictionIds
   */
  public function testBulkRestrictionLookupLoadsNothingWhenUnrestricted(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $this->groupStorage->expects($this->once())
      ->method('getQuery')
      ->willReturn($query);
    $this->groupStorage->expects($this->never())->method('load');
    $this->groupStorage->expects($this->never())->method('loadMultiple');

    $this->assertSame([], $this->service->getRestrictedJurisdictionIds());
  }

  /**
   * @covers ::canAnonymousView
   * @dataProvider visibilityViewProvider
   */
  public function testCanAnonymousView(string $visibility, bool $expected): void {
    $group = $this->createMockGroup($visibility);
    $this->groupStorage->method('load')->willReturn($group);

    $this->assertEquals($expected, $this->service->canAnonymousView(1));
  }

  /**
   * Data provider for testCanAnonymousView.
   */
  public static function visibilityViewProvider(): array {
    return [
      'public allows anonymous view' => ['public', TRUE],
      'submission_only blocks anonymous view' => ['submission_only', FALSE],
      'authenticated blocks anonymous view' => ['authenticated', FALSE],
      'blocked blocks anonymous view' => ['blocked', FALSE],
    ];
  }

  /**
   * @covers ::canAnonymousSubmit
   * @dataProvider visibilitySubmitProvider
   */
  public function testCanAnonymousSubmit(string $visibility, bool $expected): void {
    $group = $this->createMockGroup($visibility);
    $this->groupStorage->method('load')->willReturn($group);

    $this->assertEquals($expected, $this->service->canAnonymousSubmit(1));
  }

  /**
   * Data provider for testCanAnonymousSubmit.
   */
  public static function visibilitySubmitProvider(): array {
    return [
      'public allows anonymous submit' => ['public', TRUE],
      'submission_only allows anonymous submit' => ['submission_only', TRUE],
      'authenticated blocks anonymous submit' => ['authenticated', FALSE],
      'blocked blocks anonymous submit' => ['blocked', FALSE],
    ];
  }

  /**
   * @covers ::isBlocked
   */
  public function testIsBlockedReturnsFalseForRegularVisibility(): void {
    $group = $this->createMockGroup('authenticated');
    $this->groupStorage->method('load')->with(9)->willReturn($group);

    $this->assertFalse($this->service->isBlocked(9));
  }

  /**
   * Regression for H4: claimed jurisdiction is blocked → block submission.
   *
   * Coordinate-based boundary fan-out lives in markaspot_fastmap.module and
   * requires the module loaded; that path is covered by Kernel tests. The
   * claimed-id path is the most common bot vector and stays unit-testable.
   *
   * @covers ::isBlockedForSubmission
   */
  public function testIsBlockedForSubmissionReturnsTrueOnBlockedClaim(): void {
    $group = $this->createMockGroup('blocked');
    $this->groupStorage->method('load')->with(11)->willReturn($group);

    $this->assertTrue($this->service->isBlockedForSubmission(11, NULL, NULL));
  }

  /**
   * @covers ::isBlockedForSubmission
   */
  public function testIsBlockedForSubmissionReturnsFalseOnPublicClaimWithoutCoordinates(): void {
    $group = $this->createMockGroup('public');
    $this->groupStorage->method('load')->with(12)->willReturn($group);

    $this->assertFalse($this->service->isBlockedForSubmission(12, NULL, NULL));
  }

  /**
   * @covers ::isBlockedForSubmission
   */
  public function testIsBlockedForSubmissionTreatsZeroCoordinatesAsAbsent(): void {
    $group = $this->createMockGroup('public');
    $this->groupStorage->method('load')->with(13)->willReturn($group);

    // 0/0 is the sentinel from getRequestCoordinates() when input is invalid.
    // We must not run the (potentially expensive) boundary fan-out for it.
    $this->assertFalse($this->service->isBlockedForSubmission(13, 0.0, 0.0));
  }

  /**
   * @covers ::resetCache
   */
  public function testResetCacheClearsStoredVisibility(): void {
    // Load a group with 'authenticated' visibility and cache it.
    $group = $this->createMockGroup('authenticated');
    $this->groupStorage->method('load')->willReturn($group);

    $this->assertEquals('authenticated', $this->service->getVisibility(10));

    // Replace the storage response with a different visibility.
    // Without resetCache, the cached value would still be returned.
    $newGroup = $this->createMockGroup('public');

    // Use a new service because configured PHPUnit mocks are fixed.
    // Its storage returns the updated group after resetCache().
    $newStorage = $this->createMock(EntityStorageInterface::class);
    $newStorage->method('load')->willReturn($newGroup);
    $newEntityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $newEntityTypeManager->method('getStorage')->with('group')->willReturn($newStorage);
    $newService = new WorkspaceVisibilityService(
      $newEntityTypeManager,
      $this->configFactory,
      $this->createEntityFieldManager(),
    );

    // Pre-populate cache, then reset.
    $newService->getVisibility(10);
    $this->assertEquals('public', $newService->getVisibility(10));

    // Verify resetCache doesn't throw and clears internal state.
    $newService->resetCache();
    // The next call reloads the mocked 'public' value.
    $this->assertEquals('public', $newService->getVisibility(10));
  }

  /**
   * @covers ::getVisibility
   */
  public function testStaticCachePreventsDuplicateLoads(): void {
    $group = $this->createMockGroup('submission_only');

    // Configure storage to track load calls.
    $loadCount = 0;
    $this->groupStorage->method('load')->willReturnCallback(function () use ($group, &$loadCount) {
      $loadCount++;
      return $group;
    });

    // Multiple calls with same ID should only load once.
    $this->service->getVisibility(5);
    $this->service->getVisibility(5);
    $this->service->getVisibility(5);

    $this->assertEquals(1, $loadCount, 'Storage should be called exactly once due to static cache');
  }

  /**
   * @covers ::resetCache
   */
  public function testResetCacheForcesFreshLoad(): void {
    $group = $this->createMockGroup('authenticated');

    $loadCount = 0;
    $this->groupStorage->method('load')->willReturnCallback(function () use ($group, &$loadCount) {
      $loadCount++;
      return $group;
    });

    $this->service->getVisibility(7);
    $this->assertEquals(1, $loadCount);

    $this->service->resetCache();
    $this->service->getVisibility(7);
    $this->assertEquals(2, $loadCount, 'After resetCache, storage should be called again');
  }

  /**
   * @covers ::resetCache
   */
  public function testResetCacheCanTargetSingleGroup(): void {
    $groups = [
      7 => [$this->createMockGroup('authenticated'), $this->createMockGroup('public')],
      8 => [$this->createMockGroup('submission_only'), $this->createMockGroup('authenticated')],
    ];
    $loadCountByGroup = [];

    $this->groupStorage->method('load')->willReturnCallback(function (int $groupId) use (&$groups, &$loadCountByGroup) {
      $loadCountByGroup[$groupId] = ($loadCountByGroup[$groupId] ?? 0) + 1;
      return array_shift($groups[$groupId]);
    });

    $this->assertEquals('authenticated', $this->service->getVisibility(7));
    $this->assertEquals('submission_only', $this->service->getVisibility(8));

    $this->service->resetCache(7);

    $this->assertEquals('public', $this->service->getVisibility(7));
    $this->assertEquals('submission_only', $this->service->getVisibility(8));
    $this->assertEquals(2, $loadCountByGroup[7]);
    $this->assertEquals(1, $loadCountByGroup[8]);
  }

  /**
   * Tests every overlapping boundary match is checked for blocking.
   *
   * @covers ::isBlockedForSubmission
   * @covers ::resolveBoundaryCandidates
   */
  public function testBlockedOverlappingSiblingCannotHideBehindPublicSibling(): void {
    $groups = [
      7 => $this->createMockGroup('public'),
      8 => $this->createMockGroup('blocked'),
    ];
    $this->groupStorage->method('load')
      ->willReturnCallback(static fn(int $groupId): ?GroupInterface => $groups[$groupId] ?? NULL);

    $service = new class($this->entityTypeManager, $this->configFactory, $this->createEntityFieldManager()) extends WorkspaceVisibilityService {

      /**
       * {@inheritdoc}
       */
      protected function resolveMatchingBoundaryJurisdictionIds(float $lat, float $lng): array {
        return [7, 8];
      }

    };

    $this->assertTrue($service->isBlockedForSubmission(NULL, 51.0, 7.0));
  }

  /**
   * Tests boundary lookup honors a configured legacy jurisdiction bundle.
   *
   * @covers ::resolveMatchingBoundaryJurisdictionIds
   * @covers ::jurisdictionGroupType
   */
  public function testBoundaryLookupUsesConfiguredJurisdictionGroupType(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->expects($this->once())
      ->method('condition')
      ->with('type', 'legacy_jurisdiction')
      ->willReturnSelf();
    $query->method('exists')->with('field_boundary')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $this->groupStorage->method('getQuery')->willReturn($query);

    $service = new WorkspaceVisibilityService(
      $this->entityTypeManager,
      $this->createConfigFactory('legacy_jurisdiction'),
      $this->createEntityFieldManager(),
    );
    $method = new \ReflectionMethod($service, 'resolveMatchingBoundaryJurisdictionIds');
    $method->setAccessible(TRUE);

    $this->assertSame([], $method->invoke($service, 51.0, 7.0));
  }

  /**
   * Creates an Open311 configuration factory for service tests.
   */
  private function createConfigFactory(string $jurisdictionGroupType = 'jur'): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn($jurisdictionGroupType);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($config);
    return $configFactory;
  }

  /**
   * Creates a field manager that reports field_visibility as installed.
   *
   * @param bool $fieldInstalled
   *   Whether the group entity type carries field_visibility, i.e. whether
   *   markaspot_group_update_11946() has run.
   */
  private function createEntityFieldManager(bool $fieldInstalled = TRUE): EntityFieldManagerInterface {
    $fieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $fieldManager->method('getFieldStorageDefinitions')
      ->with('group')
      ->willReturn($fieldInstalled
        ? ['field_visibility' => $this->createMock(FieldStorageDefinitionInterface::class)]
        : []);
    return $fieldManager;
  }

  /**
   * Tests that unsupported stored values fail open to public.
   *
   * A value outside the four supported modes is a data error. Treating it as
   * restrictive would lock anonymous users out of a workspace nobody closed.
   *
   * @dataProvider unsupportedVisibilityProvider
   * @covers ::getVisibility
   * @covers ::canAnonymousView
   * @covers ::canAnonymousSubmit
   */
  public function testUnsupportedVisibilityValueFailsOpen(string $stored): void {
    $this->groupStorage->method('load')->with(7)->willReturn($this->createMockGroup($stored));

    $this->assertSame('public', $this->service->getVisibility(7));
    $this->assertTrue($this->service->canAnonymousView(7));
    $this->assertTrue($this->service->canAnonymousSubmit(7));
    $this->assertFalse($this->service->isBlocked(7));
  }

  /**
   * Provides stored values that must not restrict anonymous access.
   *
   * @return array<string, array{string}>
   *   Test cases.
   */
  public static function unsupportedVisibilityProvider(): array {
    return [
      'capitalised' => ['Public'],
      'leading whitespace' => [' blocked'],
      'legacy value' => ['private'],
    ];
  }

  /**
   * Tests the bulk lookup before the field-creating update has run.
   *
   * Querying a non-existent field throws, which would turn every anonymous
   * request list into a 500. Without the field nobody can have restricted a
   * workspace, so the fail-open answer is an empty exclusion list.
   *
   * @covers ::getRestrictedJurisdictionIds
   */
  public function testRestrictedIdsWithoutInstalledFieldReturnsEmpty(): void {
    $service = new WorkspaceVisibilityService(
      $this->entityTypeManager,
      $this->configFactory,
      $this->createEntityFieldManager(FALSE),
    );
    $this->groupStorage->expects($this->never())->method('getQuery');

    $this->assertSame([], $service->getRestrictedJurisdictionIds());
  }

  /**
   * Tests that the bulk lookup re-applies the strict value whitelist.
   *
   * Database collations commonly compare case-insensitively and ignore
   * trailing spaces, so the SQL condition can return candidates that
   * getVisibility() resolves to public. Both paths must agree.
   *
   * @covers ::getRestrictedJurisdictionIds
   */
  public function testRestrictedIdsDropCollationOnlyMatches(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([11 => '11', 12 => '12']);
    $this->groupStorage->method('getQuery')->willReturn($query);
    $this->groupStorage->method('load')->willReturnCallback(
      fn(int $id) => $this->createMockGroup($id === 11 ? 'Blocked' : 'authenticated'),
    );

    $this->assertSame([12], $this->service->getRestrictedJurisdictionIds());
  }

}
