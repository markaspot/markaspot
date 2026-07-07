<?php

namespace Drupal\markaspot_request_id\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates jurisdiction-scoped sequential request IDs.
 *
 * Each jurisdiction maintains its own independent sequence counter.
 * Child jurisdictions share the root parent's counter via
 * JurisdictionHierarchyResolver. Single-tenant sites use jurisdiction_id 0.
 */
class RequestIdGenerator implements RequestIdGeneratorInterface {

  /**
   * Maximum candidate checks after a sequence-table collision.
   */
  protected const MAX_COLLISION_RETRIES = 10;

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * The lock backend.
   */
  protected LockBackendInterface $lock;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * The jurisdiction hierarchy resolver.
   */
  protected ?JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * The entity type manager.
   */
  protected ?EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    Connection $database,
    ConfigFactoryInterface $configFactory,
    TimeInterface $time,
    LockBackendInterface $lock,
    LoggerChannelFactoryInterface $loggerFactory,
    ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ) {
    $this->database = $database;
    $this->configFactory = $configFactory;
    $this->time = $time;
    $this->lock = $lock;
    $this->logger = $loggerFactory->get('markaspot_request_id');
    $this->hierarchyResolver = $hierarchyResolver;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public function generateRequestId(
    ?int $jurisdictionId = NULL,
    ?int $sourceJurisdictionId = NULL,
  ): string {
    $jid = $jurisdictionId ?? 0;
    $config = $this->configFactory->get('markaspot_request_id.settings');
    $delimiter = $config->get('delimiter') ?? '-';
    $format = $config->get('format') ?? 'Y';
    $timestamp = $this->time->getRequestTime();
    $bundle = $this->getServiceRequestBundle();

    // Acquire an application-level lock per jurisdiction to serialize
    // concurrent requests. This is essential because FOR UPDATE cannot
    // lock rows that don't exist yet (first request for a jurisdiction).
    $lockName = 'markaspot_request_id:' . $jid;
    while (!$this->lock->acquire($lockName, 5)) {
      $this->lock->wait($lockName, 5);
    }

    try {
      $transaction = $this->database->startTransaction();
      try {
        // Lock the most recent row for this jurisdiction at the DB level
        // as defense-in-depth alongside the application lock.
        $last = $this->database->query(
          'SELECT seq, timestamp FROM {markaspot_request_id} WHERE jurisdiction_id = :jid ORDER BY id DESC LIMIT 1 FOR UPDATE',
          [':jid' => $jid]
        )->fetchObject();

        if ($last) {
          $nextSeq = $this->calculateNextSeq($last, $config, $timestamp);
        }
        else {
          // First entry for this jurisdiction.
          $nextSeq = 1;
        }

        $date = date($format, $timestamp);
        [$nextSeq, $requestId] = $this->buildAvailableRequestId(
          $nextSeq,
          $date,
          $delimiter,
          $jid,
          $sourceJurisdictionId,
          $bundle
        );

        $this->database->insert('markaspot_request_id')
          ->fields([
            'jurisdiction_id' => $jid,
            'seq' => $nextSeq,
            'request_id' => $requestId,
            'timestamp' => $timestamp,
          ])
          ->execute();
      }
      catch (\Exception $e) {
        if (isset($transaction)) {
          try {
            $transaction->rollBack();
          }
          catch (\Exception $e2) {
            $this->logger->error('Transaction rollback failed: @message', [
              '@message' => $e2->getMessage(),
            ]);
          }
        }
        $this->logger->error('Failed to generate request ID for jurisdiction @jid: @message', [
          '@jid' => $jid,
          '@message' => $e->getMessage(),
        ]);
        throw $e;
      }
    }
    finally {
      $this->lock->release($lockName);
    }

    return $requestId;
  }

  /**
   * Builds a request ID that does not collide with existing nodes.
   *
   * The sequence table is still authoritative for the common path. When older
   * imports already used a candidate request_id without recording it in the
   * sequence table, this method jumps to the highest matching node sequence and
   * returns that final value so the inserted sequence-table row self-heals.
   *
   * @param int $nextSeq
   *   The candidate sequence number.
   * @param string $date
   *   The already formatted date suffix.
   * @param string $delimiter
   *   The configured delimiter.
   * @param int $jurisdictionId
   *   The root jurisdiction ID used by the sequence.
   * @param int|null $sourceJurisdictionId
   *   The source jurisdiction ID used for the visible prefix.
   * @param string $bundle
   *   The node bundle that receives generated request IDs.
   *
   * @return array{0: int, 1: string}
   *   The final sequence and request ID.
   */
  protected function buildAvailableRequestId(
    int $nextSeq,
    string $date,
    string $delimiter,
    int $jurisdictionId,
    ?int $sourceJurisdictionId,
    string $bundle,
  ): array {
    $requestId = $this->buildRequestId(
      $nextSeq,
      $date,
      $delimiter,
      $jurisdictionId,
      $sourceJurisdictionId
    );

    if (!$this->requestIdExistsForJurisdiction($requestId, $jurisdictionId, $bundle)) {
      return [$nextSeq, $requestId];
    }

    $prefix = $this->resolveRequestIdPrefix($jurisdictionId, $sourceJurisdictionId);
    $maxExistingSeq = $this->findMaxExistingNodeSequence(
      $jurisdictionId,
      $bundle,
      $date,
      $delimiter,
      $prefix
    );
    $nextSeq = max($nextSeq + 1, $maxExistingSeq + 1);

    for ($attempt = 0; $attempt < static::MAX_COLLISION_RETRIES; $attempt++) {
      $requestId = $this->buildRequestId(
        $nextSeq,
        $date,
        $delimiter,
        $jurisdictionId,
        $sourceJurisdictionId
      );

      if (!$this->requestIdExistsForJurisdiction($requestId, $jurisdictionId, $bundle)) {
        return [$nextSeq, $requestId];
      }

      $nextSeq++;
    }

    throw new \RuntimeException(sprintf(
      'Could not find a free request ID for jurisdiction %d after %d collision retries.',
      $jurisdictionId,
      static::MAX_COLLISION_RETRIES
    ));
  }

  /**
   * Builds the stored request ID.
   *
   * Prefixes are purely presentational. They help multi-jurisdiction staff
   * distinguish visible tracking IDs, but API access must still scope by
   * jurisdiction or use the entity UUID.
   *
   * @param int $sequence
   *   The next sequence number for the root jurisdiction.
   * @param string $date
   *   The already formatted date suffix.
   * @param string $delimiter
   *   The configured delimiter.
   * @param int|null $jurisdictionId
   *   The root jurisdiction ID used by the sequence.
   * @param int|null $sourceJurisdictionId
   *   The source jurisdiction ID used for the visible prefix.
   *
   * @return string
   *   The formatted request ID.
   */
  protected function buildRequestId(
    int $sequence,
    string $date,
    string $delimiter,
    ?int $jurisdictionId = NULL,
    ?int $sourceJurisdictionId = NULL,
  ): string {
    $baseId = $sequence . $delimiter . $date;
    $prefix = $this->resolveRequestIdPrefix($jurisdictionId, $sourceJurisdictionId);

    return $prefix === '' ? $baseId : $prefix . $delimiter . $baseId;
  }

  /**
   * Resolves the visible request-ID prefix for a root/source jurisdiction pair.
   */
  protected function resolveRequestIdPrefix(
    ?int $jurisdictionId,
    ?int $sourceJurisdictionId,
  ): string {
    $prefix = $this->loadRequestIdPrefix($sourceJurisdictionId ?? $jurisdictionId);

    if ($prefix === '' && $sourceJurisdictionId !== NULL && $sourceJurisdictionId !== $jurisdictionId) {
      $prefix = $this->loadRequestIdPrefix($jurisdictionId);
    }

    return $prefix;
  }

  /**
   * Returns the configured service_request bundle ID.
   */
  protected function getServiceRequestBundle(): string {
    $bundle = $this->configFactory->get('node.type.service_request')->get('type');

    return is_string($bundle) && $bundle !== '' ? $bundle : 'service_request';
  }

  /**
   * Checks whether an existing node already owns a scoped request ID.
   */
  protected function requestIdExistsForJurisdiction(
    string $requestId,
    int $jurisdictionId,
    string $bundle,
  ): bool {
    $args = [
      ':bundle' => $bundle,
      ':request_id' => $requestId,
    ];

    $sql = $this->existingNodeRequestIdBaseSql('SELECT 1', 'nfd.request_id = :request_id', $jurisdictionId, $args);
    $sql .= ' LIMIT 1';

    return (bool) $this->database->query($sql, $args)->fetchField();
  }

  /**
   * Finds the highest existing node sequence for the current prefix/date shape.
   */
  protected function findMaxExistingNodeSequence(
    int $jurisdictionId,
    string $bundle,
    string $date,
    string $delimiter,
    string $prefix,
  ): int {
    $args = [
      ':bundle' => $bundle,
      ':request_id_pattern' => $this->requestIdLikePattern($date, $delimiter, $prefix),
    ];

    $sql = $this->existingNodeRequestIdBaseSql('SELECT DISTINCT nfd.request_id', 'nfd.request_id LIKE :request_id_pattern', $jurisdictionId, $args);
    $rows = $this->database->query($sql, $args)->fetchCol();

    $maxSeq = 0;
    foreach ($rows as $requestId) {
      $seq = $this->extractSequenceFromRequestId((string) $requestId, $date, $delimiter, $prefix);
      if ($seq !== NULL) {
        $maxSeq = max($maxSeq, $seq);
      }
    }

    return $maxSeq;
  }

  /**
   * Builds the node request_id scope query shared by existence and max lookup.
   *
   * Jurisdiction ID 0 is the legacy/global scope and only matches nodes without
   * field_jurisdiction. Jurisdiction-scoped nodes are compared by their root
   * jurisdiction, so a child field_jurisdiction still shares its root sequence.
   *
   * @param string $select
   *   The SELECT clause.
   * @param string $requestIdCondition
   *   The request_id predicate.
   * @param int $jurisdictionId
   *   The root jurisdiction ID, or 0 for jurisdictionless nodes.
   * @param array<string, mixed> $args
   *   Query arguments to extend with :jid when needed.
   *
   * @return string
   *   The SQL query.
   */
  protected function existingNodeRequestIdBaseSql(
    string $select,
    string $requestIdCondition,
    int $jurisdictionId,
    array &$args,
  ): string {
    if ($jurisdictionId > 0) {
      $args[':jid'] = $jurisdictionId;
      return "
        WITH RECURSIVE jurisdiction_scope (id) AS (
          SELECT :jid
          UNION
          SELECT gpj.entity_id
          FROM {group__field_parent_jurisdiction} gpj
          INNER JOIN jurisdiction_scope scope
            ON scope.id = gpj.field_parent_jurisdiction_target_id
          WHERE gpj.deleted = 0
        )
        $select
        FROM {node_field_data} nfd
        INNER JOIN {node__field_jurisdiction} nfj
          ON nfj.entity_id = nfd.nid
          AND nfj.deleted = 0
        INNER JOIN jurisdiction_scope scope
          ON scope.id = nfj.field_jurisdiction_target_id
        WHERE nfd.type = :bundle
          AND $requestIdCondition";
    }

    $sql = "
      $select
      FROM {node_field_data} nfd
      LEFT JOIN {node__field_jurisdiction} nfj
        ON nfj.entity_id = nfd.nid
        AND nfj.deleted = 0
      WHERE nfd.type = :bundle
        AND $requestIdCondition";

    return $sql . '
        AND nfj.entity_id IS NULL';
  }

  /**
   * Builds a broad LIKE pattern for same-format request IDs.
   */
  protected function requestIdLikePattern(string $date, string $delimiter, string $prefix): string {
    $safeDate = $this->database->escapeLike($date);
    $safeDelimiter = $this->database->escapeLike($delimiter);

    if ($prefix !== '') {
      return $this->database->escapeLike($prefix) . $safeDelimiter . '%' . $safeDelimiter . $safeDate;
    }

    return '%' . $safeDelimiter . $safeDate;
  }

  /**
   * Extracts the numeric sequence from a visible request ID.
   */
  protected function extractSequenceFromRequestId(
    string $requestId,
    string $date,
    string $delimiter,
    string $prefix,
  ): ?int {
    $quotedDelimiter = preg_quote($delimiter, '/');
    $quotedDate = preg_quote($date, '/');

    if ($prefix !== '') {
      $pattern = '/^' . preg_quote($prefix, '/') . $quotedDelimiter . '([1-9][0-9]*)' . $quotedDelimiter . $quotedDate . '$/';
    }
    else {
      $pattern = '/^([1-9][0-9]*)' . $quotedDelimiter . $quotedDate . '$/';
    }

    if (!preg_match($pattern, $requestId, $matches)) {
      return NULL;
    }

    return (int) $matches[1];
  }

  /**
   * Loads and normalizes the visible request-ID prefix for a jurisdiction.
   *
   * @param int|null $jurisdictionId
   *   The jurisdiction group ID.
   *
   * @return string
   *   The normalized prefix, or an empty string when no prefix is configured.
   */
  protected function loadRequestIdPrefix(?int $jurisdictionId): string {
    if ($jurisdictionId === NULL || $jurisdictionId <= 0 || !$this->entityTypeManager) {
      return '';
    }

    try {
      $group = $this->entityTypeManager->getStorage('group')->load($jurisdictionId);
    }
    catch (\Exception $e) {
      return '';
    }

    if (!$group instanceof FieldableEntityInterface
      || $group->bundle() !== 'jur'
      || !$group->hasField('field_request_id_prefix')
      || $group->get('field_request_id_prefix')->isEmpty()) {
      return '';
    }

    return $this->normalizeRequestIdPrefix((string) $group->get('field_request_id_prefix')->value);
  }

  /**
   * Normalizes a configured request-ID prefix for URL and API safety.
   *
   * @param string $prefix
   *   The raw configured prefix.
   *
   * @return string
   *   Uppercase ASCII letters and digits, capped to the field length.
   */
  protected function normalizeRequestIdPrefix(string $prefix): string {
    $normalized = strtoupper(trim($prefix));
    $normalized = preg_replace('/[^A-Z0-9]+/', '', $normalized) ?? '';

    return substr($normalized, 0, 16);
  }

  /**
   * Calculates the next sequence number, handling yearly rollover.
   *
   * @param object $last
   *   The most recent row for this jurisdiction (seq, timestamp).
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The module configuration.
   * @param int $timestamp
   *   The current request timestamp.
   *
   * @return int
   *   The next sequence number.
   */
  protected function calculateNextSeq(object $last, ImmutableConfig $config, int $timestamp): int {
    if (!$config->get('rollover')) {
      return (int) $last->seq + 1;
    }

    // Determine the rollover boundary timestamp.
    $customStart = $config->get('start');
    if (!empty($customStart)) {
      $rolloverTimestamp = (new DrupalDateTime($customStart))->getTimestamp();
    }
    else {
      // Last second of the previous year relative to current request.
      $rolloverTimestamp = mktime(23, 59, 59, 12, 31, (int) date('Y', $timestamp) - 1);
    }

    // Reset sequence if the last entry predates the rollover boundary
    // and the current request is past it.
    if ($last->timestamp <= $rolloverTimestamp && $timestamp > $rolloverTimestamp) {
      return 1;
    }

    return (int) $last->seq + 1;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveJurisdictionFromNode(EntityInterface $node): ?int {
    $jurisdictionId = $this->resolveSourceJurisdictionFromNode($node);
    if ($jurisdictionId === NULL) {
      return NULL;
    }

    // Resolve to root jurisdiction if hierarchy resolver is available.
    if ($this->hierarchyResolver) {
      try {
        $rootJurisdictionId = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
        if ($rootJurisdictionId === NULL) {
          throw new \UnexpectedValueException(sprintf(
            'Invalid jurisdiction hierarchy for category jurisdiction %d.',
            $jurisdictionId
          ));
        }
        return $rootJurisdictionId;
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not resolve root jurisdiction for @jid: @message', [
          '@jid' => $jurisdictionId,
          '@message' => $e->getMessage(),
        ]);
        if ($e instanceof \UnexpectedValueException) {
          throw $e;
        }
      }
    }

    return $jurisdictionId;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveSourceJurisdictionFromNode(EntityInterface $node): ?int {
    if (!$node->hasField('field_category') || $node->get('field_category')->isEmpty()) {
      return NULL;
    }

    $categoryTerm = $node->get('field_category')->entity;
    if (!$categoryTerm || !$categoryTerm->hasField('field_jurisdiction') || $categoryTerm->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }

    return (int) $categoryTerm->get('field_jurisdiction')->target_id;
  }

}
