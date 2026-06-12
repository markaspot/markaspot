<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_nuxt\Access\UserJsonApiAccessCheck;
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
   * JSON:API user GET routes receive the anonymous access gate.
   */
  public function testUserJsonApiRoutesReceiveCustomAccessGate(): void {
    $collection = new RouteCollection();
    $userRoute = new Route('/jsonapi/user/user');
    $userRoute->addDefaults(['resource_type' => 'user--user']);
    $userRoute->setMethods(['GET']);
    $collection->add('jsonapi.user--user.collection', $userRoute);

    $userIndividualRoute = new Route('/jsonapi/user/user/{entity}');
    $userIndividualRoute->addDefaults(['resource_type' => 'user--user']);
    $userIndividualRoute->setMethods(['GET']);
    $collection->add('jsonapi.user--user.individual', $userIndividualRoute);

    $userPostRoute = new Route('/jsonapi/user/user');
    $userPostRoute->addDefaults(['resource_type' => 'user--user']);
    $userPostRoute->setMethods(['POST']);
    $collection->add('jsonapi.user--user.collection.post', $userPostRoute);

    $userPatchRoute = new Route('/jsonapi/user/user/{entity}');
    $userPatchRoute->addDefaults(['resource_type' => 'user--user']);
    $userPatchRoute->setMethods(['PATCH']);
    $collection->add('jsonapi.user--user.individual.patch', $userPatchRoute);

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

    $this->assertSame(
          'markaspot_nuxt.user_jsonapi_access_check:access',
          $userRoute->getRequirement('_custom_access')
      );
    $this->assertSame(
          'markaspot_nuxt.user_jsonapi_access_check:access',
          $userIndividualRoute->getRequirement('_custom_access')
      );
    $this->assertNull($userPostRoute->getRequirement('_custom_access'));
    $this->assertNull($userPatchRoute->getRequirement('_custom_access'));
    $this->assertNull($nodeRoute->getRequirement('_custom_access'));
  }

  /**
   * Anonymous users cannot access JSON:API user routes.
   */
  public function testUserJsonApiAccessCheckDeniesAnonymous(): void {
    $checker = new UserJsonApiAccessCheck();
    $result = $checker->access($this->createAccount(TRUE));

    $this->assertTrue($result->isForbidden());
    $this->assertContains('user.roles:anonymous', $result->getCacheContexts());
  }

  /**
   * Authenticated users pass through to JSON:API entity access.
   */
  public function testUserJsonApiAccessCheckAllowsAuthenticated(): void {
    $checker = new UserJsonApiAccessCheck();
    $result = $checker->access($this->createAccount(FALSE));

    $this->assertTrue($result->isAllowed());
    $this->assertContains('user.roles:anonymous', $result->getCacheContexts());
  }

}
