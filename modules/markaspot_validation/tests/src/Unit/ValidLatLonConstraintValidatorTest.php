<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_validation\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\markaspot_validation\Plugin\Validation\Constraint\ValidLatLonConstraint;
use Drupal\markaspot_validation\Plugin\Validation\Constraint\ValidLatLonConstraintValidator;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Tests the ValidLatLonConstraintValidator.
 *
 * @group markaspot_validation
 * @coversDefaultClass \Drupal\markaspot_validation\Plugin\Validation\Constraint\ValidLatLonConstraintValidator
 */
class ValidLatLonConstraintValidatorTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The mocked execution context.
   *
   * @var \Symfony\Component\Validator\Context\ExecutionContextInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ExecutionContextInterface $executionContext;

  /**
   * The constraint being tested.
   *
   * @var \Drupal\markaspot_validation\Plugin\Validation\Constraint\ValidLatLonConstraint
   */
  protected ValidLatLonConstraint $constraint;

  /**
   * The mocked config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ImmutableConfig $config;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->executionContext = $this->createMock(ExecutionContextInterface::class);
    $this->constraint = new ValidLatLonConstraint();

    $this->config = $this->createMock(ImmutableConfig::class);
    $this->configFactory->method('get')
      ->with('markaspot_validation.settings')
      ->willReturn($this->config);
  }

  /**
   * Tests that validation passes when no boundary is configured.
   *
   * @covers ::validate
   */
  public function testPassesWhenNoBoundaryConfigured(): void {
    // No jurisdiction resolved, no WKT configured.
    $this->mockNoJurisdiction();
    $this->config->method('get')
      ->with('wkt')
      ->willReturn(NULL);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $field = $this->createFieldValue(6.9578, 50.9413);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests that validation passes when point is inside WKT polygon.
   *
   * @covers ::validate
   */
  public function testPassesWhenInsideWktPolygon(): void {
    $this->mockNoJurisdiction();
    // A WKT polygon around Cologne.
    $wkt = 'POLYGON ((6.8 50.8, 7.1 50.8, 7.1 51.0, 6.8 51.0, 6.8 50.8))';
    $this->config->method('get')
      ->with('wkt')
      ->willReturn($wkt);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    // Cologne Cathedral coordinates.
    $field = $this->createFieldValue(6.9578, 50.9413);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests that validation fails when point is outside WKT polygon.
   *
   * @covers ::validate
   */
  public function testFailsWhenOutsideWktPolygon(): void {
    $this->mockNoJurisdiction();
    // A WKT polygon around Cologne.
    $wkt = 'POLYGON ((6.8 50.8, 7.1 50.8, 7.1 51.0, 6.8 51.0, 6.8 50.8))';
    $this->config->method('get')
      ->with('wkt')
      ->willReturn($wkt);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with($this->constraint->noValidViewboxMessage);

    // Berlin coordinates: far outside Cologne.
    $field = $this->createFieldValue(13.405, 52.52);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests that empty WKT string means no boundary (passes).
   *
   * @covers ::validate
   */
  public function testEmptyWktStringPasses(): void {
    $this->mockNoJurisdiction();
    $this->config->method('get')
      ->with('wkt')
      ->willReturn('');

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $field = $this->createFieldValue(0.0, 0.0);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests jurisdiction GeoJSON boundary takes precedence over WKT.
   *
   * @covers ::validate
   */
  public function testJurisdictionBoundaryTakesPrecedence(): void {
    // Set up a jurisdiction with a small boundary around (5,5).
    $this->mockJurisdictionWithBoundary(1, json_encode([
      'type' => 'Polygon',
      'coordinates' => [
        [[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]],
      ],
    ]));

    // WKT would reject, but jurisdiction boundary accepts.
    $wkt = 'POLYGON ((-1 -1, -0.5 -1, -0.5 -0.5, -1 -0.5, -1 -1))';
    $this->config->method('get')
      ->with('wkt')
      ->willReturn($wkt);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $field = $this->createFieldValue(5.0, 5.0);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests violation when point is outside jurisdiction boundary.
   *
   * @covers ::validate
   */
  public function testFailsWhenOutsideJurisdictionBoundary(): void {
    $this->mockJurisdictionWithBoundary(1, json_encode([
      'type' => 'Polygon',
      'coordinates' => [
        [[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]],
      ],
    ]));

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with($this->constraint->noValidViewboxMessage);

    $field = $this->createFieldValue(50.0, 50.0);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests fallback to WKT when jurisdiction has no boundary field.
   *
   * @covers ::validate
   */
  public function testFallsBackToWktWhenNoBoundaryField(): void {
    $this->mockJurisdictionWithoutBoundary(1);

    // WKT polygon that includes the test point.
    $wkt = 'POLYGON ((0 0, 10 0, 10 10, 0 10, 0 0))';
    $this->config->method('get')
      ->with('wkt')
      ->willReturn($wkt);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $field = $this->createFieldValue(5.0, 5.0);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests that zero coordinates (0,0) are handled correctly.
   *
   * @covers ::validate
   */
  public function testZeroCoordinatesHandled(): void {
    $this->mockNoJurisdiction();
    // Polygon that does NOT include 0,0.
    $wkt = 'POLYGON ((5 5, 10 5, 10 10, 5 10, 5 5))';
    $this->config->method('get')
      ->with('wkt')
      ->willReturn($wkt);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with($this->constraint->noValidViewboxMessage);

    $field = $this->createFieldValue(0.0, 0.0);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Creates the validator with mocked dependencies.
   *
   * @return \Drupal\markaspot_validation\Plugin\Validation\Constraint\ValidLatLonConstraintValidator
   *   The initialized validator.
   */
  protected function createValidator(): ValidLatLonConstraintValidator {
    $validator = new ValidLatLonConstraintValidator(
      $this->entityTypeManager,
      $this->configFactory,
    );
    $validator->initialize($this->executionContext);
    return $validator;
  }

  /**
   * Creates a mock field value with lng/lat properties.
   *
   * @param float $lng
   *   Longitude.
   * @param float $lat
   *   Latitude.
   *
   * @return object
   *   An object with lng and lat properties.
   */
  protected function createFieldValue(float $lng, float $lat): object {
    return new class ($lng, $lat) {

      /**
       * Longitude.
       *
       * @var float
       */
      public float $lng;

      /**
       * Latitude.
       *
       * @var float
       */
      public float $lat;

      /**
       * Constructs the field value.
       */
      public function __construct(float $lng, float $lat) {
        $this->lng = $lng;
        $this->lat = $lat;
      }

    };
  }

  /**
   * Configures the execution context to not resolve a jurisdiction.
   *
   * Sets up the context root to return an object without getEntity().
   */
  protected function mockNoJurisdiction(): void {
    $root = $this->createMock(TypedDataInterface::class);
    $this->executionContext->method('getRoot')
      ->willReturn($root);
  }

  /**
   * Configures a jurisdiction group with a GeoJSON boundary.
   *
   * @param int $groupId
   *   The group entity ID.
   * @param string $geoJson
   *   The GeoJSON string for the boundary field.
   */
  protected function mockJurisdictionWithBoundary(int $groupId, string $geoJson): void {
    // Build the group entity with boundary field.
    $boundaryField = $this->createMock(FieldItemListInterface::class);
    $boundaryField->method('isEmpty')->willReturn(FALSE);
    $boundaryField->method('__get')
      ->with('value')
      ->willReturn($geoJson);

    $group = $this->createMock(ContentEntityInterface::class);
    $group->method('hasField')
      ->willReturnCallback(fn(string $name) => $name === 'field_boundary');
    $group->method('get')
      ->with('field_boundary')
      ->willReturn($boundaryField);

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')
      ->with($groupId)
      ->willReturn($group);

    // Build the entity with category -> jurisdiction reference.
    $this->setupEntityWithJurisdiction($groupId, $groupStorage);
  }

  /**
   * Configures a jurisdiction group without a boundary field.
   *
   * @param int $groupId
   *   The group entity ID.
   */
  protected function mockJurisdictionWithoutBoundary(int $groupId): void {
    $group = $this->createMock(ContentEntityInterface::class);
    $group->method('hasField')
      ->willReturnCallback(fn(string $name) => $name !== 'field_boundary');

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')
      ->with($groupId)
      ->willReturn($group);

    $this->setupEntityWithJurisdiction($groupId, $groupStorage);
  }

  /**
   * Sets up the execution context root with a validated entity.
   *
   * The entity is new (no group_relationship), so jurisdiction
   * resolution falls through to the category-based fallback.
   *
   * @param int $jurisdictionId
   *   The target jurisdiction group ID.
   * @param \Drupal\Core\Entity\EntityStorageInterface $groupStorage
   *   The mocked group storage.
   */
  protected function setupEntityWithJurisdiction(
    int $jurisdictionId,
    EntityStorageInterface $groupStorage,
  ): void {
    // Build a term with field_jurisdiction pointing to the group.
    $jurisdictionField = $this->createMock(FieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('__get')
      ->with('target_id')
      ->willReturn($jurisdictionId);

    $term = $this->createMock(ContentEntityInterface::class);
    $term->method('hasField')
      ->with('field_jurisdiction')
      ->willReturn(TRUE);
    $term->method('get')
      ->with('field_jurisdiction')
      ->willReturn($jurisdictionField);

    // Build the category field on the entity.
    $categoryField = $this->createMock(FieldItemListInterface::class);
    $categoryField->method('isEmpty')->willReturn(FALSE);
    $categoryField->method('__get')
      ->with('entity')
      ->willReturn($term);

    // Build the validated entity (new, so no group relationship).
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('isNew')->willReturn(TRUE);
    $entity->method('hasField')
      ->with('field_category')
      ->willReturn(TRUE);
    $entity->method('get')
      ->with('field_category')
      ->willReturn($categoryField);

    // Build a typed data root that wraps the entity.
    $root = new class ($entity) {

      /**
       * The wrapped entity.
       *
       * @var \Drupal\Core\Entity\ContentEntityInterface
       */
      private ContentEntityInterface $entity;

      /**
       * Constructs the root wrapper.
       */
      public function __construct(ContentEntityInterface $entity) {
        $this->entity = $entity;
      }

      /**
       * Returns the entity.
       */
      public function getEntity(): ContentEntityInterface {
        return $this->entity;
      }

    };

    $this->executionContext->method('getRoot')
      ->willReturn($root);

    // Wire up entity type manager storages.
    $relationshipStorage = $this->createMock(EntityStorageInterface::class);
    $relationshipStorage->method('loadByProperties')
      ->willReturn([]);

    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(
        fn(string $type) => match ($type) {
          'group_relationship' => $relationshipStorage,
          'group' => $groupStorage,
          default => $this->createMock(EntityStorageInterface::class),
        }
      );
  }

}
