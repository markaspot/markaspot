<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Provider-agnostic HTTP client for AI APIs.
 *
 * This service handles communication with various AI providers (OpenAI, Azure,
 * Anthropic, etc.) using a unified interface. It supports different
 * authentication methods and includes retry logic with exponential backoff.
 */
class AiClientService {

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

    $api_url = rtrim($provider_config['api_url'] ?? 'https://api.openai.com/v1', '/');
    $endpoint = $api_url . '/chat/completions';

    $headers = $this->buildAuthHeaders(
      $provider_config['auth_type'] ?? 'bearer',
      $this->resolveApiKey($provider, $provider_config)
    );

    $payload = [
      'model' => $options['model'] ?? $provider_config['chat_model'] ?? 'gpt-4o',
      'messages' => $messages,
    ];

    // Add optional parameters if provided.
    if (isset($options['temperature'])) {
      $payload['temperature'] = (float) $options['temperature'];
    }
    if (isset($options['max_tokens'])) {
      $payload['max_tokens'] = (int) $options['max_tokens'];
    }
    if (isset($options['response_format'])) {
      $payload['response_format'] = $options['response_format'];
    }
    if (isset($options['top_p'])) {
      $payload['top_p'] = (float) $options['top_p'];
    }

    return $this->executeWithRetry(function () use ($endpoint, $headers, $payload) {
      return $this->sendRequest('POST', $endpoint, $headers, $payload);
    });
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

    $api_url = rtrim($provider_config['api_url'] ?? 'https://api.openai.com/v1', '/');
    $endpoint = $api_url . '/embeddings';

    $headers = $this->buildAuthHeaders(
      $provider_config['auth_type'] ?? 'bearer',
      $this->resolveApiKey($provider, $provider_config)
    );

    $payload = [
      'model' => $options['model'] ?? $provider_config['embedding_model'] ?? 'text-embedding-3-large',
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

          sleep($waitTime);
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
   *
   * @return array
   *   The decoded JSON response.
   *
   * @throws \Exception
   *   When the request fails or returns an error status.
   */
  protected function sendRequest(string $method, string $url, array $headers, ?array $payload = NULL): array {
    $options = [
      'headers' => $headers,
      'http_errors' => FALSE,
      'timeout' => 120,
      'connect_timeout' => 30,
    ];

    if ($payload !== NULL) {
      $options['json'] = $payload;
    }

    try {
      $response = $this->httpClient->request($method, $url, $options);
    }
    catch (GuzzleException $e) {
      throw new \Exception('HTTP request failed: ' . $e->getMessage(), $e->getCode(), $e);
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
      throw new \Exception($errorMessage, $statusCode);
    }

    $decoded = json_decode($body, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \Exception('Failed to decode API response: ' . json_last_error_msg());
    }

    return $decoded;
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

    // Retryable HTTP status codes.
    $retryableCodes = [
      429, // Rate limited.
      500, // Internal server error.
      502, // Bad gateway.
      503, // Service unavailable.
      504, // Gateway timeout.
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
    $decoded = json_decode($body, TRUE);

    if (json_last_error() === JSON_ERROR_NONE && isset($decoded['error'])) {
      $error = $decoded['error'];
      $message = is_array($error) ? ($error['message'] ?? json_encode($error)) : $error;
      return "API error ({$statusCode}): {$message}";
    }

    return "API returned status code {$statusCode}: " . substr($body, 0, 500);
  }

  /**
   * Resolves the API key from environment variable or config.
   *
   * Priority: Environment variable > Config value
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
    // Environment variable names to check (in order of priority).
    $envVars = match ($provider) {
      'openai' => ['OPENAI_API_KEY', 'MARKASPOT_AI_OPENAI_KEY'],
      'azure' => ['AZURE_OPENAI_API_KEY', 'MARKASPOT_AI_AZURE_KEY'],
      'anthropic' => ['ANTHROPIC_API_KEY', 'MARKASPOT_AI_ANTHROPIC_KEY'],
      default => ['MARKASPOT_AI_' . strtoupper($provider) . '_KEY'],
    };

    // Check environment variables first.
    foreach ($envVars as $envVar) {
      $value = getenv($envVar);
      if (!empty($value)) {
        return $value;
      }
    }

    // Fall back to config value.
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
