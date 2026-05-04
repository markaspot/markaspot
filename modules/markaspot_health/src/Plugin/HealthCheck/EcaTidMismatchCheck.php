<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects ECA model conditions referencing taxonomy term IDs that do not exist.
 *
 * ECA conditions hard-code taxonomy term IDs in their value field. Status
 * term IDs are tenant-specific (e.g. bleckede=5/77/79, dorsten=15/30/37/14)
 * and migrating ECA models across tenants without remapping leaves
 * conditions pointing at non-existent terms. Symptom: ECAs silently never
 * fire, automation appears broken with no error log.
 *
 * Best-effort heuristic: scan every eca.model.* config for scalar integer
 * values stored under a key called "value" inside an entry whose plugin
 * id mentions "taxonomy" or whose path includes "field_status". Each
 * candidate tid is validated against existing taxonomy_term entities.
 *
 * @HealthCheck(
 *   id = "eca_tid_mismatch",
 *   label = @Translation("ECA conditions referencing missing taxonomy IDs"),
 *   severity = "warning",
 *   description = @Translation("Best-effort scan of eca.model.* configs for hard-coded taxonomy term IDs that do not exist on this tenant."),
 *   fix_hint = @Translation("Open the affected ECA model in /admin/config/workflow/eca and remap the value to a term that exists in this tenant."),
 * )
 */
class EcaTidMismatchCheck extends HealthCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
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
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->moduleHandler->moduleExists('eca')) {
      return $this->pass('ECA not enabled; check skipped.');
    }
    if (!$this->entityTypeManager->hasDefinition('taxonomy_term')) {
      return $this->pass('Taxonomy not enabled; check skipped.');
    }

    $names = $this->configFactory->listAll('eca.model.');
    if ($names === []) {
      return $this->pass('No ECA models configured.');
    }

    $candidates = [];
    foreach ($names as $name) {
      $raw = $this->configFactory->get($name)->getRawData();
      $modellabel = (string) ($raw['label'] ?? $name);
      foreach ($this->collectTaxonomyValueCandidates($raw) as $tid) {
        $candidates[$tid][] = $modellabel;
      }
    }
    if ($candidates === []) {
      return $this->pass('No taxonomy-coupled ECA conditions detected.');
    }

    $existing = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('tid', array_keys($candidates), 'IN')
      ->execute();
    $existingIds = array_map('intval', $existing);

    $missing = array_diff(array_keys($candidates), $existingIds);
    if ($missing === []) {
      return $this->pass(sprintf('All %d ECA-referenced taxonomy IDs exist.', count($candidates)));
    }

    sort($missing);
    $details = [];
    foreach ($missing as $tid) {
      $models = array_unique($candidates[$tid] ?? []);
      $details[] = sprintf('tid=%d in %s', $tid, implode(' / ', $models));
    }
    return $this->fail(
      count($missing),
      sprintf('%d missing taxonomy ID(s) referenced by ECA: %s.', count($missing), implode('; ', $details)),
    );
  }

  /**
   * Walks an ECA model config recursively, yielding integer term-id candidates.
   *
   * Only yields integer values that sit under a "value" key inside a structure
   * that also names a taxonomy field (field_status, field_service_status, or
   * any plugin id containing "taxonomy"). Best-effort, deliberately
   * conservative to avoid coercing weights and delays into tids.
   *
   * @return iterable<int, int>
   *   Iterator of term-id candidates.
   */
  protected function collectTaxonomyValueCandidates(array $data, string $contextHint = ''): iterable {
    foreach ($data as $key => $value) {
      $hint = $contextHint;
      if (is_string($key)) {
        $hint .= ' ' . $key;
      }
      if (is_array($value)) {
        if (isset($value['plugin']) && is_string($value['plugin'])) {
          $hint .= ' ' . $value['plugin'];
        }
        if (isset($value['field_name']) && is_string($value['field_name'])) {
          $hint .= ' ' . $value['field_name'];
        }
        yield from $this->collectTaxonomyValueCandidates($value, $hint);
        continue;
      }
      if ($key !== 'value' || !is_scalar($value)) {
        continue;
      }
      $tid = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
      if ($tid === FALSE) {
        continue;
      }
      $hintLower = strtolower($hint);
      $taxonomyHints = ['taxonomy', 'field_status', 'field_service_status', 'field_category', 'field_service_category'];
      foreach ($taxonomyHints as $needle) {
        if (str_contains($hintLower, $needle)) {
          yield $tid;
          break;
        }
      }
    }
  }

}
