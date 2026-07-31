<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Plans and applies a vertical vocabulary to a portfolio root.
 */
class VerticalVocabularyApplier {

  /**
   * Constructs a VerticalVocabularyApplier object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $hierarchyResolver
   *   The jurisdiction hierarchy resolver.
   * @param \Drupal\markaspot_group\Service\StatusTermScope $statusTermScope
   *   The jurisdiction-aware service status loader.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected JurisdictionHierarchyResolverInterface $hierarchyResolver,
    protected StatusTermScope $statusTermScope,
    protected ConfigFactoryInterface $configFactory,
    protected LanguageManagerInterface $languageManager,
    protected Connection $database,
  ) {}

  /**
   * Applies a validated vertical definition.
   *
   * @param array<string, mixed> $definition
   *   Validated vertical definition.
   * @param int $jurisdictionId
   *   Target jurisdiction group ID.
   * @param bool $dryRun
   *   Whether to report changes without writing.
   * @param string[]|null $requestedLocales
   *   Explicit locale filter, or NULL for all definition locales.
   *
   * @return array<int, array{layer: string, locale: string, item: string, result: string, detail: string}>
   *   Operator-facing result rows.
   */
  public function apply(
    array $definition,
    int $jurisdictionId,
    bool $dryRun = FALSE,
    ?array $requestedLocales = NULL,
  ): array {
    $group = $this->loadRootJurisdiction($jurisdictionId);
    $locales = $this->resolveLocales($definition, $requestedLocales);
    [$config, $originalConfig] = $this->readNuxtConfig($group);

    $rows = [];
    $configChanged = $this->planConfigChanges(
      $definition,
      $locales,
      $config,
      $rows,
      $dryRun,
    );
    [$statusRows, $statusOperations] = $this->planStatusChanges(
      $definition,
      $locales,
      $jurisdictionId,
      $dryRun,
    );
    $rows = array_merge($rows, $statusRows);

    if (!$dryRun && ($configChanged || $statusOperations !== [])) {
      $transaction = $this->database->startTransaction();
      try {
        if ($configChanged) {
          $encoded = json_encode(
            $config,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
          );
          if ($encoded !== $originalConfig) {
            $group->set('field_nuxt_config', $encoded);
            $group->save();
          }
        }
        $this->applyStatusOperations($statusOperations);
      }
      catch (\Throwable $exception) {
        $transaction->rollBack();
        throw $exception;
      }
    }

    if ($rows === []) {
      $rows[] = [
        'layer' => 'summary',
        'locale' => '-',
        'item' => (string) $definition['id'],
        'result' => 'no-changes',
        'detail' => 'No vocabulary changes are defined.',
      ];
    }
    elseif (!$this->hasPlannedChange($rows)) {
      $unresolvedResults = ['not-found', 'ambiguous', 'target-exists'];
      $hasUnresolvedItems = FALSE;
      foreach ($rows as $row) {
        if (in_array($row['result'], $unresolvedResults, TRUE)) {
          $hasUnresolvedItems = TRUE;
          break;
        }
      }
      $rows[] = [
        'layer' => 'summary',
        'locale' => '-',
        'item' => (string) $definition['id'],
        'result' => $hasUnresolvedItems ? 'no-writes' : 'no-changes',
        'detail' => $hasUnresolvedItems
          ? 'No writes were made. Review skipped or unmatched status rules.'
          : 'Tenant already matches the selected vertical.',
      ];
    }

    return $rows;
  }

  /**
   * Loads and validates the target portfolio root.
   */
  protected function loadRootJurisdiction(int $jurisdictionId): GroupInterface {
    $group = $this->entityTypeManager
      ->getStorage('group')
      ->load($jurisdictionId);
    if (!$group instanceof GroupInterface) {
      throw new \RuntimeException(sprintf(
        'Group %d does not exist.',
        $jurisdictionId,
      ));
    }

    $jurisdictionBundle = $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type') ?: 'jur';
    if ($group->bundle() !== $jurisdictionBundle) {
      throw new \RuntimeException(sprintf(
        'Group %d is a "%s" group, not a jurisdiction group.',
        $jurisdictionId,
        $group->bundle(),
      ));
    }

    $rootId = $this->hierarchyResolver
      ->getRootJurisdictionId($jurisdictionId);
    if ($rootId === NULL || $rootId <= 0) {
      throw new \RuntimeException(sprintf(
        'The portfolio root for jurisdiction %d could not be resolved.',
        $jurisdictionId,
      ));
    }
    if ($rootId !== $jurisdictionId) {
      $root = $this->entityTypeManager->getStorage('group')->load($rootId);
      $rootLabel = $root instanceof GroupInterface
        ? sprintf(' "%s"', $root->label())
        : '';
      throw new \RuntimeException(sprintf(
        'Jurisdiction %d is not the portfolio root. The root is %d%s. Run the command with --jurisdiction=%d.',
        $jurisdictionId,
        $rootId,
        $rootLabel,
        $rootId,
      ));
    }
    if (!$group->hasField('field_parent_jurisdiction')) {
      throw new \RuntimeException(sprintf(
        'Jurisdiction %d cannot be verified as a portfolio root because field_parent_jurisdiction is unavailable.',
        $jurisdictionId,
      ));
    }
    if (!$group->get('field_parent_jurisdiction')->isEmpty()) {
      throw new \RuntimeException(sprintf(
        'Jurisdiction %d has a parent reference and cannot be treated as a portfolio root. Repair the hierarchy before applying a vertical.',
        $jurisdictionId,
      ));
    }

    if (!$group->hasField('field_nuxt_config')) {
      throw new \RuntimeException(sprintf(
        'Root jurisdiction %d does not have field_nuxt_config.',
        $jurisdictionId,
      ));
    }

    return $group;
  }

  /**
   * Resolves and validates the requested locale subset.
   *
   * @return string[]
   *   Locales to process.
   */
  protected function resolveLocales(
    array $definition,
    ?array $requestedLocales,
  ): array {
    $available = [];
    foreach (['entities', 'overrides', 'statuses'] as $section) {
      foreach (array_keys($definition[$section]) as $locale) {
        $available[(string) $locale] = TRUE;
      }
    }
    $availableLocales = array_keys($available);

    if ($requestedLocales === NULL) {
      return $availableLocales;
    }

    $unknown = array_values(array_diff($requestedLocales, $availableLocales));
    if ($unknown !== []) {
      throw new \RuntimeException(sprintf(
        'Vertical "%s" has no definitions for locale(s): %s. Available locales: %s.',
        $definition['id'],
        implode(', ', $unknown),
        $availableLocales === [] ? '(none)' : implode(', ', $availableLocales),
      ));
    }

    return $requestedLocales;
  }

  /**
   * Reads the existing field_nuxt_config JSON object.
   *
   * @return array{0: \stdClass, 1: string}
   *   Decoded config and the original JSON string.
   */
  protected function readNuxtConfig(GroupInterface $group): array {
    $original = $group->get('field_nuxt_config')->getString();
    if (trim($original) === '') {
      return [new \stdClass(), $original];
    }

    try {
      $config = json_decode($original, FALSE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException(sprintf(
        'Root jurisdiction %d has invalid field_nuxt_config JSON: %s',
        $group->id(),
        $exception->getMessage(),
      ), 0, $exception);
    }
    if (!$config instanceof \stdClass) {
      throw new \RuntimeException(sprintf(
        'Root jurisdiction %d field_nuxt_config must contain a JSON object.',
        $group->id(),
      ));
    }
    if (isset($config->i18n) && !$config->i18n instanceof \stdClass) {
      throw new \RuntimeException(sprintf(
        'Root jurisdiction %d field_nuxt_config.i18n must be an object.',
        $group->id(),
      ));
    }
    foreach (['entities', 'overrides'] as $section) {
      if (isset($config->i18n->{$section})
        && !$config->i18n->{$section} instanceof \stdClass) {
        throw new \RuntimeException(sprintf(
          'Root jurisdiction %d field_nuxt_config.i18n.%s must be an object.',
          $group->id(),
          $section,
        ));
      }
    }

    return [$config, $original];
  }

  /**
   * Plans locale-specific entities and override updates.
   *
   * @param array<string, mixed> $definition
   *   Vertical definition.
   * @param string[] $locales
   *   Selected locales.
   * @param \stdClass $config
   *   Decoded config, modified by reference.
   * @param array<int, array{layer: string, locale: string, item: string, result: string, detail: string}> $rows
   *   Result rows, modified by reference.
   * @param bool $dryRun
   *   Whether to format changes as dry-run results.
   *
   * @return bool
   *   TRUE when the config differs.
   */
  protected function planConfigChanges(
    array $definition,
    array $locales,
    \stdClass $config,
    array &$rows,
    bool $dryRun,
  ): bool {
    $changed = FALSE;
    $config->i18n ??= new \stdClass();
    $config->i18n->entities ??= new \stdClass();
    $config->i18n->overrides ??= new \stdClass();
    foreach ($locales as $locale) {
      if (isset($definition['entities'][$locale])) {
        $current = $config->i18n->entities->{$locale} ?? NULL;
        $target = $this->toJsonValue($definition['entities'][$locale]);
        $isChanged = $current != $target;
        if ($isChanged) {
          $config->i18n->entities->{$locale} = $target;
          $changed = TRUE;
        }
        $rows[] = [
          'layer' => 'entities',
          'locale' => $locale,
          'item' => 'jurisdiction, organisation',
          'result' => $isChanged
            ? ($dryRun ? 'would-set' : 'set')
            : 'unchanged',
          'detail' => $isChanged
            ? 'Set the complete locale entity vocabulary.'
            : 'Entity vocabulary already matches.',
        ];
      }

      if (!isset($definition['overrides'][$locale])) {
        continue;
      }
      $currentOverrides = $config->i18n->overrides->{$locale}
      ?? new \stdClass();
      if (!$currentOverrides instanceof \stdClass) {
        throw new \RuntimeException(sprintf(
          'field_nuxt_config.i18n.overrides.%s must be an object.',
          $locale,
        ));
      }
      foreach ($definition['overrides'][$locale] as $key => $value) {
        $exists = property_exists($currentOverrides, $key);
        $isChanged = !$exists || $currentOverrides->{$key} !== $value;
        if ($isChanged) {
          $currentOverrides->{$key} = $value;
          $config->i18n->overrides->{$locale} = $currentOverrides;
          $changed = TRUE;
        }
        $rows[] = [
          'layer' => 'overrides',
          'locale' => $locale,
          'item' => $key,
          'result' => $isChanged
            ? ($dryRun
              ? ($exists ? 'would-update' : 'would-add')
              : ($exists ? 'updated' : 'added'))
            : 'unchanged',
          'detail' => $value,
        ];
      }
    }

    return $changed;
  }

  /**
   * Plans conservative root-owned service status term renames.
   *
   * @return array{0: array<int, array{layer: string, locale: string, item: string, result: string, detail: string}>, 1: array<int, array{term: \Drupal\taxonomy\TermInterface, localized: \Drupal\taxonomy\TermInterface, name: string, id: int}>}
   *   Result rows and write operations.
   */
  protected function planStatusChanges(
    array $definition,
    array $locales,
    int $rootId,
    bool $dryRun,
  ): array {
    $rulesExist = FALSE;
    foreach ($locales as $locale) {
      if (($definition['statuses'][$locale] ?? []) !== []) {
        $rulesExist = TRUE;
        break;
      }
    }
    if (!$rulesExist) {
      return [[], []];
    }
    if (!$this->statusTermScope->canScope($rootId)) {
      throw new \RuntimeException(
        'Status terms cannot be safely jurisdiction-scoped because field_jurisdiction storage is unavailable.',
      );
    }

    $terms = $this->statusTermScope->loadTreePoolByProperties(
      ['vid' => 'service_status'],
      $rootId,
    );
    $terms = array_filter(
      $terms,
      fn(mixed $term): bool => $term instanceof TermInterface
        && $this->belongsToRoot($term, $rootId),
    );

    $rows = [];
    $operations = [];
    foreach ($locales as $locale) {
      foreach ($definition['statuses'][$locale] ?? [] as $rule) {
        $matches = [];
        $targetMatches = [];
        foreach ($terms as $term) {
          $localized = $this->getLocalizedTerm($term, $locale);
          if (!$localized instanceof TermInterface) {
            continue;
          }
          $normalizedCurrent = mb_strtolower(trim($localized->getName()));
          if ($normalizedCurrent === mb_strtolower(trim($rule['match']))) {
            $matches[] = [$term, $localized];
          }
          if ($normalizedCurrent === mb_strtolower(trim($rule['name']))) {
            $targetMatches[] = [$term, $localized];
          }
        }

        if ($matches === []) {
          if (count($targetMatches) === 1) {
            $rows[] = [
              'layer' => 'statuses',
              'locale' => $locale,
              'item' => $rule['match'],
              'result' => 'unchanged',
              'detail' => sprintf(
                'Term %d already has target name "%s"; its ID and existing references are unchanged.',
                $targetMatches[0][0]->id(),
                $rule['name'],
              ),
            ];
            continue;
          }
          if (count($targetMatches) > 1) {
            $rows[] = [
              'layer' => 'statuses',
              'locale' => $locale,
              'item' => $rule['match'],
              'result' => 'ambiguous',
              'detail' => 'Skipped. More than one root-owned term already has the target name.',
            ];
            continue;
          }
          $rows[] = [
            'layer' => 'statuses',
            'locale' => $locale,
            'item' => $rule['match'],
            'result' => 'not-found',
            'detail' => 'Skipped. No root-owned service_status term matched.',
          ];
          continue;
        }
        if (count($matches) > 1) {
          $candidates = array_map(
            static fn(array $match): string => sprintf(
              '%d:%s',
              $match[0]->id(),
              $match[1]->getName(),
            ),
            $matches,
          );
          $rows[] = [
            'layer' => 'statuses',
            'locale' => $locale,
            'item' => $rule['match'],
            'result' => 'ambiguous',
            'detail' => 'Skipped candidates ' . implode(', ', $candidates) . '.',
          ];
          continue;
        }

        [$term, $localized] = $matches[0];
        $termId = (int) $term->id();
        if ($localized->getName() === $rule['name']) {
          $rows[] = [
            'layer' => 'statuses',
            'locale' => $locale,
            'item' => $rule['match'],
            'result' => 'unchanged',
            'detail' => sprintf('Term %d already has name "%s".', $termId, $rule['name']),
          ];
          continue;
        }
        $targetCollisions = array_filter(
          $targetMatches,
          static fn(array $targetMatch): bool => (int) $targetMatch[0]->id()
            !== $termId,
        );
        if ($targetCollisions !== []) {
          $candidates = array_map(
            static fn(array $targetMatch): string => sprintf(
              '%d:%s',
              $targetMatch[0]->id(),
              $targetMatch[1]->getName(),
            ),
            $targetCollisions,
          );
          $rows[] = [
            'layer' => 'statuses',
            'locale' => $locale,
            'item' => $rule['match'],
            'result' => 'target-exists',
            'detail' => 'Skipped target-name collision ' . implode(', ', $candidates) . '.',
          ];
          continue;
        }

        $operations[] = [
          'term' => $term,
          'localized' => $localized,
          'name' => $rule['name'],
          'id' => $termId,
        ];
        $rows[] = [
          'layer' => 'statuses',
          'locale' => $locale,
          'item' => $rule['match'],
          'result' => $dryRun ? 'would-rename' : 'renamed',
          'detail' => sprintf(
            'Term %d -> "%s"; the term ID and existing references remain unchanged.',
            $termId,
            $rule['name'],
          ),
        ];
      }
    }

    return [$rows, $operations];
  }

  /**
   * Applies planned term renames and verifies stable entity IDs.
   *
   * @param array<int, array{term: \Drupal\taxonomy\TermInterface, localized: \Drupal\taxonomy\TermInterface, name: string, id: int}> $operations
   *   Planned status rename operations.
   */
  protected function applyStatusOperations(array $operations): void {
    foreach ($operations as $operation) {
      $operation['localized']->setName($operation['name']);
      $operation['term']->save();
      if ((int) $operation['term']->id() !== $operation['id']) {
        throw new \RuntimeException(sprintf(
          'Service status term ID changed unexpectedly while renaming term %d.',
          $operation['id'],
        ));
      }
    }
  }

  /**
   * Returns the correct term translation for one definition locale.
   */
  protected function getLocalizedTerm(
    TermInterface $term,
    string $locale,
  ): ?TermInterface {
    if ($term->hasTranslation($locale)) {
      $translation = $term->getTranslation($locale);
      return $translation instanceof TermInterface ? $translation : NULL;
    }

    $untranslated = $term->getUntranslated();
    $defaultLocale = $this->languageManager->getDefaultLanguage()->getId();
    $termLocale = $untranslated->language()->getId();
    if ($locale === $defaultLocale || $locale === $termLocale) {
      return $untranslated instanceof TermInterface ? $untranslated : NULL;
    }

    return NULL;
  }

  /**
   * Defensively checks that a status term is owned by the requested root.
   */
  protected function belongsToRoot(TermInterface $term, int $rootId): bool {
    if ($term->bundle() !== 'service_status'
      || !$term->hasField('field_jurisdiction')
      || $term->get('field_jurisdiction')->isEmpty()) {
      return FALSE;
    }
    $values = $term->get('field_jurisdiction')->getValue();
    return (int) ($values[0]['target_id'] ?? 0) === $rootId;
  }

  /**
   * Converts definition arrays to JSON-native objects and lists.
   */
  protected function toJsonValue(array $value): mixed {
    return json_decode(
      json_encode($value, JSON_THROW_ON_ERROR),
      FALSE,
      512,
      JSON_THROW_ON_ERROR,
    );
  }

  /**
   * Checks whether result rows include an actual or planned write.
   */
  protected function hasPlannedChange(array $rows): bool {
    $changeResults = [
      'would-set',
      'set',
      'would-add',
      'added',
      'would-update',
      'updated',
      'would-rename',
      'renamed',
    ];
    foreach ($rows as $row) {
      if (in_array($row['result'], $changeResults, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
