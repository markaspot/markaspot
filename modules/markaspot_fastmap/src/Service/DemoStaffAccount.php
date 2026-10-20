<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\UserSessionRepositoryInterface;
use Drupal\markaspot_fastmap\Demo\DemoWorkspace;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The shared staff account of a template demo workspace.
 *
 * Every allowlisted demo workspace has one staff account that all visitors
 * share through POST /api/fastmap/demo-session. The seeder creates it; this
 * service recognises it and owns the session rules.
 *
 * Recognition follows the seed contract: the account's UUID is derived from
 * the site UUID and the slug (DemoWorkspace::uuid()), and its name carries
 * the slug. No other account can hold that UUID, and nothing visitors can
 * edit marks an account as demo.
 *
 * A demo session is valid only when the endpoint started it: the login
 * stores a marker in the session, every other login path of a demo account
 * is ended in markaspot_fastmap_user_login(), and DemoSessionSubscriber logs
 * a session out once the marker is missing or older than MAX_SESSION_AGE.
 */
final class DemoStaffAccount {

  /**
   * Mail domain of demo accounts; .invalid can never be delivered (RFC 2606).
   */
  public const MAIL_DOMAIN = 'civicspot.invalid';

  /**
   * Suffix of the account name and mail local part after the workspace slug.
   *
   * The slug already starts with "demo-", so the name reads
   * "demo-school-routes-team".
   */
  public const NAME_SUFFIX = '-team';

  /**
   * Membership roles in the demo workspace, without the group type prefix.
   *
   * The same pair the team dialog gives an invited moderator
   * (GroupInvitationController::ensureBaseMemberRole()).
   */
  public const MEMBERSHIP_ROLES = ['member', 'moderator'];

  /**
   * Global roles of the account besides "authenticated".
   *
   * @var string[]
   */
  public const GLOBAL_ROLES = [];

  /**
   * Session attribute that marks a session started by the demo endpoint.
   */
  public const SESSION_KEY = 'markaspot_fastmap_demo_session';

  /**
   * Header naming the jurisdiction of an upload.
   *
   * MediaTenantScopeResolver::UPLOAD_HEADER of markaspot_vision.
   */
  public const UPLOAD_JURISDICTION_HEADER = 'X-Markaspot-Jurisdiction';

  /**
   * Seconds a demo session lives at most, independent of the hourly reset.
   */
  public const MAX_SESSION_AGE = 7200;

  /**
   * Paths a demo account never reaches, whatever its roles grant.
   *
   * Keyed by case-insensitive pattern; the value lists the denied HTTP
   * methods, NULL for all. DemoSessionSubscriber matches both the request path
   * and the path of the matched route, so a language prefix or another
   * spelling of the URL reaches the same verdict. Billing and tier, team and
   * invitations, workspace settings and user administration stay with real
   * accounts, also when a visitor calls the backend directly. The controllers
   * check their own access as well; this list keeps that true if the role set
   * grows.
   */
  private const DENIED_PATHS = [
    // Billing and tier (the Stripe checkout and portal routes of the Nuxt
    // server read and write through /api/fastmap/billing), usage, workspace
    // deletion, new workspaces, removal of the template content.
    '#^/api/fastmap/(billing|usage|workspace-deletion|create-workspace|claim-login-token|demo-content|report-stats)(/|$)#i' => NULL,
    // Member list, member edits, invitations.
    '#^/api/group-members(/|$)#i' => NULL,
    // Workspace settings.
    '#^/api/tenant-settings(/|$)#i' => NULL,
    '#^%jsonapi%/(group|group_relationship)/#i' => ['POST', 'PATCH', 'DELETE'],
    '#^/api/dashboard/status-selection(/|$)#i' => ['POST', 'PATCH', 'DELETE'],
    '#^/api/dashboard/mail-texts(/|$)#i' => NULL,
    '#^/api/platform/#i' => ['POST', 'PATCH', 'PUT', 'DELETE'],
    // Functions outside report work, closed independent of the role set:
    // metrics, AI and duplicates, moderation queue, mail inbox, exports,
    // original photos, forwarding to outside recipients.
    '#^/api/dashboard/(kpis|time-series|forwarding-details|hazards)(/|$)#i' => NULL,
    '#^/api/(ai|moderation|inbound-mail|escalation|delegation)(/|$)#i' => NULL,
    '#^/api/vision/originals(/|$)#i' => NULL,
    '#^/api/export-jurisdictions(/|$)#i' => NULL,
    // Platform and user administration.
    '#^/api/admin(/|$)#i' => NULL,
    '#^/admin(/|$)#i' => NULL,
    '#^/group/#i' => NULL,
    '#^/user/(\d+|\{[^}]+\})(/|$)#i' => NULL,
    '#^/user/(password|register)(/|$)#i' => NULL,
    '#^/node/add(/|$)#i' => NULL,
    // Citizen feedback saves any report it names; the demo has no reporter.
    '#^(/api)?/feedback(/|$)#i' => NULL,
    // The operator terms apply to real workspace owners only.
    '#^/api/accept-tos$#i' => NULL,
    '#^%jsonapi%/user/#i' => NULL,
    // The account itself: no switching, no other login from this session,
    // no handoff of the session. One visitor's language choice would apply
    // to every visitor.
    '#^/api/auth/(switch-|request-code|verify-code|session-handoff|claim-token)#i' => NULL,
    '#^/api/auth/preferences$#i' => ['PATCH'],
  ];


  /**
   * Workspace slug per user ID, FALSE for regular accounts.
   *
   * @var array<int, string|false>
   */
  private array $slugs = [];

  /**
   * User ID whose next login the endpoint started, NULL otherwise.
   */
  private ?int $expectedLogin = NULL;

  /**
   * Demo workspace group ID per slug.
   *
   * @var array<string, int|null>
   */
  private array $workspaceIds = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UserSessionRepositoryInterface $sessionRepository,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns the account name for a demo workspace slug.
   */
  public static function accountName(string $slug): string {
    return $slug . self::NAME_SUFFIX;
  }

  /**
   * Returns the mail address for a demo workspace slug.
   */
  public static function mail(string $slug): string {
    return self::accountName($slug) . '@' . self::MAIL_DOMAIN;
  }

  /**
   * Whether an address belongs to the reserved demo mail domain.
   */
  public static function isDemoMail(string $address): bool {
    // Accepts "Name <address>" as well as the bare address.
    if (preg_match('/<([^>]*)>/', $address, $matches)) {
      $address = $matches[1];
    }
    return str_ends_with(mb_strtolower(trim($address)), '@' . self::MAIL_DOMAIN);
  }

  /**
   * Derives the account UUID for a demo workspace slug.
   */
  public function uuid(string $slug): string {
    return DemoWorkspace::uuid((string) $this->configFactory->get('system.site')->get('uuid'), $slug, 'user', 'staff');
  }

  /**
   * Loads the staff account of a demo workspace.
   */
  public function load(string $slug): ?UserInterface {
    $accounts = $this->entityTypeManager->getStorage('user')->loadByProperties(['uuid' => $this->uuid($slug)]);
    $account = reset($accounts);
    return $account instanceof UserInterface && $account->getAccountName() === self::accountName($slug) ? $account : NULL;
  }

  /**
   * Returns the workspace slug of a demo account, NULL for any other account.
   */
  public function workspaceSlug(AccountInterface $account): ?string {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return NULL;
    }
    if (!isset($this->slugs[$uid])) {
      $this->slugs[$uid] = FALSE;
      // The name check is a cheap prefilter; the UUID decides.
      $name = (string) $account->getAccountName();
      $slug = substr($name, 0, -strlen(self::NAME_SUFFIX));
      if (str_ends_with($name, self::NAME_SUFFIX) && preg_match(DemoWorkspace::SLUG_PATTERN, $slug)) {
        $user = $this->entityTypeManager->getStorage('user')->load($uid);
        if ($user instanceof UserInterface && $user->uuid() === $this->uuid($slug)) {
          $this->slugs[$uid] = $slug;
        }
      }
    }
    return $this->slugs[$uid] ?: NULL;
  }

  /**
   * Whether an account is the staff account of a demo workspace.
   */
  public function isDemoAccount(AccountInterface $account): bool {
    return $this->workspaceSlug($account) !== NULL;
  }

  /**
   * Logs the current request in as a demo account.
   *
   * @param \Drupal\user\UserInterface $account
   *   The demo account.
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   The request session.
   */
  public function startSession(UserInterface $account, SessionInterface $session): void {
    $slug = $this->workspaceSlug($account);
    if ($slug === NULL) {
      throw new \LogicException('Only demo accounts can start a demo session.');
    }
    $this->expectedLogin = (int) $account->id();
    try {
      user_login_finalize($account);
    }
    finally {
      $this->expectedLogin = NULL;
    }
    $session->set(self::SESSION_KEY, [
      'uid' => (int) $account->id(),
      'slug' => $slug,
      'started' => $this->time->getRequestTime(),
    ]);
  }

  /**
   * Whether the login of this account was started by startSession().
   */
  public function isExpectedLogin(AccountInterface $account): bool {
    return $this->expectedLogin !== NULL && $this->expectedLogin === (int) $account->id();
  }

  /**
   * Whether a demo account's session is no longer valid.
   *
   * Valid means: started by the endpoint for this account, and younger than
   * MAX_SESSION_AGE.
   */
  public function isSessionExpired(AccountInterface $account, SessionInterface $session): bool {
    $marker = $session->get(self::SESSION_KEY);
    if (!is_array($marker) || ($marker['uid'] ?? NULL) !== (int) $account->id() || !is_int($marker['started'] ?? NULL)) {
      return TRUE;
    }
    return $this->time->getRequestTime() - $marker['started'] >= self::MAX_SESSION_AGE;
  }

  /**
   * Whether a demo account is denied a request path and method.
   *
   * JSON:API rules follow the configured base path (jsonapi.base_path).
   */
  public static function isDeniedPath(string $path, string $method, string $jsonApiBasePath = '/jsonapi'): bool {
    $jsonApiBasePath = preg_quote(rtrim($jsonApiBasePath, '/'), '#');
    foreach (self::DENIED_PATHS as $pattern => $methods) {
      $pattern = str_replace('%jsonapi%', $jsonApiBasePath, $pattern);
      if (preg_match($pattern, $path) && ($methods === NULL || in_array(strtoupper($method), $methods, TRUE))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns the group ID of the demo workspace a demo account belongs to.
   */
  public function workspaceId(AccountInterface $account): ?int {
    $slug = $this->workspaceSlug($account);
    if ($slug === NULL) {
      return NULL;
    }
    if (!array_key_exists($slug, $this->workspaceIds)) {
      $ids = $this->entityTypeManager->getStorage('group')->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_slug', $slug)
        ->range(0, 2)
        ->execute();
      // A slug held by two groups is ambiguous; trust neither.
      $this->workspaceIds[$slug] = count($ids) === 1 ? (int) reset($ids) : NULL;
    }
    return $this->workspaceIds[$slug];
  }

  /**
   * Whether a jurisdiction reference (ID or slug) names the demo workspace.
   */
  public function isOwnWorkspace(AccountInterface $account, mixed $reference): bool {
    $id = $this->workspaceId($account);
    if ($id === NULL || !(is_string($reference) || is_int($reference))) {
      return FALSE;
    }
    $reference = trim((string) $reference);
    return $reference === (string) $id || $reference === $this->workspaceSlug($account);
  }

  /**
   * Returns the JSON:API base path of the site.
   */
  public static function jsonApiBasePath(): string {
    $container = \Drupal::getContainer();
    return $container->hasParameter('jsonapi.base_path') ? (string) $container->getParameter('jsonapi.base_path') : '/jsonapi';
  }

  /**
   * Ends every session of a demo account.
   *
   * Core does the same when the password changes, which every seed run does;
   * the reset calls this explicitly so it does not depend on that.
   */
  public function deleteSessions(UserInterface $account): void {
    $this->sessionRepository->deleteAll((int) $account->id());
  }

}
