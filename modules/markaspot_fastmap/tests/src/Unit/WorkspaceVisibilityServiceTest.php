<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceVisibilityService;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the WorkspaceVisibilityService.
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Service\WorkspaceVisibilityService
 * @group markaspot_fastmap
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($this->groupStorage);

    $this->service = new WorkspaceVisibilityService($this->entityTypeManager);
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
      ->with('field_visibility')
      ->willReturn(TRUE);

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
  }

  /**
   * @covers ::getVisibility
   */
  public function testGetVisibilityDefaultsWhenGroupNotFound(): void {
    $this->groupStorage->method('load')->with(999)->willReturn(NULL);

    $this->assertEquals('public', $this->service->getVisibility(999));
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
    $newService = new WorkspaceVisibilityService($newEntityTypeManager);

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

}
