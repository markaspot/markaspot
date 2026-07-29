<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Service\EnterpriseFeatureGate;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;

/**
 * Generic _custom_access check that gates routes behind an enterprise tier.
 *
 * Unlike FeatureFlagAccessCheck, which trusts the tenant-writable
 * field_nuxt_config JSON, this check derives the decision from platform mode
 * and, on the self-service platform, the jurisdiction's field_tier value. A
 * tenant cannot elevate their own access by editing field_nuxt_config, because
 * that value never enters this decision. See EnterpriseFeatureGate.
 *
 * These enterprise-gated admin routes (e.g. the mail-text editor) have no
 * per-request jurisdiction parameter: the config they guard (such as
 * markaspot_mail.texts) is a single object shared by every jurisdiction in
 * this Drupal instance, matching the platform's topology-per-tenant model
 * (one paying workspace = one Drupal install; multiple 'jur' groups within
 * an install are departments/hierarchy of that one workspace, not separate
 * customers). The gate is therefore evaluated against the installation's
 * own root jurisdiction rather than a request-supplied one.
 *
 * Usage in routing.yml:
 * @code
 * markaspot_dashboard.mail_texts_catalog:
 *   path: '/api/dashboard/mail-texts'
 *   defaults:
 *     _controller: '...'
 *   requirements:
 *     _permission: 'administer markaspot mail texts'
 *     _custom_access: 'markaspot_nuxt.enterprise_feature_access_check:check'
 *   options:
 *     _enterprise_feature: 'mail_text_editor'
 * @endcode
 *
 * _permission and _custom_access on the same route are combined with AND
 * by Drupal's access manager: both must allow.
 */
final class EnterpriseFeatureAccessCheck implements AccessInterface, ContainerInjectionInterface {

  public function __construct(
    private readonly EnterpriseFeatureGate $enterpriseFeatureGate,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_nuxt.enterprise_feature_gate'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->has('markaspot_group.hierarchy_resolver')
        ? $container->get('markaspot_group.hierarchy_resolver')
        : NULL,
    );
  }

  /**
   * Checks access for the incoming request against the route's tier gate.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route being matched.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account. Unused: the gate depends only on the
   *   installation's jurisdiction tier, not on who is asking. Permission
   *   checks (who may reach the route at all) stay on the route's
   *   `_permission` requirement.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed when the installation's root jurisdiction is on an enterprise
   *   tier (or self-hosted), forbidden otherwise.
   */
  public function check(Route $route, AccountInterface $account): AccessResultInterface {
    $feature = $route->getOption('_enterprise_feature');
    if (!is_string($feature) || $feature === '') {
      // A route that invokes this service without declaring a feature is a
      // configuration error, not a user-facing access denial. Fail closed
      // to surface the misconfiguration loudly.
      return AccessResult::forbidden('Route missing _enterprise_feature option.');
    }

    $group = $this->resolveInstallationJurisdiction();
    $allowed = $this->enterpriseFeatureGate->isEnterpriseFeatureAllowed($group, $feature);

    $result = $allowed
      ? AccessResult::allowed()
      : AccessResult::forbidden(sprintf('Enterprise feature "%s" is not available on this jurisdiction\'s tier.', $feature));

    if ($group instanceof GroupInterface) {
      $result->addCacheableDependency($group);
    }

    // The tier can change at any time (Stripe webhook, admin edit), so the
    // access decision itself must not be cached. Mirrors
    // FeatureFlagAccessCheck's setCacheMaxAge(0) discipline.
    return $result->setCacheMaxAge(0);
  }

  /**
   * Resolves the jurisdiction whose tier gates this Drupal installation.
   *
   * Loads the first published jurisdiction group (lowest ID, same default
   * used by MarkASpotSettingsController::getMarkASpotSettings() when no
   * jurisdiction is specified), then walks up to its root via the
   * hierarchy resolver. field_tier is set on the root of a workspace;
   * department/child jurisdictions inherit it the same way they inherit
   * the service catalog.
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The root jurisdiction group, or NULL if none could be resolved.
   */
  private function resolveInstallationJurisdiction(): ?GroupInterface {
    $jurType = $this->configFactory->get('markaspot_open311.settings')->get('jurisdiction_group_type') ?: 'jur';
    $storage = $this->entityTypeManager->getStorage('group');

    $groupIds = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $jurType)
      ->condition('status', 1)
      ->sort('id', 'ASC')
      ->range(0, 1)
      ->execute();
    if (empty($groupIds)) {
      return NULL;
    }

    $group = $storage->load(reset($groupIds));
    if (!$group instanceof GroupInterface) {
      return NULL;
    }

    if ($this->hierarchyResolver) {
      $rootId = $this->hierarchyResolver->getRootJurisdictionId((int) $group->id());
      if ($rootId !== NULL && $rootId !== (int) $group->id()) {
        $root = $storage->load($rootId);
        if ($root instanceof GroupInterface) {
          return $root;
        }
      }
    }

    return $group;
  }

}
