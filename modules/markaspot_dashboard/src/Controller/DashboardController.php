<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\markaspot_dashboard\Service\MetricsCalculatorService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for Dashboard KPI endpoints.
 *
 * Provides REST endpoints for dashboard metrics including:
 * - Forwarding rate
 * - First-Contact-Resolution (FCR) rate
 * - Average processing time
 * - Status distribution
 * - Time series volume (created/closed counts over time)
 * - Time series processing (processing time trends)
 * - Forwarding details (breakdown by organization and category)
 */
class DashboardController extends ControllerBase {

  /**
   * The metrics calculator service.
   */
  protected MetricsCalculatorService $metricsCalculator;

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a DashboardController object.
   *
   * @param \Drupal\markaspot_dashboard\Service\MetricsCalculatorService $metrics_calculator
   *   The metrics calculator service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    MetricsCalculatorService $metrics_calculator,
    RequestStack $request_stack,
  ) {
    $this->metricsCalculator = $metrics_calculator;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_dashboard.metrics_calculator'),
      $container->get('request_stack')
    );
  }

  /**
   * Returns all KPI metrics.
   *
   * Supports query parameters:
   * - start_date: Start date filter (Y-m-d or UNIX timestamp)
   * - end_date: End date filter (Y-m-d or UNIX timestamp)
   * - jurisdiction_id: Filter by jurisdiction group ID
   * - organization_id: Filter by organization group ID
   * - category_id: Filter by category taxonomy term ID
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   JSON response containing all KPI metrics.
   */
  public function getKpis(): CacheableJsonResponse {
    $request = $this->requestStack->getCurrentRequest();

    // Extract filter parameters from query string.
    $filters = [
      'start_date' => $request->query->get('start_date'),
      'end_date' => $request->query->get('end_date'),
      'jurisdiction_id' => $request->query->get('jurisdiction_id'),
      'organization_id' => $request->query->get('organization_id'),
      'category_id' => $request->query->get('category_id'),
      'status_id' => $request->query->get('status_id'),
    ];

    // Remove empty filters.
    $filters = array_filter($filters, fn($value) => $value !== NULL && $value !== '');

    // Calculate all KPIs.
    $kpis = $this->metricsCalculator->calculateAllKpis($filters);

    // Build response with cache metadata.
    $response = new CacheableJsonResponse($kpis);

    // Create cache metadata.
    $cache_metadata = new CacheableMetadata();

    // Cache tags for invalidation when service requests change.
    $cache_metadata->addCacheTags([
      'node_list:service_request',
      'taxonomy_term_list:service_status',
      'taxonomy_term_list:service_category',
    ]);

    // Cache context based on query parameters.
    $cache_metadata->addCacheContexts([
      'url.query_args:start_date',
      'url.query_args:end_date',
      'url.query_args:jurisdiction_id',
      'url.query_args:organization_id',
      'url.query_args:category_id',
      'url.query_args:status_id',
    ]);

    // Set max age (5 minutes for dashboard data).
    $cache_metadata->setCacheMaxAge(300);

    $response->addCacheableDependency($cache_metadata);

    return $response;
  }

  /**
   * Returns time series volume data (created/closed counts over time).
   *
   * Supports query parameters:
   * - start_date: Start date filter (Y-m-d or UNIX timestamp)
   * - end_date: End date filter (Y-m-d or UNIX timestamp)
   * - granularity: Time grouping (day|week|month), defaults to 'day'
   * - jurisdiction_id: Filter by jurisdiction group ID
   * - category_id: Filter by category taxonomy term ID
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   JSON response containing time series volume data.
   */
  public function getTimeSeriesVolume(): CacheableJsonResponse {
    $request = $this->requestStack->getCurrentRequest();

    $filters = [
      'start_date' => $request->query->get('start_date'),
      'end_date' => $request->query->get('end_date'),
      'granularity' => $request->query->get('granularity', 'day'),
      'jurisdiction_id' => $request->query->get('jurisdiction_id'),
      'category_id' => $request->query->get('category_id'),
      'status_id' => $request->query->get('status_id'),
    ];

    // Validate granularity.
    if (!in_array($filters['granularity'], ['day', 'week', 'month'], TRUE)) {
      $filters['granularity'] = 'day';
    }

    $filters = array_filter($filters, fn($value) => $value !== NULL && $value !== '');

    $data = $this->metricsCalculator->calculateTimeSeriesVolume($filters);

    $response = new CacheableJsonResponse($data);
    $cache_metadata = $this->buildTimeSeriesCacheMetadata($filters);
    $response->addCacheableDependency($cache_metadata);

    return $response;
  }

  /**
   * Returns time series processing data (processing time trends).
   *
   * Supports query parameters:
   * - start_date: Start date filter (Y-m-d or UNIX timestamp)
   * - end_date: End date filter (Y-m-d or UNIX timestamp)
   * - granularity: Time grouping (day|week|month), defaults to 'day'
   * - jurisdiction_id: Filter by jurisdiction group ID
   * - category_id: Filter by category taxonomy term ID
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   JSON response containing time series processing data.
   */
  public function getTimeSeriesProcessing(): CacheableJsonResponse {
    $request = $this->requestStack->getCurrentRequest();

    $filters = [
      'start_date' => $request->query->get('start_date'),
      'end_date' => $request->query->get('end_date'),
      'granularity' => $request->query->get('granularity', 'day'),
      'jurisdiction_id' => $request->query->get('jurisdiction_id'),
      'category_id' => $request->query->get('category_id'),
      'status_id' => $request->query->get('status_id'),
    ];

    // Validate granularity.
    if (!in_array($filters['granularity'], ['day', 'week', 'month'], TRUE)) {
      $filters['granularity'] = 'day';
    }

    $filters = array_filter($filters, fn($value) => $value !== NULL && $value !== '');

    $data = $this->metricsCalculator->calculateTimeSeriesProcessing($filters);

    $response = new CacheableJsonResponse($data);
    $cache_metadata = $this->buildTimeSeriesCacheMetadata($filters);
    $response->addCacheableDependency($cache_metadata);

    return $response;
  }

  /**
   * Returns forwarding details breakdown.
   *
   * Supports query parameters:
   * - start_date: Start date filter (Y-m-d or UNIX timestamp)
   * - end_date: End date filter (Y-m-d or UNIX timestamp)
   * - jurisdiction_id: Filter by jurisdiction group ID
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   JSON response containing forwarding breakdown data.
   */
  public function getForwardingDetails(): CacheableJsonResponse {
    $request = $this->requestStack->getCurrentRequest();

    $filters = [
      'start_date' => $request->query->get('start_date'),
      'end_date' => $request->query->get('end_date'),
      'jurisdiction_id' => $request->query->get('jurisdiction_id'),
    ];

    $filters = array_filter($filters, fn($value) => $value !== NULL && $value !== '');

    $data = $this->metricsCalculator->calculateForwardingDetails($filters);

    $response = new CacheableJsonResponse($data);

    $cache_metadata = new CacheableMetadata();
    $cache_metadata->addCacheTags([
      'node_list:service_request',
      'taxonomy_term_list:service_category',
      'group_list',
    ]);
    $cache_metadata->addCacheContexts([
      'url.query_args:start_date',
      'url.query_args:end_date',
      'url.query_args:jurisdiction_id',
    ]);
    $cache_metadata->setCacheMaxAge(300);

    $response->addCacheableDependency($cache_metadata);

    return $response;
  }

  /**
   * Builds cache metadata for time series endpoints.
   *
   * @param array $filters
   *   The filter parameters.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   Cache metadata object.
   */
  protected function buildTimeSeriesCacheMetadata(array $filters): CacheableMetadata {
    $cache_metadata = new CacheableMetadata();

    $cache_metadata->addCacheTags([
      'node_list:service_request',
      'taxonomy_term_list:service_status',
      'taxonomy_term_list:service_category',
    ]);

    $cache_metadata->addCacheContexts([
      'url.query_args:start_date',
      'url.query_args:end_date',
      'url.query_args:granularity',
      'url.query_args:jurisdiction_id',
      'url.query_args:category_id',
      'url.query_args:status_id',
    ]);

    $cache_metadata->setCacheMaxAge(300);

    return $cache_metadata;
  }

  /**
   * Returns hazard statistics for service requests.
   *
   * Provides hazard level distribution, category breakdown, and counts
   * for critical and high-priority hazards detected by AI vision analysis.
   *
   * Supports query parameters:
   * - start_date: Start date filter (Y-m-d or UNIX timestamp)
   * - end_date: End date filter (Y-m-d or UNIX timestamp)
   * - jurisdiction_id: Filter by jurisdiction group ID
   * - organization_id: Filter by organization group ID
   * - category_id: Filter by category taxonomy term ID
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   JSON response containing hazard statistics.
   */
  public function getHazardStatistics(): CacheableJsonResponse {
    $request = $this->requestStack->getCurrentRequest();

    $filters = [
      'start_date' => $request->query->get('start_date'),
      'end_date' => $request->query->get('end_date'),
      'jurisdiction_id' => $request->query->get('jurisdiction_id'),
      'organization_id' => $request->query->get('organization_id'),
      'category_id' => $request->query->get('category_id'),
      'status_id' => $request->query->get('status_id'),
    ];

    // Remove empty filters.
    $filters = array_filter($filters, fn($value) => $value !== NULL && $value !== '');

    // Calculate hazard statistics.
    $data = $this->metricsCalculator->calculateHazardStatistics($filters);

    // Build response with cache metadata.
    $response = new CacheableJsonResponse($data);

    $cache_metadata = new CacheableMetadata();
    $cache_metadata->addCacheTags([
      'node_list:service_request',
      'media_list',
    ]);
    $cache_metadata->addCacheContexts([
      'url.query_args:start_date',
      'url.query_args:end_date',
      'url.query_args:jurisdiction_id',
      'url.query_args:organization_id',
      'url.query_args:category_id',
      'url.query_args:status_id',
    ]);
    $cache_metadata->setCacheMaxAge(300);

    $response->addCacheableDependency($cache_metadata);

    return $response;
  }

}
