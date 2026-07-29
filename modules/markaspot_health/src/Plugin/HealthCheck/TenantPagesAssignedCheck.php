<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects jurisdictions that have no node:page assigned.
 *
 * A `jur` group without any `node:page` referencing it cannot serve a legal
 * notice / imprint / privacy / contact page when Nuxt resolves
 * `<frontend>/<slug>/{imprint,privacy,...}`. The page-by-name mapping is
 * out of scope for this check (it would need a configurable expected list);
 * the threshold here is just "at least one page exists for the tenant".
 *
 * Severity is elevated from `warning` to `error` for tenants whose
 * `field_visibility` is `public`, because a public-facing tenant without
 * pages is a release-blocker — the storefront has no content. Internal /
 * draft / demo workspaces stay on warning.
 *
 * @HealthCheck(
 *   id = "tenant_pages_assigned",
 *   label = @Translation("Tenants without node:page"),
 *   severity = "warning",
 *   description = @Translation("Counts jurisdictions that have no published page node referencing them. Elevated to error for visibility=public tenants."),
 *   fix_hint = @Translation("Create at least one node:page assigned to each jurisdiction (or assign existing pages via field_jurisdiction). Public-facing tenants need imprint/privacy/contact at minimum."),
 *   fix_url = "/admin/content?type=page",
 * )
 */
class TenantPagesAssignedCheck extends HealthCheckPluginBase {

  /**
   * Detail rows truncate cap to keep payload bounded.
   */
  private const DETAILS_LIMIT = 25;

  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->entityTypeManager->hasDefinition('group') || !$this->entityTypeManager->hasDefinition('node')) {
      return $this->pass('group or node entity type not available; check skipped.');
    }

    $jurisdictions = $this->loadJurisdictions($context);
    if ($jurisdictions === []) {
      return $this->pass('No jurisdictions present; check skipped.');
    }

    $details = [];
    $count = 0;
    $errorSeverity = FALSE;
    foreach ($jurisdictions as $jur) {
      $jurId = (int) $jur->id();
      $pageCount = $this->countPagesForJurisdiction($jurId);
      if ($pageCount > 0) {
        continue;
      }
      $count++;
      $isPublic = $this->isPublicVisibility($jur);
      if ($isPublic) {
        $errorSeverity = TRUE;
      }
      if (count($details) < self::DETAILS_LIMIT) {
        $details[] = [
          'jurisdiction' => $jurId,
          'jur_label' => (string) $jur->label(),
          'visibility' => $isPublic ? 'public' : 'non_public',
          'page_count' => 0,
        ];
      }
    }

    if ($count === 0) {
      return $this->pass('Every jurisdiction has at least one node:page assigned.');
    }

    return $this->failWithSeverity(
      $errorSeverity ? 'error' : 'warning',
      $count,
      sprintf(
        '%d jurisdiction(s) without any node:page%s.',
        $count,
        $errorSeverity ? ' (at least one is visibility=public)' : '',
      ),
      $details,
      max(0, $count - count($details)),
      $context['jurisdiction'] ?? NULL,
    );
  }

  /**
   * Loads jurisdictions, scoped to context['jurisdiction'] when present.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Single-element array when context pinned a jurisdiction, otherwise
   *   every jur group.
   */
  protected function loadJurisdictions(array $context): array {
    $storage = $this->entityTypeManager->getStorage('group');
    $contextJur = $context['jurisdiction'] ?? NULL;
    if (is_int($contextJur) && $contextJur > 0) {
      $jur = $storage->load($contextJur);
      return $jur && $jur->bundle() === 'jur' ? [$jur] : [];
    }
    return $storage->loadByProperties(['type' => 'jur']);
  }

  /**
   * Counts published page nodes whose field_jurisdiction matches the gid.
   */
  protected function countPagesForJurisdiction(int $jurisdictionId): int {
    if (!$this->entityTypeManager->hasDefinition('node')) {
      return 0;
    }
    $bundles = (array) $this->entityTypeManager->getStorage('node_type')
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    if (!in_array('page', $bundles, TRUE)) {
      return 0;
    }
    $fieldDefs = $this->entityTypeManager->getStorage('field_config')
      ->loadByProperties(['entity_type' => 'node', 'bundle' => 'page', 'field_name' => 'field_jurisdiction']);
    if ($fieldDefs === []) {
      return 0;
    }
    // Only published pages count: a tenant whose imprint/privacy lives in
    // an unpublished draft is not "ready to go live", which is the whole
    // point of the editorial-readiness gate.
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'page')
      ->condition('status', 1)
      ->condition('field_jurisdiction', $jurisdictionId)
      ->count()
      ->execute();
  }

  /**
   * Reads the jurisdiction's field_visibility to decide severity elevation.
   */
  protected function isPublicVisibility($jurisdiction): bool {
    // Intentionally stricter than WorkspaceVisibilityService: treating empty
    // legacy values as public here would raise new missing-page alarms across
    // municipal production dashboards during the field ownership update.
    if (!$jurisdiction->hasField('field_visibility')) {
      return FALSE;
    }
    $value = (string) ($jurisdiction->get('field_visibility')->value ?? '');
    return $value === 'public';
  }

}
