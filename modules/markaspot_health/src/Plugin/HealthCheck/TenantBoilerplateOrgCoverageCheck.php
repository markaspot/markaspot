<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects child orgs that cannot resolve a boilerplate response template.
 *
 * Tenants compose their boilerplate library from two layers:
 * - jur-wide: node:boilerplate with field_organisation empty, scoped only by
 *   field_jurisdiction. Used as the fallback for every child org.
 * - org-scoped: node:boilerplate with field_organisation set, used when the
 *   responding org has a department-specific phrasing.
 *
 * For each jurisdiction's child org we assert at least one of the two layers
 * is reachable. Severity stays at warning because a missing boilerplate is
 * an editorial gap, not a runtime failure — Drupal renders the empty body
 * and ECA proceeds.
 *
 * @HealthCheck(
 *   id = "tenant_boilerplate_org_coverage",
 *   label = @Translation("Orgs without resolvable boilerplate"),
 *   severity = "warning",
 *   description = @Translation("Counts child orgs whose responding department has neither a jurisdiction-wide nor an org-specific boilerplate template."),
 *   fix_hint = @Translation("Either create a jur-wide node:boilerplate (field_organisation empty) covering every org, or create an org-scoped boilerplate per affected department."),
 *   fix_url = "/admin/content?type=boilerplate",
 * )
 */
class TenantBoilerplateOrgCoverageCheck extends HealthCheckPluginBase {

  private const DETAILS_LIMIT = 50;

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
    if (!$this->entityTypeManager->hasDefinition('group') || !$this->entityTypeManager->hasDefinition('node')) {
      return $this->pass('group or node entity type not available; check skipped.');
    }
    $bundles = (array) $this->entityTypeManager->getStorage('node_type')
      ->getQuery()->accessCheck(FALSE)->execute();
    if (!in_array('boilerplate', $bundles, TRUE)) {
      return $this->pass('node:boilerplate bundle not present; check skipped.');
    }

    $jurisdictions = $this->loadJurisdictions($context);
    if ($jurisdictions === []) {
      return $this->pass('No jurisdictions present; check skipped.');
    }

    $details = [];
    $unresolvableCount = 0;
    foreach ($jurisdictions as $jur) {
      $jurId = (int) $jur->id();
      $orgs = $this->loadChildOrgs($jurId);
      if ($orgs === []) {
        continue;
      }
      $hasJurWideFallback = $this->hasJurWideBoilerplate($jurId);
      $orgsWithOwn = $this->orgsWithOwnBoilerplate($jurId);
      foreach ($orgs as $org) {
        $orgId = (int) $org->id();
        $hasOwn = in_array($orgId, $orgsWithOwn, TRUE);
        if ($hasOwn || $hasJurWideFallback) {
          continue;
        }
        $unresolvableCount++;
        if (count($details) < self::DETAILS_LIMIT) {
          $details[] = [
            'jurisdiction' => $jurId,
            'jur_label' => (string) $jur->label(),
            'org' => $orgId,
            'org_label' => (string) $org->label(),
            'has_org_specific' => FALSE,
            'has_jur_fallback' => FALSE,
          ];
        }
      }
    }

    if ($unresolvableCount === 0) {
      return $this->pass('Every child org resolves a boilerplate via own template or jurisdiction fallback.');
    }

    return $this->fail(
      $unresolvableCount,
      sprintf(
        '%d child org(s) cannot resolve a boilerplate (no jur-wide fallback and no org-scoped template).',
        $unresolvableCount,
      ),
      $details,
      max(0, $unresolvableCount - count($details)),
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
   * Loads child orgs whose field_jurisdiction matches the given jur id.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Org groups with field_jurisdiction targeting $jurisdictionId.
   */
  protected function loadChildOrgs(int $jurisdictionId): array {
    $storage = $this->entityTypeManager->getStorage('group');
    $fieldDefs = $this->entityTypeManager->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'group',
        'bundle' => 'org',
        'field_name' => 'field_jurisdiction',
      ]);
    if ($fieldDefs === []) {
      return [];
    }
    $ids = (array) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'org')
      ->condition('field_jurisdiction', $jurisdictionId)
      ->execute();
    return $ids === [] ? [] : $storage->loadMultiple($ids);
  }

  /**
   * TRUE when a published jur-wide boilerplate exists for the jurisdiction.
   */
  protected function hasJurWideBoilerplate(int $jurisdictionId): bool {
    // Only published boilerplates count — an unpublished draft does not
    // resolve at runtime when ECA looks up the response template.
    $count = (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'boilerplate')
      ->condition('status', 1)
      ->condition('field_jurisdiction', $jurisdictionId)
      ->notExists('field_organisation')
      ->count()
      ->execute();
    return $count > 0;
  }

  /**
   * Returns the set of org ids that have at least one org-scoped boilerplate.
   *
   * @return int[]
   *   Deduplicated org group ids referenced by org-scoped boilerplates.
   */
  protected function orgsWithOwnBoilerplate(int $jurisdictionId): array {
    $nids = (array) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'boilerplate')
      ->condition('status', 1)
      ->condition('field_jurisdiction', $jurisdictionId)
      ->exists('field_organisation')
      ->execute();
    if ($nids === []) {
      return [];
    }
    $orgIds = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($nids) as $node) {
      foreach ($node->get('field_organisation') as $item) {
        if (!empty($item->target_id)) {
          $orgIds[] = (int) $item->target_id;
        }
      }
    }
    return array_values(array_unique($orgIds));
  }

}
