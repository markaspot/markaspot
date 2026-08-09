<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\JurisdictionScopeValidator;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_open311\Exception\GeoreportException;
use Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestIndexResource;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

require_once dirname(dirname(__DIR__, 3)) . '/markaspot_group/src/Service/WorkspaceVisibilityInterface.php';
require_once dirname(dirname(__DIR__, 3)) . '/markaspot_group/src/Service/JurisdictionScopeValidator.php';
require_once dirname(__DIR__, 3) . '/src/Exception/GeoreportException.php';
require_once dirname(__DIR__, 3) . '/src/Plugin/rest/resource/GeoreportRequestIndexResource.php';

/**
 * Tests API-key read scope and enumeration protection.
 *
 * @group markaspot_open311
 * @coversDefaultClass \Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestIndexResource
 */
final class GeoreportRequestIndexApiKeyScopeTest extends UnitTestCase {

  /**
   * Multi-workspace platform lists require a claim without leaking IDs.
   *
   * @covers ::applyApiKeyJurisdictionReadScope
   */
  public function testUnscopedMultiMembershipPlatformListIsRejected(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->anything(),
        $this->callback(static fn(array $context): bool => $context['@reason'] === 'unscoped_list_read'
          && $context['@allowed'] === '11,12'),
      );
    $resource = $this->resource([11, 12], TRUE, [], $logger);
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->never())->method('condition');

    try {
      $this->applyApiKeyScope($resource, $query, []);
      $this->fail('The unscoped list read must be rejected.');
    }
    catch (GeoreportException $exception) {
      $this->assertSame(400, $exception->getCode());
      $this->assertSame('jurisdiction_id required', $exception->getMessage());
      $this->assertStringNotContainsString('11', $exception->getMessage());
      $this->assertStringNotContainsString('12', $exception->getMessage());
    }
  }

  /**
   * A single membership retains implicit list behavior.
   *
   * @covers ::applyApiKeyJurisdictionReadScope
   */
  public function testSingleMembershipListRemainsImplicitlyScoped(): void {
    $resource = $this->resource([11], TRUE);
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('field_jurisdiction', [11], 'IN')
      ->willReturnSelf();

    $this->applyApiKeyScope($resource, $query, []);
  }

  /**
   * Explicit ID lookup parameters bypass only the enumeration guard.
   *
   * @covers ::applyApiKeyJurisdictionReadScope
   * @covers ::isRequestIdLookup
   */
  public function testIdAndNidsLookupsRemainMembershipScoped(): void {
    foreach ([['id' => 'ABC-1'], ['nids' => '101,102']] as $parameters) {
      $resource = $this->resource([11, 12], TRUE);
      $query = $this->createMock(QueryInterface::class);
      $query->expects($this->once())
        ->method('condition')
        ->with('field_jurisdiction', [11, 12], 'IN')
        ->willReturnSelf();

      $this->applyApiKeyScope($resource, $query, $parameters);
    }
  }

  /**
   * Restricted hierarchy targets are removed from unclaimed key scope.
   *
   * @covers ::applyApiKeyJurisdictionReadScope
   */
  public function testScopeExpansionFiltersRestrictedJurisdictions(): void {
    $resource = $this->resource([11], TRUE, [12], NULL, [11 => [11, 12]]);
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('field_jurisdiction', [11], 'IN')
      ->willReturnSelf();

    $this->applyApiKeyScope($resource, $query, []);
  }

  /**
   * An unconfigured query alias does not downgrade a staff request.
   *
   * @covers ::currentRequestUsesApiKey
   * @covers ::applyApiKeyJurisdictionReadScope
   */
  public function testUnconfiguredApiKeyAliasDoesNotApplyKeyScope(): void {
    $resource = $this->resource([11, 12], TRUE, [], NULL, [], FALSE);
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->never())->method('condition');

    $this->applyApiKeyScope($resource, $query, []);
  }

  /**
   * Builds a resource with a deterministic API-key scope.
   *
   * @param int[] $allowed
   *   Direct jurisdiction memberships.
   * @param bool $selfService
   *   Whether this is the shared self-service platform.
   * @param int[] $restricted
   *   Restricted jurisdiction IDs.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Optional audit logger.
   * @param array<int, int[]> $hierarchyScopes
   *   Expanded hierarchy scope keyed by direct jurisdiction ID.
   * @param bool $configuredApiKey
   *   Whether api_key is a configured query carrier.
   */
  private function resource(
    array $allowed,
    bool $selfService,
    array $restricted = [],
    ?LoggerInterface $logger = NULL,
    array $hierarchyScopes = [],
    bool $configuredApiKey = TRUE,
  ): GeoreportRequestIndexResource {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn(7);
    $account->method('isAnonymous')->willReturn(FALSE);

    $validator = new class(
      $allowed,
      $this->createMock(Connection::class),
      $logger ?? new NullLogger(),
    ) extends JurisdictionScopeValidator {

      /**
       * Constructs a validator with a fixed membership list.
       */
      public function __construct(
        private readonly array $allowed,
        Connection $database,
        LoggerInterface $logger,
      ) {
        parent::__construct($database, $logger);
      }

      /**
       * {@inheritdoc}
       */
      public function getAllowedJurisdictionIds(AccountInterface $account): array {
        return $this->allowed;
      }

    };

    $hierarchy = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy->method('getScopeJurisdictionIds')
      ->willReturnCallback(
        static fn(int $id): array => $hierarchyScopes[$id] ?? [$id],
      );

    $visibility = $this->createMock(WorkspaceVisibilityInterface::class);
    $visibility->method('getRestrictedJurisdictionIds')->willReturn($restricted);

    $scopeResolver = $this->createMock(FeatureScopeResolver::class);
    $scopeResolver->method('isSelfServicePlatform')->willReturn($selfService);

    $request = new Request(['api_key' => 'test-key']);
    $requestStack = new RequestStack();
    $requestStack->push($request);
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
    $this->setProperty($resource, 'jurisdictionScopeValidator', $validator);
    $this->setProperty($resource, 'hierarchyResolver', $hierarchy);
    $this->setProperty($resource, 'workspaceVisibility', $visibility);
    $this->setProperty($resource, 'featureScopeResolver', $scopeResolver);
    $this->setProperty($resource, 'requestStack', $requestStack);
    $this->setProperty($resource, 'apiKeyAuthConfig', $apiKeyConfig);
    return $resource;
  }

  /**
   * Invokes the protected API-key read scope method.
   */
  private function applyApiKeyScope(
    GeoreportRequestIndexResource $resource,
    QueryInterface $query,
    array $parameters,
  ): void {
    $method = new \ReflectionMethod($resource, 'applyApiKeyJurisdictionReadScope');
    $method->invoke($resource, $query, $parameters, NULL);
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
