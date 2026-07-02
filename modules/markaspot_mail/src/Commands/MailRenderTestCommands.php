<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailBuilderRegistry;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailSampleContextProvider;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\markaspot_mail\Service\MailHtmlRenderer;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands that exercise the mail pipeline without sending mail.
 */
class MailRenderTestCommands extends DrushCommands {

  /**
   * Markaspot_fastmap.mail keys checked per activated language.
   *
   * Resolved via _markaspot_fastmap_mail_template() (config +
   * shipped-YAML fallback).
   */
  private const FASTMAP_LANGUAGE_GATED_KEYS = ['workspace_verification', 'workspace_welcome'];

  public function __construct(
    protected MailBuilderRegistry $mailBuilderRegistry,
    protected MailSampleContextProvider $sampleContextProvider,
    protected MailBrandingService $brandingService,
    protected MailHtmlRenderer $renderer,
    protected LanguageManagerInterface $languageManager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct();
  }

  /**
   * Renders every registered mail builder with sample data. Sends nothing.
   *
   * Two groups of checks:
   *   - "builder": every service tagged markaspot_mail.builder is invoked
   *     with a representative MailContext (see MailSampleContextProvider),
   *     then run through MailBrandingService + MailHtmlRenderer exactly as
   *     MailAlterHook would at send time. Asserts non-empty subject, HTML
   *     and plaintext, and a safe cta_url when the builder sets one.
   *   - "fastmap_hook": markaspot_fastmap's legacy hook_mail() path
   *     (_markaspot_fastmap_mail_template()) is resolved for
   *     workspace_verification and workspace_welcome in every activated
   *     language. This exercises the config + shipped-YAML fallback chain
   *     directly, complementing markaspot_health's mail_coverage check
   *     (which asserts the ACTIVE config alone, deliberately without the
   *     fallback, to catch missing config keys before the fallback papers
   *     over them).
   *
   * @param array $options
   *   Command options.
   *
   * @option format
   *   Output format: table or json. Defaults to table.
   * @option exit-non-zero
   *   When set, exit code equals the number of failed checks.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields|null
   *   Structured rows for table output, NULL when format is json.
   */
  #[CLI\Command(name: 'markaspot:mail-render-test', aliases: ['mas:mail-render-test'])]
  #[CLI\Option(name: 'format', description: 'Output format: table or json.')]
  #[CLI\Option(name: 'exit-non-zero', description: 'Exit non-zero when any check fails.')]
  #[CLI\FieldLabels(labels: [
    'check' => 'Check',
    'key' => 'Key',
    'detail' => 'Detail',
    'status' => 'Status',
    'message' => 'Message',
  ])]
  #[CLI\Usage(name: 'drush markaspot:mail-render-test', description: 'Render every registered mail builder with sample data and print a table.')]
  #[CLI\Usage(name: 'drush markaspot:mail-render-test --format=json --exit-non-zero', description: 'Machine-readable output; exit code equals the failure count.')]
  public function mailRenderTest(
    array $options = [
      'format' => 'table',
      'exit-non-zero' => FALSE,
    ],
  ): ?RowsOfFields {
    $rows = array_merge(
      $this->testBuilders(),
      $this->testFastmapHookTemplates(),
    );

    $failures = count(array_filter($rows, static fn(array $r): bool => $r['status'] === 'FAIL'));

    $format = $options['format'] ?? 'table';
    if ($format === 'json') {
      $this->output()->writeln((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      $this->maybeFail($options, $failures);
      return NULL;
    }

    $this->maybeFail($options, $failures);
    return new RowsOfFields($rows);
  }

  /**
   * Renders every tagged markaspot_mail.builder service with sample data.
   *
   * @return array<int, array<string, string>>
   *   Result rows.
   */
  protected function testBuilders(): array {
    $samples = $this->sampleContextProvider->getSamples();
    $rows = [];
    foreach ($this->mailBuilderRegistry->all() as $builder) {
      $type = $builder->getType()->value;
      $ctx = $samples[$type] ?? NULL;
      if ($ctx === NULL) {
        $rows[] = $this->row('builder', $type, $builder::class, 'SKIP', 'No sample MailContext available (e.g. no service_request node or org group in this database).');
        continue;
      }
      $rows[] = $this->renderAndCheckBuilder($type, $builder, $ctx);
    }
    return $rows;
  }

  /**
   * Builds, brands and renders a single builder's sample, then validates it.
   *
   * @return array<string, string>
   *   Result row.
   */
  protected function renderAndCheckBuilder(string $type, MailBuilderInterface $builder, MailContext $ctx): array {
    $class = $builder::class;
    try {
      $message = $builder->build($ctx);
    }
    catch (\Throwable $e) {
      return $this->row('builder', $type, $class, 'FAIL', 'build() threw: ' . $e->getMessage());
    }
    if ($message === NULL) {
      return $this->row('builder', $type, $class, 'FAIL', 'build() returned NULL for a sample context that should have been sufficient.');
    }

    $problems = [];
    if (trim($message->subject) === '') {
      $problems[] = 'empty subject';
    }
    $ctaUrl = trim((string) ($message->content['cta_url'] ?? ''));
    if ($ctaUrl !== '' && !$this->isSafeCtaUrl($ctaUrl)) {
      $problems[] = sprintf('unsafe cta_url "%s"', $ctaUrl);
    }

    try {
      $branding = $this->brandingService->getBranding($message->jurisdictionId, $message->mode, $ctx->langcode);
      $rendered = $this->renderer->render($message->variant, $branding, $message->content, $ctx->langcode, $message->plainText);
    }
    catch (\Throwable $e) {
      return $this->row('builder', $type, $class, 'FAIL', 'render() threw: ' . $e->getMessage());
    }

    if (trim($rendered['html']) === '') {
      $problems[] = 'empty HTML';
    }
    if (trim($rendered['plain']) === '') {
      $problems[] = 'empty plaintext';
    }

    return $problems === []
      ? $this->row('builder', $type, $class, 'PASS', sprintf('subject="%s"', $message->subject))
      : $this->row('builder', $type, $class, 'FAIL', implode('; ', $problems));
  }

  /**
   * Checks markaspot_fastmap's hook_mail() template resolution per language.
   *
   * @return array<int, array<string, string>>
   *   Result rows. Empty when markaspot_fastmap is not installed.
   */
  protected function testFastmapHookTemplates(): array {
    if (!$this->moduleHandler->moduleExists('markaspot_fastmap')) {
      return [];
    }

    $rows = [];
    foreach ($this->languageManager->getLanguages() as $language) {
      $langcode = $language->getId();
      foreach (self::FASTMAP_LANGUAGE_GATED_KEYS as $key) {
        // Provided by markaspot_fastmap.module; safe to call directly, the
        // module is guaranteed loaded because moduleExists() returned TRUE.
        $template = _markaspot_fastmap_mail_template($key, $langcode);
        $subject = trim($template['subject']);
        $body = trim($template['body']);
        if ($subject !== '' && $body !== '') {
          $rows[] = $this->row('fastmap_hook', $key, $langcode, 'PASS', 'subject+body resolved');
          continue;
        }
        $missing = array_values(array_filter([
          $subject === '' ? 'subject' : NULL,
          $body === '' ? 'body' : NULL,
        ]));
        $rows[] = $this->row('fastmap_hook', $key, $langcode, 'FAIL', 'empty ' . implode('+', $missing) . ' after config + shipped-YAML fallback');
      }
    }
    return $rows;
  }

  /**
   * Validates a CTA URL the same way MailHtmlRenderer::isSafeUrl() does.
   *
   * MailHtmlRenderer's own isSafeUrl() is private (render-time defense in
   * depth, not a public contract); this mirrors that exact http(s)-only +
   * Url::fromUri() check so the drush gate gives an early, independent
   * signal without changing the renderer's visibility.
   */
  protected function isSafeCtaUrl(string $url): bool {
    if (preg_match('#^https?://#i', $url) !== 1) {
      return FALSE;
    }
    try {
      Url::fromUri($url);
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Builds a single output row.
   *
   * @return array<string, string>
   *   Row keyed by check, key, detail, status, message.
   */
  protected function row(string $check, string $key, string $detail, string $status, string $message): array {
    return [
      'check' => $check,
      'key' => $key,
      'detail' => $detail,
      'status' => $status,
      'message' => $message,
    ];
  }

  /**
   * Throws a command failure when --exit-non-zero is set and failures exist.
   *
   * Drush 12 derives the exit code from a thrown exception, mirroring
   * markaspot_health's HealthCommands::maybeFail().
   *
   * @param array<string, mixed> $options
   *   Command options.
   * @param int $failures
   *   Number of failed checks.
   */
  protected function maybeFail(array $options, int $failures): void {
    if (!empty($options['exit-non-zero']) && $failures > 0) {
      throw new \RuntimeException(sprintf('%d mail-render-test failure(s).', $failures));
    }
  }

}
