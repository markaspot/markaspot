<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;

/**
 * Imports owned jurisdiction text templates within the bootstrap transaction.
 */
final class TenantBoilerplates {

  /**
   * Validates source rows without loading or changing entities.
   */
  public static function validate(mixed $rows): array {
    if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100) {
      return ['tenant.boilerplates must be a list of at most 100 templates.'];
    }
    $errors = [];
    $seen = [];
    foreach ($rows as $index => $row) {
      if (!is_array($row)) {
        $errors[] = "tenant.boilerplates.$index must be an object.";
        continue;
      }
      $key = $row['key'] ?? NULL;
      if (!is_string($key) || !preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $key) || isset($seen[$key])) {
        $errors[] = "tenant.boilerplates.$index.key is invalid or duplicated.";
      }
      else {
        $seen[$key] = TRUE;
      }
      foreach (['title' => 255, 'text' => 10000] as $field => $limit) {
        $value = $row[$field] ?? NULL;
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $limit || preg_match('/<[^>]+>/', $value)) {
          $errors[] = "tenant.boilerplates.$index.$field must be bounded plain text.";
        }
      }
      if (!in_array($row['type'] ?? NULL, ['status_notes', 'remarks'], TRUE) || !is_bool($row['active'] ?? NULL)) {
        $errors[] = "tenant.boilerplates.$index requires a supported type and boolean active.";
      }
      if (array_diff(array_keys($row), ['key', 'title', 'text', 'type', 'active']) !== []) {
        $errors[] = "tenant.boilerplates.$index contains unsupported fields.";
      }
    }
    return $errors;
  }

  /**
   * Creates or updates only templates already owned by this bootstrap.
   *
   * The caller holds the dedicated bootstrap lock and database transaction.
   * Missing or foreign owned entities are errors, never silently adopted.
   */
  public static function apply(
    array $rows,
    GroupInterface $jurisdiction,
    EntityTypeManagerInterface $entities,
    KeyValueStoreInterface $ownership,
    string $langcode,
  ): array {
    if (self::validate($rows) !== []) {
      throw new \RuntimeException('Invalid boilerplate configuration.');
    }
    $storage = $entities->getStorage('node');
    $ownershipKey = 'boilerplates:' . $jurisdiction->uuid();
    $owned = $ownership->get($ownershipKey, []);
    if (!is_array($owned)) {
      throw new \RuntimeException('Invalid boilerplate ownership record.');
    }
    foreach ($owned as $key => $uuid) {
      if (!is_string($key) || !preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $key)
        || !is_string($uuid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uuid)) {
        throw new \RuntimeException('Invalid boilerplate ownership record.');
      }
    }
    if (array_diff(array_keys($owned), array_column($rows, 'key')) !== []) {
      throw new \RuntimeException('Owned boilerplates may not be omitted; deactivate them explicitly.');
    }
    $results = [];
    foreach ($rows as $row) {
      $node = NULL;
      if (isset($owned[$row['key']])) {
        $matches = $storage->loadByProperties(['uuid' => $owned[$row['key']]]);
        $node = count($matches) === 1 ? reset($matches) : NULL;
        if (!$node instanceof NodeInterface || $node->bundle() !== 'boilerplate' || (int) $node->get('field_jurisdiction')->target_id !== (int) $jurisdiction->id()) {
          throw new \RuntimeException('Owned boilerplate is missing or outside its jurisdiction.');
        }
      }
      $new = !$node instanceof NodeInterface;
      if ($new) {
        $node = $storage->create(['type' => 'boilerplate', 'langcode' => $langcode, 'uid' => 1]);
      }
      foreach (['body', 'field_boilerplate_type', 'field_jurisdiction', 'field_organisation'] as $field) {
        if (!$node->hasField($field)) {
          throw new \RuntimeException('Required boilerplate field is unavailable: ' . $field);
        }
      }
      $node->setTitle($row['title']);
      $node->set('body', ['value' => $row['text'], 'format' => 'plain_text']);
      $node->set('field_boilerplate_type', $row['type']);
      $node->set('field_jurisdiction', ['target_id' => $jurisdiction->id()]);
      // These are jurisdiction-wide defaults, not organisation-specific text.
      $node->set('field_organisation', []);
      $node->set('status', $row['active']);
      if ($node->validate()->count() > 0) {
        throw new \RuntimeException('Boilerplate entity validation failed for ' . $row['key']);
      }
      $node->save();
      // Group's management permissions require a relationship as well as the
      // routing field. Keep both within the caller's bootstrap transaction.
      $relations = $jurisdiction->getRelationshipsByEntity($node, 'group_node:boilerplate');
      if ($relations === []) {
        $jurisdiction->addRelationship($node, 'group_node:boilerplate');
      }
      $owned[$row['key']] = $node->uuid();
      $results[] = ['key' => $row['key'], 'uuid' => $node->uuid(), 'action' => $new ? 'create' : 'update'];
    }
    $ownership->set($ownershipKey, $owned);
    return $results;
  }

}
