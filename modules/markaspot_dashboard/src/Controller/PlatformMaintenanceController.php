<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes the global Drupal maintenance switch to the platform dashboard.
 *
 * The status endpoint is deliberately public and contains only the boolean
 * state needed for a frontend maintenance page. State changes remain limited
 * to uid 1 and the site-wide administrator role. Jurisdiction memberships,
 * including tenant_admin, never grant this installation-wide capability.
 */
final class PlatformMaintenanceController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly StateInterface $state,
    private readonly AccountInterface $currentAccount,
    private readonly LoggerChannelInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('state'),
      $container->get('current_user'),
      $container->get('logger.channel.markaspot_dashboard'),
    );
  }

  /**
   * GET /api/platform/maintenance.
   *
   * Public frontend callers only need to know whether they should render the
   * maintenance experience. Do not add operator, tenant, or timing data here.
   */
  public function status(): JsonResponse {
    return $this->noStoreResponse([
      'maintenance' => (bool) $this->state->get('system.maintenance_mode', FALSE),
    ]);
  }

  /**
   * GET /api/platform/maintenance/access.
   *
   * The frontend uses this minimal endpoint to decide whether its current
   * session may operate during a Core maintenance window. Do not expose roles,
   * user identity, or any other session details from this central route.
   */
  public function accessStatus(): JsonResponse {
    return $this->noStoreResponse([
      'maintenance_access' => $this->currentAccount->isAuthenticated()
        && $this->currentAccount->hasPermission('access site in maintenance mode'),
    ]);
  }

  /**
   * PATCH /api/platform/maintenance.
   *
   * The route supplies both cookie-session authentication and the CSRF header
   * requirement. This method additionally keeps the JSON shape strict so a
   * future dashboard field cannot silently become a global state mutation.
   */
  public function update(Request $request): JsonResponse {
    try {
      $payload = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $this->noStoreResponse([
        'error' => 'Request body must be valid JSON.',
      ], Response::HTTP_BAD_REQUEST);
    }

    if (!is_array($payload)
      || array_keys($payload) !== ['maintenance']
      || !is_bool($payload['maintenance'])) {
      return $this->noStoreResponse([
        'error' => 'Request body must contain only a boolean maintenance field.',
      ], Response::HTTP_BAD_REQUEST);
    }

    $maintenance = $payload['maintenance'];
    $this->state->set('system.maintenance_mode', $maintenance);
    $this->logger->notice(
      'Global maintenance mode changed to @mode by uid @uid.',
      [
        '@mode' => $maintenance ? 'enabled' : 'disabled',
        '@uid' => (int) $this->currentAccount->id(),
      ],
    );

    return $this->noStoreResponse([
      'maintenance' => $maintenance,
    ]);
  }

  /**
   * Restricts global maintenance changes to platform administrators.
   *
   * Drupal's `access platform admin` permission is useful for UI navigation,
   * but this route deliberately checks the actual platform identity. A
   * jurisdiction role must not gain an installation-wide shutdown capability
   * merely because somebody later grants it a dashboard permission.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    $allowed = (int) $account->id() === 1
      || in_array('administrator', $account->getRoles(), TRUE);

    return AccessResult::allowedIf($allowed)
      // This is a state-changing endpoint. An uncacheable access decision is
      // intentional and also applies immediately after a role change.
      ->setCacheMaxAge(0);
  }

  /**
   * Returns an explicitly uncacheable JSON response.
   *
   * State API values do not provide cache tags. A stale maintenance status is
   * operationally worse than one extra tiny request, so never cache it.
   *
   * @param array<string, bool|string> $payload
   *   The response data.
   * @param int $status
   *   The HTTP status code.
   */
  private function noStoreResponse(array $payload, int $status = Response::HTTP_OK): JsonResponse {
    $response = new JsonResponse($payload, $status);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
