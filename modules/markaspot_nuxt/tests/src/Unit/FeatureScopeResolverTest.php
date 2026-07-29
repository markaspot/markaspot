<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\Tests\UnitTestCase;

require_once dirname(__DIR__, 4) . '/markaspot_group/src/Service/JurisdictionHierarchyResolverInterface.php';
require_once dirname(__DIR__, 3) . '/src/Service/FeatureScopeResolver.php';

/**
 * Tests effective feature scope resolution.
 *
 * @group markaspot_nuxt
 * @coversDefaultClass \Drupal\markaspot_nuxt\Service\FeatureScopeResolver
 */
final class FeatureScopeResolverTest extends UnitTestCase {

  /**
   * Tests jurisdiction, root, platform, tier, and boundary overlays.
   *
   * @covers ::resolveEffectiveFeatures
   */
  public function testScopeMergeAndBoundaryDefault(): void {
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $root = $this->createGroup(1, [
      'field_nuxt_config' => json_encode(['features' => [
        'aiProcessing' => TRUE,
        'dashboard' => FALSE,
      ]]),
      'field_tier' => 'pro',
    ]);
    $child = $this->createGroup(2, [
      'field_nuxt_config' => json_encode(['features' => [
        'aiProcessing' => FALSE,
        'photoReporting' => FALSE,
        'boundaries' => ['strictValidation' => TRUE],
      ]]),
      'field_boundary' => '{"type":"Polygon"}',
    ]);
    $resolver = $this->createResolver($root, [
      'loginLink' => FALSE,
      'passwordless' => TRUE,
      'onboardingTour' => NULL,
    ]);

    $features = $resolver->resolveEffectiveFeatures($child);

    $this->assertTrue($features['aiProcessing']);
    $this->assertFalse($features['dashboard']);
    $this->assertFalse($features['photoReporting']);
    // Regression: no stored aiAnalysis anywhere must resolve to TRUE. The
    // resolver materialises every key, and the citizen frontend treated a
    // missing key as enabled, so a FALSE default would silently disable
    // photo AI for every tenant that never touched the toggle.
    $this->assertTrue($features['aiAnalysis']);
    $this->assertFalse($features['loginLink']);
    $this->assertTrue($features['passwordless']);
    $this->assertFalse($features['onboardingTour']);
    $this->assertTrue($features['boundaries']['enabled']);
    $this->assertTrue($features['boundaries']['strictValidation']);
  }

  /**
   * Tests that an explicit boundary false wins over attached boundary data.
   *
   * @covers ::resolveEffectiveFeatures
   */
  public function testExplicitBoundaryFalseWins(): void {
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $root = $this->createGroup(1, [
      'field_nuxt_config' => json_encode(['features' => [
        'boundaries' => ['enabled' => FALSE],
      ]]),
      'field_boundary' => '{"type":"Polygon"}',
    ]);

    $features = $this->createResolver($root)->resolveEffectiveFeatures($root);

    $this->assertFalse($features['boundaries']['enabled']);
  }

  /**
   * Tests caller defaults remain distinct from explicit stored false values.
   *
   * @covers ::isEnabledEffective
   */
  public function testEffectiveReadPreservesCallerDefault(): void {
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $missing = $this->createGroup(1, ['field_nuxt_config' => '{}']);
    $explicit = $this->createGroup(1, [
      'field_nuxt_config' => json_encode(['features' => ['aiAnalysis' => FALSE]]),
    ]);

    $this->assertTrue($this->createResolver($missing)->isEnabledEffective('aiAnalysis', $missing, TRUE));
    $this->assertFalse($this->createResolver($explicit)->isEnabledEffective('aiAnalysis', $explicit, TRUE));
  }

  /**
   * Tests richer citizen feature objects retain their additive options.
   *
   * @covers ::resolveEffectiveFeatures
   */
  public function testObjectFeatureOptionsArePreserved(): void {
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $root = $this->createGroup(1, [
      'field_nuxt_config' => json_encode(['features' => [
        'formFirst' => [
          'mobileLayout' => 'fullscreen',
          'defaultTab' => 'form',
        ],
      ]]),
    ]);

    $features = $this->createResolver($root)->resolveEffectiveFeatures($root);

    $this->assertFalse($features['formFirst']['enabled']);
    $this->assertSame('fullscreen', $features['formFirst']['mobileLayout']);
    $this->assertSame('form', $features['formFirst']['defaultTab']);
  }

  /**
   * Tests tenant object options come from the root, not stale child data.
   *
   * @covers ::resolveEffectiveFeatures
   */
  public function testTenantObjectOptionsComeFromRoot(): void {
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $root = $this->createGroup(1, [
      'field_nuxt_config' => json_encode(['features' => [
        'dashboard' => ['enabled' => TRUE, 'layout' => 'root'],
      ]]),
    ]);
    $child = $this->createGroup(2, [
      'field_nuxt_config' => json_encode(['features' => [
        'dashboard' => ['enabled' => FALSE, 'layout' => 'stale-child'],
      ]]),
    ]);

    $features = $this->createResolver($root)->resolveEffectiveFeatures($child);

    $this->assertTrue($features['dashboard']['enabled']);
    $this->assertSame('root', $features['dashboard']['layout']);
  }

  /**
   * Tests per-feature tier entitlements and the aggregate compatibility API.
   *
   * @dataProvider tierProvider
   * @covers ::isTierFeatureAllowed
   * @covers ::canUseTierGatedFeatures
   * @covers ::resolveEffectiveFeatures
   */
  public function testTierRule(
    ?string $tier,
    string $mode,
    bool $expectedAll,
    bool $expectedOperations,
    bool $expectedAi,
  ): void {
    new Settings(['markaspot_operating_mode' => $mode]);
    $fields = [
      'field_nuxt_config' => json_encode(['features' => [
        'operationsDashboard' => TRUE,
        'aiProcessing' => TRUE,
      ]]),
      'field_tier' => $tier,
    ];
    $root = $this->createGroup(1, $fields);
    $resolver = $this->createResolver($root);
    $features = $resolver->resolveEffectiveFeatures($root);

    $this->assertSame(
      $expectedOperations,
      $resolver->isTierFeatureAllowed('operationsDashboard', $root),
    );
    $this->assertSame(
      $expectedAi,
      $resolver->isTierFeatureAllowed('aiProcessing', $root),
    );
    $this->assertSame($expectedAll, $resolver->canUseTierGatedFeatures($root));
    $this->assertSame($expectedOperations, $features['operationsDashboard']);
    $this->assertSame($expectedAi, $features['aiProcessing']);
    $this->assertSame(
      $expectedOperations,
      $resolver->isEnabledEffective('operationsDashboard', $root),
    );
    $this->assertSame(
      $expectedAi,
      $resolver->isEnabledEffective('aiProcessing', $root),
    );
  }

  /**
   * Provides tier gate cases.
   */
  public static function tierProvider(): array {
    return [
      'pro SaaS' => ['pro', 'saas', TRUE, TRUE, TRUE],
      'heart SaaS' => ['heart', 'saas', TRUE, TRUE, TRUE],
      'community self hosted' => ['community', 'self_hosted', FALSE, TRUE, FALSE],
      'premium self hosted' => ['premium', 'self_hosted', TRUE, TRUE, TRUE],
      'starter SaaS' => ['starter', 'saas', FALSE, FALSE, FALSE],
      'empty SaaS' => [NULL, 'saas', FALSE, FALSE, FALSE],
      'empty self hosted' => [NULL, 'self_hosted', TRUE, TRUE, TRUE],
    ];
  }

  /**
   * Tests child tiers override the root and empty siblings inherit it.
   *
   * Premium enables the complete tier-gated set. Community keeps Operations
   * Dashboard but not advanced AI, and an empty sibling inherits that split.
   *
   * @covers ::isTierFeatureAllowed
   * @covers ::canUseTierGatedFeatures
   * @covers ::resolveEffectiveFeatures
   */
  public function testChildTierWinsWithoutElevatingSibling(): void {
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $root = $this->createGroup(1, [
      'field_nuxt_config' => json_encode(['features' => [
        'operationsDashboard' => TRUE,
        'aiProcessing' => TRUE,
      ]]),
      'field_tier' => 'community',
    ]);
    $premiumChild = $this->createGroup(2, [
      'field_nuxt_config' => '{}',
      'field_tier' => 'premium',
    ]);
    $communityChild = $this->createGroup(4, [
      'field_nuxt_config' => '{}',
      'field_tier' => 'community',
    ]);
    $emptySibling = $this->createGroup(3, [
      'field_nuxt_config' => '{}',
      'field_tier' => NULL,
    ]);
    $resolver = $this->createResolver(
      $root,
      jurisdictions: [
        1 => $root,
        2 => $premiumChild,
        3 => $emptySibling,
        4 => $communityChild,
      ],
    );

    $this->assertTrue($resolver->canUseTierGatedFeatures($premiumChild));
    $this->assertFalse($resolver->canUseTierGatedFeatures($emptySibling));
    $premiumFeatures = $resolver->resolveEffectiveFeatures($premiumChild);
    $communityFeatures = $resolver->resolveEffectiveFeatures($communityChild);
    $siblingFeatures = $resolver->resolveEffectiveFeatures($emptySibling);
    $this->assertTrue($premiumFeatures['operationsDashboard']);
    $this->assertTrue($premiumFeatures['aiProcessing']);
    $this->assertTrue($communityFeatures['operationsDashboard']);
    $this->assertFalse($communityFeatures['aiProcessing']);
    $this->assertTrue($siblingFeatures['operationsDashboard']);
    $this->assertFalse($siblingFeatures['aiProcessing']);
  }

  /**
   * Tests that the fastmap module marks a SaaS install for tierless roots.
   *
   * A misconfigured SaaS container (operating mode not set) must not fall
   * back to "everything allowed" for tierless demo workspaces.
   *
   * @covers ::canUseTierGatedFeatures
   * @covers ::isTierFeatureAllowed
   */
  public function testTierlessRootWithFastmapStaysGated(): void {
    $root = $this->createGroup(1, ['field_nuxt_config' => '{}']);

    new Settings([]);
    $fastmap = $this->createResolver($root, [], TRUE);
    $this->assertFalse($fastmap->canUseTierGatedFeatures($root));
    $this->assertFalse($fastmap->isTierFeatureAllowed('operationsDashboard', $root));
    $this->assertFalse($fastmap->isTierFeatureAllowed('aiProcessing', $root));

    // The independent FastMap backstop remains fail-closed even when an
    // explicit self_hosted mode makes the platform-level gates permissive.
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $this->assertFalse($fastmap->canUseTierGatedFeatures($root));
    $this->assertFalse($fastmap->isTierFeatureAllowed('operationsDashboard', $root));
    $this->assertFalse($fastmap->isTierFeatureAllowed('aiProcessing', $root));

    $enterprise = $this->createResolver($root, [], FALSE);
    $this->assertTrue($enterprise->canUseTierGatedFeatures($root));
    $this->assertTrue($enterprise->isTierFeatureAllowed('operationsDashboard', $root));
    $this->assertTrue($enterprise->isTierFeatureAllowed('aiProcessing', $root));
  }

  /**
   * Tests the hard self-service platform gate and enterprise inheritance.
   *
   * Organisations and facilities are enterprise-stack exclusive: on the
   * shared self-service platform (explicit saas mode, or mode unset while
   * markaspot_fastmap is installed) they are forced OFF and even a stored
   * root opt-in cannot resurrect them. On enterprise stacks (explicit
   * self_hosted, or no fastmap module) they default ON and inherit to
   * children.
   *
   * @covers ::isEnabledEffective
   * @covers ::resolveEffectiveFeatures
   * @covers ::isEditable
   */
  public function testOrganisationAndFacilityDefaultsAndInheritance(): void {
    $bareRoot = $this->createGroup(1, ['field_nuxt_config' => '{}']);
    $child = $this->createGroup(2, ['field_nuxt_config' => '{}']);

    // Self-hosted / enterprise without the fastmap module: enabled by default.
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $features = $this->createResolver($bareRoot, [], FALSE)->resolveEffectiveFeatures($child);
    $this->assertTrue($features['organisations']);
    $this->assertTrue($features['facilities']);
    $this->assertTrue(
      $this->createResolver($bareRoot, [], FALSE)
        ->isEnabledEffective('features.facilities', $child)
    );

    // Explicit SaaS mode: opt-in, so absent resolves to FALSE.
    new Settings(['markaspot_operating_mode' => 'saas']);
    $features = $this->createResolver($bareRoot, [], FALSE)->resolveEffectiveFeatures($child);
    $this->assertFalse($features['organisations']);
    $this->assertFalse($features['facilities']);
    $this->assertFalse(
      $this->createResolver($bareRoot, [], FALSE)
        ->isEnabledEffective('features.facilities', $child)
    );

    // Misconfigured SaaS container: mode unset but fastmap installed must
    // fail closed instead of enabling the feature for every workspace.
    new Settings([]);
    $features = $this->createResolver($bareRoot, [], TRUE)->resolveEffectiveFeatures($child);
    $this->assertFalse($features['organisations']);
    $this->assertFalse($features['facilities']);
    $this->assertFalse(
      $this->createResolver($bareRoot, [], TRUE)
        ->isEnabledEffective('features.facilities', $child)
    );

    // Explicit self_hosted wins over module presence: a dev or enterprise
    // stack that ships the fastmap module keeps the enterprise defaults.
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $features = $this->createResolver($bareRoot, [], TRUE)->resolveEffectiveFeatures($child);
    $this->assertTrue($features['organisations']);
    $this->assertTrue($features['facilities']);

    // Hard platform gate: a stored root opt-in must NOT resurrect an
    // enterprise-only feature on the shared self-service platform, and the
    // key is not advertised as editable there.
    new Settings(['markaspot_operating_mode' => 'saas']);
    $optedInRoot = $this->createGroup(1, [
      'field_nuxt_config' => json_encode(['features' => [
        'organisations' => TRUE,
        'facilities' => TRUE,
      ]]),
    ]);
    $saasResolver = $this->createResolver($optedInRoot, [], TRUE);
    $features = $saasResolver->resolveEffectiveFeatures($child);
    $this->assertFalse($features['organisations']);
    $this->assertFalse($features['facilities']);
    $this->assertFalse(
      $this->createResolver($optedInRoot, [], TRUE)
        ->isEnabledEffective('features.facilities', $child)
    );
    $this->assertFalse($saasResolver->isEditable('organisations', $optedInRoot));
    $this->assertFalse($saasResolver->isEditable('facilities', $optedInRoot));

    // On an enterprise stack the same stored values are respected and the
    // keys stay editable on the root (tenant-scope opt-out remains possible).
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $enterpriseResolver = $this->createResolver($optedInRoot, [], TRUE);
    $features = $enterpriseResolver->resolveEffectiveFeatures($child);
    $this->assertTrue($features['organisations']);
    $this->assertTrue($features['facilities']);
    $this->assertTrue($enterpriseResolver->isEditable('facilities', $optedInRoot));

    // The frontend contract requires a scalar boolean even if stale config
    // stored the feature in the resolver's supported object form.
    $objectRoot = $this->createGroup(1, [
      'field_nuxt_config' => json_encode(['features' => [
        'facilities' => ['enabled' => TRUE],
      ]]),
    ]);
    $features = $this->createResolver($objectRoot, [], TRUE)->resolveEffectiveFeatures($child);
    $this->assertTrue($features['facilities']);
  }

  /**
   * Tests the data-driven organisation edition gate.
   *
   * @covers ::hasOrganisationFeatures
   */
  public function testHasOrganisationFeatures(): void {
    $root = $this->createGroup(1, ['field_nuxt_config' => '{}']);

    $this->assertFalse($this->createResolver($root, [], NULL, [])->hasOrganisationFeatures());
    $this->assertTrue($this->createResolver($root, [], NULL, ['2'])->hasOrganisationFeatures());
  }

  /**
   * Tests the privacyNotice tri-state platform semantics.
   *
   * @covers ::isPlatformFeatureEnabled
   * @covers ::isPlatformFeatureExplicitlyEnabled
   */
  public function testPrivacyNoticeTriState(): void {
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $root = $this->createGroup(1, ['field_nuxt_config' => '{}']);

    // Unset: the disclosure stays visible (legacy default), but there is
    // no explicit operator decision, so the GDPR coupling must stay off.
    $unset = $this->createResolver($root, ['privacyNotice' => NULL]);
    $this->assertTrue($unset->isPlatformFeatureEnabled('privacyNotice'));
    $this->assertFalse($unset->isPlatformFeatureExplicitlyEnabled('privacyNotice'));

    // Explicit TRUE drives both the disclosure and the GDPR coupling.
    $on = $this->createResolver($root, ['privacyNotice' => TRUE]);
    $this->assertTrue($on->isPlatformFeatureEnabled('privacyNotice'));
    $this->assertTrue($on->isPlatformFeatureExplicitlyEnabled('privacyNotice'));

    // Explicit FALSE switches the disclosure off everywhere.
    $off = $this->createResolver($root, ['privacyNotice' => FALSE]);
    $this->assertFalse($off->isPlatformFeatureEnabled('privacyNotice'));
    $this->assertFalse($off->isPlatformFeatureExplicitlyEnabled('privacyNotice'));
  }

  /**
   * Creates a resolver whose root lookup returns the supplied group.
   */
  private function createResolver(
    GroupInterface $root,
    array $platformFeatures = [],
    ?bool $fastmapInstalled = NULL,
    array $orgGroupIds = [],
    array $jurisdictions = [],
  ): FeatureScopeResolver {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($orgGroupIds);
    $storage = $this->createMock(EntityStorageInterface::class);
    $jurisdictions[(int) $root->id()] = $root;
    $storage->method('load')->willReturnCallback(
      static fn(int|string $id): ?GroupInterface => $jurisdictions[(int) $id] ?? NULL,
    );
    $storage->method('getQuery')->willReturn($query);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('group')->willReturn($storage);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('platform_features')->willReturn($platformFeatures);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('markaspot_nuxt.settings')->willReturn($config);
    $hierarchy = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy->method('getRootJurisdictionId')->willReturn(1);
    $moduleHandler = NULL;
    if ($fastmapInstalled !== NULL) {
      $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
      $moduleHandler->method('moduleExists')
        ->with('markaspot_fastmap')
        ->willReturn($fastmapInstalled);
    }

    return new FeatureScopeResolver($entityTypeManager, $configFactory, $hierarchy, $moduleHandler);
  }

  /**
   * Creates a jurisdiction group double with scalar field stubs.
   */
  private function createGroup(int $id, array $fields): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('isDefaultTranslation')->willReturn(TRUE);
    $group->method('hasField')->willReturnCallback(
      static fn(string $name): bool => array_key_exists($name, $fields),
    );
    $group->method('get')->willReturnCallback(static function (string $name) use ($fields) {
      return new class($fields[$name] ?? NULL) {

        /**
         * Constructs a scalar field stub.
         */
        public function __construct(public mixed $value) {}

        /**
         * Reports whether the field is empty.
         */
        public function isEmpty(): bool {
          return $this->value === NULL || $this->value === '';
        }

      };
    });
    return $group;
  }

}
