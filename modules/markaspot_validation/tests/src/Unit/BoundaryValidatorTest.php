<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_validation\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\markaspot_validation\Service\BoundaryValidator;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests jurisdiction boundary validation service.
 *
 * @group markaspot_validation
 * @coversDefaultClass \Drupal\markaspot_validation\Service\BoundaryValidator
 */
class BoundaryValidatorTest extends UnitTestCase {

  /**
   * Tests missing boundaries are accepted.
   *
   * @covers ::isWithinJurisdictionBoundary
   */
  public function testMissingBoundaryIsAccepted(): void {
    $group = $this->createMock(ContentEntityInterface::class);
    $group->method('hasField')
      ->with('field_boundary')
      ->willReturn(FALSE);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('submission.boundary_missing'));

    $validator = new BoundaryValidator($this->entityTypeManagerReturning($group), $logger);

    $this->assertTrue($validator->isWithinJurisdictionBoundary(11, 50.0, 7.0));
  }

  /**
   * Tests a point inside a GeoJSON boundary is accepted.
   *
   * @covers ::isWithinJurisdictionBoundary
   */
  public function testInsideBoundaryIsAccepted(): void {
    $validator = new BoundaryValidator(
      $this->entityTypeManagerReturning($this->groupWithBoundary($this->squareBoundary())),
      $this->createMock(LoggerInterface::class),
    );

    $this->assertTrue($validator->isWithinJurisdictionBoundary(11, 0.5, 0.5));
  }

  /**
   * Tests a point outside a GeoJSON boundary is rejected.
   *
   * @covers ::isWithinJurisdictionBoundary
   */
  public function testOutsideBoundaryIsRejected(): void {
    $validator = new BoundaryValidator(
      $this->entityTypeManagerReturning($this->groupWithBoundary($this->squareBoundary())),
      $this->createMock(LoggerInterface::class),
    );

    $this->assertFalse($validator->isWithinJurisdictionBoundary(11, 2.0, 2.0));
  }

  /**
   * Tests a child without geometry falls back to its root boundary.
   *
   * @covers ::isWithinFirstUsableBoundary
   */
  public function testFirstUsableBoundaryFallsBackToRoot(): void {
    $logger = $this->createMock(LoggerInterface::class);
    // The root decides, so the child's missing geometry is not logged.
    $logger->expects($this->never())->method('warning');
    $validator = new BoundaryValidator(
      $this->entityTypeManagerReturningMap([
        11 => $this->groupWithoutBoundary(),
        1 => $this->groupWithBoundary($this->squareBoundary()),
      ]),
      $logger,
    );

    $this->assertTrue($validator->isWithinFirstUsableBoundary([11, 1], 0.5, 0.5));
    $this->assertFalse($validator->isWithinFirstUsableBoundary([11, 1], 2.0, 2.0));
  }

  /**
   * Tests the most specific usable boundary decides.
   *
   * @covers ::isWithinFirstUsableBoundary
   */
  public function testFirstUsableBoundaryPrefersChild(): void {
    $validator = new BoundaryValidator(
      $this->entityTypeManagerReturningMap([
        11 => $this->groupWithBoundary($this->squareBoundary()),
        1 => $this->groupWithBoundary($this->squareBoundary(10)),
      ]),
      $this->createMock(LoggerInterface::class),
    );

    // Inside the root square but outside the child square.
    $this->assertFalse($validator->isWithinFirstUsableBoundary([11, 1], 5.0, 5.0));
  }

  /**
   * Tests no usable boundary at any level is accepted and logged once.
   *
   * @covers ::isWithinFirstUsableBoundary
   */
  public function testFirstUsableBoundaryAcceptsWhenNoneUsable(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('submission.boundary_missing'), $this->callback(
        static fn(array $context): bool => $context['@jurisdiction'] === 11 && $context['@reason'] === 'missing_boundary'
      ));
    $validator = new BoundaryValidator(
      $this->entityTypeManagerReturningMap([
        11 => $this->groupWithoutBoundary(),
        1 => NULL,
      ]),
      $logger,
    );

    $this->assertTrue($validator->isWithinFirstUsableBoundary([11, 1, 11, 0], 2.0, 2.0));
    $this->assertTrue($validator->isWithinFirstUsableBoundary([], 2.0, 2.0), 'No jurisdiction at all is accepted without a log entry.');
  }

  /**
   * Creates an entity type manager returning groups by id.
   *
   * @param array<int, \Drupal\Core\Entity\ContentEntityInterface|null> $groups
   *   Groups keyed by id.
   */
  protected function entityTypeManagerReturningMap(array $groups): EntityTypeManagerInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')
      ->willReturnCallback(static fn($id) => $groups[(int) $id] ?? NULL);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($storage);

    return $entityTypeManager;
  }

  /**
   * Creates a group mock without a field_boundary value.
   */
  protected function groupWithoutBoundary(): ContentEntityInterface {
    $group = $this->createMock(ContentEntityInterface::class);
    $group->method('hasField')
      ->with('field_boundary')
      ->willReturn(FALSE);
    return $group;
  }

  /**
   * Creates an entity type manager returning the provided group.
   */
  protected function entityTypeManagerReturning(?ContentEntityInterface $group): EntityTypeManagerInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')
      ->with(11)
      ->willReturn($group);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($storage);

    return $entityTypeManager;
  }

  /**
   * Creates a group mock with a field_boundary value.
   */
  protected function groupWithBoundary(string $boundary): ContentEntityInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('getString')->willReturn($boundary);

    $group = $this->createMock(ContentEntityInterface::class);
    $group->method('hasField')
      ->with('field_boundary')
      ->willReturn(TRUE);
    $group->method('get')
      ->with('field_boundary')
      ->willReturn($field);

    return $group;
  }

  /**
   * Returns a square GeoJSON polygon from 0/0, one degree wide by default.
   */
  protected function squareBoundary(int $size = 1): string {
    return json_encode([
      'type' => 'Polygon',
      'coordinates' => [
        [
          [0, 0],
          [$size, 0],
          [$size, $size],
          [0, $size],
          [0, 0],
        ],
      ],
    ]);
  }

}
