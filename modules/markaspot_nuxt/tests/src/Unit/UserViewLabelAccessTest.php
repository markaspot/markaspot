<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_nuxt\Routing\UserJsonApiRouteSubscriber;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Tests anonymous user label access hardening.
 */
#[Group('markaspot_nuxt')]
class UserViewLabelAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../markaspot_nuxt.module';

    $container = new ContainerBuilder();
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * Builds an account mock.
   */
  private function createAccount(bool $anonymous): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAnonymous')->willReturn($anonymous);
    return $account;
  }

  /**
   * Builds a field definition mock.
   */
  private function createFieldDefinition(string $type, ?string $targetType = NULL): FieldDefinitionInterface {
    $fieldDefinition = $this->createMock(FieldDefinitionInterface::class);
    $fieldDefinition->method('getType')->willReturn($type);
    $fieldDefinition->method('getSetting')
      ->with('target_type')
      ->willReturn($targetType);
    return $fieldDefinition;
  }

  /**
   * Anonymous users cannot read user labels.
   */
  public function testAnonymousViewLabelIsForbidden(): void {
    $result = markaspot_nuxt_user_access(
          $this->createMock(UserInterface::class),
          'view label',
          $this->createAccount(TRUE)
      );

    $this->assertTrue($result->isForbidden());
    $this->assertContains('user.roles:anonymous', $result->getCacheContexts());
  }

  /**
   * Authenticated users stay neutral so normal user access remains in control.
   */
  public function testAuthenticatedViewLabelStaysNeutral(): void {
    $result = markaspot_nuxt_user_access(
          $this->createMock(UserInterface::class),
          'view label',
          $this->createAccount(FALSE)
      );

    $this->assertTrue($result->isNeutral());
    $this->assertContains('user.roles:anonymous', $result->getCacheContexts());
  }

  /**
   * Other user operations stay neutral.
   */
  public function testOtherOperationsStayNeutral(): void {
    $result = markaspot_nuxt_user_access(
          $this->createMock(UserInterface::class),
          'view',
          $this->createAccount(TRUE)
      );

    $this->assertTrue($result->isNeutral());
  }

  /**
   * Anonymous users cannot view user reference fields.
   */
  public function testAnonymousUserReferenceFieldViewIsForbidden(): void {
    $result = markaspot_nuxt_entity_field_access(
          'view',
          $this->createFieldDefinition('entity_reference', 'user'),
          $this->createAccount(TRUE)
      );

    $this->assertTrue($result->isForbidden());
    $this->assertContains('user.roles:anonymous', $result->getCacheContexts());
  }

  /**
   * Authenticated user reference field access stays neutral.
   */
  public function testAuthenticatedUserReferenceFieldViewStaysNeutral(): void {
    $result = markaspot_nuxt_entity_field_access(
          'view',
          $this->createFieldDefinition('entity_reference', 'user'),
          $this->createAccount(FALSE)
      );

    $this->assertTrue($result->isNeutral());
    $this->assertContains('user.roles:anonymous', $result->getCacheContexts());
  }

  /**
   * Non-user reference fields stay neutral.
   */
  public function testNonUserReferenceFieldViewStaysNeutral(): void {
    $result = markaspot_nuxt_entity_field_access(
          'view',
          $this->createFieldDefinition('entity_reference', 'taxonomy_term'),
          $this->createAccount(TRUE)
      );

    $this->assertTrue($result->isNeutral());
  }

  /**
   * Anonymous users cannot JSON:API-filter through user reference fields.
   */
  public function testAnonymousUserReferenceJsonApiFilterAccessIsForbidden(): void {
    $result = markaspot_nuxt_jsonapi_entity_field_filter_access(
          $this->createFieldDefinition('entity_reference', 'user'),
          $this->createAccount(TRUE)
      );

    $this->assertTrue($result->isForbidden());
    $this->assertContains('user.roles:anonymous', $result->getCacheContexts());
  }

  /**
   * Every JSON:API user route requires a logged-in account.
   */
  public function testUserJsonApiRoutesRequireLogin(): void {
    $collection = new RouteCollection();
    $userRoutes = [];
    foreach ([
      'collection' => ['/jsonapi/user/user', 'GET'],
      'individual' => ['/jsonapi/user/user/{entity}', 'GET'],
      'related' => ['/jsonapi/user/user/{entity}/{related}', 'GET'],
      'relationship' => ['/jsonapi/user/user/{entity}/relationships/{related}', 'GET'],
      'collection.post' => ['/jsonapi/user/user', 'POST'],
      'individual.patch' => ['/jsonapi/user/user/{entity}', 'PATCH'],
    ] as $suffix => [$path, $method]) {
      $route = new Route($path);
      $route->addDefaults(['resource_type' => 'user--user']);
      $route->setMethods([$method]);
      $collection->add('jsonapi.user--user.' . $suffix, $route);
      $userRoutes[$suffix] = $route;
    }

    $nodeRoute = new Route('/jsonapi/node/service_request');
    $nodeRoute->addDefaults(['resource_type' => 'node--service_request']);
    $nodeRoute->setMethods(['GET']);
    $collection->add('jsonapi.node--service_request.collection', $nodeRoute);

    $subscriber = new class extends UserJsonApiRouteSubscriber {

      /**
       * Exposes the protected route alter method for unit tests.
       */
      public function alter(RouteCollection $collection): void {
        $this->alterRoutes($collection);
      }

    };
    $subscriber->alter($collection);

    foreach ($userRoutes as $suffix => $route) {
      $this->assertSame('TRUE', $route->getRequirement('_user_is_logged_in'), $suffix);
    }
    $this->assertNull($nodeRoute->getRequirement('_user_is_logged_in'));
  }

}
