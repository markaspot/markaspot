<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Service;

use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;

/**
 * Plans and restores missing reporting schema from canonical shipped config.
 *
 * Never installs modules or overwrites existing field configuration. Optional
 * feature roots whose dependencies are unavailable are explicitly reported.
 */
class TenantSetupSchema {

  /**
   * Constructs the schema planner.
   *
   * @param \Drupal\Core\Config\StorageInterface $active
   *   Active configuration storage.
   * @param \Drupal\Core\Config\ConfigInstallerInterface $installer
   *   Missing configuration installer.
   * @param array $sources
   *   Ordered source records: storage, required, and optional required_names.
   * @param string[] $enabledModules
   *   Modules already enabled by the selected product configuration.
   */
  public function __construct(
    private readonly StorageInterface $active,
    private readonly ConfigInstallerInterface $installer,
    private readonly array $sources,
    private readonly array $enabledModules,
  ) {}

  /**
   * Reports the complete applicable field contract and optionally repairs it.
   */
  public function prepare(bool $apply = FALSE): array {
    $shipped = [];
    $roots = [];
    foreach ($this->sources as $source) {
      foreach ($source['storage']->listAll() as $name) {
        $data = $source['storage']->read($name);
        if (!is_array($data)) {
          throw new \RuntimeException('Cannot read shipped schema configuration: ' . $name);
        }
        $shipped[$name] = $data;
        if ($this->isReportingField($name)) {
          $roots[$name] = ($roots[$name] ?? FALSE) || $source['required'] || in_array($name, $source['required_names'] ?? [], TRUE);
        }
      }
      foreach ($source['required_names'] ?? [] as $name) {
        if (!$source['storage']->exists($name)) {
          throw new \RuntimeException('Required shipped schema source is unavailable: ' . $name);
        }
        $roots[$name] = TRUE;
      }
    }
    ksort($roots);
    $selected = [];
    $applicable = [];
    $unavailable = [];
    foreach ($roots as $name => $required) {
      $candidate = [];
      try {
        $this->selectMissing($name, $shipped, $candidate);
        $selected += $candidate;
        $applicable[] = $name;
      }
      catch (\RuntimeException $error) {
        if ($required) {
          throw new \RuntimeException('Required reporting schema is incomplete: ' . $error->getMessage(), 0, $error);
        }
        $unavailable[$name] = $error->getMessage();
      }
    }
    ksort($selected);
    $result = [
      'required' => array_keys(array_filter($roots)),
      'applicable' => $applicable,
      'missing' => array_keys($selected),
      'created' => [],
      'unavailable_optional' => $unavailable,
    ];
    if (!$apply) {
      return $result;
    }
    if ($selected !== []) {
      $source = new MemoryStorage();
      foreach ($selected as $name => $data) {
        $source->write($name, $data);
      }
      $this->installer->installOptionalConfig($source);
    }
    foreach (array_unique(array_merge($applicable, array_keys($selected))) as $name) {
      if (!$this->active->exists($name)) {
        throw new \RuntimeException('Reporting schema postcondition failed: ' . $name);
      }
    }
    $result['created'] = array_keys($selected);
    return $result;
  }

  /**
   * Selects only field/bundle dependency closure, never unrelated site config.
   */
  private function selectMissing(string $name, array $shipped, array &$selected): void {
    if ($this->active->exists($name) || isset($selected[$name])) {
      return;
    }
    if (!isset($shipped[$name]) || !preg_match('/^(field\.(storage|field)\.|paragraphs\.paragraphs_type\.)/', $name)) {
      throw new \RuntimeException('Missing prerequisite ' . $name);
    }
    $data = $shipped[$name];
    foreach ($data['dependencies']['module'] ?? [] as $module) {
      if (!in_array($module, $this->enabledModules, TRUE)) {
        throw new \RuntimeException('Module ' . $module . ' is not enabled for ' . $name);
      }
    }
    // Guard cycles before following references. The installer resolves order.
    $selected[$name] = $data;
    foreach ($data['dependencies']['config'] ?? [] as $dependency) {
      $this->selectMissing($dependency, $shipped, $selected);
    }
  }

  /**
   * Limits automatic roots to report and associated paragraph field schemas.
   */
  private function isReportingField(string $name): bool {
    return str_starts_with($name, 'field.field.node.boilerplate.')
      || str_starts_with($name, 'field.field.node.service_request.')
      || str_starts_with($name, 'field.field.media.request_image.')
      || str_starts_with($name, 'field.field.paragraph.status.')
      || str_starts_with($name, 'field.field.paragraph.internal_remark.');
  }

}
