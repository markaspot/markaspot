<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\markaspot_nuxt\Service\AlertStateStore;
use Drupal\markaspot_nuxt\TenantAlertInterface;
use Drupal\markaspot_nuxt\TenantAlertPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dashboard tenant alerts API controller.
 *
 * Exposes two endpoints:
 * - GET  /api/dashboard/alerts/{jurisdiction_id}
 *   Aggregates open alerts from every TenantAlert plugin, decorates each
 *   with the calling user's "handled" state from user.data, and returns a
 *   cacheable JSON response keyed on the user.
 * - PATCH /api/dashboard/alerts/{jurisdiction_id}/{alert_id}/state
 *   Toggles the per-user handled state for one alert and returns the
 *   updated alert object.
 *
 * Access mirrors TenantSettingsController::accessCheck verbatim: superadmin
 * (uid 1), Drupal administrator role, or jur-tenant_admin membership inside
 * the requested jurisdiction's hierarchy.
 */
class DashboardAlertsController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * The group membership loader.
   *
   * @var \Drupal\group\GroupMembershipLoaderInterface
   */
  protected GroupMembershipLoaderInterface $membershipLoader;

  /**
   * The jurisdiction hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface
   */
  protected JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * The tenant alert plugin manager.
   *
   * @var \Drupal\markaspot_nuxt\TenantAlertPluginManager
   */
  protected TenantAlertPluginManager $alertPluginManager;

  /**
   * The alert state store.
   *
   * @var \Drupal\markaspot_nuxt\Service\AlertStateStore
   */
  protected AlertStateStore $alertStateStore;

  /**
   * Constructs a DashboardAlertsController.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    GroupMembershipLoaderInterface $membership_loader,
    AccountInterface $current_user,
    JurisdictionHierarchyResolverInterface $hierarchy_resolver,
    TenantAlertPluginManager $alert_plugin_manager,
    AlertStateStore $alert_state_store,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->membershipLoader = $membership_loader;
    $this->currentUser = $current_user;
    $this->hierarchyResolver = $hierarchy_resolver;
    $this->alertPluginManager = $alert_plugin_manager;
    $this->alertStateStore = $alert_state_store;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('group.membership_loader'),
      $container->get('current_user'),
      $container->get('markaspot_group.hierarchy_resolver'),
      $container->get('plugin.manager.markaspot_tenant_alert'),
      $container->get('markaspot_nuxt.alert_state_store'),
    );
  }

  /**
   * Access check for the dashboard alert endpoints.
   *
   * Mirrors TenantSettingsController::accessCheck so the alert surface
   * inherits the same trust boundary as the settings it points to. Any
   * future divergence here would be a security regression.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier from the route (numeric ID or slug).
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function accessCheck(AccountInterface $account, string $jurisdiction_id): AccessResultInterface {
    $resolved_id = $this->resolveJurisdictionId($jurisdiction_id);
    if ($resolved_id === NULL) {
      return AccessResult::forbidden('Jurisdiction not found.')
        ->addCacheContexts(['url.path']);
    }

    if ((int) $account->id() === 1) {
      return AccessResult::allowed()->addCacheContexts(['user']);
    }

    if (in_array('administrator', $account->getRoles(), TRUE)) {
      return AccessResult::allowed()->addCacheContexts(['user.roles']);
    }

    if (in_array('tenant_admin', $account->getRoles(), TRUE)) {
      $memberships = $this->membershipLoader->loadByUser(
        $account,
        $this->jurisdictionRoleIds('tenant_admin'),
      );
      foreach ($memberships as $membership) {
        $managed_group = $membership->getGroup();
        if (!$this->isJurisdictionGroup($managed_group)) {
          continue;
        }
        $managed_id = (int) $managed_group->id();
        $scope = $this->hierarchyResolver->getDescendantIds($managed_id);
        if (in_array($resolved_id, $scope, TRUE)) {
          return AccessResult::allowed()->addCacheContexts(['user']);
        }
      }
    }

    return AccessResult::forbidden('User is not an administrator or tenant admin for this jurisdiction.')
      ->addCacheContexts(['user', 'user.roles']);
  }

  /**
   * Lists open alerts for the calling user in a jurisdiction.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   JSON envelope with `alerts` and a `summary` block.
   */
  public function list(Request $request, string $jurisdiction_id): CacheableJsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group instanceof GroupInterface) {
      $response = new CacheableJsonResponse(['error' => 'Jurisdiction not found.'], 404);
      $response->getCacheableMetadata()->addCacheContexts(['url.path']);
      return $response;
    }

    $uid = (int) $this->currentUser->id();
    $jurisdiction_gid = (int) $group->id();
    $alerts = [];
    $open = 0;
    $handled = 0;

    foreach ($this->alertPluginManager->getDefinitions() as $plugin_id => $definition) {
      try {
        $plugin = $this->alertPluginManager->createInstance($plugin_id);
      }
      catch (\Exception $e) {
        $this->getLogger('markaspot_nuxt')->error(
          'TenantAlert plugin @id failed to instantiate: @msg',
          ['@id' => $plugin_id, '@msg' => $e->getMessage()],
        );
        continue;
      }
      if (!$plugin instanceof TenantAlertInterface) {
        continue;
      }
      try {
        $payload = $plugin->check($group, $this->currentUser);
      }
      catch (\Exception $e) {
        $this->getLogger('markaspot_nuxt')->error(
          'TenantAlert plugin @id check() failed: @msg',
          ['@id' => $plugin_id, '@msg' => $e->getMessage()],
        );
        continue;
      }
      if ($payload === NULL) {
        continue;
      }

      $alert = $this->decorate($payload, $uid, $jurisdiction_gid);
      $alerts[] = $alert;
      if ($alert['handled']) {
        $handled++;
      }
      else {
        $open++;
      }
    }

    $body = [
      'alerts' => $alerts,
      'summary' => [
        'open' => $open,
        'handled' => $handled,
        'total' => $open + $handled,
      ],
    ];

    $response = new CacheableJsonResponse($body);
    $cache = new CacheableMetadata();
    $cache->addCacheableDependency($group);
    $cache->addCacheTags(['user:' . $uid]);
    $cache->addCacheContexts(['user']);
    $response->addCacheableDependency($cache);
    return $response;
  }

  /**
   * Sets the handled state for one alert.
   *
   * Body: { "handled": true|false }. Returns the updated alert object,
   * re-evaluated against the live group state so the response is always
   * truthful (and so a stale handled flag for an alert that was just
   * resolved upstream is not echoed back to the client).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   * @param string $alert_id
   *   The TenantAlert plugin ID to mutate.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with the updated alert, or an error response.
   */
  public function setState(Request $request, string $jurisdiction_id, string $alert_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group instanceof GroupInterface) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $payload_in = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload_in) || !array_key_exists('handled', $payload_in) || !is_bool($payload_in['handled'])) {
      return new JsonResponse(['error' => 'Body must contain a boolean "handled" field.'], 400);
    }
    $handled = $payload_in['handled'];

    $definitions = $this->alertPluginManager->getDefinitions();
    if (!isset($definitions[$alert_id])) {
      return new JsonResponse(['error' => 'Unknown alert.'], 404);
    }

    try {
      $plugin = $this->alertPluginManager->createInstance($alert_id);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->error(
        'TenantAlert plugin @id failed to instantiate: @msg',
        ['@id' => $alert_id, '@msg' => $e->getMessage()],
      );
      return new JsonResponse(['error' => 'Alert plugin unavailable.'], 500);
    }
    if (!$plugin instanceof TenantAlertInterface) {
      return new JsonResponse(['error' => 'Invalid alert plugin.'], 500);
    }

    $uid = (int) $this->currentUser->id();
    $jurisdiction_gid = (int) $group->id();

    $this->alertStateStore->setHandled($uid, $alert_id, $jurisdiction_gid, $handled);
    Cache::invalidateTags(['user:' . $uid]);

    $payload = $plugin->check($group, $this->currentUser);
    if ($payload === NULL) {
      // Alert was resolved upstream (group state changed); echo a minimal
      // record so the client can drop it from the list without another GET.
      return new JsonResponse([
        'id' => $alert_id,
        'resolved' => TRUE,
      ]);
    }

    return new JsonResponse($this->decorate($payload, $uid, $jurisdiction_gid));
  }

  /**
   * Decorates a plugin payload with per-user handled state.
   *
   * @param array<string, mixed> $payload
   *   The payload returned by the plugin.
   * @param int $uid
   *   The calling user's ID.
   * @param int $jurisdiction_id
   *   The jurisdiction group ID.
   *
   * @return array<string, mixed>
   *   The decorated alert.
   */
  protected function decorate(array $payload, int $uid, int $jurisdiction_id): array {
    $alert_id = (string) ($payload['id'] ?? '');
    $is_handled = $alert_id !== ''
      && $this->alertStateStore->isHandled($uid, $alert_id, $jurisdiction_id);
    $handled_at = $alert_id !== ''
      ? $this->alertStateStore->getHandledAt($uid, $alert_id, $jurisdiction_id)
      : NULL;

    return $payload + [
      'handled' => $is_handled,
      'handled_at' => $handled_at,
    ];
  }

  /**
   * Loads a jurisdiction group entity from a slug or numeric ID.
   *
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The loaded group, or NULL when not found / wrong bundle.
   */
  protected function loadJurisdictionGroup(string $jurisdiction_id): ?GroupInterface {
    $resolved_id = $this->resolveJurisdictionId($jurisdiction_id);
    if ($resolved_id === NULL) {
      return NULL;
    }
    $group = $this->entityTypeManager()->getStorage('group')->load($resolved_id);
    if (!$this->isJurisdictionGroup($group)) {
      return NULL;
    }
    return $group;
  }

}
