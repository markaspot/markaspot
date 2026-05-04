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
   * Returns a one-degree square GeoJSON polygon around 0.5/0.5.
   */
  protected function squareBoundary(): string {
    return json_encode([
      'type' => 'Polygon',
      'coordinates' => [
        [
          [0, 0],
          [1, 0],
          [1, 1],
          [0, 1],
          [0, 0],
        ],
      ],
    ]);
  }

}
