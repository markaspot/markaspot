<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Service;

/**
 * Validates and maps the deliberately limited runtime part of v1 documents.
 */
final class TenantRuntimeConfiguration {

  public const FEATURES = [
    'aiAnalysis', 'aiProcessing', 'operationsDashboard', 'statistics', 'photoReporting',
    'classicReporting', 'dashboard', 'feedback', 'aiDuplicates',
    'piiRedaction', 'privacyBlockOnFlag', 'moderation', 'personalAssignment',
  ];

  /**
   * Mirrors TenantSettingsController::MAX_PHOTO_REQUIRED_CATEGORIES.
   */
  public const MAX_PHOTO_REQUIRED_CATEGORIES = 1000;

  /**
   * Mirrors FeatureScopeResolver::MAX_CATEGORY_ID (JS safe integer).
   */
  public const MAX_CATEGORY_ID = 9007199254740991;

  public const LANGUAGES = [
    'de', 'en', 'cs', 'de-ls', 'es', 'fr', 'hu', 'it', 'pt',
    'tr', 'pl', 'nl', 'da', 'sv', 'nb', 'fi', 'uk', 'ar',
  ];

  /**
   * Checks runtime values without accessing storage or external services.
   */
  public static function validate(array $tenant): array {
    $errors = [];
    if (array_key_exists('boilerplates', $tenant)) {
      $errors = array_merge($errors, TenantBoilerplates::validate($tenant['boilerplates']));
    }
    if (isset($tenant['short_name']) && (!is_string($tenant['short_name']) || mb_strlen($tenant['short_name']) > 255)) {
      $errors[] = 'tenant.short_name must be a string no longer than 255 characters.';
    }
    foreach (['primary_color', 'secondary_color'] as $key) {
      if (isset($tenant[$key]) && $tenant[$key] !== '' && (!is_string($tenant[$key]) || !preg_match('/^#[a-f0-9]{6}$/i', $tenant[$key]))) {
        $errors[] = "tenant.$key must be a six-digit hexadecimal colour.";
      }
    }
    if (isset($tenant['font_family']) && (!is_string($tenant['font_family']) || strlen($tenant['font_family']) > 255 || !preg_match('/^[a-zA-Z0-9 ,\-]*$/D', $tenant['font_family']))) {
      $errors[] = 'tenant.font_family may contain only letters, numbers, spaces, commas and hyphens.';
    }
    if (isset($tenant['map_center'])) {
      $center = $tenant['map_center'];
      if (!is_array($center) || !array_is_list($center) || count($center) !== 2 || !(is_int($center[0] ?? NULL) || is_float($center[0] ?? NULL)) || !(is_int($center[1] ?? NULL) || is_float($center[1] ?? NULL)) || !is_finite((float) ($center[0] ?? NAN)) || !is_finite((float) ($center[1] ?? NAN)) || abs((float) $center[0]) > 180 || abs((float) $center[1]) > 90) {
        $errors[] = 'tenant.map_center must be [longitude, latitude] within valid coordinate bounds.';
      }
    }
    if (isset($tenant['map_zoom']) && (!is_int($tenant['map_zoom']) || $tenant['map_zoom'] < 1 || $tenant['map_zoom'] > 22)) {
      $errors[] = 'tenant.map_zoom must be an integer between 1 and 22.';
    }
    if (isset($tenant['languages'])) {
      $languages = $tenant['languages'];
      if (!is_array($languages) || !array_is_list($languages) || $languages === []) {
        $errors[] = 'tenant.languages must be a non-empty list of language codes.';
      }
      else {
        foreach ($languages as $language) {
          if (!is_string($language) || !in_array($language, self::LANGUAGES, TRUE)) {
            $errors[] = 'tenant.languages contains an invalid language code.';
          }
        }
      }
    }
    if (isset($tenant['features'])) {
      if (!is_array($tenant['features'])) {
        $errors[] = 'tenant.features must be an object of boolean feature flags.';
      }
      else {
        foreach ($tenant['features'] as $name => $value) {
          if ($name === 'unifiedReporting') {
            if (!is_array($value) || array_diff(array_keys($value), ['enabled', 'aiMode', 'photoPolicy']) !== []
              || !is_bool($value['enabled'] ?? NULL)
              || !in_array($value['aiMode'] ?? NULL, ['disabled', 'opt_in', 'opt_out'], TRUE)
              || !in_array($value['photoPolicy'] ?? NULL, ['optional', 'required', 'required_by_category'], TRUE)) {
              $errors[] = 'tenant.features.unifiedReporting requires enabled, aiMode and photoPolicy.';
            }
            continue;
          }
          if ($name === 'photoRequiredCategories') {
            // Same bounds as the dashboard API, so an import never stores a
            // list the dashboard cannot save again.
            if (!is_array($value) || !array_is_list($value)
              || array_filter($value, static fn($id): bool => !is_int($id) || $id < 1 || $id > self::MAX_CATEGORY_ID) !== []) {
              $errors[] = 'tenant.features.photoRequiredCategories must be a list of category term IDs.';
            }
            elseif (count(array_unique($value)) > self::MAX_PHOTO_REQUIRED_CATEGORIES) {
              $errors[] = 'tenant.features.photoRequiredCategories lists more than ' . self::MAX_PHOTO_REQUIRED_CATEGORIES . ' categories.';
            }
            continue;
          }
          if ($name === 'forms') {
            $form_flags = ['allowParentCategorySelection', 'lockPhotoLocation'];
            if (!is_array($value) || $value === [] || array_diff(array_keys($value), $form_flags) !== []
              || array_filter($value, static fn($flag): bool => !is_bool($flag)) !== []) {
              $errors[] = 'tenant.features.forms supports only the booleans allowParentCategorySelection and lockPhotoLocation.';
            }
            continue;
          }
          if (!in_array($name, [...self::FEATURES, 'publicReports'], TRUE) || !is_bool($value)) {
            $errors[] = "tenant.features.$name is unsupported or is not boolean.";
          }
        }
      }
    }
    if (array_key_exists('ai', $tenant)) {
      if (!is_array($tenant['ai']) || array_is_list($tenant['ai'])) {
        $errors[] = 'tenant.ai must be an object of boolean analysis options.';
      }
      else {
        foreach ($tenant['ai'] as $name => $value) {
          $providers = ['azure', 'openai', 'ionos', 'local_nlp'];
          if ($name === 'pii_provider' && is_string($value) && in_array($value, $providers, TRUE)) {
            continue;
          }
          if (!in_array($name, ['sentiment_analysis', 'detect_names'], TRUE) || !is_bool($value)) {
            $errors[] = "tenant.ai.$name is unsupported or is not boolean.";
          }
        }
      }
    }
    $features = is_array($tenant['features'] ?? NULL) ? $tenant['features'] : [];
    $ai = is_array($tenant['ai'] ?? NULL) ? $tenant['ai'] : [];
    if (($features['aiDuplicates'] ?? FALSE) === TRUE || (($ai['sentiment_analysis'] ?? FALSE) === TRUE)) {
      if (($features['aiProcessing'] ?? FALSE) !== TRUE) {
        $errors[] = 'Duplicate and sentiment analysis require explicit aiProcessing=true.';
      }
    }
    if (($ai['detect_names'] ?? FALSE) === TRUE && ($features['piiRedaction'] ?? FALSE) !== TRUE) {
      $errors[] = 'Name detection requires explicit piiRedaction=true.';
    }
    $urlSchemes = ['http', 'https'];
    foreach (['legal_notice_url', 'privacy_policy_url'] as $key) {
      if (isset($tenant[$key]) && $tenant[$key] !== '' && (!is_string($tenant[$key]) || !filter_var($tenant[$key], FILTER_VALIDATE_URL) || !in_array(parse_url($tenant[$key], PHP_URL_SCHEME), $urlSchemes, TRUE))) {
        $errors[] = "tenant.$key must be an absolute HTTP(S) URL.";
      }
    }
    foreach (['logo_file', 'logo_dark_file', 'boundary_file'] as $key) {
      if (isset($tenant[$key]) && !is_string($tenant[$key])) {
        $errors[] = "tenant.$key must be a relative file name.";
      }
    }
    return $errors;
  }

  /**
   * Plans global options for an owned, single-jurisdiction dedicated stack.
   *
   * Generic multi-tenant imports must never apply these platform settings.
   * Providers and credentials stay in the deployment's secret store.
   */
  public static function dedicatedSettings(array $tenant): array {
    $settings = [];
    foreach (['piiRedaction', 'privacyBlockOnFlag'] as $feature) {
      if (isset($tenant['features'][$feature])) {
        $settings['markaspot_nuxt.settings']['platform_features.' . $feature] = $tenant['features'][$feature];
      }
    }
    foreach (['aiDuplicates' => 'duplicate_detection.enabled', 'piiRedaction' => 'pii_redaction.enabled'] as $feature => $key) {
      if (isset($tenant['features'][$feature])) {
        $settings['markaspot_ai.settings'][$key] = $tenant['features'][$feature];
      }
    }
    foreach (['sentiment_analysis' => 'sentiment_analysis.enabled', 'detect_names' => 'pii_redaction.detect_names'] as $option => $key) {
      if (isset($tenant['ai'][$option])) {
        $settings['markaspot_ai.settings'][$key] = $tenant['ai'][$option];
      }
    }
    if (isset($tenant['ai']['pii_provider'])) {
      $settings['markaspot_ai.settings']['pii_redaction.provider'] = $tenant['ai']['pii_provider'];
    }
    return $settings;
  }

  /**
   * Merges supported input without overwriting unspecified stored settings.
   */
  public static function merge(array $existing, array $tenant): array {
    $patch = [];
    foreach (['primary_color' => 'primary', 'secondary_color' => 'secondary'] as $key => $target) {
      if (!empty($tenant[$key])) {
        $patch['theme'][$target] = $tenant[$key];
      }
    }
    if (!empty($tenant['font_family'])) {
      $patch['theme']['fonts'] = ['heading' => $tenant['font_family'], 'body' => $tenant['font_family']];
    }
    if (!empty($tenant['map_center'])) {
      $patch['map']['center'] = array_map('floatval', $tenant['map_center']);
    }
    if (isset($tenant['map_zoom'])) {
      $patch['map']['zoomInitial'] = $tenant['map_zoom'];
      $patch['map']['zoomLevel'] = $tenant['map_zoom'];
    }
    if (!empty($tenant['languages'])) {
      // List replacement is deliberate: recursive merge would retain old codes.
      $existing['languages'] = ['available' => $tenant['languages'], 'default' => $tenant['languages'][0]];
    }
    foreach (self::FEATURES as $feature) {
      if (isset($tenant['features'][$feature])) {
        $patch['features'][$feature] = $tenant['features'][$feature];
      }
    }
    if (isset($tenant['features']['unifiedReporting'])) {
      $patch['features']['unifiedReporting'] = $tenant['features']['unifiedReporting'];
    }
    // The category list replaces the stored one; a merge keeps removed IDs.
    if (isset($tenant['features']['photoRequiredCategories'])) {
      $existing['features']['photoRequiredCategories'] = array_values(array_unique($tenant['features']['photoRequiredCategories']));
    }
    foreach (['allowParentCategorySelection', 'lockPhotoLocation'] as $form_flag) {
      if (isset($tenant['features']['forms'][$form_flag])) {
        $patch['features']['forms'][$form_flag] = $tenant['features']['forms'][$form_flag];
      }
    }
    // Personal assignment is opt-in. Seeds write the off state explicitly so
    // it is visible in the data; explicit input and a stored decision win.
    if (!isset($patch['features']['personalAssignment']) && !isset($existing['features']['personalAssignment'])) {
      $patch['features']['personalAssignment'] = FALSE;
    }
    foreach (['label' => 'name', 'short_name' => 'shortName'] as $key => $target) {
      if (!empty($tenant[$key])) {
        $patch['client'][$target] = $tenant[$key];
      }
    }
    // Coordinates are a list too: replace rather than recursively extend.
    if (isset($patch['map']['center'])) {
      $existing['map']['center'] = $patch['map']['center'];
      unset($patch['map']['center']);
    }
    return array_replace_recursive($existing, $patch);
  }

  /**
   * Makes non-runtime input explicit rather than pretending it was applied.
   */
  public static function warnings(array $tenant): array {
    $supported = [
      'slug', 'label', 'short_name', 'platform_name', 'email', 'address',
      'primary_color', 'secondary_color', 'font_family', 'map_center', 'map_zoom',
      'languages', 'features', 'logo_file', 'logo_dark_file', 'boundary_file',
    ];
    $warnings = [];
    foreach ($tenant as $key => $value) {
      if (!in_array($key, $supported, TRUE) && $value !== '' && $value !== NULL && $value !== []) {
        $warnings[] = "tenant.$key is informational and was not applied to the runtime.";
      }
    }
    if (isset($tenant['features']['publicReports'])) {
      $warnings[] = 'publicReports is not applied: the requested visibility policy is unchanged and must be configured separately.';
    }
    return $warnings;
  }

}
