<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require_once dirname(__DIR__, 3) . '/markaspot_group.module';

/**
 * Tests JSON:API filter access for service request assignees.
 */
#[Group('markaspot_group')]
final class JsonApiFieldFilterAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', new JsonApiFieldFilterAccessCacheContextsManagerStub());
    $container->set('permission_checker', new JsonApiFieldFilterAccessPermissionCheckerStub());
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Tests field_assignee filters require assignment permission.
   */
  public function testAssigneeFilterAccessRequiresAssignPermission(): void {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getTargetEntityTypeId')
      ->willReturn('node');
    $field_definition->method('getTargetBundle')
      ->willReturn('service_request');
    $field_definition->method('getName')
      ->willReturn('field_assignee');

    $assigner = $this->createMock(AccountInterface::class);
    $assigner->expects($this->once())
      ->method('hasPermission')
      ->with('assign service requests')
      ->willReturn(TRUE);

    $allowed_access = \markaspot_group_jsonapi_entity_field_filter_access(
      $field_definition,
      $assigner,
    );
    $this->assertTrue($allowed_access->isAllowed());
    $this->assertContains('user.permissions', $allowed_access->getCacheContexts());

    $unprivileged_account = $this->createMock(AccountInterface::class);
    $unprivileged_account->expects($this->once())
      ->method('hasPermission')
      ->with('assign service requests')
      ->willReturn(FALSE);

    $forbidden_access = \markaspot_group_jsonapi_entity_field_filter_access(
      $field_definition,
      $unprivileged_account,
    );
    $this->assertTrue($forbidden_access->isForbidden());
    $this->assertContains('user.permissions', $forbidden_access->getCacheContexts());

    $anonymous_access = \markaspot_group_jsonapi_entity_field_filter_access(
      $field_definition,
      new AnonymousUserSession(),
    );
    $this->assertTrue($anonymous_access->isForbidden());
    $this->assertContains('user.permissions', $anonymous_access->getCacheContexts());
  }

}

/**
 * Minimal cache context manager stub for AccessResult cacheability assertions.
 */
final class JsonApiFieldFilterAccessCacheContextsManagerStub {

  /**
   * Validates cache contexts.
   *
   * @param array<int, string> $contexts
   *   The cache contexts to validate.
   *
   * @return bool
   *   TRUE when all contexts are accepted.
   */
  public function assertValidTokens(array $contexts): bool {
    return TRUE;
  }

}

/**
 * Minimal permission checker stub for anonymous account access checks.
 */
final class JsonApiFieldFilterAccessPermissionCheckerStub {

  /**
   * Checks a permission.
   *
   * @param string $permission
   *   The permission to check.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account being checked.
   *
   * @return bool
   *   FALSE for this focused anonymous test path.
   */
  public function hasPermission(string $permission, AccountInterface $account): bool {
    return FALSE;
  }

}
