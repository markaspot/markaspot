<?php

namespace Drupal\markaspot_vision\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
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

  use JurisdictionIdResolverTrait;

  /**
   * Max bytes for the unscaled original-image fallback sent to the AI.
   *
   * Only applies when no scaled derivative could be produced (neither the
   * canonical nor the temporary:// location was writable). The scaled
   * ai_analysis derivative is normally well under 1 MB; a multi-megabyte
   * original would inflate AI input-token cost and risk a provider 413, so
   * such an image is skipped from analysis with a loud error instead.
   */
  protected const MAX_ORIGINAL_FALLBACK_BYTES = 4194304;

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
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('markaspot_vision');
  }

  /**
   * Blurs sensitive areas (faces, license plates) in an image.
   *
   * Sends the image to the blur microservice and returns the result.
   * When blur preprocessing is enabled, failures are fail-closed so original
   * images are not forwarded to the vision provider without preprocessing.
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

    // Resolve blur service URL via the canonical schema (#309).
    $blur_url = $this->resolveBlurUrl($config->get('blur_service_url'));
    if ($blur_url === '') {
      $this->logger->error('Blur preprocessing enabled but no blur service URL configured (set MARKASPOT_BLUR_URL or markaspot_vision.settings.blur_service_url).');
      throw new \RuntimeException('Blur preprocessing is enabled but no blur service URL is configured.');
    }
    $log_url = $this->redactUrlForLog($blur_url);

    // Map MIME type to file extension for the multipart filename.
    $extensions = [
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/webp' => 'webp',
    ];
    $ext = $extensions[$mimeType] ?? 'jpg';

    // Build request options with optional Bearer auth for external blur
    // service.
    $request_options = [
      'multipart' => [
        [
          'name' => 'file',
          'contents' => $contents,
          'filename' => 'upload.' . $ext,
          'headers' => ['Content-Type' => $mimeType],
        ],
      ],
      'timeout' => 10,
      'connect_timeout' => 5,
      'http_errors' => FALSE,
    ];

    // Resolve Bearer token for the blur edge auth.
    // Stage 1: canonical MARKASPOT_BLUR_API_KEY (#309 schema).
    // Stage 2: legacy AI_API_KEY (deprecation-logged, sunset next minor).
    $bearer = $this->resolveBlurBearer();
    if ($bearer !== '') {
      $request_options['headers'] = [
        'Authorization' => 'Bearer ' . $bearer,
      ];
    }

    try {
      $response = $this->httpClient->post($blur_url, $request_options);

      $statusCode = $response->getStatusCode();
      if ($statusCode !== 200) {
        $this->logger->error('Blur service returned status @code from @url. Refusing to forward the original image to vision.', [
          '@code' => $statusCode,
          '@url' => $log_url,
        ]);
        throw new \RuntimeException('Blur service returned status ' . $statusCode . '.');
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
      $this->logger->error('Blur service failed at @url. Refusing to forward the original image to vision.', [
        '@url' => $log_url,
      ]);
      throw new \RuntimeException('Blur service failed; refusing to forward the original image to vision.', 0, $e);
    }
  }

  /**
   * Saves a blurred image as a managed file on a media entity.
   *
   * Replaces the original image on field_media_image with the blurred version.
   * Does not call $media->save() so the caller can batch field changes.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity to update.
   * @param string $contents
   *   The blurred image bytes.
   * @param string $originalUri
   *   The URI of the original file, used for deriving the filename.
   *
   * @throws \RuntimeException
   *   Thrown when the blurred image cannot replace the original.
   */
  public function saveBlurredImage(MediaInterface $media, string $contents, string $originalUri): void {
    try {
      // GDPR safeguard: only allow overwriting files in the public filesystem.
      $scheme = parse_url($originalUri, PHP_URL_SCHEME);
      if ($scheme !== 'public') {
        throw new \RuntimeException(sprintf(
          'GDPR blur refused: URI scheme "%s" is not allowed for media %s. Only public:// URIs may be overwritten.',
          (string) $scheme,
          (string) $media->id(),
        ));
      }

      // GDPR Article 5(2) audit trail: record original file fingerprint
      // before overwriting so the transformation is accountable.
      $realPath = $this->fileSystem->realpath($originalUri);
      $originalHash = $realPath && file_exists($realPath) ? md5_file($realPath) : 'unreadable';

      $this->logger->notice(
        'GDPR audit: blur overwrite for media @id | uri=@uri | original_md5=@hash | timestamp=@time',
        [
          '@id' => $media->id(),
          '@uri' => $originalUri,
          '@hash' => $originalHash,
          '@time' => gmdate('c'),
        ]
      );

      // Overwrite the original file with the blurred version.
      $this->fileSystem->saveData(
        $contents,
        $originalUri,
        FileExists::Replace
      );

      // Flush image style derivatives so they regenerate from the blurred
      // source.
      image_path_flush($originalUri);

      $this->logger->notice(
        'Original image replaced with blurred version for media @id.',
        ['@id' => $media->id()]
      );
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to save blurred image for media @id. Refusing to report blur as handled.',
        ['@id' => $media->id()]
      );
      throw new \RuntimeException('Failed to persist blurred image for media ' . $media->id() . '.', 0, $e);
    }
  }

  /**
   * Redacts credentials and query strings before logging external URLs.
   */
  protected function redactUrlForLog(string $url): string {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
      return '[invalid-url]';
    }

    $authority = $parts['host'];
    if (!empty($parts['port'])) {
      $authority .= ':' . $parts['port'];
    }

    $path = $parts['path'] ?? '';
    return $parts['scheme'] . '://' . $authority . $path;
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
      $skipped_uris = [];
      $blur_applied = FALSE;
      foreach ($file_uris as $file_uri) {
        $styled_file_path = $this->getStyledImagePath($file_uri);
        // The temporary:// fallback derivative holds an unblurred copy of the
        // citizen image; guarantee it is removed once its bytes are read, even
        // if the read throws, so unblurred PII never lingers in the temp dir.
        $is_temp = str_starts_with($styled_file_path, 'temporary://');
        // True when no scaled derivative could be produced and the original
        // (full-size) image was handed back by getStyledImagePath().
        $is_original_fallback = ($styled_file_path === $file_uri);
        try {
          $contents = file_get_contents($styled_file_path);
        }
        finally {
          if ($is_temp && file_exists($styled_file_path)) {
            try {
              $this->fileSystem->unlink($styled_file_path);
            }
            catch (\Exception $e) {
              $this->logger->warning('Could not remove temporary derivative @path: @msg', [
                '@path' => $styled_file_path,
                '@msg' => $e->getMessage(),
              ]);
            }
          }
        }
        if ($contents === FALSE) {
          $this->logger->warning('Failed to read image file: @path', ['@path' => $styled_file_path]);
          $skipped_uris[$file_uri] = 'unreadable';
          continue;
        }
        // Cost guard: only the unscaled original-image fallback can be large
        // here (derivatives are downscaled). Skip an oversized original rather
        // than inflate AI token cost / risk a provider 413. This path only
        // triggers when derivative generation is broken (e.g. public://styles
        // unwritable) AND the original exceeds the limit, so log it loudly.
        if ($is_original_fallback && strlen($contents) > self::MAX_ORIGINAL_FALLBACK_BYTES) {
          $this->logger->error('Skipping AI analysis for @uri: no scaled derivative could be created and the original (@bytes bytes) exceeds the @max byte limit. Check that public://styles is writable.', [
            '@uri' => $file_uri,
            '@bytes' => strlen($contents),
            '@max' => self::MAX_ORIGINAL_FALLBACK_BYTES,
          ]);
          $skipped_uris[$file_uri] = 'oversized_original_fallback';
          continue;
        }
        // Detect actual MIME type (image style may convert format).
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($contents) ?: 'image/jpeg';

        // Blur sensitive areas (faces, license plates) before AI analysis.
        $blur_result = $this->blurSensitiveAreas($contents, $mime);
        // Carry the MIME so the controller can build a data URL preview.
        $blur_result['mime'] = $mime;
        $blur_results[$file_uri] = $blur_result;
        if (!empty($blur_result['blurred'])) {
          $blur_applied = TRUE;
        }

        $image_data[] = [
          'base64' => base64_encode($blur_result['contents']),
          'mime' => $mime,
        ];
      }
      if (empty($image_data)) {
        throw new \Exception('No images could be prepared for AI analysis.');
      }

      $categories = $this->getAllCategoriesHierarchical($jurisdictionId, $langcode);
      $category_json = json_encode($categories, JSON_UNESCAPED_UNICODE);
      $category_json = str_replace(["\n", "\r"], '', $category_json);

      // Resolve language for the AI response.
      $language = $this->resolveLanguageName($langcode);

      // Enhance the prompt to emphasize collective analysis.
      $image_count = count($image_data);
      $collective_prefix = "The following set of {$image_count} images shows a single situation or issue. " .
        "Please analyze them together as one complete scene. Consider how the images relate to and complement each other. ";
      $prompt = str_replace(
        ['{categories}', '{language}'],
        [$category_json, $language],
        $prompt_template
      );
      $prompt = $collective_prefix . $prompt;

      // Instruct AI to generate privacy-safe descriptions while leaving the
      // review policy to the configured tenant prompt.
      $prompt .= $this->buildPrivacyInstruction($blur_applied);

      // Off-domain detection: ask the model whether the image is actually a
      // reportable municipal issue, so the UI can ask the citizen to pick a
      // category instead of acting on a confabulated one.
      $prompt .= $this->buildReportabilityInstruction();

      // Append service definition attributes to the prompt.
      $serviceDefsText = $this->getServiceDefinitionsForPrompt($jurisdictionId, $langcode);
      if (!empty($serviceDefsText)) {
        $prompt .= "\n\n## Service Definition Attributes\n"
          . "Some categories have additional form fields (attributes). "
          . "For the category you select, fill matching attributes based on what you observe in the image(s). "
          . "Return values in the \"attributes\" array using the exact attribute codes listed below. "
          . "For singlevaluelist/multivaluelist types, use ONLY the provided option keys. "
          . "If you cannot determine a value from the image, use an empty string.\n\n"
          . $serviceDefsText;
      }

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
        if ($this->isJurisdictionGroup($group) && $group->hasField('field_ai_system_prompt') && !$group->get('field_ai_system_prompt')->isEmpty()) {
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
        'skipped_uris' => $skipped_uris,
      ];

    }
    catch (\Exception $e) {
      $this->logger->error('Error processing image set: ' . $e->getMessage());
      return NULL;
    }
  }

  /**
   * Builds the privacy instruction appended to the AI prompt.
   *
   * @param bool $blur_applied
   *   TRUE when image preprocessing already blurred sensitive regions.
   *
   * @return string
   *   Prompt suffix for privacy-safe AI output.
   */
  protected function buildPrivacyInstruction(bool $blur_applied): string {
    // Deterministic baseline policy. This MUST stay self-contained so that
    // privacy_flag (which drives internal moderation and depublishing) is set
    // reliably for every tenant, regardless of whether the tenant configured a
    // custom system prompt. Tenant system prompts may add to this policy but
    // must not be required for it to work.
    $instruction = "\n\nPRIVACY INSTRUCTION: "
      . "If you detect personal data (faces, license plates, readable personal "
      . "names, documents, IDs, or house numbers), set privacy_flag to true and "
      . "list the concerns in privacy_issues. ";

    if ($blur_applied) {
      // Faces/plates were already blurred by preprocessing. The AI must still
      // flag any personal data that remains visible, readable, or insufficiently
      // anonymised; the blur fact alone is not a moderation hold.
      $instruction .= "Some faces or license plates in these images have already "
        . "been blurred by preprocessing. Already blurred regions alone are not "
        . "privacy concerns. Set privacy_flag to true only when personal data "
        . "remains visible, readable, or insufficiently anonymised. ";
    }

    return $instruction
      . "IMPORTANT: Still generate a useful description, category, and hazard assessment, "
      . "but write the description WITHOUT mentioning or referencing any identifiable persons, "
      . "license plates, personal names, documents, IDs, or house numbers. "
      . "Describe the situation and the issue, not the people.";
  }

  /**
   * Builds the off-domain (reportability) instruction for the AI prompt.
   *
   * The response schema forces a category, which makes the model confabulate a
   * report for off-domain images (e.g. a portrait). This asks the model for a
   * coarse, reliable yes/no so the UI can ask the citizen to choose a category
   * rather than acting on a made-up one. It never gates moderation.
   *
   * @return string
   *   Prompt suffix instructing the model to set is_reportable_issue.
   */
  protected function buildReportabilityInstruction(): string {
    return "\n\nREPORTABILITY: Set is_reportable_issue to true when the image "
      . "shows a real, reportable public-space issue that fits one of the listed "
      . "categories (e.g. waste, road or sign damage, broken infrastructure). "
      . "Set is_reportable_issue to false when the image shows no reportable "
      . "issue at all (e.g. a portrait or selfie, an unrelated indoor object, a "
      . "screenshot, or content too unclear to assess). Still return your "
      . "best-guess category and description either way; the application decides "
      . "how to use is_reportable_issue.";
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
    $auth_type = getenv('MARKASPOT_VISION_AUTH_TYPE') ?: $config->get('auth_type') ?? 'bearer';
    $api_key = $this->resolveApiKey($config);
    // ENV takes priority over config and allows per-instance overrides without
    // config changes.
    $api_url = trim(getenv('MARKASPOT_VISION_API_URL') ?: $config->get('api_url') ?? '');

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
   * Priority: MARKASPOT_VISION_API_KEY env > config > OPENAI_API_KEY env.
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
              // TRUE when the image shows an actual reportable municipal issue
              // that fits a category; FALSE for off-domain images (portraits,
              // unrelated objects, unclear content). Drives the citizen-facing
              // "please pick a category yourself" hint; never gates moderation.
              'is_reportable_issue' => ['type' => 'boolean'],
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
              'attributes' => [
                'type' => 'array',
                'items' => [
                  'type' => 'object',
                  'properties' => [
                    'code' => ['type' => 'string'],
                    'value' => ['type' => ['string', 'null']],
                  ],
                  'required' => ['code', 'value'],
                  'additionalProperties' => FALSE,
                ],
              ],
            ],
            'required' => [
              'category',
              'is_reportable_issue',
              'description',
              'alt_text',
              'hazard_flag',
              'hazard_level',
              'hazard_issues',
              'privacy_flag',
              'privacy_issues',
              'attributes',
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
    $style = $this->entityTypeManager->getStorage('image_style')->load('ai_analysis');
    if (!$style) {
      throw new \Exception('The "ai_analysis" image style was not found. Please ensure it exists.');
    }
    $styled_file_path = $style->buildUri($uri);
    // Reuse an already-generated derivative.
    if (file_exists($styled_file_path)) {
      return $styled_file_path;
    }
    // Generate the derivative in its canonical location. createDerivative()
    // returns FALSE when the destination is not writable (e.g. public://styles
    // on a deployment where that path is not writable by the web user); the
    // return value MUST be checked, otherwise a failed generation surfaces only
    // as a downstream "failed to read" and the image is silently dropped from
    // AI analysis AND privacy blurring.
    if ($style->createDerivative($uri, $styled_file_path) && file_exists($styled_file_path)) {
      return $styled_file_path;
    }
    // Fallback: build the same scaled derivative in temporary:// (the system
    // temp dir, writable even when public://styles is not). This preserves the
    // AI token savings of the downscaled image and keeps blur preprocessing
    // working. The caller removes the temp file after reading it.
    // Constrain the extension to a known image type (the derivative still holds
    // an unblurred copy of the citizen image) and make the name per-process
    // unique so concurrent workers analysing the same media never race on, or
    // unlink, each other's temp file.
    $raw_extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
    $extension = in_array($raw_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], TRUE) ? $raw_extension : 'jpg';
    $temp_path = 'temporary://markaspot_vision_ai_' . md5($uri) . '_' . getmypid() . '_' . uniqid() . '.' . $extension;
    if ($style->createDerivative($uri, $temp_path) && file_exists($temp_path)) {
      $this->logger->warning('The "ai_analysis" derivative could not be written to its canonical location for @uri; used a temporary:// fallback. Check that public://styles is writable.', ['@uri' => $uri]);
      return $temp_path;
    }
    // Last resort: hand back the original. AI then runs on the full-size image
    // (higher token cost) but analysis and privacy blurring still happen.
    $this->logger->warning('Could not create the "ai_analysis" derivative for @uri; falling back to the original image (higher AI token cost).', ['@uri' => $uri]);
    return $uri;
  }

  /**
   * Retrieves all leaf categories with their IDs and full paths.
   *
   * Public so that markaspot_mail_inbound's MailCategorySuggestionService can
   * reuse the same category list for text classification without duplicating
   * this query. No other callers outside these two modules should use it
   * directly; treat it as package-internal.
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
  public function getAllCategoriesHierarchical(?int $jurisdictionId = NULL, ?string $langcode = NULL): array {
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
   * Sanitizes a string for safe inclusion in an AI prompt.
   *
   * Strips newlines and control characters, and truncates to prevent
   * prompt injection via admin-editable service definition fields.
   *
   * @param string $value
   *   The raw string value.
   * @param int $maxLength
   *   Maximum allowed character length.
   *
   * @return string
   *   The sanitized string.
   */
  private function sanitizePromptField(string $value, int $maxLength): string {
    // Strip all control characters (U+0000-U+001F, U+007F-U+009F).
    $sanitized = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', ' ', $value);
    return mb_substr($sanitized, 0, $maxLength, 'UTF-8');
  }

  /**
   * Builds a prompt section with service definition attributes per category.
   *
   * Loads taxonomy terms from service_category, checks each for
   * field_service_definition JSON, and formats variable attributes
   * as human-readable text so the AI can suggest attribute values.
   *
   * @param int|null $jurisdictionId
   *   Optional jurisdiction group ID to filter categories.
   * @param string|null $langcode
   *   Optional language code for translated term labels.
   *
   * @return string
   *   Formatted attribute definitions per category, or empty string
   *   if no categories have service definitions.
   */
  private function getServiceDefinitionsForPrompt(?int $jurisdictionId, ?string $langcode): string {
    try {
      $properties = ['vid' => 'service_category', 'status' => 1];
      if ($jurisdictionId) {
        $properties['field_jurisdiction'] = $jurisdictionId;
      }

      $terms = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadByProperties($properties);

      if (empty($terms)) {
        return '';
      }

      // Translate terms if a specific language is requested.
      if ($langcode) {
        foreach ($terms as $tid => $term) {
          if ($term->hasTranslation($langcode)) {
            $terms[$tid] = $term->getTranslation($langcode);
          }
        }
      }

      $sections = [];

      foreach ($terms as $term) {
        if (!$term->hasField('field_service_definition') || $term->get('field_service_definition')->isEmpty()) {
          continue;
        }

        $raw = $term->get('field_service_definition')->value;
        $decoded = json_decode($raw, TRUE);
        if (!is_array($decoded)) {
          continue;
        }

        // Accept both {"attributes": [...]} wrapper and plain array format.
        $attributes = isset($decoded['attributes']) && is_array($decoded['attributes'])
          ? $decoded['attributes']
          : $decoded;

        // Filter to variable attributes only.
        $variable_attrs = array_filter($attributes, function ($attr) {
          return !empty($attr['variable']);
        });

        if (empty($variable_attrs)) {
          continue;
        }

        $tid = $term->id();
        $label = $this->sanitizePromptField($term->label(), 100);
        $lines = [];

        foreach ($variable_attrs as $attr) {
          $code = $this->sanitizePromptField($attr['code'] ?? '', 64);
          $datatype = $this->sanitizePromptField($attr['datatype'] ?? 'string', 32);
          $description = $this->sanitizePromptField($attr['description'] ?? $code, 200);
          $required = !empty($attr['required']) ? ', required' : '';

          $line = "- \"{$code}\" ({$datatype}{$required}): {$description}";

          // Add valid options for list types.
          if (in_array($datatype, ['singlevaluelist', 'multivaluelist'], TRUE)
              && !empty($attr['values'])) {
            $options = [];
            foreach ($attr['values'] as $option) {
              $key = $this->sanitizePromptField($option['key'] ?? '', 64);
              $name = $this->sanitizePromptField($option['name'] ?? $key, 100);
              $options[] = "\"{$key}\" = {$name}";
            }
            $line .= "\n  Options: " . implode(', ', $options);
          }

          $lines[] = $line;
        }

        $sections[] = "Category tid={$tid} \"{$label}\":\n" . implode("\n", $lines);
      }

      // Build result with per-category budget to avoid cutting mid-definition.
      $result = '';
      $budget = 4000;
      $included = 0;

      foreach ($sections as $section) {
        $needed = mb_strlen($section, 'UTF-8') + ($included > 0 ? 2 : 0);
        if ($needed > $budget) {
          $this->logger->warning('Service definitions prompt truncated after @count categories for jurisdiction @jid.', [
            '@count' => $included,
            '@jid' => $jurisdictionId,
          ]);
          break;
        }
        $result .= ($included > 0 ? "\n\n" : '') . $section;
        $budget -= $needed;
        $included++;
      }

      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('Error building service definitions for prompt: @error', [
        '@error' => $e->getMessage(),
      ]);
      return '';
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

  /**
   * Resolves the bearer token for the blur edge service.
   *
   * Two-stage resolution per the canonical schema in #309:
   *   1. Canonical ENV: MARKASPOT_BLUR_API_KEY.
   *   2. Legacy ENV: AI_API_KEY (deprecation-logged, sunset next minor).
   *
   * No config-side fallback exists: the bearer is exclusively edge-auth and
   * must come from the deployment ENV, never from persisted Drupal config.
   *
   * @return string
   *   The resolved bearer token, or empty string if not configured.
   */
  protected function resolveBlurBearer(): string {
    $canonical = getenv('MARKASPOT_BLUR_API_KEY');
    if (is_string($canonical) && $canonical !== '') {
      return $canonical;
    }

    $legacy = getenv('AI_API_KEY');
    if (is_string($legacy) && $legacy !== '') {
      $this->logger->warning('Deprecated ENV AI_API_KEY used for blur bearer; migrate to MARKASPOT_BLUR_API_KEY (see #309).');
      return $legacy;
    }

    return '';
  }

  /**
   * Resolves the blur service URL.
   *
   * Resolution order per the canonical schema in #309:
   *   1. Drupal config (markaspot_vision.settings.blur_service_url).
   *   2. Canonical ENV: MARKASPOT_BLUR_URL.
   *   3. Legacy ENV: VISION_BLUR_URL (deprecation-logged).
   *
   * No default is supplied: the blur step requires explicit deployment
   * configuration. If nothing is set, blurSensitiveAreas() skips the
   * blur step and returns the original image untouched.
   *
   * @param string|null $configValue
   *   The blur_service_url config value, may be NULL or empty.
   *
   * @return string
   *   The resolved blur service URL, or empty string if not configured.
   */
  protected function resolveBlurUrl(?string $configValue): string {
    if (is_string($configValue) && $configValue !== '') {
      return $configValue;
    }

    $canonical = getenv('MARKASPOT_BLUR_URL');
    if (is_string($canonical) && $canonical !== '') {
      return $canonical;
    }

    $legacy = getenv('VISION_BLUR_URL');
    if (is_string($legacy) && $legacy !== '') {
      $this->logger->warning('Deprecated ENV VISION_BLUR_URL used for blur service; migrate to MARKASPOT_BLUR_URL (see #309).');
      return $legacy;
    }

    return '';
  }

}
