<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_validation\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\markaspot_validation\Plugin\Validation\Constraint\DefaultLocationConstraint;
use Drupal\markaspot_validation\Plugin\Validation\Constraint\DefaultLocationConstraintValidator;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Tests the DefaultLocationConstraintValidator.
 *
 * @group markaspot_validation
 * @coversDefaultClass \Drupal\markaspot_validation\Plugin\Validation\Constraint\DefaultLocationConstraintValidator
 */
class DefaultLocationConstraintValidatorTest extends UnitTestCase {

  /**
   * The mocked time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected TimeInterface $time;

  /**
   * The mocked request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack|\PHPUnit\Framework\MockObject\MockObject
   */
  protected RequestStack $requestStack;

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
   * The mocked current user.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected AccountInterface $account;

  /**
   * The mocked execution context.
   *
   * @var \Symfony\Component\Validator\Context\ExecutionContextInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ExecutionContextInterface $executionContext;

  /**
   * The constraint being tested.
   *
   * @var \Drupal\markaspot_validation\Plugin\Validation\Constraint\DefaultLocationConstraint
   */
  protected DefaultLocationConstraint $constraint;

  /**
   * The mocked editable config.
   *
   * @var \Drupal\Core\Config\Config|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Config $editableConfig;

  /**
   * Default geolocation coordinates (matching field_geolocation defaults).
   *
   * @var float
   */
  protected float $defaultLng = 6.958;

  /**
   * Default geolocation latitude.
   *
   * @var float
   */
  protected float $defaultLat = 50.941;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->time = $this->createMock(TimeInterface::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->account = $this->createMock(AccountInterface::class);
    $this->executionContext = $this->createMock(ExecutionContextInterface::class);
    $this->constraint = new DefaultLocationConstraint();

    // Mock the editable config.
    $this->editableConfig = $this->createMock(Config::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('getEditable')
      ->with('markaspot_validation.settings')
      ->willReturn($this->editableConfig);

    // Mock the field_config for geolocation defaults.
    $fieldConfig = $this->createMock(FieldConfigInterface::class);
    $fieldConfig->method('get')
      ->with('default_value')
      ->willReturn([
        [
          'lng' => $this->defaultLng,
          'lat' => $this->defaultLat,
        ],
      ]);

    $fieldConfigStorage = $this->createMock(EntityStorageInterface::class);
    $fieldConfigStorage->method('load')
      ->with('node.service_request.field_geolocation')
      ->willReturn($fieldConfig);

    $this->entityTypeManager->method('getStorage')
      ->with('field_config')
      ->willReturn($fieldConfigStorage);

    // Mock string translation.
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')
      ->willReturnCallback(fn($input) => $input);
  }

  /**
   * Tests that validation is skipped when defaultLocation check is disabled.
   *
   * @covers ::validate
   */
  public function testSkippedWhenDefaultLocationCheckDisabled(): void {
    $this->account->method('hasPermission')
      ->willReturn(FALSE);
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'defaultLocation' => '0',
        default => NULL,
      });

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $field = $this->createFieldValue($this->defaultLng, $this->defaultLat);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests that validation is skipped when config is null (defaults to '0').
   *
   * @covers ::validate
   */
  public function testSkippedWhenConfigIsNull(): void {
    $this->account->method('hasPermission')
      ->willReturn(FALSE);
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'defaultLocation' => NULL,
        default => NULL,
      });

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $field = $this->createFieldValue($this->defaultLng, $this->defaultLat);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests violation when coordinates match the default location.
   *
   * @covers ::validate
   * @covers ::checkDefaultLocation
   */
  public function testViolationWhenCoordinatesMatchDefault(): void {
    $this->account->method('hasPermission')
      ->willReturn(FALSE);
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'defaultLocation' => '1',
        default => NULL,
      });

    $this->executionContext->expects($this->once())
      ->method('addViolation');

    $field = $this->createFieldValue($this->defaultLng, $this->defaultLat);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests no violation when coordinates differ from the default location.
   *
   * @covers ::validate
   * @covers ::checkDefaultLocation
   */
  public function testNoViolationWhenCoordinatesDiffer(): void {
    $this->account->method('hasPermission')
      ->willReturn(FALSE);
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'defaultLocation' => '1',
        default => NULL,
      });

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    // Different coordinates.
    $field = $this->createFieldValue(7.0, 51.0);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests that users with bypass permission skip validation.
   *
   * @covers ::validate
   */
  public function testBypassPermissionSkipsValidation(): void {
    $this->account->method('hasPermission')
      ->willReturnCallback(
        fn(string $perm) => $perm === 'bypass mas validation'
      );
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'defaultLocation' => '1',
        default => NULL,
      });

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    // Even with default coordinates, bypass user should pass.
    $field = $this->createFieldValue($this->defaultLng, $this->defaultLat);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests that slightly different coordinates pass validation.
   *
   * @covers ::validate
   * @covers ::checkDefaultLocation
   */
  public function testSlightlyDifferentCoordinatesPass(): void {
    $this->account->method('hasPermission')
      ->willReturn(FALSE);
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'defaultLocation' => '1',
        default => NULL,
      });

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    // Slightly different from default.
    $field = $this->createFieldValue(
      $this->defaultLng + 0.001,
      $this->defaultLat + 0.001
    );
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Creates the validator with mocked dependencies.
   *
   * @return \Drupal\markaspot_validation\Plugin\Validation\Constraint\DefaultLocationConstraintValidator
   *   The initialized validator.
   */
  protected function createValidator(): DefaultLocationConstraintValidator {
    $validator = new DefaultLocationConstraintValidator(
      $this->time,
      $this->requestStack,
      $this->entityTypeManager,
      $this->configFactory,
      $this->account,
    );
    $validator->initialize($this->executionContext);
    $validator->setStringTranslation($this->getStringTranslationStub());
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

}
