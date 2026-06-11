<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Form;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\markaspot_mail_inbound\Service\MailboxResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for the inbound mail pipeline.
 *
 * Pragmatic v1 (API-first module): the mailbox list is edited as a YAML
 * textarea with structural validation instead of a multi-fieldset UI. The
 * authoritative structure is documented in config/schema and the textarea
 * description below.
 *
 * @phpstan-consistent-constructor
 */
class MailInboundSettingsForm extends ConfigFormBase {

  /**
   * Whitelisted IMAP keys with their coercion callback.
   *
   * Only these keys are persisted, with the declared scalar type, so the
   * saved structure always matches the closed config schema.
   */
  private const IMAP_KEYS = [
    'host' => 'string',
    'port' => 'int',
    'encryption' => 'string',
    'username' => 'string',
    'password' => 'string',
    'folder' => 'string',
    'processed_folder' => 'string',
    'fetch_limit' => 'int',
  ];

  /**
   * Constructs the settings form.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_mail_inbound_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [MailboxResolver::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(MailboxResolver::CONFIG_NAME);

    $form['password_warning'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      'message' => [
        '#markup' => $this->t('<strong>IMAP passwords entered here are written to exportable configuration</strong> (config/sync, version control, container images). In production, leave the password empty and provide it out-of-band: set the environment variable <code>MARKASPOT_MAIL_INBOUND_PASSWORD_&lt;UPPERCASE_MAILBOX_ID&gt;</code> (or <code>MARKASPOT_MAIL_INBOUND_PASSWORD</code> for a single mailbox), or override it in settings.php.'),
      ],
    ];

    $form['mailboxes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Mailboxes (YAML)'),
      '#default_value' => Yaml::encode($config->get('mailboxes') ?: []),
      '#rows' => 18,
      '#description' => $this->t("A YAML sequence of mailbox definitions. Each mailbox needs: <code>id</code>, <code>label</code>, <code>enabled</code>, <code>imap</code> (host, port, encryption, username, password, folder, processed_folder, fetch_limit), <code>recipient_addresses</code> (matched against To/Cc/Delivered-To), <code>jurisdiction_gid</code> (the jur group id), <code>default_category_tid</code>, optional <code>sender_allowlist</code> / <code>sender_blocklist</code>.<br><strong>Leave the IMAP password empty in production</strong> and set it via the environment variable described above; a password committed here lands in config/sync and any image built from it."),
    ];

    $form['limits'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Limits'),
    ];
    $form['limits']['max_attachments'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum attachments per message'),
      '#default_value' => $config->get('max_attachments') ?? 5,
      '#min' => 0,
      '#required' => TRUE,
    ];
    $form['limits']['max_attachment_size_mb'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum attachment size (MB)'),
      '#default_value' => $config->get('max_attachment_size_mb') ?? 8,
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['limits']['allowed_mime_types'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed attachment MIME types'),
      '#default_value' => implode("\n", $config->get('allowed_mime_types') ?: []),
      '#rows' => 4,
      '#description' => $this->t('One MIME type per line. Types are verified by sniffing the file content, not by the declared header.'),
    ];
    $form['limits']['max_body_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum body length (characters)'),
      '#default_value' => $config->get('max_body_length') ?? 10000,
      '#min' => 100,
      '#required' => TRUE,
    ];
    $form['limits']['flood_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Flood limit (mails per sender)'),
      '#default_value' => $config->get('flood_limit') ?? 10,
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['limits']['flood_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Flood window (seconds)'),
      '#default_value' => $config->get('flood_window') ?? 3600,
      '#min' => 60,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $mailboxes = $this->decodeMailboxes((string) $form_state->getValue('mailboxes'), $error);
    if ($error !== NULL) {
      $form_state->setErrorByName('mailboxes', $this->t('Invalid mailbox definition: @error', ['@error' => $error]));
      return;
    }

    // Reject mailboxes that would silently drop every mail: a required
    // field_category with an unresolvable default_category_tid, or a
    // non-existent jurisdiction group.
    $referenceError = $this->validateMailboxReferences($mailboxes);
    if ($referenceError !== NULL) {
      $form_state->setErrorByName('mailboxes', $referenceError);
      return;
    }

    $form_state->set('decoded_mailboxes', $mailboxes);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $mimeTypes = array_values(array_filter(array_map(
      'trim',
      explode("\n", (string) $form_state->getValue('allowed_mime_types'))
    )));

    $this->config(MailboxResolver::CONFIG_NAME)
      ->set('mailboxes', $form_state->get('decoded_mailboxes') ?? [])
      ->set('max_attachments', (int) $form_state->getValue('max_attachments'))
      ->set('max_attachment_size_mb', (int) $form_state->getValue('max_attachment_size_mb'))
      ->set('allowed_mime_types', $mimeTypes)
      ->set('max_body_length', (int) $form_state->getValue('max_body_length'))
      ->set('flood_limit', (int) $form_state->getValue('flood_limit'))
      ->set('flood_window', (int) $form_state->getValue('flood_window'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Decodes the mailbox YAML into a clean, whitelisted, type-coerced list.
   *
   * Only known keys with their declared scalar types are kept, so the saved
   * structure always validates against the closed config schema (a stray key
   * or a port given as a string would otherwise break export and kernel
   * tests).
   *
   * @param string $yaml
   *   The YAML input.
   * @param string|null $error
   *   Receives a validation error message, NULL on success.
   *
   * @return array<int, array<string, mixed>>
   *   The decoded, sanitized mailbox list.
   */
  protected function decodeMailboxes(string $yaml, ?string &$error): array {
    $error = NULL;
    if (trim($yaml) === '') {
      return [];
    }
    try {
      $decoded = Yaml::decode($yaml);
    }
    catch (\Exception $e) {
      $error = $e->getMessage();
      return [];
    }
    if (!is_array($decoded)) {
      $error = 'Expected a YAML sequence of mailboxes.';
      return [];
    }
    $seenIds = [];
    $clean = [];
    foreach ($decoded as $index => $mailbox) {
      if (!is_array($mailbox)) {
        $error = "Entry $index is not a mapping.";
        return [];
      }
      $id = trim((string) ($mailbox['id'] ?? ''));
      if ($id === '' || !preg_match('/^[a-z0-9_]+$/', $id)) {
        $error = "Entry $index needs a machine id (lowercase letters, digits, underscores).";
        return [];
      }
      if (isset($seenIds[$id])) {
        $error = "Duplicate mailbox id '$id'.";
        return [];
      }
      $seenIds[$id] = TRUE;
      if (isset($mailbox['recipient_addresses']) && !is_array($mailbox['recipient_addresses'])) {
        $error = "Entry '$id': recipient_addresses must be a sequence.";
        return [];
      }
      foreach (['sender_allowlist', 'sender_blocklist'] as $listKey) {
        if (isset($mailbox[$listKey]) && !is_array($mailbox[$listKey])) {
          $error = "Entry '$id': $listKey must be a sequence.";
          return [];
        }
      }
      if (isset($mailbox['imap']) && !is_array($mailbox['imap'])) {
        $error = "Entry '$id': imap must be a mapping.";
        return [];
      }
      $clean[] = $this->sanitizeMailbox($id, $mailbox);
    }
    return $clean;
  }

  /**
   * Reduces a decoded mailbox to whitelisted, type-coerced keys only.
   *
   * @param string $id
   *   The already validated machine id.
   * @param array<string, mixed> $mailbox
   *   The raw decoded mailbox.
   *
   * @return array<string, mixed>
   *   The sanitized mailbox matching the config schema.
   */
  protected function sanitizeMailbox(string $id, array $mailbox): array {
    $clean = [
      'id' => $id,
      'label' => (string) ($mailbox['label'] ?? ''),
      'enabled' => (bool) ($mailbox['enabled'] ?? FALSE),
      'jurisdiction_gid' => (int) ($mailbox['jurisdiction_gid'] ?? 0),
      'default_category_tid' => (int) ($mailbox['default_category_tid'] ?? 0),
      'recipient_addresses' => $this->coerceStringList($mailbox['recipient_addresses'] ?? []),
      'sender_allowlist' => $this->coerceStringList($mailbox['sender_allowlist'] ?? []),
      'sender_blocklist' => $this->coerceStringList($mailbox['sender_blocklist'] ?? []),
    ];

    $imap = is_array($mailbox['imap'] ?? NULL) ? $mailbox['imap'] : [];
    $cleanImap = [];
    foreach (self::IMAP_KEYS as $key => $type) {
      if (!array_key_exists($key, $imap)) {
        continue;
      }
      $cleanImap[$key] = $type === 'int' ? (int) $imap[$key] : (string) $imap[$key];
    }
    if ($cleanImap !== []) {
      $clean['imap'] = $cleanImap;
    }

    return $clean;
  }

  /**
   * Coerces a value into a clean list of trimmed, non-empty strings.
   *
   * @param mixed $value
   *   The raw value (expected to be a sequence).
   *
   * @return string[]
   *   The cleaned list.
   */
  protected function coerceStringList(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    return array_values(array_filter(array_map(
      static fn($item): string => trim((string) $item),
      $value
    ), static fn(string $item): bool => $item !== ''));
  }

  /**
   * Validates that referenced category terms and jurisdictions exist.
   *
   * A required field_category combined with a missing or typo'd
   * default_category_tid would make every mail fail entity validation and get
   * dropped after the IMAP message was already flagged Seen, i.e. silent
   * total data loss. The same applies to a non-existent jurisdiction group.
   *
   * @param array<int, array<string, mixed>> $mailboxes
   *   The decoded mailbox list.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   An error message, or NULL when all references resolve.
   */
  protected function validateMailboxReferences(array $mailboxes): ?TranslatableMarkup {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $groupStorage = $this->entityTypeManager->hasDefinition('group')
      ? $this->entityTypeManager->getStorage('group')
      : NULL;

    foreach ($mailboxes as $mailbox) {
      $id = (string) $mailbox['id'];

      $tid = (int) ($mailbox['default_category_tid'] ?? 0);
      if ($tid > 0) {
        /** @var \Drupal\taxonomy\TermInterface|null $term */
        $term = $termStorage->load($tid);
        if ($term === NULL || $term->bundle() !== 'service_category') {
          return $this->t("Mailbox '@id': default_category_tid @tid is not an existing service_category term.", [
            '@id' => $id,
            '@tid' => $tid,
          ]);
        }
      }

      $gid = (int) ($mailbox['jurisdiction_gid'] ?? 0);
      if ($gid > 0 && $groupStorage !== NULL) {
        /** @var \Drupal\group\Entity\GroupInterface|null $group */
        $group = $groupStorage->load($gid);
        if ($group === NULL || $group->bundle() !== 'jur') {
          return $this->t("Mailbox '@id': jurisdiction_gid @gid is not an existing jur group.", [
            '@id' => $id,
            '@gid' => $gid,
          ]);
        }
      }
    }

    return NULL;
  }

}
