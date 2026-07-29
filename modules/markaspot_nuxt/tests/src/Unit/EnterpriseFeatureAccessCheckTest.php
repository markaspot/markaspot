<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Access\EnterpriseFeatureAccessCheck;
use Drupal\markaspot_nuxt\Service\EnterpriseFeatureGate;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Routing\Route;

/**
 * Tests the EnterpriseFeatureAccessCheck route access service.
 *
 * These tests exercise platform detection, installation-jurisdiction
 * resolution (default group, then root), and the self-service tier decision.
 *
 * @group markaspot_nuxt
 * @coversDefaultClass \Drupal\markaspot_nuxt\Access\EnterpriseFeatureAccessCheck
 */
class EnterpriseFeatureAccessCheckTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // AccessResult cache-context validation needs a minimal container.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);

    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * Builds an access check wired to a stubbed default/root jurisdiction.
   *
   * The "first published jurisdiction" query resolves to $defaultGroup;
   * an optional hierarchy resolver redirects it to $rootGroup.
   */
  protected function buildCheck(
    ?GroupInterface $defaultGroup,
    ?GroupInterface $rootGroup = NULL,
    bool $selfService = TRUE,
  ): EnterpriseFeatureAccessCheck {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($defaultGroup ? [(int) $defaultGroup->id()] : []);

    $groupsById = [];
    if ($defaultGroup) {
      $groupsById[(int) $defaultGroup->id()] = $defaultGroup;
    }
    if ($rootGroup) {
      $groupsById[(int) $rootGroup->id()] = $rootGroup;
    }

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('getQuery')->willReturn($query);
    $groupStorage->method('load')
      ->willReturnCallback(static fn($id) => $groupsById[(int) $id] ?? NULL);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('group')->willReturn($groupStorage);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('jurisdiction_group_type')->willReturn('jur');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('markaspot_open311.settings')->willReturn($config);

    $hierarchyResolver = NULL;
    if ($rootGroup && $defaultGroup) {
      $hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
      $hierarchyResolver->method('getRootJurisdictionId')
        ->with((int) $defaultGroup->id())
        ->willReturn((int) $rootGroup->id());
    }

    $featureScopeResolver = $this->createMock(FeatureScopeResolver::class);
    $featureScopeResolver->method('isSelfServicePlatform')
      ->willReturn($selfService);

    return new EnterpriseFeatureAccessCheck(
      new EnterpriseFeatureGate($featureScopeResolver),
      $entityTypeManager,
      $configFactory,
      $hierarchyResolver,
    );
  }

  /**
   * Creates a group mock with a controllable id and field_tier state.
   */
  protected function mockGroup(int $id, bool $hasTierField, ?string $tierValue = NULL): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('getCacheTags')->willReturn(['group:' . $id]);
    $group->method('getCacheMaxAge')->willReturn(-1);
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => $name === 'field_tier' && $hasTierField);

    if ($hasTierField) {
      // @phpcs:disable Drupal.Commenting.DocComment
      $fieldItem = new class($tierValue) {

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

        public function __construct($value) {
          $this->value = $value;
          $this->empty = $value === NULL;
        }

        /**
         *
         */
        public function isEmpty(): bool {
          return $this->empty;
        }

      };
      // @phpcs:enable
      $group->method('get')->with('field_tier')->willReturn($fieldItem);
    }

    return $group;
  }

  /**
   * Builds a route with the given _enterprise_feature option.
   */
  protected function buildRoute(?string $feature): Route {
    $options = [];
    if ($feature !== NULL) {
      $options['_enterprise_feature'] = $feature;
    }
    return new Route('/test', [], [], $options);
  }

  /**
   * Missing _enterprise_feature option is a misconfiguration — fail closed.
   *
   * @covers ::check
   */
  public function testMissingFeatureOptionIsForbidden(): void {
    $check = $this->buildCheck(NULL);
    $result = $check->check($this->buildRoute(NULL), $this->createMock(AccountInterface::class));
    $this->assertFalse($result->isAllowed());
  }

  /**
   * No jurisdiction resolvable at all (fresh install) fails closed.
   *
   * @covers ::check
   */
  public function testNoJurisdictionResolvedIsForbidden(): void {
    $check = $this->buildCheck(NULL);
    $result = $check->check($this->buildRoute('mail_text_editor'), $this->createMock(AccountInterface::class));
    $this->assertFalse($result->isAllowed());
  }

  /**
   * Self-hosted is allowed with the now-universal empty tier field.
   *
   * @covers ::check
   */
  public function testSelfHostedJurisdictionIsAllowed(): void {
    $group = $this->mockGroup(1, TRUE, NULL);
    $check = $this->buildCheck($group, selfService: FALSE);
    $result = $check->check($this->buildRoute('mail_text_editor'), $this->createMock(AccountInterface::class));
    $this->assertTrue($result->isAllowed());
  }

  /**
   * Empty self-service tier is forbidden.
   *
   * @covers ::check
   */
  public function testEmptySelfServiceTierIsForbidden(): void {
    $group = $this->mockGroup(1, TRUE, NULL);
    $check = $this->buildCheck($group);
    $result = $check->check($this->buildRoute('mail_text_editor'), $this->createMock(AccountInterface::class));
    $this->assertFalse($result->isAllowed());
  }

  /**
   * A SaaS jurisdiction below the top tier is forbidden.
   *
   * @covers ::check
   */
  public function testTierGatedJurisdictionIsForbiddenBelowTier(): void {
    $group = $this->mockGroup(1, TRUE, 'pro');
    $check = $this->buildCheck($group);
    $result = $check->check($this->buildRoute('mail_text_editor'), $this->createMock(AccountInterface::class));
    $this->assertFalse($result->isAllowed());
  }

  /**
   * A SaaS jurisdiction on the top ('heart') tier is allowed.
   *
   * @covers ::check
   */
  public function testTierGatedJurisdictionIsAllowedAtTopTier(): void {
    $group = $this->mockGroup(1, TRUE, 'heart');
    $check = $this->buildCheck($group);
    $result = $check->check($this->buildRoute('mail_text_editor'), $this->createMock(AccountInterface::class));
    $this->assertTrue($result->isAllowed());
  }

  /**
   * The gate is evaluated against the ROOT jurisdiction, not a child.
   *
   * The first-resolved (lowest ID, published) jurisdiction is a child on a
   * non-enterprise tier; its root carries 'heart'. Access must follow the
   * root, mirroring how child jurisdictions inherit the service catalog.
   *
   * @covers ::check
   */
  public function testResolvesToRootJurisdictionTier(): void {
    $child = $this->mockGroup(5, TRUE, 'starter');
    $root = $this->mockGroup(1, TRUE, 'heart');
    $check = $this->buildCheck($child, $root);
    $result = $check->check($this->buildRoute('mail_text_editor'), $this->createMock(AccountInterface::class));
    $this->assertTrue($result->isAllowed());
  }

}
