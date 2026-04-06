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
 * HTTP client for the local NLP container.
 *
 * Communicates with a local Ollama-based NLP service for PII redaction
 * and sentiment analysis, running as a sidecar container.
 */
class NlpClientService {

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
   * Constructs a new NlpClientService.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('markaspot_ai');
  }

  /**
   * Redacts personally identifiable information from text.
   *
   * @param string $text
   *   The text to redact PII from.
   * @param string $language
   *   The language code (default: 'de').
   *
   * @return array
   *   An array with keys:
   *   - original: The original text.
   *   - redacted: The text with PII replaced.
   *   - entities_found: List of detected PII entities.
   */
  public function redactPii(string $text, string $language = 'de'): array {
    $fallback = [
      'original' => $text,
      'redacted' => $text,
      'entities_found' => [],
    ];

    try {
      $response = $this->request('POST', '/redact', [
        'text' => $text,
        'language' => $language,
      ], 300);

      $data = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($data) || !isset($data['redacted'])) {
        $this->logger->warning('NLP redact endpoint returned unexpected response format.');
        return $fallback;
      }

      return [
        'original' => $data['original'] ?? $text,
        'redacted' => $data['redacted'],
        'entities_found' => $data['entities_found'] ?? [],
      ];
    }
    catch (GuzzleException $e) {
      $this->logger->warning('NLP PII redaction failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return $fallback;
    }
  }

  /**
   * Analyzes the sentiment of text using the local NLP service.
   *
   * @param string $text
   *   The text to analyze.
   * @param string $language
   *   The language code (default: 'de').
   *
   * @return array
   *   An array with keys:
   *   - sentiment: The sentiment category (frustrated, neutral, positive).
   *   - score: Numeric sentiment score.
   *   - urgency: Urgency level from NLP analysis.
   *   - confidence: Confidence level (default 0.8).
   *   - reasoning: Explanation string.
   */
  public function analyzeSentiment(string $text, string $language = 'de'): array {
    try {
      $response = $this->request('POST', '/sentiment', [
        'text' => $text,
        'language' => $language,
      ], 300);

      $data = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($data)) {
        $this->logger->warning('NLP sentiment endpoint returned unexpected response format.');
        return $this->defaultSentiment();
      }

      $sentiment = $data['sentiment'] ?? 'neutral';
      // Map NLP "negative" to our internal "frustrated" category.
      $sentiment = match ($sentiment) {
        'negative' => 'frustrated',
        default => $sentiment,
      };

      $urgency = $data['urgency'] ?? 'normal';

      return [
        'sentiment' => $sentiment,
        'score' => $data['score'] ?? 0.0,
        'urgency' => $urgency,
        'confidence' => 0.8,
        'reasoning' => "Local NLP analysis (urgency: {$urgency})",
      ];
    }
    catch (GuzzleException $e) {
      $this->logger->warning('NLP sentiment analysis failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return $this->defaultSentiment();
    }
  }

  /**
   * Checks whether the NLP service is available and healthy.
   *
   * @return bool
   *   TRUE if the service responds with HTTP 200 and Ollama is available.
   */
  public function isAvailable(): bool {
    try {
      $url = $this->getServiceUrl();
      $options = [
        'timeout' => 5,
        'connect_timeout' => 5,
      ];

      $apiKey = $this->resolveApiKey();
      if (!empty($apiKey)) {
        $options['headers']['Authorization'] = "Bearer {$apiKey}";
      }

      $response = $this->httpClient->request('GET', $url . '/health', $options);

      if ($response->getStatusCode() !== 200) {
        return FALSE;
      }

      $data = json_decode((string) $response->getBody(), TRUE);
      return is_array($data) && ($data['ollama_available'] ?? FALSE) === TRUE;
    }
    catch (\Exception) {
      return FALSE;
    }
  }

  /**
   * Sends a request to the NLP service.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The endpoint path (e.g., '/redact').
   * @param array $payload
   *   The JSON payload.
   * @param int $timeout
   *   Request timeout in seconds.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The HTTP response.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function request(string $method, string $path, array $payload, int $timeout): \Psr\Http\Message\ResponseInterface {
    $url = $this->getServiceUrl() . $path;

    $options = [
      'json' => $payload,
      'timeout' => $timeout,
      'connect_timeout' => 10,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ];

    $apiKey = $this->resolveApiKey();
    if (!empty($apiKey)) {
      $options['headers']['Authorization'] = "Bearer {$apiKey}";
    }

    return $this->httpClient->request($method, $url, $options);
  }

  /**
   * Resolves the NLP API key from environment or config.
   *
   * Priority: Environment variable > Config value.
   *
   * @return string
   *   The resolved API key, or empty string if not found.
   */
  protected function resolveApiKey(): string {
    $envValue = getenv('MARKASPOT_NLP_API_KEY');
    if (!empty($envValue)) {
      return $envValue;
    }

    return $this->getConfig()->get('nlp_service.api_key') ?? '';
  }

  /**
   * Gets the NLP service base URL from config.
   *
   * @return string
   *   The service URL.
   */
  protected function getServiceUrl(): string {
    return $this->getConfig()->get('nlp_service.url') ?? 'http://markaspot-nlp:8100';
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

  /**
   * Returns default sentiment values for fallback.
   *
   * @return array
   *   Default sentiment array.
   */
  protected function defaultSentiment(): array {
    return [
      'sentiment' => 'neutral',
      'score' => 0.0,
      'urgency' => 'normal',
      'confidence' => 0.8,
      'reasoning' => 'Local NLP analysis (urgency: normal)',
    ];
  }

}
