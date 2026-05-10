<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects jurisdictions with no taxonomy_term:service_category attached.
 *
 * A jur without categories cannot accept a categorised report submission —
 * the GeoReport endpoint rejects POSTs whose service_code maps to a term
 * outside the tenant's allowed list. Upstream of `eca_tid_mismatch`: a
 * tenant with no categories cannot have mismatched tids in the first
 * place.
 *
 * Two relationship sources are checked because the profile uses both:
 * - field_jurisdiction ON THE TERM (canonical, used by
 *   GeoreportProcessorService::mapServiceCodeToTaxonomy)
 * - field_service_categories on the jur group (forward-looking, populated
 *   on newer tenants)
 *
 * Pass when at least one source resolves to ≥1 term for the tenant.
 *
 * Severity `error`: misconfigured tenants fail citizen submissions silently
 * from end-user perspective.
 *
 * @HealthCheck(
 *   id = "tenant_service_categories_assigned",
 *   label = @Translation("Tenants without service categories"),
 *   severity = "error",
 *   description = @Translation("Asserts every jurisdiction has at least one taxonomy_term:service_category attached, via field_jurisdiction on the term or field_service_categories on the group."),
 *   fix_hint = @Translation("Open the jurisdiction edit form and assign at least one service_category — or back-fill field_jurisdiction on existing terms via a seed script."),
 * )
 */
class TenantServiceCategoriesAssignedCheck extends HealthCheckPluginBase {

  private const DETAILS_LIMIT = 25;

  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->entityTypeManager->hasDefinition('group')) {
      return $this->pass('group entity type not available; check skipped.');
    }

    $jurisdictions = $this->loadJurisdictions($context);
    if ($jurisdictions === []) {
      return $this->pass('No jurisdictions present; check skipped.');
    }

    $errorDetails = [];
    $warnDetails = [];
    $errorCount = 0;
    $warnCount = 0;
    foreach ($jurisdictions as $jur) {
      $jurId = (int) $jur->id();
      $termSide = $this->countTermsByJurisdiction($jurId);
      $groupSide = $this->countGroupSideAssignments($jur);
      if ($termSide > 0) {
        continue;
      }
      $detail = [
        'jurisdiction' => $jurId,
        'jur_label' => (string) $jur->label(),
        'term_side_count' => $termSide,
        'group_side_count' => $groupSide,
      ];
      if ($groupSide > 0) {
        // Group-side-only assignment is not submission-safe: GeoReport's
        // mapServiceCodeToTaxonomy() resolves via field_jurisdiction on
        // the term, so a tenant with categories listed only on the group
        // still 404s on every categorised POST. Warn instead of pass to
        // surface the gap without escalating to a release-blocking error.
        $warnCount++;
        if (count($warnDetails) < self::DETAILS_LIMIT) {
          $warnDetails[] = $detail;
        }
        continue;
      }
      $errorCount++;
      if (count($errorDetails) < self::DETAILS_LIMIT) {
        $errorDetails[] = $detail;
      }
    }

    if ($errorCount === 0 && $warnCount === 0) {
      return $this->pass('Every jurisdiction has at least one service_category term targeting it via field_jurisdiction.');
    }

    if ($errorCount > 0) {
      // Count reflects error-severity items only — the SmokeCommands CI
      // gate counts `failed() && severity==='error'` results as 1 each,
      // and the `count` field is the per-result tally consumed by
      // dashboards. Warnings travel via evidence + tagged details so a
      // mixed result does not silently promote group-side-only rows to
      // error.
      $details = array_merge(
        array_map(static fn(array $d) => $d + ['severity' => 'error'], $errorDetails),
        array_map(static fn(array $d) => $d + ['severity' => 'warning'], $warnDetails),
      );
      $truncated = ($errorCount + $warnCount) - count($details);
      return $this->failWithSeverity(
        'error',
        $errorCount,
        sprintf(
          '%d jurisdiction(s) without categorised submission coverage (plus %d warning(s) for group-side-only assignments).',
          $errorCount,
          $warnCount,
        ),
        $details,
        max(0, $truncated),
        $context['jurisdiction'] ?? NULL,
      );
    }

    // Only group-side-only entries → degrade to warning severity.
    return $this->failWithSeverity(
      'warning',
      $warnCount,
      sprintf(
        '%d jurisdiction(s) carry service categories on the group side only; Open311 mapping needs field_jurisdiction on the term too.',
        $warnCount,
      ),
      array_map(static fn(array $d) => $d + ['severity' => 'warning'], $warnDetails),
      max(0, $warnCount - count($warnDetails)),
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
   * Counts service_category terms whose field_jurisdiction targets this jur.
   *
   * Mirrors GeoreportProcessorService::mapServiceCodeToTaxonomy(); the
   * canonical relation in the running profile.
   */
  protected function countTermsByJurisdiction(int $jurisdictionId): int {
    if (!$this->entityTypeManager->hasDefinition('taxonomy_term')) {
      return 0;
    }
    $fieldDefs = $this->entityTypeManager->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'taxonomy_term',
        'bundle' => 'service_category',
        'field_name' => 'field_jurisdiction',
      ]);
    if ($fieldDefs === []) {
      return 0;
    }
    return (int) $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'service_category')
      ->condition('field_jurisdiction', $jurisdictionId)
      ->count()
      ->execute();
  }

  /**
   * Counts the jur's own field_service_categories references (forward-looking).
   */
  protected function countGroupSideAssignments($jurisdiction): int {
    if (!$jurisdiction->hasField('field_service_categories')) {
      return 0;
    }
    return $jurisdiction->get('field_service_categories')->count();
  }

}
