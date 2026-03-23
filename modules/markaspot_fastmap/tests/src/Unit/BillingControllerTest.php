<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Controller\BillingController;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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
   * The mocked group storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $groupStorage;

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

    // Set up the Drupal container.
    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('logger.channel.markaspot_fastmap', $this->logger);
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
      'POST',
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
   * Tests get() returns 403 when service key is missing.
   *
   * @covers ::get
   */
  public function testGetReturns403WithoutServiceKey(): void {
    $request = Request::create('/api/billing/14', 'GET');

    $this->groupStorage->method('load')->willReturn(NULL);

    $response = $this->controller->get('14', $request);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
   * Tests get() returns 403 when service key is wrong.
   *
   * @covers ::get
   */
  public function testGetReturns403WithWrongServiceKey(): void {
    $request = Request::create(
      '/api/billing/14',
      'GET',
      [],
      [],
      [],
      ['HTTP_X_SERVICE_KEY' => 'wrong-key']
    );

    $response = $this->controller->get('14', $request);

    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Invalid service key', $data['error']);
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
    $this->assertEquals('free', $data['tier']);
    $this->assertNull($data['stripe_customer_id']);
    $this->assertNull($data['stripe_subscription_id']);
    $this->assertNull($data['expiry_date']);
    $this->assertNull($data['billing_name']);
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
  }

  /**
   * Tests get() accepts service key from JSON body.
   *
   * @covers ::get
   */
  public function testGetAcceptsServiceKeyFromBody(): void {
    $request = $this->createJsonRequest(
      ['service_key' => 'test-service-key-456'],
    );

    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->get('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
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
      ['tier' => 'diamond'],
      'test-service-key-456'
    );

    $group = $this->createMockGroup([
      'field_tier' => 'free',
    ]);
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
      ['unknown_field' => 'value'],
      'test-service-key-456'
    );

    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->update('14', $request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('No valid fields to update', $data['error']);
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
        'stripe_customer_id' => 'cus_new',
        'billing_name' => 'Updated Name',
      ],
      'test-service-key-456'
    );

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $group->method('hasField')->willReturn(TRUE);

    $fieldItem = $this->createMock(FieldItemListInterface::class);
    $group->method('get')->willReturn($fieldItem);
    $group->expects($this->atLeast(3))->method('set');
    $group->expects($this->once())->method('save');

    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->update('14', $request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertContains('tier', $data['updated']);
    $this->assertContains('stripe_customer_id', $data['updated']);
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
      ['expiry_date' => NULL],
      'test-service-key-456'
    );

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $group->method('hasField')->willReturn(TRUE);

    $fieldItem = $this->createMock(FieldItemListInterface::class);
    $group->method('get')->willReturn($fieldItem);
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
      ['tier' => 'starter'],
      'test-service-key-456'
    );

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $group->method('hasField')->willReturn(TRUE);

    $fieldItem = $this->createMock(FieldItemListInterface::class);
    $group->method('get')->willReturn($fieldItem);
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
      ['billing_country' => $longCountry],
      'test-service-key-456'
    );

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $group->method('hasField')->willReturn(TRUE);

    $fieldItem = $this->createMock(FieldItemListInterface::class);
    $group->method('get')->willReturn($fieldItem);

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
      ['expiry_date' => '1700000000'],
      'test-service-key-456'
    );

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $group->method('hasField')->willReturn(TRUE);

    $fieldItem = $this->createMock(FieldItemListInterface::class);
    $group->method('get')->willReturn($fieldItem);

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
      ['tier' => $tier],
      'test-service-key-456'
    );

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $group->method('hasField')->willReturn(TRUE);

    $fieldItem = $this->createMock(FieldItemListInterface::class);
    $group->method('get')->willReturn($fieldItem);
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

}
