<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Form;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edits the default-language markaspot_mail.texts notification templates.
 *
 * Client projects are single-language: this form edits the site's default
 * language only. Per-locale wording (optional, not required) is edited
 * through core Config Translation at
 * /admin/config/regional/config-translation/markaspot_mail_texts, wired up
 * via markaspot_mail.config_translation.yml — the same
 * markaspot_mail.texts config object, no separate storage.
 *
 * One details group per notification key (report_confirmation, status_open,
 * status_closed, status_not_responsible), each exposing the six
 * MailTextResolver slots (subject, headline, intro, body_blocks, cta_label,
 * preheader). body_blocks is edited as a textarea, one paragraph per line;
 * NotificationTextBuilder renders each line as a separate <p> block.
 *
 * Tokens are resolved against the acted-upon service_request node
 * (['node' => $node]) exactly as ResubmissionRequestBuilder and the legacy
 * ECA texts did: [node:request_id], [node:field_category:entity:name],
 * [node:initial_status_note], etc. The jurisdiction footer
 * (field_email_footer) and Reply-To (field_jurisdiction_e_mail) are NOT
 * tokens here — MailBrandingService renders both automatically.
 */
final class MailTextsForm extends ConfigFormBase {

  private const CONFIG_NAME = 'markaspot_mail.texts';

  /**
   * Notification keys handled by this form, in display order.
   *
   * @var list<string>
   */
  private const KEYS = [
    'report_confirmation',
    'status_open',
    'status_closed',
    'status_not_responsible',
  ];

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_mail_texts_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);

    $form['token_help'] = $this->buildTokenHelp();

    foreach (self::KEYS as $key) {
      $slots = (array) ($config->get($key) ?? []);

      $form[$key] = [
        '#type' => 'details',
        '#title' => $this->t('@label (@key)', ['@label' => $this->keyLabel($key), '@key' => $key]),
        '#description' => $this->keyDescription($key),
        '#open' => TRUE,
        '#tree' => TRUE,
      ];

      $form[$key]['subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => (string) ($slots['subject'] ?? ''),
        '#maxlength' => 254,
      ];
      $form[$key]['headline'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Headline'),
        '#description' => $this->t('Bold heading at the top of the mail card.'),
        '#default_value' => (string) ($slots['headline'] ?? ''),
        '#maxlength' => 254,
      ];
      $form[$key]['intro'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Intro paragraph'),
        '#default_value' => (string) ($slots['intro'] ?? ''),
        '#rows' => 2,
      ];
      $form[$key]['body_blocks'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body paragraphs'),
        '#description' => $this->t('One paragraph per line.'),
        '#default_value' => implode("\n", array_map('strval', (array) ($slots['body_blocks'] ?? []))),
        '#rows' => 4,
      ];
      $form[$key]['cta_label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Call-to-action button label'),
        '#description' => $this->t('Leave empty to hide the button. When set, it links to the report itself.'),
        '#default_value' => (string) ($slots['cta_label'] ?? ''),
        '#maxlength' => 254,
      ];
      $form[$key]['preheader'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Preheader'),
        '#description' => $this->t('Short preview text shown in the inbox list, before the mail is opened.'),
        '#default_value' => (string) ($slots['preheader'] ?? ''),
        '#maxlength' => 254,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(self::CONFIG_NAME);

    foreach (self::KEYS as $key) {
      $values = (array) $form_state->getValue($key, []);
      $bodyBlocks = array_values(array_filter(
        array_map('trim', explode("\n", (string) ($values['body_blocks'] ?? ''))),
        static fn(string $line): bool => $line !== '',
      ));

      $config->set($key, [
        'subject' => trim((string) ($values['subject'] ?? '')),
        'headline' => trim((string) ($values['headline'] ?? '')),
        'intro' => trim((string) ($values['intro'] ?? '')),
        'body_blocks' => $bodyBlocks,
        'cta_label' => trim((string) ($values['cta_label'] ?? '')),
        'preheader' => trim((string) ($values['preheader'] ?? '')),
      ]);
    }

    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Builds the token help element: a browsable tree when possible.
   *
   * The "token" contrib module provides a #theme => 'token_tree_link'
   * render element with a searchable token browser; markaspot_mail does
   * not require it, so this degrades to a plain description referencing
   * the same node tokens the legacy ECA texts used.
   */
  private function buildTokenHelp(): array {
    if ($this->moduleHandler->moduleExists('token')) {
      return [
        '#theme' => 'token_tree_link',
        '#token_types' => ['node'],
        '#weight' => -10,
      ];
    }
    return [
      '#type' => 'container',
      '#weight' => -10,
      'text' => [
        '#markup' => $this->t('Available tokens include [node:request_id], [node:title], [node:field_category:entity:name], [node:initial_status_note], [node:url]. Enable the "token" module for a browsable token list.'),
      ],
    ];
  }

  /**
   * Human-readable fieldset label for a notification key.
   *
   * Literal $this->t() calls per key (rather than translating a value
   * pulled from an array) so Drupal's static string-extraction tooling
   * can discover every translatable string at scan time.
   */
  private function keyLabel(string $key): string {
    return match ($key) {
      'report_confirmation' => (string) $this->t('Report confirmation'),
      'status_open' => (string) $this->t('Status update: open / in progress'),
      'status_closed' => (string) $this->t('Status update: closed'),
      'status_not_responsible' => (string) $this->t('Status update: not responsible'),
      default => $key,
    };
  }

  /**
   * Helper description for a notification key's fieldset.
   *
   * Literal $this->t() calls per key for the same reason as keyLabel().
   */
  private function keyDescription(string $key): TranslatableMarkup|string {
    return match ($key) {
      'report_confirmation' => $this->t('Sent when a citizen submits a new report.'),
      'status_open' => $this->t('Sent when a report is forwarded to the responsible department and marked as being worked on.'),
      'status_closed' => $this->t('Sent when a report has been resolved.'),
      'status_not_responsible' => $this->t("Sent when a report is reviewed and found to be outside this jurisdiction's responsibility."),
      default => '',
    };
  }

}
