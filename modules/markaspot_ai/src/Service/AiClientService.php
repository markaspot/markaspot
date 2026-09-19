<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Drupal\markaspot_ai\Utility\ProviderError;
use Drupal\markaspot_ai\Exception\ProviderRequestException;

/**
 * Provider-agnostic HTTP client for AI APIs.
 *
 * This service handles communication with various AI providers (OpenAI, Azure,
 * Anthropic, etc.) using a unified interface. It supports different
 * authentication methods and includes retry logic with exponential backoff.
 */
class AiClientService {

  /**
   * Default chat model matching the untouched install configuration.
   *
   * Providers with different model or deployment identifiers must configure
   * their own chat_model. This constant is the final fallback only.
   *
   * @var string
   */
  public const DEFAULT_CHAT_MODEL = 'gpt-4.1-mini';

  /**
   * Default embedding model when no provider model is configured.
   */
  public const DEFAULT_EMBEDDING_MODEL = 'text-embedding-3-large';

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
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The token tracking service.
   *
   * @var \Drupal\markaspot_ai\Service\TokenTrackingService
   */
  protected TokenTrackingService $tokenTracking;

  /**
   * Constructs a new AiClientService.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\markaspot_ai\Service\TokenTrackingService $token_tracking
   *   The token tracking service.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    TokenTrackingService $token_tracking,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('markaspot_ai');
    $this->tokenTracking = $token_tracking;
  }

  /**
   * Sends a chat completion request to the AI API.
   *
   * @param array $messages
   *   Array of message objects with 'role' and 'content' keys.
   *   Example: [['role' => 'user', 'content' => 'Hello']].
   * @param array $options
   *   Optional parameters:
   *   - 'model': Override the default model.
   *   - 'temperature': Float between 0 and 2.
   *   - 'max_tokens': Maximum tokens in response.
   *   - 'response_format': Response format specification.
   *   - 'provider': Override the default provider.
   *   - 'timeout': Per-attempt HTTP timeout in seconds, clamped to 1-120.
   *   - 'connect_timeout': Connection timeout, clamped to 1-30 and timeout.
   *   - 'max_attempts': Maximum attempts, clamped to 1-3 (default: 3).
   *
   * @return array
   *   The API response containing:
   *   - 'choices': Array of completion choices.
   *   - 'usage': Token usage information.
   *   - 'model': The model used.
   *
   * @throws \Exception
   *   When the API request fails after all retry attempts.
   */
  public function chat(array $messages, array $options = []): array {
    // Check token limit before making API call.
    if (!$this->tokenTracking->checkLimit()) {
      throw new \Exception('Daily token limit exceeded. API request blocked.');
    }

    $config = $this->getConfig();
    $provider = $options['provider'] ?? $config->get('default_provider') ?? 'openai';
    $provider_config = $config->get("providers.{$provider}") ?? [];

    $model = $this->resolveChatModel($options['model'] ?? NULL, $provider);
    if ($provider === 'anthropic') {
      return $this->chatAnthropic($messages, $provider_config, $model, $options);
    }

    $endpoint = $this->buildEndpoint($provider, $provider_config, $model, 'chat/completions');

    $headers = $this->buildAuthHeaders(
      $provider_config['auth_type'] ?? 'bearer',
      $this->resolveApiKey($provider, $provider_config)
    );

    $payload = [
      'model' => $model,
      'messages' => $messages,
    ];

    // Add optional parameters if provided.
    if (isset($options['temperature']) && ($provider_config['send_temperature'] ?? TRUE)) {
      $payload['temperature'] = (float) $options['temperature'];
    }
    $limit_param = $provider_config['max_tokens_param'] ?? 'max_tokens';
    if (isset($options['max_tokens']) && $limit_param !== 'none') {
      $payload[$limit_param] = max((int) $options['max_tokens'], (int) ($provider_config['max_tokens_min'] ?? 0));
    }
    $format_mode = $provider_config['response_format_mode'] ?? 'native';
    if (isset($options['response_format']) && $format_mode !== 'none') {
      $payload['response_format'] = $format_mode === 'json_object' && ($options['response_format']['type'] ?? '') === 'json_schema'
        ? ['type' => 'json_object'] : $options['response_format'];
    }
    if (isset($options['top_p'])) {
      $payload['top_p'] = (float) $options['top_p'];
    }

    return $this->sendChatRequest($endpoint, $headers, $payload, $options, $provider, $model);
  }

  /**
   * Resolves a chat model from an override, provider config, or the default.
   */
  public function resolveChatModel(?string $override = NULL, ?string $provider = NULL): string {
    $provider ??= $this->getConfig()->get('default_provider') ?? 'openai';
    return trim($override ?? '') !== '' ? $override : (($this->getConfig()->get("providers.{$provider}")['chat_model'] ?? NULL) ?: self::DEFAULT_CHAT_MODEL);
  }

  /**
   * Resolves the embedding model consistently for requests and stored vectors.
   */
  public function resolveEmbeddingModel(?string $override = NULL, ?string $provider = NULL): string {
    $provider ??= $this->getConfig()->get('default_provider') ?? 'openai';
    return trim($override ?? '') !== '' ? $override : (($this->getConfig()->get("providers.{$provider}")['embedding_model'] ?? NULL) ?: self::DEFAULT_EMBEDDING_MODEL);
  }

  /**
   * Sends chat with one parameter repair, independent of transient retries.
   */
  protected function sendChatRequest(string $endpoint, array $headers, array $payload, array $options, string $provider, string $model): array {
    $adapted = FALSE;
    return $this->executeWithRetry(function () use ($endpoint, $headers, &$payload, $options, $provider, $model, &$adapted) {
      try {
        $response = $this->sendRequest('POST', $endpoint, $headers, $payload, $options);
      }
      catch (\Exception $e) {
        if ($adapted || $e->getCode() !== 400 || !($e instanceof ProviderRequestException) || !$this->adaptRejectedParameter($payload, $e->rejectedParameters, $provider, $model)) {
          throw $e;
        }
        $adapted = TRUE;
        $response = $this->sendRequest('POST', $endpoint, $headers, $payload, $options);
      }
      $content = $response['choices'][0]['message']['content'] ?? '';
      $exhausted = ($response['choices'][0]['finish_reason'] ?? '') === 'length';
      if ($provider === 'anthropic') {
        $content = '';
        foreach ($response['content'] ?? [] as $block) {
          if (($block['type'] ?? '') === 'text') {
            $content .= $block['text'] ?? '';
          }
        }
        $exhausted = ($response['stop_reason'] ?? '') === 'max_tokens';
      }
      if ($exhausted && (!is_string($content) || trim($content) === '')) {
        $message = "Token budget exhausted for {$provider}/{$model}; increase providers.{$provider}.max_tokens_min.";
        if (isset($response['usage'])) {
          $usage = $response['usage'];
          $this->tokenTracking->logUsage($provider, $model, $options['operation'] ?? 'chat', (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0));
        }
        $this->logger->error('@message', ['@message' => $message]);
        throw new \RuntimeException($message);
      }
      return $response;
    }, max(1, min(3, (int) ($options['max_attempts'] ?? 3))));
  }

  /**
   * Repairs a named rejected parameter, without inferring model capabilities.
   */
  protected function adaptRejectedParameter(array &$payload, array $rejected, string $provider, string $model): bool {
    foreach ($rejected as $parameter) {
      if (!array_key_exists($parameter, $payload)) {
        continue;
      }
      $key = match ($parameter) {
        'temperature' => 'send_temperature=false',
        'top_p' => 'top_p (omit from caller options; no provider policy key)',
        'max_tokens' => 'max_tokens_param=max_completion_tokens',
        default => 'response_format_mode=none',
      };
      if ($parameter === 'max_tokens') {
        // Anthropic requires max_tokens and cannot accept its OpenAI alias.
        if ($provider === 'anthropic') {
          return FALSE;
        }
        $payload['max_completion_tokens'] = $payload[$parameter];
        unset($payload[$parameter]);
      }
      elseif ($parameter === 'response_format' && ($payload[$parameter]['type'] ?? '') === 'json_schema') {
        $payload[$parameter] = ['type' => 'json_object'];
        $key = 'response_format_mode=json_object';
      }
      else {
        unset($payload[$parameter]);
      }
      $setting = $parameter === 'top_p' ? $key : "providers.{$provider}.{$key}";
      $this->logger->warning('Provider @provider model @model rejected @parameter; retried once. Permanent setting: @setting.', [
        '@provider' => $provider,
        '@model' => $model,
        '@parameter' => $parameter,
        '@setting' => $setting,
      ]);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Generates embeddings for the given text.
   *
   * @param string|array $text
   *   The text to embed. Can be a single string or array of strings.
   * @param array $options
   *   Optional parameters:
   *   - 'model': Override the default embedding model.
   *   - 'dimensions': Number of dimensions for the embedding.
   *   - 'provider': Override the default provider.
   *
   * @return array
   *   The API response containing:
   *   - 'data': Array of embedding objects with 'embedding' vectors.
   *   - 'usage': Token usage information.
   *   - 'model': The model used.
   *
   * @throws \Exception
   *   When the API request fails after all retry attempts.
   */
  public function embed(string|array $text, array $options = []): array {
    // Check token limit before making API call.
    if (!$this->tokenTracking->checkLimit()) {
      throw new \Exception('Daily token limit exceeded. API request blocked.');
    }

    $config = $this->getConfig();
    $provider = $options['provider'] ?? $config->get('default_provider') ?? 'openai';
    $provider_config = $config->get("providers.{$provider}") ?? [];
    if ($provider === 'anthropic') {
      throw new \InvalidArgumentException(
        'Anthropic embeddings are not supported by this adapter. Use an OpenAI-compatible provider for embedding workflows.'
      );
    }

    $model = $this->resolveEmbeddingModel($options['model'] ?? NULL, $provider);
    $endpoint = $this->buildEndpoint($provider, $provider_config, $model, 'embeddings');

    $headers = $this->buildAuthHeaders(
      $provider_config['auth_type'] ?? 'bearer',
      $this->resolveApiKey($provider, $provider_config)
    );

    $payload = [
      'model' => $model,
      'input' => $text,
    ];

    // Add optional dimensions parameter if provided.
    if (isset($options['dimensions'])) {
      $payload['dimensions'] = (int) $options['dimensions'];
    }

    return $this->executeWithRetry(function () use ($endpoint, $headers, $payload) {
      return $this->sendRequest('POST', $endpoint, $headers, $payload);
    });
  }

  /**
   * Builds the API endpoint URL based on provider type.
   *
   * For Azure, constructs the deployment-specific URL:
   * {base}/openai/deployments/{model}/{operation}?api-version={version}
   *
   * For OpenAI and others, appends the operation to the base URL:
   * {base}/{operation}
   *
   * @param string $provider
   *   The provider name ('openai', 'azure', etc.).
   * @param array $providerConfig
   *   The provider configuration array.
   * @param string $model
   *   The model/deployment name.
   * @param string $operation
   *   The API operation ('chat/completions' or 'embeddings').
   *
   * @return string
   *   The fully constructed endpoint URL.
   */
  protected function buildEndpoint(string $provider, array $providerConfig, string $model, string $operation): string {
    $api_url = rtrim(
      getenv('MARKASPOT_AI_API_URL') ?: $providerConfig['api_url'] ?? 'https://api.openai.com/v1',
      '/'
    );

    if ($provider === 'azure') {
      $api_version = getenv('MARKASPOT_AI_API_VERSION') ?: $providerConfig['api_version'] ?? '2024-12-01-preview';
      return $api_url . '/openai/deployments/' . rawurlencode($model) . '/' . $operation . '?api-version=' . urlencode($api_version);
    }

    return $api_url . '/' . $operation;
  }

  /**
   * Builds authentication headers based on the auth type.
   *
   * @param string $authType
   *   The authentication type: 'bearer', 'api_key_header', or 'none'.
   * @param string $apiKey
   *   The API key to use for authentication.
   *
   * @return array
   *   The headers array including authentication.
   */
  public function buildAuthHeaders(string $authType, string $apiKey): array {
    $headers = [
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ];

    switch ($authType) {
      case 'bearer':
        if (!empty($apiKey)) {
          $headers['Authorization'] = 'Bearer ' . $apiKey;
        }
        break;

      case 'api_key_header':
        if (!empty($apiKey)) {
          $headers['api-key'] = $apiKey;
        }
        break;

      case 'x_api_key':
        if (!empty($apiKey)) {
          $headers['x-api-key'] = $apiKey;
        }
        break;

      case 'none':
      default:
        // No authentication header needed.
        break;
    }

    return $headers;
  }

  /**
   * Executes a callable with retry logic and exponential backoff.
   *
   * @param callable $request
   *   The callable to execute. Should return the result or throw an exception.
   * @param int $maxAttempts
   *   Maximum number of attempts (default: 3).
   *
   * @return mixed
   *   The result of the callable.
   *
   * @throws \Exception
   *   When all retry attempts have been exhausted.
   */
  public function executeWithRetry(callable $request, int $maxAttempts = 3): mixed {
    $attempts = 0;
    $lastException = NULL;

    while ($attempts < $maxAttempts) {
      try {
        return $request();
      }
      catch (\Exception $e) {
        $lastException = $e;
        $attempts++;

        // Check if this is a retryable error.
        $isRetryable = $this->isRetryableError($e);

        if ($isRetryable && $attempts < $maxAttempts) {
          // Exponential backoff: 2^attempt * base_seconds.
          $waitTime = (int) pow(2, $attempts) * 5;

          $this->logger->warning('AI API request failed (attempt @attempt/@max): @message. Retrying in @wait seconds.', [
            '@attempt' => $attempts,
            '@max' => $maxAttempts,
            '@message' => $e->getMessage(),
            '@wait' => $waitTime,
          ]);

          $this->wait($waitTime);
          continue;
        }

        // Non-retryable error or max attempts reached.
        break;
      }
    }

    $this->logger->error('AI API request failed after @attempts attempts: @message', [
      '@attempts' => $attempts,
      '@message' => $lastException?->getMessage() ?? 'Unknown error',
    ]);

    throw new \Exception(
      'AI API request failed after ' . $attempts . ' attempts: ' . ($lastException?->getMessage() ?? 'Unknown error'),
      0,
      $lastException
    );
  }

  /**
   * Waits for the specified number of seconds.
   *
   * Extracted to allow overriding in tests.
   *
   * @param int $seconds
   *   The number of seconds to wait.
   */
  protected function wait(int $seconds): void {
    sleep($seconds);
  }

  /**
   * Sends an HTTP request to the AI API.
   *
   * @param string $method
   *   The HTTP method (GET, POST, etc.).
   * @param string $url
   *   The full URL to send the request to.
   * @param array $headers
   *   The request headers.
   * @param array|null $payload
   *   The request payload (for POST requests).
   * @param array $requestOptions
   *   Optional timeout and connect_timeout limits.
   *
   * @return array
   *   The decoded JSON response.
   *
   * @throws \Exception
   *   When the request fails or returns an error status.
   */
  protected function sendRequest(string $method, string $url, array $headers, ?array $payload = NULL, array $requestOptions = []): array {
    $timeout = max(1.0, min(120.0, (float) ($requestOptions['timeout'] ?? 120)));
    $options = [
      'headers' => $headers,
      'http_errors' => FALSE,
      'timeout' => $timeout,
      'connect_timeout' => max(1.0, min(30.0, $timeout, (float) ($requestOptions['connect_timeout'] ?? 30))),
    ];

    if ($payload !== NULL) {
      $options['json'] = $payload;
    }

    try {
      $response = $this->httpClient->request($method, $url, $options);
    }
    catch (GuzzleException $e) {
      throw new \Exception(mb_substr('HTTP request failed: ' . $e->getMessage(), 0, 300), $e->getCode());
    }

    $statusCode = $response->getStatusCode();
    $body = (string) $response->getBody();

    // Handle rate limiting specifically.
    if ($statusCode === 429) {
      throw new \Exception('Rate limit exceeded (429). Please wait before retrying.', 429);
    }

    // Handle other error status codes.
    if ($statusCode >= 400) {
      $errorMessage = $this->parseErrorMessage($body, $statusCode);
      $decoded_error = json_decode($body, TRUE);
      $rejected = [];
      foreach (['temperature', 'top_p', 'max_tokens', 'response_format'] as $parameter) {
        if (is_array($decoded_error) && ProviderError::rejects($decoded_error, $parameter, isset($headers['anthropic-version']))) {
          $rejected[] = $parameter;
        }
      }
      throw new ProviderRequestException($errorMessage, $statusCode, $rejected);
    }

    $decoded = json_decode($body, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \Exception('Failed to decode API response: ' . json_last_error_msg());
    }

    return $decoded;
  }

  /**
   * Sends a chat request to Anthropic's Messages API.
   *
   * Anthropic does not use OpenAI's chat/completions shape. This adapter keeps
   * the public chat() return shape compatible with existing callers.
   */
  protected function chatAnthropic(array $messages, array $providerConfig, string $model, array $options): array {
    $endpoint = rtrim(
      getenv('MARKASPOT_AI_API_URL') ?: $providerConfig['api_url'] ?? 'https://api.anthropic.com/v1',
      '/'
    ) . '/messages';

    $apiVersion = getenv('MARKASPOT_AI_API_VERSION') ?: $providerConfig['api_version'] ?? '2023-06-01';
    $headers = $this->buildAuthHeaders(
      $providerConfig['auth_type'] ?? 'x_api_key',
      $this->resolveApiKey('anthropic', $providerConfig)
    );
    $headers['anthropic-version'] = $apiVersion;

    $system = [];
    $anthropicMessages = [];
    foreach ($messages as $message) {
      $role = $message['role'] ?? 'user';
      $content = $message['content'] ?? '';
      if ($role === 'system') {
        $system[] = is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_SLASHES);
        continue;
      }
      $anthropicMessages[] = [
        'role' => $role === 'assistant' ? 'assistant' : 'user',
        'content' => is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_SLASHES),
      ];
    }

    if ($anthropicMessages === []) {
      $anthropicMessages[] = ['role' => 'user', 'content' => ''];
    }

    $payload = [
      'model' => $model,
      'max_tokens' => max(1, (int) ($options['max_tokens'] ?? 1024), (int) ($providerConfig['max_tokens_min'] ?? 0)),
      'messages' => $anthropicMessages,
    ];
    if ($system !== []) {
      $payload['system'] = implode("\n\n", $system);
    }
    if (isset($options['temperature']) && ($providerConfig['send_temperature'] ?? TRUE)) {
      $payload['temperature'] = (float) $options['temperature'];
    }
    if (isset($options['top_p'])) {
      $payload['top_p'] = (float) $options['top_p'];
    }

    $response = $this->sendChatRequest($endpoint, $headers, $payload, $options, 'anthropic', $model);

    $content = '';
    foreach ($response['content'] ?? [] as $block) {
      if (($block['type'] ?? NULL) === 'text' && isset($block['text'])) {
        $content .= (string) $block['text'];
      }
    }

    return [
      'choices' => [
        ['message' => ['content' => $content]],
      ],
      'usage' => [
        'prompt_tokens' => $response['usage']['input_tokens'] ?? 0,
        'completion_tokens' => $response['usage']['output_tokens'] ?? 0,
      ],
      'model' => $response['model'] ?? $model,
      'raw' => $response,
    ];
  }

  /**
   * Determines if an error is retryable.
   *
   * @param \Exception $exception
   *   The exception to check.
   *
   * @return bool
   *   TRUE if the error is retryable, FALSE otherwise.
   */
  protected function isRetryableError(\Exception $exception): bool {
    $code = $exception->getCode();
    if ($code === 400) {
      return FALSE;
    }

    // Retryable HTTP status codes.
    $retryableCodes = [
    // Rate limited.
      429,
    // Internal server error.
      500,
    // Bad gateway.
      502,
    // Service unavailable.
      503,
    // Gateway timeout.
      504,
    ];

    if (in_array($code, $retryableCodes, TRUE)) {
      return TRUE;
    }

    // Check for connection/timeout errors in the message.
    $message = strtolower($exception->getMessage());
    $retryablePatterns = [
      'timeout',
      'connection reset',
      'connection refused',
      'network is unreachable',
      'temporarily unavailable',
    ];

    foreach ($retryablePatterns as $pattern) {
      if (str_contains($message, $pattern)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Parses error message from API response body.
   *
   * @param string $body
   *   The response body.
   * @param int $statusCode
   *   The HTTP status code.
   *
   * @return string
   *   A formatted error message.
   */
  protected function parseErrorMessage(string $body, int $statusCode): string {
    return ProviderError::message($body, $statusCode);
  }

  /**
   * Resolves the API key from environment variable or config.
   *
   * Three-stage resolution per the canonical schema in #309:
   *   1. Canonical ENV: MARKASPOT_AI_API_KEY (provider-agnostic).
   *   2. Legacy per-provider ENVs (deprecation-logged).
   *   3. providerConfig['api_key'] from markaspot_ai.settings.
   *
   * @param string $provider
   *   The provider name (e.g., 'openai', 'azure').
   * @param array $providerConfig
   *   The provider configuration array.
   *
   * @return string
   *   The resolved API key, or empty string if not found.
   */
  protected function resolveApiKey(string $provider, array $providerConfig): string {
    // Stage 1: canonical ENV (#309 schema).
    $canonical = getenv('MARKASPOT_AI_API_KEY');
    if (is_string($canonical) && $canonical !== '') {
      return $canonical;
    }

    // Stage 2: legacy per-provider ENV names with deprecation warning.
    $legacy = match ($provider) {
      'openai' => ['OPENAI_API_KEY', 'MARKASPOT_AI_OPENAI_KEY'],
      'azure' => ['AZURE_OPENAI_API_KEY', 'MARKASPOT_AI_AZURE_KEY'],
      'anthropic' => ['ANTHROPIC_API_KEY', 'MARKASPOT_AI_ANTHROPIC_KEY'],
      'ionos' => ['IONOS_AI_API_KEY', 'MARKASPOT_AI_IONOS_KEY'],
      default => ['MARKASPOT_AI_' . strtoupper($provider) . '_KEY'],
    };
    foreach ($legacy as $envVar) {
      $value = getenv($envVar);
      if (is_string($value) && $value !== '') {
        $this->logger->warning('Deprecated ENV @legacy used for provider @provider; migrate to MARKASPOT_AI_API_KEY (see #309).', [
          '@legacy' => $envVar,
          '@provider' => $provider,
        ]);
        return $value;
      }
    }

    // Stage 3: config fallback.
    return $providerConfig['api_key'] ?? '';
  }

  /**
   * Gets the module configuration.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The configuration object.
   */
  protected function getConfig(): ImmutableConfig {
    return $this->configFactory->get('markaspot_ai.settings');
  }

}
