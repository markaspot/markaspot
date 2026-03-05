<?php

namespace Drupal\markaspot_request_id\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
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

  public function __construct(
    Connection $database,
    ConfigFactoryInterface $configFactory,
    TimeInterface $time,
    LockBackendInterface $lock,
    LoggerChannelFactoryInterface $loggerFactory,
    ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
  ) {
    $this->database = $database;
    $this->configFactory = $configFactory;
    $this->time = $time;
    $this->lock = $lock;
    $this->logger = $loggerFactory->get('markaspot_request_id');
    $this->hierarchyResolver = $hierarchyResolver;
  }

  /**
   * {@inheritdoc}
   */
  public function generateRequestId(?int $jurisdictionId = NULL): string {
    $jid = $jurisdictionId ?? 0;
    $config = $this->configFactory->get('markaspot_request_id.settings');
    $delimiter = $config->get('delimiter') ?? '-';
    $format = $config->get('format') ?? 'Y';
    $timestamp = $this->time->getRequestTime();

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
        $requestId = $nextSeq . $delimiter . $date;

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
    if (!$node->hasField('field_category') || $node->get('field_category')->isEmpty()) {
      return NULL;
    }

    $categoryTerm = $node->get('field_category')->entity;
    if (!$categoryTerm || !$categoryTerm->hasField('field_jurisdiction') || $categoryTerm->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }

    $jurisdictionId = (int) $categoryTerm->get('field_jurisdiction')->target_id;

    // Resolve to root jurisdiction if hierarchy resolver is available.
    if ($this->hierarchyResolver) {
      try {
        return $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not resolve root jurisdiction for @jid: @message', [
          '@jid' => $jurisdictionId,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $jurisdictionId;
  }

}
