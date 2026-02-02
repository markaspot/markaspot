<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for AI usage statistics API endpoints.
 *
 * Provides REST API endpoints for retrieving token usage statistics
 * and cost monitoring data.
 */
class UsageController extends ControllerBase {

  /**
   * The token tracking service.
   *
   * @var \Drupal\markaspot_ai\Service\TokenTrackingService
   */
  protected TokenTrackingService $tokenTrackingService;

  /**
   * Constructs a UsageController object.
   *
   * @param \Drupal\markaspot_ai\Service\TokenTrackingService $token_tracking_service
   *   The token tracking service.
   */
  public function __construct(TokenTrackingService $token_tracking_service) {
    $this->tokenTrackingService = $token_tracking_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_ai.token_tracking')
    );
  }

  /**
   * Gets AI usage statistics.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   JSON response containing usage statistics.
   */
  public function getUsage(Request $request): CacheableJsonResponse {
    // Get query parameters.
    $period = (int) $request->query->get('period', 30);
    $provider = $request->query->get('provider');

    // Cap period to prevent abuse.
    $period = min($period, 365);

    // Get configuration.
    $config = $this->config('markaspot_ai.settings');
    $trackingEnabled = (bool) $config->get('token_tracking.enabled');
    $dailyLimit = (int) $config->get('token_tracking.daily_limit');

    // Get usage data.
    $dailyUsage = $this->tokenTrackingService->getDailyUsage($provider);
    $totalUsage = $this->tokenTrackingService->getTotalUsage($period);
    $remainingTokens = $this->tokenTrackingService->getRemainingTokens();

    // Calculate usage percentage if limit is set.
    $usagePercent = NULL;
    if ($dailyLimit > 0) {
      $usagePercent = round(($dailyUsage['total_tokens'] / $dailyLimit) * 100, 2);
    }

    $response = new CacheableJsonResponse([
      'tracking_enabled' => $trackingEnabled,
      'daily' => [
        'input_tokens' => $dailyUsage['total_input_tokens'],
        'output_tokens' => $dailyUsage['total_output_tokens'],
        'total_tokens' => $dailyUsage['total_tokens'],
        'request_count' => $dailyUsage['request_count'],
        'limit' => $dailyLimit > 0 ? $dailyLimit : NULL,
        'remaining' => $remainingTokens >= 0 ? $remainingTokens : NULL,
        'usage_percent' => $usagePercent,
        'by_model' => $dailyUsage['by_model'],
        'by_operation' => $dailyUsage['by_operation'],
      ],
      'period' => [
        'days' => $period,
        'input_tokens' => $totalUsage['total_input_tokens'],
        'output_tokens' => $totalUsage['total_output_tokens'],
        'total_tokens' => $totalUsage['total_tokens'],
        'request_count' => $totalUsage['request_count'],
        'by_provider' => $totalUsage['by_provider'],
        'by_model' => $totalUsage['by_model'],
        'daily_breakdown' => $totalUsage['daily_breakdown'],
      ],
      'provider_filter' => $provider,
    ]);

    // Add cache metadata.
    $cacheMetadata = new CacheableMetadata();
    $cacheMetadata->setCacheMaxAge(300); // Cache for 5 minutes.
    $cacheMetadata->addCacheTags(['markaspot_ai:usage']);
    $cacheMetadata->addCacheContexts(['url.query_args']);

    $response->addCacheableDependency($cacheMetadata);

    return $response;
  }

  /**
   * Gets a summary of AI usage for the dashboard.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   JSON response with usage summary.
   */
  public function getSummary(): CacheableJsonResponse {
    $config = $this->config('markaspot_ai.settings');
    $trackingEnabled = (bool) $config->get('token_tracking.enabled');
    $dailyLimit = (int) $config->get('token_tracking.daily_limit');

    $dailyUsage = $this->tokenTrackingService->getDailyUsage();
    $remainingTokens = $this->tokenTrackingService->getRemainingTokens();

    // Calculate status.
    $status = 'ok';
    $usagePercent = 0;
    if ($dailyLimit > 0) {
      $usagePercent = ($dailyUsage['total_tokens'] / $dailyLimit) * 100;
      if ($usagePercent >= 100) {
        $status = 'limit_exceeded';
      }
      elseif ($usagePercent >= 90) {
        $status = 'warning';
      }
      elseif ($usagePercent >= 75) {
        $status = 'approaching_limit';
      }
    }

    $response = new CacheableJsonResponse([
      'status' => $status,
      'tracking_enabled' => $trackingEnabled,
      'today' => [
        'tokens_used' => $dailyUsage['total_tokens'],
        'tokens_remaining' => $remainingTokens >= 0 ? $remainingTokens : NULL,
        'tokens_limit' => $dailyLimit > 0 ? $dailyLimit : NULL,
        'usage_percent' => round($usagePercent, 1),
        'requests' => $dailyUsage['request_count'],
      ],
    ]);

    // Short cache for dashboard.
    $cacheMetadata = new CacheableMetadata();
    $cacheMetadata->setCacheMaxAge(60);
    $cacheMetadata->addCacheTags(['markaspot_ai:usage']);

    $response->addCacheableDependency($cacheMetadata);

    return $response;
  }

}
