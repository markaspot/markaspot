<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Tests configured jurisdiction type resolution.
 *
 * @group markaspot_group
 */
class JurisdictionIdResolverTraitTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn('jurisdiction');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($config);

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    \Drupal::setContainer($container);
  }

  /**
   * Tests slug lookups use markaspot_open311 jurisdiction_group_type.
   */
  public function testSlugLookupUsesConfiguredJurisdictionGroupType(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('42');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadByProperties')
      ->with([
        'type' => 'jurisdiction',
        'field_slug' => 'amsterdam',
        'status' => 1,
      ])
      ->willReturn([$group]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($storage);

    $resolver = new class($entityTypeManager) {
      use JurisdictionIdResolverTrait;

      /**
       * Constructs the test resolver.
       */
      public function __construct(
        protected EntityTypeManagerInterface $entityTypeManager,
      ) {}

      /**
       * Exposes the protected resolver for testing.
       */
      public function resolve(string|int|null $value, ?string $groupType = NULL): ?int {
        return $this->resolveJurisdictionId($value, $groupType);
      }

    };

    $this->assertSame(42, $resolver->resolve('amsterdam'));
  }

  /**
   * Tests explicit group type overrides the configured default.
   */
  public function testExplicitGroupTypeOverridesConfiguredDefault(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('7');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadByProperties')
      ->with([
        'type' => 'org',
        'field_slug' => 'roads',
        'status' => 1,
      ])
      ->willReturn([$group]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($storage);

    $resolver = $this->createResolver($entityTypeManager);

    $this->assertSame(7, $resolver->resolve('roads', 'org'));
  }

  /**
   * Tests numeric IDs resolve when the group exists with configured type.
   */
  public function testNumericIdOfExistingJurisdictionGroupResolves(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('42');
    $group->method('bundle')->willReturn('jurisdiction');
    $group->method('isPublished')->willReturn(TRUE);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with(42)
      ->willReturn($group);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($storage);

    $resolver = $this->createResolver($entityTypeManager);

    $this->assertSame(42, $resolver->resolve('42'));
  }

  /**
   * Tests numeric IDs return NULL when the group does not exist.
   */
  public function testNumericIdOfMissingGroupReturnsNull(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with(99)
      ->willReturn(NULL);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($storage);

    $resolver = $this->createResolver($entityTypeManager);

    $this->assertNull($resolver->resolve(99));
  }

  /**
   * Tests numeric zero returns NULL without a storage lookup.
   */
  public function testNumericZeroReturnsNull(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())
      ->method('getStorage');

    $resolver = $this->createResolver($entityTypeManager);

    $this->assertNull($resolver->resolve(0));
    $this->assertNull($resolver->resolve('0'));
  }

  /**
   * Tests decimal numeric strings are not cast to IDs.
   */
  public function testDecimalNumericStringReturnsNull(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())
      ->method('getStorage');

    $resolver = $this->createResolver($entityTypeManager);

    $this->assertNull($resolver->resolve('42.9'));
  }

  /**
   * Tests numeric IDs return NULL when the group has a different type.
   */
  public function testNumericIdOfDifferentGroupTypeReturnsNull(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('org');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with(7)
      ->willReturn($group);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($storage);

    $resolver = $this->createResolver($entityTypeManager);

    $this->assertNull($resolver->resolve(7));
  }

  /**
   * Tests numeric IDs return NULL when the jurisdiction group is inactive.
   */
  public function testNumericIdOfInactiveJurisdictionGroupReturnsNull(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jurisdiction');
    $group->method('isPublished')->willReturn(FALSE);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with(8)
      ->willReturn($group);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($storage);

    $resolver = $this->createResolver($entityTypeManager);

    $this->assertNull($resolver->resolve(8));
  }

  /**
   * Tests configured jurisdiction group checks.
   */
  public function testIsJurisdictionGroupUsesConfiguredType(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jurisdiction');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $resolver = $this->createResolver($entityTypeManager);

    $this->assertTrue($resolver->isConfiguredJurisdictionGroup($group));
  }

  /**
   * Tests configured jurisdiction role checks and canonicalization.
   */
  public function testJurisdictionRoleHelpersUseConfiguredType(): void {
    $role = new class {

      /**
       * Gets the role ID.
       */
      public function id(): string {
        return 'jurisdiction-tenant_admin';
      }

    };

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $resolver = $this->createResolver($entityTypeManager);

    $this->assertTrue($resolver->hasConfiguredJurisdictionRole($role, 'tenant_admin'));
    $this->assertSame(
      'jur-tenant_admin',
      $resolver->canonicalizeRoleId('jurisdiction-tenant_admin')
    );
  }

  /**
   * Creates a test resolver exposing the protected trait methods.
   */
  private function createResolver(EntityTypeManagerInterface $entityTypeManager): object {
    return new class($entityTypeManager) {
      use JurisdictionIdResolverTrait;

      /**
       * Constructs the test resolver.
       */
      public function __construct(
        protected EntityTypeManagerInterface $entityTypeManager,
      ) {}

      /**
       * Exposes the protected resolver for testing.
       */
      public function resolve(string|int|null $value, ?string $groupType = NULL): ?int {
        return $this->resolveJurisdictionId($value, $groupType);
      }

      /**
       * Exposes the protected group check for testing.
       */
      public function isConfiguredJurisdictionGroup(mixed $group): bool {
        return $this->isJurisdictionGroup($group);
      }

      /**
       * Exposes the protected role check for testing.
       */
      public function hasConfiguredJurisdictionRole(mixed $role, string $roleName): bool {
        return $this->isJurisdictionRole($role, $roleName);
      }

      /**
       * Exposes the protected role canonicalizer for testing.
       */
      public function canonicalizeRoleId(string $roleId): string {
        return $this->canonicalizeJurisdictionRoleId($roleId);
      }

    };
  }

}
