<?php

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Validates API-key jurisdiction scope without platform-admin bypasses.
 */
class JurisdictionScopeValidator {

  /**
   * Constructs a JurisdictionScopeValidator object.
   */
  public function __construct(
    protected Connection $database,
    protected LoggerInterface $logger,
    protected ?ConfigFactoryInterface $configFactory = NULL,
  ) {}

  /**
   * Gets jurisdiction group IDs the account is directly a member of.
   *
   * @return int[]
   *   Sorted jurisdiction group IDs.
   */
  public function getAllowedJurisdictionIds(AccountInterface $account): array {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return [];
    }

    try {
      $query = $this->database->select('group_relationship_field_data', 'gr');
      $query->join('groups_field_data', 'g', 'g.id = gr.gid');
      $ids = $query
        ->fields('gr', ['gid'])
        ->condition('gr.entity_id', $uid)
        ->condition('gr.plugin_id', 'group_membership')
        ->condition('g.type', $this->jurisdictionGroupType())
        ->orderBy('gr.gid', 'ASC')
        ->execute()
        ->fetchCol();
    }
    catch (\Exception) {
      return [];
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids, SORT_NUMERIC);
    return $ids;
  }

  /**
   * Resolves the request jurisdiction against the caller's allowed scope.
   */
  public function resolveSubmissionJurisdiction(?int $claimedJurisdictionId, AccountInterface $account): int {
    $allowed = $this->getAllowedJurisdictionIds($account);
    $uid = (int) $account->id();

    if ($uid <= 0) {
      $this->logViolation($uid, $claimedJurisdictionId, $allowed, 401, 'missing_api_key_owner');
      throw new UnauthorizedHttpException('api_key', 'API key authentication required.');
    }

    if ($claimedJurisdictionId === NULL) {
      if (count($allowed) === 1) {
        return $allowed[0];
      }

      if ($allowed === []) {
        $this->logViolation($uid, NULL, $allowed, 403, 'empty_scope');
        throw new AccessDeniedHttpException('key has no jurisdiction scope');
      }

      $this->logViolation($uid, NULL, $allowed, 400, 'ambiguous_scope');
      throw new BadRequestHttpException('jurisdiction_id required');
    }

    if (!in_array($claimedJurisdictionId, $allowed, TRUE)) {
      $this->logViolation($uid, $claimedJurisdictionId, $allowed, 403, 'foreign_scope');
      throw new AccessDeniedHttpException('key not authorized for jur ' . $claimedJurisdictionId);
    }

    return $claimedJurisdictionId;
  }

  /**
   * Resolves the jurisdiction scope for an API-key read.
   *
   * @return int[]
   *   Jurisdiction IDs that the read may search.
   */
  public function resolveReadScope(?int $claimedJurisdictionId, AccountInterface $account): array {
    $allowed = $this->getAllowedJurisdictionIds($account);
    $uid = (int) $account->id();

    if ($uid <= 0) {
      $this->logViolation($uid, $claimedJurisdictionId, $allowed, 401, 'missing_api_key_owner');
      throw new UnauthorizedHttpException('api_key', 'API key authentication required.');
    }

    if ($allowed === []) {
      $this->logViolation($uid, $claimedJurisdictionId, $allowed, 403, 'empty_scope');
      throw new AccessDeniedHttpException('key has no jurisdiction scope');
    }

    if ($claimedJurisdictionId !== NULL) {
      if (!in_array($claimedJurisdictionId, $allowed, TRUE)) {
        $this->logViolation($uid, $claimedJurisdictionId, $allowed, 403, 'foreign_scope');
        throw new AccessDeniedHttpException('key not authorized for jur ' . $claimedJurisdictionId);
      }

      return [$claimedJurisdictionId];
    }

    return $allowed;
  }

  /**
   * Logs a scope violation in the canonical audit format.
   *
   * @param int $callerUid
   *   Caller user ID.
   * @param int|null $claimedJurisdictionId
   *   Claimed jurisdiction ID, if any.
   * @param int[] $allowedJurisdictionIds
   *   Allowed jurisdiction IDs.
   * @param int $result
   *   HTTP result code.
   * @param string $reason
   *   Machine-readable reason.
   */
  public function logViolation(int $callerUid, ?int $claimedJurisdictionId, array $allowedJurisdictionIds, int $result, string $reason): void {
    $this->logger->warning(
      'submission.scope_violation caller_uid=@uid claimed=@claimed allowed=[@allowed] result=@result reason=@reason',
      [
        '@uid' => $callerUid,
        '@claimed' => $claimedJurisdictionId === NULL ? 'NULL' : (string) $claimedJurisdictionId,
        '@allowed' => implode(',', array_map('intval', $allowedJurisdictionIds)),
        '@result' => $result,
        '@reason' => $reason,
      ]
    );
  }

  /**
   * Returns the configured jurisdiction group bundle.
   */
  protected function jurisdictionGroupType(): string {
    $config = $this->configFactory?->get('markaspot_open311.settings');
    $configured = $config ? $config->get('jurisdiction_group_type') : NULL;

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

}
