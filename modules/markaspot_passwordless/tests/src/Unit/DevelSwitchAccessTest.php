<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Unit;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_passwordless\Access\DevelSwitchTargetAccessCheck;
use Drupal\markaspot_passwordless\Routing\DevelSwitchRouteSubscriber;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

require_once dirname(__DIR__, 3) . '/src/Access/DevelSwitchTargetAccessCheck.php';
require_once dirname(__DIR__, 3) . '/src/Routing/DevelSwitchRouteSubscriber.php';

/**
 * Tests the profile-level access guard for Devel user switching.
 *
 * @group markaspot_passwordless
 */
final class DevelSwitchAccessTest extends UnitTestCase {

  /**
   * Both Devel switch routes receive the additional target access check.
   */
  public function testRouteSubscriberGuardsDevelSwitchRoutes(): void {
    $collection = new RouteCollection();
    $collection->add('devel.switch', new Route('/devel/switch/{name}'));
    $collection->add('devel.switch_user', new Route('/devel/switch-user'));
    $unrelated = new Route('/unrelated');
    $collection->add('example.unrelated', $unrelated);

    $this->routeSubscriber()->alter($collection);

    foreach (['devel.switch', 'devel.switch_user'] as $routeName) {
      $this->assertSame(
        'markaspot_passwordless.devel_switch_target_access:access',
        $collection->get($routeName)?->getRequirement('_custom_access'),
      );
    }
    $this->assertNull($unrelated->getRequirement('_custom_access'));
  }

  /**
   * Missing Devel routes leave route rebuilding unaffected.
   */
  public function testRouteSubscriberAllowsDevelToBeAbsent(): void {
    $collection = new RouteCollection();

    $this->routeSubscriber()->alter($collection);

    $this->assertCount(0, $collection);
  }

  /**
   * The selector page remains available because target links are guarded.
   */
  public function testSelectorRouteIsAllowedWithoutTarget(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->never())->method('getStorage');

    $result = (new DevelSwitchTargetAccessCheck($manager))->access();

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Super admin and blocked accounts cannot be switch targets.
   */
  public function testUnsafeTargetsAreForbidden(): void {
    $uidOne = $this->targetAccount(1, FALSE);
    $blocked = $this->targetAccount(42, TRUE);

    $this->assertTrue($this->accessCheckFor($uidOne, 'root')->isForbidden());
    $this->assertTrue($this->accessCheckFor($blocked, 'blocked')->isForbidden());
  }

  /**
   * Active non-super-admin accounts remain valid switch targets.
   */
  public function testActiveTargetIsAllowed(): void {
    $result = $this->accessCheckFor(
      $this->targetAccount(42, FALSE),
      'active',
    );

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Builds a route subscriber with a public test seam.
   */
  private function routeSubscriber(): object {
    return new class extends DevelSwitchRouteSubscriber {

      /**
       * Exposes the protected route alter method for unit tests.
       */
      public function alter(RouteCollection $collection): void {
        $this->alterRoutes($collection);
      }

    };
  }

  /**
   * Builds an access check whose storage resolves one named target.
   */
  private function accessCheckFor(UserInterface $account, string $name): AccessResultInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')
      ->with(['name' => $name])
      ->willReturn([$account]);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')
      ->with('user')
      ->willReturn($storage);

    return (new DevelSwitchTargetAccessCheck($manager))->access($name);
  }

  /**
   * Builds a user account with the target security state.
   */
  private function targetAccount(int $uid, bool $blocked): UserInterface {
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('isBlocked')->willReturn($blocked);
    $account->method('getCacheContexts')->willReturn([]);
    $account->method('getCacheTags')->willReturn(["user:$uid"]);
    $account->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    return $account;
  }

}
