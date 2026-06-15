<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel coverage for Open311 read-scope response shaping.
 *
 * @group markaspot_open311
 *
 * @covers \Drupal\markaspot_open311\Service\GeoreportProcessorService::getResults
 */
#[RunTestsInSeparateProcesses]
final class GeoreportReadScopeKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait {
    createContentType as drupalCreateContentType;
  }
  use UserCreationTrait {
    createUser as drupalCreateUser;
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'markaspot_request_id',
  ];

  /**
   * Service category term used by the test request.
   */
  private int $categoryTid;

  /**
   * The service request node ID under test.
   */
  private int $requestNid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('node', 'node_access');
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['filter', 'node', 'user']);

    $this->config('user.role.' . RoleInterface::ANONYMOUS_ID)
      ->set('permissions', [])
      ->save();
    $this->config('user.role.' . RoleInterface::AUTHENTICATED_ID)
      ->set('permissions', [])
      ->save();

    $this->drupalCreateContentType(['type' => 'service_request']);
    $this->createServiceRequestFields();
    $this->categoryTid = $this->createCategoryTerm();
    $this->requestNid = $this->createServiceRequestNode();

    node_access_rebuild();
  }

  /**
   * Dashboard-capable non-members keep read access but get public shape.
   */
  public function testForeignManagerReadScopeGetsPublicShape(): void {
    $this->setCurrentUser($this->drupalCreateUser());
    $account = $this->createManagerAccount();

    $result = $this->createProcessor(FALSE)
      ->getResults($this->buildRequestQuery(), $account, [], 42);

    $this->assertCount(1, $result, 'Read access to the request must be preserved.');
    $request = $result[0];
    $this->assertSame('REQ-476', $request['service_request_id']);
    $this->assertArrayNotHasKey('email', $request);
    $this->assertArrayNotHasKey('first_name', $request);
    $this->assertArrayNotHasKey('last_name', $request);
    $this->assertArrayNotHasKey('phone', $request);
  }

  /**
   * Scoped jurisdiction members keep the manager response shape.
   */
  public function testMemberReadScopeGetsManagerShape(): void {
    $this->setCurrentUser($this->drupalCreateUser());
    $account = $this->createManagerAccount();

    $result = $this->createProcessor(TRUE)
      ->getResults($this->buildRequestQuery(), $account, [], 42);

    $this->assertCount(1, $result);
    $request = $result[0];
    $this->assertSame('citizen@example.test', $request['email']);
    $this->assertSame('Ada', $request['first_name']);
    $this->assertSame('Lovelace', $request['last_name']);
  }

  /**
   * Builds the service_request fields used by the real mapper.
   */
  private function createServiceRequestFields(): void {
    Vocabulary::create([
      'vid' => 'service_category',
      'name' => 'Category',
    ])->save();

    Vocabulary::create([
      'vid' => 'service_status',
      'name' => 'Status',
    ])->save();

    $this->createNodeFieldStorage('field_category', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ]);
    $this->createNodeFieldConfig('field_category', 'entity_reference', [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => [
        'target_bundles' => ['service_category' => 'service_category'],
      ],
    ]);

    $this->createNodeFieldStorage('field_status', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ]);
    $this->createNodeFieldConfig('field_status', 'entity_reference', [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => [
        'target_bundles' => ['service_status' => 'service_status'],
      ],
    ]);

    $this->createNodeFieldStorage('field_e_mail', 'email');
    $this->createNodeFieldConfig('field_e_mail', 'email');
    $this->createNodeFieldStorage('field_first_name', 'string');
    $this->createNodeFieldConfig('field_first_name', 'string');
    $this->createNodeFieldStorage('field_last_name', 'string');
    $this->createNodeFieldConfig('field_last_name', 'string');
  }

  /**
   * Creates a node field storage definition.
   *
   * @param string $fieldName
   *   The field name.
   * @param string $type
   *   The field type.
   * @param array<string, mixed> $settings
   *   Field storage settings.
   */
  private function createNodeFieldStorage(string $fieldName, string $type, array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'node',
      'type' => $type,
      'settings' => $settings,
    ])->save();
  }

  /**
   * Creates a field config on service_request.
   *
   * @param string $fieldName
   *   The field name.
   * @param string $type
   *   The field type.
   * @param array<string, mixed> $settings
   *   Field settings.
   */
  private function createNodeFieldConfig(string $fieldName, string $type, array $settings = []): void {
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => $fieldName,
      'settings' => $settings,
      'field_type' => $type,
    ])->save();
  }

  /**
   * Creates the category term referenced by the request.
   */
  private function createCategoryTerm(): int {
    $term = Term::create([
      'vid' => 'service_category',
      'name' => 'Broken streetlight',
    ]);
    $term->save();

    return (int) $term->id();
  }

  /**
   * Creates the service request node under test.
   */
  private function createServiceRequestNode(): int {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Scoped request',
      'status' => 1,
      'uid' => 1,
      'request_id' => 'REQ-476',
      'field_category' => $this->categoryTid,
      'field_e_mail' => 'citizen@example.test',
      'field_first_name' => 'Ada',
      'field_last_name' => 'Lovelace',
      'created' => 1600000000,
      'changed' => 1600000100,
    ]);
    $node->save();

    return (int) $node->id();
  }

  /**
   * Builds a node query for the request under test.
   */
  private function buildRequestQuery(): object {
    $query = $this->container
      ->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery();
    $query->accessCheck(FALSE);
    $query->condition('nid', $this->requestNid);
    return $query;
  }

  /**
   * Builds an account that reaches the Open311 manager response role.
   */
  private function createManagerAccount(): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('isAnonymous')->willReturn(FALSE);
    $account->method('hasPermission')
      ->willReturnCallback(
        static fn(string $permission): bool => $permission === 'access open311 advanced properties'
      );

    return $account;
  }

  /**
   * Builds a processor with deterministic jurisdiction membership.
   */
  private function createProcessor(bool $isMember): GeoreportProcessorService {
    return new class(
      $isMember,
      $this->container->get('config.factory'),
      $this->container->get('current_user'),
      $this->container->get('datetime.time'),
      $this->container->get('request_stack'),
      $this->container->get('entity_type.manager'),
      $this->container->get('file_url_generator'),
      $this->container->get('module_handler'),
      $this->container->get('entity_field.manager'),
      $this->container->get('stream_wrapper_manager'),
      $this->container->get('token'),
      $this->container->get('language_manager'),
      $this->container->get('database'),
      $this->container->get('file_system'),
      $this->container->get('http_client'),
      $this->container->get('messenger'),
      $this->container->get('account_switcher'),
      NULL,
      $this->container->get('logger.factory')->get('markaspot_open311'),
    ) extends GeoreportProcessorService {

      /**
       * Constructs the scoped test processor.
       */
      public function __construct(
        private readonly bool $isMember,
        ...$args,
      ) {
        parent::__construct(...$args);
      }

      /**
       * {@inheritdoc}
       */
      public function isJurisdictionMember(?int $jurisdictionId, $account = NULL): bool {
        return $jurisdictionId === 42 && $this->isMember;
      }

      /**
       * {@inheritdoc}
       */
      protected function getNodePermissions(object $node, $user): array {
        return [
          'view' => TRUE,
          'update' => FALSE,
          'delete' => FALSE,
        ];
      }

    };
  }

}
