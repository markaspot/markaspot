<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_validation\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\markaspot_validation\Plugin\Validation\Constraint\MultipleReportsConstraint;
use Drupal\markaspot_validation\Plugin\Validation\Constraint\MultipleReportsConstraintValidator;
use Drupal\node\NodeInterface;
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
   * The mocked Open311 settings config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ImmutableConfig $open311Config;

  /**
   * The configured jurisdiction group bundle.
   */
  protected string $jurisdictionGroupType = 'jur';

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
    $this->open311Config = $this->createMock(ImmutableConfig::class);
    $this->open311Config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturnCallback(fn(string $key) => $key === 'jurisdiction_group_type' ? $this->jurisdictionGroupType : NULL);
    $this->configFactory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($this->open311Config);
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
   * Uses the configured plural in the visible repeated-email 422 detail.
   *
   * @covers ::resolveCitizenLimitMessage
   */
  public function testViolationUsesConfiguredCitizenPlural(): void {
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'multiple_reports' => 1,
        'max_count' => 5,
        default => NULL,
      });
    $this->account->method('hasPermission')->willReturn(FALSE);
    $this->mockEntityQueryReturning(5);
    $this->mockContextRootWithWording('test@example.com', 'entry', 'de');

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with($this->callback(static function (mixed $message): bool {
        $detail = (string) $message;
        return $detail === 'Das tägliche E-Mail-Kontingent ist erreicht. Einträge: 5. Bitte versuchen Sie es später erneut.';
      }));

    $field = $this->createFieldValue(6.9, 50.9);
    $this->createValidator(new CitizenWordingResolver())->validate($field, $this->constraint);
  }

  /**
   * Resolves wording through the configured jurisdiction group bundle.
   *
   * @covers ::resolveCitizenLimitMessage
   */
  public function testViolationUsesConfiguredJurisdictionGroupType(): void {
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'multiple_reports' => 1,
        'max_count' => 5,
        default => NULL,
      });
    $this->jurisdictionGroupType = 'jurisdiction';
    $this->account->method('hasPermission')->willReturn(FALSE);
    $this->mockEntityQueryReturning(5);
    $this->mockContextRootWithWording('test@example.com', 'contribution', 'de', 'jurisdiction');

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with('Das tägliche E-Mail-Kontingent ist erreicht. Beiträge: 5. Bitte versuchen Sie es später erneut.');

    $field = $this->createFieldValue(6.9, 50.9);
    $this->createValidator(new CitizenWordingResolver())->validate($field, $this->constraint);
  }

  /**
   * Uses the active content locale rather than an unstamped node locale.
   *
   * @covers ::resolveResponseLangcode
   */
  public function testViolationUsesCurrentContentLanguage(): void {
    $this->editableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'multiple_reports' => 1,
        'max_count' => 5,
        default => NULL,
      });
    $this->account->method('hasPermission')->willReturn(FALSE);
    $this->mockEntityQueryReturning(5);
    $this->mockContextRootWithWording('test@example.com', 'entry', 'en');

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with('La limite quotidienne d’e-mail est atteinte. saisies : 5. Veuillez réessayer plus tard.');

    $field = $this->createFieldValue(6.9, 50.9);
    $this->createValidator(new CitizenWordingResolver(), $this->createLanguageManager('fr'))
      ->validate($field, $this->constraint);
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
  protected function createValidator(?CitizenWordingResolver $citizenWordingResolver = NULL, ?LanguageManagerInterface $languageManager = NULL): MultipleReportsConstraintValidator {
    $validator = new MultipleReportsConstraintValidator(
      $this->time,
      $this->requestStack,
      $this->entityTypeManager,
      $this->configFactory,
      $this->account,
      $citizenWordingResolver,
      $languageManager,
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

  /**
   * Mocks the root service request with its citizen terminology setting.
   */
  protected function mockContextRootWithWording(string $email, string $wording, string $langcode, string $groupType = 'jur'): void {
    $emailField = $this->createMock(FieldItemListInterface::class);
    $emailField->method('getValue')->willReturn([['value' => $email]]);

    $configField = $this->createMock(FieldItemListInterface::class);
    $configField->method('isEmpty')->willReturn(FALSE);
    $configField->method('__get')
      ->with('value')
      ->willReturn(json_encode(['i18n' => ['wording' => $wording]]));

    $group = $this->createMock(GroupInterface::class);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('bundle')->willReturn($groupType);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasField')->with('field_nuxt_config')->willReturn(TRUE);
    $group->method('get')->with('field_nuxt_config')->willReturn($configField);

    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn($langcode);

    $entity = $this->createMock(NodeInterface::class);
    $entity->method('hasField')
      ->willReturnCallback(fn(string $field) => in_array($field, ['field_e_mail', 'field_jurisdiction'], TRUE));
    $entity->method('get')
      ->willReturnCallback(fn(string $field) => match ($field) {
        'field_e_mail' => $emailField,
        'field_jurisdiction' => $jurisdictionField,
        default => NULL,
      });
    $entity->method('language')->willReturn($language);

    $this->executionContext->method('getRoot')->willReturn($entity);
  }

  /**
   * Creates a language manager with a fixed active content locale.
   */
  protected function createLanguageManager(string $langcode): LanguageManagerInterface {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn($langcode);

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')
      ->with(LanguageInterface::TYPE_CONTENT)
      ->willReturn($language);

    return $languageManager;
  }

}
