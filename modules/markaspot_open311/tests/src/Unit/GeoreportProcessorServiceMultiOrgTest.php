<?php

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\Component\Datetime\Time;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests multi-org response building in GeoreportProcessorService.
 *
 * Verifies that mapNodeToServiceRequest() correctly produces the
 * 'organisation' (singular, backward compat) and 'organisations' (plural,
 * multi-value) keys in the API response.
 *
 * @group markaspot_open311
 * @coversDefaultClass \Drupal\markaspot_open311\Service\GeoreportProcessorService
 */
class GeoreportProcessorServiceMultiOrgTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_open311\Service\GeoreportProcessorService
   */
  protected $processor;

  /**
   * Mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * Mocked module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $moduleHandler;

  /**
   * Mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $currentUser = $this->createMock(AccountProxyInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    // Set up entity storages.
    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $termStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['node', $nodeStorage],
        ['group', $groupStorage],
        ['taxonomy_term', $termStorage],
      ]);

    // Default config: manager role sees organisation, response_visibility
    // has public_organisation enabled.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['bundle', 'service_request'],
        ['group_filter_enabled', FALSE],
        ['jurisdiction_group_type', 'jur'],
        [
          'field_access',
          [
            'manager_fields' => [
              'field_hazard_level',
              'field_sentiment',
              'field_ai_hazard_category',
              'field_organisation',
            ],
          ],
        ],
        [
          'field_access.manager_fields',
          [
            'field_hazard_level',
            'field_sentiment',
            'field_ai_hazard_category',
            'field_organisation',
          ],
        ],
        [
          'response_visibility',
          ['public_organisation' => TRUE],
        ],
        ['status_closed', [6 => '6']],
      ]);

    $dateConfig = $this->createMock(ImmutableConfig::class);
    $dateConfig->method('get')
      ->willReturnMap([
        ['country.default', 'DE'],
      ]);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['markaspot_open311.settings', $config],
        ['system.date', $dateConfig],
      ]);

    $time = $this->createMock(Time::class);
    $requestStack = $this->createMock(RequestStack::class);
    $request = new Request();
    $requestStack->method('getCurrentRequest')->willReturn($request);
    $fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $streamWrapperManager = $this->createMock(StreamWrapperManagerInterface::class);
    $token = $this->createMock(Token::class);
    $database = $this->createMock(Connection::class);
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $httpClient = $this->createMock(ClientInterface::class);
    $messenger = $this->createMock(MessengerInterface::class);
    $accountSwitcher = $this->createMock(AccountSwitcherInterface::class);

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager->method('getDefaultLanguage')->willReturn($language);

    $this->processor = new GeoreportProcessorService(
      $this->configFactory,
      $currentUser,
      $time,
      $requestStack,
      $this->entityTypeManager,
      $fileUrlGenerator,
      $this->moduleHandler,
      $entityFieldManager,
      $streamWrapperManager,
      $token,
      $languageManager,
      $database,
      $fileSystem,
      $httpClient,
      $messenger,
      $accountSwitcher,
      $hierarchyResolver,
      $this->logger,
    );
  }

  /**
   * Creates a mock organisation group entity.
   *
   * @param int $id
   *   The group ID.
   * @param string $uuid
   *   The group UUID.
   * @param string $label
   *   The group label.
   * @param int|null $jurisdictionId
   *   The optional jurisdiction group ID.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked group entity.
   */
  protected function createMockOrgEntity(int $id, string $uuid, string $label, ?int $jurisdictionId = NULL): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('uuid')->willReturn($uuid);
    $group->method('label')->willReturn($label);
    $group->method('bundle')->willReturn('org');
    $jurisdiction = NULL;
    if ($jurisdictionId !== NULL) {
      $jurisdiction = $this->createMock(GroupInterface::class);
      $jurisdiction->method('id')->willReturn((string) $jurisdictionId);
      $jurisdiction->method('bundle')->willReturn('jur');
    }
    $group->method('hasField')
      ->willReturnCallback(fn(string $field): bool => $field === 'field_jurisdiction');
    $group->method('get')
      ->willReturnCallback(static function (string $field) use ($jurisdictionId, $jurisdiction) {
        if ($field !== 'field_jurisdiction') {
          return NULL;
        }
        return new class($jurisdictionId, $jurisdiction) {

          /**
           * Constructs a jurisdiction reference field stub.
           */
          public function __construct(
            public ?int $targetId,
            public ?object $entity,
          ) {}

          /**
           * Checks whether the field is empty.
           */
          public function isEmpty(): bool {
            return $this->targetId === NULL;
          }

        };
      });
    return $group;
  }

  /**
   * Auto-incrementing node ID to avoid static cache collisions.
   *
   * @var int
   */
  protected static int $nextNodeId = 9000;

  /**
   * Creates a mock node with the given organisation entities.
   *
   * Each call produces a unique node ID so the static cache inside
   * mapNodeToServiceRequest() does not return stale results.
   *
   * @param \Drupal\group\Entity\GroupInterface[] $orgEntities
   *   Organisation group entities for field_organisation.
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked node.
   */
  protected function createMockNode(array $orgEntities = []): NodeInterface {
    $nodeId = self::$nextNodeId++;
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nodeId);
    $node->method('getTitle')->willReturn('Test Request');
    $node->method('hasTranslation')->willReturn(FALSE);

    // Build field stubs.
    $fields = [];

    // field_organisation (multi-value entity reference).
    if (!empty($orgEntities)) {
      $fields['field_organisation'] = new class($orgEntities) {

        /**
         * The referenced org entities.
         *
         * @var array
         */
        private array $entities;

        /**
         * Constructs a multi-value field item list stub.
         */
        public function __construct(array $entities) {
          $this->entities = $entities;
        }

        /**
         * Checks whether the field is empty.
         */
        public function isEmpty(): bool {
          return FALSE;
        }

        /**
         * Returns all referenced entities.
         */
        public function referencedEntities(): array {
          return $this->entities;
        }

      };
    }
    else {
      $fields['field_organisation'] = new class() {

        /**
         * Checks whether the field is empty.
         */
        public function isEmpty(): bool {
          return TRUE;
        }

        /**
         * Returns all referenced entities.
         */
        public function referencedEntities(): array {
          return [];
        }

      };
    }

    // Minimal field stubs for required fields in mapNodeToServiceRequest().
    $fields['request_id'] = new class() {

      /**
       * The field value.
       *
       * @var string
       */
      public string $value = 'REQ-001';

      /**
       * Checks whether the field is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $fields['created'] = new class() {

      /**
       * The created timestamp.
       *
       * @var int
       */
      public int $value = 1700000000;

      /**
       * Checks whether the field is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $fields['changed'] = new class() {

      /**
       * The changed timestamp.
       *
       * @var int
       */
      public int $value = 1700001000;

      /**
       * Checks whether the field is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    // field_category (empty).
    $fields['field_category'] = new class() {

      /**
       * The target_id (NULL).
       *
       * @var int|null
       */
      // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
      public ?int $target_id = NULL;

      /**
       * Checks whether the field is empty.
       */
      public function isEmpty(): bool {
        return TRUE;
      }

    };

    // field_status (empty).
    $fields['field_status'] = new class() {

      /**
       * The target_id (NULL).
       *
       * @var int|null
       */
      // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
      public ?int $target_id = NULL;

      /**
       * Checks whether the field is empty.
       */
      public function isEmpty(): bool {
        return TRUE;
      }

    };

    $node->method('hasField')
      ->willReturnCallback(fn($name) => isset($fields[$name]));
    $node->method('get')
      ->willReturnCallback(function ($name) use ($fields) {
        if (isset($fields[$name])) {
          return $fields[$name];
        }
        return new class() {

          /**
           * Checks whether the field is empty.
           */
          public function isEmpty(): bool {
            return TRUE;
          }

          /**
           * Returns all referenced entities.
           */
          public function referencedEntities(): array {
            return [];
          }

        };
      });

    return $node;
  }

  /**
   * Tests that a node with 2 orgs produces both keys in the response.
   *
   * @covers ::mapNodeToServiceRequest
   */
  public function testBuildResponseWithMultipleOrganisations(): void {
    $org1 = $this->createMockOrgEntity(10, 'uuid-org-10', 'Department A');
    $org2 = $this->createMockOrgEntity(20, 'uuid-org-20', 'Department B');
    $node = $this->createMockNode([$org1, $org2]);

    $result = $this->processor->mapNodeToServiceRequest($node, 'manager', []);

    $this->assertArrayHasKey('organisations', $result);
    $this->assertCount(2, $result['organisations']);
    $this->assertEquals('10', $result['organisations'][0]['id']);
    $this->assertEquals('Department A', $result['organisations'][0]['label']);
    $this->assertEquals('20', $result['organisations'][1]['id']);
    $this->assertEquals('Department B', $result['organisations'][1]['label']);

    // Backward compat: singular key is the first item.
    $this->assertArrayHasKey('organisation', $result);
    $this->assertEquals($result['organisations'][0], $result['organisation']);
  }

  /**
   * Tests that a node with 1 org produces both keys with 1 item.
   *
   * @covers ::mapNodeToServiceRequest
   */
  public function testBuildResponseWithSingleOrganisation(): void {
    $org = $this->createMockOrgEntity(10, 'uuid-org-10', 'Department A');
    $node = $this->createMockNode([$org]);

    $result = $this->processor->mapNodeToServiceRequest($node, 'manager', []);

    $this->assertArrayHasKey('organisations', $result);
    $this->assertCount(1, $result['organisations']);
    $this->assertEquals('10', $result['organisations'][0]['id']);

    $this->assertArrayHasKey('organisation', $result);
    $this->assertEquals($result['organisations'][0], $result['organisation']);
  }

  /**
   * Tests manager response exposes valid organisation jurisdiction metadata.
   *
   * @covers ::mapNodeToServiceRequest
   */
  public function testManagerResponseIncludesOrganisationJurisdictionMetadata(): void {
    $org = $this->createMockOrgEntity(10, 'uuid-org-10', 'Department A', 5);
    $node = $this->createMockNode([$org]);

    $result = $this->processor->mapNodeToServiceRequest($node, 'manager', []);

    $this->assertSame(5, $result['organisation']['jurisdiction_id']);
    $this->assertFalse($result['organisation']['orphan']);
    $this->assertSame($result['organisation'], $result['organisations'][0]);
  }

  /**
   * Tests manager response marks orphan organisations explicitly.
   *
   * @covers ::mapNodeToServiceRequest
   */
  public function testManagerResponseMarksOrphanOrganisation(): void {
    $org = $this->createMockOrgEntity(10, 'uuid-org-10', 'Department A');
    $node = $this->createMockNode([$org]);

    $result = $this->processor->mapNodeToServiceRequest($node, 'manager', []);

    $this->assertNull($result['organisation']['jurisdiction_id']);
    $this->assertTrue($result['organisation']['orphan']);
  }

  /**
   * Tests that a node with empty field_organisation has neither key.
   *
   * @covers ::mapNodeToServiceRequest
   */
  public function testBuildResponseWithNoOrganisation(): void {
    $node = $this->createMockNode([]);

    $result = $this->processor->mapNodeToServiceRequest($node, 'manager', []);

    $this->assertArrayNotHasKey('organisation', $result);
    $this->assertArrayNotHasKey('organisations', $result);
  }

  /**
   * Tests backward compat: 'organisation' always equals 'organisations[0]'.
   *
   * @covers ::mapNodeToServiceRequest
   */
  public function testBackwardCompatOrganisationIsFirstItem(): void {
    $org1 = $this->createMockOrgEntity(10, 'uuid-org-10', 'First Org');
    $org2 = $this->createMockOrgEntity(20, 'uuid-org-20', 'Second Org');
    $org3 = $this->createMockOrgEntity(30, 'uuid-org-30', 'Third Org');
    $node = $this->createMockNode([$org1, $org2, $org3]);

    $result = $this->processor->mapNodeToServiceRequest($node, 'manager', []);

    $this->assertSame($result['organisations'][0], $result['organisation']);
    $this->assertEquals('10', $result['organisation']['id']);
    $this->assertEquals('First Org', $result['organisation']['label']);
  }

}
