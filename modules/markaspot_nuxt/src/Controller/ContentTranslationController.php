<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for creating content translations via REST.
 *
 * Drupal core's JSON:API returns a 405 when PATCHing a non-existent
 * translation. This endpoint fills that gap by providing a POST route
 * to create new translations for any translatable content entity.
 */
class ContentTranslationController extends ControllerBase {

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a ContentTranslationController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LanguageManagerInterface $language_manager,
    AccountInterface $current_user,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->languageManager = $language_manager;
    $this->currentUser = $current_user;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('current_user'),
      $container->get('logger.factory')->get('markaspot_nuxt'),
    );
  }

  /**
   * Creates a new translation for the given entity.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request containing the translation field values.
   * @param string $entity_type
   *   The entity type ID (e.g. "node", "group", "taxonomy_term").
   * @param string $uuid
   *   The UUID of the entity to translate.
   * @param string $langcode
   *   The target language code (e.g. "fr", "de", "en").
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with the created translation data or an error.
   */
  public function createTranslation(Request $request, string $entity_type, string $uuid, string $langcode): JsonResponse {
    // Validate the entity type exists and is a content entity.
    if (!$this->entityTypeManager->hasDefinition($entity_type)) {
      return new JsonResponse([
        'error' => 'Invalid entity type.',
        'message' => "The entity type '$entity_type' does not exist.",
      ], Response::HTTP_BAD_REQUEST);
    }

    $definition = $this->entityTypeManager->getDefinition($entity_type);
    if (!$definition->entityClassImplements(ContentEntityInterface::class)) {
      return new JsonResponse([
        'error' => 'Invalid entity type.',
        'message' => "The entity type '$entity_type' is not a content entity.",
      ], Response::HTTP_BAD_REQUEST);
    }

    // Validate the langcode is an enabled language.
    $languages = $this->languageManager->getLanguages();
    if (!isset($languages[$langcode])) {
      $available = implode(', ', array_keys($languages));
      return new JsonResponse([
        'error' => 'Invalid language.',
        'message' => "The language '$langcode' is not enabled. Available languages: $available.",
      ], Response::HTTP_BAD_REQUEST);
    }

    // Load the entity by UUID.
    $entities = $this->entityTypeManager
      ->getStorage($entity_type)
      ->loadByProperties(['uuid' => $uuid]);
    $entity = reset($entities);

    if (!$entity || !($entity instanceof ContentEntityInterface)) {
      return new JsonResponse([
        'error' => 'Entity not found.',
        'message' => "No $entity_type entity found with UUID '$uuid'.",
      ], Response::HTTP_NOT_FOUND);
    }

    // Verify the entity type supports translations.
    if (!$entity->isTranslatable()) {
      return new JsonResponse([
        'error' => 'Not translatable.',
        'message' => "The entity type '$entity_type' (bundle: {$entity->bundle()}) does not support translations.",
      ], Response::HTTP_BAD_REQUEST);
    }

    // Check that the translation does not already exist.
    if ($entity->hasTranslation($langcode)) {
      return new JsonResponse([
        'error' => 'Translation exists.',
        'message' => "A '$langcode' translation already exists for this entity. Use PATCH via JSON:API to update it.",
      ], Response::HTTP_CONFLICT);
    }

    // Parse the request body.
    $content = $request->getContent();
    $data = json_decode($content, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      return new JsonResponse([
        'error' => 'Invalid JSON.',
        'message' => 'The request body is not valid JSON: ' . json_last_error_msg(),
      ], Response::HTTP_BAD_REQUEST);
    }

    $attributes = $data['attributes'] ?? [];
    if (empty($attributes)) {
      return new JsonResponse([
        'error' => 'Missing attributes.',
        'message' => 'The request body must contain an "attributes" object with field values.',
      ], Response::HTTP_BAD_REQUEST);
    }

    // Build the translation values from the provided attributes.
    $values = $this->mapAttributesToFieldValues($entity, $attributes);

    try {
      $translation = $entity->addTranslation($langcode, $values);
      $translation->save();

      $this->logger->info('Created @langcode translation for @type @uuid.', [
        '@langcode' => $langcode,
        '@type' => $entity_type,
        '@uuid' => $uuid,
      ]);

      // Build the response data.
      $response_data = $this->buildResponseData($translation, $entity_type, $uuid, $langcode);

      return new JsonResponse($response_data, Response::HTTP_CREATED);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create @langcode translation for @type @uuid: @message', [
        '@langcode' => $langcode,
        '@type' => $entity_type,
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Translation creation failed.',
        'message' => $e->getMessage(),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Access check for the content translation endpoint.
   *
   * Verifies that the user has both the 'create content translations'
   * permission and edit access on the target entity.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check.
   * @param string $entity_type
   *   The entity type ID.
   * @param string $uuid
   *   The entity UUID.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function accessCheck(AccountInterface $account, string $entity_type, string $uuid): AccessResultInterface {
    // First check the base permission.
    if (!$account->hasPermission('create content translations')) {
      return AccessResult::forbidden('Missing "create content translations" permission.')
        ->addCacheContexts(['user.permissions']);
    }

    // Then check entity-level edit access.
    if (!$this->entityTypeManager->hasDefinition($entity_type)) {
      return AccessResult::forbidden('Invalid entity type.')
        ->addCacheContexts(['url.path']);
    }

    $entities = $this->entityTypeManager
      ->getStorage($entity_type)
      ->loadByProperties(['uuid' => $uuid]);
    $entity = reset($entities);

    if (!$entity || !($entity instanceof ContentEntityInterface)) {
      // Let the controller return a proper 404.
      return AccessResult::allowed()
        ->addCacheContexts(['url.path']);
    }

    $edit_access = $entity->access('update', $account, TRUE);
    if (!$edit_access->isAllowed()) {
      return AccessResult::forbidden('No edit access on the target entity.')
        ->addCacheContexts(['user.permissions', 'url.path'])
        ->addCacheableDependency($entity);
    }

    return AccessResult::allowed()
      ->addCacheContexts(['user.permissions', 'url.path'])
      ->addCacheableDependency($entity);
  }

  /**
   * Maps incoming JSON attributes to Drupal field values.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The source entity to validate fields against.
   * @param array $attributes
   *   The attributes from the request body.
   *
   * @return array
   *   An array of field name => value pairs suitable for addTranslation().
   */
  protected function mapAttributesToFieldValues(ContentEntityInterface $entity, array $attributes): array {
    $values = [];

    foreach ($attributes as $field_name => $field_value) {
      // Only set values for fields that exist on the entity.
      if (!$entity->hasField($field_name)) {
        continue;
      }

      $field_definition = $entity->getFieldDefinition($field_name);

      // Skip non-translatable fields.
      if (!$field_definition->isTranslatable()) {
        continue;
      }

      $values[$field_name] = $field_value;
    }

    return $values;
  }

  /**
   * Builds the response data from the saved translation.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $translation
   *   The saved translation entity.
   * @param string $entity_type
   *   The entity type ID.
   * @param string $uuid
   *   The entity UUID.
   * @param string $langcode
   *   The translation language code.
   *
   * @return array
   *   The response data array.
   */
  protected function buildResponseData(ContentEntityInterface $translation, string $entity_type, string $uuid, string $langcode): array {
    $data = [
      'entity_type' => $entity_type,
      'uuid' => $uuid,
      'langcode' => $langcode,
    ];

    // Include common fields if present.
    if ($translation->hasField('title')) {
      $data['title'] = $translation->get('title')->value;
    }
    elseif ($translation->hasField('name')) {
      $data['name'] = $translation->get('name')->value;
    }
    elseif ($translation->hasField('info')) {
      $data['info'] = $translation->get('info')->value;
    }
    elseif ($translation->hasField('label')) {
      $data['label'] = $translation->get('label')->value;
    }

    if ($translation->hasField('body') && !$translation->get('body')->isEmpty()) {
      $data['body'] = [
        'value' => $translation->get('body')->value,
        'format' => $translation->get('body')->format,
      ];
    }

    return $data;
  }

}
