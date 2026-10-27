<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\JsonApi;

use Drupal\Core\Entity\Query\Sql\Query;

/**
 * Adds language fallback only to explicitly requested dashboard virtual sorts.
 *
 * Conditions and access queries continue using Core's normal Tables helper.
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
class LocalizedSortQuery extends Query {

  /**
   * Sort helpers, isolated from filter joins and shared within each language.
   *
   * @var array<string, \Drupal\markaspot_nuxt\JsonApi\LocalizedSortTables>
   */
  private array $localizedSortTables = [];

  /**
   * Copies a fully validated, unexecuted collection query.
   */
  public static function wrap(Query $source, array $positions): self {
    $instance = new self($source->entityType, $source->conjunction, $source->connection, $source->namespaces);
    foreach (get_object_vars($source) as $name => $value) {
      $instance->$name = $name === 'condition' ? clone $value : $value;
    }
    // The cloned condition must compile against this query's joins.
    $instance->condition->setQuery($instance);
    foreach ($positions as $position) {
      $sort = $source->sort[$position];
      $instance->alterMetaData['dashboard_localized_sorts'][] = $sort;
    }
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getSqlField($field, $langcode) {
    foreach ($this->alterMetaData['dashboard_localized_sorts'] ?? [] as $sort) {
      if ($sort['field'] === $field && $sort['langcode'] === $langcode && $langcode) {
        // A separate helper prevents a sort join from changing a filter join.
        $this->localizedSortTables[$langcode] ??= new LocalizedSortTables($this->sqlQuery);
        return $this->localizedSortTables[$langcode]->addField($field, 'LEFT', $langcode);
      }
    }
    return parent::getSqlField($field, $langcode);
  }

}
