<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\Component\Utility\EmailValidator;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use CommerceGuys\Addressing\Country\CountryRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for tenant settings API endpoints.
 *
 * Provides endpoints to manage logos, general settings, language settings,
 * branding, features, map configuration, and navigation for jurisdiction
 * groups. General settings cover the platform name, contact email, email
 * footer text, and postal address. Language, feature, map, and navigation
 * settings are stored in the field_nuxt_config JSON blob.
 *
 * All endpoints accept both numeric group IDs and URL slugs as the
 * jurisdiction_id parameter via JurisdictionIdResolverTrait.
 */
class TenantSettingsController extends ControllerBase {

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
    'privacyBlur',
    'feedback',
    'pwaInstallPrompt',
    'objectId',
    'party',
    'formFirst',
    'dashboard',
    'contactForm',
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
   * Supported locales with display names.
   *
   * Must match the frontend config/locales.ts definitions.
   */
  const SUPPORTED_LOCALES = [
    'de' => 'Deutsch',
    'en' => 'English',
    'de-ls' => 'Einfache Sprache',
    'es' => 'Español',
    'fr' => 'Français',
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
    'de-ls' => 'de-DE',
    'es' => 'es-ES',
    'fr' => 'fr-FR',
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
   * The group membership loader.
   *
   * @var \Drupal\group\GroupMembershipLoaderInterface
   */
  protected GroupMembershipLoaderInterface $membershipLoader;

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
    GroupMembershipLoaderInterface $membership_loader,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    FileSystemInterface $file_system,
    FileRepositoryInterface $file_repository,
    AccountInterface $current_user,
    JurisdictionHierarchyResolverInterface $hierarchy_resolver,
    EmailValidator $email_validator,
    ?CountryRepositoryInterface $country_repository,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->membershipLoader = $membership_loader;
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
      $container->get('group.membership_loader'),
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

    // tenant_admin role: allow access if the requested jurisdiction falls
    // within the hierarchy (self + descendants) of any jurisdiction where
    // the user holds jur-tenant_admin membership.
    if (in_array('tenant_admin', $account->getRoles(), TRUE)) {
      $memberships = $this->membershipLoader->loadByUser($account, ['jur-tenant_admin']);
      foreach ($memberships as $membership) {
        $managedJurId = (int) $membership->getGroup()->id();
        $scopeIds = $this->hierarchyResolver->getDescendantIds($managedJurId);
        if (in_array($resolved_id, $scopeIds, TRUE)) {
          return AccessResult::allowed()->addCacheContexts(['user']);
        }
      }
    }

    return AccessResult::forbidden('User is not an administrator or tenant admin for this jurisdiction.')
      ->addCacheContexts(['user', 'user.roles']);
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
    if (!$group || $group->bundle() !== 'jur') {
      return NULL;
    }

    return $group;
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

    if (!$logoLight && !$logoDark) {
      return new JsonResponse(['error' => 'No logo file provided. Use logo_light or logo_dark field.'], 400);
    }

    $logos = [];
    $errors = [];

    // Process logo_light.
    if ($logoLight) {
      $result = $this->processLogoUpload($logoLight, $group, 'field_logo_light', 'logo_light');
      if ($result['success']) {
        $logos['light'] = $result['url'];
      }
      else {
        $errors[] = $result['error'];
      }
    }

    // Process logo_dark.
    if ($logoDark) {
      $result = $this->processLogoUpload($logoDark, $group, 'field_logo_dark', 'logo_dark');
      if ($result['success']) {
        $logos['dark'] = $result['url'];
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
   *
   * @return array
   *   Result array with 'success' bool and 'url' or 'error'.
   */
  protected function processLogoUpload(
    $uploadedFile,
    $group,
    string $fieldName,
    string $fileKey,
  ): array {
    // Validate file type: only SVG and PNG are allowed.
    $allowedMimeTypes = ['image/svg+xml', 'image/png'];
    $allowedExtensions = ['svg', 'png'];
    $mimeType = $uploadedFile->getMimeType();
    $clientMimeType = $uploadedFile->getClientMimeType();
    $extension = strtolower($uploadedFile->getClientOriginalExtension());

    // SVG files are often detected as application/octet-stream or text/xml
    // by finfo on temp files. Fall back to client-reported MIME type for SVG
    // when the extension matches, as an additional safety check.
    if (!in_array($mimeType, $allowedMimeTypes, TRUE)
      && in_array($clientMimeType, $allowedMimeTypes, TRUE)
      && in_array($extension, $allowedExtensions, TRUE)) {
      $mimeType = $clientMimeType;
    }

    if (!in_array($mimeType, $allowedMimeTypes, TRUE) || !in_array($extension, $allowedExtensions, TRUE)) {
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

    // Save the file using Drupal's file repository (handles managed files).
    try {
      $fileData = file_get_contents($uploadedFile->getPathname());
      if ($fileData === FALSE) {
        return [
          'success' => FALSE,
          'error' => "Failed to read uploaded file for $fileKey.",
        ];
      }

      $file = $this->fileRepository->writeData(
        $fileData,
        $destination,
        FileSystemInterface::EXISTS_REPLACE
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

    // Assign the file to the group field.
    $group->set($fieldName, ['target_id' => $file->id()]);

    // Build the URL for the response (relative path,
    // same pattern as getMarkASpotSettings).
    $uri = $file->getFileUri();
    $scheme = $this->streamWrapperManager->getScheme($uri);
    if ($scheme === 'public') {
      $target = $this->streamWrapperManager->getTarget($uri);
      $publicPath = PublicStream::basePath();
      $url = '/' . $publicPath . '/' . $target;
    }
    else {
      $url = '/' . str_replace('://', '/', $uri);
    }

    return [
      'success' => TRUE,
      'url' => $url,
    ];
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

    // Delete file entities only after successful group save.
    foreach ($filesToDelete as $file) {
      $file->delete();
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

    // Read the full nuxt config JSON blob.
    $config = [];
    if ($group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $decoded = json_decode($group->get('field_nuxt_config')->value, TRUE);
      if (is_array($decoded)) {
        $config = $decoded;
      }
    }

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

    // Read the full nuxt config JSON blob.
    $config = [];
    if ($group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $decoded = json_decode($group->get('field_nuxt_config')->value, TRUE);
      if (is_array($decoded)) {
        $config = $decoded;
      }
    }

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

    // Read the full nuxt config JSON blob.
    $config = [];
    if ($group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $decoded = json_decode($group->get('field_nuxt_config')->value, TRUE);
      if (is_array($decoded)) {
        $config = $decoded;
      }
    }

    $features = $config['features'] ?? [];

    return new JsonResponse([
      'jurisdiction_id' => (int) $group->id(),
      'features' => [
        'photoReporting' => $features['photoReporting'] ?? TRUE,
        'classicReporting' => $features['classicReporting'] ?? FALSE,
        'voting' => $features['voting'] ?? FALSE,
        'statistics' => $features['statistics'] ?? FALSE,
        'following' => $features['following'] ?? FALSE,
        'passwordless' => $features['passwordless'] ?? FALSE,
        'aiAnalysis' => $features['aiAnalysis'] ?? FALSE,
        'privacyBlur' => $features['privacyBlur'] ?? FALSE,
        'feedback' => $features['feedback'] ?? FALSE,
        'pwaInstallPrompt' => $features['pwaInstallPrompt'] ?? FALSE,
        'objectId' => $features['objectId'] ?? FALSE,
        'party' => $features['party'] ?? FALSE,
        'formFirst' => $features['formFirst'] ?? FALSE,
        'dashboard' => $features['dashboard'] ?? TRUE,
        'contactForm' => $features['contactForm'] ?? FALSE,
        'emergency' => ['enabled' => $features['emergency']['enabled'] ?? FALSE],
        'funFacts' => ['enabled' => $features['funFacts']['enabled'] ?? FALSE],
        'search' => ['enabled' => $features['search']['enabled'] ?? TRUE],
        'boundaries' => ['enabled' => $features['boundaries']['enabled'] ?? FALSE],
        'privacyNotice' => ['enabled' => $features['privacyNotice']['enabled'] ?? FALSE],
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
    $allKnownFlags = array_merge(self::SIMPLE_FEATURE_FLAGS, self::NESTED_FEATURE_FLAGS);
    foreach ($data as $key => $value) {
      if (in_array($key, $allKnownFlags, TRUE)) {
        $config['features'][$key] = $value;
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

    // Read the full nuxt config JSON blob.
    $config = [];
    if ($group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $decoded = json_decode($group->get('field_nuxt_config')->value, TRUE);
      if (is_array($decoded)) {
        $config = $decoded;
      }
    }

    $map = $config['map'] ?? [];

    return new JsonResponse([
      'jurisdiction_id' => (int) $group->id(),
      'map' => [
        'center' => $map['center'] ?? [0, 0],
        'zoom' => (int) ($map['zoom'] ?? 13),
        'maxBounds' => $map['maxBounds'] ?? NULL,
        'loadMarkersOnInit' => $map['loadMarkersOnInit'] ?? TRUE,
        'enableBoundsFiltering' => $map['enableBoundsFiltering'] ?? TRUE,
        'deferredMap' => $map['deferredMap'] ?? FALSE,
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

    // Validate controls: allowlist known sub-keys, values must be boolean or object with enabled boolean.
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
    foreach ($data as $key => $value) {
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

    // Read the full nuxt config JSON blob.
    $config = [];
    if ($group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $decoded = json_decode($group->get('field_nuxt_config')->value, TRUE);
      if (is_array($decoded)) {
        $config = $decoded;
      }
    }

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
        if (strlen($value) > 100) {
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
        if (strlen($value) > 1000) {
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
          $stringSubfields = ['organization', 'address_line1', 'locality', 'postal_code'];
          foreach ($stringSubfields as $subfield) {
            if (isset($value[$subfield]) && !is_string($value[$subfield])) {
              return "field_jurisdiction_address.$subfield must be a string.";
            }
          }
        }
        return NULL;

      case 'field_visibility':
        if (!is_string($value)) {
          return 'field_visibility must be a string.';
        }
        $allowed = ['public', 'submission_only', 'authenticated'];
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
        if (strlen($value) > 50000) {
          return "$fieldName must not exceed 50000 characters.";
        }
        return NULL;

      default:
        return "Unknown field: $fieldName.";
    }
  }

}
