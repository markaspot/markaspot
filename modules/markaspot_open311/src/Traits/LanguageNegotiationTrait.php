<?php

namespace Drupal\markaspot_open311\Traits;

/**
 * Provides language negotiation methods for GeoReport REST resources.
 *
 * Classes using this trait must have:
 * - $languageManager: \Drupal\Core\Language\LanguageManagerInterface
 * - $requestStack: \Symfony\Component\HttpFoundation\RequestStack
 */
trait LanguageNegotiationTrait {

  /**
   * Resolves the language code from request parameters and headers.
   *
   * Priority order:
   * 1. Query parameter 'langcode' (for backwards compatibility and explicit override)
   * 2. Accept-Language HTTP header
   * 3. Site default language.
   *
   * @param array $parameters
   *   The query parameters from the request.
   *
   * @return string
   *   The resolved language code.
   */
  protected function resolveLanguageCode(array $parameters): string {
    $languages = $this->languageManager->getLanguages();
    $defaultLangcode = $this->languageManager->getDefaultLanguage()->getId();

    // Priority 1: Explicit query parameter (backwards compatibility).
    if (!empty($parameters['langcode'])) {
      $langcode = $parameters['langcode'];
      if (isset($languages[$langcode])) {
        return $langcode;
      }
      // Invalid langcode in query param - fall through to header.
    }

    // Priority 2: Accept-Language header.
    $request = $this->requestStack->getCurrentRequest();
    $acceptLanguage = $request->headers->get('Accept-Language');

    if ($acceptLanguage) {
      // Parse Accept-Language header to extract language codes.
      // Format: "de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7".
      $langcode = $this->parseAcceptLanguageHeader($acceptLanguage, $languages);
      if ($langcode) {
        return $langcode;
      }
    }

    // Priority 3: Site default language.
    return $defaultLangcode;
  }

  /**
   * Parses the Accept-Language header and returns the best matching language.
   *
   * @param string $acceptLanguage
   *   The Accept-Language header value.
   * @param array $availableLanguages
   *   Array of available language objects keyed by language code.
   *
   * @return string|null
   *   The best matching language code or null if no match found.
   */
  protected function parseAcceptLanguageHeader(string $acceptLanguage, array $availableLanguages): ?string {
    // Parse header into language-quality pairs.
    $languageRanges = [];
    $parts = explode(',', $acceptLanguage);

    foreach ($parts as $part) {
      $part = trim($part);
      if (empty($part)) {
        continue;
      }

      // Split on semicolon to separate language from quality factor.
      $segments = explode(';', $part);
      $lang = trim($segments[0]);
      $quality = 1.0;

      // Check for quality factor (q=0.x).
      if (isset($segments[1])) {
        $qPart = trim($segments[1]);
        if (preg_match('/^q=([0-9.]+)$/i', $qPart, $matches)) {
          $quality = (float) $matches[1];
        }
      }

      $languageRanges[$lang] = $quality;
    }

    // Sort by quality factor (highest first).
    arsort($languageRanges);

    // Find best match.
    foreach ($languageRanges as $lang => $quality) {
      // Try exact match first (e.g., "de" or "en").
      if (isset($availableLanguages[$lang])) {
        return $lang;
      }

      // Try base language from regional variant (e.g., "de" from "de-DE").
      $baseLang = strtok($lang, '-');
      if ($baseLang && isset($availableLanguages[$baseLang])) {
        return $baseLang;
      }
    }

    return NULL;
  }

}
