<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Drupal\markaspot_mail\Mail\MailBuilderRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects silent gaps in the transactional mail pipeline before they fire.
 *
 * Three independent sub-checks, each gated on its own module being present:
 *   a) markaspot_fastmap: every top-level key shipped in
 *      config/install/markaspot_fastmap.mail.yml must exist in the ACTIVE
 *      markaspot_fastmap.mail config with a non-empty subject and body. This
 *      is deliberately stricter than _markaspot_fastmap_mail_template()'s
 *      fallback chain — that helper self-heals from the shipped YAML at
 *      send time, which is exactly what let workspace_welcome ship with a
 *      backfill-only config key and go unnoticed until strtr(NULL) fired
 *      inside the fail-safe catch. Checking the active config directly
 *      surfaces the gap before an update hook has to paper over it again.
 *   b) mailsystem + phpmailer_smtp: when phpmailer_smtp is the configured
 *      sender, phpmailer_smtp.settings:smtp_ehlo_host must be non-empty.
 *      PHPMailer falls back to "localhost.localdomain" as the EHLO hostname
 *      when this is blank, which upstream relays such as Microsoft 365
 *      treat as a spoofing/spam signal and may silently quarantine.
 *   c) markaspot_mail: every service tagged markaspot_mail.builder must
 *      instantiate cleanly and expose a unique MailType via getType().
 *      Two builders claiming the same MailType is a registration bug that
 *      would otherwise only surface as "the wrong template rendered" at
 *      the point some future findByType() caller picks the first match.
 *
 * @HealthCheck(
 *   id = "mail_coverage",
 *   label = @Translation("Mail template and builder coverage gaps"),
 *   severity = "error",
 *   description = @Translation("Checks that shipped markaspot_fastmap mail templates are present in active config, that phpmailer_smtp has a non-empty EHLO host when it is the configured sender, and that every tagged markaspot_mail.builder service instantiates with a unique MailType."),
 *   fix_hint = @Translation("For missing fastmap templates: re-run markaspot_fastmap_update_11924() or edit the mail templates at /admin/config/markaspot/fastmap/mail. For an empty EHLO host: set phpmailer_smtp.settings:smtp_ehlo_host to the sending domain. For builder collisions: give the colliding builder its own Drupal\markaspot_mail\Enum\MailType case."),
 *   fix_url = "/admin/config/markaspot/fastmap/mail",
 * )
 */
class MailCoverageCheck extends HealthCheckPluginBase {

  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected ModuleExtensionList $moduleExtensionList,
    protected ?MailBuilderRegistry $mailBuilderRegistry = NULL,
    protected ?string $mailBuilderRegistryError = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $moduleHandler = $container->get('module_handler');

    // markaspot_health.mail_builder_registry is instantiated eagerly here
    // (not deferred into run()) because container->get() is the only point
    // where the tagged_iterator materializes the builder services — and a
    // broken builder constructor must surface as a failed check result, not
    // as a fatal error that aborts the entire markaspot:health run. Catching
    // it here keeps that failure contained to this plugin.
    $mailBuilderRegistry = NULL;
    $mailBuilderRegistryError = NULL;
    if ($moduleHandler->moduleExists('markaspot_mail') && $container->has('markaspot_health.mail_builder_registry')) {
      try {
        $mailBuilderRegistry = $container->get('markaspot_health.mail_builder_registry');
      }
      catch (\Throwable $e) {
        $mailBuilderRegistryError = $e->getMessage();
      }
    }

    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $moduleHandler,
      $container->get('extension.list.module'),
      $mailBuilderRegistry,
      $mailBuilderRegistryError,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    $errors = [];
    $warnings = [];

    if ($this->moduleHandler->moduleExists('markaspot_fastmap')) {
      $this->checkFastmapMailTemplateCoverage($errors);
    }

    if ($this->moduleHandler->moduleExists('mailsystem') && $this->moduleHandler->moduleExists('phpmailer_smtp')) {
      $this->checkSmtpEhloHost($warnings);
    }

    if ($this->moduleHandler->moduleExists('markaspot_mail')) {
      $this->checkMailBuilderRegistry($errors);
    }

    if ($errors === [] && $warnings === []) {
      return $this->pass('Mail template coverage, SMTP EHLO host, and markaspot_mail builder registry all check out (or their modules are not installed).');
    }

    $details = array_merge(
      array_map(static fn(array $d) => $d + ['severity' => 'error'], $errors),
      array_map(static fn(array $d) => $d + ['severity' => 'warning'], $warnings),
    );

    if ($errors !== []) {
      return $this->failWithSeverity(
        'error',
        count($errors),
        sprintf(
          '%d mail-coverage error(s)%s.',
          count($errors),
          $warnings !== [] ? sprintf(' (plus %d warning(s))', count($warnings)) : '',
        ),
        $details,
        0,
        $context['jurisdiction'] ?? NULL,
      );
    }

    return $this->failWithSeverity(
      'warning',
      count($warnings),
      sprintf('%d mail-coverage warning(s).', count($warnings)),
      $details,
      0,
      $context['jurisdiction'] ?? NULL,
    );
  }

  /**
   * Sub-check (a): active markaspot_fastmap.mail config vs. shipped YAML.
   *
   * @param array<int, array<string, mixed>> $errors
   *   Error bucket, appended to by reference.
   */
  protected function checkFastmapMailTemplateCoverage(array &$errors): void {
    $keys = $this->shippedFastmapMailKeys();
    if ($keys === []) {
      return;
    }

    $activeConfig = $this->configFactory->get('markaspot_fastmap.mail');
    foreach ($keys as $key) {
      $template = $activeConfig->get($key);
      $subject = is_array($template) ? trim((string) ($template['subject'] ?? '')) : '';
      $body = is_array($template) ? trim((string) ($template['body'] ?? '')) : '';
      if ($subject !== '' && $body !== '') {
        continue;
      }
      $missing = array_values(array_filter([
        $subject === '' ? 'subject' : NULL,
        $body === '' ? 'body' : NULL,
      ]));
      $errors[] = [
        'check' => 'markaspot_fastmap.mail',
        'key' => $key,
        'missing_field' => implode('+', $missing),
      ];
    }
  }

  /**
   * Reads the top-level mail template keys from the shipped install YAML.
   *
   * _markaspot_fastmap_shipped_mail_template() only resolves a single known
   * key; it has no "list every key" mode. Reading the same shipped file
   * directly keeps this check future-proof against a third mail key being
   * added without a matching health-check update.
   *
   * @return list<string>
   *   Top-level keys, excluding the "langcode" sibling key.
   */
  protected function shippedFastmapMailKeys(): array {
    $path = $this->moduleExtensionList->getPath('markaspot_fastmap') . '/config/install/markaspot_fastmap.mail.yml';
    if (!is_file($path)) {
      return [];
    }
    $decoded = Yaml::decode((string) file_get_contents($path));
    if (!is_array($decoded)) {
      return [];
    }
    unset($decoded['langcode']);
    return array_values(array_filter(array_keys($decoded), 'is_string'));
  }

  /**
   * Sub-check (b): phpmailer_smtp EHLO host when it is the configured sender.
   *
   * @param array<int, array<string, mixed>> $warnings
   *   Warning bucket, appended to by reference.
   */
  protected function checkSmtpEhloHost(array &$warnings): void {
    $sender = (string) $this->configFactory->get('mailsystem.settings')->get('defaults.sender');
    if ($sender !== 'phpmailer_smtp') {
      return;
    }

    $host = trim((string) $this->configFactory->get('phpmailer_smtp.settings')->get('smtp_ehlo_host'));
    if ($host !== '') {
      return;
    }

    $warnings[] = [
      'check' => 'phpmailer_smtp.settings',
      'key' => 'smtp_ehlo_host',
      'issue' => 'Empty. PHPMailer falls back to "localhost.localdomain" as the EHLO hostname; upstream relays such as Microsoft 365 treat that as a spoofing/spam signal and may silently quarantine the mail.',
    ];
  }

  /**
   * Sub-check (c): tagged markaspot_mail.builder services.
   *
   * @param array<int, array<string, mixed>> $errors
   *   Error bucket, appended to by reference.
   */
  protected function checkMailBuilderRegistry(array &$errors): void {
    if ($this->mailBuilderRegistryError !== NULL) {
      $errors[] = [
        'check' => 'markaspot_mail.builder',
        'issue' => 'Failed to instantiate one or more tagged builder services: ' . $this->mailBuilderRegistryError,
      ];
      return;
    }
    if ($this->mailBuilderRegistry === NULL) {
      return;
    }

    $classesByType = [];
    foreach ($this->mailBuilderRegistry->all() as $builder) {
      $classesByType[$builder->getType()->value][] = $builder::class;
    }

    foreach ($classesByType as $type => $classes) {
      if (count($classes) > 1) {
        $errors[] = [
          'check' => 'markaspot_mail.builder',
          'issue' => sprintf('MailType "%s" is claimed by more than one builder: %s.', $type, implode(', ', $classes)),
        ];
      }
    }
  }

}
