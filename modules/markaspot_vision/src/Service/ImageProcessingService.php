<?php

namespace Drupal\markaspot_vision\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\taxonomy\TermInterface;
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
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

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
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    LanguageManagerInterface $language_manager,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('markaspot_vision');
    $this->languageManager = $language_manager;
  }

  /**
   * Processes a set of images using AI vision services.
   *
   * @param array $file_uris
   *   Array of file URIs to process.
   * @param string|null $langcode
   *   Optional language code for the response (e.g., 'de', 'fr', 'en').
   *   If not provided, falls back to the site's current language.
   *
   * @return array|null
   *   The AI processing result or NULL on failure.
   */
  public function processImages(array $file_uris, ?string $langcode = NULL): ?array {
    $config = $this->configFactory->get('markaspot_vision.settings');
    $prompt_template = $config->get('image_prompt');

    // Get the API configuration.
    $api_config = $this->getApiConfig($config);

    try {
      // Process all images together.
      $image_contents = [];
      foreach ($file_uris as $file_uri) {
        $styled_file_path = $this->getStyledImagePath($file_uri);
        $image_contents[] = base64_encode(file_get_contents($styled_file_path));
      }

      $categories = $this->getAllCategoriesHierarchical();
      $category_json = json_encode($categories, JSON_UNESCAPED_UNICODE);
      $category_json = str_replace(["\n", "\r"], '', $category_json);

      // Use provided language or fall back to site's current language.
      if (empty($langcode)) {
        $langcode = $this->languageManager->getCurrentLanguage()->getId();
      }
      $language_name = $this->getLanguageName($langcode);

      // Enhance the prompt to emphasize collective analysis and language.
      $image_count = count($file_uris);
      $collective_prefix = "The following set of {$image_count} images shows a single situation or issue. " .
        "Please analyze them together as one complete scene. Consider how the images relate to and complement each other. " .
        "IMPORTANT: Write the description in {$language_name}. ";
      $prompt = $collective_prefix . str_replace(
        ['{categories}', '{language}'],
        [$category_json, $language_name],
        $prompt_template
      );

      // Build messages array.
      $messages = [];

      // Add optional system message if configured.
      $system_prompt = trim($config->get('system_prompt') ?? '');
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
      foreach ($image_contents as $content) {
        $user_message['content'][] = [
          'type' => 'image_url',
          'image_url' => ['url' => "data:image/jpeg;base64,$content"],
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
            $wait_time = pow(2, $attempts) * 10;
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
          $wait_time = pow(2, $attempts) * 10;
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
    $api_key = trim($config->get('api_key') ?? '');
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
            ],
            'required' => [
              'category',
              'description',
              'alt_text',
              'hazard_flag',
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
   * @return array
   *   Array of leaf categories with tid, path, and label.
   */
  private function getAllCategoriesHierarchical(): array {
    try {
      $vid = 'service_category';
      // Load all taxonomy terms for the vocabulary, including their statuses.
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => $vid, 'status' => 1]);

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
  private function buildCategoryPath(TermInterface $term, array $term_lookup): string {
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
   * Gets a human-readable language name from a langcode.
   *
   * @param string $langcode
   *   The language code (e.g., 'de', 'en', 'fr').
   *
   * @return string
   *   The language name in English for AI prompts.
   */
  private function getLanguageName(string $langcode): string {
    $languages = [
      'de' => 'German',
      'en' => 'English',
      'fr' => 'French',
      'nl' => 'Dutch',
      'es' => 'Spanish',
      'it' => 'Italian',
      'pt' => 'Portuguese',
      'pl' => 'Polish',
      'cs' => 'Czech',
      'da' => 'Danish',
      'sv' => 'Swedish',
      'fi' => 'Finnish',
      'no' => 'Norwegian',
      'ru' => 'Russian',
      'uk' => 'Ukrainian',
      'ar' => 'Arabic',
      'ja' => 'Japanese',
      'zh-hans' => 'Simplified Chinese',
      'zh-hant' => 'Traditional Chinese',
      'ko' => 'Korean',
    ];

    return $languages[$langcode] ?? 'English';
  }

}
