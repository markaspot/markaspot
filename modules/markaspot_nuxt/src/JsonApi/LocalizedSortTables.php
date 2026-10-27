<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\JsonApi;

use Drupal\Core\Entity\Query\Sql\Tables;

/**
 * Selects one preferred/default-language row for dashboard ORDER BY joins.
 *
 * Core applies the same langcode to every reference hop. Legacy reports often
 * use und while their categories have real translations. Sorting must retain
 * the original reference and fall back independently on the referenced entity.
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
final class LocalizedSortTables extends Tables {

  /**
   * Joins already restricted to one preferred/default language row.
   *
   * @var array<string, bool>
   */
  private array $localizedAliases = [];

  /**
   * {@inheritdoc}
   */
  protected function ensureFieldTable($index_prefix, &$field, $type, $langcode, $base_table, $entity_id_field, $field_id_field, $delta) {
    $alias = parent::ensureFieldTable($index_prefix, $field, $type, NULL, $base_table, $entity_id_field, $field_id_field, $delta);
    if ($langcode && !isset($this->localizedAliases[$alias])) {
      $this->localizedAliases[$alias] = TRUE;
      $tables = &$this->sqlQuery->getTables();
      $table = $tables[$alias]['table'];
      $placeholder = ':dashboard_sort_language_' . $this->sqlQuery->nextPlaceholder();
      $tables[$alias]['condition'] .= " AND [$alias].[langcode] = COALESCE((SELECT preferred.langcode FROM {{$table}} preferred WHERE preferred.[$field_id_field] = [$alias].[$field_id_field] AND preferred.langcode = $placeholder AND preferred.delta = [$alias].[delta] LIMIT 1), [$base_table].[langcode])";
      $tables[$alias]['arguments'][$placeholder] = $langcode;
    }
    return $alias;
  }

  /**
   * {@inheritdoc}
   */
  protected function ensureEntityTable($index_prefix, $property, $type, $langcode, $base_table, $id_field, $entity_tables) {
    $alias = parent::ensureEntityTable($index_prefix, $property, $type, NULL, $base_table, $id_field, $entity_tables);
    $tables = &$this->sqlQuery->getTables();
    $table = $tables[$alias]['table'];
    $mapping = $entity_tables[$table] ?? [];
    if ($langcode && !isset($this->localizedAliases[$alias]) && isset($mapping['langcode'], $mapping['default_langcode'])) {
      $this->localizedAliases[$alias] = TRUE;
      $placeholder = ':dashboard_sort_language_' . $this->sqlQuery->nextPlaceholder();
      $tables[$alias]['condition'] .= " AND ([$alias].[langcode] = $placeholder OR ([$alias].[default_langcode] = 1 AND NOT EXISTS (SELECT 1 FROM {{$table}} preferred WHERE preferred.[$id_field] = [$alias].[$id_field] AND preferred.langcode = $placeholder)))";
      $tables[$alias]['arguments'][$placeholder] = $langcode;
    }
    return $alias;
  }

}
