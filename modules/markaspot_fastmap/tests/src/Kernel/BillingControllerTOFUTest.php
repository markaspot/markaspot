<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_fastmap\Controller\BillingController;
use Drupal\markaspot_fastmap\Service\BillingStateResolver;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests BillingController initial Stripe customer TOFU behavior.
 *
 * @group markaspot_fastmap
 */
#[RunTestsInSeparateProcesses]
class BillingControllerTOFUTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
  ];

  /**
   * Tests a legacy PATCH can bind its first Stripe customer before token use.
   */
  public function testFirstPatchSetsCustomer(): void {
    $this->createFixtureTables();
    $this->insertGroupRow();

    $group = $this->mockGroup(NULL);
    $initialCustomer = NULL;
    $group->expects($this->once())
      ->method('set')
      ->willReturnCallback(static function (string $field, mixed $value) use (&$initialCustomer): void {
        if ($field === 'field_stripe_customer_id') {
          $initialCustomer = $value;
        }
      });
    $logger = $this->createMock(LoggerInterface::class);
    $messages = [];
    $logger->method('info')
      ->willReturnCallback(static function (string $message) use (&$messages): void {
        $messages[] = $message;
      });

    $controller = $this->createController($group, $logger);
    $request = $this->createPatchRequest(['stripe_customer_id' => 'cus_first']);

    $response = $controller->update('14', $request);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertContains('stripe_customer_id', $data['updated']);
    $this->assertSame('cus_first', $initialCustomer);
    $this->assertTrue((bool) array_filter($messages, static fn(string $message): bool => str_contains($message, 'billing.initial_customer_set')));
  }

  /**
   * Tests a concurrent loser is rejected when another customer already won.
   */
  public function testRaceConditionAtomicSet(): void {
    $this->createFixtureTables();
    $this->insertGroupRow();
    $this->insertStoredCustomer('cus_winner');

    $group = $this->mockGroup('cus_winner');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('billing.scope_violation'));

    $controller = $this->createController($group, $logger);
    $request = $this->createPatchRequest(['stripe_customer_id' => 'cus_loser']);

    $response = $controller->update('14', $request);

    $this->assertSame(403, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('Stripe customer mismatch', $data['error']);
    $this->assertSame('cus_winner', $this->loadStoredCustomer());
  }

  /**
   * Tests a rejected first claim does not persist the Stripe customer anchor.
   */
  public function testRejectedFirstPatchRollsBackCustomerClaim(): void {
    $this->createFixtureTables();
    $this->insertGroupRow();

    $group = $this->mockGroup(NULL);
    $logger = $this->createMock(LoggerInterface::class);

    $controller = $this->createController($group, $logger);
    $request = $this->createPatchRequest([
      'stripe_customer_id' => 'cus_rejected',
      'tier' => 'diamond',
    ]);

    $response = $controller->update('14', $request);

    $this->assertSame(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('Invalid tier value', $data['error']);
    $this->assertNull($this->loadStoredCustomer());
  }

  /**
   * Tests that a later token invalidates an earlier Stripe read result.
   */
  public function testSyncTokenSupersedesOlderWriters(): void {
    $this->createFixtureTables();
    $this->insertGroupRow();
    $this->insertStoredCustomer('cus_bound');

    $group = $this->mockGroup('cus_bound');
    $group->expects($this->once())->method('save');
    $logger = $this->createMock(LoggerInterface::class);
    $controller = $this->createController($group, $logger);

    $first = $controller->reserveSyncToken('14', $this->createTokenRequest('cus_bound'));
    $second = $controller->reserveSyncToken('14', $this->createTokenRequest('cus_bound'));
    $tokenOne = json_decode($first->getContent(), TRUE)['sync_token'];
    $tokenTwo = json_decode($second->getContent(), TRUE)['sync_token'];

    $this->assertSame(200, $first->getStatusCode());
    $this->assertSame(200, $second->getStatusCode());
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $tokenOne);
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $tokenTwo);
    $this->assertNotSame($tokenOne, $tokenTwo);

    $stale = $controller->update('14', $this->createPatchRequest([
      'stripe_customer_id' => 'cus_bound',
      'tier' => 'pro',
    ], $tokenOne));
    $this->assertSame(409, $stale->getStatusCode());
    $this->assertSame('Billing sync token is stale. Request a new token and retry.', json_decode($stale->getContent(), TRUE)['error']);

    $current = $controller->update('14', $this->createPatchRequest([
      'stripe_customer_id' => 'cus_bound',
      'tier' => 'pro',
    ], $tokenTwo));
    $this->assertSame(200, $current->getStatusCode());
    $this->assertSame($tokenTwo, $this->loadSyncToken());
  }

  /**
   * Tests that opting in to token sync rejects an unversioned PATCH.
   */
  public function testSyncTokenRejectsMissingTokenAfterReservation(): void {
    $this->createFixtureTables();
    $this->insertGroupRow();
    $this->insertStoredCustomer('cus_bound');

    $group = $this->mockGroup('cus_bound');
    $group->expects($this->never())->method('save');
    $controller = $this->createController($group, $this->createMock(LoggerInterface::class));
    $this->assertSame(200, $controller->reserveSyncToken('14', $this->createTokenRequest('cus_bound'))->getStatusCode());

    $response = $controller->update('14', $this->createPatchRequest([
      'stripe_customer_id' => 'cus_bound',
      'tier' => 'starter',
    ]));
    $this->assertSame(409, $response->getStatusCode());
  }

  /**
   * Tests a token request cannot bind or replace a different customer.
   */
  public function testSyncTokenRejectsForeignCustomerWithoutWritingToken(): void {
    $this->createFixtureTables();
    $this->insertGroupRow();
    $this->insertStoredCustomer('cus_bound');

    $controller = $this->createController($this->mockGroup('cus_bound'), $this->createMock(LoggerInterface::class));
    $valid = $controller->reserveSyncToken('14', $this->createTokenRequest('cus_bound'));
    $validToken = json_decode($valid->getContent(), TRUE)['sync_token'];
    $response = $controller->reserveSyncToken('14', $this->createTokenRequest('cus_foreign'));

    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame($validToken, $this->loadSyncToken());
    $this->assertSame('cus_bound', $this->loadStoredCustomer());
  }

  /**
   * Creates a BillingController wired to the kernel database.
   */
  private function createController(GroupInterface $group, LoggerInterface $logger): BillingController {
    $fastmapConfig = $this->createMock(ImmutableConfig::class);
    $fastmapConfig->method('get')
      ->willReturnCallback(fn(string $key) => $key === 'service_key' ? 'test-service-key-456' : NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->willReturn($fastmapConfig);

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')->with(14)->willReturn($group);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);
    $account->method('getRoles')->willReturn(['authenticated', 'administrator']);

    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('entity_type.manager', $entityTypeManager);
    $container->set('logger.channel.markaspot_fastmap', $logger);
    $container->set('database', $this->container->get('database'));
    $container->set('current_user', $account);
    $container->set('request_stack', new RequestStack());
    $container->set('cache_contexts_manager', $cacheContextsManager);
    $container->set('markaspot_fastmap.billing_state_resolver', new BillingStateResolver());
    \Drupal::setContainer($container);

    return BillingController::create($container);
  }

  /**
   * Creates a PATCH request with a valid service key.
   */
  private function createPatchRequest(array $data, ?string $syncToken = NULL): Request {
    $headers = [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_SERVICE_KEY' => 'test-service-key-456',
    ];
    if ($syncToken !== NULL) {
      $headers['HTTP_X_BILLING_SYNC_TOKEN'] = $syncToken;
    }
    return Request::create(
      '/api/fastmap/billing/14',
      'PATCH',
      [],
      [],
      [],
      $headers,
      json_encode($data)
    );
  }

  /**
   * Creates a POST token reservation request with a valid service key.
   */
  private function createTokenRequest(string $customer): Request {
    return Request::create(
      '/api/fastmap/billing/14/sync-token',
      'POST',
      [],
      [],
      [],
      [
        'HTTP_X_SERVICE_KEY' => 'test-service-key-456',
        'HTTP_X_STRIPE_CUSTOMER' => $customer,
      ]
    );
  }

  /**
   * Creates a group test double.
   */
  private function mockGroup(?string $storedCustomer): GroupInterface {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $group->method('isPublished')->willReturn(TRUE);
    $group->method('getRevisionId')->willReturn('140');
    $group->method('language')->willReturn($language);
    $group->method('hasField')->willReturn(TRUE);
    $group->method('get')
      ->willReturnCallback(fn(string $name) => $name === 'field_stripe_customer_id'
        ? $this->fieldItem($storedCustomer)
        : $this->fieldItem(NULL));
    $group->method('set');
    $group->method('save');

    return $group;
  }

  /**
   * Creates a minimal field item list stub.
   */
  private function fieldItem(?string $value): object {
    return new class ($value) {

      /**
       * The first field item value.
       */
      public ?string $value;

      /**
       * Constructs the field item stub.
       */
      public function __construct(?string $value) {
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
   * Creates the minimal billing field tables.
   */
  private function createFixtureTables(): void {
    $schema = $this->container->get('database')->schema();

    $schema->createTable('groups', [
      'fields' => [
        'id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'type' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
        'revision_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
      ],
      'primary key' => ['id'],
    ]);
    $schema->createTable('group__field_stripe_customer_id', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'revision_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'langcode' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
        'delta' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'field_stripe_customer_id_value' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
      ],
      'primary key' => ['entity_id', 'deleted', 'delta', 'langcode'],
    ]);
    $schema->createTable('key_value', [
      'fields' => [
        'collection' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'name' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'value' => ['type' => 'blob', 'size' => 'big', 'not null' => TRUE],
      ],
      'primary key' => ['collection', 'name'],
    ]);
  }

  /**
   * Inserts the jurisdiction group row.
   */
  private function insertGroupRow(): void {
    $this->container->get('database')->insert('groups')
      ->fields(['id', 'type', 'revision_id'])
      ->values([14, 'jur', 140])
      ->execute();
  }

  /**
   * Inserts a stored Stripe customer row.
   */
  private function insertStoredCustomer(string $customerId): void {
    $this->container->get('database')->insert('group__field_stripe_customer_id')
      ->fields(['bundle', 'deleted', 'entity_id', 'revision_id', 'langcode', 'delta', 'field_stripe_customer_id_value'])
      ->values(['jur', 0, 14, 140, 'en', 0, $customerId])
      ->execute();
  }

  /**
   * Loads the stored Stripe customer from the fixture table.
   */
  private function loadStoredCustomer(): ?string {
    $value = $this->container->get('database')
      ->select('group__field_stripe_customer_id', 'f')
      ->fields('f', ['field_stripe_customer_id_value'])
      ->condition('entity_id', 14)
      ->condition('deleted', 0)
      ->execute()
      ->fetchField();

    return is_string($value) ? $value : NULL;
  }

  /**
   * Loads the raw stored sync token for the fixture group.
   */
  private function loadSyncToken(): ?string {
    $value = $this->container->get('database')
      ->select('key_value', 'kv')
      ->fields('kv', ['value'])
      ->condition('collection', 'markaspot_fastmap.billing_sync')
      ->condition('name', 'group:14')
      ->execute()
      ->fetchField();

    return is_string($value) && $value !== '' ? $value : NULL;
  }

}
