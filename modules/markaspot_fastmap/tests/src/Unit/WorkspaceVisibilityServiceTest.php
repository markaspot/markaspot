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
    ];
  }

}
