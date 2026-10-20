<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Demo\DemoWorkspace;
use Drupal\markaspot_fastmap\Service\DemoStaffAccount;
use Drupal\markaspot_fastmap\Service\DemoWorkspaceSeeder;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Logs a visitor in as the shared staff account of a template demo.
 *
 * POST /api/fastmap/demo-session with {"workspace": "<slug>"}. The workspace
 * must be allowlisted in markaspot_fastmap.settings:demo_workspaces, carry
 * the demo block and have its seeded staff account; every other slug gets
 * the same 404. A real session is never replaced.
 *
 * Cross-site requests: Core's CSRF header check on the route only applies
 * to authenticated sessions. For anonymous visitors the JSON content type
 * is the guard: a cross-site form cannot send it, and a cross-site fetch
 * with it needs a CORS preflight.
 */
final class DemoSessionController implements ContainerInjectionInterface {

  /**
   * Flood event of new demo sessions, counted per client IP.
   */
  public const FLOOD_EVENT = 'markaspot_fastmap.demo_session';

  /**
   * New demo sessions allowed per client IP and window.
   */
  public const FLOOD_THRESHOLD = 20;

  /**
   * Flood window in seconds.
   */
  public const FLOOD_WINDOW = 3600;

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FloodInterface $flood,
    private readonly DemoWorkspaceSeeder $seeder,
    private readonly DemoStaffAccount $staffAccount,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('flood'),
      $container->get('markaspot_fastmap.demo_workspace_seeder'),
      $container->get('markaspot_fastmap.demo_staff_account'),
      $container->get('logger.channel.markaspot_fastmap'),
    );
  }

  /**
   * Starts a demo staff session.
   */
  public function start(Request $request): JsonResponse {
    if (!preg_match('#^application/(vnd\.api\+)?json\s*(;|$)#i', (string) $request->headers->get('Content-Type'))) {
      return new JsonResponse(['error' => 'Content-Type must be application/json.'], 415);
    }
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data) || !is_string($data['workspace'] ?? NULL)) {
      return new JsonResponse(['error' => 'Body must be {"workspace": "<slug>"}.'], 400);
    }
    $slug = $data['workspace'];

    $account = $this->resolveAccount($slug);
    if ($account === NULL) {
      return new JsonResponse(['error' => 'Demo workspace not found.'], 404);
    }
    $response = ['ok' => TRUE, 'dashboard' => '/' . $slug . '/dashboard'];

    if ($this->currentUser->isAuthenticated()) {
      if ((int) $this->currentUser->id() === (int) $account->id()) {
        return new JsonResponse($response);
      }
      return new JsonResponse(['error' => 'Log out first to explore the demo.'], 409);
    }

    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_THRESHOLD, self::FLOOD_WINDOW)) {
      return new JsonResponse(['error' => 'Too many demo sessions. Please try again later.'], 429, [
        'Retry-After' => (string) self::FLOOD_WINDOW,
      ]);
    }
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW);

    $this->staffAccount->startSession($account, $request->getSession());
    $this->logger->notice('Demo session started for workspace @slug (account @uid).', [
      '@slug' => $slug,
      '@uid' => $account->id(),
    ]);
    return new JsonResponse($response);
  }

  /**
   * Returns the staff account of a published demo workspace.
   *
   * @return \Drupal\user\UserInterface|null
   *   The active staff account with its membership, NULL when the slug is
   *   not allowlisted, the workspace is no seeded demo or has no account.
   */
  private function resolveAccount(string $slug): ?UserInterface {
    $allowlist = $this->configFactory->get('markaspot_fastmap.settings')->get('demo_workspaces');
    if (!preg_match(DemoWorkspace::SLUG_PATTERN, $slug) || !is_array($allowlist) || !in_array($slug, $allowlist, TRUE)) {
      return NULL;
    }
    try {
      $group = $this->seeder->findWorkspace($slug);
    }
    catch (\RuntimeException) {
      return NULL;
    }
    if (!$group instanceof GroupInterface || !DemoWorkspace::isDemo($group)) {
      return NULL;
    }
    $account = $this->staffAccount->load($slug);
    if ($account === NULL || !$account->isActive() || !$group->getMember($account)) {
      return NULL;
    }
    return $account;
  }

}
