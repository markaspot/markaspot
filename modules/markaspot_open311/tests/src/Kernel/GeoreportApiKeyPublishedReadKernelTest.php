<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\markaspot_open311\Service\StatusClassifier;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that plain API-key reads only return published requests.
 *
 * The account behind the frontend proxy key gets the anonymous response
 * shape. Group roles in its jurisdictions can grant it unpublished nodes
 * (a drifted jur-member role did on the demo tenant), so the query must
 * filter them itself. Key accounts with 'access open311 advanced
 * properties' keep their access.
 *
 * @group markaspot_open311
 */
#[RunTestsInSeparateProcesses]
final class GeoreportApiKeyPublishedReadKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait {
    createContentType as drupalCreateContentType;
  }
  use UserCreationTrait;

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
  ];

  /**
   * Published request node ID.
   */
  private int $publishedNid;

  /**
   * Unpublished request node ID.
   */
  private int $unpublishedNid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('node', 'node_access');
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['filter', 'node', 'user']);
    $this->drupalCreateContentType(['type' => 'service_request']);

    // Keep uid 1 out of the way; it bypasses every access check.
    $this->createUser();

    $published = Node::create(['type' => 'service_request', 'title' => 'Published', 'status' => 1]);
    $published->save();
    $this->publishedNid = (int) $published->id();
    $unpublished = Node::create(['type' => 'service_request', 'title' => 'Unpublished', 'status' => 0]);
    $unpublished->save();
    $this->unpublishedNid = (int) $unpublished->id();
  }

  /**
   * A plain key account sees only published requests, whatever it may view.
   */
  public function testPlainKeyReadGetsPublishedOnly(): void {
    // No module implements hook_node_grants here, so the entity query hands
    // every node to an authenticated account, as a group role with "view
    // unpublished" does on a tenant. Only the status condition can filter.
    $account = $this->keyAccount(['bypass node access']);

    $this->assertSame([$this->publishedNid], $this->readNids($account, TRUE));
  }

  /**
   * The same account without a key keeps what its grants allow.
   */
  public function testSessionReadIsUnchanged(): void {
    $account = $this->keyAccount(['bypass node access']);

    $this->assertSame([$this->publishedNid, $this->unpublishedNid], $this->readNids($account, FALSE));
  }

  /**
   * Integration keys with the advanced permission keep unpublished access.
   */
  public function testAdvancedKeyReadIsUnchanged(): void {
    $account = $this->keyAccount(['bypass node access', 'access open311 advanced properties']);

    $this->assertSame([$this->publishedNid, $this->unpublishedNid], $this->readNids($account, TRUE));
  }

  /**
   * The super user keeps unpublished access with a key.
   */
  public function testSuperUserKeyReadIsUnchanged(): void {
    $account = new UserSession(['uid' => 1, 'roles' => [AccountInterface::AUTHENTICATED_ROLE]]);

    $this->assertSame([$this->publishedNid, $this->unpublishedNid], $this->readNids($account, TRUE));
  }

  /**
   * An authenticated account holding exactly the given permissions.
   *
   * The Open311 permissions belong to markaspot_open311, which this test
   * does not install, so the session reports them directly.
   *
   * @param string[] $permissions
   *   Permission names.
   */
  private function keyAccount(array $permissions): AccountInterface {
    $user = $this->createUser();
    return new class(['uid' => $user->id(), 'roles' => [AccountInterface::AUTHENTICATED_ROLE]], $permissions) extends UserSession {

      /**
       * Constructs the session.
       *
       * @param array $values
       *   Session values.
       * @param string[] $permissions
       *   Permission names the account holds.
       */
      public function __construct(array $values, private readonly array $permissions) {
        parent::__construct($values);
      }

      /**
       * {@inheritdoc}
       */
      public function hasPermission(string $permission): bool {
        return in_array($permission, $this->permissions, TRUE);
      }

    };
  }

  /**
   * Runs the processor's base query for an account.
   *
   * @return int[]
   *   Matching node IDs in ascending order.
   */
  private function readNids(AccountInterface $account, bool $apiKeyRead): array {
    $this->setCurrentUser($account);
    $nids = array_map('intval', array_values($this->createProcessor()->createNodeQuery([], $account, $apiKeyRead)->execute()));
    sort($nids);
    return $nids;
  }

  /**
   * Builds the processor from the container services.
   */
  private function createProcessor(): GeoreportProcessorService {
    return new GeoreportProcessorService(
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
      new StatusClassifier(
        $this->container->get('config.factory'),
        $this->container->get('entity_type.manager'),
        $this->container->get('cache.default'),
      ),
      NULL,
      $this->container->get('logger.factory')->get('markaspot_open311'),
    );
  }

}
