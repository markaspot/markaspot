<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\group\GroupMembership;
use Drupal\markaspot_fastmap\Controller\BillingController;
use Drupal\markaspot_fastmap\Service\BillingStateResolver;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the BillingController.
 *
 * @group markaspot_fastmap
 * @coversDefaultClass \Drupal\markaspot_fastmap\Controller\BillingController
 */
class BillingControllerTest extends UnitTestCase {

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerInterface $logger;

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Connection $database;

  /**
   * The mocked group storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $groupStorage;

  /**
   * The mocked current user.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected AccountInterface $currentUser;

  /**
   * Request stack used by route access tests.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Current user ID returned by the current user mock.
   *
   * @var int
   */
  protected int $currentUserId = 1;

  /**
   * Current user roles returned by the current user mock.
   *
   * @var string[]
   */
  protected array $currentUserRoles = ['authenticated', 'administrator'];

  /**
   * The controller under test.
   *
   * @var \Drupal\markaspot_fastmap\Controller\BillingController
   */
  protected BillingController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Build FastMap config.
    $fastmapConfig = $this->createMock(ImmutableConfig::class);
    $fastmapConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'service_key' => 'test-service-key-456',
        default => NULL,
      });

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'markaspot_fastmap.settings' => $fastmapConfig,
        default => $this->createMock(ImmutableConfig::class),
      });

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $this->groupStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $this->logger = $this->createMock(LoggerInterface::class);
    $this->database = $this->createMock(Connection::class);
    $transaction = new class() {

      /**
       * Records rollback requests in tests.
       */
      public function rollBack(): void {
      }

    };
    $this->database->method('startTransaction')->willReturn($transaction);
    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->currentUser->method('id')
      ->willReturnCallback(fn() => $this->currentUserId);
    $this->currentUser->method('getRoles')
      ->willReturnCallback(fn() => $this->currentUserRoles);
    $this->requestStack = new RequestStack();
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);

    // Set up the Drupal container.
    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('logger.channel.markaspot_fastmap', $this->logger);
    $container->set('database', $this->database);
    $container->set('current_user', $this->currentUser);
    $container->set('request_stack', $this->requestStack);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    $container->set('markaspot_fastmap.billing_state_resolver', new BillingStateResolver());
    \Drupal::setContainer($container);

    $this->controller = BillingController::create($container);
  }

  /**
   * Creates a request with JSON body and optional service key header.
   *
   * @param array $data
   *   The JSON body data.
   * @param string|null $serviceKey
   *   The service key to send as X-Service-Key header.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request object.
   */
  protected function createJsonRequest(array $data, ?string $serviceKey = NULL): Request {
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($serviceKey !== NULL) {
      $server['HTTP_X_SERVICE_KEY'] = $serviceKey;
    }
    return Request::create(
      '/api/billing/14',
      'PATCH',
      [],
      [],
      [],
      $server,
      json_encode($data)
    );
  }

  /**
   * Creates a mock group entity with billing fields.
   *
   * @param array $fields
   *   Field values keyed by field name.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked group entity.
   */
  protected function createMockGroup(array $fields = []): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheTags')->willReturn(['group:14']);
    $group->method('getCacheMaxAge')->willReturn(-1);

    $group->method('hasField')
      ->willReturnCallback(fn(string $name) => array_key_exists($name, $fields));

    $group->method('get')
      ->willReturnCallback(function (string $name) use ($fields) {
        $value = $fields[$name] ?? NULL;
        // @phpcs:disable Drupal.Commenting.DocComment
        return new class ($value) {

          /**
           * The field value.
           *
           * @var mixed
           */
          public $value;

          /**
           * Whether the field is empty.
           *
           * @var bool
           */
          private bool $empty;

          /**
           * Constructs a field item stub.
           */
          public function __construct($value) {
            $this->value = $value;
            $this->empty = ($value === NULL);
          }

          /**
           * Returns whether the field is empty.
           */
          public function isEmpty(): bool {
            return $this->empty;
          }

        };
        // @phpcs:enable
      });

    return $group;
  }

  /**
   * Creates a writable group mock for PATCH tests.
   */
  protected function createWritableGroup(?string $storedCustomer = 'cus_existing'): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $group->method('hasField')->willReturn(TRUE);
    $group->method('get')
      ->willReturnCallback(fn(string $name) => $name === 'field_stripe_customer_id'
        ? $this->createFieldItem($storedCustomer)
        : $this->createFieldItem(NULL));

    return $group;
  }

  /**
   * Creates a minimal field item list stub.
   */
  protected function createFieldItem($value): object {
    return new class ($value) {

      /**
       * The first field item value.
       *
       * @var mixed
       */
      public $value;

      /**
       * Constructs the field item stub.
       */
      public function __construct($value) {
        $this->value = $value;
      }

      /**
       * Returns whether the field item is empty.
       */
      public function isEmpty(): bool {
        return $this->value === NULL || $this->value === '';
      }

    };
  }

  /**
   * Creates a tenant admin account for a billing group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The billing group.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The tenant admin account.
   */
  protected function createTenantAdminAccountForGroup(GroupInterface $group): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(3);
    $account->method('getRoles')->willReturn(['authenticated']);
    $account->method('hasPermission')->willReturn(FALSE);

    $role = $this->createMock(GroupRoleInterface::class);
    $role->method('id')->willReturn('jur-tenant_admin');

    $membership = $this->createMock(GroupMembership::class);
    $membership->method('getRoles')->willReturn([$role]);

    $group->method('getMember')
      ->with($account)
      ->willReturn($membership);

    return $account;
  }

  /**
   * Tests get() allows authenticated admin access without service key.
   *
   * @covers ::get
   */
  public function testGetAllowsAdminWithoutServiceKey(): void {
    $request = Request::create('/api/billing/14', 'GET');

    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->get('14', $request);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests service-key-only GET access is denied for anonymous users.
   *
   * @covers ::access
   */
  public function testAccessDeniesReadWithOnlyServiceKey(): void {
    $request = Request::create(
      '/api/billing/14',
      'GET',
      [],
      [],
      [],
      ['HTTP_X_SERVICE_KEY' => 'test-service-key-456']
    );
    $this->requestStack->push($request);

    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(3);
    $account->method('getRoles')->willReturn(['authenticated']);

    $this->assertFalse($this->controller->access('14', $account)->isAllowed());
  }

  /**
   * Tests tenant admins can read billing for their own jurisdiction.
   *
   * @covers ::access
   */
  public function testAccessAllowsTenantAdminReadForOwnJurisdiction(): void {
    $request = Request::create('/api/billing/14', 'GET');
    $this->requestStack->push($request);

    $group = $this->createMockGroup([]);
    $account = $this->createTenantAdminAccountForGroup($group);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $this->assertTrue($this->controller->access('14', $account)->isAllowed());
  }

  /**
   * Tests get() returns 404 when group is not found.
   *
   * @covers ::get
   */
  public function testGetReturns404WhenGroupNotFound(): void {
    $request = Request::create(
      '/api/billing/999',
      'GET',
      [],
      [],
      [],
      ['HTTP_X_SERVICE_KEY' => 'test-service-key-456']
    );

    $this->groupStorage->method('load')->with(999)->willReturn(NULL);

    $response = $this->controller->get('999', $request);

    $this->assertEquals(404, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Jurisdiction not found', $data['error']);
  }

  /**
   * Tests get() returns 404 when group has wrong bundle.
   *
   * @covers ::get
   */
  public function testGetReturns404WhenGroupIsNotJur(): void {
    $request = Request::create(
      '/api/billing/14',
      'GET',
      [],
      [],
      [],
      ['HTTP_X_SERVICE_KEY' => 'test-service-key-456']
    );

    $wrongGroup = $this->createMock(GroupInterface::class);
    $wrongGroup->method('bundle')->willReturn('org');
    $this->groupStorage->method('load')->with(14)->willReturn($wrongGroup);

    $response = $this->controller->get('14', $request);

    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Tests get() returns billing data with defaults for a group.
   *
   * @covers ::get
   */
  public function testGetReturnsBillingDataWithDefaults(): void {
    $request = Request::create(
      '/api/billing/14',
      'GET',
      [],
      [],
      [],
      ['HTTP_X_SERVICE_KEY' => 'test-service-key-456']
    );

    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->get('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    // Tier is never set eagerly — empty group has NULL tier.
    $this->assertNull($data['tier']);
    $this->assertNull($data['stripe_customer_id']);
    $this->assertNull($data['stripe_subscription_id']);
    $this->assertNull($data['expiry_date']);
    $this->assertNull($data['billing_name']);
    // A group with no expiry, no customer, no subscription, no tier has no
    // recognizable lifecycle state.
    $this->assertEquals('unknown', $data['effective_state']);
  }

  /**
   * Tests get() returns actual field values when present.
   *
   * @covers ::get
   */
  public function testGetReturnsBillingDataWithFieldValues(): void {
    $request = Request::create(
      '/api/billing/14',
      'GET',
      [],
      [],
      [],
      ['HTTP_X_SERVICE_KEY' => 'test-service-key-456']
    );

    $group = $this->createMockGroup([
      'field_tier' => 'pro',
      'field_stripe_customer_id' => 'cus_123',
      'field_stripe_subscription_id' => 'sub_456',
      'field_expiry_date' => '1700000000',
      'field_billing_name' => 'Test Company',
      'field_billing_email' => 'billing@example.com',
      'field_billing_country' => 'DE',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->get('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('pro', $data['tier']);
    $this->assertEquals('cus_123', $data['stripe_customer_id']);
    $this->assertEquals('sub_456', $data['stripe_subscription_id']);
    $this->assertEquals(1700000000, $data['expiry_date']);
    $this->assertEquals('Test Company', $data['billing_name']);
    $this->assertEquals('billing@example.com', $data['billing_email']);
    $this->assertEquals('DE', $data['billing_country']);
    // Expiry set AND subscription set is a transitional inconsistency that
    // should never occur in production: the webhook clears expiry the moment
    // it activates the subscription. Marked 'unknown' so the frontend can
    // flag it rather than silently rendering a misleading badge.
    $this->assertEquals('unknown', $data['effective_state']);
  }

  /**
   * Tests valid service key in JSON body does not authorize billing reads.
   *
   * @covers ::get
   */
  public function testGetDeniesValidServiceKeyFromBodyWithoutMembership(): void {
    $this->currentUserId = 3;
    $this->currentUserRoles = ['authenticated'];

    $request = $this->createJsonRequest(
      ['service_key' => 'test-service-key-456'],
    );

    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->get('14', $request);

    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Access denied', $data['error']);
  }

  /**
   * Tests update() returns 403 when service key is invalid.
   *
   * @covers ::update
   */
  public function testUpdateReturns403WithInvalidKey(): void {
    $request = $this->createJsonRequest(
      ['tier' => 'pro'],
      'wrong-key'
    );

    $this->logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('billing.access_invalid_key'));

    $response = $this->controller->update('14', $request);

    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
   * Tests update() returns 404 when group is not found.
   *
   * @covers ::update
   */
  public function testUpdateReturns404WhenGroupNotFound(): void {
    $request = $this->createJsonRequest(
      ['tier' => 'pro'],
      'test-service-key-456'
    );

    $this->groupStorage->method('load')->with(999)->willReturn(NULL);

    $response = $this->controller->update('999', $request);

    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Tests update() returns 400 for invalid JSON body.
   *
   * @covers ::update
   */
  public function testUpdateReturns400ForInvalidJson(): void {
    $server = [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_SERVICE_KEY' => 'test-service-key-456',
    ];
    $request = Request::create(
      '/api/billing/14',
      'PATCH',
      [],
      [],
      [],
      $server,
      'not-json'
    );

    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->update('14', $request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Invalid JSON body', $data['error']);
  }

  /**
   * Tests update() returns 400 for invalid tier value.
   *
   * @covers ::update
   */
  public function testUpdateReturns400ForInvalidTier(): void {
    $request = $this->createJsonRequest(
      ['tier' => 'diamond', 'stripe_customer_id' => 'cus_existing'],
      'test-service-key-456'
    );

    $group = $this->createWritableGroup();
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->update('14', $request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Invalid tier value', $data['error']);
  }

  /**
   * Tests update() returns 400 when no valid fields are provided.
   *
   * @covers ::update
   */
  public function testUpdateReturns400WhenNoValidFields(): void {
    $request = $this->createJsonRequest(
      ['unknown_field' => 'value', 'stripe_customer_id' => 'cus_existing'],
      'test-service-key-456'
    );

    $group = $this->createWritableGroup();
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->update('14', $request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('No valid fields to update', $data['error']);
  }

  /**
   * Tests update() requires a claimed Stripe customer.
   *
   * @covers ::update
   */
  public function testUpdateRequiresStripeCustomer(): void {
    $request = $this->createJsonRequest(
      ['tier' => 'pro'],
      'test-service-key-456'
    );

    $group = $this->createWritableGroup();
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->update('14', $request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('stripe_customer_id required for billing update', $data['error']);
  }

  /**
   * Tests update() rejects a mismatched Stripe customer.
   *
   * @covers ::update
   */
  public function testUpdateRejectsStripeCustomerMismatch(): void {
    $request = $this->createJsonRequest(
      ['tier' => 'pro', 'stripe_customer_id' => 'cus_other'],
      'test-service-key-456'
    );

    $group = $this->createWritableGroup('cus_existing');
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $this->logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('billing.scope_violation'));

    $response = $this->controller->update('14', $request);

    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Stripe customer mismatch', $data['error']);
  }

  /**
   * Tests update() logs matching Stripe customer claims.
   *
   * @covers ::update
   */
  public function testUpdateLogsStripeCustomerMatch(): void {
    $request = $this->createJsonRequest(
      ['tier' => 'pro', 'stripe_customer_id' => 'cus_existing'],
      'test-service-key-456'
    );

    $group = $this->createWritableGroup('cus_existing');
    $group->method('save');
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $messages = [];
    $this->logger->method('info')
      ->willReturnCallback(static function (string $message) use (&$messages): void {
        $messages[] = $message;
      });

    $response = $this->controller->update('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue((bool) array_filter($messages, static fn(string $message): bool => str_contains($message, 'billing.match')));
  }

  /**
   * Tests update() successfully updates valid fields and returns them.
   *
   * @covers ::update
   */
  public function testUpdateSuccessfullyUpdatesFields(): void {
    $request = $this->createJsonRequest(
      [
        'tier' => 'pro',
        'stripe_customer_id' => 'cus_existing',
        'billing_name' => 'Updated Name',
      ],
      'test-service-key-456'
    );

    $group = $this->createWritableGroup();
    $group->expects($this->atLeast(2))->method('set');
    $group->expects($this->once())->method('save');

    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->update('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertContains('tier', $data['updated']);
    $this->assertContains('billing_name', $data['updated']);
    $this->assertEquals(14, $data['group_id']);
  }

  /**
   * Tests update() allows setting fields to null to clear them.
   *
   * @covers ::update
   */
  public function testUpdateAllowsNullToClearFields(): void {
    $request = $this->createJsonRequest(
      ['expiry_date' => NULL, 'stripe_customer_id' => 'cus_existing'],
      'test-service-key-456'
    );

    $group = $this->createWritableGroup();
    $group->expects($this->once())
      ->method('set')
      ->with('field_expiry_date', NULL);
    $group->expects($this->once())->method('save');

    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->update('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertContains('expiry_date', $data['updated']);
  }

  /**
   * Tests update() returns 500 when entity save fails.
   *
   * @covers ::update
   */
  public function testUpdateReturns500WhenSaveFails(): void {
    $request = $this->createJsonRequest(
      ['tier' => 'starter', 'stripe_customer_id' => 'cus_existing'],
      'test-service-key-456'
    );

    $group = $this->createWritableGroup();
    $group->method('save')->willThrowException(new \Exception('DB error'));

    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Failed to update billing'),
        $this->anything()
      );

    $response = $this->controller->update('14', $request);

    $this->assertEquals(500, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Failed to update billing data', $data['error']);
  }

  /**
   * Tests update() truncates string fields to max length.
   *
   * @covers ::update
   */
  public function testUpdateTruncatesLongValues(): void {
    $longCountry = 'DEU';
    $request = $this->createJsonRequest(
      ['billing_country' => $longCountry, 'stripe_customer_id' => 'cus_existing'],
      'test-service-key-456'
    );

    $group = $this->createWritableGroup();

    // billing_country max length is 2 characters.
    $group->expects($this->once())
      ->method('set')
      ->with('field_billing_country', 'DE');
    $group->method('save');

    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->update('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests update() stores expiry_date as integer.
   *
   * @covers ::update
   */
  public function testUpdateStoresExpiryDateAsInteger(): void {
    $request = $this->createJsonRequest(
      ['expiry_date' => '1700000000', 'stripe_customer_id' => 'cus_existing'],
      'test-service-key-456'
    );

    $group = $this->createWritableGroup();

    $group->expects($this->once())
      ->method('set')
      ->with('field_expiry_date', 1700000000);
    $group->method('save');

    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->update('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests all valid tier values are accepted.
   *
   * @covers ::update
   *
   * @dataProvider validTierProvider
   */
  public function testUpdateAcceptsValidTiers(string $tier): void {
    $request = $this->createJsonRequest(
      ['tier' => $tier, 'stripe_customer_id' => 'cus_existing'],
      'test-service-key-456'
    );

    $group = $this->createWritableGroup();
    $group->method('save');

    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->update('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Provides valid tier values.
   *
   * @return array
   *   Valid tiers.
   */
  public static function validTierProvider(): array {
    return [
      'free' => ['free'],
      'starter' => ['starter'],
      'pro' => ['pro'],
      'heart' => ['heart'],
    ];
  }

  /**
   * Demo state: expiry IS NOT NULL AND stripe_subscription_id IS EMPTY.
   *
   * @covers ::get
   */
  public function testEffectiveStateForDemo(): void {
    $request = Request::create('/api/billing/14', 'GET');

    $group = $this->createMockGroup([
      'field_expiry_date' => '1700000000',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->get('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('demo', $data['effective_state']);
    $this->assertNull($data['tier']);
    $this->assertNull($data['stripe_subscription_id']);
  }

  /**
   * Pending checkout: customer present, no subscription, no expiry.
   *
   * User clicked "Start checkout", Stripe customer created, but webhook
   * has not yet confirmed the subscription.
   *
   * @covers ::get
   */
  public function testEffectiveStateForPendingCheckout(): void {
    $request = Request::create('/api/billing/14', 'GET');

    $group = $this->createMockGroup([
      'field_stripe_customer_id' => 'cus_pending',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->get('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('pending_checkout', $data['effective_state']);
    $this->assertEquals('cus_pending', $data['stripe_customer_id']);
    $this->assertNull($data['stripe_subscription_id']);
  }

  /**
   * Canceled: customer present, no subscription, tier set.
   *
   * Subscription was canceled (by user or Stripe). Webhook downgrades
   * tier to 'free' but the customer record persists. Distinct from
   * pending_checkout where tier is still NULL.
   *
   * @covers ::get
   */
  public function testEffectiveStateForCanceled(): void {
    $request = Request::create('/api/billing/14', 'GET');

    $group = $this->createMockGroup([
      'field_tier' => 'free',
      'field_stripe_customer_id' => 'cus_canceled',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->get('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('canceled', $data['effective_state']);
    $this->assertEquals('cus_canceled', $data['stripe_customer_id']);
    $this->assertNull($data['stripe_subscription_id']);
  }

  /**
   * Free permanent: no expiry, has subscription, tier='free'.
   *
   * Admin-granted permanent free plan (e.g. partner, hardship). Distinct
   * from the demo state where tier is NULL.
   *
   * @covers ::get
   */
  public function testEffectiveStateForFreePermanent(): void {
    $request = Request::create('/api/billing/14', 'GET');

    $group = $this->createMockGroup([
      'field_tier' => 'free',
      'field_stripe_customer_id' => 'cus_partner',
      'field_stripe_subscription_id' => 'sub_free_perm',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->get('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('free_permanent', $data['effective_state']);
    $this->assertEquals('free', $data['tier']);
  }

  /**
   * Paid: no expiry, tier in starter/pro/heart.
   *
   * @covers ::get
   *
   * @dataProvider paidTierProvider
   */
  public function testEffectiveStateForPaid(string $tier): void {
    $request = Request::create('/api/billing/14', 'GET');

    $group = $this->createMockGroup([
      'field_tier' => $tier,
      'field_stripe_customer_id' => 'cus_paid',
      'field_stripe_subscription_id' => 'sub_active',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->get('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('paid', $data['effective_state']);
    $this->assertEquals($tier, $data['tier']);
  }

  /**
   * Provides paid tier values for effective_state tests.
   */
  public static function paidTierProvider(): array {
    return [
      'starter' => ['starter'],
      'pro' => ['pro'],
      'heart' => ['heart'],
    ];
  }

  /**
   * Update accepts NULL tier (subscription deletion path).
   *
   * Customer.subscription.deleted resets field_tier to NULL so the workspace
   * falls back to demo-equivalent state, never to 'free'.
   *
   * @covers ::update
   */
  public function testUpdateAcceptsNullTier(): void {
    $request = $this->createJsonRequest(
      ['tier' => NULL, 'stripe_customer_id' => 'cus_existing'],
      'test-service-key-456'
    );

    $group = $this->createWritableGroup();
    $group->expects($this->atLeastOnce())
      ->method('set')
      ->willReturnSelf();
    $group->expects($this->once())->method('save');

    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->update('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertContains('tier', $data['updated']);
  }

}
