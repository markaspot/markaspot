<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\Component\Utility\EmailValidator;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\markaspot_nuxt\Service\BoundaryGeoJsonValidator;
use CommerceGuys\Addressing\Country\CountryRepositoryInterface;
use enshrined\svgSanitize\Sanitizer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for tenant settings API endpoints.
 *
 * Provides endpoints to manage logos, general settings, language settings,
 * branding, features, map configuration, boundary, and navigation for
 * jurisdiction groups. General settings cover the platform name, contact
 * email, email footer text, and postal address. Language, feature, map, and
 * navigation settings are stored in the field_nuxt_config JSON blob; the
 * boundary lives directly on field_boundary as a raw GeoJSON string.
 *
 * All endpoints accept both numeric group IDs and URL slugs as the
 * jurisdiction_id parameter via JurisdictionIdResolverTrait.
 */
final class TenantSettingsController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * Feature flags that hold a simple boolean value.
   *
   * Used to validate incoming PATCH data for the features endpoint.
   */
  const SIMPLE_FEATURE_FLAGS = [
    'photoReporting',
    'classicReporting',
    'voting',
    'statistics',
    'following',
    'passwordless',
    'aiAnalysis',
    'aiProcessing',
    'piiRedaction',
    'feedback',
    'pwaInstallPrompt',
    'objectId',
    'party',
    'formFirst',
    'dashboard',
    'dashboardRequestCreate',
    'operationsDashboard',
    'contactForm',
    'privacyBlockOnFlag',
    'onboardingTour',
    'loginLink',
    'delegationNoteRequired',
    'assignmentSyncsOrganisation',
  ];

  /**
   * Feature flags that hold a nested object with an 'enabled' boolean.
   *
   * Used to validate incoming PATCH data for the features endpoint.
   */
  const NESTED_FEATURE_FLAGS = [
    'emergency',
    'funFacts',
    'search',
    'boundaries',
    'privacyNotice',
  ];

  /**
   * Feature flags stored below features.forms.
   *
   * Used to validate generic report-form behaviour flags. Client-specific
   * form features stay outside this allowlist.
   */
  const FORM_FEATURE_FLAGS = [
    'allowParentCategorySelection',
  ];

  /**
   * Allowed dashboard column IDs.
   *
   * Used to validate incoming PATCH data for the dashboard endpoint.
   */
  const DASHBOARD_COLUMN_IDS = [
    'service_request_id',
    'category',
    'status',
    'visibility',
    'hazard_level',
    'hazard_category',
    'sentiment',
    'group',
    'district',
    'sublocality',
    'location',
    'created',
    'updated',
    'nid',
    'lat',
    'lon',
    'status_notes',
  ];

  /**
   * Map settings keys that hold a boolean value.
   *
   * Used to validate incoming PATCH data for the map endpoint.
   */
  const MAP_BOOLEAN_KEYS = [
    'loadMarkersOnInit',
    'enableBoundsFiltering',
    'deferredMap',
  ];

  /**
   * Curated wording preset IDs.
   *
   * Must match WORDING_PRESET_IDS in the frontend (app/utils/i18nOverrides.ts).
   */
  const WORDING_PRESETS = ['report', 'suggestion', 'entry', 'contribution'];

  /**
   * Maximum raw JSON body size for text override updates.
   */
  const TEXT_OVERRIDES_MAX_PAYLOAD_BYTES = 65536;

  /**
   * Maximum number of text override keys per locale.
   */
  const TEXT_OVERRIDES_MAX_KEYS_PER_LOCALE = 300;

  /**
   * Flat dotted-key pattern accepted by the Nuxt i18n override consumer.
   */
  const TEXT_OVERRIDE_KEY_PATTERN = '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/i';

  /**
   * Complete vue-i18n placeholder token accepted inside override values.
   */
  const TEXT_OVERRIDE_PLACEHOLDER_PATTERN = '/\{[a-zA-Z_][a-zA-Z0-9_]*\}/';

  /**
   * Dotted path segments that are unsafe for object unflattening.
   */
  const TEXT_OVERRIDE_BLOCKED_PATH_SEGMENTS = [
    '__proto__',
    'prototype',
    'constructor',
  ];

  /**
   * Full i18n override keys that the Nuxt consumer intentionally ignores.
   */
  const TEXT_OVERRIDE_BLOCKED_KEYS = [
    'fields.field_terms_of_use',
  ];

  /**
   * Supported locales with display names.
   *
   * Must match the frontend config/locales.ts definitions.
   */
  const SUPPORTED_LOCALES = [
    'de' => 'Deutsch',
    'en' => 'English',
    'cs' => 'Čeština',
    'de-ls' => 'Einfache Sprache',
    'es' => 'Español',
    'fr' => 'Français',
    'hu' => 'Magyar',
    'it' => 'Italiano',
    'pt' => 'Português',
    'tr' => 'Türkçe',
    'pl' => 'Polski',
    'nl' => 'Nederlands',
    'da' => 'Dansk',
    'sv' => 'Svenska',
    'nb' => 'Norsk bokmål',
    'fi' => 'Suomi',
    'uk' => 'Українська',
    'ar' => 'العربية',
  ];

  /**
   * Mapping of locale codes to ISO 639-1/3166 codes.
   */
  const LOCALE_ISO_CODES = [
    'de' => 'de-DE',
    'en' => 'en-US',
    'cs' => 'cs-CZ',
    'de-ls' => 'de-DE',
    'es' => 'es-ES',
    'fr' => 'fr-FR',
    'hu' => 'hu-HU',
    'it' => 'it-IT',
    'pt' => 'pt-PT',
    'tr' => 'tr-TR',
    'pl' => 'pl-PL',
    'nl' => 'nl-NL',
    'da' => 'da-DK',
    'sv' => 'sv-SE',
    'nb' => 'nb-NO',
    'fi' => 'fi-FI',
    'uk' => 'uk-UA',
    'ar' => 'ar-SA',
  ];

  /**
   * Valid Tailwind CSS color palette names.
   *
   * Used to validate theme color values that are not HEX codes.
   */
  const VALID_TAILWIND_PALETTES = [
    'slate', 'gray', 'zinc', 'neutral', 'stone',
    // Tailwind v4.2+ warm/organic neutrals.
    'mauve', 'olive', 'mist', 'taupe',
    'red', 'orange', 'amber', 'yellow', 'lime',
    'green', 'emerald', 'teal', 'cyan', 'sky',
    'blue', 'indigo', 'violet', 'purple', 'fuchsia',
    'pink', 'rose',
  ];

  /**
   * Fields exposed by the general settings endpoints.
   *
   * Only these fields may be read or written via GET/PATCH general settings.
   */
  const GENERAL_SETTINGS_ALLOWED_FIELDS = [
    'field_platform_name',
    'field_jurisdiction_e_mail',
    'field_email_footer',
    'field_jurisdiction_address',
    'field_visibility',
    'field_legal_notice',
    'field_privacy_policy',
  ];

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected StreamWrapperManagerInterface $streamWrapperManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The file repository service.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected FileRepositoryInterface $fileRepository;

  /**
   * The jurisdiction hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface
   */
  protected JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * The email validator.
   *
   * @var \Drupal\Component\Utility\EmailValidator
   */
  protected EmailValidator $emailValidator;

  /**
   * The country repository (optional, from address module).
   *
   * @var \CommerceGuys\Addressing\Country\CountryRepositoryInterface|null
   */
  protected ?CountryRepositoryInterface $countryRepository;

  /**
   * Constructs a TenantSettingsController.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    FileSystemInterface $file_system,
    FileRepositoryInterface $file_repository,
    AccountInterface $current_user,
    JurisdictionHierarchyResolverInterface $hierarchy_resolver,
    EmailValidator $email_validator,
    ?CountryRepositoryInterface $country_repository,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->fileSystem = $file_system;
    $this->fileRepository = $file_repository;
    $this->currentUser = $current_user;
    $this->hierarchyResolver = $hierarchy_resolver;
    $this->emailValidator = $email_validator;
    $this->countryRepository = $country_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('stream_wrapper_manager'),
      $container->get('file_system'),
      $container->get('file.repository'),
      $container->get('current_user'),
      $container->get('markaspot_group.hierarchy_resolver'),
      $container->get('email.validator'),
      $container->has('address.country_repository')
        ? $container->get('address.country_repository')
        : NULL,
    );
  }

  /**
   * Access check for the tenant settings endpoints.
   *
   * Grants access to Drupal administrators and users who hold the
   * jur-tenant_admin group role in any jurisdiction group.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier from the route (numeric ID or slug).
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function accessCheck(AccountInterface $account, string $jurisdiction_id): AccessResultInterface {
    // Resolve slug to numeric ID.
    $resolved_id = $this->resolveJurisdictionId($jurisdiction_id);
    if ($resolved_id === NULL) {
      return AccessResult::forbidden('Jurisdiction not found.')
        ->addCacheContexts(['url.path']);
    }

    // User 1 (superadmin) always has access — bypasses all checks.
    if ((int) $account->id() === 1) {
      return AccessResult::allowed()->addCacheContexts(['user']);
    }

    // Drupal administrators always have access.
    if (in_array('administrator', $account->getRoles(), TRUE)) {
      return AccessResult::allowed()->addCacheContexts(['user.roles']);
    }

    // Decisions below depend on the user's group memberships. Tag the result
    // with the membership-list cache tag so it invalidates when a membership
    // is added, removed, or its roles change — without it a demoted admin
    // would keep a cached allow. Mirrors GroupMembership::loadByUser().
    $membership_cache_tag = 'group_relationship_list:plugin:group_membership:entity:' . $account->id();

    // tenant_admin role: allow access if the requested jurisdiction falls
    // within the hierarchy (self + descendants) of any jurisdiction where
    // the user holds tenant_admin membership.
    if (in_array('tenant_admin', $account->getRoles(), TRUE)) {
      $admin_roles = array_values(array_unique([
        $this->getJurisdictionGroupType() . '-tenant_admin',
        'jur-tenant_admin',
      ]));
      foreach (GroupMembership::loadByUser($account, $admin_roles) as $relationship) {
        $managedGroup = $relationship->getGroup();
        if (!$this->isJurisdictionGroup($managedGroup)) {
          continue;
        }
        $managedJurId = (int) $managedGroup->id();
        $scopeIds = $this->hierarchyResolver->getDescendantIds($managedJurId);
        if (in_array($resolved_id, $scopeIds, TRUE)) {
          return AccessResult::allowed()
            ->addCacheContexts(['user'])
            ->addCacheTags([$membership_cache_tag]);
        }
      }
    }

    return AccessResult::forbidden('User is not an administrator or tenant admin for this jurisdiction.')
      ->addCacheContexts(['user', 'user.roles'])
      ->addCacheTags([$membership_cache_tag]);
  }

  /**
   * Loads a jurisdiction group entity from a slug or numeric ID.
   *
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The loaded group entity, or NULL if not found or wrong bundle.
   */
  private function loadJurisdictionGroup(string $jurisdiction_id) {
    $resolved_id = $this->resolveJurisdictionId($jurisdiction_id);
    if ($resolved_id === NULL) {
      return NULL;
    }

    $group = $this->entityTypeManager()->getStorage('group')->load($resolved_id);
    if (!$this->isJurisdictionGroup($group)) {
      return NULL;
    }

    return $group;
  }

  /**
   * Reads the field_nuxt_config JSON from the default translation.
   *
   * Field_nuxt_config is non-translatable config data that is only saved on
   * the entity's original language. When the entity is loaded in a non-default
   * translation, this field may be empty. This helper always reads from the
   * default translation to ensure the config is never missing.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity (any translation).
   *
   * @return array
   *   The decoded JSON config, or an empty array if not set.
   */
  private function getNuxtConfig(GroupInterface $group): array {
    $source = $group->isDefaultTranslation() ? $group : $group->getUntranslated();
    if ($source->hasField('field_nuxt_config') && !$source->get('field_nuxt_config')->isEmpty()) {
      $decoded = json_decode($source->get('field_nuxt_config')->value, TRUE);
      if (is_array($decoded)) {
        return $decoded;
      }
    }
    return [];
  }

  /**
   * Returns stored text overrides in the tenant-settings API shape.
   *
   * @param array $config
   *   Decoded field_nuxt_config.
   *
   * @return array|\stdClass
   *   Stored flat override maps, or an empty object when none exist.
   */
  private function getStoredTextOverrides(array $config): array|\stdClass {
    $overrides = $config['i18n']['overrides'] ?? [];
    if (!is_array($overrides) || $overrides === []) {
      return new \stdClass();
    }

    return $overrides;
  }

  /**
   * Checks vue-i18n brace usage in an override value.
   *
   * Values may include complete placeholder tokens such as {count}. Any other
   * brace would be handed to vue-i18n's message compiler and is rejected here.
   *
   * @param string $value
   *   Override value to check.
   *
   * @return bool
   *   TRUE when every brace belongs to a complete placeholder token.
   */
  private function textOverrideBracesAreSafe(string $value): bool {
    $withoutPlaceholders = preg_replace(self::TEXT_OVERRIDE_PLACEHOLDER_PATTERN, '', $value);
    if ($withoutPlaceholders === NULL) {
      return FALSE;
    }

    return !str_contains($withoutPlaceholders, '{') && !str_contains($withoutPlaceholders, '}');
  }

  /**
   * Checks dotted override keys for path segments unsafe for unflattening.
   *
   * @param string $key
   *   Flat dotted override key.
   *
   * @return bool
   *   TRUE when the key can be safely unflattened by the frontend consumer.
   */
  private function textOverrideKeySegmentsAreSafe(string $key): bool {
    $segments = explode('.', $key);
    foreach ($segments as $segment) {
      if (in_array($segment, self::TEXT_OVERRIDE_BLOCKED_PATH_SEGMENTS, TRUE)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Checks whether a full dotted key is allowed by the frontend consumer.
   *
   * @param string $key
   *   Flat dotted override key.
   *
   * @return bool
   *   TRUE when the key is supported by the runtime override consumer.
   */
  private function textOverrideKeyIsAllowed(string $key): bool {
    return !in_array($key, self::TEXT_OVERRIDE_BLOCKED_KEYS, TRUE);
  }

  /**
   * Checks whether a text override value contains HTML markup delimiters.
   *
   * Some existing i18n messages are rendered through v-html in the Nuxt app.
   * Tenant overrides are therefore kept to plain text for v1.
   *
   * @param string $value
   *   Override value to check.
   *
   * @return bool
   *   TRUE when the value does not contain HTML angle brackets.
   */
  private function textOverrideHtmlIsSafe(string $value): bool {
    return !str_contains($value, '<') && !str_contains($value, '>');
  }

  /**
   * Normalises a stored feature flag to the boolean dashboard API shape.
   *
   * Field_nuxt_config may store selected features either as a bare boolean or
   * as an object with an enabled value. The dashboard feature endpoint exposes
   * the compact boolean representation so existing controls keep their shape.
   *
   * @param array $features
   *   The decoded features config.
   * @param string $key
   *   The feature key to read.
   * @param bool $default
   *   The fallback value when the feature is not set or has an unsupported
   *   shape.
   *
   * @return bool
   *   The normalised boolean value.
   */
  private function getBooleanFeatureValue(array $features, string $key, bool $default): bool {
    $value = $features[$key] ?? NULL;
    if (is_bool($value)) {
      return $value;
    }
    if (is_array($value) && is_bool($value['enabled'] ?? NULL)) {
      return $value['enabled'];
    }
    return $default;
  }

  /**
   * Reads generic form feature flags from canonical and legacy config paths.
   *
   * Features.forms is the canonical path written by the dashboard. Top-level
   * forms is kept as a read-only fallback for older JSON UI tenant configs.
   * Explicit features.forms values win.
   *
   * @param array $config
   *   Decoded field_nuxt_config.
   *
   * @return array
   *   Merged form feature settings.
   */
  private function getFormFeatureSettings(array $config): array {
    $legacy_forms = is_array($config['forms'] ?? NULL) ? $config['forms'] : [];
    $feature_forms = is_array($config['features']['forms'] ?? NULL) ? $config['features']['forms'] : [];

    return array_replace($legacy_forms, $feature_forms);
  }

  /**
   * Checks whether the jurisdiction tier may use Operations Overview.
   */
  private function canUseOperationsDashboard(GroupInterface $group): bool {
    if (!$group->hasField('field_tier')) {
      return TRUE;
    }

    if ($group->get('field_tier')->isEmpty()) {
      return FALSE;
    }

    return in_array((string) $group->get('field_tier')->value, ['pro', 'heart'], TRUE);
  }

  /**
   * Checks whether the jurisdiction tier may use case assignment.
   */
  private function canUseCaseAssignment(GroupInterface $group): bool {
    $tier_group = $this->effectiveCaseAssignmentTierGroup($group);
    if (!$tier_group->hasField('field_tier')) {
      return TRUE;
    }

    if ($tier_group->get('field_tier')->isEmpty()) {
      return TRUE;
    }

    return in_array((string) $tier_group->get('field_tier')->value, ['pro', 'heart'], TRUE);
  }

  /**
   * Resolves the group whose tier controls case assignment.
   */
  private function effectiveCaseAssignmentTierGroup(GroupInterface $group): GroupInterface {
    $root_id = $this->hierarchyResolver->getRootJurisdictionId((int) $group->id());
    if ($root_id === NULL || $root_id === (int) $group->id()) {
      return $group;
    }

    $root = $this->entityTypeManager()
      ->getStorage('group')
      ->load($root_id);

    return $root instanceof GroupInterface ? $root : $group;
  }

  /**
   * Handles logo upload for a jurisdiction group entity.
   *
   * Accepts multipart/form-data with logo_light and/or logo_dark file fields.
   * Validates file type (SVG, PNG) and size (max 500KB). Saves to the group
   * entity's field_logo_light / field_logo_dark fields.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request with uploaded file(s).
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with updated logo URLs on success, or error message.
   */
  public function uploadLogo(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    // Validate that at least one file was uploaded.
    $uploadedFiles = $request->files;
    $logoLight = $uploadedFiles->get('logo_light');
    $logoDark = $uploadedFiles->get('logo_dark');
    $logoLightPng = $uploadedFiles->get('logo_light_png');
    $logoDarkPng = $uploadedFiles->get('logo_dark_png');

    if (!$logoLight && !$logoDark) {
      return new JsonResponse(['error' => 'No logo file provided. Use logo_light or logo_dark field.'], 400);
    }

    $logos = [];
    $errors = [];
    $warnings = [];

    // Process logo_light.
    if ($logoLight) {
      $result = $this->processLogoUpload(
        $logoLight,
        $group,
        'field_logo_light',
        'logo_light',
        $logoLightPng instanceof UploadedFile ? $logoLightPng : NULL,
      );
      if ($result['success']) {
        $logos['light'] = $result['url'];
        $warnings = array_merge($warnings, $result['warnings'] ?? []);
      }
      else {
        $errors[] = $result['error'];
      }
    }

    // Process logo_dark.
    if ($logoDark) {
      $result = $this->processLogoUpload(
        $logoDark,
        $group,
        'field_logo_dark',
        'logo_dark',
        $logoDarkPng instanceof UploadedFile ? $logoDarkPng : NULL,
      );
      if ($result['success']) {
        $logos['dark'] = $result['url'];
        $warnings = array_merge($warnings, $result['warnings'] ?? []);
      }
      else {
        $errors[] = $result['error'];
      }
    }

    // If any valid logo was processed, save the group entity.
    if (!empty($logos)) {
      try {
        $group->save();
      }
      catch (\Exception $e) {
        $this->getLogger('markaspot_nuxt')->error(
          'Failed to save group @id after logo upload: @message',
          ['@id' => $group->id(), '@message' => $e->getMessage()]
        );
        return new JsonResponse(['error' => 'Failed to save logo to group entity.'], 500);
      }
    }

    if (!empty($errors) && empty($logos)) {
      return new JsonResponse(['error' => implode(' ', $errors)], 400);
    }

    $response = [
      'status' => 'ok',
      'jurisdiction_id' => (int) $group->id(),
      'logos' => $logos,
    ];

    if (!empty($errors)) {
      $response['warnings'] = $errors;
    }
    if (!empty($warnings)) {
      $response['warnings'] = array_merge($response['warnings'] ?? [], $warnings);
    }

    $this->getLogger('markaspot_nuxt')->notice(
      'User @user uploaded logo(s) for jurisdiction @id: @logos',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@id' => $group->id(),
        '@logos' => implode(', ', array_keys($logos)),
      ]
    );

    return new JsonResponse($response);
  }

  /**
   * Validates and saves a single uploaded logo file to a group field.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $uploadedFile
   *   The uploaded file from the request.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group entity.
   * @param string $fieldName
   *   The group field name (field_logo_light or field_logo_dark).
   * @param string $fileKey
   *   Human-readable key for error messages (logo_light or logo_dark).
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile|null $pngFallbackFile
   *   Optional client-generated PNG fallback for SVG logos.
   *
   * @return array
   *   Result array with 'success' bool, 'url', optional 'warnings', or 'error'.
   */
  protected function processLogoUpload(
    UploadedFile $uploadedFile,
    $group,
    string $fieldName,
    string $fileKey,
    ?UploadedFile $pngFallbackFile = NULL,
  ): array {
    // Validate file type: only SVG and PNG are allowed.
    $allowedExtensions = ['svg', 'png'];
    $extension = strtolower($uploadedFile->getClientOriginalExtension());

    if (!in_array($extension, $allowedExtensions, TRUE)) {
      return [
        'success' => FALSE,
        'error' => "Invalid file type for $fileKey. Only SVG and PNG files are allowed.",
      ];
    }

    // Validate file size: max 500KB.
    $maxSizeBytes = 500 * 1024;
    if ($uploadedFile->getSize() > $maxSizeBytes) {
      return [
        'success' => FALSE,
        'error' => "File too large for $fileKey. Maximum size is 500KB.",
      ];
    }

    // Check the group has the required field.
    if (!$group->hasField($fieldName)) {
      return [
        'success' => FALSE,
        'error' => "Field $fieldName does not exist on the jurisdiction group.",
      ];
    }

    // Prepare the upload directory.
    $uploadDir = 'public://jurisdictions/' . $group->id() . '/logos';
    if (!$this->fileSystem->prepareDirectory($uploadDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      return [
        'success' => FALSE,
        'error' => "Failed to prepare upload directory for $fileKey.",
      ];
    }

    // Build destination filename: sanitize the original filename.
    $originalName = $uploadedFile->getClientOriginalName();
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
    $destination = $uploadDir . '/' . $safeName;
    $previousFallbackUri = $this->getExistingLogoPngFallbackUri($group, $fieldName);

    // Save the file using Drupal's file repository (handles managed files).
    try {
      $fileData = file_get_contents($uploadedFile->getPathname());
      if ($fileData === FALSE) {
        return [
          'success' => FALSE,
          'error' => "Failed to read uploaded file for $fileKey.",
        ];
      }

      if ($extension === 'svg') {
        $fileData = $this->sanitizeSvgLogoData($fileData, $fileKey);
        if ($fileData === NULL) {
          return [
            'success' => FALSE,
            'error' => "Invalid SVG file for $fileKey.",
          ];
        }
      }
      elseif (!$this->isPngImageData($fileData)) {
        return [
          'success' => FALSE,
          'error' => "Invalid PNG file for $fileKey.",
        ];
      }

      $file = $this->fileRepository->writeData(
        $fileData,
        $destination,
        FileExists::Replace
      );

      if (!$file) {
        return [
          'success' => FALSE,
          'error' => "Failed to save file for $fileKey.",
        ];
      }

      // Make the file permanent (not temporary).
      $file->setPermanent();
      $file->save();

    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->error(
        'File save failed for @key: @message',
        ['@key' => $fileKey, '@message' => $e->getMessage()]
      );
      return [
        'success' => FALSE,
        'error' => "File save error for $fileKey: " . $e->getMessage(),
      ];
    }

    $warnings = [];
    $mailFallbackUrl = NULL;
    $mailFallbackUri = NULL;
    if ($extension === 'svg' && $pngFallbackFile !== NULL) {
      $fallback = $this->saveLogoPngFallback($pngFallbackFile, $uploadDir, $safeName, $fileKey);
      if ($fallback['success']) {
        $mailFallbackUrl = $fallback['url'];
        $mailFallbackUri = $fallback['uri'] ?? NULL;
      }
      else {
        $warnings[] = $fallback['error'];
      }
    }

    if ($previousFallbackUri !== NULL && $previousFallbackUri !== $mailFallbackUri) {
      $this->deleteFileIfReadable($previousFallbackUri);
    }

    // Assign the file to the group field.
    $group->set($fieldName, ['target_id' => $file->id()]);

    // Build the URL for the response (relative path,
    // same pattern as getMarkASpotSettings).
    $uri = $file->getFileUri();
    $url = $this->buildPublicFileUrl($uri);

    return [
      'success' => TRUE,
      'url' => $url,
      'mail_fallback_url' => $mailFallbackUrl,
      'warnings' => $warnings,
    ];
  }

  /**
   * Sanitizes uploaded SVG logo data before it is stored publicly.
   */
  protected function sanitizeSvgLogoData(string $fileData, string $fileKey): ?string {
    $sanitizer = new Sanitizer();
    $sanitizer->removeRemoteReferences(TRUE);
    try {
      $clean = $sanitizer->sanitize($fileData);
    }
    catch (\Throwable) {
      $clean = FALSE;
    }
    if (!is_string($clean) || trim($clean) === '' || preg_match('/<svg[\s>]/i', $clean) !== 1) {
      $this->getLogger('markaspot_nuxt')->warning(
        'Rejected invalid SVG logo upload for @key.',
        ['@key' => $fileKey]
      );
      return NULL;
    }

    $clean = (string) preg_replace('/^<\?xml[^?]*\?>\s*/i', '', $clean);
    $clean = (string) preg_replace('/<!DOCTYPE[^>]*>\s*/i', '', $clean);
    $trimmed = trim($clean);

    return $trimmed !== '' ? $trimmed : NULL;
  }

  /**
   * Verifies PNG data using the file signature and image metadata.
   */
  protected function isPngImageData(string $fileData): bool {
    if (!str_starts_with($fileData, "\x89PNG\r\n\x1A\n")) {
      return FALSE;
    }

    $imageInfo = @getimagesizefromstring($fileData);
    return is_array($imageInfo) && ($imageInfo[2] ?? NULL) === IMAGETYPE_PNG;
  }

  /**
   * Validates and stores the client-generated PNG fallback for SVG mails.
   *
   * @return array
   *   Result array with 'success' bool, 'url', or 'error'.
   */
  protected function saveLogoPngFallback(
    UploadedFile $uploadedFile,
    string $uploadDir,
    string $safeSvgName,
    string $fileKey,
  ): array {
    $extension = strtolower($uploadedFile->getClientOriginalExtension());
    if ($extension !== 'png') {
      return [
        'success' => FALSE,
        'error' => "Invalid PNG fallback for $fileKey.",
      ];
    }

    $maxSizeBytes = 500 * 1024;
    if ($uploadedFile->getSize() > $maxSizeBytes) {
      return [
        'success' => FALSE,
        'error' => "PNG fallback too large for $fileKey. Maximum size is 500KB.",
      ];
    }

    $fileData = file_get_contents($uploadedFile->getPathname());
    if ($fileData === FALSE) {
      return [
        'success' => FALSE,
        'error' => "Failed to read PNG fallback for $fileKey.",
      ];
    }

    if (!$this->isPngImageData($fileData)) {
      return [
        'success' => FALSE,
        'error' => "Invalid PNG fallback for $fileKey.",
      ];
    }

    $baseName = pathinfo($safeSvgName, PATHINFO_FILENAME);
    $destination = $uploadDir . '/' . $baseName . '.png';
    try {
      $uri = $this->fileSystem->saveData($fileData, $destination, FileExists::Replace);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->warning(
        'PNG fallback save failed for @key: @message',
        ['@key' => $fileKey, '@message' => $e->getMessage()]
      );
      return [
        'success' => FALSE,
        'error' => "PNG fallback save error for $fileKey: " . $e->getMessage(),
      ];
    }

    if (!is_string($uri) || $uri === '') {
      return [
        'success' => FALSE,
        'error' => "Failed to save PNG fallback for $fileKey.",
      ];
    }

    return [
      'success' => TRUE,
      'uri' => $uri,
      'url' => $this->buildPublicFileUrl($uri),
    ];
  }

  /**
   * Builds a public-facing relative file URL from a Drupal stream URI.
   */
  protected function buildPublicFileUrl(string $uri): string {
    $scheme = StreamWrapperManager::getScheme($uri);
    if ($scheme === 'public') {
      $target = StreamWrapperManager::getTarget($uri);
      $publicPath = PublicStream::basePath();
      return '/' . $publicPath . '/' . $target;
    }
    return '/' . str_replace('://', '/', $uri);
  }

  /**
   * Deletes logo(s) from a jurisdiction group entity.
   *
   * Accepts a query parameter 'variant' to specify which logo to delete:
   * 'logo_light', 'logo_dark', or 'both' (default). Removes the file reference
   * from the group entity and deletes the underlying file entity.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with deletion status, or error message.
   */
  public function deleteLogo(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $variant = $request->query->get('variant');
    $validVariants = ['logo_light', 'logo_dark', 'both'];
    if (!$variant || !in_array($variant, $validVariants, TRUE)) {
      return new JsonResponse([
        'error' => 'Missing or invalid variant parameter. Use logo_light, logo_dark, or both.',
      ], 400);
    }

    $fieldsToDelete = [];
    if ($variant === 'both') {
      $fieldsToDelete = ['field_logo_light', 'field_logo_dark'];
    }
    else {
      $fieldsToDelete = ['field_' . $variant];
    }

    $deleted = [];
    $filesToDelete = [];
    $fileStorage = $this->entityTypeManager()->getStorage('file');

    foreach ($fieldsToDelete as $fieldName) {
      if (!$group->hasField($fieldName)) {
        continue;
      }

      $fieldValue = $group->get($fieldName)->getValue();
      if (!empty($fieldValue[0]['target_id'])) {
        // Collect file entities for deletion after successful save.
        $file = $fileStorage->load($fieldValue[0]['target_id']);
        if ($file) {
          $filesToDelete[] = $file;
        }
      }

      // Clear the field on the group entity.
      $group->set($fieldName, NULL);
      $deleted[] = str_replace('field_', '', $fieldName);
    }

    if (empty($deleted)) {
      return new JsonResponse([
        'status' => 'ok',
        'message' => 'No logos to delete.',
        'jurisdiction_id' => (int) $group->id(),
      ]);
    }

    try {
      $group->save();
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->error(
        'Failed to save group @id after logo deletion: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to save group entity after logo deletion.'], 500);
    }

    // Delete file entities only after successful group save — but skip any
    // file still referenced by the other logo variant. Light and dark logos
    // may point at the same file; deleting it for one variant must not break
    // the other. The PNG fallback follows the same keep/delete decision.
    $referencedFids = [];
    foreach (['field_logo_light', 'field_logo_dark'] as $logoField) {
      if ($group->hasField($logoField)) {
        $remaining = $group->get($logoField)->getValue();
        if (!empty($remaining[0]['target_id'])) {
          $referencedFids[] = (int) $remaining[0]['target_id'];
        }
      }
    }
    foreach ($filesToDelete as $file) {
      if (in_array((int) $file->id(), $referencedFids, TRUE)) {
        continue;
      }
      $fallbackUri = $this->getLogoPngFallbackUri((string) $file->getFileUri());
      $file->delete();
      if ($fallbackUri !== NULL) {
        $this->deleteFileIfReadable($fallbackUri);
      }
    }

    $this->getLogger('markaspot_nuxt')->notice(
      'User @user deleted logo(s) for jurisdiction @id: @logos',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@id' => $group->id(),
        '@logos' => implode(', ', $deleted),
      ]
    );

    return new JsonResponse([
      'status' => 'ok',
      'jurisdiction_id' => (int) $group->id(),
      'deleted' => $deleted,
    ]);
  }

  /**
   * Returns general settings for a jurisdiction group.
   *
   * Reads field_platform_name, field_jurisdiction_e_mail, field_email_footer,
   * and field_jurisdiction_address from the group entity and returns them as
   * JSON.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with current general settings, or an error response.
   */
  public function getGeneralSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $address = NULL;
    if ($group->hasField('field_jurisdiction_address') && !$group->get('field_jurisdiction_address')->isEmpty()) {
      $addressItem = $group->get('field_jurisdiction_address')->first();
      $address = [
        'country_code' => $addressItem->get('country_code')->getValue() ?? '',
        'organization' => $addressItem->get('organization')->getValue() ?? '',
        'address_line1' => $addressItem->get('address_line1')->getValue() ?? '',
        'locality' => $addressItem->get('locality')->getValue() ?? '',
        'postal_code' => $addressItem->get('postal_code')->getValue() ?? '',
      ];
    }

    // Provide available countries from the address module so the frontend
    // does not need a hardcoded list.
    $countries = [];
    if ($this->countryRepository) {
      $countries = $this->countryRepository->getList();
    }

    return new JsonResponse([
      'jurisdiction_id' => (int) $group->id(),
      'field_platform_name' => $group->hasField('field_platform_name') && !$group->get('field_platform_name')->isEmpty()
        ? $group->get('field_platform_name')->value
        : '',
      'field_jurisdiction_e_mail' => $group->hasField('field_jurisdiction_e_mail') && !$group->get('field_jurisdiction_e_mail')->isEmpty()
        ? $group->get('field_jurisdiction_e_mail')->value
        : '',
      'field_email_footer' => $group->hasField('field_email_footer') && !$group->get('field_email_footer')->isEmpty()
        ? $group->get('field_email_footer')->value
        : '',
      'field_visibility' => $group->hasField('field_visibility') && !$group->get('field_visibility')->isEmpty()
        ? $group->get('field_visibility')->value
        : 'public',
      'field_jurisdiction_address' => $address,
      'field_legal_notice' => $group->hasField('field_legal_notice') && !$group->get('field_legal_notice')->isEmpty()
        ? $group->get('field_legal_notice')->value
        : '',
      'field_privacy_policy' => $group->hasField('field_privacy_policy') && !$group->get('field_privacy_policy')->isEmpty()
        ? $group->get('field_privacy_policy')->value
        : '',
      'available_countries' => $countries,
    ]);
  }

  /**
   * Updates general settings for a jurisdiction group.
   *
   * Accepts a JSON body with any subset of the allowed fields. Each provided
   * value is validated individually before being written to the entity. An
   * entity-level validation pass runs before the final save.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request carrying a JSON body.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with updated general settings, or an error response.
   */
  public function updateGeneralSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $body = $request->getContent();
    $data = json_decode($body, TRUE);

    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
    }

    // Strip any keys not in the allowlist.
    $data = array_intersect_key($data, array_flip(self::GENERAL_SETTINGS_ALLOWED_FIELDS));

    if (empty($data)) {
      return new JsonResponse(['error' => 'No valid fields provided.'], 400);
    }

    if (array_key_exists('field_visibility', $data)) {
      $currentVisibility = $group->hasField('field_visibility') && !$group->get('field_visibility')->isEmpty()
        ? (string) $group->get('field_visibility')->value
        : 'public';
      $requestedVisibility = is_string($data['field_visibility']) ? $data['field_visibility'] : '';
      if (($currentVisibility === 'blocked' || $requestedVisibility === 'blocked')
        && !$this->currentUserCanManageWorkspaceBlock()) {
        return new JsonResponse(['error' => 'Only platform administrators can block or unblock a workspace.'], 403);
      }
    }

    // TOCTOU recheck against the freshest DB state. Runs even when the PATCH
    // does not touch field_visibility, because $group->save() writes the full
    // entity row: an in-memory field_visibility loaded BEFORE an admin's
    // concurrent block would otherwise silently overwrite that block. Placed
    // before field validation so blocked-workspace rejections short-circuit
    // and don't spend cycles validating fields that won't be saved.
    $fresh = $this->entityTypeManager->getStorage('group')->loadUnchanged($group->id());
    $freshVisibility = $fresh && $fresh->hasField('field_visibility') && !$fresh->get('field_visibility')->isEmpty()
      ? (string) $fresh->get('field_visibility')->value
      : 'public';
    if ($freshVisibility === 'blocked' && !$this->currentUserCanManageWorkspaceBlock()) {
      return new JsonResponse(['error' => 'Only platform administrators can block or unblock a workspace.'], 403);
    }
    // For privileged callers on a fresh-blocked workspace who did not patch
    // field_visibility themselves, preserve the fresh blocked state so the
    // in-memory stale value doesn't get re-written by save().
    if ($freshVisibility === 'blocked' && !array_key_exists('field_visibility', $data)) {
      $group->set('field_visibility', 'blocked');
    }

    // Validate each supplied field before touching the entity.
    foreach ($data as $fieldName => $value) {
      $error = $this->validateFieldValue($fieldName, $value);
      if ($error !== NULL) {
        return new JsonResponse(['error' => $error], 422);
      }
    }

    // Apply validated values to the group entity.
    foreach ($data as $fieldName => $value) {
      if (!$group->hasField($fieldName)) {
        continue;
      }
      $group->set($fieldName, $value);
    }

    // Validate only the fields that were actually changed, not the entire
    // entity. Full entity validation would fail on unrelated fields (e.g.
    // address module constraints on country-specific formats).
    // Skip field_jurisdiction_address: the address module enforces personal
    // name fields (given_name, family_name) for certain countries, which do
    // not apply to organizational jurisdiction addresses. Our own validation
    // in validateFieldValue() is sufficient.
    $skipFieldValidation = ['field_jurisdiction_address'];
    $validationErrors = [];
    foreach (array_keys($data) as $fieldName) {
      if (!$group->hasField($fieldName)) {
        continue;
      }
      if (in_array($fieldName, $skipFieldValidation, TRUE)) {
        continue;
      }
      $fieldViolations = $group->get($fieldName)->validate();
      foreach ($fieldViolations as $violation) {
        $validationErrors[] = $fieldName . '.' . $violation->getPropertyPath() . ': ' . $violation->getMessage();
      }
    }
    if (!empty($validationErrors)) {
      return new JsonResponse(['error' => 'Validation failed.', 'details' => $validationErrors], 422);
    }

    try {
      $group->save();
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->error(
        'Failed to save general settings for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to save general settings.'], 500);
    }

    $this->getLogger('markaspot_nuxt')->notice(
      'User @user updated general settings for jurisdiction @id (fields: @fields)',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@id' => $group->id(),
        '@fields' => implode(', ', array_keys($data)),
      ]
    );

    // Return current general settings (same shape as GET).
    return $this->getGeneralSettings($request, $jurisdiction_id);
  }

  /**
   * Returns language settings for a jurisdiction group.
   *
   * Reads the languages key from the field_nuxt_config JSON blob on the group
   * entity and returns it along with the full list of supported locales.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with current language settings and supported locales.
   */
  public function getLanguageSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $config = $this->getNuxtConfig($group);

    // Extract languages with sensible defaults.
    $languages = $config['languages'] ?? [
      'default' => 'de',
      'available' => ['de'],
    ];

    // Build the supported_locales list for the frontend.
    $supportedLocales = [];
    foreach (self::SUPPORTED_LOCALES as $code => $name) {
      $supportedLocales[] = [
        'code' => $code,
        'name' => $name,
      ];
    }

    return new JsonResponse([
      'jurisdiction_id' => (int) $group->id(),
      'languages' => $languages,
      'supported_locales' => $supportedLocales,
    ]);
  }

  /**
   * Updates language settings for a jurisdiction group.
   *
   * Accepts a JSON body with default and available locale codes. Validates
   * all codes against SUPPORTED_LOCALES, then updates only the languages key
   * inside the field_nuxt_config JSON blob.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request carrying a JSON body.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with updated language settings, or an error response.
   */
  public function updateLanguageSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $body = $request->getContent();
    $data = json_decode($body, TRUE);

    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
    }

    // Validate required keys.
    if (!isset($data['available']) || !is_array($data['available']) || empty($data['available'])) {
      return new JsonResponse(['error' => 'available must be a non-empty array of locale codes.'], 422);
    }

    if (!isset($data['default']) || !is_string($data['default'])) {
      return new JsonResponse(['error' => 'default must be a string locale code.'], 422);
    }

    // Validate all locale codes against supported locales.
    $validCodes = array_keys(self::SUPPORTED_LOCALES);
    foreach ($data['available'] as $code) {
      if (!is_string($code) || !in_array($code, $validCodes, TRUE)) {
        return new JsonResponse([
          'error' => "Unsupported locale code: $code. Supported: " . implode(', ', $validCodes) . '.',
        ], 422);
      }
    }

    // Default must be in the available list.
    if (!in_array($data['default'], $data['available'], TRUE)) {
      return new JsonResponse(['error' => 'default locale must be present in available list.'], 422);
    }

    // Optional curated wording preset (frontend merges the matching
    // i18n bundle at runtime; the backend only stores the choice).
    $wording = $data['wording'] ?? NULL;
    if ($wording !== NULL && (!is_string($wording) || !in_array($wording, self::WORDING_PRESETS, TRUE))) {
      return new JsonResponse([
        'error' => 'wording must be one of: ' . implode(', ', self::WORDING_PRESETS) . '.',
      ], 422);
    }

    // Read-modify-write: load existing config, update only the languages key.
    $config = [];
    if ($group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $decoded = json_decode($group->get('field_nuxt_config')->value, TRUE);
      if (is_array($decoded)) {
        $config = $decoded;
      }
    }

    // Build the locales sub-key with name and ISO code
    // for each available locale.
    $locales = [];
    foreach ($data['available'] as $code) {
      $locales[] = [
        'code' => $code,
        'name' => self::SUPPORTED_LOCALES[$code],
        'iso' => self::LOCALE_ISO_CODES[$code] ?? $code,
      ];
    }

    $config['languages'] = [
      'default' => $data['default'],
      'available' => array_values($data['available']),
      'locales' => $locales,
    ];

    if ($wording !== NULL) {
      $config['i18n']['wording'] = $wording;
    }

    // Write back the full config JSON.
    $group->set('field_nuxt_config', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    // Validate only the fields that were actually changed, not the entire
    // entity. Full entity validation would fail on unrelated fields (e.g.
    // address module constraints on country-specific formats).
    $validationErrors = [];
    foreach (array_keys($data) as $fieldName) {
      if (!$group->hasField($fieldName)) {
        continue;
      }
      $fieldViolations = $group->get($fieldName)->validate();
      foreach ($fieldViolations as $violation) {
        $validationErrors[] = $fieldName . '.' . $violation->getPropertyPath() . ': ' . $violation->getMessage();
      }
    }
    if (!empty($validationErrors)) {
      return new JsonResponse(['error' => 'Validation failed.', 'details' => $validationErrors], 422);
    }

    try {
      $group->save();
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->error(
        'Failed to save language settings for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to save language settings.'], 500);
    }

    $this->getLogger('markaspot_nuxt')->notice(
      'User @user updated language settings for jurisdiction @id (default: @default, available: @available)',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@id' => $group->id(),
        '@default' => $data['default'],
        '@available' => implode(', ', $data['available']),
      ]
    );

    // Return the current state (same shape as GET).
    return $this->getLanguageSettings($request, $jurisdiction_id);
  }

  /**
   * Returns text override settings for a jurisdiction group.
   *
   * Reads i18n.overrides from the field_nuxt_config JSON blob. The stored
   * shape is a map of locale codes to flat dotted-key string maps, matching
   * the Nuxt i18n override consumer.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with the current text overrides, or an error response.
   */
  public function getTextOverrideSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $config = $this->getNuxtConfig($group);

    return new JsonResponse([
      'overrides' => $this->getStoredTextOverrides($config),
    ]);
  }

  /**
   * Updates text override settings for a jurisdiction group.
   *
   * Accepts a JSON body with i18n overrides in the runtime shape:
   * locale code to flat dotted-key string map. Replaces i18n.overrides
   * wholesale while preserving i18n.wording and all sibling config keys.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request carrying a JSON body.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with updated text overrides, or an error response.
   */
  public function updateTextOverrideSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $body = $request->getContent();
    if (strlen($body) > self::TEXT_OVERRIDES_MAX_PAYLOAD_BYTES) {
      return new JsonResponse([
        'error' => sprintf(
          'Request payload exceeds the %d byte limit.',
          self::TEXT_OVERRIDES_MAX_PAYLOAD_BYTES
        ),
      ], 422);
    }

    $document = json_decode($body);
    $data = json_decode($body, TRUE);
    if (!$document instanceof \stdClass
      || !is_array($data)
      || !property_exists($document, 'overrides')
      || !array_key_exists('overrides', $data)) {
      return new JsonResponse(['error' => 'Invalid JSON body: missing overrides key.'], 400);
    }

    if (!$document->overrides instanceof \stdClass || !is_array($data['overrides'])) {
      return new JsonResponse(['error' => 'overrides must be an object.'], 422);
    }

    $validLocales = array_keys(self::SUPPORTED_LOCALES);
    $sanitized = [];

    foreach (get_object_vars($document->overrides) as $locale => $localeOverridesDocument) {
      if (!in_array($locale, $validLocales, TRUE)) {
        return new JsonResponse([
          'error' => "Unsupported locale code: $locale. Supported: " . implode(', ', $validLocales) . '.',
        ], 422);
      }

      if (!$localeOverridesDocument instanceof \stdClass || !is_array($data['overrides'][$locale] ?? NULL)) {
        return new JsonResponse(['error' => "overrides.$locale must be an object."], 422);
      }

      $localeOverrides = $data['overrides'][$locale];
      if (count($localeOverrides) > self::TEXT_OVERRIDES_MAX_KEYS_PER_LOCALE) {
        return new JsonResponse([
          'error' => sprintf(
            'overrides.%s must not exceed %d keys.',
            $locale,
            self::TEXT_OVERRIDES_MAX_KEYS_PER_LOCALE
          ),
        ], 422);
      }

      foreach ($localeOverrides as $key => $value) {
        if (!is_string($key) || !preg_match(self::TEXT_OVERRIDE_KEY_PATTERN, $key)) {
          return new JsonResponse(['error' => "overrides.$locale contains an invalid dotted key: $key."], 422);
        }

        if (!$this->textOverrideKeySegmentsAreSafe($key)) {
          return new JsonResponse([
            'error' => "overrides.$locale contains an unsafe dotted key segment: $key.",
          ], 422);
        }

        if (!$this->textOverrideKeyIsAllowed($key)) {
          return new JsonResponse([
            'error' => "overrides.$locale contains an unsupported dotted key: $key.",
          ], 422);
        }

        if (!is_string($value)) {
          return new JsonResponse(['error' => "overrides.$locale.$key must be a string."], 422);
        }

        if ($value === '') {
          continue;
        }

        if (!$this->textOverrideHtmlIsSafe($value)) {
          return new JsonResponse([
            'error' => "overrides.$locale.$key must be plain text and must not contain HTML angle brackets.",
          ], 422);
        }

        if (!$this->textOverrideBracesAreSafe($value)) {
          return new JsonResponse([
            'error' => "overrides.$locale.$key contains unsupported brace syntax. "
              . 'Use complete placeholders such as {count}.',
          ], 422);
        }

        $sanitized[$locale][$key] = $value;
      }
    }

    $config = $this->getNuxtConfig($group);
    if (!isset($config['i18n']) || !is_array($config['i18n'])) {
      $config['i18n'] = [];
    }
    $config['i18n']['overrides'] = $sanitized === [] ? new \stdClass() : $sanitized;

    $group->set('field_nuxt_config', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    try {
      $group->save();
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->error(
        'Failed to save text override settings for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to save text override settings.'], 500);
    }

    $this->getLogger('markaspot_nuxt')->notice(
      'User @user updated text override settings for jurisdiction @id (locales: @locales)',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@id' => $group->id(),
        '@locales' => implode(', ', array_keys($sanitized)),
      ]
    );

    return $this->getTextOverrideSettings($request, $jurisdiction_id);
  }

  /**
   * Returns branding settings for a jurisdiction group.
   *
   * Reads theme colors (primary, secondary, neutral) from the field_nuxt_config
   * JSON blob and custom CSS from the field_custom_css standalone field.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with current branding settings, or an error response.
   */
  public function getBrandingSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $config = $this->getNuxtConfig($group);

    $theme = $config['theme'] ?? [];

    // Read custom CSS from the standalone field.
    $customCss = '';
    if ($group->hasField('field_custom_css') && !$group->get('field_custom_css')->isEmpty()) {
      $customCss = $group->get('field_custom_css')->value;
    }

    return new JsonResponse([
      'jurisdiction_id' => (int) $group->id(),
      'theme' => [
        'primary' => $theme['primary'] ?? '',
        'secondary' => $theme['secondary'] ?? '',
        'neutral' => $theme['neutral'] ?? '',
      ],
      'fonts' => [
        'heading' => $theme['fonts']['heading'] ?? '',
        'body' => $theme['fonts']['body'] ?? '',
      ],
      'custom_css' => $customCss,
    ]);
  }

  /**
   * Updates branding settings for a jurisdiction group.
   *
   * Accepts a JSON body with theme colors and/or custom CSS. Theme colors are
   * written into the field_nuxt_config JSON blob (read-modify-write), while
   * custom CSS is written to the field_custom_css standalone field.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request carrying a JSON body.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with updated branding settings, or an error response.
   */
  public function updateBrandingSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $body = $request->getContent();
    $data = json_decode($body, TRUE);

    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
    }

    $hasTheme = isset($data['theme']) && is_array($data['theme']);
    $hasCss = array_key_exists('custom_css', $data);
    $hasFonts = isset($data['fonts']) && is_array($data['fonts']);

    if (!$hasTheme && !$hasCss && !$hasFonts) {
      return new JsonResponse(['error' => 'No valid fields provided. Supply theme, fonts, and/or custom_css.'], 400);
    }

    // Validate theme color values.
    if ($hasTheme) {
      $colorKeys = ['primary', 'secondary', 'neutral'];
      foreach ($colorKeys as $key) {
        if (isset($data['theme'][$key])) {
          $error = $this->validateColorValue($data['theme'][$key]);
          if ($error !== NULL) {
            return new JsonResponse(['error' => "theme.$key: $error"], 422);
          }
        }
      }
    }

    // Validate custom CSS.
    if ($hasCss) {
      if (!is_string($data['custom_css'])) {
        return new JsonResponse(['error' => 'custom_css must be a string.'], 422);
      }
      $cssError = $this->validateCustomCss($data['custom_css']);
      if ($cssError !== NULL) {
        return new JsonResponse(['error' => "custom_css: $cssError"], 422);
      }
    }

    // Validate font values: alphanumeric, spaces, hyphens, commas only.
    if ($hasFonts) {
      $fontPattern = '/^[a-zA-Z0-9\s\-,]*$/';
      foreach (['heading', 'body'] as $fontKey) {
        if (isset($data['fonts'][$fontKey])) {
          if (!is_string($data['fonts'][$fontKey])) {
            return new JsonResponse(['error' => "fonts.$fontKey must be a string."], 422);
          }
          if (!preg_match($fontPattern, $data['fonts'][$fontKey])) {
            return new JsonResponse(['error' => "fonts.$fontKey may only contain alphanumeric characters, spaces, hyphens, and commas."], 422);
          }
        }
      }
    }

    // Read-modify-write: load existing config, update only theme colors.
    if ($hasTheme || $hasFonts) {
      $config = [];
      if ($group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
        $decoded = json_decode($group->get('field_nuxt_config')->value, TRUE);
        if (is_array($decoded)) {
          $config = $decoded;
        }
      }

      if (!isset($config['theme'])) {
        $config['theme'] = [];
      }

      if ($hasTheme) {
        $colorKeys = ['primary', 'secondary', 'neutral'];
        foreach ($colorKeys as $key) {
          if (isset($data['theme'][$key])) {
            $config['theme'][$key] = $data['theme'][$key];
          }
        }
      }

      if ($hasFonts) {
        if (!isset($config['theme']['fonts'])) {
          $config['theme']['fonts'] = [];
        }
        foreach (['heading', 'body'] as $fontKey) {
          if (isset($data['fonts'][$fontKey])) {
            $config['theme']['fonts'][$fontKey] = $data['fonts'][$fontKey];
          }
        }
      }

      $group->set('field_nuxt_config', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // Write custom CSS to the standalone field.
    if ($hasCss && $group->hasField('field_custom_css')) {
      $group->set('field_custom_css', $data['custom_css']);
    }

    try {
      $group->save();
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->error(
        'Failed to save branding settings for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to save branding settings.'], 500);
    }

    $this->getLogger('markaspot_nuxt')->notice(
      'User @user updated branding settings for jurisdiction @id',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@id' => $group->id(),
      ]
    );

    // Return the current state (same shape as GET).
    return $this->getBrandingSettings($request, $jurisdiction_id);
  }

  /**
   * Marks the first-run branding setup as completed for a jurisdiction.
   *
   * Sets field_nuxt_config['setup']['brandingCompleted'] = TRUE on the group.
   * Used by the FastMap dashboard first-run flow to record that an admin has
   * finished (or explicitly completed via Save) the initial branding setup, so
   * the redirect-to-setup gate stays off on subsequent dashboard visits.
   *
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with the new setup state, or an error response.
   */
  public function markBrandingSetupCompleted(string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $config = $this->getNuxtConfig($group);
    if (!isset($config['setup']) || !is_array($config['setup'])) {
      $config['setup'] = [];
    }
    $config['setup']['brandingCompleted'] = TRUE;

    $group->set('field_nuxt_config', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    try {
      $group->save();
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->error(
        'Failed to mark branding setup completed for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to mark setup completed.'], 500);
    }

    $this->getLogger('markaspot_nuxt')->notice(
      'User @user marked first-run branding setup completed for jurisdiction @id',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@id' => $group->id(),
      ]
    );

    return new JsonResponse([
      'jurisdiction_id' => (int) $group->id(),
      'setup' => [
        'brandingCompleted' => TRUE,
      ],
    ]);
  }

  /**
   * Returns feature settings for a jurisdiction group.
   *
   * Reads the features key from the field_nuxt_config JSON blob on the group
   * entity and returns it with sensible defaults for all known feature flags.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with current feature settings, or an error response.
   */
  public function getFeatureSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $config = $this->getNuxtConfig($group);

    $features = $config['features'] ?? [];
    $forms = $this->getFormFeatureSettings($config);
    $operations_dashboard = $this->getBooleanFeatureValue($features, 'operationsDashboard', FALSE);
    if (!$this->canUseOperationsDashboard($group)) {
      $operations_dashboard = FALSE;
    }

    return new JsonResponse([
      'jurisdiction_id' => (int) $group->id(),
      'features' => [
        // All simple flags go through getBooleanFeatureValue(): legacy
        // tenant configs still store some of them in the richer object
        // form (e.g. classicReporting: {enabled, formFirst, bottomSheet})
        // and the settings UI round-trips whatever we emit here, so raw
        // passthrough breaks the PATCH validation with a 422.
        'photoReporting' => $this->getBooleanFeatureValue($features, 'photoReporting', TRUE),
        'classicReporting' => $this->getBooleanFeatureValue($features, 'classicReporting', FALSE),
        'voting' => $this->getBooleanFeatureValue($features, 'voting', FALSE),
        'statistics' => $this->getBooleanFeatureValue($features, 'statistics', FALSE),
        'following' => $this->getBooleanFeatureValue($features, 'following', FALSE),
        'passwordless' => $this->getBooleanFeatureValue($features, 'passwordless', FALSE),
        // Visibility of the citizen footer sign-in link; /auth/login itself
        // stays reachable regardless of this flag.
        'loginLink' => $this->getBooleanFeatureValue($features, 'loginLink', TRUE),
        'aiAnalysis' => $this->getBooleanFeatureValue($features, 'aiAnalysis', FALSE),
        'aiProcessing' => $this->getBooleanFeatureValue($features, 'aiProcessing', FALSE),
        'piiRedaction' => $this->getBooleanFeatureValue($features, 'piiRedaction', FALSE),
        'feedback' => $this->getBooleanFeatureValue($features, 'feedback', FALSE),
        'pwaInstallPrompt' => $this->getBooleanFeatureValue($features, 'pwaInstallPrompt', FALSE),
        'objectId' => $this->getBooleanFeatureValue($features, 'objectId', FALSE),
        'party' => $this->getBooleanFeatureValue($features, 'party', FALSE),
        'formFirst' => $this->getBooleanFeatureValue($features, 'formFirst', FALSE),
        'dashboard' => $this->getBooleanFeatureValue($features, 'dashboard', TRUE),
        'dashboardRequestCreate' => $this->getBooleanFeatureValue($features, 'dashboardRequestCreate', TRUE),
        'operationsDashboard' => $operations_dashboard,
        'caseAssignment' => $this->canUseCaseAssignment($group),
        'contactForm' => $this->getBooleanFeatureValue($features, 'contactForm', FALSE),
        'privacyBlockOnFlag' => $this->getBooleanFeatureValue($features, 'privacyBlockOnFlag', FALSE),
        'delegationNoteRequired' => $this->getBooleanFeatureValue($features, 'delegationNoteRequired', FALSE),
        'assignmentSyncsOrganisation' => $this->getBooleanFeatureValue($features, 'assignmentSyncsOrganisation', FALSE),
        // Guided onboarding tour (citizen report + dashboard walkthrough).
        // Default follows the operating mode: on for SaaS workspaces, off
        // for self-hosted/enterprise installs where it is opt-in via this
        // flag (see MailBrandingService for the operating-mode pattern).
        'onboardingTour' => $this->getBooleanFeatureValue($features, 'onboardingTour', Settings::get('markaspot_operating_mode', 'self_hosted') === 'saas'),
        'emergency' => ['enabled' => $features['emergency']['enabled'] ?? FALSE],
        'funFacts' => ['enabled' => $features['funFacts']['enabled'] ?? FALSE],
        'search' => ['enabled' => $features['search']['enabled'] ?? TRUE],
        'boundaries' => ['enabled' => $features['boundaries']['enabled'] ?? FALSE],
        'privacyNotice' => ['enabled' => $features['privacyNotice']['enabled'] ?? FALSE],
        'forms' => [
          'allowParentCategorySelection' => $this->getBooleanFeatureValue($forms, 'allowParentCategorySelection', FALSE),
        ],
      ],
      'capabilities' => [
        // Authoritative tier gate for the Operations Overview toggle. The
        // frontend reads this instead of re-deriving tier rules, so the
        // settings switch can never diverge from the backend enforcement
        // (updateFeatureSettings scrubs the flag to FALSE when not permitted).
        'operationsDashboard' => $this->canUseOperationsDashboard($group),
      ],
    ]);
  }

  /**
   * Updates feature settings for a jurisdiction group.
   *
   * Accepts a JSON body with feature flag values. Validates all values against
   * the known feature flag constants, then updates only the features key inside
   * the field_nuxt_config JSON blob. Keys not in the allowlist are preserved
   * (e.g. analytics, geocoding).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request carrying a JSON body.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with updated feature settings, or an error response.
   */
  public function updateFeatureSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $body = $request->getContent();
    $data = json_decode($body, TRUE);

    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
    }

    // Accept the legacy object form for simple flags and normalise it to a
    // plain boolean before validation: older tenant configs stored e.g.
    // classicReporting as {enabled, formFirst, bottomSheet} and clients may
    // round-trip that shape. Persisting afterwards writes the plain boolean,
    // healing the stored config on the first save. Legacy sub-options
    // (formFirst, bottomSheet) are dropped deliberately: they have been
    // runtime-dead on 2.x (useFormFirstMode and the bottom sheet read their
    // own top-level config), and resurrecting stale intent here would flip
    // citizen-facing behaviour as a save side effect.
    foreach (self::SIMPLE_FEATURE_FLAGS as $flag) {
      if (array_key_exists($flag, $data) && is_array($data[$flag]) && is_bool($data[$flag]['enabled'] ?? NULL)) {
        $data[$flag] = $data[$flag]['enabled'];
      }
    }

    // Validate simple boolean feature flags.
    foreach (self::SIMPLE_FEATURE_FLAGS as $flag) {
      if (array_key_exists($flag, $data) && !is_bool($data[$flag])) {
        return new JsonResponse(['error' => "$flag must be a boolean."], 422);
      }
    }

    // Validate nested feature flags (object with 'enabled' boolean).
    foreach (self::NESTED_FEATURE_FLAGS as $flag) {
      if (array_key_exists($flag, $data)) {
        if (!is_array($data[$flag]) || !array_key_exists('enabled', $data[$flag])) {
          return new JsonResponse(['error' => "$flag must be an object with an 'enabled' boolean."], 422);
        }
        if (!is_bool($data[$flag]['enabled'])) {
          return new JsonResponse(['error' => "$flag.enabled must be a boolean."], 422);
        }
      }
    }

    // Validate generic form behaviour flags stored below features.forms.
    if (array_key_exists('forms', $data)) {
      if (!is_array($data['forms'])) {
        return new JsonResponse(['error' => 'forms must be an object.'], 422);
      }
      foreach (self::FORM_FEATURE_FLAGS as $flag) {
        if (array_key_exists($flag, $data['forms']) && !is_bool($data['forms'][$flag])) {
          return new JsonResponse(['error' => "forms.$flag must be a boolean."], 422);
        }
      }
    }

    if (($data['operationsDashboard'] ?? FALSE) === TRUE && !$this->canUseOperationsDashboard($group)) {
      $data['operationsDashboard'] = FALSE;
    }

    // Read-modify-write: load existing config, update only the features key.
    $config = [];
    if ($group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $decoded = json_decode($group->get('field_nuxt_config')->value, TRUE);
      if (is_array($decoded)) {
        $config = $decoded;
      }
    }

    if (!isset($config['features'])) {
      $config['features'] = [];
    }

    // Apply only known feature flags from the request, preserving all others.
    // Special case: formFirst can be an object with config options. Preserve
    // the existing object when the dashboard sends true.
    $allKnownFlags = array_merge(self::SIMPLE_FEATURE_FLAGS, self::NESTED_FEATURE_FLAGS);
    foreach ($data as $key => $value) {
      if (in_array($key, $allKnownFlags, TRUE)) {
        if ($key === 'formFirst' && $value === TRUE && is_array($config['features']['formFirst'] ?? NULL)) {
          // Preserve existing formFirst config object when enabling.
          continue;
        }
        $config['features'][$key] = $value;
      }
    }

    if (array_key_exists('forms', $data)) {
      if (!isset($config['features']['forms']) || !is_array($config['features']['forms'])) {
        $config['features']['forms'] = [];
      }
      foreach (self::FORM_FEATURE_FLAGS as $flag) {
        if (array_key_exists($flag, $data['forms'])) {
          $config['features']['forms'][$flag] = $data['forms'][$flag];
        }
      }
    }

    // Write back the full config JSON.
    $group->set('field_nuxt_config', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    try {
      $group->save();
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->error(
        'Failed to save feature settings for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to save feature settings.'], 500);
    }

    $this->getLogger('markaspot_nuxt')->notice(
      'User @user updated feature settings for jurisdiction @id (flags: @flags)',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@id' => $group->id(),
        '@flags' => implode(', ', array_keys($data)),
      ]
    );

    // Return the current state (same shape as GET).
    return $this->getFeatureSettings($request, $jurisdiction_id);
  }

  /**
   * Returns map settings for a jurisdiction group.
   *
   * Reads the map key from the field_nuxt_config JSON blob on the group
   * entity and returns it with sensible defaults.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with current map settings, or an error response.
   */
  public function getMapSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $config = $this->getNuxtConfig($group);

    $map = $config['map'] ?? [];

    return new JsonResponse([
      'jurisdiction_id' => (int) $group->id(),
      'map' => [
        'center' => $map['center'] ?? [0, 0],
        'zoom' => (int) ($map['zoom'] ?? 13),
        'maxBounds' => $map['maxBounds'] ?? NULL,
        'loadMarkersOnInit' => $map['loadMarkersOnInit'] ?? TRUE,
        'enableBoundsFiltering' => $map['enableBoundsFiltering'] ?? TRUE,
        'deferredMap' => !empty($map['deferredMap']),
        'layers' => $map['layers'] ?? new \stdClass(),
        'controls' => $map['controls'] ?? new \stdClass(),
      ],
    ]);
  }

  /**
   * Updates map settings for a jurisdiction group.
   *
   * Accepts a JSON body with map configuration values. Validates all values
   * against an allowlist, then updates only the map key inside the
   * field_nuxt_config JSON blob. Infrastructure keys like mapbox_style,
   * mapbox_token, and fallback_style are rejected as admin-only.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request carrying a JSON body.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with updated map settings, or an error response.
   */
  public function updateMapSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $body = $request->getContent();
    $data = json_decode($body, TRUE);

    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
    }

    // Block admin-only infrastructure keys.
    $infraKeys = [
      'mapbox_style', 'mapbox_token', 'fallback_style',
      'style', 'token', 'apiKey',
    ];
    foreach ($infraKeys as $key) {
      if (array_key_exists($key, $data)) {
        return new JsonResponse(['error' => "$key is an admin-only setting and cannot be changed via this endpoint."], 403);
      }
    }

    // Allowlist of keys this endpoint accepts.
    $allowedKeys = [
      'center', 'zoom', 'maxBounds',
      'loadMarkersOnInit', 'enableBoundsFiltering', 'deferredMap',
      'layers', 'controls',
    ];

    // Strip any keys not in the allowlist.
    $data = array_intersect_key($data, array_flip($allowedKeys));

    if (empty($data)) {
      return new JsonResponse(['error' => 'No valid map settings provided.'], 400);
    }

    // Validate center: array of exactly 2 numbers.
    if (array_key_exists('center', $data)) {
      if (!is_array($data['center']) || count($data['center']) !== 2
        || !is_numeric($data['center'][0]) || !is_numeric($data['center'][1])) {
        return new JsonResponse(['error' => 'center must be an array of exactly 2 numbers [lng, lat].'], 422);
      }
      $data['center'] = [(float) $data['center'][0], (float) $data['center'][1]];
    }

    // Validate zoom: integer between 1 and 22.
    if (array_key_exists('zoom', $data)) {
      if (!is_int($data['zoom']) || $data['zoom'] < 1 || $data['zoom'] > 22) {
        return new JsonResponse(['error' => 'zoom must be an integer between 1 and 22.'], 422);
      }
    }

    // Validate maxBounds: null or array of 2 arrays each with 2 numbers.
    if (array_key_exists('maxBounds', $data)) {
      if ($data['maxBounds'] !== NULL) {
        if (!is_array($data['maxBounds']) || count($data['maxBounds']) !== 2
          || !is_array($data['maxBounds'][0]) || count($data['maxBounds'][0]) !== 2
          || !is_array($data['maxBounds'][1]) || count($data['maxBounds'][1]) !== 2
          || !is_numeric($data['maxBounds'][0][0]) || !is_numeric($data['maxBounds'][0][1])
          || !is_numeric($data['maxBounds'][1][0]) || !is_numeric($data['maxBounds'][1][1])) {
          return new JsonResponse(['error' => 'maxBounds must be null or [[sw_lng, sw_lat], [ne_lng, ne_lat]].'], 422);
        }
      }
    }

    // Validate boolean keys.
    foreach (self::MAP_BOOLEAN_KEYS as $key) {
      if (array_key_exists($key, $data) && !is_bool($data[$key])) {
        return new JsonResponse(['error' => "$key must be a boolean."], 422);
      }
    }

    // Validate controls: allowlist known sub-keys. Values must be boolean or
    // an object with an enabled boolean.
    $validControls = ['zoom', 'tilt', 'theme', 'geolocation', 'heatmap', 'reports', 'attribution'];
    if (array_key_exists('controls', $data)) {
      if (!is_array($data['controls'])) {
        return new JsonResponse(['error' => 'controls must be an object.'], 422);
      }
      // Only allow known control keys.
      $data['controls'] = array_intersect_key($data['controls'], array_flip($validControls));
    }

    // Validate layers: allowlist known sub-keys.
    $validLayers = ['heatmap', 'clusters', 'markers'];
    if (array_key_exists('layers', $data)) {
      if (!is_array($data['layers'])) {
        return new JsonResponse(['error' => 'layers must be an object.'], 422);
      }
      $data['layers'] = array_intersect_key($data['layers'], array_flip($validLayers));
    }

    // Read-modify-write: load existing config, update only the map key.
    $config = [];
    if ($group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $decoded = json_decode($group->get('field_nuxt_config')->value, TRUE);
      if (is_array($decoded)) {
        $config = $decoded;
      }
    }

    if (!isset($config['map'])) {
      $config['map'] = [];
    }

    // Apply only allowed keys, preserving all other map config.
    // Special case: deferredMap can be an object with config options.
    // When the dashboard sends true, preserve the existing object.
    foreach ($data as $key => $value) {
      if ($key === 'deferredMap' && $value === TRUE && is_array($config['map']['deferredMap'] ?? NULL)) {
        continue;
      }
      $config['map'][$key] = $value;
    }

    // Write back the full config JSON.
    $group->set('field_nuxt_config', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    try {
      $group->save();
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->error(
        'Failed to save map settings for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to save map settings.'], 500);
    }

    $this->getLogger('markaspot_nuxt')->notice(
      'User @user updated map settings for jurisdiction @id (keys: @keys)',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@id' => $group->id(),
        '@keys' => implode(', ', array_keys($data)),
      ]
    );

    // Return the current state (same shape as GET).
    return $this->getMapSettings($request, $jurisdiction_id);
  }

  /**
   * Returns the boundary GeoJSON for a jurisdiction group.
   *
   * Reads field_boundary directly on the group entity (a raw GeoJSON
   * string, not part of the field_nuxt_config JSON blob) and normalizes it
   * into a FeatureCollection.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with the current boundary, or an error response.
   */
  public function getBoundarySettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    return new JsonResponse([
      'jurisdiction_id' => (int) $group->id(),
      'boundary' => $this->readBoundary($group),
    ]);
  }

  /**
   * Updates the boundary GeoJSON for a jurisdiction group.
   *
   * Accepts a JSON body with a 'boundary' key holding a FeatureCollection,
   * Feature, Polygon, MultiPolygon, or NULL to clear the stored boundary.
   * The value is validated and normalized by BoundaryGeoJsonValidator before
   * being written to field_boundary as a raw GeoJSON string.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request carrying a JSON body.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with the updated boundary, or an error response.
   */
  public function updateBoundarySettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $body = $request->getContent();
    if (strlen($body) > BoundaryGeoJsonValidator::MAX_PAYLOAD_BYTES) {
      return new JsonResponse([
        'error' => sprintf(
          'Request payload exceeds the %d MB limit.',
          BoundaryGeoJsonValidator::MAX_PAYLOAD_BYTES / 1024 / 1024
        ),
      ], 422);
    }

    $data = json_decode($body, TRUE);
    if (!is_array($data) || !array_key_exists('boundary', $data)) {
      return new JsonResponse(['error' => 'Invalid JSON body: missing boundary key.'], 400);
    }

    $result = BoundaryGeoJsonValidator::validate($data['boundary']);
    if (!$result['valid']) {
      return new JsonResponse(['error' => $result['error']], 422);
    }

    if (!$group->hasField('field_boundary')) {
      $this->getLogger('markaspot_nuxt')->error(
        'Cannot save boundary settings for jurisdiction @id: field_boundary does not exist on the group entity.',
        ['@id' => $group->id()]
      );
      return new JsonResponse(['error' => 'Field field_boundary does not exist on the jurisdiction group.'], 500);
    }

    $group->set(
      'field_boundary',
      $result['normalized'] === NULL ? NULL : json_encode($result['normalized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    try {
      $group->save();
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->error(
        'Failed to save boundary settings for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to save boundary settings.'], 500);
    }

    $this->getLogger('markaspot_nuxt')->notice(
      'User @user updated boundary settings for jurisdiction @id',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@id' => $group->id(),
      ]
    );

    // Return the current state (same shape as GET).
    return $this->getBoundarySettings($request, $jurisdiction_id);
  }

  /**
   * Reads and normalizes the boundary GeoJSON from field_boundary.
   *
   * Field_boundary is non-translatable and only saved on the entity's
   * original language, so this always reads from the default translation
   * (mirrors getNuxtConfig()).
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity (any translation).
   *
   * @return array|null
   *   The boundary as a FeatureCollection, or NULL if not set or unreadable.
   */
  private function readBoundary(GroupInterface $group): ?array {
    $source = $group->isDefaultTranslation() ? $group : $group->getUntranslated();
    if (!$source->hasField('field_boundary') || $source->get('field_boundary')->isEmpty()) {
      return NULL;
    }

    $boundary_json = strip_tags($source->get('field_boundary')->value);
    $boundary_data = json_decode($boundary_json, TRUE);
    if (!is_array($boundary_data) || !isset($boundary_data['type'])) {
      return NULL;
    }

    return match ($boundary_data['type']) {
      'FeatureCollection' => $boundary_data,
      'Feature' => [
        'type' => 'FeatureCollection',
        'features' => [$boundary_data],
      ],
      'Polygon', 'MultiPolygon' => [
        'type' => 'FeatureCollection',
        'features' => [
          [
            'type' => 'Feature',
            'properties' => [],
            'geometry' => $boundary_data,
          ],
        ],
      ],
      default => NULL,
    };
  }

  /**
   * Returns navigation settings for a jurisdiction group.
   *
   * Reads the navigation key from the field_nuxt_config JSON blob on the
   * group entity and returns it as an array of navigation items.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with current navigation settings, or an error response.
   */
  public function getNavigationSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $config = $this->getNuxtConfig($group);

    return new JsonResponse([
      'jurisdiction_id' => (int) $group->id(),
      'navigation' => $config['navigation'] ?? [],
    ]);
  }

  /**
   * Updates navigation settings for a jurisdiction group.
   *
   * Accepts a JSON body with a navigation array. Each item must have a label
   * and link, with optional target and icon. Maximum 10 items. Replaces the
   * entire navigation key in the field_nuxt_config JSON blob.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request carrying a JSON body.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with updated navigation settings, or an error response.
   */
  public function updateNavigationSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $body = $request->getContent();
    $data = json_decode($body, TRUE);

    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
    }

    if (!isset($data['navigation']) || !is_array($data['navigation'])) {
      return new JsonResponse(['error' => 'navigation must be an array.'], 422);
    }

    $items = $data['navigation'];

    // Enforce maximum item count.
    if (count($items) > 10) {
      return new JsonResponse(['error' => 'navigation must not exceed 10 items.'], 422);
    }

    $validTargets = ['_self', '_blank'];
    $sanitized = [];

    foreach ($items as $index => $item) {
      if (!is_array($item)) {
        return new JsonResponse(['error' => "navigation[$index] must be an object."], 422);
      }

      // label: required, non-empty string.
      if (!isset($item['label']) || !is_string($item['label']) || trim($item['label']) === '') {
        return new JsonResponse(['error' => "navigation[$index].label is required and must be a non-empty string."], 422);
      }

      // link: required string.
      if (!isset($item['link']) || !is_string($item['link'])) {
        return new JsonResponse(['error' => "navigation[$index].link is required and must be a string."], 422);
      }

      // Sanitize label against XSS.
      $label = strip_tags(trim($item['label']));
      if (empty($label)) {
        return new JsonResponse(['error' => "navigation[$index].label must contain text (HTML tags are stripped)."], 422);
      }

      // Validate link: only relative paths and https:// URLs allowed.
      $link = trim($item['link']);
      if (preg_match('/^(javascript|data|vbscript):/i', $link)) {
        return new JsonResponse(['error' => "navigation[$index].link contains a disallowed scheme."], 422);
      }

      $entry = [
        'label' => $label,
        'link' => $link,
      ];

      // target: optional, must be _self or _blank.
      if (isset($item['target'])) {
        if (!is_string($item['target']) || !in_array($item['target'], $validTargets, TRUE)) {
          return new JsonResponse(['error' => "navigation[$index].target must be '_self' or '_blank'."], 422);
        }
        $entry['target'] = $item['target'];
      }

      // icon: optional string, restricted to valid icon identifiers.
      if (isset($item['icon'])) {
        if (!is_string($item['icon']) || !preg_match('/^[a-zA-Z0-9:\-]+$/', $item['icon'])) {
          return new JsonResponse(['error' => "navigation[$index].icon must contain only letters, numbers, hyphens, and colons."], 422);
        }
        $entry['icon'] = $item['icon'];
      }

      $sanitized[] = $entry;
    }

    // Read-modify-write: load existing config, replace the navigation key.
    $config = [];
    if ($group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $decoded = json_decode($group->get('field_nuxt_config')->value, TRUE);
      if (is_array($decoded)) {
        $config = $decoded;
      }
    }

    $config['navigation'] = $sanitized;

    // Write back the full config JSON.
    $group->set('field_nuxt_config', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    try {
      $group->save();
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->error(
        'Failed to save navigation settings for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to save navigation settings.'], 500);
    }

    $this->getLogger('markaspot_nuxt')->notice(
      'User @user updated navigation settings for jurisdiction @id (@count items)',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@id' => $group->id(),
        '@count' => count($sanitized),
      ]
    );

    // Return the current state (same shape as GET).
    return $this->getNavigationSettings($request, $jurisdiction_id);
  }

  /**
   * Validates a color value as a Tailwind palette name or HEX code.
   *
   * @param string $value
   *   The color value to validate.
   *
   * @return string|null
   *   An error message if invalid, NULL if valid.
   */
  private function validateColorValue(string $value): ?string {
    // Allow Tailwind palette names.
    if (in_array($value, self::VALID_TAILWIND_PALETTES, TRUE)) {
      return NULL;
    }

    // Allow HEX color codes (#RGB or #RRGGBB).
    if (preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', $value)) {
      return NULL;
    }

    return 'Must be a valid Tailwind palette name (' . implode(', ', self::VALID_TAILWIND_PALETTES) . ') or a HEX color code (e.g. #FF5733).';
  }

  /**
   * Validates custom CSS for dangerous patterns.
   *
   * Blocks known CSS injection vectors such as @import, javascript: URIs,
   * expression(), behavior:, -moz-binding, script tags, and external url().
   *
   * @param string $css
   *   The CSS string to validate.
   *
   * @return string|null
   *   An error message if dangerous content is found, NULL if safe.
   */
  private function validateCustomCss(string $css): ?string {
    $lower = strtolower($css);

    if (str_contains($lower, '@import')) {
      return 'CSS must not contain @import directives.';
    }
    if (str_contains($lower, 'javascript:')) {
      return 'CSS must not contain javascript: URIs.';
    }
    if (str_contains($lower, 'expression(')) {
      return 'CSS must not contain expression() functions.';
    }
    if (str_contains($lower, 'behavior:')) {
      return 'CSS must not contain behavior: properties.';
    }
    if (str_contains($lower, '-moz-binding')) {
      return 'CSS must not contain -moz-binding properties.';
    }
    if (preg_match('/<\s*script/i', $css)) {
      return 'CSS must not contain script tags.';
    }
    if (preg_match('/url\s*\(\s*["\']?\s*https?:/i', $css)) {
      return 'CSS must not contain external url() references.';
    }

    return NULL;
  }

  /**
   * Checks whether the current user can change the platform block state.
   */
  private function currentUserCanManageWorkspaceBlock(): bool {
    return (int) $this->currentUser->id() === 1
      || in_array('administrator', $this->currentUser->getRoles(), TRUE);
  }

  /**
   * Validates a single general settings field value.
   *
   * @param string $fieldName
   *   The field name from GENERAL_SETTINGS_ALLOWED_FIELDS.
   * @param mixed $value
   *   The value to validate.
   *
   * @return string|null
   *   An error message string if validation fails, NULL if the value is valid.
   */
  private function validateFieldValue(string $fieldName, mixed $value): ?string {
    switch ($fieldName) {
      case 'field_platform_name':
        if (!is_string($value)) {
          return 'field_platform_name must be a string.';
        }
        if ($value !== strip_tags($value)) {
          return 'field_platform_name must not contain HTML tags.';
        }
        if (mb_strlen($value) > 100) {
          return 'field_platform_name must not exceed 100 characters.';
        }
        return NULL;

      case 'field_jurisdiction_e_mail':
        if (!is_string($value)) {
          return 'field_jurisdiction_e_mail must be a string.';
        }
        if ($value !== '' && !$this->emailValidator->isValid($value)) {
          return 'field_jurisdiction_e_mail must be a valid email address.';
        }
        return NULL;

      case 'field_email_footer':
        if (!is_string($value)) {
          return 'field_email_footer must be a string.';
        }
        if ($value !== strip_tags($value)) {
          return 'field_email_footer must not contain HTML tags.';
        }
        if (mb_strlen($value) > 1000) {
          return 'field_email_footer must not exceed 1000 characters.';
        }
        return NULL;

      case 'field_jurisdiction_address':
        if (!is_array($value) && $value !== NULL) {
          return 'field_jurisdiction_address must be an object or null.';
        }
        if (is_array($value)) {
          if (isset($value['country_code'])) {
            if (!is_string($value['country_code']) || !preg_match('/^[A-Z]{2}$/', $value['country_code'])) {
              return 'field_jurisdiction_address.country_code must be a 2-letter ISO country code (uppercase).';
            }
          }
          $stringSubfields = [
            'organization' => 255,
            'address_line1' => 255,
            'address_line2' => 255,
            'address_line3' => 255,
            'locality' => 255,
            'postal_code' => 255,
          ];
          foreach ($stringSubfields as $subfield => $maxLength) {
            if (!isset($value[$subfield])) {
              continue;
            }
            if (!is_string($value[$subfield])) {
              return "field_jurisdiction_address.$subfield must be a string.";
            }
            if ($value[$subfield] !== strip_tags($value[$subfield])) {
              return "field_jurisdiction_address.$subfield must not contain HTML tags.";
            }
            if (mb_strlen($value[$subfield]) > $maxLength) {
              return "field_jurisdiction_address.$subfield must not exceed $maxLength characters.";
            }
          }
        }
        return NULL;

      case 'field_visibility':
        if (!is_string($value)) {
          return 'field_visibility must be a string.';
        }
        $allowed = ['public', 'submission_only', 'authenticated', 'blocked'];
        if (!in_array($value, $allowed, TRUE)) {
          return 'field_visibility must be one of: ' . implode(', ', $allowed) . '.';
        }
        return NULL;

      case 'field_legal_notice':
      case 'field_privacy_policy':
        if (!is_string($value)) {
          return "$fieldName must be a string.";
        }
        // text_long fields: allow HTML content but cap at a reasonable size.
        if (mb_strlen($value) > 50000) {
          return "$fieldName must not exceed 50000 characters.";
        }
        return NULL;

      default:
        return "Unknown field: $fieldName.";
    }
  }

  /**
   * Returns dashboard settings for a jurisdiction group.
   *
   * Reads the dashboard.columns key from the field_nuxt_config JSON blob
   * on the group entity and returns it. Defaults to an empty object when
   * no dashboard configuration exists yet.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with current dashboard settings, or an error response.
   */
  public function getDashboardSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $config = $this->getNuxtConfig($group);

    $columns = $config['dashboard']['columns'] ?? (object) [];

    return new JsonResponse([
      'jurisdiction_id' => (int) $group->id(),
      'dashboard' => [
        'columns' => $columns,
      ],
    ]);
  }

  /**
   * Updates dashboard settings for a jurisdiction group.
   *
   * Accepts a JSON body with a columns object mapping column IDs to booleans.
   * Validates all keys against the known DASHBOARD_COLUMN_IDS constant, then
   * updates only the dashboard.columns key inside the field_nuxt_config JSON
   * blob. Other config keys are preserved.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request carrying a JSON body.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with updated dashboard settings, or an error response.
   */
  public function updateDashboardSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $body = $request->getContent();
    $data = json_decode($body, TRUE);

    if (!is_array($data) || !array_key_exists('columns', $data)) {
      return new JsonResponse(['error' => 'Invalid JSON body. Expected object with "columns" key.'], 400);
    }

    $columns = $data['columns'];
    if (!is_array($columns)) {
      return new JsonResponse(['error' => '"columns" must be an object.'], 422);
    }

    // Validate each column key and value.
    foreach ($columns as $key => $value) {
      if (!in_array($key, self::DASHBOARD_COLUMN_IDS, TRUE)) {
        return new JsonResponse(['error' => "Unknown column ID: $key."], 422);
      }
      if (!is_bool($value)) {
        return new JsonResponse(['error' => "Column '$key' must be a boolean."], 422);
      }
    }

    // Read-modify-write: load existing config, update only dashboard.columns.
    $config = [];
    if ($group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $decoded = json_decode($group->get('field_nuxt_config')->value, TRUE);
      if (is_array($decoded)) {
        $config = $decoded;
      }
    }

    if (!isset($config['dashboard'])) {
      $config['dashboard'] = [];
    }

    $config['dashboard']['columns'] = $columns;

    // Write back the full config JSON.
    $group->set('field_nuxt_config', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    try {
      $group->save();
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->error(
        'Failed to save dashboard settings for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to save dashboard settings.'], 500);
    }

    $this->getLogger('markaspot_nuxt')->notice(
      'User @user updated dashboard column settings for jurisdiction @id (columns: @columns)',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@id' => $group->id(),
        '@columns' => implode(', ', array_keys($columns)),
      ]
    );

    // Return the current state (same shape as GET).
    return $this->getDashboardSettings($request, $jurisdiction_id);
  }

  /**
   * Returns the current sidecar PNG fallback URI for a logo field.
   */
  protected function getExistingLogoPngFallbackUri($group, string $fieldName): ?string {
    if (!$group->hasField($fieldName) || $group->get($fieldName)->isEmpty()) {
      return NULL;
    }

    $field = $group->get($fieldName);
    $file = $field->entity ?? NULL;
    if ($file && method_exists($file, 'getFileUri')) {
      return $this->getLogoPngFallbackUri((string) $file->getFileUri());
    }

    if (!method_exists($field, 'getValue')) {
      return NULL;
    }

    $fieldValue = $field->getValue();
    if (empty($fieldValue[0]['target_id'])) {
      return NULL;
    }

    $storedFile = $this->entityTypeManager()
      ->getStorage('file')
      ->load($fieldValue[0]['target_id']);
    if (!$storedFile || !method_exists($storedFile, 'getFileUri')) {
      return NULL;
    }

    return $this->getLogoPngFallbackUri((string) $storedFile->getFileUri());
  }

  /**
   * Returns the sidecar PNG fallback URI for an SVG logo URI.
   */
  protected function getLogoPngFallbackUri(string $uri): ?string {
    if (preg_match('/\.svg$/i', $uri) !== 1) {
      return NULL;
    }
    return (string) preg_replace('/\.svg$/i', '.png', $uri);
  }

  /**
   * Deletes a file only when its stream wrapper resolves to a readable path.
   */
  protected function deleteFileIfReadable(string $uri): void {
    $realpath = $this->fileSystem->realpath($uri);
    if (is_string($realpath) && $realpath !== '' && is_readable($realpath)) {
      $this->fileSystem->delete($uri);
    }
  }

}
