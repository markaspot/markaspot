<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_group\Service\JurisdictionScopeValidator;
use Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestResource;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Tests API-key jurisdiction scoping on single-request lookups.
 *
 * @group markaspot_open311
 * @coversDefaultClass \Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestResource
 */
final class GeoreportRequestResourceScopeTest extends UnitTestCase {

  /**
   * Single-request reads use the same GET flood event as index reads.
   *
   * @covers ::get
   */
  public function testSingleRequestReadChecksGetRateLimit(): void {
    $resource = $this->resource([], [], []);

    try {
      $resource->get('REQ-101');
      $this->fail('The test resource must stop after the rate-limit check.');
    }
    catch (\LogicException $exception) {
      $this->assertSame('georeport_api_get', $exception->getMessage());
    }
  }

  /**
   * Authenticated invalid claims are recorded before the existing 400.
   *
   * @covers ::resolveSingleRequestJurisdictionScope
   */
  public function testAuthenticatedInvalidClaimIsLogged(): void {
    $validator = $this->createMock(JurisdictionScopeValidator::class);
    $validator->expects($this->once())
      ->method('getAllowedJurisdictionIds')
      ->with($this->isInstanceOf(AccountInterface::class))
      ->willReturn([1, 4]);
    $validator->expects($this->once())
      ->method('logViolation')
      ->with(7, NULL, [1, 4], 400, 'invalid_claim');
    $resource = $this->resource([], [], [], TRUE, FALSE, [1, 4], $validator);

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Invalid jurisdiction_id.');
    $resource->loadForRead('REQ-101', ['jurisdiction_id' => 999]);
  }

  /**
   * Multi-scope API keys can find a request without claiming a jurisdiction.
   *
   * @covers ::loadScopedRequestNode
   * @covers ::resolveSingleRequestJurisdictionScope
   * @covers ::requestNodeMatchesScope
   */
  public function testMultiScopeApiKeyReadWithoutClaimFindsRequest(): void {
    $node = $this->node(101);
    $resource = $this->resource([1, 4], [101 => 4], [$node]);

    $this->assertSame($node, $resource->loadForRead('REQ-101', []));
  }

  /**
   * Candidates outside the granted read scope are discarded.
   *
   * @covers ::loadScopedRequestNode
   * @covers ::requestNodeMatchesScope
   */
  public function testReadWithoutClaimDiscardsForeignCandidate(): void {
    $foreign = $this->node(101);
    $allowed = $this->node(102);
    $resource = $this->resource(
      [1, 4],
      [101 => 5, 102 => 4],
      [$foreign, $allowed],
      TRUE,
      FALSE,
      [1, 4, 5],
    );

    $this->assertSame($allowed, $resource->loadForRead('REQ-101', []));
  }

  /**
   * A jurisdiction claim outside the key scope remains forbidden.
   *
   * @covers ::loadScopedRequestNode
   * @covers ::resolveSingleRequestJurisdictionScope
   */
  public function testForeignJurisdictionClaimRemainsForbidden(): void {
    $resource = $this->resource([1, 4], [], [], TRUE, FALSE, [1, 4, 5]);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('key not authorized for jur 5');
    $resource->loadForRead('REQ-101', ['jurisdiction_id' => 5]);
  }

  /**
   * An API key without jurisdiction memberships remains forbidden.
   *
   * @covers ::loadScopedRequestNode
   * @covers ::resolveSingleRequestJurisdictionScope
   */
  public function testEmptyApiKeyScopeRemainsForbidden(): void {
    $resource = $this->resource([], [], []);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('key has no jurisdiction scope');
    $resource->loadForRead('REQ-101', []);
  }

  /**
   * Duplicate request IDs within the granted scope remain ambiguous.
   *
   * @covers ::loadScopedRequestNode
   * @covers ::requestNodeMatchesScope
   */
  public function testMultipleMatchesWithinScopeStillRequireClaim(): void {
    $first = $this->node(101);
    $second = $this->node(102);
    $resource = $this->resource(
      [1, 4],
      [101 => 1, 102 => 4],
      [$first, $second],
    );

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('jurisdiction_id required to disambiguate service_request_id.');
    $resource->loadForRead('DUPLICATE', []);
  }

  /**
   * Anonymous invalid claims keep returning an empty lookup result.
   *
   * @covers ::loadScopedRequestNode
   * @covers ::resolveSingleRequestJurisdictionScope
   */
  public function testAnonymousInvalidClaimKeepsExistenceOracleClosed(): void {
    $node = $this->node(101);
    $resource = $this->resource(
      [],
      [101 => 1],
      [$node],
      FALSE,
      TRUE,
      [1, 4],
    );

    $this->assertNull($resource->loadForRead('REQ-101', ['jurisdiction_id' => 999]));
  }

  /**
   * Submission lookup still rejects an unclaimed multi-scope API key.
   *
   * @covers ::loadScopedRequestNode
   * @covers ::resolveSingleRequestJurisdictionScope
   */
  public function testSubmissionPathStillRequiresMultiScopeClaim(): void {
    $resource = $this->resource([1, 4], [], []);

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('jurisdiction_id required');
    $resource->loadForWrite('REQ-101', []);
  }

  /**
   * Builds the resource with deterministic scope and request candidates.
   *
   * @param int[] $allowedJurisdictionIds
   *   Jurisdiction scope granted to the API key.
   * @param array<int, int> $nodeJurisdictionIds
   *   Node ID to jurisdiction ID map.
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $nodes
   *   Candidate request nodes.
   * @param bool $usesApiKey
   *   Whether the request carries an API key.
   * @param bool $anonymous
   *   Whether the current user is anonymous.
   * @param int[] $validJurisdictionIds
   *   Existing jurisdiction IDs.
   * @param \Drupal\markaspot_group\Service\JurisdictionScopeValidator|null $validator
   *   Optional validator used to assert audit-log calls.
   */
  private function resource(
    array $allowedJurisdictionIds,
    array $nodeJurisdictionIds,
    array $nodes,
    bool $usesApiKey = TRUE,
    bool $anonymous = FALSE,
    array $validJurisdictionIds = [1, 4],
    ?JurisdictionScopeValidator $validator = NULL,
  ): ScopeTestGeoreportRequestResource {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn($anonymous ? 0 : 7);
    $account->method('isAnonymous')->willReturn($anonymous);

    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn(array_map(
      static fn(ContentEntityInterface $node): int => (int) $node->id(),
      $nodes,
    ));

    $processor = $this->createMock(GeoreportProcessorService::class);
    $processor->method('createNodeQuery')->willReturn($query);
    $processor->method('resolveJurisdictionId')->willReturnCallback(
      static fn(array $parameters): ?int => isset($parameters['jurisdiction_id'])
        && is_numeric($parameters['jurisdiction_id'])
          ? (int) $parameters['jurisdiction_id']
          : NULL,
    );

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('loadMultiple')->willReturn($nodes);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('node')
      ->willReturn($nodeStorage);

    $config = $this->createMock(Config::class);
    $config->method('get')->with('bundle')->willReturn('service_request');

    return new ScopeTestGeoreportRequestResource(
      $account,
      $config,
      $entityTypeManager,
      $processor,
      $validator ?? $this->validatorWithAllowed($allowedJurisdictionIds),
      $usesApiKey,
      $nodeJurisdictionIds,
      $validJurisdictionIds,
    );
  }

  /**
   * Creates a validator with a deterministic granted scope.
   *
   * @param int[] $allowed
   *   Allowed jurisdiction IDs.
   */
  private function validatorWithAllowed(array $allowed): JurisdictionScopeValidator {
    return new class($allowed, $this->createMock(Connection::class)) extends JurisdictionScopeValidator {

      /**
       * Constructs the test validator.
       *
       * @param int[] $allowed
       *   Allowed jurisdiction IDs.
       * @param \Drupal\Core\Database\Connection $database
       *   Database mock.
       */
      public function __construct(
        private readonly array $allowed,
        Connection $database,
      ) {
        parent::__construct($database, new NullLogger());
      }

      /**
       * {@inheritdoc}
       */
      public function getAllowedJurisdictionIds(AccountInterface $account): array {
        return $this->allowed;
      }

    };
  }

  /**
   * Creates a request node mock.
   */
  private function node(int $id): ContentEntityInterface {
    $node = $this->createMock(ContentEntityInterface::class);
    $node->method('id')->willReturn($id);
    return $node;
  }

}

/**
 * Exposes the scoped request loader with deterministic jurisdiction mapping.
 */
final class ScopeTestGeoreportRequestResource extends GeoreportRequestResource {

  /**
   * Stops full GET execution after recording the flood event.
   */
  protected function checkRateLimit(string $name): void {
    throw new \LogicException($name);
  }

  /**
   * Constructs the scoped test resource.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   Current user proxy.
   * @param \Drupal\Core\Config\Config $config
   *   Open311 configuration mock.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager mock.
   * @param \Drupal\markaspot_open311\Service\GeoreportProcessorService $georeportProcessor
   *   Open311 processor mock.
   * @param \Drupal\markaspot_group\Service\JurisdictionScopeValidator $jurisdictionScopeValidator
   *   Jurisdiction scope validator.
   * @param bool $usesApiKey
   *   Whether the request carries an API key.
   * @param array<int, int> $nodeJurisdictionIds
   *   Node ID to jurisdiction ID map.
   * @param int[] $validJurisdictionIds
   *   Existing jurisdiction IDs.
   */
  public function __construct(
    AccountProxyInterface $currentUser,
    Config $config,
    EntityTypeManagerInterface $entityTypeManager,
    GeoreportProcessorService $georeportProcessor,
    JurisdictionScopeValidator $jurisdictionScopeValidator,
    private readonly bool $usesApiKey,
    private readonly array $nodeJurisdictionIds,
    private readonly array $validJurisdictionIds,
  ) {
    $this->currentUser = $currentUser;
    $this->config = $config;
    $this->entityTypeManager = $entityTypeManager;
    $this->georeportProcessor = $georeportProcessor;
    $this->jurisdictionScopeValidator = $jurisdictionScopeValidator;
    $this->workspaceVisibility = NULL;
  }

  /**
   * Loads a request through the read path.
   */
  public function loadForRead(string $id, array $parameters): ?ContentEntityInterface {
    return $this->loadScopedRequestNode($id, $parameters, FALSE);
  }

  /**
   * Loads a request through the write path.
   */
  public function loadForWrite(string $id, array $parameters): ?ContentEntityInterface {
    return $this->loadScopedRequestNode($id, $parameters);
  }

  /**
   * {@inheritdoc}
   */
  protected function currentRequestUsesApiKey(): bool {
    return $this->usesApiKey;
  }

  /**
   * {@inheritdoc}
   */
  protected function nodeBelongsToJurisdiction(ContentEntityInterface $node, int $jurisdictionId): bool {
    return ($this->nodeJurisdictionIds[(int) $node->id()] ?? NULL) === $jurisdictionId;
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveNodeJurisdictionId(object $node): ?int {
    return $this->nodeJurisdictionIds[(int) $node->id()] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function isJurisdictionGroupId(int $groupId): bool {
    return in_array($groupId, $this->validJurisdictionIds, TRUE);
  }

}
