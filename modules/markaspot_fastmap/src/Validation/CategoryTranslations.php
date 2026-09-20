<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Validation;

/**
 * Validates category translations without changing their shared indexes.
 */
final class CategoryTranslations {

  /**
   * Normalizes complete language arrays, including legacy flat input.
   *
   * @param array $categories
   *   Language-keyed labels, or a legacy flat list of labels.
   * @param string $requestedLanguage
   *   The primary language, or an empty string for the legacy default.
   * @param string[] $allowedLanguages
   *   Supported language codes.
   *
   * @return array<string, string[]>
   *   Trimmed labels with unchanged language and category indexes.
   *
   * @throws \RuntimeException
   *   When labels are missing, invalid, or not aligned across languages.
   */
  public static function normalize(array $categories, string $requestedLanguage, array $allowedLanguages): array {
    if ($categories === []) {
      throw new \RuntimeException('categories must contain at least one non-empty string');
    }
    if ($requestedLanguage !== '' && !in_array($requestedLanguage, $allowedLanguages, TRUE)) {
      throw new \RuntimeException('Unsupported category language');
    }
    if (array_is_list($categories)) {
      $categories = [$requestedLanguage ?: 'en' => $categories];
    }
    if ($requestedLanguage !== '' && !array_key_exists($requestedLanguage, $categories)) {
      throw new \RuntimeException('Categories must include the primary language');
    }

    $normalized = [];
    $expectedCount = NULL;
    foreach ($categories as $language => $labels) {
      if (!is_string($language) || !in_array($language, $allowedLanguages, TRUE)) {
        throw new \RuntimeException('Unsupported category language');
      }
      if (!is_array($labels) || !array_is_list($labels) || $labels === []) {
        throw new \RuntimeException('Each category language must contain a complete list');
      }
      if (count($labels) > 30) {
        throw new \RuntimeException('Maximum 30 categories allowed');
      }
      $expectedCount ??= count($labels);
      if (count($labels) !== $expectedCount) {
        throw new \RuntimeException('Category translations must match the primary category count');
      }
      $normalized[$language] = [];
      foreach ($labels as $label) {
        if (!is_string($label) || trim($label) === '') {
          throw new \RuntimeException('Every category requires a non-empty translation');
        }
        $normalized[$language][] = mb_substr(trim($label), 0, 255);
      }
    }
    return $normalized;
  }

}
