<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\file\FileInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_fastmap\Service\PlaceholderSignetGenerator;
use Drupal\markaspot_fastmap\Service\PlaceholderSignetGeneratorInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 3) . '/src/Service/WorkspaceProvisioningService.php';

/**
 * Tests the WorkspaceProvisioningService.
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService
 * @group markaspot_fastmap
 */
class WorkspaceProvisioningServiceTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Connection $database;

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerInterface $logger;

  /**
   * The mocked language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The mocked group storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $groupStorage;

  /**
   * The mocked taxonomy term storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $termStorage;

  /**
   * The mocked user storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $userStorage;

  /**
   * The mocked group relationship storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $relationshipStorage;

  /**
   * The mocked group role storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $groupRoleStorage;

  /**
   * The mocked node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $nodeStorage;

  /**
   * The mocked configurable language storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $langStorage;

  /**
   * Inspectable lock backend used by the service.
   */
  protected LockBackendInterface $lock;

  /**
   * The placeholder signet generator.
   */
  protected PlaceholderSignetGeneratorInterface $placeholderSignetGenerator;

  /**
   * The mocked file repository.
   *
   * @var \Drupal\file\FileRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected FileRepositoryInterface $fileRepository;

  /**
   * Managed files written during provisioning.
   *
   * @var array<int, array{data: string, destination: string, behavior: mixed}>
   */
  protected array $writtenFiles = [];

  /**
   * Directories the service asked to prepare, in order.
   *
   * @var array<int, array{directory: string, options: int}>
   */
  protected array $preparedDirectories = [];

  /**
   * The mocked file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService
   */
  protected WorkspaceProvisioningService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->termStorage = $this->createMock(EntityStorageInterface::class);
    $this->userStorage = $this->createMock(EntityStorageInterface::class);
    $this->relationshipStorage = $this->createMock(EntityStorageInterface::class);
    $this->groupRoleStorage = $this->createMock(EntityStorageInterface::class);
    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);

    $this->langStorage = $this->createMock(EntityStorageInterface::class);
    $langEntity = $this->createMock(EntityInterface::class);
    $langEntity->method('save')->willReturn(1);
    $this->langStorage->method('create')->willReturn($langEntity);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $this->groupStorage,
        'taxonomy_term' => $this->termStorage,
        'user' => $this->userStorage,
        'node' => $this->nodeStorage,
        'group_relationship' => $this->relationshipStorage,
        'group_role' => $this->groupRoleStorage,
        'configurable_language' => $this->langStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $this->database = $this->createMock(Connection::class);
    $transaction = $this->createTransactionStub();
    $this->database->method('startTransaction')->willReturn($transaction);

    $this->logger = $this->createMock(LoggerInterface::class);

    // Language manager: return all supported languages as "installed".
    // This prevents ensureLanguagesExist() from calling the static
    // ConfigurableLanguage::createFromLangcode() which needs the container.
    $this->languageManager = $this->createMock(LanguageManagerInterface::class);
    $langMock = $this->createMock(LanguageInterface::class);
    $allLangs = [];
    foreach (['en', 'de', 'cs', 'nl', 'fr', 'es', 'ar', 'da', 'fi', 'hu', 'it', 'nb', 'pl', 'pt', 'sv', 'tr', 'uk'] as $code) {
      $allLangs[$code] = $langMock;
    }
    $this->languageManager->method('getLanguages')
      ->willReturn($allLangs);

    // Set up a minimal Drupal container for static calls in the service.
    $container = new ContainerBuilder();

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $immutableConfig = $this->createMock(ImmutableConfig::class);
    $immutableConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'checkout_grace_days' => 14,
        'demo_expiry_days' => 5,
        default => NULL,
      });
    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'jurisdiction_group_type' => 'jur',
        default => NULL,
      });
    $configFactory->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'markaspot_fastmap.settings' => $immutableConfig,
        'markaspot_open311.settings' => $open311Config,
        default => $this->createMock(ImmutableConfig::class),
      });
    $container->set('config.factory', $configFactory);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);
    $container->set('datetime.time', $time);

    $this->lock = new class implements LockBackendInterface {

      /**
       * Whether acquire() should succeed.
       */
      public bool $acquireResult = TRUE;

      /**
       * Recorded lock events.
       *
       * @var array<int, array<int, mixed>>
       */
      public array $events = [];

      /**
       * {@inheritdoc}
       */
      public function acquire($name, $timeout = 30.0): bool {
        $this->events[] = ['acquire', $name, $timeout];
        return $this->acquireResult;
      }

      /**
       * {@inheritdoc}
       */
      public function lockMayBeAvailable($name): bool {
        return $this->acquireResult;
      }

      /**
       * {@inheritdoc}
       */
      public function wait($name, $delay = 30): bool {
        return !$this->acquireResult;
      }

      /**
       * {@inheritdoc}
       */
      public function release($name): void {
        $this->events[] = ['release', $name];
      }

      /**
       * {@inheritdoc}
       */
      public function releaseAll($lockId = NULL): void {}

      /**
       * {@inheritdoc}
       */
      public function getLockId(): string {
        return 'workspace-provisioning-test-lock';
      }

    };

    $this->placeholderSignetGenerator = new PlaceholderSignetGenerator();
    $signetFile = $this->createMock(FileInterface::class);
    $signetFile->method('id')->willReturn(123);
    $this->fileRepository = $this->createMock(FileRepositoryInterface::class);
    $this->fileRepository->method('writeData')
      ->willReturnCallback(function (string $data, string $destination, mixed $behavior) use ($signetFile) {
        $this->writtenFiles[] = [
          'data' => $data,
          'destination' => $destination,
          'behavior' => $behavior,
        ];
        return $signetFile;
      });
    $this->fileSystem = $this->createMock(FileSystemInterface::class);
    $this->fileSystem->method('prepareDirectory')
      ->willReturnCallback(function (string &$directory, int $options): bool {
        $this->preparedDirectories[] = ['directory' => $directory, 'options' => $options];
        return TRUE;
      });

    \Drupal::setContainer($container);

    $this->service = new WorkspaceProvisioningService(
      $this->entityTypeManager,
      $this->database,
      $this->logger,
      $this->languageManager,
      $configFactory,
      $time,
      $this->lock,
      $this->placeholderSignetGenerator,
      $this->fileRepository,
      $this->fileSystem,
    );
  }

  /**
   * Returns valid workspace data for provisioning.
   *
   * @param array $overrides
   *   Optional overrides for specific keys.
   *
   * @return array
   *   Workspace data array.
   */
  protected function validData(array $overrides = []): array {
    return array_merge([
      'name' => 'Test Workspace',
      'slug' => 'test-ws',
      'email' => 'admin@example.com',
      'categories' => ['Road Damage', 'Flood'],
      'lat' => 50.9,
      'lng' => 6.9,
      'zoom' => 14,
      'template' => 'civic-report',
      'language' => '',
      'boundary' => NULL,
    ], $overrides);
  }

  /**
   * Configures mocks for a successful provisioning flow.
   *
   * @param int $groupId
   *   The group ID to assign.
   * @param int $userId
   *   The user ID to assign.
   * @param array|null $createdGroupFields
   *   Receives the group create values for assertions.
   * @param array|null $setGroupFields
   *   Receives the fields set on the group entity after creation.
   * @param array $existingFields
   *   Field names the group mock reports via hasField().
   */
  protected function setupSuccessfulProvisioning(
    int $groupId = 42,
    int $userId = 10,
    ?array &$createdGroupFields = NULL,
    ?array &$setGroupFields = NULL,
    // Mirrors the shipped profile: field_favicon exists on no install, so the
    // default here is the configuration the code actually meets.
    array $existingFields = ['field_logo_light', 'field_logo_dark'],
  ): void {
    // Group storage: slug not taken.
    $this->groupStorage->method('loadByProperties')
      ->willReturn([]);

    // Group entity mock.
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($groupId);
    $group->method('hasField')
      ->willReturnCallback(static fn (string $name): bool => in_array($name, $existingFields, TRUE));
    $setGroupFields = [];
    $group->method('set')
      ->willReturnCallback(function (string $name, $value) use ($group, &$setGroupFields) {
        $setGroupFields[$name] = $value;
        return $group;
      });
    $group->method('save')->willReturn(1);

    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);

    $this->groupStorage->method('create')
      ->willReturnCallback(function (array $values) use ($group, &$createdGroupFields) {
        $createdGroupFields = $values;
        return $group;
      });

    // Term storage: create terms that return incremental IDs, tracked by ID
    // (with their create() 'name') so loadMultiple() can resolve category
    // names for demo request seeding (createDemoRequests()).
    $termIdCounter = 0;
    $termsById = [];
    $this->termStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$termIdCounter, &$termsById) {
        $termIdCounter++;
        $currentId = $termIdCounter;
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn($currentId);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        $term->method('label')->willReturn($values['name'] ?? '');
        $termsById[$currentId] = $term;
        return $term;
      });
    $this->termStorage->method('loadMultiple')
      ->willReturnCallback(function (array $ids) use (&$termsById) {
        return array_intersect_key($termsById, array_flip($ids));
      });

    // User storage: no existing user, create new.
    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn($userId);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);

    // Relationship storage: no existing membership.
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    // Node storage: for demo request creation.
    $this->nodeStorage->method('create')
      ->willReturnCallback(function () {
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        return $node;
      });

    // Term storage query: for resolveStatusTermIds().
    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);
  }

  /**
   * Configures term storage to resolve category name mocks for demo content.
   *
   * Sets up loadMultiple() to return term mocks (with label()) keyed by
   * term ID, and getQuery() to resolve to an empty status term set (no
   * field_status assigned to created demo requests).
   *
   * @param array<int, string> $namesByTid
   *   Category names keyed by term ID.
   */
  protected function mockCategoryTermsForDemoContent(array $namesByTid): void {
    $terms = [];
    foreach ($namesByTid as $tid => $name) {
      $term = $this->createMock(TermInterface::class);
      $term->method('id')->willReturn($tid);
      $term->method('label')->willReturn($name);
      $terms[$tid] = $term;
    }
    $this->termStorage->method('loadMultiple')
      ->willReturnCallback(fn(array $ids) => array_intersect_key($terms, array_flip($ids)));

    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);
  }

  /**
   * Builds a group mock with no map/boundary fields set.
   *
   * Used by createDemoRequests() reflection tests that don't exercise
   * coordinate generation, so extractMapCenter()/extractBoundaryGeometry()
   * take their safe fallback paths.
   */
  protected function mockGroupWithNoMapFields(int $groupId): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($groupId);
    $group->method('hasField')->willReturn(FALSE);
    return $group;
  }

  /**
   * Builds a group mock with a selected citizen wording preset.
   */
  protected function mockGroupWithWording(int $groupId, string $preset): GroupInterface {
    $configField = new class ($preset) {

      /**
       * The raw tenant config consumed by CitizenWordingResolver.
       */
      public string $value;

      /**
       * Constructs the field value.
       */
      public function __construct(string $preset) {
        $this->value = json_encode(['i18n' => ['wording' => $preset]], JSON_THROW_ON_ERROR);
      }

      /**
       * Reports the field as populated.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($groupId);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_nuxt_config',
    );
    $group->method('get')->willReturnCallback(
      static fn(string $field): object => $field === 'field_nuxt_config'
        ? $configField
        : throw new \LogicException("Unexpected field {$field}"),
    );
    return $group;
  }

  /**
   * Clears AI provider ENV vars for hermetic AI-guard tests.
   *
   * The DDEV container may have real AI credentials in its environment
   * (e.g. MARKASPOT_AI_API_KEY); AI-guard tests must not depend on that
   * ambient state, so this clears the known ENV vars and returns their
   * original values for restoreAiEnvVars().
   *
   * @return array<string, string|false>
   *   Original values keyed by ENV var name.
   */
  protected function clearAiEnvVars(): array {
    $vars = [
      'MARKASPOT_AI_API_KEY', 'OPENAI_API_KEY', 'MARKASPOT_AI_OPENAI_KEY',
      'AZURE_OPENAI_API_KEY', 'MARKASPOT_AI_AZURE_KEY',
      'ANTHROPIC_API_KEY', 'MARKASPOT_AI_ANTHROPIC_KEY',
      'IONOS_AI_API_KEY', 'MARKASPOT_AI_IONOS_KEY',
    ];
    $backup = [];
    foreach ($vars as $var) {
      $backup[$var] = getenv($var);
      putenv($var);
    }
    return $backup;
  }

  /**
   * Restores ENV vars cleared by clearAiEnvVars().
   *
   * @param array<string, string|false> $backup
   *   Original values as returned by clearAiEnvVars().
   */
  protected function restoreAiEnvVars(array $backup): void {
    foreach ($backup as $var => $value) {
      putenv($value === FALSE ? $var : "$var=$value");
    }
  }

  /**
   * Builds a config factory where an AI provider is fully configured.
   */
  protected function buildConfigFactoryWithAiConfigured(string $provider = 'openai', string $apiKey = 'test-key-123'): ConfigFactoryInterface {
    $aiSettings = $this->createMock(ImmutableConfig::class);
    $aiSettings->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'default_provider' => $provider,
        "providers.{$provider}.api_key" => $apiKey,
        default => NULL,
      });
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'markaspot_ai.settings' => $aiSettings,
        default => $this->createMock(ImmutableConfig::class),
      });
    return $configFactory;
  }

  /**
   * Builds a config factory where no AI provider is configured.
   */
  protected function buildConfigFactoryWithAiNotConfigured(): ConfigFactoryInterface {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($this->createMock(ImmutableConfig::class));
    return $configFactory;
  }

  /**
   * @covers ::addGroupMembership
   */
  public function testTenantAdminProvisioningKeepsMemberBaseRole(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);

    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);

    $this->relationshipStorage->expects($this->once())
      ->method('loadByProperties')
      ->with([
        'gid' => 42,
        'entity_id' => 10,
        'plugin_id' => 'group_membership',
      ])
      ->willReturn([]);

    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->expects($this->once())
      ->method('set')
      ->with('group_roles', ['jur-member', 'jur-tenant_admin'])
      ->willReturnSelf();
    $membership->expects($this->once())->method('save')->willReturn(1);

    $group->expects($this->once())
      ->method('addRelationship')
      ->with($user, 'group_membership')
      ->willReturn($membership);

    $method = new \ReflectionMethod($this->service, 'addGroupMembership');
    $method->invoke($this->service, $group, $user);
  }

  /**
   * Tests successful workspace provisioning.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceSuccess(): void {
    $createdGroupFields = NULL;
    $setGroupFields = NULL;
    $this->setupSuccessfulProvisioning(42, 10, $createdGroupFields, $setGroupFields);

    $result = $this->service->provisionWorkspace($this->validData());

    $this->assertEquals(42, $result['group_id']);
    $this->assertEquals('test-ws', $result['slug']);
    $this->assertEquals('Test Workspace', $result['name']);
    $this->assertEquals('/test-ws', $result['url']);
    // 2 categories (Road Damage, Flood).
    $this->assertEquals(2, $result['categories']);
    $this->assertEquals(10, $result['user_id']);

    $nuxtConfig = json_decode($createdGroupFields['field_nuxt_config'], TRUE);
    $this->assertTrue($nuxtConfig['features']['passwordless']);

    // field_tier is NEVER set in the create() payload. Stripe webhook is the
    // only path that activates a tier.
    $this->assertArrayNotHasKey('field_tier', $createdGroupFields);

    $this->assertCount(1, $this->writtenFiles);
    $this->assertSame(
      'public://jurisdictions/42/logos/signet.svg',
      $this->writtenFiles[0]['destination'],
    );
    // writeData() throws when the directory is missing, so the destination has
    // to be prepared with CREATE_DIRECTORY first or the feature is dead on any
    // instance whose files directory was never prepared by hand.
    $this->assertSame(
      'public://jurisdictions/42/logos',
      $this->preparedDirectories[0]['directory'],
    );
    $this->assertSame(
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
      $this->preparedDirectories[0]['options'],
    );
    $this->assertSame(FileExists::Replace, $this->writtenFiles[0]['behavior']);
    $this->assertStringContainsString('fill="#3b82f6"', $this->writtenFiles[0]['data']);
    $this->assertStringContainsString('data-generated="placeholder"', $this->writtenFiles[0]['data']);
    // The signet is set on the entity, not passed through the create payload,
    // so each target field can be guarded by hasField().
    $this->assertSame(['target_id' => 123], $setGroupFields['field_logo_light']);
    $this->assertSame($setGroupFields['field_logo_light'], $setGroupFields['field_logo_dark']);
    // field_favicon does not exist on the shipped profile: assigning it anyway
    // would make entity creation throw and abort the whole provisioning run.
    $this->assertArrayNotHasKey('field_favicon', $setGroupFields);
  }

  /**
   * Tests that the signet also reaches field_favicon where that field exists.
   *
   * @covers ::attachPlaceholderSignet
   */
  public function testPlaceholderSignetUsesFaviconFieldWhenPresent(): void {
    $createdGroupFields = NULL;
    $setGroupFields = NULL;
    $this->setupSuccessfulProvisioning(
      42,
      10,
      $createdGroupFields,
      $setGroupFields,
      ['field_logo_light', 'field_logo_dark', 'field_favicon'],
    );

    $this->service->provisionWorkspace($this->validData());

    $this->assertSame($setGroupFields['field_logo_light'], $setGroupFields['field_favicon']);
  }

  /**
   * Tests all supported Tailwind names and both fallback paths.
   *
   * @covers ::resolvePrimaryHex
   * @dataProvider primaryColorProvider
   */
  public function testPrimaryColorResolution(string $input, string $expected): void {
    $method = new \ReflectionMethod($this->service, 'resolvePrimaryHex');

    $this->assertSame($expected, $method->invoke($this->service, $input));
  }

  /**
   * Data provider for primary theme color resolution.
   *
   * @return array<string, array{string, string}>
   *   Color input and expected hex value.
   */
  public static function primaryColorProvider(): array {
    return [
      'red' => ['red', '#ef4444'],
      'blue' => ['blue', '#3b82f6'],
      'amber' => ['amber', '#f59e0b'],
      'violet' => ['violet', '#8b5cf6'],
      'orange' => ['orange', '#f97316'],
      'green' => ['green', '#22c55e'],
      'emerald' => ['emerald', '#10b981'],
      'cyan' => ['cyan', '#06b6d4'],
      'yellow' => ['yellow', '#eab308'],
      'unknown name' => ['purple', '#3b82f6'],
      'six-digit hex unchanged' => ['#AbC123', '#AbC123'],
      'three-digit hex unchanged' => ['#0aF', '#0aF'],
    ];
  }

  /**
   * Tests that signet failures do not abort workspace provisioning.
   *
   * @covers ::attachPlaceholderSignet
   * @covers ::provisionWorkspace
   */
  public function testPlaceholderSignetFailureDoesNotAbortProvisioning(): void {
    $createdGroupFields = NULL;
    $this->setupSuccessfulProvisioning(42, 10, $createdGroupFields);

    $generator = $this->createMock(PlaceholderSignetGeneratorInterface::class);
    $generator->method('generate')
      ->willThrowException(new \RuntimeException('Generation failed'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Placeholder signet creation failed'),
        $this->callback(
          static fn(array $context): bool => $context['@slug'] === 'test-ws'
            && $context['@message'] === 'Generation failed',
        ),
      );

    /** @var \Drupal\Core\Config\ConfigFactoryInterface $configFactory */
    $configFactory = \Drupal::getContainer()->get('config.factory');
    /** @var \Drupal\Component\Datetime\TimeInterface $time */
    $time = \Drupal::getContainer()->get('datetime.time');
    $service = new WorkspaceProvisioningService(
      $this->entityTypeManager,
      $this->database,
      $this->logger,
      $this->languageManager,
      $configFactory,
      $time,
      $this->lock,
      $generator,
      $this->fileRepository,
      $this->fileSystem,
    );

    $result = $service->provisionWorkspace($this->validData());

    $this->assertSame(42, $result['group_id']);
    $this->assertArrayNotHasKey('field_logo_light', $createdGroupFields);
    $this->assertArrayNotHasKey('field_logo_dark', $createdGroupFields);
    $this->assertArrayNotHasKey('field_favicon', $createdGroupFields);
    $this->assertSame([], $this->writtenFiles);
  }

  /**
   * Tests that a non-default wording preset is persisted to field_nuxt_config.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceWithWordingPreset(): void {
    $createdGroupFields = NULL;
    $this->setupSuccessfulProvisioning(42, 10, $createdGroupFields);

    $this->service->provisionWorkspace($this->validData(['wording' => 'suggestion']));

    $nuxtConfig = json_decode($createdGroupFields['field_nuxt_config'], TRUE);
    $this->assertSame('suggestion', $nuxtConfig['i18n']['wording']);
  }

  /**
   * Tests that the 'report' default and invalid presets are not persisted.
   *
   * 'report' is the built-in default, so writing it would only bloat
   * field_nuxt_config without changing behavior. An unrecognized value must
   * be dropped silently rather than failing provisioning.
   *
   * @covers ::provisionWorkspace
   * @dataProvider wordingPresetsNotPersistedProvider
   */
  public function testProvisionWorkspaceWordingNotPersisted(mixed $wording): void {
    $createdGroupFields = NULL;
    $this->setupSuccessfulProvisioning(42, 10, $createdGroupFields);

    $this->service->provisionWorkspace($this->validData(['wording' => $wording]));

    $nuxtConfig = json_decode($createdGroupFields['field_nuxt_config'], TRUE);
    $this->assertArrayNotHasKey('i18n', $nuxtConfig);
  }

  /**
   * Data provider for values that must not be written to field_nuxt_config.
   *
   * @return array
   *   Test cases with wording values that should be dropped.
   */
  public static function wordingPresetsNotPersistedProvider(): array {
    return [
      'default preset' => ['report'],
      'unrecognized preset' => ['not-a-preset'],
      'non-string value' => [42],
      'null' => [NULL],
    ];
  }

  /**
   * Tests new workspaces have NULL tier and explicitly clear field_tier.
   *
   * Tier is activated only by the Stripe webhook (checkout.session.completed).
   * Provisioning must not leak 'free' into field_tier — that would tell the
   * billing GET endpoint "Current Plan: Free" before the user has agreed to
   * any plan.
   *
   * @covers ::provisionWorkspace
   */
  public function testNewWorkspaceHasNullTier(): void {
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('hasField')->willReturnCallback(
      fn(string $name): bool => $name === 'field_tier'
    );

    // Record all set() calls so we can assert field_tier was explicitly
    // cleared after create() — defense-in-depth against pre-update_11922
    // tenants where the field default might still be 'free'.
    $setCalls = [];
    $group->method('set')
      ->willReturnCallback(function (string $name, mixed $value) use (&$setCalls, $group) {
        $setCalls[] = [$name, $value];
        return $group;
      });
    $group->method('save')->willReturn(1);

    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);

    $createdGroupFields = NULL;
    $this->groupStorage->method('create')
      ->willReturnCallback(function (array $values) use ($group, &$createdGroupFields) {
        $createdGroupFields = $values;
        return $group;
      });

    $this->termStorage->method('create')
      ->willReturnCallback(function () {
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn(1);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        return $term;
      });

    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);

    $this->relationshipStorage->method('loadByProperties')->willReturn([]);
    $this->nodeStorage->method('create')
      ->willReturnCallback(function () {
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        return $node;
      });

    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    $this->service->provisionWorkspace($this->validData());

    // 1. Tier was never set via group create().
    $this->assertArrayNotHasKey('field_tier', $createdGroupFields);

    // 2. Tier was explicitly cleared to NULL after creation.
    $tierClearCalls = array_filter(
      $setCalls,
      static fn(array $call): bool => $call[0] === 'field_tier' && $call[1] === NULL
    );
    $this->assertNotEmpty(
      $tierClearCalls,
      'WorkspaceProvisioningService must explicitly clear field_tier to NULL after create().'
    );
  }

  /**
   * Tests that missing name throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRequiresName(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('name, slug and email are required');

    $this->service->provisionWorkspace($this->validData(['name' => '']));
  }

  /**
   * Tests that missing slug throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRequiresSlug(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('name, slug and email are required');

    $this->service->provisionWorkspace($this->validData(['slug' => '']));
  }

  /**
   * Tests that missing email throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRequiresEmail(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('name, slug and email are required');

    $this->service->provisionWorkspace($this->validData(['email' => '']));
  }

  /**
   * Tests that invalid slug format throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   * @dataProvider invalidSlugProvider
   */
  public function testProvisionWorkspaceRejectsInvalidSlug(string $slug): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('slug must be 2-30 chars');

    $this->service->provisionWorkspace($this->validData(['slug' => $slug]));
  }

  /**
   * Data provider for invalid slugs.
   *
   * @return array
   *   Test cases with invalid slug values.
   */
  public static function invalidSlugProvider(): array {
    return [
      'too short' => ['a'],
      'uppercase' => ['TestSlug'],
      'spaces' => ['test slug'],
      'special chars' => ['test_slug!'],
      'too long' => [str_repeat('a', 31)],
    ];
  }

  /**
   * Tests that empty categories throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRequiresCategories(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('categories must be provided');

    $this->service->provisionWorkspace($this->validData(['categories' => []]));
  }

  /**
   * Tests that categories with only empty strings throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRejectsEmptyStringCategories(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('categories must contain at least one non-empty string');

    $this->service->provisionWorkspace($this->validData(['categories' => ['', '  ']]));
  }

  /**
   * Tests that duplicate slug throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRejectsDuplicateSlug(): void {
    $existingGroup = $this->createMock(GroupInterface::class);
    $this->groupStorage->method('loadByProperties')
      ->with(['field_slug' => 'test-ws'])
      ->willReturn([$existingGroup]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Slug already taken');

    $this->service->provisionWorkspace($this->validData());
  }

  /**
   * Tests that concurrent provisioning for the same slug is rejected.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRejectsConcurrentSlugLock(): void {
    $this->lock->acquireResult = FALSE;
    $this->groupStorage->expects($this->never())->method('loadByProperties');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Slug already taken');

    try {
      $this->service->provisionWorkspace($this->validData());
    }
    finally {
      $this->assertCount(1, $this->lock->events);
      $this->assertSame('acquire', $this->lock->events[0][0]);
      $this->assertStringStartsWith('markaspot_fastmap:workspace_slug:', $this->lock->events[0][1]);
      $this->assertSame(300.0, $this->lock->events[0][2]);
    }
  }

  /**
   * Tests that too many categories throws RuntimeException.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRejectsTooManyCategories(): void {
    $categories = [];
    for ($i = 0; $i < 31; $i++) {
      $categories[] = 'Category ' . $i;
    }

    $this->groupStorage->method('loadByProperties')->willReturn([]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Maximum 30 categories allowed');

    $this->service->provisionWorkspace($this->validData(['categories' => $categories]));
  }

  /**
   * Tests provisioning with multilingual categories.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceMultilingualCategories(): void {
    $this->setupSuccessfulProvisioning();

    $result = $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'en' => ['Road Damage', 'Flood'],
        'de' => ['Strassenschaden', 'Hochwasser'],
      ],
      'language' => 'en',
    ]));

    $this->assertEquals(2, $result['categories']);
    $this->assertEquals(42, $result['group_id']);
  }

  /**
   * Tests that lat is clamped to valid range.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceClampLatitude(): void {
    $this->setupSuccessfulProvisioning();

    // Should not throw, lat is clamped to [-90, 90].
    $result = $this->service->provisionWorkspace($this->validData(['lat' => 200.0]));
    $this->assertEquals(42, $result['group_id']);
  }

  /**
   * Tests that lng is clamped to valid range.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceClampLongitude(): void {
    $this->setupSuccessfulProvisioning();

    // Should not throw, lng is clamped to [-180, 180].
    $result = $this->service->provisionWorkspace($this->validData(['lng' => -999.0]));
    $this->assertEquals(42, $result['group_id']);
  }

  /**
   * Tests provisioning with a known template.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceWithTemplate(): void {
    $this->setupSuccessfulProvisioning();

    $result = $this->service->provisionWorkspace($this->validData([
      'template' => 'crisis-map',
    ]));

    $this->assertEquals(42, $result['group_id']);
  }

  /**
   * Tests that unknown template falls back to civic-report.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceUnknownTemplateFallback(): void {
    $this->setupSuccessfulProvisioning();

    // Should not throw, unknown template falls back to civic-report.
    $result = $this->service->provisionWorkspace($this->validData([
      'template' => 'nonexistent-template',
    ]));

    $this->assertEquals(42, $result['group_id']);
  }

  /**
   * Tests provisioning with a Polygon boundary.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceWithBoundary(): void {
    $this->setupSuccessfulProvisioning();

    $boundary = [
      'type' => 'Polygon',
      'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]],
    ];

    $result = $this->service->provisionWorkspace($this->validData([
      'boundary' => $boundary,
    ]));

    $this->assertEquals(42, $result['group_id']);
  }

  /**
   * Tests that existing user is reused during provisioning.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceReusesExistingUser(): void {
    // Group storage: slug not taken.
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    // Group entity mock.
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);

    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);

    $this->groupStorage->method('create')->willReturn($group);

    // Term storage.
    $this->termStorage->method('create')
      ->willReturnCallback(function () {
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn(1);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        return $term;
      });

    // User storage: existing user found.
    $existingUser = $this->createMock(UserInterface::class);
    $existingUser->method('id')->willReturn(99);
    $this->userStorage->method('loadByProperties')
      ->willReturnCallback(
        static fn(array $properties): array => $properties === ['mail' => 'admin@example.com']
          ? [$existingUser]
          : [],
      );
    // create() should NOT be called since user exists.
    $this->userStorage->expects($this->never())->method('create');

    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    // Node storage for demo requests.
    $this->nodeStorage->method('create')
      ->willReturnCallback(function () {
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        return $node;
      });
    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    $result = $this->service->provisionWorkspace($this->validData());
    $this->assertEquals(99, $result['user_id']);
  }

  /**
   * Tests that provisioning failure rolls back and throws.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceRollsBackOnFailure(): void {
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    // Group creation throws an exception.
    $this->groupStorage->method('create')
      ->willThrowException(new \Exception('DB error'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Workspace provisioning failed'),
        $this->anything()
      );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Workspace provisioning failed');

    $this->service->provisionWorkspace($this->validData());
  }

  /**
   * Tests that provisioning scopes api_user with the base member role.
   *
   * @covers ::addApiUserMembership
   */
  public function testApiUserMembershipGetsMemberRole(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $apiUser = $this->createMock(UserInterface::class);
    $apiUser->method('id')->willReturn(77);
    $this->userStorage->method('loadByProperties')
      ->with(['name' => 'api_user'])
      ->willReturn([$apiUser]);
    $this->groupRoleStorage->method('load')
      ->with('jur-member')
      ->willReturn($this->createMock(EntityInterface::class));
    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([]);

    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->expects($this->once())
      ->method('set')
      ->with('group_roles', ['jur-member'])
      ->willReturnSelf();
    $membership->expects($this->once())->method('save');
    $group->expects($this->once())
      ->method('addRelationship')
      ->with($apiUser, 'group_membership')
      ->willReturn($membership);

    $method = new \ReflectionMethod($this->service, 'addApiUserMembership');
    $method->invoke($this->service, $group);
  }

  /**
   * Tests that a missing member role skips the api_user membership.
   *
   * @covers ::addApiUserMembership
   */
  public function testMissingApiUserMemberRoleSkipsMembership(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $apiUser = $this->createMock(UserInterface::class);
    $apiUser->method('id')->willReturn(77);
    $this->userStorage->method('loadByProperties')->willReturn([$apiUser]);
    $this->groupRoleStorage->method('load')->willReturn(NULL);
    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('role does not exist'),
        ['@role' => 'jur-member', '@gid' => 42],
      );

    $this->relationshipStorage->expects($this->never())
      ->method('loadByProperties');
    $group->expects($this->never())->method('addRelationship');

    $method = new \ReflectionMethod($this->service, 'addApiUserMembership');
    $method->invoke($this->service, $group);
  }

  /**
   * Tests that a missing api_user does not block workspace provisioning.
   *
   * @covers ::addApiUserMembership
   */
  public function testMissingApiUserIsLoggedAndSkipped(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $this->userStorage->method('loadByProperties')
      ->with(['name' => 'api_user'])
      ->willReturn([]);
    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('account does not exist'),
        ['@gid' => 42],
      );
    $group->expects($this->never())->method('addRelationship');

    $method = new \ReflectionMethod($this->service, 'addApiUserMembership');
    $method->invoke($this->service, $group);
  }

  /**
   * Tests teardown of a workspace.
   *
   * @covers ::teardownWorkspace
   */
  public function testTeardownWorkspaceSuccess(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $this->groupStorage->method('load')->with(42)->willReturn($group);

    // Memberships.
    $memberUser = $this->createMock(UserInterface::class);
    $memberUser->method('id')->willReturn(10);
    $apiUser = $this->createMock(UserInterface::class);
    $apiUser->method('id')->willReturn(11);
    $this->userStorage->method('loadByProperties')
      ->with(['name' => 'api_user'])
      ->willReturn([$apiUser]);

    $membershipEntity = $this->createMock(GroupRelationshipInterface::class);
    $membershipEntity->method('getEntity')->willReturn($memberUser);
    $apiMembershipEntity = $this->createMock(GroupRelationshipInterface::class);
    $apiMembershipEntity->method('getEntity')->willReturn($apiUser);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturnCallback(function (array $props) use ($membershipEntity, $apiMembershipEntity) {
        if (isset($props['gid'])) {
          return [$membershipEntity, $apiMembershipEntity];
        }
        return [];
      });

    // Query for nodes to delete.
    $nodeQuery = $this->createMock(QueryInterface::class);
    $nodeQuery->method('accessCheck')->willReturnSelf();
    $nodeQuery->method('condition')->willReturnSelf();
    $nodeQuery->method('execute')->willReturn([]);
    $this->nodeStorage->method('getQuery')->willReturn($nodeQuery);

    // Query for terms to delete.
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($query);

    // Query for other memberships.
    $countQuery = $this->createMock(QueryInterface::class);
    $countQuery->method('accessCheck')->willReturnSelf();
    $countQuery->method('condition')->willReturnSelf();
    $countQuery->method('count')->willReturnSelf();
    $countQuery->method('execute')->willReturn(0);
    $this->relationshipStorage->method('getQuery')->willReturn($countQuery);

    // User with no other memberships should be deleted.
    $deletableUser = $this->createMock(UserInterface::class);
    $deletableUser->expects($this->once())->method('delete');
    $this->userStorage->method('load')->with(10)->willReturn($deletableUser);

    $group->expects($this->once())->method('delete');

    $this->service->teardownWorkspace(42);
  }

  /**
   * Tests that teardown of nonexistent group throws RuntimeException.
   *
   * @covers ::teardownWorkspace
   */
  public function testTeardownWorkspaceGroupNotFound(): void {
    $this->groupStorage->method('load')->with(999)->willReturn(NULL);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Group not found: 999');

    $this->service->teardownWorkspace(999);
  }

  /**
   * Tests that teardown skips user with uid <= 1.
   *
   * @covers ::teardownWorkspace
   */
  public function testTeardownWorkspaceSkipsAdminUser(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $this->groupStorage->method('load')->with(42)->willReturn($group);

    // Membership with admin user (uid=1).
    $adminUser = $this->createMock(UserInterface::class);
    $adminUser->method('id')->willReturn(1);

    $membershipEntity = $this->createMock(GroupRelationshipInterface::class);
    $membershipEntity->method('getEntity')->willReturn($adminUser);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturnCallback(function (array $props) use ($membershipEntity) {
        if (isset($props['gid'])) {
          return [$membershipEntity];
        }
        return [];
      });

    $nodeQuery = $this->createMock(QueryInterface::class);
    $nodeQuery->method('accessCheck')->willReturnSelf();
    $nodeQuery->method('condition')->willReturnSelf();
    $nodeQuery->method('execute')->willReturn([]);
    $this->nodeStorage->method('getQuery')->willReturn($nodeQuery);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($query);

    // Admin user should never be loaded for deletion.
    $this->userStorage->expects($this->never())->method('load');

    $this->service->teardownWorkspace(42);
  }

  /**
   * Tests that teardown rolls back on failure.
   *
   * @covers ::teardownWorkspace
   */
  public function testTeardownWorkspaceRollsBackOnFailure(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('delete')
      ->willThrowException(new \Exception('Delete failed'));
    $this->groupStorage->method('load')->with(42)->willReturn($group);

    // Empty memberships so we get to group->delete().
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    $nodeQuery = $this->createMock(QueryInterface::class);
    $nodeQuery->method('accessCheck')->willReturnSelf();
    $nodeQuery->method('condition')->willReturnSelf();
    $nodeQuery->method('execute')->willReturn([]);
    $this->nodeStorage->method('getQuery')->willReturn($nodeQuery);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($query);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Workspace teardown failed'),
        $this->anything()
      );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Workspace teardown failed');

    $this->service->teardownWorkspace(42);
  }

  /**
   * Tests provisioning with a valid language selection.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceWithSpecificLanguage(): void {
    $this->setupSuccessfulProvisioning();

    $result = $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'de' => ['Strassenschaden', 'Hochwasser'],
        'en' => ['Road Damage', 'Flood'],
      ],
      'language' => 'de',
    ]));

    $this->assertEquals(42, $result['group_id']);
    $this->assertEquals(2, $result['categories']);
  }

  /**
   * Tests provisioning with invalid language falls back to first available.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceInvalidLanguageFallback(): void {
    $this->setupSuccessfulProvisioning();

    $result = $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'en' => ['Road Damage'],
      ],
      'language' => 'xx',
    ]));

    $this->assertEquals(42, $result['group_id']);
  }

  /**
   * Tests that name is truncated to 255 characters.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceTruncatesLongName(): void {
    $this->setupSuccessfulProvisioning();

    $longName = str_repeat('A', 300);
    $result = $this->service->provisionWorkspace($this->validData([
      'name' => $longName,
    ]));

    // Should succeed (name is truncated internally).
    $this->assertEquals(42, $result['group_id']);
    $this->assertEquals(255, mb_strlen($result['name']));
  }

  /**
   * Tests that provisioning creates one demo request per category.
   *
   * @covers ::provisionWorkspace
   */
  public function testProvisionWorkspaceCreatesDemoRequests(): void {
    // Group storage: slug not taken.
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    // Group entity mock.
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);

    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);

    $this->groupStorage->method('create')->willReturn($group);

    // Term storage: create terms that return incremental IDs, tracked by ID
    // (with label()) so loadMultiple() can resolve both the fixed status
    // term mocks below and the real category terms created for this test.
    $termIdCounter = 0;
    $termsById = [];
    $this->termStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$termIdCounter, &$termsById) {
        $termIdCounter++;
        $currentId = $termIdCounter;
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn($currentId);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        $term->method('label')->willReturn($values['name'] ?? '');
        $termsById[$currentId] = $term;
        return $term;
      });

    // Status term query: return two status term IDs.
    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([100, 101]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    // Mock status terms with Open311 mappings.
    $initialTerm = $this->createMock(TermInterface::class);
    $initialTerm->method('id')->willReturn(100);
    $initialTerm->method('hasField')->willReturn(TRUE);
    $initialField = $this->createMock(FieldItemListInterface::class);
    $initialField->method('isEmpty')->willReturn(FALSE);
    $initialField->__set('value', 'initial');
    $initialField->method('__get')->with('value')->willReturn('initial');
    $initialTerm->method('get')->willReturn($initialField);

    $closedTerm = $this->createMock(TermInterface::class);
    $closedTerm->method('id')->willReturn(101);
    $closedTerm->method('hasField')->willReturn(TRUE);
    $closedField = $this->createMock(FieldItemListInterface::class);
    $closedField->method('isEmpty')->willReturn(FALSE);
    $closedField->__set('value', 'closed');
    $closedField->method('__get')->with('value')->willReturn('closed');
    $closedTerm->method('get')->willReturn($closedField);

    $termsById[100] = $initialTerm;
    $termsById[101] = $closedTerm;
    $this->termStorage->method('loadMultiple')
      ->willReturnCallback(function (array $ids) use (&$termsById) {
        return array_intersect_key($termsById, array_flip($ids));
      });

    // User storage.
    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);

    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    // Node storage: expect 2 demo nodes (one per category) + 1 start page.
    $demoCount = 0;
    $pageCount = 0;
    $this->nodeStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$demoCount, &$pageCount) {
        if ($values['type'] === 'service_request') {
          $demoCount++;
          $this->assertArrayHasKey('field_category', $values);
          $this->assertArrayHasKey('field_geolocation', $values);
          $this->assertEquals('plain_text', $values['body']['format']);
          $this->assertStringContainsString('[demo-content]', $values['body']['value']);
          // No AI client is configured in $this->service (setUp() defaults
          // it to NULL), so the fallback path must be used: the title is
          // the category name, and the body must reference that same name
          // -- proving title/body always match field_category.
          $this->assertContains($values['title'], ['Road Damage', 'Flood']);
          $this->assertStringContainsString($values['title'], $values['body']['value']);
        }
        elseif ($values['type'] === 'page') {
          $pageCount++;
          $this->assertTrue($values['promote']);
          $this->assertTrue($values['sticky']);
          $this->assertArrayHasKey('field_jurisdiction', $values);
        }

        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        return $node;
      });

    $this->service->provisionWorkspace($this->validData([
      'lat' => 50.9,
      'lng' => 6.9,
    ]));

    $this->assertEquals(2, $demoCount, 'Expected 1 demo request per category (2 categories) to be created.');
    $this->assertEquals(1, $pageCount, 'Expected 1 start page to be created.');
  }

  /**
   * Tests demo requests use coordinates within boundary bbox.
   *
   * The boundary is stored in field_boundary (FeatureCollection) and the
   * map center is stored in field_nuxt_config. The provisioning service
   * reads both from the group entity rather than from $data, so the mock
   * must expose these fields.
   *
   * @covers ::provisionWorkspace
   */
  public function testDemoRequestsUseBoundaryBbox(): void {
    // Group storage.
    $this->groupStorage->method('loadByProperties')->willReturn([]);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);
    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);
    $this->groupStorage->method('create')->willReturn($group);

    // Boundary polygon covering lat 10-11, lng 20-21.
    $boundaryGeometry = [
      'type' => 'Polygon',
      'coordinates' => [[[20, 10], [21, 10], [21, 11], [20, 11], [20, 10]]],
    ];

    // Mock field_nuxt_config: center at [lng=20.5, lat=10.5] (inside boundary).
    $nuxtConfigJson = json_encode([
      'map' => ['center' => [20.5, 10.5], 'zoomInitial' => 13],
    ]);
    $nuxtConfigField = $this->createMock(FieldItemListInterface::class);
    $nuxtConfigField->method('isEmpty')->willReturn(FALSE);
    $nuxtConfigField->method('__get')->with('value')->willReturn($nuxtConfigJson);

    // Mock field_boundary: FeatureCollection wrapping the boundary polygon.
    $boundaryJson = json_encode([
      'type' => 'FeatureCollection',
      'features' => [
        [
          'type' => 'Feature',
          'properties' => ['name' => 'Test'],
          'geometry' => $boundaryGeometry,
        ],
      ],
    ]);
    $boundaryField = $this->createMock(FieldItemListInterface::class);
    $boundaryField->method('isEmpty')->willReturn(FALSE);
    $boundaryField->method('__get')->with('value')->willReturn($boundaryJson);

    $group->method('hasField')->willReturn(TRUE);
    $group->method('get')->willReturnCallback(
      function (string $fieldName) use ($nuxtConfigField, $boundaryField) {
        return match ($fieldName) {
          'field_nuxt_config' => $nuxtConfigField,
          'field_boundary' => $boundaryField,
          default => $this->createMock(FieldItemListInterface::class),
        };
      }
    );

    // Terms.
    $this->termStorage->method('create')
      ->willReturnCallback(function () {
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn(1);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        return $term;
      });
    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    // User.
    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    // Track coordinates from created demo nodes (skip page node).
    $coords = [];
    $this->nodeStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$coords) {
        if ($values['type'] === 'service_request') {
          $coords[] = $values['field_geolocation'];
        }
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        return $node;
      });

    $this->service->provisionWorkspace($this->validData([
      'boundary' => $boundaryGeometry,
    ]));

    // Default validData() has 2 categories -> 1 demo request each.
    $this->assertCount(2, $coords);
    foreach ($coords as $coord) {
      $this->assertGreaterThanOrEqual(10.0, $coord['lat'], 'Lat should be >= 10');
      $this->assertLessThanOrEqual(11.0, $coord['lat'], 'Lat should be <= 11');
      $this->assertGreaterThanOrEqual(20.0, $coord['lng'], 'Lng should be >= 20');
      $this->assertLessThanOrEqual(21.0, $coord['lng'], 'Lng should be <= 21');
    }
  }

  /**
   * Tests demo requests use the category's own name/language as title.
   *
   * With no AI client configured, createDemoRequests() falls back to
   * deterministic per-category content: the title is the category's own
   * label in the workspace language (here 'de', matching the category
   * term's primary langcode), never a canned phrase decoupled from
   * field_category.
   *
   * @covers ::provisionWorkspace
   */
  public function testDemoRequestsUseLanguageTemplates(): void {
    // Group storage.
    $this->groupStorage->method('loadByProperties')->willReturn([]);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);
    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);
    $this->groupStorage->method('create')->willReturn($group);

    // Terms: track created terms by ID (with label()) so loadMultiple() can
    // resolve category names for demo request seeding.
    $termIdCounter = 0;
    $termsById = [];
    $this->termStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$termIdCounter, &$termsById) {
        $termIdCounter++;
        $currentId = $termIdCounter;
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn($currentId);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        $term->method('label')->willReturn($values['name'] ?? '');
        $termsById[$currentId] = $term;
        return $term;
      });
    $this->termStorage->method('loadMultiple')
      ->willReturnCallback(function (array $ids) use (&$termsById) {
        return array_intersect_key($termsById, array_flip($ids));
      });
    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    // User.
    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    // Node storage: track created demo titles and langcodes (skip page).
    $titles = [];
    $this->nodeStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$titles) {
        if ($values['type'] === 'service_request') {
          $titles[] = $values['title'];
          $this->assertEquals('de', $values['langcode']);
        }
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        return $node;
      });

    $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'de' => ['Strassenschaden', 'Hochwasser'],
        'en' => ['Road Damage', 'Flood'],
      ],
      'language' => 'de',
    ]));

    $this->assertCount(2, $titles);
    // Category terms are created in the 'de' primary langcode here, so the
    // demo title (= category label) is the German name, in creation order.
    $this->assertEquals('Strassenschaden', $titles[0]);
    $this->assertEquals('Hochwasser', $titles[1]);
  }

  /**
   * Tests demo content falls back to per-category boilerplate with no AI.
   *
   * $this->service (built in setUp()) always has a NULL AI client, so this
   * exercises the deterministic fallback path directly: the title equals
   * the category name and the body contains both the localized boilerplate
   * and that same category name, proving demo content always matches
   * field_category even without an AI provider configured.
   *
   * @covers ::createDemoRequests
   * @covers ::buildFallbackDemoBody
   */
  public function testDemoRequestsFallbackContentMatchesCategoryWhenAiClientNull(): void {
    $group = $this->mockGroupWithNoMapFields(42);
    $categoryNames = [10 => 'Road Damage', 20 => 'Flood', 30 => 'Graffiti'];
    $this->mockCategoryTermsForDemoContent($categoryNames);

    $created = [];
    $this->nodeStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$created) {
        $created[] = $values;
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        return $node;
      });

    $method = new \ReflectionMethod($this->service, 'createDemoRequests');
    $method->invoke($this->service, ['name' => 'Test Workspace'], $group, array_keys($categoryNames), 42, 'en');

    $this->assertCount(3, $created);
    $tids = array_keys($categoryNames);
    foreach ($created as $i => $values) {
      $tid = $tids[$i];
      $expectedName = $categoryNames[$tid];
      $this->assertEquals($expectedName, $values['title']);
      $this->assertStringContainsString($expectedName, $values['body']['value']);
      $this->assertStringContainsString('[demo-content]', $values['body']['value']);
      $this->assertEquals('plain_text', $values['body']['format']);
      $this->assertEquals(['target_id' => $tid], $values['field_category']);
    }
  }

  /**
   * Tests static onboarding copy uses the configured citizen terminology.
   *
   * @covers ::renderStartPageTemplate
   */
  public function testStartPageTemplateUsesConfiguredWording(): void {
    /** @var \Drupal\Core\Config\ConfigFactoryInterface $configFactory */
    $configFactory = \Drupal::getContainer()->get('config.factory');
    /** @var \Drupal\Component\Datetime\TimeInterface $time */
    $time = \Drupal::getContainer()->get('datetime.time');
    $service = new WorkspaceProvisioningService(
      $this->entityTypeManager,
      $this->database,
      $this->logger,
      $this->languageManager,
      $configFactory,
      $time,
      $this->lock,
      $this->placeholderSignetGenerator,
      $this->fileRepository,
      $this->fileSystem,
      NULL,
      new CitizenWordingResolver(),
    );

    $configField = new class {

      /**
       * The raw tenant config used by the resolver.
       */
      public string $value = '{"i18n":{"wording":"entry"}}';

      /**
       * Reports that the field contains a value.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };
    $group = $this->createMock(GroupInterface::class);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasField')->with('field_nuxt_config')->willReturn(TRUE);
    $group->method('get')->with('field_nuxt_config')->willReturn($configField);

    $method = new \ReflectionMethod($service, 'renderStartPageTemplate');
    $rendered = $method->invoke($service, $group, 'Demo Workspace', 'de');

    $this->assertSame('Willkommen bei Demo Workspace', $rendered['title']);
    $this->assertStringContainsString('Einträge', $rendered['body']);
    $this->assertStringNotContainsString('Meldungen', $rendered['body']);

    $czech = $method->invoke($service, $group, 'Demo Workspace', 'cs');
    $this->assertStringContainsString('záznamy', $czech['body']);
    $this->assertStringNotContainsString('občanská hlášení', $czech['body']);
  }

  /**
   * Static fallback copy must not mix an English shell with a local term.
   *
   * @covers ::renderStartPageTemplate
   * @covers ::buildFallbackDemoBody
   */
  public function testStaticFallbackCopyCoversEveryAllowedWorkspaceLanguage(): void {
    $reflection = new \ReflectionClass(WorkspaceProvisioningService::class);
    $allowed = $reflection->getConstant('ALLOWED_LANGS');
    $startPageTemplates = $reflection->getConstant('START_PAGE_TEMPLATES');
    $demoFallbacks = $reflection->getConstant('DEMO_FALLBACK_BOILERPLATE');

    $this->assertIsArray($allowed);
    $this->assertIsArray($startPageTemplates);
    $this->assertIsArray($demoFallbacks);
    $this->assertSame([], array_values(array_diff($allowed, array_keys($startPageTemplates))));
    $this->assertSame([], array_values(array_diff($allowed, array_keys($demoFallbacks))));
  }

  /**
   * Tests createDemoRequests() never calls chat() without AI credentials.
   *
   * AiClientService retries failed requests up to 3x with exponential
   * backoff, so calling chat() with no resolvable provider credentials
   * would fail slowly and block workspace provisioning. This guards that
   * the AI path is skipped entirely (falling back to boilerplate) when no
   * credentials are resolvable via ENV or config.
   *
   * @covers ::createDemoRequests
   * @covers ::isAiConfigured
   */
  public function testDemoRequestsSkipsAiWhenNotConfigured(): void {
    $envBackup = $this->clearAiEnvVars();
    try {
      $configFactory = $this->buildConfigFactoryWithAiNotConfigured();

      $aiClient = $this->createMock(AiClientService::class);
      $aiClient->expects($this->never())->method('chat');

      $service = new WorkspaceProvisioningService(
        $this->entityTypeManager,
        $this->database,
        $this->logger,
        $this->languageManager,
        $configFactory,
        $this->createMock(TimeInterface::class),
        $this->lock,
        $this->placeholderSignetGenerator,
        $this->fileRepository,
        $this->fileSystem,
        $aiClient,
      );

      $group = $this->mockGroupWithNoMapFields(42);
      $categoryNames = [10 => 'Road Damage', 20 => 'Flood'];
      $this->mockCategoryTermsForDemoContent($categoryNames);

      $created = [];
      $this->nodeStorage->method('create')
        ->willReturnCallback(function (array $values) use (&$created) {
          $created[] = $values;
          $node = $this->createMock(NodeInterface::class);
          $node->method('save')->willReturn(1);
          return $node;
        });

      $method = new \ReflectionMethod($service, 'createDemoRequests');
      $method->invoke($service, ['name' => 'Test Workspace'], $group, array_keys($categoryNames), 42, 'en');

      $this->assertCount(2, $created);
      $this->assertEquals('Road Damage', $created[0]['title']);
      $this->assertStringContainsString('Road Damage', $created[0]['body']['value']);
    }
    finally {
      $this->restoreAiEnvVars($envBackup);
    }
  }

  /**
   * Tests demo requests use valid AI-generated content when configured.
   *
   * Covers the AI client being configured and returning a valid,
   * correctly-shaped JSON array.
   *
   * @covers ::createDemoRequests
   * @covers ::generateDemoContentWithAi
   */
  public function testDemoRequestsUseAiGeneratedContentWhenConfigured(): void {
    $envBackup = $this->clearAiEnvVars();
    try {
      $configFactory = $this->buildConfigFactoryWithAiConfigured();

      $capturedMessages = [];
      $aiClient = $this->createMock(AiClientService::class);
      $aiClient->expects($this->once())
        ->method('chat')
        ->willReturnCallback(static function (array $messages) use (&$capturedMessages): array {
          $capturedMessages = $messages;
          return [
            'choices' => [
              [
                'message' => [
                  'content' => json_encode([
                    ['title' => 'Broken traffic light', 'body' => 'The traffic light has been dark since Monday.'],
                    ['title' => 'Flooded underpass', 'body' => 'Heavy rain has flooded the pedestrian underpass.'],
                  ]),
                ],
              ],
            ],
          ];
        });

      $service = new WorkspaceProvisioningService(
        $this->entityTypeManager,
        $this->database,
        $this->logger,
        $this->languageManager,
        $configFactory,
        $this->createMock(TimeInterface::class),
        $this->lock,
        $this->placeholderSignetGenerator,
        $this->fileRepository,
        $this->fileSystem,
        $aiClient,
        new CitizenWordingResolver(),
      );

      $group = $this->mockGroupWithWording(42, 'entry');
      $categoryNames = [10 => 'Road Damage', 20 => 'Flood'];
      $this->mockCategoryTermsForDemoContent($categoryNames);

      $created = [];
      $this->nodeStorage->method('create')
        ->willReturnCallback(function (array $values) use (&$created) {
          $created[] = $values;
          $node = $this->createMock(NodeInterface::class);
          $node->method('save')->willReturn(1);
          return $node;
        });

      $method = new \ReflectionMethod($service, 'createDemoRequests');
      $method->invoke($service, ['name' => 'Test Workspace'], $group, array_keys($categoryNames), 42, 'de');

      $this->assertCount(2, $created);
      $this->assertEquals('Broken traffic light', $created[0]['title']);
      $this->assertStringContainsString('traffic light has been dark', $created[0]['body']['value']);
      $this->assertStringContainsString('[demo-content]', $created[0]['body']['value']);
      $this->assertEquals('Flooded underpass', $created[1]['title']);
      $this->assertStringContainsString('pedestrian underpass', $created[1]['body']['value']);
      $this->assertStringContainsString('Eintrag', $capturedMessages[0]['content']);
      $this->assertStringContainsString('Einträge', $capturedMessages[0]['content']);
    }
    finally {
      $this->restoreAiEnvVars($envBackup);
    }
  }

  /**
   * Tests demo requests fall back to boilerplate on unparseable AI JSON.
   *
   * Proves provisioning never breaks on a malformed or unexpected AI reply.
   *
   * @covers ::createDemoRequests
   * @covers ::generateDemoContentWithAi
   */
  public function testDemoRequestsFallbackWhenAiResponseMalformed(): void {
    $envBackup = $this->clearAiEnvVars();
    try {
      $configFactory = $this->buildConfigFactoryWithAiConfigured();

      $aiClient = $this->createMock(AiClientService::class);
      $aiClient->method('chat')->willReturn([
        'choices' => [
          ['message' => ['content' => 'not valid json{']],
        ],
      ]);

      $service = new WorkspaceProvisioningService(
        $this->entityTypeManager,
        $this->database,
        $this->logger,
        $this->languageManager,
        $configFactory,
        $this->createMock(TimeInterface::class),
        $this->lock,
        $this->placeholderSignetGenerator,
        $this->fileRepository,
        $this->fileSystem,
        $aiClient,
      );

      $group = $this->mockGroupWithNoMapFields(42);
      $categoryNames = [10 => 'Road Damage', 20 => 'Flood'];
      $this->mockCategoryTermsForDemoContent($categoryNames);

      $created = [];
      $this->nodeStorage->method('create')
        ->willReturnCallback(function (array $values) use (&$created) {
          $created[] = $values;
          $node = $this->createMock(NodeInterface::class);
          $node->method('save')->willReturn(1);
          return $node;
        });

      $method = new \ReflectionMethod($service, 'createDemoRequests');
      $method->invoke($service, ['name' => 'Test Workspace'], $group, array_keys($categoryNames), 42, 'en');

      $this->assertCount(2, $created);
      $this->assertEquals('Road Damage', $created[0]['title']);
      $this->assertStringContainsString('Road Damage', $created[0]['body']['value']);
      $this->assertEquals('Flood', $created[1]['title']);
      $this->assertStringContainsString('Flood', $created[1]['body']['value']);
    }
    finally {
      $this->restoreAiEnvVars($envBackup);
    }
  }

  /**
   * Tests demo requests fall back to boilerplate when the AI client throws.
   *
   * Covers a network failure or rate limit exhausted after retries.
   *
   * @covers ::createDemoRequests
   * @covers ::generateDemoContentWithAi
   */
  public function testDemoRequestsFallbackWhenAiClientThrows(): void {
    $envBackup = $this->clearAiEnvVars();
    try {
      $configFactory = $this->buildConfigFactoryWithAiConfigured();

      $aiClient = $this->createMock(AiClientService::class);
      $aiClient->method('chat')->willThrowException(new \Exception('Rate limit exceeded'));

      $service = new WorkspaceProvisioningService(
        $this->entityTypeManager,
        $this->database,
        $this->logger,
        $this->languageManager,
        $configFactory,
        $this->createMock(TimeInterface::class),
        $this->lock,
        $this->placeholderSignetGenerator,
        $this->fileRepository,
        $this->fileSystem,
        $aiClient,
      );

      $group = $this->mockGroupWithNoMapFields(42);
      $categoryNames = [10 => 'Road Damage'];
      $this->mockCategoryTermsForDemoContent($categoryNames);

      $created = [];
      $this->nodeStorage->method('create')
        ->willReturnCallback(function (array $values) use (&$created) {
          $created[] = $values;
          $node = $this->createMock(NodeInterface::class);
          $node->method('save')->willReturn(1);
          return $node;
        });

      $method = new \ReflectionMethod($service, 'createDemoRequests');
      $method->invoke($service, ['name' => 'Test'], $group, array_keys($categoryNames), 42, 'en');

      $this->assertCount(1, $created);
      $this->assertEquals('Road Damage', $created[0]['title']);
    }
    finally {
      $this->restoreAiEnvVars($envBackup);
    }
  }

  /**
   * Tests createCustomStatusTerms() adds translations for each language/status.
   *
   * When status_translations are provided with multiple languages, each
   * translatable status term should receive addTranslation() calls for
   * every non-default language.
   *
   * @covers ::provisionWorkspace
   */
  public function testCustomStatusTermsWithTranslations(): void {
    // Group storage: slug not taken.
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    // Group entity mock.
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);
    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);
    $this->groupStorage->method('create')->willReturn($group);

    // Track addTranslation() calls on status terms.
    $translationCalls = [];
    $termIdCounter = 0;
    $this->termStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$termIdCounter, &$translationCalls) {
        $termIdCounter++;
        $currentId = $termIdCounter;
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn($currentId);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(TRUE);

        // Track translation calls with the term name context.
        $term->method('addTranslation')
          ->willReturnCallback(function (string $lang, array $data) use ($currentId, $values, &$translationCalls) {
            $translationCalls[] = [
              'term_id' => $currentId,
              'original_name' => $values['name'],
              'lang' => $lang,
              'translated_name' => $data['name'],
            ];
            $trans = $this->createMock(EntityInterface::class);
            $trans->method('save')->willReturn(1);
            return $trans;
          });

        return $term;
      });

    // User storage.
    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    // Node storage.
    $this->nodeStorage->method('create')
      ->willReturnCallback(function () {
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        $node->method('isTranslatable')->willReturn(FALSE);
        return $node;
      });
    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'en' => ['Road Damage', 'Flood'],
        'de' => ['Strassenschaden', 'Hochwasser'],
      ],
      'language' => 'en',
      'statuses' => [
        ['name' => 'Open', 'hex' => '#FF0000', 'icon' => 'i-lucide-circle', 'mapping' => 'initial'],
        ['name' => 'In Progress', 'hex' => '#FFA500', 'icon' => 'i-lucide-clock', 'mapping' => 'open'],
        ['name' => 'Closed', 'hex' => '#00FF00', 'icon' => 'i-lucide-check', 'mapping' => 'closed'],
      ],
      'status_translations' => [
        'de' => ['Offen', 'In Bearbeitung', 'Geschlossen'],
      ],
    ]));

    // Filter to only status term translations (term IDs 1-3 are statuses).
    $statusTranslations = array_filter($translationCalls, fn($c) => $c['term_id'] <= 3);

    // Expect 3 German translations for the 3 custom statuses.
    $this->assertCount(3, $statusTranslations);

    $deTranslations = array_column($statusTranslations, 'translated_name');
    $this->assertContains('Offen', $deTranslations);
    $this->assertContains('In Bearbeitung', $deTranslations);
    $this->assertContains('Geschlossen', $deTranslations);
  }

  /**
   * Tests that skipped statuses (empty name) still increment the index.
   *
   * When a status has an empty name, it is skipped but the index for
   * translation lookup should still advance to keep translations aligned.
   *
   * @covers ::provisionWorkspace
   */
  public function testCustomStatusTermsSkippedStatusIncrementsIndex(): void {
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);
    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);
    $this->groupStorage->method('create')->willReturn($group);

    $translationCalls = [];
    $termIdCounter = 0;
    $this->termStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$termIdCounter, &$translationCalls) {
        $termIdCounter++;
        $currentId = $termIdCounter;
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn($currentId);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(TRUE);
        $term->method('addTranslation')
          ->willReturnCallback(function (string $lang, array $data) use ($values, &$translationCalls) {
            $translationCalls[] = [
              'original_name' => $values['name'],
              'lang' => $lang,
              'translated_name' => $data['name'],
            ];
            $trans = $this->createMock(EntityInterface::class);
            $trans->method('save')->willReturn(1);
            return $trans;
          });
        return $term;
      });

    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);
    $this->nodeStorage->method('create')
      ->willReturnCallback(function () {
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        $node->method('isTranslatable')->willReturn(FALSE);
        return $node;
      });
    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    // Status at index 1 has empty name and should be skipped,
    // but index 2 ("Closed") should pick up translation at index 2.
    $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'en' => ['Road Damage'],
        'de' => ['Strassenschaden'],
      ],
      'language' => 'en',
      'statuses' => [
        ['name' => 'Open', 'hex' => '#FF0000', 'icon' => 'i-lucide-circle', 'mapping' => 'initial'],
        ['name' => '', 'hex' => '#FFA500', 'icon' => 'i-lucide-clock', 'mapping' => 'open'],
        ['name' => 'Closed', 'hex' => '#00FF00', 'icon' => 'i-lucide-check', 'mapping' => 'closed'],
      ],
      'status_translations' => [
        'de' => ['Offen', 'SKIPPED', 'Geschlossen'],
      ],
    ]));

    // Only indexes 0 and 2 get terms and German translations.
    $statusTranslations = array_filter($translationCalls, fn($c) => in_array($c['original_name'], ['Open', 'Closed']));
    $this->assertCount(2, $statusTranslations);

    $deNames = array_column($statusTranslations, 'translated_name');
    $this->assertContains('Offen', $deNames);
    // Index 2 must map to 'Geschlossen', NOT 'SKIPPED'.
    $this->assertContains('Geschlossen', $deNames);
    $this->assertNotContains('SKIPPED', $deNames);
  }

  /**
   * Tests the language guard for status translations.
   *
   * When status_translations include a language not in the workspace's
   * available languages, addTranslation() should not be called for that
   * language.
   *
   * @covers ::provisionWorkspace
   */
  public function testCustomStatusTermsLanguageGuardFiltersUnknownLanguages(): void {
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);
    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);
    $this->groupStorage->method('create')->willReturn($group);

    $translationCalls = [];
    $termIdCounter = 0;
    $this->termStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$termIdCounter, &$translationCalls) {
        $termIdCounter++;
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn($termIdCounter);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(TRUE);
        $term->method('addTranslation')
          ->willReturnCallback(function (string $lang, array $data) use (&$translationCalls) {
            $translationCalls[] = ['lang' => $lang, 'name' => $data['name']];
            $trans = $this->createMock(EntityInterface::class);
            $trans->method('save')->willReturn(1);
            return $trans;
          });
        return $term;
      });

    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);
    $this->nodeStorage->method('create')
      ->willReturnCallback(function () {
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        $node->method('isTranslatable')->willReturn(FALSE);
        return $node;
      });
    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    // Categories only define 'en' and 'de'.
    // Translations include 'fr' which is NOT in availableLanguages.
    $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'en' => ['Road Damage'],
        'de' => ['Strassenschaden'],
      ],
      'language' => 'en',
      'statuses' => [
        ['name' => 'Open', 'hex' => '#FF0000', 'icon' => 'i-lucide-circle', 'mapping' => 'initial'],
        ['name' => 'In Progress', 'hex' => '#FFA500', 'icon' => 'i-lucide-clock', 'mapping' => 'open'],
        ['name' => 'Closed', 'hex' => '#00FF00', 'icon' => 'i-lucide-check', 'mapping' => 'closed'],
      ],
      'status_translations' => [
        'de' => ['Offen', 'In Bearbeitung', 'Geschlossen'],
        'fr' => ['Ouvert', 'En cours', 'Ferme'],
      ],
    ]));

    // Only 'de' translations should be created, 'fr' should be blocked.
    $langs = array_unique(array_column($translationCalls, 'lang'));
    $this->assertContains('de', $langs);
    $this->assertNotContains('fr', $langs);
  }

  /**
   * Tests createStartPage() with AI-generated translations per language.
   *
   * When start_page_translations are provided, addTranslation() should be
   * called for each language with the correct title and body.
   *
   * @covers ::provisionWorkspace
   */
  public function testStartPageWithTranslations(): void {
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);
    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);
    $this->groupStorage->method('create')->willReturn($group);

    $this->termStorage->method('create')
      ->willReturnCallback(function () {
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn(1);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        return $term;
      });

    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    // Track node translations.
    $nodeTranslations = [];
    $this->nodeStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$nodeTranslations) {
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        $node->method('isTranslatable')->willReturn(TRUE);
        $node->method('addTranslation')
          ->willReturnCallback(function (string $lang, array $data) use (&$nodeTranslations) {
            $nodeTranslations[] = [
              'lang' => $lang,
              'title' => $data['title'],
              'body' => $data['body']['value'] ?? $data['body'],
            ];
            $trans = $this->createMock(EntityInterface::class);
            $trans->method('save')->willReturn(1);
            return $trans;
          });
        return $node;
      });

    $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'en' => ['Road Damage'],
        'de' => ['Strassenschaden'],
      ],
      'language' => 'en',
      'start_page' => [
        'title' => 'Welcome to Test',
        'body' => '<p>This is the English start page.</p>',
      ],
      'start_page_translations' => [
        'de' => [
          'title' => 'Willkommen bei Test',
          'body' => '<p>Dies ist die deutsche Startseite.</p>',
        ],
      ],
    ]));

    // Find the German page translation (not demo request translations).
    $dePages = array_filter($nodeTranslations, fn($t) => $t['lang'] === 'de');
    $this->assertNotEmpty($dePages, 'Expected a German translation for the start page.');

    $dePage = reset($dePages);
    $this->assertEquals('Willkommen bei Test', $dePage['title']);
    $this->assertStringContainsString('deutsche Startseite', $dePage['body']);
  }

  /**
   * Tests that start page uses template fallback for untranslated languages.
   *
   * When AI translations are provided for some languages but not all,
   * the missing languages should fall back to static templates.
   *
   * @covers ::provisionWorkspace
   */
  public function testStartPageTemplateFallbackForMissingTranslation(): void {
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);
    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);
    $this->groupStorage->method('create')->willReturn($group);

    $this->termStorage->method('create')
      ->willReturnCallback(function () {
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn(1);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        return $term;
      });

    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    $nodeTranslations = [];
    $this->nodeStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$nodeTranslations) {
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        $node->method('isTranslatable')->willReturn(TRUE);
        $node->method('addTranslation')
          ->willReturnCallback(function (string $lang, array $data) use (&$nodeTranslations) {
            $nodeTranslations[] = [
              'lang' => $lang,
              'title' => $data['title'],
              'body' => $data['body']['value'] ?? $data['body'],
            ];
            $trans = $this->createMock(EntityInterface::class);
            $trans->method('save')->willReturn(1);
            return $trans;
          });
        return $node;
      });

    // Default language is 'en', categories provide 'en', 'de', 'fr'.
    // AI translation only for 'de'. French should fall back to template.
    $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'en' => ['Road Damage'],
        'de' => ['Strassenschaden'],
        'fr' => ['Dommage routier'],
      ],
      'language' => 'en',
      'start_page' => [
        'title' => 'Welcome to Test WS',
        'body' => '<p>English content.</p>',
      ],
      'start_page_translations' => [
        'de' => [
          'title' => 'AI-generiert: Willkommen',
          'body' => '<p>AI-generierter Inhalt.</p>',
        ],
        // 'fr' is NOT provided, should fall back to template.
      ],
    ]));

    // Find page translations by language.
    $dePages = array_filter($nodeTranslations, fn($t) => $t['lang'] === 'de');
    $frPages = array_filter($nodeTranslations, fn($t) => $t['lang'] === 'fr');

    $this->assertNotEmpty($dePages, 'Expected a German translation.');
    $this->assertNotEmpty($frPages, 'Expected a French fallback translation.');

    $dePage = reset($dePages);
    $this->assertStringContainsString('AI-generiert', $dePage['title']);

    // French should use the template with %name replaced.
    $frPage = reset($frPages);
    $this->assertStringContainsString('Bienvenue', $frPage['title']);
    $this->assertStringContainsString('Test Workspace', $frPage['title']);
  }

  /**
   * Tests the language guard for start page translations.
   *
   * When start_page_translations include a language not in the workspace's
   * available languages, addTranslation() should not be called for it.
   *
   * @covers ::provisionWorkspace
   */
  public function testStartPageLanguageGuardBlocksNonWorkspaceLanguages(): void {
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);
    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);
    $this->groupStorage->method('create')->willReturn($group);

    $this->termStorage->method('create')
      ->willReturnCallback(function () {
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn(1);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        return $term;
      });

    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    $nodeTranslations = [];
    $this->nodeStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$nodeTranslations) {
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        $node->method('isTranslatable')->willReturn(TRUE);
        $node->method('addTranslation')
          ->willReturnCallback(function (string $lang, array $data) use (&$nodeTranslations) {
            $nodeTranslations[] = ['lang' => $lang];
            $trans = $this->createMock(EntityInterface::class);
            $trans->method('save')->willReturn(1);
            return $trans;
          });
        return $node;
      });

    // Only 'en' in categories, so availableLanguages = ['en'].
    // start_page_translations includes 'de' which is NOT available.
    $this->service->provisionWorkspace($this->validData([
      'categories' => ['Road Damage'],
      'language' => 'en',
      'start_page' => [
        'title' => 'Welcome',
        'body' => '<p>English only.</p>',
      ],
      'start_page_translations' => [
        'de' => [
          'title' => 'Willkommen',
          'body' => '<p>Deutsch.</p>',
        ],
      ],
    ]));

    // No translations should be created because only 'en' is available.
    $langs = array_unique(array_column($nodeTranslations, 'lang'));
    $this->assertNotContains('de', $langs, 'German translation should be blocked by availableLanguages guard.');
  }

  /**
   * Tests that start page title is truncated to 255 and body to 2000 chars.
   *
   * @covers ::provisionWorkspace
   */
  public function testStartPageTruncation(): void {
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);
    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);
    $this->groupStorage->method('create')->willReturn($group);

    $this->termStorage->method('create')
      ->willReturnCallback(function () {
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn(1);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        return $term;
      });

    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    $createdPages = [];
    $this->nodeStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$createdPages) {
        if ($values['type'] === 'page') {
          $createdPages[] = $values;
        }
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        $node->method('isTranslatable')->willReturn(FALSE);
        return $node;
      });

    $longTitle = str_repeat('T', 500);
    $longBody = str_repeat('B', 5000);

    $this->service->provisionWorkspace($this->validData([
      'categories' => ['Road Damage'],
      'start_page' => [
        'title' => $longTitle,
        'body' => $longBody,
      ],
    ]));

    $this->assertCount(1, $createdPages, 'Expected 1 start page.');
    $this->assertEquals(255, mb_strlen($createdPages[0]['title']), 'Title should be truncated to 255.');
    $this->assertEquals(2000, mb_strlen($createdPages[0]['body']['value']), 'Body should be truncated to 2000.');
  }

  /**
   * Creates a Transaction stub that avoids readonly property issues.
   *
   * The Drupal Transaction class uses readonly promoted constructor properties,
   * which cannot be mocked with disableOriginalConstructor(). This creates
   * an anonymous class that extends Transaction without calling the parent
   * constructor.
   *
   * @return \Drupal\Core\Database\Transaction
   *   A transaction stub.
   */
  protected function createTransactionStub(): Transaction {
    return new class () extends Transaction {

      /**
       * {@inheritdoc}
       */
      public function __construct() {
        // Intentionally empty: skip parent constructor to avoid readonly
        // property initialization and Database::commitAllOnShutdown().
      }

      /**
       * {@inheritdoc}
       */
      public function __destruct() {
        // Intentionally empty: prevent access to uninitialized properties.
      }

      /**
       * {@inheritdoc}
       */
      public function rollBack() {
        // No-op for testing.
      }

    };
  }

  /**
   * Tests default status terms for an Italian workspace (#358).
   *
   * Covers the reported bug: a workspace created with language=it must
   * receive the three default status terms in Italian as their primary
   * language, with English added as a secondary translation. Prior to the
   * fix the terms were stored with langcode=it but English names,
   * so the Italian (Default) tab in the admin rendered English labels.
   *
   * @covers ::provisionWorkspace
   */
  public function testDefaultStatusTermsAreLocalizedForItalianWorkspace(): void {
    // Capture the record array via a closure-friendly helper.
    $record = $this->setupDefaultStatusTermTrackerViaRef();

    $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'en' => ['Road Damage', 'Flood'],
        'it' => ['Danno stradale', 'Alluvione'],
      ],
      'language' => 'it',
      // No 'statuses' key: exercise the DEFAULT_STATUSES path.
    ]));

    $creates = $record->creates;
    $translations = $record->translations;

    // Three default status terms created, all with langcode=it and
    // Italian primary names.
    $this->assertCount(3, $creates, 'Three default status terms should be created.');

    $this->assertSame('it', $creates[0]['langcode']);
    $this->assertSame('Creato', $creates[0]['name']);
    $this->assertSame('initial', $creates[0]['mapping']);

    $this->assertSame('it', $creates[1]['langcode']);
    $this->assertSame('In lavorazione', $creates[1]['name']);
    $this->assertSame('open', $creates[1]['mapping']);

    $this->assertSame('it', $creates[2]['langcode']);
    $this->assertSame('Completato', $creates[2]['name']);
    $this->assertSame('closed', $creates[2]['mapping']);

    // Each default status term gets an English translation added.
    $firstTermTranslations = array_values(array_filter(
      $translations,
      fn($t) => $t['term_id'] === $creates[0]['term_id'],
    ));
    $enFirst = array_values(array_filter($firstTermTranslations, fn($t) => $t['lang'] === 'en'));
    $this->assertCount(1, $enFirst);
    $this->assertSame('Created', $enFirst[0]['name']);

    $secondTermTranslations = array_values(array_filter(
      $translations,
      fn($t) => $t['term_id'] === $creates[1]['term_id'],
    ));
    $enSecond = array_values(array_filter($secondTermTranslations, fn($t) => $t['lang'] === 'en'));
    $this->assertCount(1, $enSecond);
    $this->assertSame('In progress', $enSecond[0]['name']);

    $thirdTermTranslations = array_values(array_filter(
      $translations,
      fn($t) => $t['term_id'] === $creates[2]['term_id'],
    ));
    $enThird = array_values(array_filter($thirdTermTranslations, fn($t) => $t['lang'] === 'en'));
    $this->assertCount(1, $enThird);
    $this->assertSame('Done', $enThird[0]['name']);

    // Italian must NOT be added as a secondary translation when it is
    // already the primary langcode (would trigger a duplicate-translation
    // EntityStorageException in Drupal core).
    $itFirst = array_filter($firstTermTranslations, fn($t) => $t['lang'] === 'it');
    $this->assertEmpty($itFirst, 'Primary language must not be re-added as translation.');
  }

  /**
   * Tests default status terms for every ALLOWED_LANGS locale (#358).
   *
   * Every locale the onboarding exposes must either (a) have a localized
   * default status name in self::DEFAULT_STATUSES so the primary langcode
   * matches the workspace default language, or (b) gracefully fall back to
   * English with langcode=en so the "Default" tab still renders a
   * consistent label. The test pins down both cases so regressions are
   * caught before a tenant is provisioned.
   *
   * @dataProvider provideAllowedLocales
   * @covers ::provisionWorkspace
   */
  public function testDefaultStatusTermsMatchPrimaryLangcodeForEveryAllowedLocale(string $locale): void {
    $record = $this->setupDefaultStatusTermTrackerViaRef();

    $this->service->provisionWorkspace($this->validData([
      'categories' => [
        'en' => ['Road Damage'],
        $locale => ['Road Damage'],
      ],
      'language' => $locale,
    ]));

    $creates = $record->creates;
    $this->assertCount(3, $creates, "Three default status terms should be created for locale {$locale}.");
    $this->assertSame(
      ['initial', 'open', 'closed'],
      array_column($creates, 'mapping'),
      "Default statuses must cover every public Open311 mapping for locale {$locale}.",
    );

    foreach ($creates as $create) {
      $this->assertNotSame('', $create['name'], "Status term for locale {$locale} must have a non-empty name.");
      // Term's primary langcode must equal the name's source language so
      // the Default tab in the admin renders the same string that is stored
      // as the primary entry in taxonomy_term_field_data.
      $this->assertContains(
        $create['langcode'],
        [$locale, 'en'],
        "Status term langcode should be either {$locale} or 'en' fallback.",
      );
    }
  }

  /**
   * Provides every ALLOWED_LANGS locale for the default-status test.
   *
   * @return array<string, array{string}>
   *   Locale code keyed test cases.
   */
  public static function provideAllowedLocales(): array {
    $locales = [
      'en', 'de', 'cs', 'nl', 'fr', 'es', 'ar', 'da', 'fi',
      'hu', 'it', 'nb', 'pl', 'pt', 'sv', 'tr', 'uk',
    ];
    $cases = [];
    foreach ($locales as $locale) {
      $cases[$locale] = [$locale];
    }
    return $cases;
  }

  /**
   * Helper that wraps the status-term tracker array in an object.
   *
   * Individual test methods read $record->creates / $record->translations
   * after provisionWorkspace() runs; the closure inside the mock term
   * storage mutates the same object so the captured state is visible to
   * the asserting code.
   */
  protected function setupDefaultStatusTermTrackerViaRef(): object {
    $record = new class {
      /**
       * Captured status-term create() payloads.
       *
       * @var array<int, array{term_id: int, name: string, langcode: string, mapping: string}>
       */
      public array $creates = [];

      /**
       * Captured addTranslation() payloads per status term.
       *
       * @var array<int, array{term_id: int, lang: string, name: string}>
       */
      public array $translations = [];

    };

    $this->groupStorage->method('loadByProperties')->willReturn([]);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);
    $group->method('set')->willReturnSelf();
    $group->method('save')->willReturn(1);
    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('set')->willReturnSelf();
    $membership->method('save')->willReturn(1);
    $group->method('addRelationship')->willReturn($membership);
    $this->groupStorage->method('create')->willReturn($group);

    $termIdCounter = 0;
    $this->termStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$termIdCounter, $record) {
        $termIdCounter++;
        $currentId = $termIdCounter;

        $isStatus = ($values['vid'] ?? '') === 'service_status';
        if ($isStatus) {
          $record->creates[] = [
            'term_id' => $currentId,
            'name' => $values['name'] ?? '',
            'langcode' => $values['langcode'] ?? '',
            'mapping' => $values['field_open311_mapping'] ?? '',
          ];
        }

        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn($currentId);
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(TRUE);
        $term->method('addTranslation')
          ->willReturnCallback(function (string $lang, array $data) use ($currentId, $isStatus, $record) {
            if ($isStatus) {
              $record->translations[] = [
                'term_id' => $currentId,
                'lang' => $lang,
                'name' => $data['name'] ?? '',
              ];
            }
            $trans = $this->createMock(EntityInterface::class);
            $trans->method('save')->willReturn(1);
            return $trans;
          });
        return $term;
      });

    $this->userStorage->method('loadByProperties')->willReturn([]);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $user->method('save')->willReturn(1);
    $this->userStorage->method('create')->willReturn($user);
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    $this->nodeStorage->method('create')
      ->willReturnCallback(function () {
        $node = $this->createMock(NodeInterface::class);
        $node->method('save')->willReturn(1);
        $node->method('isTranslatable')->willReturn(FALSE);
        return $node;
      });

    $statusQuery = $this->createMock(QueryInterface::class);
    $statusQuery->method('accessCheck')->willReturnSelf();
    $statusQuery->method('condition')->willReturnSelf();
    $statusQuery->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($statusQuery);

    return $record;
  }

  /**
   * Tests that an explicit valid category_icons entry beats the heuristic.
   *
   * @covers ::createCategoryTerms
   */
  public function testCreateCategoryTermsExplicitIconWins(): void {
    $captured = [];
    $this->termStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$captured) {
        $captured[] = $values;
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn(count($captured));
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        return $term;
      });

    $method = new \ReflectionMethod($this->service, 'createCategoryTerms');
    $method->invoke(
      $this->service,
      $this->termStorage,
      42,
      ['en' => ['Flood Damage']],
      'en',
      ['i-lucide-car']
    );

    // Without the explicit override, "Flood Damage" would match the "flood"
    // keyword heuristic (i-lucide-droplets). The explicit icon must win.
    $this->assertSame('i-lucide-car', $captured[0]['field_category_icon']);
  }

  /**
   * Tests that an invalid category_icons entry falls back to the heuristic.
   *
   * @covers ::createCategoryTerms
   */
  public function testCreateCategoryTermsInvalidIconFallsBackToHeuristic(): void {
    $captured = [];
    $this->termStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$captured) {
        $captured[] = $values;
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn(count($captured));
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        return $term;
      });

    $method = new \ReflectionMethod($this->service, 'createCategoryTerms');
    $method->invoke(
      $this->service,
      $this->termStorage,
      42,
      ['en' => ['Flood Damage']],
      'en',
      // Fails the i-lucide-* pattern: no explicit icon should be applied.
      ['not-a-valid-icon']
    );

    $this->assertSame('i-lucide-droplets', $captured[0]['field_category_icon']);
  }

  /**
   * Tests that the heuristic checks labels in all available languages.
   *
   * @covers ::createCategoryTerms
   * @covers ::guessIcon
   */
  public function testCreateCategoryTermsHeuristicMatchesGermanLabel(): void {
    $captured = [];
    $this->termStorage->method('create')
      ->willReturnCallback(function (array $values) use (&$captured) {
        $captured[] = $values;
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn(count($captured));
        $term->method('save')->willReturn(1);
        $term->method('isTranslatable')->willReturn(FALSE);
        return $term;
      });

    $method = new \ReflectionMethod($this->service, 'createCategoryTerms');
    $method->invoke(
      $this->service,
      $this->termStorage,
      42,
      [
        // English label matches no keyword...
        'en' => ['Community Issue'],
        // ...but the German label does ("Schlagloch" = pothole).
        'de' => ['Schlagloch'],
      ],
      'en',
      NULL
    );

    $this->assertSame('i-lucide-construction', $captured[0]['field_category_icon']);
  }

}
