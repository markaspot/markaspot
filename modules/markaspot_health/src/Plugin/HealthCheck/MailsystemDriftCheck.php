<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects mailsystem configuration drift.
 *
 * After any DB import on cp2/prod, mailsystem.settings.defaults.sender drifts
 * away from phpmailer_smtp and attachments.enabled flips off. Symptom:
 * notification mails go out via the wrong transport, attachments silently
 * dropped.
 *
 * @HealthCheck(
 *   id = "mailsystem_drift",
 *   label = @Translation("Mailsystem configuration drift"),
 *   severity = "error",
 *   description = @Translation("Verifies mailsystem.settings sender is phpmailer_smtp and attachments are enabled; both flip after raw DB imports."),
 *   fix_hint = @Translation("drush cset mailsystem.settings defaults.sender phpmailer_smtp and drush cset mailsystem.settings attachments.enabled true."),
 * )
 */
class MailsystemDriftCheck extends HealthCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
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
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    $config = $this->configFactory->get('mailsystem.settings');
    if ($config->isNew()) {
      return $this->pass('mailsystem module not configured; check skipped.');
    }

    $issues = [];
    $sender = (string) $config->get('defaults.sender');
    if ($sender !== 'phpmailer_smtp') {
      $issues[] = sprintf('defaults.sender=%s (expected phpmailer_smtp)', $sender === '' ? '<unset>' : $sender);
    }
    $attachments = $config->get('attachments.enabled');
    if ($attachments !== TRUE && $attachments !== 1 && $attachments !== '1') {
      $issues[] = 'attachments.enabled=false (expected true)';
    }

    if ($issues === []) {
      return $this->pass('Mailsystem configured correctly.');
    }
    return $this->fail(
      count($issues),
      sprintf('Mailsystem drift: %s.', implode('; ', $issues)),
    );
  }

}
