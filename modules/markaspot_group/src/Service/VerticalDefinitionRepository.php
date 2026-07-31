<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Loads declarative vertical vocabulary definitions shipped by the module.
 */
class VerticalDefinitionRepository {

  /**
   * Directory containing vertical definitions relative to the module root.
   */
  private const DIRECTORY = 'verticals';

  /**
   * Constructs a VerticalDefinitionRepository object.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   The module extension list.
   */
  public function __construct(
    protected ModuleExtensionList $moduleExtensionList,
  ) {}

  /**
   * Returns the available vertical IDs.
   *
   * Missing directories are treated as an empty definition set so command
   * discovery and Drush boot remain safe on older profile checkouts.
   *
   * @return string[]
   *   Available vertical IDs sorted alphabetically.
   */
  public function getAvailableIds(): array {
    $directory = $this->getDirectory();
    if (!is_dir($directory)) {
      return [];
    }

    $files = glob($directory . '/*.yml');
    if ($files === FALSE) {
      return [];
    }

    $ids = [];
    foreach ($files as $file) {
      $id = pathinfo($file, PATHINFO_FILENAME);
      if (preg_match('/^[a-z][a-z0-9_-]*$/', $id) === 1) {
        $ids[] = $id;
      }
    }
    sort($ids, SORT_STRING);
    return array_values(array_unique($ids));
  }

  /**
   * Loads and validates one vertical definition.
   *
   * @param string $verticalId
   *   Vertical ID matching a shipped YAML filename.
   *
   * @return array<string, mixed>
   *   Validated vertical definition.
   */
  public function load(string $verticalId): array {
    $available = $this->getAvailableIds();
    if (!in_array($verticalId, $available, TRUE)) {
      $listing = $available === [] ? '(none)' : implode(', ', $available);
      throw new \RuntimeException(sprintf(
        'Unknown vertical "%s". Available verticals: %s.',
        $verticalId,
        $listing,
      ));
    }

    $path = $this->getDirectory() . '/' . $verticalId . '.yml';
    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new \RuntimeException(sprintf(
        'Vertical definition "%s" could not be read.',
        $path,
      ));
    }

    $definition = Yaml::decode($contents);
    if (!is_array($definition)) {
      throw new \RuntimeException(sprintf(
        'Vertical definition "%s" must contain a YAML mapping.',
        $path,
      ));
    }

    return $this->validate($definition, $verticalId, $path);
  }

  /**
   * Validates and normalizes one decoded definition.
   *
   * @param array<string, mixed> $definition
   *   Decoded YAML definition.
   * @param string $verticalId
   *   Expected vertical ID.
   * @param string $path
   *   Source path for operator-facing errors.
   *
   * @return array<string, mixed>
   *   Validated and normalized definition.
   */
  protected function validate(
    array $definition,
    string $verticalId,
    string $path,
  ): array {
    if (($definition['id'] ?? NULL) !== $verticalId) {
      throw $this->invalid($path, sprintf(
        'id must be "%s"',
        $verticalId,
      ));
    }
    if (!is_string($definition['label'] ?? NULL)
      || trim($definition['label']) === '') {
      throw $this->invalid($path, 'label must be a non-empty string');
    }

    foreach (['entities', 'overrides', 'statuses'] as $section) {
      if (isset($definition[$section]) && !is_array($definition[$section])) {
        throw $this->invalid($path, sprintf(
          '%s must be a locale mapping',
          $section,
        ));
      }
      $definition[$section] ??= [];
    }

    $this->validateEntities($definition['entities'], $path);
    $this->validateOverrides($definition['overrides'], $path);
    $this->validateStatuses($definition['statuses'], $path);

    return $definition;
  }

  /**
   * Validates locale-specific entity labels.
   *
   * @param array<string, mixed> $entities
   *   Entity label definitions.
   * @param string $path
   *   Definition source path.
   */
  protected function validateEntities(array $entities, string $path): void {
    foreach ($entities as $locale => $localeEntities) {
      $this->assertLocaleMapping($locale, $localeEntities, 'entities', $path);
      foreach (['jurisdiction', 'organisation'] as $entityType) {
        $labels = $localeEntities[$entityType] ?? NULL;
        if (!is_array($labels)) {
          throw $this->invalid($path, sprintf(
            'entities.%s.%s must contain singular and plural labels',
            $locale,
            $entityType,
          ));
        }
        foreach (['singular', 'plural'] as $form) {
          if (!is_string($labels[$form] ?? NULL)
            || trim($labels[$form]) === '') {
            throw $this->invalid($path, sprintf(
              'entities.%s.%s.%s must be a non-empty string',
              $locale,
              $entityType,
              $form,
            ));
          }
        }
      }
    }
  }

  /**
   * Validates locale-specific interface overrides.
   *
   * @param array<string, mixed> $overrides
   *   Interface override definitions.
   * @param string $path
   *   Definition source path.
   */
  protected function validateOverrides(array $overrides, string $path): void {
    foreach ($overrides as $locale => $localeOverrides) {
      $this->assertLocaleMapping($locale, $localeOverrides, 'overrides', $path);
      foreach ($localeOverrides as $key => $value) {
        if (!is_string($key) || trim($key) === ''
          || !is_string($value) || trim($value) === '') {
          throw $this->invalid($path, sprintf(
            'overrides.%s entries must have non-empty string keys and values',
            $locale,
          ));
        }
      }
    }
  }

  /**
   * Validates locale-specific status rename rules.
   *
   * @param array<string, mixed> $statuses
   *   Status rename definitions.
   * @param string $path
   *   Definition source path.
   */
  protected function validateStatuses(array $statuses, string $path): void {
    foreach ($statuses as $locale => $rules) {
      $this->assertLocaleMapping($locale, $rules, 'statuses', $path);
      $matches = [];
      $names = [];
      foreach ($rules as $index => $rule) {
        if (!is_array($rule)
          || !is_string($rule['match'] ?? NULL)
          || trim($rule['match']) === ''
          || !is_string($rule['name'] ?? NULL)
          || trim($rule['name']) === '') {
          throw $this->invalid($path, sprintf(
            'statuses.%s.%s must contain non-empty match and name strings',
            $locale,
            $index,
          ));
        }
        $normalizedMatch = mb_strtolower(trim($rule['match']));
        if (isset($matches[$normalizedMatch])) {
          throw $this->invalid($path, sprintf(
            'statuses.%s contains duplicate match "%s"',
            $locale,
            $rule['match'],
          ));
        }
        $matches[$normalizedMatch] = TRUE;
        $normalizedName = mb_strtolower(trim($rule['name']));
        if (isset($names[$normalizedName])) {
          throw $this->invalid($path, sprintf(
            'statuses.%s contains duplicate target name "%s"',
            $locale,
            $rule['name'],
          ));
        }
        $names[$normalizedName] = TRUE;
      }
    }
  }

  /**
   * Asserts a section item uses a supported locale mapping.
   *
   * @param mixed $locale
   *   Locale key.
   * @param mixed $mapping
   *   Locale value.
   * @param string $section
   *   Section name.
   * @param string $path
   *   Definition source path.
   */
  protected function assertLocaleMapping(
    mixed $locale,
    mixed $mapping,
    string $section,
    string $path,
  ): void {
    if (!is_string($locale)
      || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale) !== 1
      || !is_array($mapping)) {
      throw $this->invalid($path, sprintf(
        '%s must map locale IDs to mappings or lists',
        $section,
      ));
    }
  }

  /**
   * Creates a consistent invalid-definition exception.
   */
  protected function invalid(string $path, string $message): \RuntimeException {
    return new \RuntimeException(sprintf(
      'Invalid vertical definition "%s": %s.',
      $path,
      $message,
    ));
  }

  /**
   * Returns the absolute definition directory.
   */
  protected function getDirectory(): string {
    return $this->moduleExtensionList->getPath('markaspot_group')
      . '/' . self::DIRECTORY;
  }

}
