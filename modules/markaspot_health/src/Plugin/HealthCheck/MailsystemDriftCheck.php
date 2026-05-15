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
 * away from phpmailer_smtp and markaspot_mail.settings.attachments.enabled
 * flips off. Symptom: notification mails go out via the wrong transport,
 * attachments silently dropped.
 *
 * @HealthCheck(
 *   id = "mailsystem_drift",
 *   label = @Translation("Mailsystem configuration drift"),
 *   severity = "error",
 *   description = @Translation("Verifies mailsystem.settings sender is phpmailer_smtp and markaspot_mail attachments are enabled; both flip after raw DB imports."),
 *   fix_hint = @Translation("Deploy the profile mail config update or set mailsystem.settings defaults.sender=phpmailer_smtp and markaspot_mail.settings attachments.enabled=true."),
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
    $mailsystem = $this->configFactory->get('mailsystem.settings');
    if ($mailsystem->isNew()) {
      return $this->pass('mailsystem module not configured; check skipped.');
    }

    $issues = [];
    $sender = (string) $mailsystem->get('defaults.sender');
    if ($sender !== 'phpmailer_smtp') {
      $issues[] = sprintf('defaults.sender=%s (expected phpmailer_smtp)', $sender === '' ? '<unset>' : $sender);
    }

    $mail_settings = $this->configFactory->get('markaspot_mail.settings');
    $attachments = $mail_settings->isNew() ? NULL : $mail_settings->get('attachments.enabled');
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
