<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_validation\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_validation\Plugin\Validation\Constraint\MultipleReportsConstraint;
use Drupal\markaspot_validation\Plugin\Validation\Constraint\MultipleReportsConstraintValidator;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Tests the MultipleReportsConstraintValidator.
 *
 * @group markaspot_validation
 * @coversDefaultClass \Drupal\markaspot_validation\Plugin\Validation\Constraint\MultipleReportsConstraintValidator
 */
class MultipleReportsConstraintValidatorTest extends UnitTestCase {

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
   * @var \Drupal\markaspot_validation\Plugin\Validation\Constraint\MultipleReportsConstraint
   */
  protected MultipleReportsConstraint $constraint;

  /**
   * The mocked editable config.
   *
   * @var \Drupal\Core\Config\Config|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Config $editableConfig;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getRequestTime')->willReturn(1700000000);

    $this->requestStack = $this->createMock(RequestStack::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->account = $this->createMock(AccountInterface::class);
    $this->executionContext = $this->createMock(ExecutionContextInterface::class);
    $this->constraint = new MultipleReportsConstraint();

    $this->editableConfig = $this->createMock(Config::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('getEditable')
      ->with('markaspot_validation.settings')
      ->willReturn($this->editableConfig);
  }

  /**
   * Tests that validation is skipped when multiple_reports is disabled.
   *
   * @covers ::validate
   */
  public function testSkippedWhenDisabled(): void {
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'multiple_reports' => 0,
        default => NULL,
      });

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $field = $this->createFieldValue(6.9, 50.9);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests that bypass permission skips the count check.
   *
   * @covers ::validate
   */
  public function testBypassPermissionSkipsCheck(): void {
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'multiple_reports' => 1,
        'max_count' => 5,
        default => NULL,
      });

    $this->account->method('hasPermission')
      ->willReturnCallback(
        fn(string $perm) => $perm === 'bypass mas validation'
      );

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $field = $this->createFieldValue(6.9, 50.9);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests violation when report count meets the max.
   *
   * @covers ::validate
   * @covers ::countReports
   */
  public function testViolationWhenAtMaxCount(): void {
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'multiple_reports' => 1,
        'max_count' => 5,
        default => NULL,
      });

    $this->account->method('hasPermission')
      ->willReturn(FALSE);

    $this->mockEntityQueryReturning(5);
    $this->mockContextRoot('test@example.com');

    $this->executionContext->expects($this->once())
      ->method('addViolation');

    $field = $this->createFieldValue(6.9, 50.9);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests violation when report count exceeds the max.
   *
   * @covers ::validate
   * @covers ::countReports
   */
  public function testViolationWhenOverMaxCount(): void {
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'multiple_reports' => 1,
        'max_count' => 5,
        default => NULL,
      });

    $this->account->method('hasPermission')
      ->willReturn(FALSE);

    $this->mockEntityQueryReturning(10);
    $this->mockContextRoot('test@example.com');

    $this->executionContext->expects($this->once())
      ->method('addViolation');

    $field = $this->createFieldValue(6.9, 50.9);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests no violation when report count is under the max.
   *
   * @covers ::validate
   * @covers ::countReports
   */
  public function testNoViolationUnderMaxCount(): void {
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'multiple_reports' => 1,
        'max_count' => 5,
        default => NULL,
      });

    $this->account->method('hasPermission')
      ->willReturn(FALSE);

    $this->mockEntityQueryReturning(3);
    $this->mockContextRoot('test@example.com');

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $field = $this->createFieldValue(6.9, 50.9);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests that null max_count defaults to 5.
   *
   * @covers ::validate
   */
  public function testNullMaxCountDefaultsToFive(): void {
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'multiple_reports' => 1,
        'max_count' => NULL,
        default => NULL,
      });

    $this->account->method('hasPermission')
      ->willReturn(FALSE);

    // Exactly 5 should trigger (>= 5).
    $this->mockEntityQueryReturning(5);
    $this->mockContextRoot('test@example.com');

    $this->executionContext->expects($this->once())
      ->method('addViolation');

    $field = $this->createFieldValue(6.9, 50.9);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests that 4 reports with default max of 5 passes.
   *
   * @covers ::validate
   */
  public function testFourReportsWithDefaultMaxPasses(): void {
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'multiple_reports' => 1,
        'max_count' => NULL,
        default => NULL,
      });

    $this->account->method('hasPermission')
      ->willReturn(FALSE);

    $this->mockEntityQueryReturning(4);
    $this->mockContextRoot('test@example.com');

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $field = $this->createFieldValue(6.9, 50.9);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Creates the validator with mocked dependencies.
   *
   * @return \Drupal\markaspot_validation\Plugin\Validation\Constraint\MultipleReportsConstraintValidator
   *   The initialized validator.
   */
  protected function createValidator(): MultipleReportsConstraintValidator {
    $validator = new MultipleReportsConstraintValidator(
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

  /**
   * Mocks the entity query to return a specific count of results.
   *
   * @param int $count
   *   Number of node IDs to return.
   */
  protected function mockEntityQueryReturning(int $count): void {
    $nids = range(1, $count);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn($nids);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $this->entityTypeManager->method('getStorage')
      ->with('node')
      ->willReturn($storage);
  }

  /**
   * Mocks the execution context root entity with an email field.
   *
   * @param string $email
   *   The email address on the entity.
   */
  protected function mockContextRoot(string $email): void {
    $emailField = $this->createMock(FieldItemListInterface::class);
    $emailField->method('getValue')
      ->willReturn([['value' => $email]]);

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('get')
      ->with('field_e_mail')
      ->willReturn($emailField);

    $this->executionContext->method('getRoot')
      ->willReturn($entity);
  }

}
