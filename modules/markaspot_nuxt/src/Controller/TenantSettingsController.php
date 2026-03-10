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
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for tenant settings API endpoints.
 *
 * Provides endpoints to manage logos and general settings for jurisdiction
 * groups. General settings cover the platform name, contact email, email
 * footer text, and postal address.
 */
class TenantSettingsController extends ControllerBase {

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
   * Constructs a TenantSettingsController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\group\GroupMembershipLoaderInterface $membership_loader
   *   The group membership loader.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\file\FileRepositoryInterface $file_repository
   *   The file repository service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $hierarchy_resolver
   *   The jurisdiction hierarchy resolver.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    GroupMembershipLoaderInterface $membership_loader,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    FileSystemInterface $file_system,
    FileRepositoryInterface $file_repository,
    AccountInterface $current_user,
    JurisdictionHierarchyResolverInterface $hierarchy_resolver,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->membershipLoader = $membership_loader;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->fileSystem = $file_system;
    $this->fileRepository = $file_repository;
    $this->currentUser = $current_user;
    $this->hierarchyResolver = $hierarchy_resolver;
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
   * @param int $jurisdiction_id
   *   The jurisdiction group ID from the route.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function accessCheck(AccountInterface $account, int $jurisdiction_id): AccessResultInterface {
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
        if (in_array($jurisdiction_id, $scopeIds, TRUE)) {
          return AccessResult::allowed()->addCacheContexts(['user']);
        }
      }
    }

    return AccessResult::forbidden('User is not an administrator or tenant admin for this jurisdiction.')
      ->addCacheContexts(['user', 'user.roles']);
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
   * @param int $jurisdiction_id
   *   The group entity ID for the jurisdiction.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with updated logo URLs on success, or error message.
   */
  public function uploadLogo(Request $request, int $jurisdiction_id): JsonResponse {
    // Load and validate the group entity.
    $groupStorage = $this->entityTypeManager()->getStorage('group');
    /** @var \Drupal\group\Entity\GroupInterface|null $group */
    $group = $groupStorage->load($jurisdiction_id);

    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    if ($group->bundle() !== 'jur') {
      return new JsonResponse(['error' => 'The specified entity is not a jurisdiction group.'], 400);
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
          ['@id' => $jurisdiction_id, '@message' => $e->getMessage()]
        );
        return new JsonResponse(['error' => 'Failed to save logo to group entity.'], 500);
      }
    }

    if (!empty($errors) && empty($logos)) {
      return new JsonResponse(['error' => implode(' ', $errors)], 400);
    }

    $response = [
      'status' => 'ok',
      'jurisdiction_id' => $jurisdiction_id,
      'logos' => $logos,
    ];

    if (!empty($errors)) {
      $response['warnings'] = $errors;
    }

    $this->getLogger('markaspot_nuxt')->notice(
      'User @user uploaded logo(s) for jurisdiction @id: @logos',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@id' => $jurisdiction_id,
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
    $extension = strtolower($uploadedFile->getClientOriginalExtension());

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

    // Build the URL for the response (relative path, same pattern as getMarkASpotSettings).
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
   * Returns general settings for a jurisdiction group.
   *
   * Reads field_platform_name, field_jurisdiction_e_mail, field_email_footer,
   * and field_jurisdiction_address from the group entity and returns them as
   * JSON.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param int $jurisdiction_id
   *   The group entity ID for the jurisdiction.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with current general settings, or an error response.
   */
  public function getGeneralSettings(Request $request, int $jurisdiction_id): JsonResponse {
    $groupStorage = $this->entityTypeManager()->getStorage('group');
    /** @var \Drupal\group\Entity\GroupInterface|null $group */
    $group = $groupStorage->load($jurisdiction_id);

    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    if ($group->bundle() !== 'jur') {
      return new JsonResponse(['error' => 'The specified entity is not a jurisdiction group.'], 400);
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
    if (\Drupal::hasService('address.country_repository')) {
      $countryRepository = \Drupal::service('address.country_repository');
      $countries = $countryRepository->getList();
    }

    return new JsonResponse([
      'jurisdiction_id' => $jurisdiction_id,
      'field_platform_name' => $group->hasField('field_platform_name') && !$group->get('field_platform_name')->isEmpty()
        ? $group->get('field_platform_name')->value
        : '',
      'field_jurisdiction_e_mail' => $group->hasField('field_jurisdiction_e_mail') && !$group->get('field_jurisdiction_e_mail')->isEmpty()
        ? $group->get('field_jurisdiction_e_mail')->value
        : '',
      'field_email_footer' => $group->hasField('field_email_footer') && !$group->get('field_email_footer')->isEmpty()
        ? $group->get('field_email_footer')->value
        : '',
      'field_jurisdiction_address' => $address,
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
   * @param int $jurisdiction_id
   *   The group entity ID for the jurisdiction.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with updated general settings, or an error response.
   */
  public function updateGeneralSettings(Request $request, int $jurisdiction_id): JsonResponse {
    $groupStorage = $this->entityTypeManager()->getStorage('group');
    /** @var \Drupal\group\Entity\GroupInterface|null $group */
    $group = $groupStorage->load($jurisdiction_id);

    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    if ($group->bundle() !== 'jur') {
      return new JsonResponse(['error' => 'The specified entity is not a jurisdiction group.'], 400);
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

    // Run Drupal entity-level validation before saving.
    $violations = $group->validate();
    if ($violations->count() > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
      }
      return new JsonResponse(['error' => 'Validation failed.', 'details' => $messages], 422);
    }

    try {
      $group->save();
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_nuxt')->error(
        'Failed to save general settings for jurisdiction @id: @message',
        ['@id' => $jurisdiction_id, '@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to save general settings.'], 500);
    }

    $this->getLogger('markaspot_nuxt')->notice(
      'User @user updated general settings for jurisdiction @id (fields: @fields)',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@id' => $jurisdiction_id,
        '@fields' => implode(', ', array_keys($data)),
      ]
    );

    // Return the current state of all general settings fields (same shape as GET).
    return $this->getGeneralSettings($request, $jurisdiction_id);
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
        /** @var \Drupal\Component\Utility\EmailValidatorInterface $emailValidator */
        $emailValidator = \Drupal::service('email.validator');
        if ($value !== '' && !$emailValidator->isValid($value)) {
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

      default:
        return "Unknown field: $fieldName.";
    }
  }

}
