<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\markaspot_dashboard\Service\MetricsCalculatorService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin controller for rendering dashboard KPIs as HTML.
 *
 * Provides an admin report page under /admin/reports/markaspot-dashboard
 * that displays KPI metrics calculated by MetricsCalculatorService.
 */
class DashboardAdminController extends ControllerBase {

  /**
   * The metrics calculator service.
   */
  protected MetricsCalculatorService $metricsCalculator;

  /**
   * Constructs a DashboardAdminController object.
   *
   * @param \Drupal\markaspot_dashboard\Service\MetricsCalculatorService $metrics_calculator
   *   The metrics calculator service.
   */
  public function __construct(
    MetricsCalculatorService $metrics_calculator,
  ) {
    $this->metricsCalculator = $metrics_calculator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_dashboard.metrics_calculator'),
    );
  }

  /**
   * Renders the dashboard admin report page.
   *
   * Parses filter query parameters, calculates KPIs, and returns
   * a render array with KPI cards and status distribution table.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array for the admin dashboard page.
   */
  public function report(Request $request): array {
    $filters = $this->parseFilters($request);
    $kpis = $this->metricsCalculator->calculateAllKpis($filters);

    $build = [
      '#attached' => [
        'library' => ['markaspot_dashboard/dashboard_admin'],
      ],
    ];

    // Filter form.
    $build['filters'] = $this->formBuilder()->getForm(
      'Drupal\markaspot_dashboard\Form\DashboardFilterForm'
    );

    // KPI cards.
    $build['kpis'] = $this->buildKpiCards($kpis);

    // Status distribution table.
    $build['status_distribution'] = $this->buildStatusTable($kpis);

    $build['#cache'] = [
      'max-age' => 0,
    ];

    return $build;
  }

  /**
   * Parses filter parameters from the request query string.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   Associative array of non-empty filter values.
   */
  protected function parseFilters(Request $request): array {
    $filters = [
      'start_date' => $request->query->get('start_date'),
      'end_date' => $request->query->get('end_date'),
      'jurisdiction_id' => $request->query->get('jurisdiction_id'),
      'category_id' => $request->query->get('category_id'),
    ];

    // Cast jurisdiction_id to int if numeric.
    if (is_numeric($filters['jurisdiction_id'])) {
      $filters['jurisdiction_id'] = (int) $filters['jurisdiction_id'];
    }

    return array_filter(
      $filters,
      fn($value) => $value !== NULL && $value !== '',
    );
  }

  /**
   * Builds the KPI cards render array.
   *
   * @param array $kpis
   *   The KPI data from MetricsCalculatorService.
   *
   * @return array
   *   A render array with KPI card containers.
   */
  protected function buildKpiCards(array $kpis): array {
    $forwarding = $kpis['forwarding_rate'] ?? [];
    $fcr = $kpis['fcr_rate'] ?? [];
    $processing = $kpis['avg_processing_time'] ?? [];
    $total = $kpis['total_requests'] ?? 0;

    $forwarding_rate = isset($forwarding['rate'])
      ? round((float) $forwarding['rate'], 1) . '%'
      : $this->t('N/A');

    $fcr_rate = isset($fcr['rate'])
      ? round((float) $fcr['rate'], 1) . '%'
      : $this->t('N/A');

    if (isset($processing['avg_days'])) {
      $days = round((float) $processing['avg_days'], 1);
      $hours = round((float) ($processing['avg_hours'] ?? 0), 1);
      $processing_display = $days >= 1
        ? $this->t('@days d', ['@days' => $days])
        : $this->t('@hours h', ['@hours' => $hours]);
    }
    else {
      $processing_display = $this->t('N/A');
    }

    $cards = [
      $this->buildCard((string) $total, $this->t('Total Requests')),
      $this->buildCard((string) $forwarding_rate, $this->t('Forwarding Rate')),
      $this->buildCard((string) $fcr_rate, $this->t('FCR Rate')),
      $this->buildCard((string) $processing_display, $this->t('Avg Processing Time')),
    ];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['dashboard-kpis']],
      'cards' => $cards,
    ];
  }

  /**
   * Builds a single KPI card render array.
   *
   * @param string $value
   *   The KPI value to display.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $label
   *   The label for the KPI card.
   *
   * @return array
   *   A render array for one KPI card.
   */
  protected function buildCard(string $value, $label): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['dashboard-kpi-card']],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['dashboard-kpi-value']],
        '#value' => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['dashboard-kpi-label']],
        '#value' => htmlspecialchars((string) $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
      ],
    ];
  }

  /**
   * Builds the status distribution table render array.
   *
   * @param array $kpis
   *   The KPI data from MetricsCalculatorService.
   *
   * @return array
   *   A render array for the status distribution table.
   */
  protected function buildStatusTable(array $kpis): array {
    $distribution = $kpis['status_distribution'] ?? [];

    if (empty($distribution)) {
      return [
        '#markup' => '<p>' . $this->t('No status data available.') . '</p>',
      ];
    }

    $header = [
      $this->t('Status'),
      $this->t('Count'),
    ];

    $rows = [];
    foreach ($distribution as $item) {
      $color = $item['color'] ?? '#999999';
      $status = $item['status'] ?? $this->t('Unknown');
      $count = $item['count'] ?? 0;

      $status_markup = '<span class="status-dot" style="background-color: '
        . htmlspecialchars($color, ENT_QUOTES, 'UTF-8')
        . ';"></span> '
        . htmlspecialchars((string) $status, ENT_QUOTES, 'UTF-8');

      $rows[] = [
        ['data' => ['#markup' => $status_markup]],
        $count,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No status data available.'),
      '#attributes' => ['class' => ['dashboard-status-table']],
    ];
  }

}
