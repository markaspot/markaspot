<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\DatabaseStorageExpirable;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_fastmap\Controller\FastMapWorkspaceController;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Exercises token consumption with actual database storage and lock owners.
 */
#[Group('markaspot_fastmap')]
final class LoginTokenStorageConcurrencyTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * A competing claim cannot consume a token while its first reader holds it.
   */
  public function testOverlappingClaimsAndReplay(): void {
    $database = $this->container->get('database');
    $time = $this->container->get('datetime.time');
    $first_store = new class('markaspot_fastmap_login_tokens', new PhpSerialize(), $database, $time) extends DatabaseStorageExpirable {

      /**
       * Runs another request exactly between reading and deleting the token.
       */
      public ?\Closure $afterRead = NULL;

      /**
       * {@inheritdoc}
       */
      public function get($key, $default = NULL) {
        $value = parent::get($key, $default);
        if ($this->afterRead !== NULL) {
          $callback = $this->afterRead;
          $this->afterRead = NULL;
          $callback();
        }
        return $value;
      }

    };
    $second_store = new DatabaseStorageExpirable('markaspot_fastmap_login_tokens', new PhpSerialize(), $database, $time);
    $first_lock = new DatabaseLockBackend($database);
    $second_lock = new DatabaseLockBackend($database);
    $this->assertNotSame($first_lock->getLockId(), $second_lock->getLockId());

    $first = $this->controller($first_store, $first_lock, 1);
    $second = $this->controller($second_store, $second_lock, 0);
    $token = bin2hex(random_bytes(32));
    $first_store->setWithExpire($token, ['uid' => 42, 'slug' => 'synthetic-concurrency'], 300);
    $competing_status = NULL;
    $first_store->afterRead = function () use ($second, $token, &$competing_status): void {
      $response = $second->claimLoginToken($this->request($token));
      $competing_status = $response->getStatusCode();
      $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    };

    // A blocked fixture avoids session side effects, while proving consumption
    // happens exactly once even when the subsequent account check fails.
    $response = $first->claimLoginToken($this->request($token));
    $this->assertSame(409, $competing_status);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertNull($second_store->get($token));
    $replay = $second->claimLoginToken($this->request($token));
    $this->assertSame(401, $replay->getStatusCode());
    $this->assertTrue($replay->headers->hasCacheControlDirective('no-store'));
    $name = 'markaspot_fastmap:claim_login:' . hash('sha256', $token);
    $this->assertTrue($second_lock->acquire($name));
    $second_lock->release($name);
  }

  /**
   * Actual expirable storage rejects expired tokens before loading an account.
   */
  public function testExpiredTokenNeverReachesAccountLookup(): void {
    $database = $this->container->get('database');
    $store = new DatabaseStorageExpirable('markaspot_fastmap_login_tokens', new PhpSerialize(), $database, $this->container->get('datetime.time'));
    $token = bin2hex(random_bytes(32));
    $store->setWithExpire($token, ['uid' => 42, 'slug' => 'synthetic-expired'], -1);
    $controller = $this->controller($store, new DatabaseLockBackend($database), 0);
    $response = $controller->claimLoginToken($this->request($token));
    $this->assertSame(401, $response->getStatusCode());
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
  }

  /**
   * Builds the real controller with isolated storage and a blocked account.
   */
  private function controller(DatabaseStorageExpirable $store, DatabaseLockBackend $lock, int $account_loads): FastMapWorkspaceController {
    $factory = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $factory->method('get')->with('markaspot_fastmap_login_tokens')->willReturn($store);
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);
    $user = $this->createMock(UserInterface::class);
    $user->method('isBlocked')->willReturn(TRUE);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->exactly($account_loads))->method('load')->with(42)->willReturn($user);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('user')->willReturn($storage);
    $controller = new FastMapWorkspaceController();
    foreach (['keyValueExpirable' => $factory, 'lock' => $lock, 'flood' => $flood, 'entityTypeManager' => $manager] as $name => $value) {
      (new \ReflectionProperty($controller, $name))->setValue($controller, $value);
    }
    return $controller;
  }

  /**
   * Creates a synthetic request without any browser or runtime session.
   */
  private function request(string $token): Request {
    return Request::create('/api/fastmap/claim-login-token', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.1'], json_encode(['token' => $token], JSON_THROW_ON_ERROR));
  }

}
