<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_tenant_admin\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which node bundles get the tenant-scoped status field access.
 */
#[Group('markaspot_tenant_admin')]
final class TenantAdminStatusFieldAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../markaspot_tenant_admin.module';
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
  }

  /**
   * Creating a node: allowed for supported bundles with the create permission.
   */
  #[DataProvider('newNodeCases')]
  public function testNewNodeStatusAccess(string $bundle, string $field, array $roles, array $permissions, bool $allowed, bool $neutral): void {
    $result = markaspot_tenant_admin_entity_field_access('edit', $this->field($field, $bundle), $this->account($roles, $permissions));
    $this->assertSame($allowed, $result->isAllowed());
    $this->assertSame($neutral, $result->isNeutral());
  }

  /**
   * Bundle, field, roles, permissions, expected allowed, expected neutral.
   */
  public static function newNodeCases(): iterable {
    // Dashboard text templates: the template editor writes status.
    yield 'boilerplate by tenant admin' => [
      'boilerplate',
      'status',
      ['tenant_admin'],
      ['create boilerplate content'],
      TRUE,
      FALSE,
    ];
    yield 'boilerplate without create permission' => [
      'boilerplate',
      'status',
      ['tenant_admin'],
      [],
      FALSE,
      TRUE,
    ];
    yield 'page by moderator' => [
      'page',
      'status',
      ['moderator'],
      ['create page content'],
      TRUE,
      FALSE,
    ];
    yield 'unsupported bundle' => [
      'article',
      'status',
      ['tenant_admin'],
      ['create article content'],
      FALSE,
      TRUE,
    ];
    yield 'other field' => [
      'boilerplate',
      'title',
      ['tenant_admin'],
      ['create boilerplate content'],
      FALSE,
      TRUE,
    ];
    yield 'role without publish rights' => [
      'boilerplate',
      'status',
      ['authenticated'],
      ['create boilerplate content'],
      FALSE,
      TRUE,
    ];
  }

  /**
   * The shared status base field names no bundle; the entity provides it.
   *
   * Boilerplate ships no base field override for status, so the definition
   * the hook receives is the base field with target bundle NULL.
   */
  #[DataProvider('baseFieldCases')]
  public function testBaseFieldUsesTheEntityBundle(string $bundle, bool $allowed): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('bundle')->willReturn($bundle);
    $entity->method('isNew')->willReturn(TRUE);
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($entity);
    $account = $this->account(['tenant_admin'], ["create $bundle content"]);

    $result = markaspot_tenant_admin_entity_field_access('edit', $this->field('status', NULL), $account, $items);
    $this->assertSame($allowed, $result->isAllowed());
  }

  /**
   * Entity bundle, expected allowed.
   */
  public static function baseFieldCases(): iterable {
    yield 'boilerplate' => ['boilerplate', TRUE];
    yield 'service_request' => ['service_request', TRUE];
    yield 'unsupported bundle' => ['article', FALSE];
  }

  /**
   * Without items a base field definition cannot be attributed to a bundle.
   */
  public function testBaseFieldWithoutItemsStaysNeutral(): void {
    $result = markaspot_tenant_admin_entity_field_access('edit', $this->field('status', NULL), $this->account(['tenant_admin'], ['create boilerplate content']));
    $this->assertTrue($result->isNeutral());
  }

  /**
   * Builds a node field definition mock.
   */
  private function field(string $name, ?string $bundle): FieldDefinitionInterface {
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getName')->willReturn($name);
    $field->method('getTargetEntityTypeId')->willReturn('node');
    $field->method('getTargetBundle')->willReturn($bundle);
    return $field;
  }

  /**
   * Builds an account mock.
   */
  private function account(array $roles, array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn($roles);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $account;
  }

}
