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
 * - $this->config (the markaspot_open311.settings config object) — supplies
 *   the configurable rate_limit thresholds; the constants below are the
 *   fallback defaults when a key is unset.
 *
 * The contract is documented at the property level on the consuming
 * class.
 */
trait Open311RateLimitTrait {

  /**
   * Default max requests per window for read/search/update events.
   */
  protected const RATE_LIMIT_THRESHOLD = 60;

  /**
   * Default max create (POST) requests per window.
   *
   * Report creation is throttled harder than reads as anti-bombing
   * defense-in-depth behind the Nuxt proxy (#474). Tune via
   * markaspot_open311.settings:rate_limit.create_threshold (3-5 recommended).
   */
  protected const RATE_LIMIT_CREATE_THRESHOLD = 5;

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
   * Resolves the flood threshold for an event from config, with a default.
   *
   * The create path (georeport_api_create) carries its own stricter default
   * so report creation can be throttled hard without lowering the read or
   * search limit. Both are configurable under
   * markaspot_open311.settings:rate_limit.
   *
   * @param string $name
   *   The flood event name.
   *
   * @return int
   *   The maximum number of requests allowed in the window.
   */
  protected function rateLimitThreshold(string $name): int {
    $is_create = $name === 'georeport_api_create';
    $key = $is_create ? 'rate_limit.create_threshold' : 'rate_limit.threshold';
    $value = $this->config->get($key);
    if (is_numeric($value) && (int) $value > 0) {
      return (int) $value;
    }
    return $is_create ? self::RATE_LIMIT_CREATE_THRESHOLD : self::RATE_LIMIT_THRESHOLD;
  }

  /**
   * Resolves the flood window in seconds from config, with a default.
   *
   * @return int
   *   The flood window length in seconds.
   */
  protected function rateLimitWindow(): int {
    $value = $this->config->get('rate_limit.window');
    return is_numeric($value) && (int) $value > 0 ? (int) $value : self::RATE_LIMIT_WINDOW;
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

    $threshold = $this->rateLimitThreshold($name);
    $window = $this->rateLimitWindow();

    if (!$this->flood->isAllowed($name, $threshold, $window, $identifier)) {
      // Path-info disambiguates which endpoint tripped the gate when
      // adjacent endpoints land in the same rate-limit log channel (e.g.
      // georeport_api_create on /requests vs georeport_api_post on
      // /requests/{id}). Without it the operator sees 429 spikes in the
      // log without knowing whether /requests or /requests/{id} produced
      // them.
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
      $retryAfter = $window + random_int(0, (int) ($window / 2));
      $exception = new GeoreportException('Too many requests. Please slow down.', 429);
      $exception->setHeaders(['Retry-After' => (string) $retryAfter]);
      throw $exception;
    }

    $this->flood->register($name, $window, $identifier);
  }

}
