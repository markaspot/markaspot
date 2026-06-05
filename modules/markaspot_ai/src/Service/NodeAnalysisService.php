<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates combined AI analysis for service request nodes.
 *
 * Collects full context (title, body, category, service attributes,
 * vision hazard) and makes one API call to get both sentiment and
 * text-based hazard assessment. Distributes results to their
 * respective storage: SentimentService for sentiment data,
 * field_hazard_level on the node for hazard (using max of vision
 * and text-based hazard).
 */
class NodeAnalysisService {

  /**
   * Hazard level constants matching CAP severity scale.
   */
  public const HAZARD_NONE = 0;
  public const HAZARD_LOW = 1;
  public const HAZARD_MEDIUM = 2;
  public const HAZARD_HIGH = 3;
  public const HAZARD_CRITICAL = 4;

  /**
   * Valid hazard categories (CAP standard).
   */
  public const VALID_HAZARD_CATEGORIES = [
    'Infra', 'Transport', 'Safety', 'Env',
    'Fire', 'Health', 'Geo', 'Met', 'Other',
  ];

  /**
   * The AI client service.
   *
   * @var \Drupal\markaspot_ai\Service\AiClientService
   */
  protected AiClientService $aiClient;

  /**
   * The sentiment analysis service.
   *
   * @var \Drupal\markaspot_ai\Service\SentimentService
   */
  protected SentimentService $sentimentService;

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
   * The logger.
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
   * The risk score calculator.
   *
   * @var \Drupal\markaspot_ai\Service\RiskScoreCalculator
   */
  protected RiskScoreCalculator $riskScoreCalculator;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Constructs a new NodeAnalysisService.
   */
  public function __construct(
    AiClientService $ai_client,
    SentimentService $sentiment_service,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    TokenTrackingService $token_tracking,
    RiskScoreCalculator $risk_score_calculator,
    Connection $database,
  ) {
    $this->aiClient = $ai_client;
    $this->sentimentService = $sentiment_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('markaspot_ai');
    $this->tokenTracking = $token_tracking;
    $this->riskScoreCalculator = $risk_score_calculator;
    $this->database = $database;
  }

  /**
   * Analyzes a service request node for sentiment and hazard.
   *
   * One API call returns both sentiment and hazard assessment.
   * Results are stored separately:
   * - Sentiment -> SentimentService (DB table + field_sentiment)
   * - Hazard -> field_hazard_level on node (max of vision and text)
   * - Risk score -> field_risk_score on node (deterministic calculation)
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param bool $force
   *   Force re-analysis even if results already exist.
   *
   * @return array|null
   *   Combined result with 'sentiment' and 'hazard' keys, or NULL on failure.
   */
  public function analyzeNode(NodeInterface $node, bool $force = FALSE): ?array {
    $nid = (int) $node->id();

    // GDPR safety: skip if AI processing is disabled for this jurisdiction.
    if (function_exists('_markaspot_ai_is_ai_enabled_for_node')
        && !_markaspot_ai_is_ai_enabled_for_node($node)) {
      $this->logger->debug('AI disabled for node @nid jurisdiction, skipping analysis.', [
        '@nid' => $nid,
      ]);
      return NULL;
    }

    // Check if full analysis already exists.
    // Sentiment alone is not enough: nodes analyzed before the refactoring
    // have sentiment but no hazard_category or risk_score. We check all three.
    if (!$force && $this->isFullyAnalyzed($node)) {
      $this->logger->debug('Analysis already exists for node @nid, skipping.', [
        '@nid' => $nid,
      ]);
      return NULL;
    }

    // Collect full context from the node.
    $context = $this->collectContext($node);

    if (empty(trim($context['text']))) {
      $this->logger->warning('Node @nid has no text content for analysis.', [
        '@nid' => $nid,
      ]);
      return NULL;
    }

    try {
      // One API call for both sentiment and hazard.
      $result = $this->analyzeWithContext($context);

      // Store sentiment via SentimentService (keeps existing storage).
      $this->sentimentService->storeSentiment(
        $nid,
        $result['sentiment']['sentiment'],
        $result['sentiment']['score'],
        $result['sentiment']['confidence'],
        $result['sentiment']['reasoning']
      );

      // Update field_sentiment on node.
      if ($node->hasField('field_sentiment')) {
        $node->set('field_sentiment', $result['sentiment']['sentiment']);
      }

      // Apply max(vision, text) hazard strategy.
      $textHazard = $result['hazard']['level'];
      $visionHazard = $context['vision_hazard_level'];
      $finalHazard = max($textHazard, $visionHazard);

      if ($node->hasField('field_hazard_level')) {
        $node->set('field_hazard_level', $finalHazard);
      }

      if ($node->hasField('field_hazard_category') && !empty($result['hazard']['category'])) {
        $node->set('field_hazard_category', $result['hazard']['category']);
      }

      // Calculate and store deterministic risk score.
      $duplicateCount = $this->getDuplicateCount($nid);
      $riskScore = $this->riskScoreCalculator->calculate(
        $finalHazard,
        $context['category_name'],
        $result['sentiment']['sentiment'],
        $context['has_media'],
        $duplicateCount
      );

      if ($node->hasField('field_risk_score')) {
        $node->set('field_risk_score', round($riskScore, 2));
      }

      $node->save();

      $this->logger->info('Analyzed node @nid: sentiment=@sentiment, hazard=@hazard (vision=@vision, text=@text), risk=@risk', [
        '@nid' => $nid,
        '@sentiment' => $result['sentiment']['sentiment'],
        '@hazard' => $finalHazard,
        '@vision' => $visionHazard,
        '@text' => $textHazard,
        '@risk' => round($riskScore, 2),
      ]);

      return [
        'sentiment' => $result['sentiment'],
        'hazard' => [
          'level' => $finalHazard,
          'text_level' => $textHazard,
          'vision_level' => $visionHazard,
          'category' => $result['hazard']['category'],
          'reasoning' => $result['hazard']['reasoning'],
        ],
        'risk_score' => round($riskScore, 2),
      ];

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to analyze node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Collects full context from a service request node.
   *
   * Gathers title, body, category name, service attributes,
   * existing vision hazard level, and media presence.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return array
   *   Context array with keys: text, category_name, attributes,
   *   vision_hazard_level, has_media.
   */
  protected function collectContext(NodeInterface $node): array {
    $textParts = [];
    $categoryName = '';
    $attributes = '';
    $visionHazardLevel = 0;
    $hasMedia = FALSE;

    // Title.
    $textParts[] = $node->getTitle();

    // Body.
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body = $node->get('body')->value;
      $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $textParts[] = $body;
    }

    // Category name and service definition.
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      $category = $node->get('field_category')->entity;
      if ($category) {
        $categoryName = $category->label();
        $textParts[] = 'Category: ' . $categoryName;

        // Service definition schema (what attributes are defined).
        if ($category->hasField('field_service_definition') && !$category->get('field_service_definition')->isEmpty()) {
          $schema = $category->get('field_service_definition')->value;
          $decoded = json_decode($schema, TRUE);
          if (json_last_error() === JSON_ERROR_NONE && !empty($decoded)) {
            $textParts[] = 'Service definition: ' . $this->summarizeServiceDefinition($decoded);
          }
        }
      }
    }

    // Citizen-filled attribute values.
    if ($node->hasField('field_request_attributes') && !$node->get('field_request_attributes')->isEmpty()) {
      $attrJson = $node->get('field_request_attributes')->value;
      $attrData = json_decode($attrJson, TRUE);
      if (json_last_error() === JSON_ERROR_NONE && !empty($attrData)) {
        $attributes = $this->summarizeAttributes($attrData);
        if (!empty($attributes)) {
          $textParts[] = 'Reported details: ' . $attributes;
        }
      }
    }

    // Vision hazard from media entities.
    if ($node->hasField('field_request_media') && !$node->get('field_request_media')->isEmpty()) {
      $hasMedia = TRUE;
      foreach ($node->get('field_request_media') as $mediaRef) {
        $media = $mediaRef->entity;
        if ($media && $media->hasField('field_ai_hazard_level') && !$media->get('field_ai_hazard_level')->isEmpty()) {
          $level = (int) $media->get('field_ai_hazard_level')->value;
          $visionHazardLevel = max($visionHazardLevel, $level);
        }
      }
    }

    return [
      'text' => implode("\n\n", array_filter($textParts)),
      'category_name' => $categoryName,
      'attributes' => $attributes,
      'vision_hazard_level' => $visionHazardLevel,
      'has_media' => $hasMedia,
    ];
  }

  /**
   * Makes combined AI call for sentiment and hazard assessment.
   *
   * @param array $context
   *   The collected node context.
   *
   * @return array
   *   Array with 'sentiment' and 'hazard' sub-arrays.
   *
   * @throws \Exception
   *   When the API request fails.
   */
  protected function analyzeWithContext(array $context): array {
    $visionNote = $context['vision_hazard_level'] > 0
      ? "Note: Photo analysis already assessed hazard level {$context['vision_hazard_level']}/4. Your text-based assessment is independent. The system will use the higher of both values."
      : "Note: No photo was provided. Your hazard assessment from text is the only source.";

    $systemPrompt = <<<PROMPT
You are an analyst for citizen service requests (311 reports) in a municipal reporting system.
You must analyze TWO things from the report text and context:

1. SENTIMENT: How does the citizen feel?
   - "frustrated": upset, angry, complaining, expressing dissatisfaction
   - "neutral": matter-of-fact, simply reporting
   - "positive": appreciative, thankful

2. HAZARD: How dangerous is the reported situation? (0-4 scale)
   - 0 (None): no safety concern (graffiti, litter, cosmetic issues)
   - 1 (Low): minor inconvenience, no injury risk (small pothole, faded sign)
   - 2 (Medium): moderate risk if unattended (broken glass, flooding)
   - 3 (High): significant danger, injury likely (deep pothole, exposed wiring, collapsed structure)
   - 4 (Critical): immediate life-threatening danger (open manhole, gas leak, structural collapse near pedestrians)

   Consider: the report text, the category, and any additional details provided.
   {$visionNote}

   Hazard categories (CAP standard): Infra, Transport, Safety, Env, Fire, Health, Geo, Met, Other

Respond with valid JSON only:
{
  "sentiment": {
    "sentiment": "frustrated" | "neutral" | "positive",
    "score": <float -1.0 to 1.0>,
    "confidence": <float 0 to 1>,
    "reasoning": "<brief, same language as input>"
  },
  "hazard": {
    "level": <int 0-4>,
    "category": "<CAP category>",
    "reasoning": "<brief, same language as input>"
  }
}
PROMPT;

    $userPrompt = "Analyze this citizen report:\n\n" . $context['text'];

    $model = $this->resolveChatModel();

    $response = $this->aiClient->chat(
      [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
      ],
      [
        'model' => $model,
        'temperature' => 0.3,
        'max_tokens' => 300,
        'response_format' => ['type' => 'json_object'],
      ]
    );

    // Track token usage.
    if (isset($response['usage'])) {
      $provider = $this->configFactory->get('markaspot_ai.settings')->get('default_provider') ?: 'openai';
      $this->tokenTracking->logUsage(
        $provider,
        $model,
        'node_analysis',
        $response['usage']['prompt_tokens'] ?? 0,
        $response['usage']['completion_tokens'] ?? 0
      );
    }

    $content = $response['choices'][0]['message']['content'] ?? '';
    $result = json_decode($content, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($result['sentiment']) || !isset($result['hazard'])) {
      $this->logger->warning('Failed to parse node analysis response: @content', [
        '@content' => $content,
      ]);
      return $this->getDefaultResult();
    }

    return $this->validateResult($result);
  }

  /**
   * Resolves the chat model via a three-tier fallback chain.
   *
   * Order of precedence:
   *   1. sentiment_analysis.model (explicit override).
   *   2. providers.{default_provider}.chat_model (per-provider default).
   *   3. AiClientService::DEFAULT_CHAT_MODEL (hardcoded safety net, valid on
   *      OpenAI + Azure tenants and matches the install-config default).
   *
   * @return string
   *   The resolved model identifier.
   */
  protected function resolveChatModel(): string {
    $config = $this->configFactory->get('markaspot_ai.settings');
    $provider = $config->get('default_provider') ?: 'openai';
    $providerChatModel = $config->get("providers.{$provider}.chat_model") ?: AiClientService::DEFAULT_CHAT_MODEL;
    return $config->get('sentiment_analysis.model') ?: $providerChatModel;
  }

  /**
   * Validates and clamps the AI response values.
   *
   * @param array $result
   *   Raw AI response.
   *
   * @return array
   *   Validated result.
   */
  protected function validateResult(array $result): array {
    // Validate sentiment.
    $sentiment = $result['sentiment']['sentiment'] ?? SentimentService::SENTIMENT_NEUTRAL;
    if (!in_array($sentiment, SentimentService::VALID_SENTIMENTS, TRUE)) {
      $sentiment = SentimentService::SENTIMENT_NEUTRAL;
    }

    $score = max(-1.0, min(1.0, (float) ($result['sentiment']['score'] ?? 0.0)));
    $confidence = max(0.0, min(1.0, (float) ($result['sentiment']['confidence'] ?? 0.5)));

    // Validate hazard.
    $hazardLevel = max(self::HAZARD_NONE, min(self::HAZARD_CRITICAL, (int) ($result['hazard']['level'] ?? 0)));
    $hazardCategory = $result['hazard']['category'] ?? 'Other';
    if (!in_array($hazardCategory, self::VALID_HAZARD_CATEGORIES, TRUE)) {
      $hazardCategory = 'Other';
    }

    return [
      'sentiment' => [
        'sentiment' => $sentiment,
        'score' => $score,
        'confidence' => $confidence,
        'reasoning' => $result['sentiment']['reasoning'] ?? '',
      ],
      'hazard' => [
        'level' => $hazardLevel,
        'category' => $hazardCategory,
        'reasoning' => $result['hazard']['reasoning'] ?? '',
      ],
    ];
  }

  /**
   * Returns default results when analysis fails.
   */
  protected function getDefaultResult(): array {
    return [
      'sentiment' => [
        'sentiment' => SentimentService::SENTIMENT_NEUTRAL,
        'score' => 0.0,
        'confidence' => 0.0,
        'reasoning' => 'Unable to analyze.',
      ],
      'hazard' => [
        'level' => self::HAZARD_NONE,
        'category' => 'Other',
        'reasoning' => 'Unable to analyze.',
      ],
    ];
  }

  /**
   * Summarizes service definition attributes into a readable string.
   *
   * @param array $definition
   *   Decoded service definition JSON.
   *
   * @return string
   *   Human-readable summary.
   */
  protected function summarizeServiceDefinition(array $definition): string {
    $parts = [];
    foreach ($definition as $attr) {
      $desc = $attr['description'] ?? $attr['variable_name'] ?? '';
      if (!empty($desc)) {
        $parts[] = $desc;
        // Include values for single/multi-value list attributes.
        if (!empty($attr['values'])) {
          $valueLabels = array_map(function ($v) {
            return $v['name'] ?? $v['key'] ?? '';
          }, $attr['values']);
          $valueLabels = array_filter($valueLabels);
          if (!empty($valueLabels)) {
            $parts[array_key_last($parts)] .= ' (' . implode(', ', $valueLabels) . ')';
          }
        }
      }
    }
    return implode('; ', $parts);
  }

  /**
   * Summarizes citizen-filled attribute values.
   *
   * @param array $attributes
   *   Decoded attribute values.
   *
   * @return string
   *   Human-readable summary.
   */
  protected function summarizeAttributes(array $attributes): string {
    $parts = [];
    foreach ($attributes as $key => $value) {
      if (is_array($value)) {
        $value = implode(', ', $value);
      }
      if (!empty($value)) {
        $parts[] = "$key: $value";
      }
    }
    return implode('; ', $parts);
  }

  /**
   * Checks if a node has been fully analyzed (sentiment + hazard + risk).
   *
   * Nodes analyzed before the refactoring only have sentiment data.
   * This method returns TRUE only when all three dimensions are populated,
   * ensuring backfill of hazard and risk score on previously analyzed nodes.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return bool
   *   TRUE if all analysis fields are populated.
   */
  protected function isFullyAnalyzed(NodeInterface $node): bool {
    $nid = (int) $node->id();

    // Sentiment must exist in the DB table.
    if ($this->sentimentService->getSentiment($nid) === NULL) {
      return FALSE;
    }

    // Risk score must be set on the node.
    if ($node->hasField('field_risk_score')
        && ($node->get('field_risk_score')->isEmpty() || $node->get('field_risk_score')->value === NULL)) {
      return FALSE;
    }

    // Hazard category must be set (hazard_level 0 is valid, but category
    // should be present if analyzed by NodeAnalysisService).
    if ($node->hasField('field_hazard_category')
        && $node->get('field_hazard_category')->isEmpty()) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Gets the duplicate count for a node.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return int
   *   Number of detected duplicates.
   */
  protected function getDuplicateCount(int $nid): int {
    try {
      $count = $this->database->select('markaspot_ai_duplicate_matches', 'd')
        ->condition('d.source_nid', $nid)
        ->countQuery()
        ->execute()
        ->fetchField();
      return (int) $count;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

}
