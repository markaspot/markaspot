<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_validation\Unit;

use Drupal\Component\Datetime\TimeInterface;
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
use Drupal\markaspot_validation\Plugin\Validation\Constraint\DoublePostConstraint;
use Drupal\markaspot_validation\Plugin\Validation\Constraint\DoublePostConstraintValidator;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Tests the DoublePostConstraintValidator.
 *
 * @group markaspot_validation
 * @coversDefaultClass \Drupal\markaspot_validation\Plugin\Validation\Constraint\DoublePostConstraintValidator
 */
class DoublePostConstraintValidatorTest extends UnitTestCase {

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
   * @var \Drupal\markaspot_validation\Plugin\Validation\Constraint\DoublePostConstraint
   */
  protected DoublePostConstraint $constraint;

  /**
   * The mocked config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ImmutableConfig $config;

  /**
   * The mocked HTTP request.
   *
   * @var \Symfony\Component\HttpFoundation\Request|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Request $request;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getRequestTime')->willReturn(1700000000);

    $this->request = $this->createMock(Request::class);
    $this->request->headers = new HeaderBag();

    $this->requestStack = $this->createMock(RequestStack::class);
    $this->requestStack->method('getCurrentRequest')
      ->willReturn($this->request);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->account = $this->createMock(AccountInterface::class);
    $this->executionContext = $this->createMock(ExecutionContextInterface::class);
    $this->constraint = new DoublePostConstraint();

    $this->config = $this->createMock(ImmutableConfig::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->with('markaspot_validation.settings')
      ->willReturn($this->config);
  }

  /**
   * Tests that validation is skipped when duplicate_check is disabled.
   *
   * @covers ::validate
   */
  public function testSkippedWhenDuplicateCheckDisabled(): void {
    $this->config->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'duplicate_check' => FALSE,
        default => NULL,
      });

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $field = $this->createFieldValue(6.9, 50.9);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests that bypass permission skips validation.
   *
   * @covers ::validate
   */
  public function testBypassPermissionSkipsValidation(): void {
    $this->config->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'duplicate_check' => TRUE,
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
   * Tests no violation when no duplicates are found.
   *
   * @covers ::validate
   */
  public function testNoViolationWhenNoDuplicatesFound(): void {
    $this->setupDuplicateCheck();
    $this->account->method('hasPermission')->willReturn(FALSE);
    $this->mockEntityQuery([]);
    $this->mockContextRootWithCategory(42);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $field = $this->createFieldValue(6.9, 50.9);
    $this->createValidator()->validate($field, $this->constraint);
  }

  /**
   * Tests that the ACKNOWLEDGE_HEADER constant has the expected value.
   *
   * @covers ::ACKNOWLEDGE_HEADER
   */
  public function testAcknowledgeHeaderConstant(): void {
    $this->assertSame(
      'X-Acknowledge-Duplicate',
      DoublePostConstraintValidator::ACKNOWLEDGE_HEADER
    );
  }

  /**
   * Tests duplicate message fallback when legacy nodes miss request_id.
   *
   * @covers ::duplicateNodeRequestId
   */
  public function testDuplicateNodeRequestIdFallsBackToEntityId(): void {
    $requestId = $this->createMock(FieldItemListInterface::class);
    $requestId->method('isEmpty')->willReturn(TRUE);

    $node = $this->createMock(ContentEntityInterface::class);
    $node->method('hasField')
      ->with('request_id')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('request_id')
      ->willReturn($requestId);
    $node->method('id')->willReturn(123);

    $method = new \ReflectionMethod(DoublePostConstraintValidator::class, 'duplicateNodeRequestId');
    $method->setAccessible(TRUE);

    $this->assertSame('123', $method->invoke($this->createValidator(), $node));
  }

  /**
   * Resolves the configured term only for human-readable duplicate text.
   *
   * @covers ::resolveCitizenWording
   */
  public function testResolvesConfiguredCitizenWordingForDuplicateMessage(): void {
    $configField = new class {

      /**
       * The raw Nuxt wording config.
       */
      public string $value = '{"i18n":{"wording":"entry"}}';

      /**
       * Reports the field as populated.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };
    $group = $this->createMock(GroupInterface::class);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasField')->with('field_nuxt_config')->willReturn(TRUE);
    $group->method('get')->with('field_nuxt_config')->willReturn($configField);

    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('de');
    $root = $this->createMock(ContentEntityInterface::class);
    $root->method('hasField')->with('field_jurisdiction')->willReturn(TRUE);
    $root->method('get')->with('field_jurisdiction')->willReturn($jurisdictionField);
    $root->method('language')->willReturn($language);
    $this->executionContext->method('getRoot')->willReturn($root);

    $method = new \ReflectionMethod(DoublePostConstraintValidator::class, 'resolveCitizenWording');
    $wording = $method->invoke($this->createValidator(new CitizenWordingResolver()));
    $this->assertSame('Eintrag', $wording['singular']);
  }

  /**
   * Uses the active content locale for a localized duplicate 422 detail.
   *
   * @covers ::resolveCitizenWording
   * @covers ::resolveResponseLangcode
   * @covers ::formatCitizenValidationMessage
   */
  public function testDuplicateMessageUsesCurrentContentLanguage(): void {
    $configField = new class {

      /**
       * The raw Nuxt wording config.
       */
      public string $value = '{"i18n":{"wording":"entry"}}';

      /**
       * Reports the field as populated.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };
    $group = $this->createMock(GroupInterface::class);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasField')->with('field_nuxt_config')->willReturn(TRUE);
    $group->method('get')->with('field_nuxt_config')->willReturn($configField);

    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $nodeLanguage = $this->createMock(LanguageInterface::class);
    $nodeLanguage->method('getId')->willReturn('en');
    $root = $this->createMock(ContentEntityInterface::class);
    $root->method('hasField')->with('field_jurisdiction')->willReturn(TRUE);
    $root->method('get')->with('field_jurisdiction')->willReturn($jurisdictionField);
    $root->method('language')->willReturn($nodeLanguage);
    $this->executionContext->method('getRoot')->willReturn($root);

    $validator = $this->createValidator(
      new CitizenWordingResolver(),
      $this->createLanguageManager('fr'),
    );
    $wordingMethod = new \ReflectionMethod(DoublePostConstraintValidator::class, 'resolveCitizenWording');
    $wording = $wordingMethod->invoke($validator);

    $this->assertSame('saisie', $wording['singular']);
    $messageMethod = new \ReflectionMethod(DoublePostConstraintValidator::class, 'formatCitizenValidationMessage');
    $this->assertSame(
      'Doublon possible dans la même catégorie. Type : saisie. ID : 42. Distance : 10 m.',
      $messageMethod->invoke($validator, CitizenWordingResolver::VALIDATION_DUPLICATE_VISIBLE, $wording, [
        '@id' => '42',
        '@radius' => '10',
        '@unit' => 'm',
      ]),
    );
    $this->assertStringContainsString(
      'ID : &lt;42&gt;.',
      $messageMethod->invoke($validator, CitizenWordingResolver::VALIDATION_DUPLICATE_VISIBLE, $wording, [
        '@id' => '<42>',
        '@radius' => '10',
        '@unit' => 'm',
      ]),
    );
  }

  /**
   * Sets up the config for duplicate checking to be enabled.
   *
   * @param bool $hint
   *   Whether hint mode is enabled.
   * @param int $radius
   *   The radius in meters/yards.
   * @param string $unit
   *   The unit: 'meters' or 'yards'.
   * @param int $days
   *   How many days to look back.
   */
  protected function setupDuplicateCheck(
    bool $hint = FALSE,
    int $radius = 100,
    string $unit = 'meters',
    int $days = 7,
  ): void {
    $this->config->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'duplicate_check' => TRUE,
        'hint' => $hint,
        'radius' => $radius,
        'unit' => $unit,
        'days' => $days,
        default => NULL,
      });
  }

  /**
   * Creates the validator with mocked dependencies.
   *
   * @return \Drupal\markaspot_validation\Plugin\Validation\Constraint\DoublePostConstraintValidator
   *   The initialized validator.
   */
  protected function createValidator(?CitizenWordingResolver $citizenWordingResolver = NULL, ?LanguageManagerInterface $languageManager = NULL): DoublePostConstraintValidator {
    $validator = new DoublePostConstraintValidator(
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
   * Mocks the entity query to return specific node IDs.
   *
   * @param array $nids
   *   Array of node IDs to return from the query.
   */
  protected function mockEntityQuery(array $nids): void {
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
   * Mocks the execution context root entity with a category field.
   *
   * @param int $categoryTargetId
   *   The target_id for the category reference.
   */
  protected function mockContextRootWithCategory(int $categoryTargetId): void {
    $categoryField = $this->createMock(FieldItemListInterface::class);
    $categoryField->method('getValue')
      ->willReturn([['target_id' => $categoryTargetId]]);

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('get')
      ->with('field_category')
      ->willReturn($categoryField);

    $this->executionContext->method('getRoot')
      ->willReturn($entity);
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
