<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves authenticated CSV export scope without using the public catalog.
 */
class ExportJurisdictionsController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * Explicit export roles, matching the authenticated frontend export policy.
   */
  private const ELEVATED_ROLES = [
    'jur-admin',
    'jur-editorial',
    'jur-moderator',
    'jur-tenant_admin',
  ];

  /**
   * Constructs the export scope controller.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $manager,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    private readonly AccountInterface $account,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      // This resolver rejects invalid parent chains instead of assuming self.
      $container->get('markaspot_group.organisation_hierarchy_resolver'),
      $container->get('current_user'),
      $container->get('config.factory'),
    );
  }

  /**
   * Returns only the requested descendants allowed by explicit memberships.
   */
  public function getScope(Request $request): JsonResponse {
    if (!$this->account->isAuthenticated()) {
      return $this->response(['error' => 'Authentication required.'], 403);
    }
    $raw = $request->query->all()['roots'] ?? NULL;
    if (!is_string($raw) || strlen($raw) > 2048
      || !preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $raw)) {
      return $this->response(['error' => 'Provide positive jurisdiction IDs.'], 400);
    }
    $roots = [];
    foreach (explode(',', $raw) as $value) {
      $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
      if ($id === FALSE) {
        return $this->response(['error' => 'Invalid jurisdiction ID.'], 400);
      }
      $roots[$id] = $id;
    }
    if (count($roots) > 100) {
      return $this->response(['error' => 'Too many jurisdictions requested.'], 400);
    }
    $roots = array_values($roots);
    sort($roots, SORT_NUMERIC);

    $global = (int) $this->account->id() === 1
      || array_intersect(['administrator', 'editorial_board'], $this->account->getRoles()) !== [];
    $accessible = $global ? [] : $this->accessibleJurisdictionIds();
    if (!$global && array_diff($roots, $accessible) !== []) {
      return $this->response(['error' => 'Export jurisdiction access denied.'], 403);
    }

    $ids = [];
    foreach ($roots as $root) {
      $group = $this->manager->getStorage('group')->load($root);
      if (!$group instanceof GroupInterface || !$this->isJurisdictionGroup($group)) {
        return $this->response(['error' => 'Export jurisdiction hierarchy unavailable.'], 503);
      }
      $descendants = $this->hierarchyResolver->getScopeJurisdictionIds($root);
      if ($descendants === [] || !in_array($root, $descendants, TRUE)) {
        return $this->response(['error' => 'Export jurisdiction hierarchy unavailable.'], 503);
      }
      // The shared resolver caps traversal depth and can return a partial
      // subtree. Detect omitted child edges before accepting it for export.
      if ($group->hasField('field_parent_jurisdiction')) {
        $omitted = $this->manager->getStorage('group')->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', $this->getJurisdictionGroupType())
          ->condition('field_parent_jurisdiction', $descendants, 'IN')
          ->condition('id', $descendants, 'NOT IN')
          ->range(0, 1)->execute();
        if ($omitted !== []) {
          return $this->response(['error' => 'Export jurisdiction hierarchy unavailable.'], 503);
        }
      }
      // Hierarchy is a filter, never a grant. Even a root manager retains
      // only the explicit jurisdiction scope from authenticated memberships.
      $ids = array_merge($ids, $global ? $descendants : array_intersect($descendants, $accessible));
    }
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_NUMERIC);

    // Categories and attribute definitions belong to the hierarchy root.
    // Expose this metadata separately: an authorised child does not thereby
    // gain report access to its root or siblings when settings are hidden.
    $taxonomy_roots = [];
    foreach ($ids as $id) {
      $taxonomy_root = $this->hierarchyResolver->getRootJurisdictionId($id);
      if ($taxonomy_root === NULL || $taxonomy_root <= 0) {
        return $this->response(['error' => 'Export jurisdiction hierarchy unavailable.'], 503);
      }
      $taxonomy_roots[] = ['jurisdictionId' => $id, 'rootId' => $taxonomy_root];
    }

    $covers_all = FALSE;
    if ($global) {
      // Include inactive jurisdictions in the completeness proof. A public
      // routing catalog cannot establish that omitting a filter is safe.
      $all_ids = array_map('intval', array_values($this->manager->getStorage('group')->getQuery()
        ->accessCheck(FALSE)->condition('type', $this->getJurisdictionGroupType())->execute()));
      sort($all_ids, SORT_NUMERIC);
      $covers_all = $all_ids !== [] && $ids === $all_ids;
    }

    return $this->response([
      'requestedRootIds' => $roots,
      'jurisdictionIds' => $ids,
      'taxonomyRoots' => $taxonomy_roots,
      'coversAllJurisdictions' => $covers_all,
    ]);
  }

  /**
   * Gets the explicit elevated jurisdiction memberships for this account.
   *
   * @return int[]
   *   Jurisdiction IDs, without inherited or organisation-derived grants.
   */
  private function accessibleJurisdictionIds(): array {
    $ids = [];
    foreach ($this->loadMemberships() as $membership) {
      $group = $membership->getGroup();
      if (!$this->isJurisdictionGroup($group)) {
        continue;
      }
      foreach ($membership->getRoles() as $role) {
        if (in_array($this->canonicalizeJurisdictionRoleId($role->id()), self::ELEVATED_ROLES, TRUE)) {
          $ids[] = (int) $group->id();
          break;
        }
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Loads current memberships using Group's entity API.
   *
   * @return \Drupal\group\Entity\GroupMembershipInterface[]
   *   Memberships belonging to the authenticated account.
   */
  protected function loadMemberships(): array {
    return GroupMembership::loadByUser($this->account);
  }

  /**
   * Prevents shared or stale caching of authorization-derived scope.
   */
  private function response(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
  }

}
