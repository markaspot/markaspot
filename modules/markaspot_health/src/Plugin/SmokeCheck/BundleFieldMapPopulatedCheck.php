<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\SmokeCheck;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\markaspot_health\SmokeCheckPluginBase;
use Drupal\markaspot_health\SmokeCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects bundles whose field map was wiped by pm:uninstall cascade.
 *
 * Key_value:entity.definitions.bundle_field_map is the derived index that
 * JSON:API uses to enumerate fields per resource type. When pm:uninstall
 * tears down a module that owns a field storage shared by another bundle,
 * Drupal's cleanup over-deletes the affected entry. Symptom: field config
 * YAML is intact, but JSON:API POSTs return 422 "field does not exist on
 * resource type" for an entire bundle.
 *
 * Mark-a-Spot 11.9.72 hotfix was triggered by this on media:request_image.
 *
 * @SmokeCheck(
 *   id = "bundle_field_map_populated",
 *   label = @Translation("Bundle field map populated"),
 *   severity = "error",
 *   category = "drupal_internal",
 *   description = @Translation("Verifies entity.definitions.bundle_field_map has at least one bundle entry per fieldable bundle entity type."),
 *   fix_hint = @Translation("If field config is intact, clear cached field definitions and run drush cr; otherwise re-import config via cim."),
 * )
 */
class BundleFieldMapPopulatedCheck extends SmokeCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected KeyValueFactoryInterface $keyValueFactory,
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
      $container->get('keyvalue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): SmokeCheckResult {
    $mode = $this->mode($context);

    $store = $this->keyValueFactory->get('entity.definitions.bundle_field_map');
    $offenders = [];
    $checked = 0;

    // bundle_field_map only indexes configurable fields (field_config), not
    // base fields. A bundle entity type without any configured field_config
    // legitimately has an empty map; the corruption case to catch is "has
    // field_config entries but the map for the entity type is gone".
    $fieldConfigStorage = $this->entityTypeManager->hasDefinition('field_config')
      ? $this->entityTypeManager->getStorage('field_config')
      : NULL;

    foreach ($this->entityTypeManager->getDefinitions() as $entityType) {
      if (!$entityType->entityClassImplements('Drupal\Core\Entity\FieldableEntityInterface')) {
        continue;
      }
      if ($entityType->getBundleEntityType() === NULL) {
        continue;
      }
      $entityTypeId = $entityType->id();

      $fieldCount = 0;
      if ($fieldConfigStorage !== NULL) {
        $fieldCount = count($fieldConfigStorage->loadByProperties([
          'entity_type' => $entityTypeId,
        ]));
      }
      if ($fieldCount === 0) {
        // No configurable fields → empty map is the legitimate state.
        continue;
      }
      $checked++;

      $map = $store->get($entityTypeId);
      if (!is_array($map) || $map === []) {
        $offenders[] = [
          'entity_type' => $entityTypeId,
          'field_config_count' => $fieldCount,
          'reason' => 'empty_map_with_configured_fields',
        ];
      }
    }

    if ($offenders === []) {
      return $this->pass(sprintf('Bundle field map populated for all %d entity types with configured fields.', $checked), [], $mode);
    }

    return $this->fail(
      count($offenders),
      sprintf('%d entity type(s) have configured fields but an empty/missing bundle field map.', count($offenders)),
      ['offenders' => $offenders],
      $mode,
    );
  }

}
