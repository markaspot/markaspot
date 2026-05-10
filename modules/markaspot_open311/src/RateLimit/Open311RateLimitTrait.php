<?php

declare(strict_types=1);

namespace Drupal\markaspot_open311\RateLimit;

use Drupal\markaspot_open311\Exception\GeoreportException;

/**
 * Per-user / per-IP flood control for the Open311 REST resources.
 *
 * Extracted from GeoreportRequestIndexResource so the single-resource
 * UPDATE endpoint (GeoreportRequestResource) can apply the same flood
 * gate. Without it, an authenticated api-key consumer with the
 * `access open311 advanced properties` permission could flood the
 * UPDATE endpoint and amplify watchdog writes during a broken-mail
 * outage (security review of 81dd6f4 / 100ebc2 finding 7).
 *
 * Consumers must inject:
 * - $this->flood (\Drupal\Core\Flood\FloodInterface)
 * - $this->currentUser (\Drupal\Core\Session\AccountInterface)
 * - $this->requestStack (\Symfony\Component\HttpFoundation\RequestStack)
 * - $this->logger (\Psr\Log\LoggerInterface)
 *
 * The contract is documented at the property level on the consuming
 * class.
 */
trait Open311RateLimitTrait {

  /**
   * Rate limit: max requests per window for regular users.
   */
  protected const RATE_LIMIT_THRESHOLD = 60;

  /**
   * Rate limit window in seconds (1 minute).
   */
  protected const RATE_LIMIT_WINDOW = 60;

  /**
   * Roles exempt from rate limiting.
   *
   * Staff roles that need unrestricted API access for moderation.
   * Note: api_user is NOT exempt — session UID is checked separately
   * to distinguish frontend app users from external API consumers.
   */
  protected const RATE_LIMIT_EXEMPT_ROLES = [
    'administrator',
    'moderator',
    'editorial_board',
    'api_editor',
    'api_municipality',
  ];

  /**
   * Returns TRUE for staff roles or session-authenticated frontend users.
   *
   * - Anonymous → not exempt (rate limited per IP)
   * - api_user (no session) → not exempt (rate limited per UID)
   * - Nuxt frontend users (session-based) → exempt
   * - External API consumers (api_key only, no session) → rate limited.
   *
   * IMPORTANT — coupling with the UPDATE endpoint: the session-UID
   * branch exempts every authenticated user that carries a session.
   * Today the UPDATE endpoint at /georeport/v2/requests/{id}.json gates
   * on the `access open311 advanced properties` permission, which only
   * the roles listed above carry, so a citizen session never reaches
   * the gate. If a future role gains `access open311 advanced
   * properties` without being added to RATE_LIMIT_EXEMPT_ROLES,
   * session-authenticated users in that role would silently bypass the
   * flood limit on the UPDATE path. Auditors who add such a role MUST
   * either add it to the exempt list (if intentional) or scope the
   * session-UID branch behind a flood-key allowlist.
   *
   * @return bool
   *   TRUE if the user is exempt from rate limiting, FALSE otherwise.
   */
  protected function isExemptFromRateLimit(): bool {
    $userRoles = $this->currentUser->getRoles();
    foreach (self::RATE_LIMIT_EXEMPT_ROLES as $exemptRole) {
      if (in_array($exemptRole, $userRoles, TRUE)) {
        return TRUE;
      }
    }

    $request = $this->requestStack->getCurrentRequest();
    if ($request && $request->hasSession()) {
      $session = $request->getSession();
      $uid = $session->get('uid');
      if (!empty($uid) && $uid > 0) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks flood control and throws if the rate limit is exceeded.
   *
   * @param string $name
   *   The flood event name (e.g. 'georeport_api_get',
   *   'georeport_api_post', 'georeport_api_search').
   *
   * @throws \Drupal\markaspot_open311\Exception\GeoreportException
   *   Throws 429 Too Many Requests if rate limit exceeded.
   */
  protected function checkRateLimit(string $name): void {
    if ($this->isExemptFromRateLimit()) {
      return;
    }

    $identifier = $this->currentUser->isAnonymous()
      ? $this->requestStack->getCurrentRequest()->getClientIp()
      : (string) $this->currentUser->id();

    if (!$this->flood->isAllowed($name, self::RATE_LIMIT_THRESHOLD, self::RATE_LIMIT_WINDOW, $identifier)) {
      // Path-info disambiguates which endpoint tripped the gate when
      // multiple resources share a flood key (e.g. create + update both
      // register under georeport_api_post). Without it the operator sees
      // 429 spikes in the log without knowing whether /requests or
      // /requests/{id} produced them.
      $request = $this->requestStack->getCurrentRequest();
      $this->logger->warning('Rate limit exceeded for @name by @identifier on @path', [
        '@name' => $name,
        '@identifier' => $identifier,
        '@path' => $request ? $request->getPathInfo() : '(unknown)',
      ]);
      // Jitter the Retry-After by up to 50% of the window so a 429
      // burst against a shared identifier does not retry synchronously.
      // 502 throws on the same endpoints already follow the same
      // pattern; mirroring it here keeps the contract consistent and
      // future-proofs the trait against shrinking RATE_LIMIT_WINDOW.
      $retryAfter = self::RATE_LIMIT_WINDOW + random_int(0, (int) (self::RATE_LIMIT_WINDOW / 2));
      $exception = new GeoreportException('Too many requests. Please slow down.', 429);
      $exception->setHeaders(['Retry-After' => (string) $retryAfter]);
      throw $exception;
    }

    $this->flood->register($name, self::RATE_LIMIT_WINDOW, $identifier);
  }

}
