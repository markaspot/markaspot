<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Utility\Token;
use Drupal\field\FieldConfigInterface;
use Drupal\markaspot_dashboard\Service\Exception\MailTextsConflictException;
use Drupal\markaspot_dashboard\Service\Exception\MailTextsForbiddenException;
use Drupal\markaspot_dashboard\Service\Exception\MailTextsNotFoundException;
use Drupal\markaspot_group\Service\JurisdictionScopeValidator;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * {@inheritdoc}
 */
final class MailTextsService implements MailTextsServiceInterface {

  use StringTranslationTrait;

  private const CONFIG_NAME = 'markaspot_mail.texts';

  /**
   * Notification keys shipped in config/install/markaspot_mail.texts.yml.
   *
   * Never deletable; slots may be emptied. Mirrors
   * \Drupal\markaspot_mail\Service\EcaMailMigrator::KNOWN_KEYS and
   * \Drupal\markaspot_mail\Form\MailTextsForm::KEYS. Not read from those
   * classes directly to avoid a cross-service coupling for a four-item
   * list that changes only when markaspot_mail ships a new standard key
   * (which itself requires a schema + admin-form change in that module).
   */
  public const STANDARD_KEYS = [
    'report_confirmation',
    'status_open',
    'status_closed',
    'status_not_responsible',
  ];

  /**
   * The six wording slots every notification key carries.
   */
  private const SLOTS = ['subject', 'headline', 'intro', 'body_blocks', 'cta_label', 'preheader'];

  private const KEY_PATTERN = '/^[a-z0-9_]{3,64}$/';

  private const SUBJECT_MAX_LENGTH = 200;

  private const TEXT_MAX_LENGTH = 5000;

  private const BODY_BLOCKS_MAX_COUNT = 20;

  /**
   * Max length for the three short slots not already covered above.
   *
   * Mirrors the `#maxlength` already enforced client-side by
   * MailTextsForm's headline/cta_label/preheader textfields; this is the
   * server-side backstop for the API path that form does not cover.
   */
  private const SHORT_SLOTS_MAX_LENGTH = 254;

  /**
   * Max number of custom (non-standard) keys the config object may hold.
   *
   * A hard ceiling against unbounded growth (CWE-770): every key is a
   * top-level property of a single markaspot_mail.texts config object,
   * so there is otherwise no natural limit to how many a tenant_admin
   * could create through the API.
   */
  private const MAX_CUSTOM_KEYS = 50;

  /**
   * Field types rendered as a plain `[node:FIELD]` token.
   *
   * Entity-reference fields are handled separately (see buildTokenCatalog):
   * only a reference targeting taxonomy_term gets a token, as
   * `[node:FIELD:entity:name]`. Any other field type (file/image,
   * geolocation, entity_reference to node/media/group, paragraphs, ...) has
   * no defined token mapping in the pinned contract and is silently
   * skipped rather than emitting a token that resolves to a raw ID or
   * nothing useful.
   */
  private const SCALAR_FIELD_TYPES = [
    'string', 'string_long', 'text', 'text_long', 'text_with_summary',
    'email', 'telephone', 'integer', 'float', 'decimal', 'boolean',
    'list_string', 'list_integer', 'list_float', 'datetime', 'timestamp',
  ];

  /**
   * Field names excluded from the generated token catalog.
   *
   * `field_notification` is named explicitly in the contract. The rest are
   * this implementation's judgment call on "internal/technical fields":
   *   - Internal-only status/remark fields, distinct from the public
   *     `field_status` the citizen sees (curated separately below).
   *   - Service-provider-internal fields ("field_service_provider-Interna").
   *   - AI/triage metadata (sentiment, risk score, hazard, priority):
   *     staff-facing signals, not citizen-facing report content.
   *   - Inbound-mail plumbing (source, message ID) and GDPR consent
   *     bookkeeping: neither is meaningful mail wording content.
   *   - `field_escalation`/`field_jurisdiction`/`field_organisation`:
   *     structural group relations. MailBrandingService already resolves
   *     the jurisdiction footer and Reply-To automatically (see
   *     MailTextsForm's class doc); exposing them again as a raw token
   *     risks duplicated or conflicting content.
   *   - `field_geolocation`: a composite geofield value with no clean
   *     scalar token representation.
   *   - File/image/media reference fields: a raw token has no meaningful
   *     mail rendering for an attachment.
   *
   * A handful of these (the taxonomy-reference and structural-relation
   * ones) would otherwise be picked up by the type-based rules above, so
   * the blocklist is the actual gate for them, not a redundant belt.
   */
  private const FIELD_TOKEN_BLOCKLIST = [
    'field_notification',
    'field_object_id',
    // Curated separately as [node:field_status:entity:name] (Status group).
    'field_status',
    'field_status_internal',
    'field_status_internal_term',
    'field_internal_remark',
    'field_notes',
    'field_status_notes',
    'field_service_provider',
    'field_service_provider_notes',
    'field_service_provider_status',
    'field_service_provider_feedback',
    'field_service_provider_files',
    'field_reassign_sp',
    'field_boilerplates_sp',
    'field_sp_attachment',
    'field_sentiment',
    'field_risk_score',
    'field_hazard_category',
    'field_hazard_level',
    'field_priority',
    'field_source',
    'field_email_message_id',
    'field_gdpr',
    'field_escalation',
    'field_jurisdiction',
    'field_organisation',
    'field_geolocation',
    'field_attachment',
    'field_request_image',
    'field_request_media',
  ];

  /**
   * Scalar PII fields masked with a fixed placeholder in sample/preview.
   *
   * FindNewestServiceRequestNode() already scopes the sample node to the
   * caller's own jurisdiction(s), but a real citizen's contact data is
   * still not this staff member's business while they are only editing
   * mail wording, not handling that citizen's report. Applied in
   * maskPiiFields(), which clones the node before overwriting these
   * fields, so it never touches the entity storage's static cache or
   * the real record.
   *
   * NEVER applied on the send-time path (MailTextResolver /
   * NotificationTextBuilder use the real recipient node directly): the
   * whole point of personalization tokens is to resolve the actual
   * recipient's data at send time. This map exists only for this
   * service's sample-catalog and live-preview endpoints.
   */
  private const PII_FIELD_PLACEHOLDERS = [
    'field_e_mail' => 'erika.musterfrau@example.org',
    'field_first_name' => 'Erika',
    'field_last_name' => 'Musterfrau',
    'field_phone' => '+49 30 000000',
  ];

  /**
   * The composite address field, masked separately (not a scalar overwrite).
   */
  private const PII_ADDRESS_FIELD = 'field_address';

  /**
   * Placeholder address sub-property values for PII_ADDRESS_FIELD.
   */
  private const PII_ADDRESS_PLACEHOLDER = [
    'address_line1' => 'Musterstraße 1',
    'postal_code' => '12345',
    'locality' => 'Musterstadt',
    'country_code' => 'DE',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly Token $token,
    private readonly LoggerInterface $logger,
    TranslationInterface $string_translation,
    private readonly AccountInterface $currentUser,
    private readonly ?JurisdictionScopeValidator $jurisdictionScopeValidator = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function getCatalog(): array {
    $raw = (array) $this->configFactory->get(self::CONFIG_NAME)->getRawData();
    unset($raw['langcode']);

    $texts = [];
    foreach ($raw as $key => $slots) {
      if (!is_string($key) || !is_array($slots)) {
        continue;
      }
      $texts[$key] = $this->normalizeSlots($slots) + ['standard' => in_array($key, self::STANDARD_KEYS, TRUE)];
    }

    return [
      'texts' => $texts,
      'standard_keys' => self::STANDARD_KEYS,
      'tokens' => $this->buildTokenCatalog(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function saveText(string $key, array $slots, AccountInterface $account): array {
    if (preg_match(self::KEY_PATTERN, $key) !== 1) {
      throw new \InvalidArgumentException(sprintf('Key "%s" must match ^[a-z0-9_]{3,64}$.', $key));
    }
    $this->validateSlots($slots);

    $config = $this->configFactory->getEditable(self::CONFIG_NAME);
    $existing = $config->get($key);
    $created = !is_array($existing);

    // Standard keys are exempt: they ship in config/install and can only
    // be "created" here if that shipped config is missing (a broken
    // install), never through unbounded tenant_admin API usage.
    if ($created && !in_array($key, self::STANDARD_KEYS, TRUE)) {
      $this->assertCustomKeyLimitNotReached($config);
    }

    $current = $this->normalizeSlots(is_array($existing) ? $existing : []);
    foreach (self::SLOTS as $slot) {
      if (!array_key_exists($slot, $slots)) {
        continue;
      }
      $current[$slot] = $slot === 'body_blocks'
        ? array_values(array_map('strval', (array) $slots[$slot]))
        : trim((string) $slots[$slot]);
    }

    $config->set($key, $current);
    $config->save();

    $this->logger->info('Mail text "@key" @action by uid @uid.', [
      '@key' => $key,
      '@action' => $created ? 'created' : 'updated',
      '@uid' => $account->id(),
    ]);

    return [
      'key' => $key,
      'text' => $current + ['standard' => in_array($key, self::STANDARD_KEYS, TRUE)],
      'created' => $created,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function deleteText(string $key, AccountInterface $account): string {
    // Identical guard to saveText(): without it, a dotted key such as
    // "report_confirmation.body_blocks" resolves through Config's
    // NestedArray get()/clear() support to a *sub-slot* of a standard
    // key, while failing both the STANDARD_KEYS and ECA-reference string
    // comparisons below (they compare against the literal dotted string,
    // which matches nothing) — silently bypassing both delete guards.
    if (preg_match(self::KEY_PATTERN, $key) !== 1) {
      throw new \InvalidArgumentException(sprintf('Key "%s" must match ^[a-z0-9_]{3,64}$.', $key));
    }

    $config = $this->configFactory->getEditable(self::CONFIG_NAME);
    if (!is_array($config->get($key))) {
      throw new MailTextsNotFoundException(sprintf('Key "%s" does not exist.', $key));
    }
    if (in_array($key, self::STANDARD_KEYS, TRUE)) {
      throw new MailTextsForbiddenException('Standard keys cannot be deleted');
    }

    $referencedBy = $this->findEcaReferences($key);
    if ($referencedBy !== []) {
      throw new MailTextsConflictException(
        sprintf('Key "%s" is still referenced by an ECA action.', $key),
        $referencedBy,
      );
    }

    $config->clear($key)->save();

    $this->logger->info('Mail text "@key" deleted by uid @uid.', [
      '@key' => $key,
      '@uid' => $account->id(),
    ]);

    return $key;
  }

  /**
   * {@inheritdoc}
   */
  public function preview(array $input): array {
    $node = $this->findNewestServiceRequestNode();
    $tokenData = $node !== NULL ? ['node' => $node] : [];
    $options = ['clear' => TRUE];

    $bodyBlocks = [];
    if (array_key_exists('body_blocks', $input) && is_array($input['body_blocks'])) {
      $bodyBlocks = array_values(array_map(
        fn($block): string => (string) $this->token->replace((string) $block, $tokenData, $options),
        $input['body_blocks'],
      ));
    }

    return [
      'subject' => array_key_exists('subject', $input)
        ? (string) $this->token->replace((string) $input['subject'], $tokenData, $options)
        : '',
      'intro' => array_key_exists('intro', $input)
        ? (string) $this->token->replace((string) $input['intro'], $tokenData, $options)
        : '',
      'body_blocks' => $bodyBlocks,
      'sample_request_id' => $node !== NULL ? $this->nodeRequestId($node) : NULL,
    ];
  }

  /**
   * Validates a partial slots payload for saveText().
   *
   * @param array<string, mixed> $slots
   *   The raw slots payload to validate.
   *
   * @throws \InvalidArgumentException
   *   An unknown slot key, or a slot value failing its length/type rule.
   */
  private function validateSlots(array $slots): void {
    foreach (array_keys($slots) as $slot) {
      if (!in_array($slot, self::SLOTS, TRUE)) {
        throw new \InvalidArgumentException(sprintf('Unknown slot "%s".', $slot));
      }
    }

    if (array_key_exists('subject', $slots) && mb_strlen((string) $slots['subject']) > self::SUBJECT_MAX_LENGTH) {
      throw new \InvalidArgumentException(sprintf('subject exceeds the maximum length of %d characters.', self::SUBJECT_MAX_LENGTH));
    }
    if (array_key_exists('intro', $slots) && mb_strlen((string) $slots['intro']) > self::TEXT_MAX_LENGTH) {
      throw new \InvalidArgumentException(sprintf('intro exceeds the maximum length of %d characters.', self::TEXT_MAX_LENGTH));
    }
    foreach (['headline', 'cta_label', 'preheader'] as $shortSlot) {
      if (array_key_exists($shortSlot, $slots) && mb_strlen((string) $slots[$shortSlot]) > self::SHORT_SLOTS_MAX_LENGTH) {
        throw new \InvalidArgumentException(sprintf('%s exceeds the maximum length of %d characters.', $shortSlot, self::SHORT_SLOTS_MAX_LENGTH));
      }
    }

    if (!array_key_exists('body_blocks', $slots)) {
      return;
    }
    if (!is_array($slots['body_blocks'])) {
      throw new \InvalidArgumentException('body_blocks must be an array of strings.');
    }
    if (count($slots['body_blocks']) > self::BODY_BLOCKS_MAX_COUNT) {
      throw new \InvalidArgumentException(sprintf('body_blocks exceeds the maximum of %d entries.', self::BODY_BLOCKS_MAX_COUNT));
    }
    foreach ($slots['body_blocks'] as $block) {
      if (!is_scalar($block)) {
        throw new \InvalidArgumentException('body_blocks entries must be strings.');
      }
      if (mb_strlen((string) $block) > self::TEXT_MAX_LENGTH) {
        throw new \InvalidArgumentException(sprintf('body_blocks entries exceed the maximum length of %d characters.', self::TEXT_MAX_LENGTH));
      }
    }
  }

  /**
   * Guards against unbounded custom-key growth (CWE-770).
   *
   * @param \Drupal\Core\Config\Config $config
   *   The editable markaspot_mail.texts config, read (not yet saved).
   *
   * @throws \InvalidArgumentException
   *   The config already holds MAX_CUSTOM_KEYS non-standard keys.
   */
  private function assertCustomKeyLimitNotReached(Config $config): void {
    $raw = (array) $config->getRawData();
    unset($raw['langcode']);
    $customKeyCount = count(array_diff(array_keys($raw), self::STANDARD_KEYS));

    if ($customKeyCount >= self::MAX_CUSTOM_KEYS) {
      throw new \InvalidArgumentException(sprintf(
        'The maximum number of custom mail texts (%d) has been reached.',
        self::MAX_CUSTOM_KEYS,
      ));
    }
  }

  /**
   * Fills in all six slots with defaults, coercing stored config values.
   *
   * @param array<string, mixed> $raw
   *   A raw slot_set array as read from config (may be partial).
   *
   * @return array{subject: string, headline: string, intro: string, body_blocks: list<string>, cta_label: string, preheader: string}
   *   All six slots, always present.
   */
  private function normalizeSlots(array $raw): array {
    $result = [];
    foreach (self::SLOTS as $slot) {
      if ($slot === 'body_blocks') {
        $blocks = $raw[$slot] ?? [];
        $result[$slot] = is_array($blocks) ? array_values(array_map('strval', $blocks)) : [];
        continue;
      }
      $result[$slot] = (string) ($raw[$slot] ?? '');
    }
    /** @var array{subject: string, headline: string, intro: string, body_blocks: list<string>, cta_label: string, preheader: string} $result */
    return $result;
  }

  /**
   * Builds the token catalog: curated extras plus generated field tokens.
   *
   * @return list<array<string, string>>
   *   Token entries, each with token/label/group/sample.
   */
  private function buildTokenCatalog(): array {
    $node = $this->findNewestServiceRequestNode();

    $reportGroup = (string) $this->t('Report');
    $statusGroup = (string) $this->t('Status');

    $tokens = [
      $this->tokenEntry('[node:request_id]', (string) $this->t('Report ID'), $reportGroup, $node),
      $this->tokenEntry('[node:title]', (string) $this->t('Title'), $reportGroup, $node),
      $this->tokenEntry('[node:initial_status_note]', (string) $this->t('Initial status note'), $statusGroup, $node),
      $this->tokenEntry('[node:field_status:entity:name]', (string) $this->t('Status'), $statusGroup, $node),
    ];

    foreach ($this->entityFieldManager->getFieldDefinitions('node', 'service_request') as $fieldName => $definition) {
      if (!$definition instanceof FieldConfigInterface || !str_starts_with($fieldName, 'field_')) {
        continue;
      }
      if (in_array($fieldName, self::FIELD_TOKEN_BLOCKLIST, TRUE)) {
        continue;
      }

      $type = $definition->getType();
      if ($type === 'entity_reference' && $definition->getSetting('target_type') === 'taxonomy_term') {
        $tokenPattern = sprintf('[node:%s:entity:name]', $fieldName);
      }
      elseif (in_array($type, self::SCALAR_FIELD_TYPES, TRUE)) {
        $tokenPattern = sprintf('[node:%s]', $fieldName);
      }
      else {
        continue;
      }

      $tokens[] = $this->tokenEntry($tokenPattern, (string) $definition->getLabel(), $reportGroup, $node);
    }

    return $tokens;
  }

  /**
   * Builds one token catalog entry, resolving its sample against $node.
   */
  private function tokenEntry(string $tokenPattern, string $label, string $group, ?NodeInterface $node): array {
    return [
      'token' => $tokenPattern,
      'label' => $label,
      'group' => $group,
      'sample' => $node !== NULL
        ? (string) $this->token->replace($tokenPattern, ['node' => $node], ['clear' => TRUE])
        : '',
    ];
  }

  /**
   * Finds every eca.eca.* model referencing $key as a notification_key.
   *
   * Scans ALL eca.eca.* configs, active or not: a disabled model can be
   * re-enabled later, so a disabled reference still blocks deletion. This
   * is deliberately broader than EcaMailMigrator::analyze(), which only
   * looks at active models for its own migration-suggestion purpose.
   *
   * @return list<string>
   *   Sorted, deduplicated eca.eca.* model IDs (the config `id` property).
   */
  private function findEcaReferences(string $key): array {
    $models = [];
    foreach ($this->configFactory->listAll('eca.eca.') as $configName) {
      $raw = $this->configFactory->get($configName)->getRawData();
      foreach ((array) ($raw['actions'] ?? []) as $action) {
        if (!is_array($action) || ($action['plugin'] ?? NULL) !== 'markaspot_mail_send_notification') {
          continue;
        }
        if ((string) ($action['configuration']['notification_key'] ?? '') === $key) {
          $models[] = (string) ($raw['id'] ?? $configName);
          break;
        }
      }
    }
    $models = array_values(array_unique($models));
    sort($models);
    return $models;
  }

  /**
   * Finds the most recently created service_request node, if any.
   *
   * AccessCheck(FALSE): this is a staff-only preview/sample data source
   * gated by the 'administer markaspot mail texts' permission, exactly
   * like \Drupal\markaspot_mail\Mail\MailSampleContextProvider's own
   * sample lookups — not a citizen-facing read path. accessCheck(FALSE) is
   * deliberate rather than a shortcut: gnode grants for service_request do
   * not reliably scope by jurisdiction (see the lesson recorded as
   * lesson-jsonapi-jur-outsider-pii-cross-jurisdiction in project memory),
   * so this method filters explicitly by field_jurisdiction below instead
   * of trusting node grants to do it.
   *
   * 'administer markaspot mail texts' is a global Drupal permission (see
   * user.role.tenant_admin.yml), not scoped per Group: any tenant_admin,
   * regardless of which jurisdiction they administer, can reach this
   * method. Non-platform-admin callers are therefore restricted to nodes
   * in a jurisdiction they are a direct member of; the returned node also
   * has its PII fields masked (see maskPiiFields()) since even an
   * in-scope citizen's contact data is not this endpoint's business.
   */
  private function findNewestServiceRequestNode(): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, 1);

    if (!$this->currentUserCanSeeAllJurisdictions()) {
      $allowedJurisdictionIds = $this->jurisdictionScopeValidator
        ?->getAllowedJurisdictionIds($this->currentUser) ?? [];
      if ($allowedJurisdictionIds === []) {
        return NULL;
      }
      $query->condition('field_jurisdiction', $allowedJurisdictionIds, 'IN');
    }

    $ids = $query->execute();
    if ($ids === []) {
      return NULL;
    }
    $node = $storage->load(reset($ids));
    return $node instanceof NodeInterface ? $this->maskPiiFields($node) : NULL;
  }

  /**
   * Checks whether the current user may see samples from any jurisdiction.
   *
   * Mirrors \Drupal\markaspot_dashboard\Controller\DashboardController::
   * currentUserCanSeeAllJurisdictions(); duplicated rather than shared
   * because the two classes have no common base and the check is three
   * lines, exactly like nodeRequestId() below.
   */
  private function currentUserCanSeeAllJurisdictions(): bool {
    return (int) $this->currentUser->id() === 1
      || $this->currentUser->hasPermission('administer nodes')
      || $this->currentUser->hasPermission('administer site configuration');
  }

  /**
   * Clones $node and overwrites its PII fields with fixed placeholders.
   *
   * Defense in depth alongside the jurisdiction scope in
   * findNewestServiceRequestNode(): the sample/preview node is real
   * citizen data otherwise, regardless of how tightly it is scoped.
   * Clones first so the mutation never touches the entity storage's
   * static cache or the original loaded node.
   */
  private function maskPiiFields(NodeInterface $node): NodeInterface {
    $masked = clone $node;
    foreach (self::PII_FIELD_PLACEHOLDERS as $fieldName => $placeholder) {
      if ($masked->hasField($fieldName)) {
        $masked->set($fieldName, $placeholder);
      }
    }
    if ($masked->hasField(self::PII_ADDRESS_FIELD)) {
      $masked->set(self::PII_ADDRESS_FIELD, self::PII_ADDRESS_PLACEHOLDER);
    }
    return $masked;
  }

  /**
   * The node's citizen-facing request_id, falling back to the raw nid.
   *
   * Mirrors \Drupal\markaspot_dashboard\Controller\SplitController::
   * nodeRequestId(); duplicated rather than shared because the two classes
   * have no common base and the logic is three lines.
   */
  private function nodeRequestId(NodeInterface $node): string {
    if ($node->hasField('request_id') && !$node->get('request_id')->isEmpty()) {
      return (string) $node->get('request_id')->value;
    }
    return (string) $node->id();
  }

}
