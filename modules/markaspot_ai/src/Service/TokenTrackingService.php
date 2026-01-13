<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for tracking AI API token usage and costs.
 *
 * This service logs all API calls to enable cost monitoring, usage analytics,
 * and daily limit enforcement. Usage data is stored in a database table for
 * reporting and auditing purposes.
 */
class TokenTrackingService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

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
   * Constructs a new TokenTrackingService.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    Connection $database,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('markaspot_ai');
  }

  /**
   * Logs API usage for cost monitoring.
   *
   * @param string $provider
   *   The AI provider name (e.g., 'openai', 'azure').
   * @param string $model
   *   The model name used (e.g., 'gpt-4o', 'text-embedding-3-large').
   * @param string $operation
   *   The operation type: 'chat', 'embed', or 'vision'.
   * @param int $inputTokens
   *   Number of input/prompt tokens used.
   * @param int $outputTokens
   *   Number of output/completion tokens used.
   * @param string|null $entityType
   *   Optional entity type this usage relates to (e.g., 'node').
   * @param int|null $entityId
   *   Optional entity ID this usage relates to.
   */
  public function logUsage(
    string $provider,
    string $model,
    string $operation,
    int $inputTokens,
    int $outputTokens,
    ?string $entityType = NULL,
    ?int $entityId = NULL,
  ): void {
    $config = $this->configFactory->get('markaspot_ai.settings');

    // Check if token tracking is enabled.
    if (!$config->get('token_tracking.enabled')) {
      return;
    }

    try {
      $this->database->insert('markaspot_ai_token_usage')
        ->fields([
          'provider' => $provider,
          'model' => $model,
          'operation' => $operation,
          'input_tokens' => $inputTokens,
          'output_tokens' => $outputTokens,
          'entity_type' => $entityType,
          'entity_id' => $entityId,
          'created' => \Drupal::time()->getRequestTime(),
        ])
        ->execute();

      $this->logger->debug('Logged API usage: @provider/@model (@operation) - @input input, @output output tokens', [
        '@provider' => $provider,
        '@model' => $model,
        '@operation' => $operation,
        '@input' => $inputTokens,
        '@output' => $outputTokens,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to log token usage: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Gets usage statistics for the current day.
   *
   * @param string|null $provider
   *   Optional provider to filter by. NULL returns all providers.
   *
   * @return array
   *   Array containing:
   *   - 'total_input_tokens': Total input tokens today.
   *   - 'total_output_tokens': Total output tokens today.
   *   - 'total_tokens': Combined total tokens.
   *   - 'by_model': Breakdown by model.
   *   - 'by_operation': Breakdown by operation type.
   */
  public function getDailyUsage(?string $provider = NULL): array {
    $today_start = strtotime('today midnight');

    try {
      // Base query for today's usage.
      $query = $this->database->select('markaspot_ai_token_usage', 't')
        ->condition('t.created', $today_start, '>=');

      if ($provider !== NULL) {
        $query->condition('t.provider', $provider);
      }

      // Get totals.
      $query->addExpression('SUM(t.input_tokens)', 'total_input');
      $query->addExpression('SUM(t.output_tokens)', 'total_output');
      $query->addExpression('COUNT(*)', 'request_count');

      $totals = $query->execute()->fetchAssoc();

      // Get breakdown by model.
      $model_query = $this->database->select('markaspot_ai_token_usage', 't')
        ->fields('t', ['model'])
        ->condition('t.created', $today_start, '>=');

      if ($provider !== NULL) {
        $model_query->condition('t.provider', $provider);
      }

      $model_query->addExpression('SUM(t.input_tokens)', 'input_tokens');
      $model_query->addExpression('SUM(t.output_tokens)', 'output_tokens');
      $model_query->addExpression('COUNT(*)', 'requests');
      $model_query->groupBy('t.model');

      $by_model = $model_query->execute()->fetchAllAssoc('model', \PDO::FETCH_ASSOC);

      // Get breakdown by operation.
      $operation_query = $this->database->select('markaspot_ai_token_usage', 't')
        ->fields('t', ['operation'])
        ->condition('t.created', $today_start, '>=');

      if ($provider !== NULL) {
        $operation_query->condition('t.provider', $provider);
      }

      $operation_query->addExpression('SUM(t.input_tokens)', 'input_tokens');
      $operation_query->addExpression('SUM(t.output_tokens)', 'output_tokens');
      $operation_query->addExpression('COUNT(*)', 'requests');
      $operation_query->groupBy('t.operation');

      $by_operation = $operation_query->execute()->fetchAllAssoc('operation', \PDO::FETCH_ASSOC);

      $total_input = (int) ($totals['total_input'] ?? 0);
      $total_output = (int) ($totals['total_output'] ?? 0);

      return [
        'total_input_tokens' => $total_input,
        'total_output_tokens' => $total_output,
        'total_tokens' => $total_input + $total_output,
        'request_count' => (int) ($totals['request_count'] ?? 0),
        'by_model' => $by_model,
        'by_operation' => $by_operation,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get daily usage: @message', [
        '@message' => $e->getMessage(),
      ]);

      return [
        'total_input_tokens' => 0,
        'total_output_tokens' => 0,
        'total_tokens' => 0,
        'request_count' => 0,
        'by_model' => [],
        'by_operation' => [],
      ];
    }
  }

  /**
   * Gets total usage statistics for a given time period.
   *
   * @param int $days
   *   Number of days to include (default: 30).
   *
   * @return array
   *   Array containing:
   *   - 'total_input_tokens': Total input tokens.
   *   - 'total_output_tokens': Total output tokens.
   *   - 'total_tokens': Combined total.
   *   - 'daily_breakdown': Usage per day.
   *   - 'by_provider': Breakdown by provider.
   *   - 'by_model': Breakdown by model.
   */
  public function getTotalUsage(int $days = 30): array {
    $start_time = strtotime("-{$days} days midnight");

    try {
      // Get overall totals.
      $query = $this->database->select('markaspot_ai_token_usage', 't')
        ->condition('t.created', $start_time, '>=');

      $query->addExpression('SUM(t.input_tokens)', 'total_input');
      $query->addExpression('SUM(t.output_tokens)', 'total_output');
      $query->addExpression('COUNT(*)', 'request_count');

      $totals = $query->execute()->fetchAssoc();

      // Get daily breakdown (database-agnostic approach).
      $daily_query = $this->database->select('markaspot_ai_token_usage', 't')
        ->fields('t', ['created', 'input_tokens', 'output_tokens'])
        ->condition('t.created', $start_time, '>=')
        ->orderBy('t.created', 'DESC');

      $daily_records = $daily_query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      // Group by date in PHP for database portability.
      $daily_breakdown = [];
      foreach ($daily_records as $record) {
        $date = date('Y-m-d', (int) $record['created']);
        if (!isset($daily_breakdown[$date])) {
          $daily_breakdown[$date] = [
            'date' => $date,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'requests' => 0,
          ];
        }
        $daily_breakdown[$date]['input_tokens'] += (int) $record['input_tokens'];
        $daily_breakdown[$date]['output_tokens'] += (int) $record['output_tokens'];
        $daily_breakdown[$date]['requests']++;
      }

      // Get breakdown by provider.
      $provider_query = $this->database->select('markaspot_ai_token_usage', 't')
        ->fields('t', ['provider'])
        ->condition('t.created', $start_time, '>=');

      $provider_query->addExpression('SUM(t.input_tokens)', 'input_tokens');
      $provider_query->addExpression('SUM(t.output_tokens)', 'output_tokens');
      $provider_query->addExpression('COUNT(*)', 'requests');
      $provider_query->groupBy('t.provider');

      $by_provider = $provider_query->execute()->fetchAllAssoc('provider', \PDO::FETCH_ASSOC);

      // Get breakdown by model.
      $model_query = $this->database->select('markaspot_ai_token_usage', 't')
        ->fields('t', ['provider', 'model'])
        ->condition('t.created', $start_time, '>=');

      $model_query->addExpression('SUM(t.input_tokens)', 'input_tokens');
      $model_query->addExpression('SUM(t.output_tokens)', 'output_tokens');
      $model_query->addExpression('COUNT(*)', 'requests');
      $model_query->groupBy('t.provider');
      $model_query->groupBy('t.model');

      $by_model = $model_query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      $total_input = (int) ($totals['total_input'] ?? 0);
      $total_output = (int) ($totals['total_output'] ?? 0);

      return [
        'period_days' => $days,
        'total_input_tokens' => $total_input,
        'total_output_tokens' => $total_output,
        'total_tokens' => $total_input + $total_output,
        'request_count' => (int) ($totals['request_count'] ?? 0),
        'daily_breakdown' => $daily_breakdown,
        'by_provider' => $by_provider,
        'by_model' => $by_model,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get total usage: @message', [
        '@message' => $e->getMessage(),
      ]);

      return [
        'period_days' => $days,
        'total_input_tokens' => 0,
        'total_output_tokens' => 0,
        'total_tokens' => 0,
        'request_count' => 0,
        'daily_breakdown' => [],
        'by_provider' => [],
        'by_model' => [],
      ];
    }
  }

  /**
   * Checks if the daily token limit has been exceeded.
   *
   * @return bool
   *   TRUE if under the limit, FALSE if limit exceeded.
   */
  public function checkLimit(): bool {
    $config = $this->configFactory->get('markaspot_ai.settings');

    // If tracking is disabled, always allow.
    if (!$config->get('token_tracking.enabled')) {
      return TRUE;
    }

    $daily_limit = (int) $config->get('token_tracking.daily_limit');

    // If no limit configured, always allow.
    if ($daily_limit <= 0) {
      return TRUE;
    }

    $usage = $this->getDailyUsage();
    $current_total = $usage['total_tokens'];

    if ($current_total >= $daily_limit) {
      $this->logger->warning('Daily token limit reached: @current / @limit tokens', [
        '@current' => $current_total,
        '@limit' => $daily_limit,
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Gets the remaining tokens available for today.
   *
   * @return int
   *   Number of tokens remaining, or -1 if unlimited.
   */
  public function getRemainingTokens(): int {
    $config = $this->configFactory->get('markaspot_ai.settings');

    if (!$config->get('token_tracking.enabled')) {
      return -1;
    }

    $daily_limit = (int) $config->get('token_tracking.daily_limit');

    if ($daily_limit <= 0) {
      return -1;
    }

    $usage = $this->getDailyUsage();
    $remaining = $daily_limit - $usage['total_tokens'];

    return max(0, $remaining);
  }

  /**
   * Cleans up old usage records beyond a retention period.
   *
   * @param int $retention_days
   *   Number of days to retain records (default: 90).
   *
   * @return int
   *   Number of records deleted.
   */
  public function cleanupOldRecords(int $retention_days = 90): int {
    $cutoff_time = strtotime("-{$retention_days} days");

    try {
      $deleted = $this->database->delete('markaspot_ai_token_usage')
        ->condition('created', $cutoff_time, '<')
        ->execute();

      if ($deleted > 0) {
        $this->logger->info('Cleaned up @count old token usage records (older than @days days)', [
          '@count' => $deleted,
          '@days' => $retention_days,
        ]);
      }

      return $deleted;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to cleanup old records: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

}
