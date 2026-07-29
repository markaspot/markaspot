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
   * Tests the unified tier rule.
   *
   * @dataProvider tierProvider
   * @covers ::canUseTierGatedFeatures
   */
  public function testTierRule(?string $tier, string $mode, bool $expected): void {
    new Settings(['markaspot_operating_mode' => $mode]);
    $fields = ['field_nuxt_config' => '{}'];
    if ($tier !== NULL) {
      $fields['field_tier'] = $tier;
    }
    elseif ($mode === 'saas') {
      $fields['field_tier'] = NULL;
    }
    $root = $this->createGroup(1, $fields);

    $this->assertSame($expected, $this->createResolver($root)->canUseTierGatedFeatures($root));
  }

  /**
   * Provides tier gate cases.
   */
  public static function tierProvider(): array {
    return [
      'pro SaaS' => ['pro', 'saas', TRUE],
      'heart SaaS' => ['heart', 'saas', TRUE],
      'starter SaaS' => ['starter', 'saas', FALSE],
      'empty SaaS' => [NULL, 'saas', FALSE],
      'empty self hosted' => [NULL, 'self_hosted', TRUE],
    ];
  }

  /**
   * Tests that the fastmap module marks a SaaS install for tierless roots.
   *
   * A misconfigured SaaS container (operating mode not set) must not fall
   * back to "everything allowed" for tierless demo workspaces.
   *
   * @covers ::canUseTierGatedFeatures
   */
  public function testTierlessRootWithFastmapStaysGated(): void {
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $root = $this->createGroup(1, ['field_nuxt_config' => '{}']);

    $this->assertFalse($this->createResolver($root, [], TRUE)->canUseTierGatedFeatures($root));
    $this->assertTrue($this->createResolver($root, [], FALSE)->canUseTierGatedFeatures($root));
  }

  /**
   * Tests operating-mode aware premium defaults and root inheritance.
   *
   * The default is fail-closed on SaaS: explicit saas mode AND the
   * misconfigured-container case (mode unset while markaspot_fastmap is
   * installed) both resolve to FALSE, mirroring canUseTierGatedFeatures().
   * A stored root opt-in wins in every mode and is inherited by children.
   *
   * @covers ::resolveEffectiveFeatures
   */
  public function testOrganisationAndFacilityDefaultsAndInheritance(): void {
    $bareRoot = $this->createGroup(1, ['field_nuxt_config' => '{}']);
    $child = $this->createGroup(2, ['field_nuxt_config' => '{}']);

    // Self-hosted / enterprise without the fastmap module: enabled by default.
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $features = $this->createResolver($bareRoot, [], FALSE)->resolveEffectiveFeatures($child);
    $this->assertTrue($features['organisations']);
    $this->assertTrue($features['facilities']);

    // Explicit SaaS mode: opt-in, so absent resolves to FALSE.
    new Settings(['markaspot_operating_mode' => 'saas']);
    $features = $this->createResolver($bareRoot, [], FALSE)->resolveEffectiveFeatures($child);
    $this->assertFalse($features['organisations']);
    $this->assertFalse($features['facilities']);

    // Misconfigured SaaS container: mode unset but fastmap installed must
    // fail closed instead of enabling the feature for every workspace.
    new Settings([]);
    $features = $this->createResolver($bareRoot, [], TRUE)->resolveEffectiveFeatures($child);
    $this->assertFalse($features['organisations']);
    $this->assertFalse($features['facilities']);

    // A premium workspace's stored root opt-in wins on SaaS and is
    // inherited by the child workspace.
    new Settings(['markaspot_operating_mode' => 'saas']);
    $optedInRoot = $this->createGroup(1, [
      'field_nuxt_config' => json_encode(['features' => [
        'organisations' => TRUE,
        'facilities' => TRUE,
      ]]),
    ]);
    $features = $this->createResolver($optedInRoot, [], TRUE)->resolveEffectiveFeatures($child);
    $this->assertTrue($features['organisations']);
    $this->assertTrue($features['facilities']);

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
  private function createResolver(GroupInterface $root, array $platformFeatures = [], ?bool $fastmapInstalled = NULL, array $orgGroupIds = []): FeatureScopeResolver {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($orgGroupIds);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($root);
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
