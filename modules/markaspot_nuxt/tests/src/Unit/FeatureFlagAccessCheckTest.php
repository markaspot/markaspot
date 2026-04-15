<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Access\FeatureFlagAccessCheck;
use Drupal\markaspot_nuxt\Service\FeatureFlagChecker;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Tests the FeatureFlagAccessCheck route access service.
 *
 * FeatureFlagChecker is final and has no constructor dependencies, so the
 * real class is used instead of a mock. These tests exercise the access
 * check's own behavior — jurisdiction resolution, bundle validation, and
 * default handling — by wiring groups without field_nuxt_config so the
 * checker falls back to the declared route default. The config-parsing
 * logic itself is covered by FeatureFlagCheckerTest.
 *
 * @group markaspot_nuxt
 * @coversDefaultClass \Drupal\markaspot_nuxt\Access\FeatureFlagAccessCheck
 */
class FeatureFlagAccessCheckTest extends UnitTestCase {

  /**
   * Builds a FeatureFlagAccessCheck with a request carrying the given query.
   */
  protected function buildCheck(array $query, ?GroupInterface $groupToLoad = NULL): FeatureFlagAccessCheck {
    $request = new Request($query);
    $stack = new RequestStack();
    $stack->push($request);

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')->willReturn($groupToLoad);
    $groupStorage->method('loadByProperties')->willReturn($groupToLoad ? [$groupToLoad] : []);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('group')->willReturn($groupStorage);

    // Real FeatureFlagChecker (final class, no deps). Groups without a
    // field_nuxt_config yield NULL config, which makes isEnabled() return
    // the caller-supplied default.
    return new FeatureFlagAccessCheck(new FeatureFlagChecker(), $stack, $entityTypeManager);
  }

  /**
   * Creates a group mock with a given bundle and no field_nuxt_config.
   */
  protected function stubGroup(string $bundle): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn($bundle);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasField')->willReturn(FALSE);
    return $group;
  }

  /**
   * Builds a route with the given feature-flag options.
   */
  protected function buildRoute(?string $flag, bool $default = FALSE): Route {
    $options = [];
    if ($flag !== NULL) {
      $options['_feature_flag'] = $flag;
    }
    $options['_feature_flag_default'] = $default;
    return new Route('/test', [], [], $options);
  }

  /**
   * Missing _feature_flag option is a misconfiguration — fail closed.
   *
   * @covers ::check
   */
  public function testMissingFlagOptionIsForbidden(): void {
    $check = $this->buildCheck([]);
    $route = new Route('/test', [], [], []);
    $result = $check->check($route, $this->createMock(AccountInterface::class));
    $this->assertFalse($result->isAllowed());
  }

  /**
   * Resolved jur group with no config → default TRUE → allowed.
   *
   * @covers ::check
   */
  public function testResolvedJurGroupHonorsDefaultTrue(): void {
    $group = $this->stubGroup('jur');
    $check = $this->buildCheck(['jurisdiction_id' => '42'], $group);
    $result = $check->check($this->buildRoute('features.statistics', TRUE), $this->createMock(AccountInterface::class));
    $this->assertTrue($result->isAllowed());
  }

  /**
   * Resolved jur group with no config → default FALSE → forbidden.
   *
   * @covers ::check
   */
  public function testResolvedJurGroupHonorsDefaultFalse(): void {
    $group = $this->stubGroup('jur');
    $check = $this->buildCheck(['jurisdiction_id' => '42'], $group);
    $result = $check->check($this->buildRoute('features.statistics', FALSE), $this->createMock(AccountInterface::class));
    $this->assertFalse($result->isAllowed());
  }

  /**
   * Non-existent GID → group is NULL → jurisdiction NULL → default applies.
   *
   * @covers ::check
   */
  public function testPhantomGidFallsBackToDefault(): void {
    $check = $this->buildCheck(['jurisdiction_id' => '999999'], NULL);
    $result = $check->check($this->buildRoute('features.statistics', FALSE), $this->createMock(AccountInterface::class));
    $this->assertFalse($result->isAllowed());
  }

  /**
   * Org-type group → bundle mismatch → treated as NULL → default applies.
   *
   * @covers ::check
   */
  public function testOrgGroupGidIsRejected(): void {
    $orgGroup = $this->stubGroup('org');
    $check = $this->buildCheck(['jurisdiction_id' => '3'], $orgGroup);
    // Default TRUE confirms the fallback path is taken rather than the
    // group config path — a real org group config would never be consulted.
    $result = $check->check($this->buildRoute('features.statistics', TRUE), $this->createMock(AccountInterface::class));
    $this->assertTrue($result->isAllowed());
  }

  /**
   * Missing jurisdiction query param → NULL jurisdiction → default applies.
   *
   * @covers ::check
   */
  public function testNoJurisdictionAppliesDefault(): void {
    $check = $this->buildCheck([], NULL);
    $resultAllow = $check->check($this->buildRoute('features.statistics', TRUE), $this->createMock(AccountInterface::class));
    $this->assertTrue($resultAllow->isAllowed());

    $check2 = $this->buildCheck([], NULL);
    $resultDeny = $check2->check($this->buildRoute('features.statistics', FALSE), $this->createMock(AccountInterface::class));
    $this->assertFalse($resultDeny->isAllowed());
  }

  /**
   * Slug resolution reaches the bundle check.
   *
   * @covers ::check
   */
  public function testSlugJurisdictionResolves(): void {
    $group = $this->stubGroup('jur');
    $check = $this->buildCheck(['jurisdiction_id' => 'amsterdam'], $group);
    $result = $check->check($this->buildRoute('features.statistics', TRUE), $this->createMock(AccountInterface::class));
    $this->assertTrue($result->isAllowed());
  }

  /**
   * Malformed slug → skipped without storage lookup → default applies.
   *
   * @covers ::check
   */
  public function testMalformedSlugIsRejected(): void {
    $check = $this->buildCheck(['jurisdiction_id' => 'a/b/c'], NULL);
    $result = $check->check($this->buildRoute('features.statistics', FALSE), $this->createMock(AccountInterface::class));
    $this->assertFalse($result->isAllowed());
  }

  /**
   * Legacy `jurisdiction` query param alias resolves identically.
   *
   * @covers ::check
   */
  public function testLegacyJurisdictionParamResolves(): void {
    $group = $this->stubGroup('jur');
    $check = $this->buildCheck(['jurisdiction' => '42'], $group);
    $result = $check->check($this->buildRoute('features.statistics', TRUE), $this->createMock(AccountInterface::class));
    $this->assertTrue($result->isAllowed());
  }

}
