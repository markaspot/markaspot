<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestIndexResource;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

require_once dirname(dirname(__DIR__, 3)) . '/markaspot_group/src/Service/WorkspaceVisibilityInterface.php';
require_once dirname(__DIR__, 3) . '/src/Plugin/rest/resource/GeoreportRequestIndexResource.php';

/**
 * Tests platform-aware anonymous workspace list scoping.
 *
 * @group markaspot_open311
 * @coversDefaultClass \Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestIndexResource
 */
final class GeoreportRequestIndexVisibilityScopeTest extends UnitTestCase {

  /**
   * Municipal regression: all-public self-hosted lists remain unfiltered.
   *
   * Existing municipal data may predate jurisdiction assignments. Adding any
   * positive field_jurisdiction condition would hide those requests.
   *
   * @covers ::applyAnonymousWorkspaceReadScope
   */
  public function testSelfHostedMunicipalListWithoutRestrictionsAddsNoJurisdictionCondition(): void {
    $resource = $this->resource(FALSE, [10], []);
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->never())->method('condition');
    $query->expects($this->never())->method('addTag');
    $query->expects($this->never())->method('addMetaData');

    $this->applyAnonymousScope($resource, $query);
  }

  /**
   * A self-hosted stack without jurisdiction groups stays unfiltered.
   *
   * @covers ::applyAnonymousWorkspaceReadScope
   */
  public function testSelfHostedListWithoutJurisdictionGroupsRemainsUnrestricted(): void {
    $resource = $this->resource(FALSE, [], []);
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->never())->method('condition');
    $query->expects($this->never())->method('addTag');
    $query->expects($this->never())->method('addMetaData');

    $this->applyAnonymousScope($resource, $query);
  }

  /**
   * Self-hosted restrictions exclude only assigned restricted requests.
   *
   * The NID anti-subquery preserves unassigned legacy requests and public
   * jurisdiction 11 while excluding any request assigned to jurisdiction 10.
   *
   * @covers ::applyAnonymousWorkspaceReadScope
   */
  public function testSelfHostedRestrictionKeepsPublicAndUnassignedRequestsVisible(): void {
    $resource = $this->resource(FALSE, [10, 11], [10]);
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->never())->method('condition');
    $query->expects($this->once())
      ->method('addTag')
      ->with('markaspot_open311_workspace_visibility')
      ->willReturnSelf();
    $query->expects($this->once())
      ->method('addMetaData')
      ->with('markaspot_open311_restricted_jurisdiction_ids', [10])
      ->willReturnSelf();

    $this->applyAnonymousScope($resource, $query);
  }

  /**
   * Self-service retains its strict positive jurisdiction scope.
   *
   * @covers ::applyAnonymousWorkspaceReadScope
   */
  public function testSelfServiceListRetainsStrictPositiveScope(): void {
    $resource = $this->resource(TRUE, [10, 11], [10]);
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('field_jurisdiction', [11], 'IN')
      ->willReturnSelf();
    $query->expects($this->once())
      ->method('addTag')
      ->with('markaspot_open311_workspace_visibility')
      ->willReturnSelf();
    $query->expects($this->once())
      ->method('addMetaData')
      ->with('markaspot_open311_restricted_jurisdiction_ids', [10])
      ->willReturnSelf();

    $this->applyAnonymousScope($resource, $query);
  }

  /**
   * Explicit restricted claims remain unreadable off-platform.
   *
   * Without a claim, municipal list behavior remains unscoped.
   *
   * @covers ::anonymousJurisdictionClaimIsUnreadable
   */
  public function testSelfHostedOnlyExplicitRestrictedClaimIsUnreadable(): void {
    $resource = $this->resource(FALSE, [10], [10], [10]);

    $this->assertFalse($this->claimIsUnreadable($resource, [], NULL));
    $this->assertTrue($this->claimIsUnreadable(
      $resource,
      ['jurisdiction_id' => 10],
      10,
    ));
  }

  /**
   * API-key claims use anonymous visibility without revealing restrictions.
   *
   * @covers ::anonymousJurisdictionClaimIsUnreadable
   */
  public function testApiKeyRestrictedClaimIsUnreadable(): void {
    $resource = $this->resource(
      FALSE,
      [10],
      [10],
      [10],
      FALSE,
      TRUE,
    );

    $this->assertTrue($this->claimIsUnreadable(
      $resource,
      ['jurisdiction_id' => 10],
      10,
    ));
  }

  /**
   * API-key invalid claims use the anonymous empty-result behavior.
   *
   * @covers ::applyInvalidJurisdictionClaimScope
   */
  public function testApiKeyInvalidClaimIsScopedToEmpty(): void {
    $resource = $this->resource(FALSE, [], [], [], FALSE, TRUE);
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('nid', [0], 'IN')
      ->willReturnSelf();

    $this->applyInvalidClaimScope(
      $resource,
      $query,
      ['jurisdiction_id' => 999],
      NULL,
    );
  }

  /**
   * API-key reads reuse the multi-value-safe restricted target exclusion.
   *
   * @covers ::applyAnonymousWorkspaceReadScope
   */
  public function testApiKeyReadAddsRestrictedWorkspaceAntiSubquery(): void {
    $resource = $this->resource(TRUE, [10, 11], [10], [], FALSE, TRUE);
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->never())->method('condition');
    $query->expects($this->once())
      ->method('addTag')
      ->with('markaspot_open311_workspace_visibility')
      ->willReturnSelf();
    $query->expects($this->once())
      ->method('addMetaData')
      ->with('markaspot_open311_restricted_jurisdiction_ids', [10])
      ->willReturnSelf();

    $this->applyAnonymousScope($resource, $query);
  }

  /**
   * Builds a resource with deterministic platform and visibility behavior.
   *
   * @param bool $selfService
   *   Whether the shared self-service platform is active.
   * @param int[] $jurisdictionIds
   *   IDs returned by the jurisdiction group query.
   * @param int[] $restrictedJurisdictionIds
   *   Explicitly restricted jurisdiction IDs.
   * @param int[] $loadableJurisdictionIds
   *   IDs that load as configured jurisdiction groups.
   * @param bool $anonymous
   *   Whether the current account is anonymous.
   * @param bool $apiKey
   *   Whether the request carries an API key.
   * @param bool $configuredApiKey
   *   Whether api_key is a configured query carrier.
   */
  private function resource(
    bool $selfService,
    array $jurisdictionIds,
    array $restrictedJurisdictionIds,
    array $loadableJurisdictionIds = [],
    bool $anonymous = TRUE,
    bool $apiKey = FALSE,
    bool $configuredApiKey = TRUE,
  ): GeoreportRequestIndexResource {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAnonymous')->willReturn($anonymous);

    $groupQuery = $this->createMock(QueryInterface::class);
    $groupQuery->method('accessCheck')->willReturnSelf();
    $groupQuery->method('condition')->willReturnSelf();
    $groupQuery->method('execute')->willReturn($jurisdictionIds);

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    if ($selfService && $anonymous) {
      $groupStorage->expects($this->once())
        ->method('getQuery')
        ->willReturn($groupQuery);
    }
    else {
      $groupStorage->expects($this->never())->method('getQuery');
    }
    $groupStorage->method('load')->willReturnCallback(
      function (int $id) use ($loadableJurisdictionIds): ?GroupInterface {
        if (!in_array($id, $loadableJurisdictionIds, TRUE)) {
          return NULL;
        }
        $group = $this->createMock(GroupInterface::class);
        $group->method('bundle')->willReturn('jur');
        return $group;
      },
    );

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    $visibility = $this->createMock(WorkspaceVisibilityInterface::class);
    $visibility->method('getRestrictedJurisdictionIds')
      ->willReturn($restrictedJurisdictionIds);
    $visibility->method('canAnonymousView')->willReturnCallback(
      static fn(int $id): bool => !in_array($id, $restrictedJurisdictionIds, TRUE),
    );

    $scopeResolver = $this->createMock(FeatureScopeResolver::class);
    $scopeResolver->method('isSelfServicePlatform')->willReturn($selfService);

    $config = $this->createMock(Config::class);
    $config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn('jur');

    $requestStack = new RequestStack();
    $requestStack->push(new Request($apiKey ? ['api_key' => 'test-key'] : []));
    $apiKeyConfig = $this->createMock(Config::class);
    $apiKeyConfig->method('get')->willReturnCallback(
      static fn(string $name): string => $name === 'api_key_get_parameter_name'
        && $configuredApiKey
          ? 'api_key'
          : '',
    );

    $reflection = new \ReflectionClass(GeoreportRequestIndexResource::class);
    $resource = $reflection->newInstanceWithoutConstructor();
    $this->setProperty($resource, 'currentUser', $account);
    $this->setProperty($resource, 'entityTypeManager', $entityTypeManager);
    $this->setProperty($resource, 'workspaceVisibility', $visibility);
    $this->setProperty($resource, 'featureScopeResolver', $scopeResolver);
    $this->setProperty($resource, 'config', $config);
    $this->setProperty($resource, 'requestStack', $requestStack);
    $this->setProperty($resource, 'apiKeyAuthConfig', $apiKeyConfig);
    return $resource;
  }

  /**
   * Invokes the protected anonymous list scope.
   */
  private function applyAnonymousScope(
    GeoreportRequestIndexResource $resource,
    QueryInterface $query,
  ): void {
    $method = new \ReflectionMethod($resource, 'applyAnonymousWorkspaceReadScope');
    $method->invoke($resource, $query);
  }

  /**
   * Invokes the protected anonymous claim decision.
   */
  private function claimIsUnreadable(
    GeoreportRequestIndexResource $resource,
    array $parameters,
    ?int $jurisdictionId,
  ): bool {
    $method = new \ReflectionMethod($resource, 'anonymousJurisdictionClaimIsUnreadable');
    return (bool) $method->invoke($resource, $parameters, $jurisdictionId);
  }

  /**
   * Invokes the protected invalid-claim scope.
   */
  private function applyInvalidClaimScope(
    GeoreportRequestIndexResource $resource,
    QueryInterface $query,
    array $parameters,
    ?int $jurisdictionId,
  ): void {
    $method = new \ReflectionMethod($resource, 'applyInvalidJurisdictionClaimScope');
    $method->invoke($resource, $query, $parameters, $jurisdictionId);
  }

  /**
   * Sets a protected resource property for focused unit testing.
   */
  private function setProperty(
    GeoreportRequestIndexResource $resource,
    string $name,
    object $value,
  ): void {
    $property = new \ReflectionProperty($resource, $name);
    $property->setValue($resource, $value);
  }

}
