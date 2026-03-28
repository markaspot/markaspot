<?php

namespace Drupal\markaspot_vision\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\MediaInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for processing images using AI vision APIs.
 *
 * This service is provider-agnostic and supports any OpenAI-compatible API
 * including OpenAI, Azure OpenAI, Qwen Vision, Ollama, and others.
 */
class ImageProcessingService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

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
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new ImageProcessingService.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\file\FileRepositoryInterface|null $file_repository
   *   The file repository service.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    ?FileRepositoryInterface $file_repository = NULL,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('markaspot_vision');
    // Optional for backward compatibility with existing service definitions.
    $this->fileRepository = $file_repository ?? \Drupal::service('file.repository');
  }

  /**
   * Blurs sensitive areas (faces, license plates) in an image.
   *
   * Sends the image to the blur microservice and returns the result.
   * Gracefully falls back to the original image if the service is
   * unavailable or not configured.
   *
   * @param string $contents
   *   The raw image bytes.
   * @param string $mimeType
   *   The MIME type of the image (e.g., 'image/jpeg').
   *
   * @return array
   *   Array with keys:
   *   - 'contents': The (possibly blurred) image bytes.
   *   - 'blurred': Whether blurring was applied.
   *   - 'faces': Number of detected faces.
   *   - 'plates': Number of detected license plates.
   */
  public function blurSensitiveAreas(string $contents, string $mimeType): array {
    $config = $this->configFactory->get('markaspot_vision.settings');
    $fallback = [
      'contents' => $contents,
      'blurred' => FALSE,
      'faces' => 0,
      'plates' => 0,
    ];

    // Check if blur preprocessing is enabled.
    if (empty($config->get('enable_blur_preprocessing'))) {
      return $fallback;
    }

    // Resolve blur service URL: config > ENV > default.
    $blur_url = $config->get('blur_service_url');
    if (empty($blur_url)) {
      $blur_url = getenv('VISION_BLUR_URL') ?: 'http://markaspot-vision:8200/blur';
    }

    // Map MIME type to file extension for the multipart filename.
    $extensions = [
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/webp' => 'webp',
    ];
    $ext = $extensions[$mimeType] ?? 'jpg';

    try {
      $response = $this->httpClient->post($blur_url, [
        'multipart' => [
          [
            'name' => 'image',
            'contents' => $contents,
            'filename' => 'upload.' . $ext,
            'headers' => ['Content-Type' => $mimeType],
          ],
        ],
        'timeout' => 10,
        'connect_timeout' => 5,
        'http_errors' => FALSE,
      ]);

      $statusCode = $response->getStatusCode();
      if ($statusCode !== 200) {
        $this->logger->warning('Blur service returned status @code from @url.', [
          '@code' => $statusCode,
          '@url' => $blur_url,
        ]);
        return $fallback;
      }

      $faces = (int) ($response->getHeaderLine('X-Detections-Faces') ?: 0);
      $plates = (int) ($response->getHeaderLine('X-Detections-Plates') ?: 0);
      $blurred = strtolower($response->getHeaderLine('X-Image-Blurred')) === 'true';
      $blurredContents = (string) $response->getBody();

      if ($blurred) {
        $this->logger->notice('Blur service detected @faces face(s), @plates plate(s). Image was blurred.', [
          '@faces' => $faces,
          '@plates' => $plates,
        ]);
      }

      return [
        'contents' => $blurredContents,
        'blurred' => $blurred,
        'faces' => $faces,
        'plates' => $plates,
      ];
    }
    catch (\Exception $e) {
      $this->logger->warning('Blur service unreachable at @url: @error', [
        '@url' => $blur_url,
        '@error' => $e->getMessage(),
      ]);
      return $fallback;
    }
  }

  /**
   * Saves a blurred image as a managed file on a media entity.
   *
   * Creates a new file entity with the blurred image contents and
   * attaches it to the field_media_image_blurred field. Does not
   * call $media->save() so the caller can batch field changes.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity to attach the blurred image to.
   * @param string $contents
   *   The blurred image bytes.
   * @param string $originalUri
   *   The URI of the original file, used for deriving the filename.
   */
  public function saveBlurredImage(MediaInterface $media, string $contents, string $originalUri): void {
    try {
      $originalFilename = $this->fileSystem->basename($originalUri);
      $directory = 'public://blurred/' . date('Y-m');
      $this->fileSystem->prepareDirectory(
        $directory,
        FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
      );

      $destination = $directory . '/blurred_' . $originalFilename;
      $blurredFile = $this->fileRepository->writeData(
        $contents,
        $destination,
        FileSystemInterface::EXISTS_RENAME
      );

      if ($blurredFile) {
        // Copy alt text from the original image field.
        $alt = '';
        $originalImage = $media->get('field_media_image');
        if ($originalImage && !$originalImage->isEmpty()) {
          $alt = $originalImage->alt ?? '';
        }

        $media->set('field_media_image_blurred', [
          'target_id' => $blurredFile->id(),
          'alt' => $alt,
        ]);
        $this->logger->notice(
          'Blurred image saved for media @id as file @fid.',
          ['@id' => $media->id(), '@fid' => $blurredFile->id()]
        );
      }
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to save blurred image for media @id: @error',
        ['@id' => $media->id(), '@error' => $e->getMessage()]
      );
    }
  }

  /**
   * Processes a set of images using AI vision services.
   *
   * @param array $file_uris
   *   Array of file URIs to process.
   * @param string|null $langcode
   *   Optional language code for the response.
   * @param int|null $jurisdictionId
   *   Optional jurisdiction group ID to filter categories.
   *
   * @return array|null
   *   The AI processing result or NULL on failure.
   */
  public function processImages(array $file_uris, ?string $langcode = NULL, ?int $jurisdictionId = NULL): ?array {
    $config = $this->configFactory->get('markaspot_vision.settings');
    $prompt_template = $config->get('image_prompt');

    // Get the API configuration.
    $api_config = $this->getApiConfig($config);

    try {
      // Process all images together.
      $image_data = [];
      $blur_results = [];
      foreach ($file_uris as $file_uri) {
        $styled_file_path = $this->getStyledImagePath($file_uri);
        $contents = file_get_contents($styled_file_path);
        if ($contents === FALSE) {
          $this->logger->warning('Failed to read image file: @path', ['@path' => $styled_file_path]);
          continue;
        }
        // Detect actual MIME type (image style may convert format).
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($contents) ?: 'image/jpeg';

        // Blur sensitive areas (faces, license plates) before AI analysis.
        $blur_result = $this->blurSensitiveAreas($contents, $mime);
        $blur_results[$file_uri] = $blur_result;

        $image_data[] = [
          'base64' => base64_encode($blur_result['contents']),
          'mime' => $mime,
        ];
      }

      $categories = $this->getAllCategoriesHierarchical($jurisdictionId, $langcode);
      $category_json = json_encode($categories, JSON_UNESCAPED_UNICODE);
      $category_json = str_replace(["\n", "\r"], '', $category_json);

      // Resolve language for the AI response.
      $language = $this->resolveLanguageName($langcode);

      // Enhance the prompt to emphasize collective analysis.
      $image_count = count($file_uris);
      $collective_prefix = "The following set of {$image_count} images shows a single situation or issue. " .
        "Please analyze them together as one complete scene. Consider how the images relate to and complement each other. ";
      $prompt = str_replace(
        ['{categories}', '{language}'],
        [$category_json, $language],
        $prompt_template
      );
      $prompt = $collective_prefix . $prompt;

      // Instruct AI to generate privacy-safe descriptions even
      // when PII is detected. Still categorize and assess hazards,
      // but describe the scene without referencing identifiable
      // people, license plates, or readable names.
      $prompt .= "\n\nPRIVACY INSTRUCTION: "
        . "If you detect personal data "
        . "(faces, license plates, readable names), "
        . "set privacy_flag to true and list issues "
        . "in privacy_issues. "
        . "IMPORTANT: Still generate a useful description, "
        . "category, and hazard assessment, "
        . "but write the description WITHOUT mentioning "
        . "or referencing any identifiable persons, "
        . "license plates, or personal names. "
        . "Describe the situation and the issue, "
        . "not the people.";

      // Append a language instruction so the AI responds in the
      // user's language, even without {language} in the template.
      if ($langcode && $langcode !== 'en') {
        $prompt .= "\n\nIMPORTANT: Write the \"description\" and \"hazard_issues\" fields in {$language}. "
          . "Use the JSON key \"description\" (not \"description_de\" or any locale-suffixed key).";
      }

      // Build messages array.
      $messages = [];

      // Resolve system prompt: jurisdiction-specific overrides global.
      $system_prompt = '';
      if ($jurisdictionId) {
        $group = $this->entityTypeManager->getStorage('group')->load($jurisdictionId);
        if ($group && $group->bundle() === 'jur' && $group->hasField('field_ai_system_prompt') && !$group->get('field_ai_system_prompt')->isEmpty()) {
          $system_prompt = trim($group->get('field_ai_system_prompt')->value);
        }
      }
      // Fallback to global config.
      if (empty($system_prompt)) {
        $system_prompt = trim($config->get('system_prompt') ?? '');
      }

      if (!empty($system_prompt)) {
        $messages[] = [
          'role' => 'system',
          'content' => [
            ['type' => 'text', 'text' => $system_prompt],
          ],
        ];
      }

      // Create user message with all images.
      $user_message = [
        'role' => 'user',
        'content' => [
          ['type' => 'text', 'text' => $prompt],
        ],
      ];

      // Add all images to the same message.
      foreach ($image_data as $img) {
        $user_message['content'][] = [
          'type' => 'image_url',
          'image_url' => ['url' => "data:{$img['mime']};base64,{$img['base64']}"],
        ];
      }

      $messages[] = $user_message;

      // Prepare and send request.
      $request_payload = $this->prepareRequestPayload($messages, $api_config);

      // Use the retry mechanism.
      $ai_data = $this->sendRequestWithRetry($api_config, $request_payload);

      if (!$ai_data || !isset($ai_data['choices'][0]['message']['content'])) {
        throw new \Exception('Invalid API response structure');
      }

      $ai_result_content = $ai_data['choices'][0]['message']['content'];

      return [
        'ai_result' => $ai_result_content,
        'blur_results' => $blur_results,
      ];

    }
    catch (\Exception $e) {
      $this->logger->error('Error processing image set: ' . $e->getMessage());
      return NULL;
    }
  }

  /**
   * Sends a request to the AI API with retry logic.
   *
   * @param array $api_config
   *   The API configuration.
   * @param array $request_payload
   *   The request payload.
   * @param int $max_retries
   *   Maximum number of retry attempts.
   *
   * @return array
   *   The decoded JSON response.
   *
   * @throws \Exception
   *   When max retries are reached or a non-recoverable error occurs.
   */
  protected function sendRequestWithRetry(array $api_config, array $request_payload, int $max_retries = 3): array {
    $attempts = 0;
    $last_error = NULL;

    while ($attempts < $max_retries) {
      try {
        $response = $this->httpClient->post($api_config['url'], [
          'headers' => $api_config['headers'],
          'json' => $request_payload,
          'http_errors' => FALSE,
        ]);

        $status_code = $response->getStatusCode();
        $body = (string) $response->getBody();

        // If we get a 429, wait and retry.
        if ($status_code === 429) {
          $attempts++;
          if ($attempts < $max_retries) {
            // Exponential backoff.
            $wait_time = min(20, pow(2, $attempts) * 10);
            $this->logger->warning("Rate limited. Waiting {$wait_time}s before retry (attempt {$attempts}/{$max_retries})");
            sleep($wait_time);
            continue;
          }
        }

        // For successful response or other errors, return immediately.
        if ($status_code !== 200) {
          throw new \Exception('API returned status code ' . $status_code . ': ' . $body);
        }

        return json_decode($body, TRUE);

      }
      catch (\Exception $e) {
        $last_error = $e;
        $attempts++;

        if ($attempts < $max_retries) {
          $wait_time = min(20, pow(2, $attempts) * 10);
          $this->logger->error('Request failed: ' . $e->getMessage() . ". Retrying in {$wait_time} seconds...");
          sleep($wait_time);
          continue;
        }
      }
    }

    // If we've exhausted all retries, throw the last error.
    throw new \Exception('Max retry attempts reached. Last error: ' . $last_error->getMessage());
  }

  /**
   * Gets the API configuration based on auth type.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The module configuration.
   *
   * @return array
   *   The API configuration array containing url, model, and headers.
   */
  protected function getApiConfig(ImmutableConfig $config): array {
    $auth_type = $config->get('auth_type') ?? 'bearer';
    $api_key = $this->resolveApiKey($config);
    $api_url = trim($config->get('api_url') ?? '');

    $headers = [
      'Content-Type' => 'application/json',
    ];

    switch ($auth_type) {
      case 'bearer':
        if (!empty($api_key)) {
          $headers['Authorization'] = 'Bearer ' . $api_key;
        }
        break;

      case 'api_key_header':
        if (!empty($api_key)) {
          $headers['api-key'] = $api_key;
        }
        break;

      case 'none':
      default:
        // No authentication header needed.
        break;
    }

    return [
      'url' => $api_url,
      'model' => $config->get('ai_model'),
      'headers' => $headers,
    ];
  }

  /**
   * Resolves the API key from config or environment variable.
   *
   * Priority: MARKASPOT_VISION_API_KEY env > Drupal config > OPENAI_API_KEY env.
   * Config is the standard source, set per site in Drupal admin for each
   * provider (OpenAI, Azure, local LLM). The generic OPENAI_API_KEY is only
   * used as a last-resort fallback.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The module configuration.
   *
   * @return string
   *   The resolved API key.
   */
  protected function resolveApiKey(ImmutableConfig $config): string {
    // Vision-specific ENV override (explicit deployment override).
    $envKey = getenv('MARKASPOT_VISION_API_KEY');
    if (!empty($envKey)) {
      return $envKey;
    }

    // Drupal config is the standard source (per-site, per-provider).
    $configKey = trim($config->get('api_key') ?? '');
    if (!empty($configKey)) {
      return $configKey;
    }

    // Generic fallback only if nothing else is configured.
    return trim(getenv('OPENAI_API_KEY') ?: '');
  }

  /**
   * Prepares the request payload for the AI API.
   *
   * @param array $messages
   *   The messages array for the API.
   * @param array $api_config
   *   The API configuration.
   *
   * @return array
   *   The prepared request payload.
   */
  protected function prepareRequestPayload(array $messages, array $api_config): array {
    $config = $this->configFactory->get('markaspot_vision.settings');

    $payload = [
      'messages' => $messages,
      'model' => $api_config['model'],
      'response_format' => [
        'type' => 'json_schema',
        'json_schema' => [
          'name' => 'vision_response',
          'schema' => [
            'type' => 'object',
            'properties' => [
              'category' => ['type' => 'integer'],
              'description' => ['type' => 'string'],
              'alt_text' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
              ],
              'hazard_flag' => ['type' => 'boolean'],
              'hazard_issues' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
              ],
              'privacy_flag' => ['type' => 'boolean'],
              'privacy_issues' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
              ],
              'hazard_level' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 4,
              ],
              'hazard_category' => [
                'type' => ['string', 'null'],
              ],
            ],
            'required' => [
              'category',
              'description',
              'alt_text',
              'hazard_flag',
              'hazard_level',
              'hazard_issues',
              'privacy_flag',
              'privacy_issues',
            ],
            'additionalProperties' => FALSE,
          ],
        ],
      ],
    ];

    // Only add parameters if they are explicitly set in config.
    if ($config->get('temperature') !== NULL) {
      $payload['temperature'] = (float) $config->get('temperature');
    }

    if ($config->get('top_p') !== NULL) {
      $payload['top_p'] = (float) $config->get('top_p');
    }

    // Add max_tokens only for non-vision models and if explicitly set.
    $model = $api_config['model'];
    $vision_models = ['gpt-4-vision-preview', 'gpt-4v'];
    if (!in_array($model, $vision_models) && $config->get('max_tokens') !== NULL) {
      $payload['max_tokens'] = (int) $config->get('max_tokens');
    }

    return $payload;
  }

  /**
   * Gets the styled image path for a file URI.
   *
   * @param string $uri
   *   The original file URI.
   *
   * @return string
   *   The path to the styled image derivative.
   *
   * @throws \Exception
   *   When the required image style is not found.
   */
  private function getStyledImagePath(string $uri): string {
    $style = $this->entityTypeManager->getStorage('image_style')->load('wide');
    if (!$style) {
      throw new \Exception('The "wide" image style was not found. Please ensure it exists.');
    }
    $styled_file_path = $style->buildUri($uri);
    if (!file_exists($styled_file_path)) {
      $style->createDerivative($uri, $styled_file_path);
    }
    return $styled_file_path;
  }

  /**
   * Retrieves all leaf categories with their IDs and full paths.
   *
   * @param int|null $jurisdictionId
   *   Optional jurisdiction group ID to filter categories.
   *   Without jurisdiction, loads all categories (single-installation mode).
   * @param string|null $langcode
   *   Optional language code. When set, category names are returned in
   *   this language (if a translation exists), so the AI prompt contains
   *   localized labels.
   *
   * @return array
   *   Array of leaf categories with tid, path, and label.
   */
  private function getAllCategoriesHierarchical(?int $jurisdictionId = NULL, ?string $langcode = NULL): array {
    try {
      $vid = 'service_category';
      $properties = ['vid' => $vid, 'status' => 1];
      if ($jurisdictionId) {
        $properties['field_jurisdiction'] = $jurisdictionId;
      }
      // Load taxonomy terms for the vocabulary, filtered by jurisdiction.
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadByProperties($properties);

      // Translate terms if a specific language is requested.
      if ($langcode) {
        foreach ($terms as $tid => $term) {
          if ($term->hasTranslation($langcode)) {
            $terms[$tid] = $term->getTranslation($langcode);
          }
        }
      }

      // Create a lookup array for quick parent-child checks.
      $term_lookup = [];
      $children_count = [];

      foreach ($terms as $term) {
        $term_lookup[$term->id()] = $term;
        $children_count[$term->id()] = 0;
      }

      // Count children for each term.
      foreach ($terms as $term) {
        $parent_id = $term->get('parent')->target_id ?? 0;
        if ($parent_id && isset($children_count[$parent_id])) {
          $children_count[$parent_id]++;
        }
      }

      // Build paths for leaf terms (terms with no children).
      $leaf_categories = [];

      foreach ($terms as $term) {
        $term_id = $term->id();

        // Only include leaf terms (no children).
        if ($children_count[$term_id] == 0) {
          $path = $this->buildCategoryPath($term, $term_lookup);
          $leaf_categories[] = [
            'tid' => $term_id,
            'path' => $path,
            'label' => str_replace(' > ', ' - ', $path),
          ];
        }
      }

      return $leaf_categories;
    }
    catch (\Exception $e) {
      $this->logger->error('Error fetching leaf categories: ' . $e->getMessage());
      return [];
    }
  }

  /**
   * Builds the full path for a category term.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The taxonomy term.
   * @param array $term_lookup
   *   Lookup array of all terms.
   *
   * @return string
   *   The full category path.
   */
  private function buildCategoryPath(mixed $term, array $term_lookup): string {
    $path_parts = [];
    $current_term = $term;

    // Build path from leaf to root.
    while ($current_term) {
      array_unshift($path_parts, $current_term->label());
      $parent_id = $current_term->get('parent')->target_id ?? 0;
      $current_term = $parent_id && isset($term_lookup[$parent_id]) ? $term_lookup[$parent_id] : NULL;
    }

    return implode(' > ', $path_parts);
  }

  /**
   * Resolves a langcode to a human-readable language name for AI prompts.
   *
   * @param string|null $langcode
   *   The language code (e.g., 'de', 'fr', 'nl').
   *
   * @return string
   *   The language name in English (e.g., 'German', 'French').
   */
  private function resolveLanguageName(?string $langcode): string {
    $map = [
      'de' => 'German',
      'en' => 'English',
      'fr' => 'French',
      'es' => 'Spanish',
      'nl' => 'Dutch',
      'it' => 'Italian',
      'pt' => 'Portuguese',
      'pl' => 'Polish',
      'da' => 'Danish',
      'tr' => 'Turkish',
      'uk' => 'Ukrainian',
      'ar' => 'Arabic',
    ];

    return $map[$langcode ?? ''] ?? 'English';
  }

}
