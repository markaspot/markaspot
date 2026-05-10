<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects elevated mail-failure rates across the canonical mail channels.
 *
 * The GeoReport endpoint is fire-and-forget on mail: a save commits even
 * when the citizen-confirmation or editorial-notification mail fails (502
 * is returned only when the throw escapes the post-save hook chain). That
 * keeps the API contract clean but pushes the operational signal entirely
 * to the watchdog.
 *
 * This plugin closes that gap: it counts ERROR/WARNING-severity entries
 * on the canonical mail channels (`mail`, `phpmailer_smtp`,
 * `markaspot_mail`) within a rolling time window. When the count crosses
 * the threshold the plugin fails — operators get the same release-deploy
 * gate signal they would have gotten from a 502 spike, without coupling
 * the API contract to mail config.
 *
 * Relies on dblog: skips cleanly when dblog is not enabled.
 *
 * @HealthCheck(
 *   id = "mail_failure_rate",
 *   label = @Translation("Elevated mail-failure rate in watchdog"),
 *   severity = "warning",
 *   description = @Translation("Counts ERROR/WARNING-severity entries on the mail / phpmailer_smtp / markaspot_mail channels in the last hour. Crosses the threshold → fail."),
 *   fix_hint = @Translation("Inspect /admin/reports/dblog?type=mail (or phpmailer_smtp / markaspot_mail). Common causes: missing recipient (field_e_mail empty on a flow that triggers a notification), SMTP relay outage, throttling by upstream provider."),
 *   fix_url = "/admin/reports/dblog",
 * )
 */
class MailFailureRateCheck extends HealthCheckPluginBase {

  /**
   * Default lookback window in seconds.
   */
  private const DEFAULT_WINDOW_SECONDS = 3600;

  /**
   * Default failure-count threshold across the window.
   *
   * Set above the smoke-suite's own one-shot synthetic-mail failure
   * (ReportCreate + StatusChange each fail one mail per --mode=full run)
   * so a normal daily smoke run does not flip this check red on its own.
   * Tenants with chronic SMTP issues will easily exceed this.
   */
  private const DEFAULT_THRESHOLD = 10;

  /**
   * Watchdog channels considered mail-relevant.
   *
   * Covers Drupal core (`mail`), the contrib SMTP transport
   * (`phpmailer_smtp`), the profile mail module (`markaspot_mail`),
   * Drupal 11's moving target (`symfony_mailer`, growing default), and
   * ECA — `action_send_email` logs failures on the `eca` channel rather
   * than `mail` when it wraps the underlying mail manager exception.
   */
  private const MAIL_CHANNELS = [
    'mail',
    'phpmailer_smtp',
    'markaspot_mail',
    'symfony_mailer',
    'eca',
  ];

  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected Connection $database,
    protected ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->moduleHandler->moduleExists('dblog')) {
      return $this->pass('dblog not enabled; mail-failure rate cannot be observed via watchdog. Check skipped.');
    }
    if (!$this->database->schema()->tableExists('watchdog')) {
      return $this->pass('watchdog table missing; check skipped.');
    }

    $window = self::DEFAULT_WINDOW_SECONDS;
    $threshold = self::DEFAULT_THRESHOLD;
    $cutoff = (int) time() - $window;

    // RFC 5424 severities: 0=emergency, 1=alert, 2=critical, 3=error,
    // 4=warning. Drupal's WATCHDOG_* constants map 1:1. Anything
    // <= warning counts as a mail failure for our purposes.
    $count = (int) $this->database->select('watchdog', 'w')
      ->condition('w.type', self::MAIL_CHANNELS, 'IN')
      ->condition('w.severity', 4, '<=')
      ->condition('w.timestamp', $cutoff, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($count < $threshold) {
      return $this->pass(sprintf(
        '%d mail-failure event(s) on channels [%s] in the last %d minute(s) (threshold %d).',
        $count,
        implode(', ', self::MAIL_CHANNELS),
        (int) ($window / 60),
        $threshold,
      ));
    }

    $details = $this->collectRecentFailures($cutoff, 10);
    return $this->fail(
      $count,
      sprintf(
        '%d mail-failure event(s) on channels [%s] in the last %d minute(s); threshold is %d.',
        $count,
        implode(', ', self::MAIL_CHANNELS),
        (int) ($window / 60),
        $threshold,
      ),
      $details,
      max(0, $count - count($details)),
      // tenantId stays NULL: mail failures are platform-wide and the
      // watchdog row carries no jurisdiction column. Tagging the result
      // with $context['jurisdiction'] would mislead the per-tenant
      // fastmap dashboard.
      NULL,
    );
  }

  /**
   * Returns up to $limit recent watchdog entries on the mail channels.
   *
   * @return array<int, array<string, mixed>>
   *   Per-row evidence with channel, severity label, message excerpt,
   *   and ISO timestamp.
   */
  protected function collectRecentFailures(int $cutoffTimestamp, int $limit): array {
    $rows = $this->database->select('watchdog', 'w')
      ->fields('w', ['type', 'severity', 'message', 'variables', 'timestamp'])
      ->condition('w.type', self::MAIL_CHANNELS, 'IN')
      ->condition('w.severity', 4, '<=')
      ->condition('w.timestamp', $cutoffTimestamp, '>=')
      ->orderBy('w.timestamp', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();

    $details = [];
    foreach ($rows as $row) {
      $details[] = [
        'channel' => $row->type,
        'severity' => $this->severityLabel((int) $row->severity),
        'message_excerpt' => $this->renderExcerpt((string) $row->message, (string) $row->variables),
        'timestamp_iso' => gmdate('c', (int) $row->timestamp),
      ];
    }
    return $details;
  }

  /**
   * Maps an RFC 5424 severity number to a short label.
   */
  protected function severityLabel(int $severity): string {
    return match ($severity) {
      0 => 'emergency',
      1 => 'alert',
      2 => 'critical',
      3 => 'error',
      4 => 'warning',
      5 => 'notice',
      6 => 'info',
      7 => 'debug',
      default => 'unknown',
    };
  }

  /**
   * Renders a watchdog message + variables blob into a short, redacted string.
   *
   * Drupal's mail stack (PHPMailer / Symfony Mailer / phpmailer_smtp)
   * embeds the failed recipient address verbatim into the Exception
   * message ("SMTP ERROR: … Recipients: citizen@example.com"). When that
   * lands in the watchdog row, the renderExcerpt output flows into the
   * health-check details payload exposed via /api/admin/health-check.
   * Even though that endpoint is admin-gated, citizen email addresses are
   * personal data under GDPR Art. 5(1)(f) and BSI APP.3.1.A14 — log data
   * must not carry PII beyond what is operationally necessary. Redact
   * email-shaped substrings before clipping the excerpt; operators still
   * see the failure pattern, citizens stay anonymous.
   */
  protected function renderExcerpt(string $message, string $serialisedVariables): string {
    $variables = @unserialize($serialisedVariables, ['allowed_classes' => FALSE]);
    if (is_array($variables)) {
      $message = strtr($message, array_map(
        static fn(mixed $v): string => is_scalar($v) ? (string) $v : '',
        $variables,
      ));
    }
    $message = (string) preg_replace('/[\w.+\-]+@[\w\-]+\.[\w.\-]+/', '[email]', $message);
    $stripped = trim((string) preg_replace('/\s+/', ' ', strip_tags($message)));
    return mb_substr($stripped, 0, 160);
  }

}
