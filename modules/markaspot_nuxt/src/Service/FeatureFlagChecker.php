<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;

/**
 * Reads feature flags from a jurisdiction group's field_nuxt_config.
 *
 * Central place to answer "is feature X enabled for jurisdiction Y?". The
 * JSON schema at markaspot_nuxt/schema/nuxt_config.schema.json is the canonical
 * contract; this service looks up concrete values against that contract and
 * accepts a caller-provided default for the case where the config is silent.
 *
 * Use the `$default` parameter to encode safety intent: compliance-critical
 * features (privacyNotice) default to TRUE so an unconfigured tenant fails
 * closed, cost-critical features (aiAnalysis) default to the schema value.
 */
final class FeatureFlagChecker {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Reads a feature flag value from a jurisdiction group's field_nuxt_config.
   *
   * @param string $dotPath
   *   Dot-separated path into the config JSON, e.g. "features.aiAnalysis" or
   *   "features.privacyNotice.enabled". The caller knows the path; this
   *   service does not enforce a fixed list.
   * @param \Drupal\group\Entity\GroupInterface|null $jurisdiction
   *   The jurisdiction group to read from. NULL means "no jurisdiction
   *   context available" — the caller will receive the $default.
   * @param bool $default
   *   Value returned when the config key is absent or the jurisdiction is
   *   NULL. Encode compliance/safety intent here.
   *
   * @return bool
   *   TRUE if the feature is enabled, FALSE otherwise.
   */
  public function isEnabled(string $dotPath, ?GroupInterface $jurisdiction, bool $default = FALSE): bool {
    if ($jurisdiction === NULL) {
      return $default;
    }

    $config = $this->readConfig($jurisdiction);
    if ($config === NULL) {
      return $default;
    }

    $value = $this->readDotPath($config, $dotPath);
    if ($value === NULL) {
      return $default;
    }

    // Accept both "features.foo: true" and "features.foo: { enabled: true }"
    // shapes, matching the Nuxt-side isFeatureEnabled helper.
    if (is_bool($value)) {
      return $value;
    }
    if (is_array($value) && array_key_exists('enabled', $value)) {
      return (bool) $value['enabled'];
    }

    return $default;
  }

  /**
   * Resolves the jurisdiction group for a service request node.
   *
   * Mirrors the primary path of
   * GeoreportProcessorService::resolveNodeJurisdiction(): read the direct
   * field_jurisdiction reference on the node. The group_relationship fallback
   * is intentionally omitted here because presave hooks run before relationship
   * entities are created, so only field_jurisdiction is reliable.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The jurisdiction group entity, or NULL if not resolvable.
   */
  public function resolveJurisdictionForNode(NodeInterface $node): ?GroupInterface {
    if (!$node->hasField('field_jurisdiction') || $node->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }

    $entity = $node->get('field_jurisdiction')->entity;
    return $entity instanceof GroupInterface ? $entity : NULL;
  }

  /**
   * Reads and decodes the field_nuxt_config JSON from a group's default translation.
   */
  private function readConfig(GroupInterface $group): ?array {
    $source = $group->isDefaultTranslation() ? $group : $group->getUntranslated();
    if (!$source->hasField('field_nuxt_config') || $source->get('field_nuxt_config')->isEmpty()) {
      return NULL;
    }

    $raw = $source->get('field_nuxt_config')->value;
    if (!is_string($raw) || $raw === '') {
      return NULL;
    }

    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * Walks a dot-separated path into a nested array.
   */
  private function readDotPath(array $data, string $dotPath): mixed {
    $keys = explode('.', $dotPath);
    $cursor = $data;
    foreach ($keys as $key) {
      if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
        return NULL;
      }
      $cursor = $cursor[$key];
    }
    return $cursor;
  }

}
