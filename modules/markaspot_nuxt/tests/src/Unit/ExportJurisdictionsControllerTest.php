<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Controller\ExportJurisdictionsController;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests unpublished export scope without broadening membership permissions.
 */
#[Group('markaspot_nuxt')]
final class ExportJurisdictionsControllerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../src/Controller/ExportJurisdictionsController.php';
  }

  /**
   * Unpublished intermediate groups do not disconnect eligible descendants.
   */
  public function testScopeIntersectsExplicitMembershipsAcrossUnpublishedParents(): void {
    $controller = $this->controller(
      ['authenticated', 'tenant_admin'],
      [$this->membership(4), $this->membership(6), $this->membership(90)],
      [4 => [4, 5, 6]],
    );
    $response = $controller->getScope(new Request(['roots' => '4']));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([
      'requestedRootIds' => [4],
      'jurisdictionIds' => [4, 6],
      'taxonomyRoots' => [
        ['jurisdictionId' => 4, 'rootId' => 4],
        ['jurisdictionId' => 6, 'rootId' => 4],
      ],
      'coversAllJurisdictions' => FALSE,
    ], json_decode($response->getContent(), TRUE));
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
  }

  /**
   * Every requested scope must be authorised, without a partial response.
   */
  public function testForeignRootRejectsTheEntireRequest(): void {
    $controller = $this->controller(['authenticated'], [$this->membership(4)], []);
    $response = $controller->getScope(new Request(['roots' => '4,90']));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertArrayNotHasKey('jurisdictionIds', json_decode($response->getContent(), TRUE));
  }

  /**
   * Global roles include unpublished groups in the completeness proof.
   */
  #[DataProvider('globalCoverageProvider')]
  public function testGlobalCoverageIncludesAllJurisdictions(array $allIds, bool $expected): void {
    $controller = $this->controller(['editorial_board'], [], [4 => [4, 5, 6]], $allIds);
    $response = $controller->getScope(new Request(['roots' => '4']));
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([4, 5, 6], $data['jurisdictionIds']);
    $this->assertSame($expected, $data['coversAllJurisdictions']);
  }

  /**
   * Full coverage cannot be inferred from a published subset.
   */
  public static function globalCoverageProvider(): array {
    return [
      'all including unpublished' => [[6, 4, 5], TRUE],
      'unrelated unpublished jurisdiction exists' => [[4, 5, 6, 90], FALSE],
      'empty catalog fails closed' => [[], FALSE],
    ];
  }

  /**
   * Staff Drupal roles alone and org-derived membership grant no scope.
   */
  public function testBasicAndOrganisationMembershipsDoNotGrantExportScope(): void {
    foreach ([
      [],
      [$this->membership(4, 'jur-member')],
      [$this->membership(4, 'jur-org_member')],
      [$this->membership(4, 'jur-admin', 'org')],
    ] as $memberships) {
      $controller = $this->controller(['authenticated', 'moderator', 'tenant_admin'], $memberships, []);
      $this->assertSame(403, $controller->getScope(new Request(['roots' => '4']))->getStatusCode());
    }
  }

  /**
   * An invalid or cyclic hierarchy must not return a successful partial CSV.
   */
  public function testInvalidHierarchyFailsClosed(): void {
    $controller = $this->controller(['administrator'], [], [4 => []]);
    $this->assertSame(503, $controller->getScope(new Request(['roots' => '4']))->getStatusCode());
  }

  /**
   * IDs are deduplicated and ordered without changing selected roots.
   */
  public function testMultipleRootsAreCanonicalized(): void {
    $controller = $this->controller(['administrator'], [], [4 => [4, 5], 9 => [9]], [4, 5, 9]);
    $response = $controller->getScope(new Request(['roots' => '9,4,9']));
    $this->assertSame([
      'requestedRootIds' => [4, 9],
      'jurisdictionIds' => [4, 5, 9],
      'taxonomyRoots' => [
        ['jurisdictionId' => 4, 'rootId' => 4],
        ['jurisdictionId' => 5, 'rootId' => 4],
        ['jurisdictionId' => 9, 'rootId' => 9],
      ],
      'coversAllJurisdictions' => TRUE,
    ], json_decode($response->getContent(), TRUE));
  }

  /**
   * The uid 1 normalization used by the CSV route is preserved server-side.
   */
  public function testSuperuserDoesNotRequireAnAssignedAdministratorRole(): void {
    $controller = $this->controller(['authenticated'], [], [4 => [4]], [4], uid: 1);
    $response = $controller->getScope(new Request(['roots' => '4']));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue(json_decode($response->getContent(), TRUE)['coversAllJurisdictions']);
  }

  /**
   * Configured legacy bundles and roles match the canonical auth payload.
   */
  public function testConfiguredJurisdictionBundleAndRoles(): void {
    $controller = $this->controller(
      ['authenticated'],
      [$this->membership(4, 'municipality-moderator', 'municipality')],
      [4 => [4]],
      groupType: 'municipality',
    );
    $response = $controller->getScope(new Request(['roots' => '4']));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([4], json_decode($response->getContent(), TRUE)['jurisdictionIds']);
  }

  /**
   * A truncated resolver result cannot silently omit deeper descendants.
   */
  public function testOmittedChildFailsClosed(): void {
    $controller = $this->controller(['administrator'], [], [4 => [4, 5]], [4, 5, 6], omittedChildren: [6]);
    $this->assertSame(503, $controller->getScope(new Request(['roots' => '4']))->getStatusCode());
  }

  /**
   * A hidden child inherits taxonomy metadata without inheriting report scope.
   */
  public function testInheritedTaxonomyDoesNotBroadenReportScope(): void {
    $controller = $this->controller(['authenticated'], [$this->membership(9)], [9 => [9]], taxonomyRootIds: [9 => 7]);
    $response = $controller->getScope(new Request(['roots' => '9']));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([
      'requestedRootIds' => [9],
      'jurisdictionIds' => [9],
      'taxonomyRoots' => [['jurisdictionId' => 9, 'rootId' => 7]],
      'coversAllJurisdictions' => FALSE,
    ], json_decode($response->getContent(), TRUE));
  }

  /**
   * Missing taxonomy ancestry cannot silently omit inherited export columns.
   */
  public function testInvalidTaxonomyRootFailsClosed(): void {
    $controller = $this->controller(['authenticated'], [$this->membership(9)], [9 => [9]], taxonomyRootIds: [9 => NULL]);
    $this->assertSame(503, $controller->getScope(new Request(['roots' => '9']))->getStatusCode());
  }

  /**
   * Anonymous callers cannot obtain even a syntactically valid scope.
   */
  public function testAnonymousDenied(): void {
    $controller = $this->controller([], [], [], NULL, FALSE);
    $this->assertSame(403, $controller->getScope(new Request(['roots' => '4']))->getStatusCode());
  }

  /**
   * Malformed and unbounded input is rejected before hierarchy traversal.
   */
  #[DataProvider('invalidRootsProvider')]
  public function testInvalidRoots(mixed $roots): void {
    $controller = $this->controller(['administrator'], [], []);
    $this->assertSame(400, $controller->getScope(new Request(['roots' => $roots]))->getStatusCode());
  }

  /**
   * Invalid query values.
   */
  public static function invalidRootsProvider(): array {
    return [
      'missing' => [NULL],
      'array' => [['4']],
      'zero' => ['0'],
      'negative' => ['-4'],
      'fraction' => ['4.5'],
      'trailing separator' => ['4,'],
      'overflow' => [str_repeat('9', 30)],
      'too many roots' => [implode(',', range(1, 101))],
    ];
  }

  /**
   * Creates a controller with exact synthetic membership and hierarchy data.
   */
  private function controller(array $roles, array $memberships, array $scopes, ?array $allIds = NULL, bool $authenticated = TRUE, string $groupType = 'jur', int $uid = 2, array $omittedChildren = [], array $taxonomyRootIds = []): ExportJurisdictionsController {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn($authenticated);
    $account->method('getRoles')->willReturn($roles);
    $account->method('id')->willReturn((string) $uid);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(fn($id) => isset($scopes[$id]) ? $this->jurisdiction((int) $id, $groupType) : NULL);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $completeness_query = FALSE;
    $query->method('condition')->willReturnCallback(static function ($field, $value, $operator = NULL) use ($query, &$completeness_query) {
      $completeness_query = $completeness_query || $operator === 'NOT IN';
      return $query;
    });
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturnCallback(static function () use (&$completeness_query, $allIds, $omittedChildren) {
      $result = $completeness_query ? $omittedChildren : ($allIds ?? []);
      $completeness_query = FALSE;
      return $result;
    });
    $storage->method('getQuery')->willReturn($query);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('group')->willReturn($storage);
    $hierarchy = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy->expects($scopes === [] ? $this->never() : $this->any())
      ->method('getScopeJurisdictionIds')->willReturnCallback(static fn($id) => $scopes[$id] ?? []);
    $hierarchy->method('getRootJurisdictionId')->willReturnCallback(static function ($id) use ($scopes, $taxonomyRootIds) {
      if (array_key_exists($id, $taxonomyRootIds)) {
        return $taxonomyRootIds[$id];
      }
      foreach ($scopes as $root => $descendants) {
        if (in_array($id, $descendants, TRUE)) {
          return $root;
        }
      }
      return NULL;
    });
    $controller = $this->getMockBuilder(ExportJurisdictionsController::class)
      ->setConstructorArgs([$manager, $hierarchy, $account, $this->getConfigFactoryStub([
        'markaspot_open311.settings' => ['jurisdiction_group_type' => $groupType],
      ])])
      ->onlyMethods(['loadMemberships'])->getMock();
    $controller->method('loadMemberships')->willReturn($memberships);
    return $controller;
  }

  /**
   * Creates a synthetic group without allowing a publication-status gate.
   */
  private function jurisdiction(int $id, string $bundle = 'jur'): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('bundle')->willReturn($bundle);
    $group->method('hasField')->with('field_parent_jurisdiction')->willReturn(TRUE);
    $group->expects($this->never())->method('isPublished');
    return $group;
  }

  /**
   * Creates one explicit role membership.
   */
  private function membership(int $id, string $roleId = 'jur-tenant_admin', string $bundle = 'jur'): GroupMembershipInterface {
    $role = $this->createMock(GroupRoleInterface::class);
    $role->method('id')->willReturn($roleId);
    $membership = $this->createMock(GroupMembershipInterface::class);
    $membership->method('getGroup')->willReturn($this->jurisdiction($id, $bundle));
    $membership->method('getRoles')->willReturn([$role]);
    return $membership;
  }

}
