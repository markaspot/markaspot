<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects mailsystem configuration drift.
 *
 * After any DB import on cp2/prod, mailsystem defaults can drift away from
 * phpmailer_smtp and markaspot_mail.settings.attachments.enabled can flip off.
 * Symptom: notification mails go out via the wrong transport, branded HTML is
 * converted to plaintext, or attachments are silently dropped.
 *
 * @HealthCheck(
 *   id = "mailsystem_drift",
 *   label = @Translation("Mailsystem configuration drift"),
 *   severity = "error",
 *   description = @Translation("Checks mail sender, formatter, and markaspot_mail attachment defaults."),
 *   fix_hint = @Translation("Run markaspot_mail updates or reapply the phpmailer_smtp mail config."),
 * )
 */
class MailsystemDriftCheck extends HealthCheckPluginBase
{
  /**
   * Constructs the plugin.
   */
    public function __construct(
        array $configuration,
        string $plugin_id,
        $plugin_definition,
        protected ConfigFactoryInterface $configFactory,
        protected ModuleHandlerInterface $moduleHandler,
    ) {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
    }

  /**
   * {@inheritdoc}
   */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        return new self(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->get('config.factory'),
            $container->get('module_handler'),
        );
    }

  /**
   * {@inheritdoc}
   */
    public function run(array $context = []): HealthCheckResult
    {
        $mailsystem = $this->configFactory->get('mailsystem.settings');
        if ($mailsystem->isNew()) {
            return $this->fail(1, 'mailsystem.settings missing; mail backend is not configured.');
        }

        $issues = [];
        if (!$this->moduleHandler->moduleExists('mailsystem')) {
            $issues[] = 'mailsystem module disabled';
        }

        $system_mail = $this->configFactory->get('system.mail');
        $default_interface = $system_mail->isNew() ? '' : (string) $system_mail->get('interface.default');
        // Legacy tenants can intentionally select smtp through SMTP_MODULE.
        // Cloud runtime settings keep sender, formatter, and system.mail aligned.
        $expected_backends = ['phpmailer_smtp', 'smtp'];
        if (!in_array($default_interface, $expected_backends, true)) {
            $issues[] = sprintf(
                'system.mail.interface.default=%s (expected phpmailer_smtp or smtp)',
                $default_interface === '' ? '<unset>' : $default_interface,
            );
        }

        $sender = (string) $mailsystem->get('defaults.sender');
        if (!in_array($sender, $expected_backends, true)) {
            $issues[] = sprintf(
                'defaults.sender=%s (expected phpmailer_smtp or smtp)',
                $sender === '' ? '<unset>' : $sender,
            );
        }
        $formatter = (string) $mailsystem->get('defaults.formatter');
        if (!in_array($formatter, $expected_backends, true)) {
            $issues[] = sprintf(
                'defaults.formatter=%s (expected phpmailer_smtp or smtp)',
                $formatter === '' ? '<unset>' : $formatter,
            );
        }
        if ($sender !== $formatter) {
            $issues[] = sprintf('defaults.sender=%s differs from defaults.formatter=%s', $sender, $formatter);
        }
        if ($sender !== $default_interface) {
            $issues[] = sprintf(
                'defaults.sender=%s differs from system.mail.interface.default=%s',
                $sender,
                $default_interface,
            );
        }
        if ($formatter !== $default_interface) {
            $issues[] = sprintf(
                'defaults.formatter=%s differs from system.mail.interface.default=%s',
                $formatter,
                $default_interface,
            );
        }
        foreach (array_unique([$default_interface, $sender, $formatter]) as $backend) {
            if (in_array($backend, $expected_backends, true) && !$this->moduleHandler->moduleExists($backend)) {
                $issues[] = sprintf('%s module disabled', $backend);
            }
        }

        $mail_settings = $this->configFactory->get('markaspot_mail.settings');
        $attachments = $mail_settings->isNew() ? null : $mail_settings->get('attachments.enabled');
        if ($attachments !== true && $attachments !== 1 && $attachments !== '1') {
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
