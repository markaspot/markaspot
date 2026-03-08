<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Calculates a deterministic risk score for service requests.
 *
 * No AI call needed. Combines hazard level, category weight,
 * sentiment urgency, media presence, and duplicate count into
 * a normalized 0.0-1.0 score for prioritization.
 *
 * Formula:
 *   risk_score = normalize(
 *     hazard_level      x 0.40
 *   + category_weight   x 0.25
 *   + sentiment_urgency x 0.15
 *   + has_media         x 0.10
 *   + duplicate_factor  x 0.10
 *   )
 */
class RiskScoreCalculator {

  /**
   * Weight factors for each dimension.
   */
  protected const WEIGHT_HAZARD = 0.40;
  protected const WEIGHT_CATEGORY = 0.25;
  protected const WEIGHT_SENTIMENT = 0.15;
  protected const WEIGHT_MEDIA = 0.10;
  protected const WEIGHT_DUPLICATES = 0.10;

  /**
   * Sentiment urgency mapping.
   */
  protected const SENTIMENT_URGENCY = [
    'frustrated' => 1.0,
    'neutral' => 0.3,
    'positive' => 0.0,
  ];

  /**
   * Default category weights for common service categories.
   *
   * Can be overridden via config markaspot_ai.settings.
   */
  protected const DEFAULT_CATEGORY_WEIGHTS = [
    'Dangerous Structure' => 0.9,
    'Gefährliche Baustruktur' => 0.9,
    'Electrical Hazard' => 0.9,
    'Gas Leak' => 1.0,
    'Gasleck' => 1.0,
    'Open Manhole' => 0.9,
    'Offener Schacht' => 0.9,
    'Pothole' => 0.5,
    'Schlagloch' => 0.5,
    'Street Light' => 0.4,
    'Straßenbeleuchtung' => 0.4,
    'Flooding' => 0.6,
    'Überflutung' => 0.6,
    'Fallen Tree' => 0.7,
    'Umgestürzter Baum' => 0.7,
    'Graffiti' => 0.1,
    'Litter' => 0.1,
    'Müll' => 0.1,
    'Abandoned Vehicle' => 0.3,
    'Noise Complaint' => 0.2,
    'Lärmbelästigung' => 0.2,
  ];

  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a new RiskScoreCalculator.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Calculates the risk score for a service request.
   *
   * @param int $hazardLevel
   *   Hazard level (0-4).
   * @param string $categoryName
   *   The service category name.
   * @param string $sentiment
   *   Sentiment classification (frustrated/neutral/positive).
   * @param bool $hasMedia
   *   Whether the report includes photos.
   * @param int $duplicateCount
   *   Number of detected duplicate reports.
   *
   * @return float
   *   Normalized risk score between 0.0 and 1.0.
   */
  public function calculate(
    int $hazardLevel,
    string $categoryName,
    string $sentiment,
    bool $hasMedia,
    int $duplicateCount,
  ): float {
    // Normalize hazard level to 0-1 range (max is 4).
    $hazardNorm = min($hazardLevel, 4) / 4.0;

    // Get category weight (0-1).
    $categoryWeight = $this->getCategoryWeight($categoryName);

    // Sentiment urgency (0-1).
    $sentimentUrgency = self::SENTIMENT_URGENCY[$sentiment] ?? 0.3;

    // Media factor (0 or 1).
    $mediaFactor = $hasMedia ? 1.0 : 0.0;

    // Duplicate factor: saturates at 5 duplicates.
    $duplicateFactor = min($duplicateCount / 5.0, 1.0);

    // Weighted sum (already normalized, max = 1.0).
    $score = ($hazardNorm * self::WEIGHT_HAZARD)
           + ($categoryWeight * self::WEIGHT_CATEGORY)
           + ($sentimentUrgency * self::WEIGHT_SENTIMENT)
           + ($mediaFactor * self::WEIGHT_MEDIA)
           + ($duplicateFactor * self::WEIGHT_DUPLICATES);

    return max(0.0, min(1.0, $score));
  }

  /**
   * Gets the weight for a service category.
   *
   * Checks config overrides first, then falls back to defaults.
   *
   * @param string $categoryName
   *   The category name.
   *
   * @return float
   *   Weight between 0.0 and 1.0.
   */
  protected function getCategoryWeight(string $categoryName): float {
    // Check config overrides.
    $config = $this->configFactory->get('markaspot_ai.settings');
    $overrides = $config->get('risk_score.category_weights') ?? [];

    if (!empty($overrides) && isset($overrides[$categoryName])) {
      return max(0.0, min(1.0, (float) $overrides[$categoryName]));
    }

    // Fall back to defaults.
    if (isset(self::DEFAULT_CATEGORY_WEIGHTS[$categoryName])) {
      return self::DEFAULT_CATEGORY_WEIGHTS[$categoryName];
    }

    // Unknown category gets a middle-of-the-road weight.
    return 0.3;
  }

}
