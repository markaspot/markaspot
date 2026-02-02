<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for analyzing sentiment in service requests.
 *
 * Uses GPT-4o-mini to classify the emotional tone of citizen reports
 * as frustrated, neutral, or positive. This helps prioritize responses
 * to upset citizens and identify satisfaction patterns.
 */
class SentimentService {

  /**
   * Sentiment categories.
   */
  public const SENTIMENT_FRUSTRATED = 'frustrated';
  public const SENTIMENT_NEUTRAL = 'neutral';
  public const SENTIMENT_POSITIVE = 'positive';

  /**
   * Valid sentiment values.
   */
  public const VALID_SENTIMENTS = [
    self::SENTIMENT_FRUSTRATED,
    self::SENTIMENT_NEUTRAL,
    self::SENTIMENT_POSITIVE,
  ];

  /**
   * The AI client service.
   *
   * @var \Drupal\markaspot_ai\Service\AiClientService
   */
  protected AiClientService $aiClient;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

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
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Constructs a new SentimentService.
   *
   * @param \Drupal\markaspot_ai\Service\AiClientService $ai_client
   *   The AI client service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\markaspot_ai\Service\TokenTrackingService $token_tracking
   *   The token tracking service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    AiClientService $ai_client,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    TokenTrackingService $token_tracking,
    TimeInterface $time,
    ModuleHandlerInterface $module_handler,
  ) {
    $this->aiClient = $ai_client;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('markaspot_ai');
    $this->tokenTracking = $token_tracking;
    $this->time = $time;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Analyzes sentiment of text using GPT-4o-mini.
   *
   * @param string $text
   *   The text to analyze.
   *
   * @return array
   *   Array containing:
   *   - 'sentiment': One of 'frustrated', 'neutral', 'positive'.
   *   - 'score': Float from -1.0 (very frustrated) to 1.0 (very positive).
   *   - 'confidence': Float from 0 to 1 indicating confidence level.
   *   - 'reasoning': Brief explanation of the classification.
   *
   * @throws \Exception
   *   When the API request fails.
   */
  public function analyzeSentiment(string $text): array {
    if (empty(trim($text))) {
      return [
        'sentiment' => self::SENTIMENT_NEUTRAL,
        'score' => 0.0,
        'confidence' => 1.0,
        'reasoning' => 'Empty text provided.',
      ];
    }

    $systemPrompt = <<<PROMPT
You are a sentiment analysis expert for citizen service requests (311 reports).
Analyze the emotional tone of the text and classify it as:
- "frustrated": The citizen is upset, angry, complaining, or expressing dissatisfaction
- "neutral": The citizen is matter-of-fact, simply reporting an issue without strong emotion
- "positive": The citizen is appreciative, thankful, or expressing satisfaction

Consider:
- Exclamation marks, capital letters, and strong language indicate frustration
- Repeated complaints about the same issue indicate frustration
- Simple factual reports are neutral
- Thank you messages or appreciation are positive

Respond with valid JSON only:
{
  "sentiment": "frustrated" | "neutral" | "positive",
  "score": <float from -1.0 (very frustrated) to 1.0 (very positive)>,
  "confidence": <float from 0 to 1>,
  "reasoning": "<brief explanation in same language as input>"
}
PROMPT;

    $userPrompt = "Analyze the sentiment of this citizen report:\n\n" . $text;

    // Get model from config.
    $config = $this->configFactory->get('markaspot_ai.settings');
    $model = $config->get('sentiment_analysis.model') ?: 'gpt-4o-mini';

    try {
      $response = $this->aiClient->chat(
        [
          ['role' => 'system', 'content' => $systemPrompt],
          ['role' => 'user', 'content' => $userPrompt],
        ],
        [
          'model' => $model,
          'temperature' => 0.3,
          'max_tokens' => 150,
          'response_format' => ['type' => 'json_object'],
        ]
      );

      // Track token usage.
      if (isset($response['usage'])) {
        $this->tokenTracking->logUsage(
          'openai',
          $model,
          'sentiment',
          $response['usage']['prompt_tokens'] ?? 0,
          $response['usage']['completion_tokens'] ?? 0
        );
      }

      // Parse response.
      $content = $response['choices'][0]['message']['content'] ?? '';
      $result = json_decode($content, TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->warning('Failed to parse sentiment response: @content', [
          '@content' => $content,
        ]);
        return $this->getDefaultResult();
      }

      // Validate sentiment value.
      $sentiment = $result['sentiment'] ?? self::SENTIMENT_NEUTRAL;
      if (!in_array($sentiment, self::VALID_SENTIMENTS, TRUE)) {
        $sentiment = self::SENTIMENT_NEUTRAL;
      }

      // Clamp score to valid range (-1.0 to 1.0).
      $score = (float) ($result['score'] ?? 0.0);
      $score = max(-1.0, min(1.0, $score));

      // Clamp confidence to valid range (0 to 1).
      $confidence = (float) ($result['confidence'] ?? 0.5);
      $confidence = max(0.0, min(1.0, $confidence));

      return [
        'sentiment' => $sentiment,
        'score' => $score,
        'confidence' => $confidence,
        'reasoning' => $result['reasoning'] ?? '',
      ];

    }
    catch (\Exception $e) {
      $this->logger->error('Sentiment analysis failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Analyzes and stores sentiment for a service request node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param bool $force
   *   Force re-analysis even if sentiment already exists.
   *
   * @return array|null
   *   The sentiment result or NULL on failure.
   */
  public function analyzeNode(NodeInterface $node, bool $force = FALSE): ?array {
    $nid = (int) $node->id();

    // Check if sentiment already exists.
    if (!$force && $this->getSentiment($nid) !== NULL) {
      $this->logger->debug('Sentiment already exists for node @nid, skipping.', [
        '@nid' => $nid,
      ]);
      return $this->getSentiment($nid);
    }

    // Build text from node content.
    $textParts = [];

    // Add title.
    $textParts[] = $node->getTitle();

    // Add body content.
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body = $node->get('body')->value;
      $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $textParts[] = $body;
    }

    // Add citizen feedback if markaspot_feedback module is enabled.
    if ($this->moduleHandler->moduleExists('markaspot_feedback')
        && $node->hasField('field_feedback')
        && !$node->get('field_feedback')->isEmpty()) {
      $feedback = $node->get('field_feedback')->value;
      $feedback = html_entity_decode(strip_tags($feedback), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $textParts[] = "Citizen feedback: " . $feedback;
    }

    $text = implode("\n\n", array_filter($textParts));

    if (empty(trim($text))) {
      $this->logger->warning('Node @nid has no text content for sentiment analysis.', [
        '@nid' => $nid,
      ]);
      return NULL;
    }

    try {
      $result = $this->analyzeSentiment($text);

      // Store the result in database.
      $this->storeSentiment(
        $nid,
        $result['sentiment'],
        $result['score'],
        $result['confidence'],
        $result['reasoning']
      );

      // Update the node field if it exists.
      if ($node->hasField('field_sentiment')) {
        $node->set('field_sentiment', $result['sentiment']);
        $node->save();
      }

      $this->logger->info('Analyzed sentiment for node @nid: @sentiment (score: @score)', [
        '@nid' => $nid,
        '@sentiment' => $result['sentiment'],
        '@score' => $result['score'],
      ]);

      return $result;

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to analyze sentiment for node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Stores sentiment analysis result in the database.
   *
   * @param int $nid
   *   The node ID.
   * @param string $sentiment
   *   The sentiment category.
   * @param float $score
   *   The sentiment score (-1.0 to 1.0).
   * @param float $confidence
   *   The confidence level (0 to 1).
   * @param string $reasoning
   *   The reasoning for the classification.
   */
  public function storeSentiment(int $nid, string $sentiment, float $score, float $confidence, string $reasoning = ''): void {
    $this->database->merge('markaspot_ai_sentiment')
      ->keys(['entity_id' => $nid])
      ->fields([
        'entity_type' => 'node',
        'sentiment' => $sentiment,
        'score' => $score,
        'confidence' => $confidence,
        'reasoning' => $reasoning,
        'analyzed_at' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Gets stored sentiment for a node.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return array|null
   *   The sentiment data or NULL if not found.
   */
  public function getSentiment(int $nid): ?array {
    $result = $this->database->select('markaspot_ai_sentiment', 's')
      ->fields('s')
      ->condition('entity_id', $nid)
      ->condition('entity_type', 'node')
      ->execute()
      ->fetchAssoc();

    if (!$result) {
      return NULL;
    }

    return [
      'sentiment' => $result['sentiment'],
      'score' => (float) $result['score'],
      'confidence' => (float) $result['confidence'],
      'reasoning' => $result['reasoning'] ?? '',
      'analyzed_at' => (int) $result['analyzed_at'],
    ];
  }

  /**
   * Gets sentiment statistics.
   *
   * @param array $options
   *   Optional filters:
   *   - 'days': Number of days to look back (default: 30).
   *
   * @return array
   *   Statistics including counts by sentiment.
   */
  public function getStatistics(array $options = []): array {
    $days = $options['days'] ?? 30;
    $cutoff = $this->time->getRequestTime() - ($days * 86400);

    $query = $this->database->select('markaspot_ai_sentiment', 's')
      ->condition('s.analyzed_at', $cutoff, '>=');

    $query->addExpression('sentiment');
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('sentiment');

    $results = $query->execute()->fetchAllKeyed();

    return [
      'frustrated' => (int) ($results['frustrated'] ?? 0),
      'neutral' => (int) ($results['neutral'] ?? 0),
      'positive' => (int) ($results['positive'] ?? 0),
      'total' => array_sum(array_map('intval', $results)),
      'period_days' => $days,
    ];
  }

  /**
   * Gets default result when analysis cannot be performed.
   *
   * @return array
   *   Default sentiment result.
   */
  protected function getDefaultResult(): array {
    return [
      'sentiment' => self::SENTIMENT_NEUTRAL,
      'score' => 0.0,
      'confidence' => 0.0,
      'reasoning' => 'Unable to analyze sentiment.',
    ];
  }

}
