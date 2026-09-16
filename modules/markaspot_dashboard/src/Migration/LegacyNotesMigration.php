<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Migration;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Explicit offline, resumable migration; never an automatic update hook.
 *
 * @internal
 */
final class LegacyNotesMigration {

  private const COLLECTION = 'markaspot_legacy_notes_v1';

  /**
   * Constructs the migration without registering any runtime service.
   */
  public function __construct(
    private readonly ContainerInterface $container,
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Validates the target and rejects potentially broader reader permissions.
   */
  public function preflight(int $jurisdiction, bool $apply = FALSE): void {
    if (!$this->container->get('module_handler')->moduleExists('markaspot_dashboard')) {
      throw new \RuntimeException('The dashboard access guards must be enabled.');
    }
    if ($apply && $this->container->get('module_handler')->moduleExists('markaspot_ai')
      && !defined('Drupal\\markaspot_ai\\Service\\AttributeFillingService::LEGACY_NOTES_POLICY_VERSION')) {
      throw new \RuntimeException('Deploy the legacy-remark AI exclusion before applying.');
    }
    $group = $this->entityTypeManager->getStorage('group')->load($jurisdiction);
    if (!$group || $group->bundle() !== 'jur') {
      throw new \InvalidArgumentException('Select an existing jurisdiction.');
    }
    $manager = $this->container->get('entity_field.manager');
    $fields = $manager->getFieldDefinitions('node', 'service_request');
    $paragraph_fields = $manager->getFieldDefinitions('paragraph', 'internal_remark');
    if (($fields['field_notes'] ?? NULL)?->getType() !== 'text_long'
      || $fields['field_notes']->getFieldStorageDefinition()->getCardinality() !== 1
      || ($fields['field_internal_remark'] ?? NULL)?->getType() !== 'entity_reference_revisions'
      || $fields['field_internal_remark']->getSetting('target_type') !== 'paragraph'
      || $fields['field_internal_remark']->getFieldStorageDefinition()->getCardinality() !== -1
      || ($paragraph_fields['field_internal_remark_text'] ?? NULL)?->getType() !== 'text_long'
      || ($paragraph_fields['field_author'] ?? NULL)?->getSetting('target_type') !== 'user') {
      throw new \RuntimeException('Unsupported legacy or internal remark field schema.');
    }
    $source_storage = $this->entityTypeManager->getStorage('field_storage_config')->load('node.field_notes');
    $permission_type = $source_storage?->getThirdPartySetting('field_permissions', 'permission_type', 'public');
    if (!in_array($permission_type, ['public', 'custom'], TRUE)) {
      throw new \RuntimeException('Source field requires an individual access-policy review.');
    }
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $role) {
      if ($role->isAdmin()) {
        continue;
      }
      if ($role->hasPermission('view field_internal_remark')
        && ($role->id() === 'anonymous' || ($permission_type === 'custom'
          && !$role->hasPermission('view field_notes')))) {
        throw new \RuntimeException('Target readership is broader than source readership: role ' . $role->id());
      }
    }
  }

  /**
   * Returns a bounded page of candidates, without loading or printing text.
   */
  public function candidates(int $jurisdiction, int $after, int $limit, array $ids = []): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)->condition('type', 'service_request')
      ->condition('nid', $after, '>')->sort('nid')->range(0, $limit);
    if ($ids !== []) {
      // Explicit IDs are checked individually, including empty/foreign reports.
      $query->condition('nid', $ids, 'IN');
    }
    else {
      $query->condition('field_jurisdiction.target_id', $jurisdiction)
        ->condition('field_notes.value', '', '<>');
    }
    return array_map('intval', array_values($query->execute()));
  }

  /**
   * Batches read-only entity loading; write runs always reload under a lock.
   */
  public function preparePlanBatch(array $ids): void {
    if (count($ids) > 100) {
      throw new \InvalidArgumentException('Plan preload is limited to 100 reports.');
    }
    $storage = $this->entityTypeManager->getStorage('node');
    // Bound PHP memory without flushing the site's shared persistent cache.
    $this->container->get('entity.memory_cache')->deleteAll();
    $storage->resetCache($ids);
    $storage->loadMultiple($ids);
  }

  /**
   * Inspects or migrates one node. Apply requires an externally frozen writer.
   *
   * No note text, authors, addresses or other citizen data enter the result.
   */
  public function process(int $nid, int $jurisdiction, bool $apply = FALSE, string $label = 'Imported legacy note; author and original date unknown.'): array {
    $transaction = NULL;
    $lock = $this->container->get('lock');
    $lock_name = self::COLLECTION . ':' . $nid;
    if ($apply && !$lock->acquire($lock_name, 120)) {
      throw new \RuntimeException('Another migration is processing this report.');
    }
    try {
      if ($apply) {
        $transaction = $this->database->startTransaction();
        // Also serialize concurrent migrations even if lock backends differ.
        $this->database->select('node', 'n')->fields('n', ['nid'])
          ->condition('nid', $nid)->forUpdate()->execute()->fetchField();
      }
      $storage = $this->entityTypeManager->getStorage('node');
      $node = $apply ? $storage->loadUnchanged($nid) : $storage->load($nid);
      if (!$node instanceof NodeInterface || $node->bundle() !== 'service_request') {
        throw new \RuntimeException('Not a service request.');
      }
      $scope = array_map('intval', array_column($node->get('field_jurisdiction')->getValue(), 'target_id'));
      if ($scope !== [$jurisdiction]) {
        throw new \RuntimeException('Report is outside the exclusive selected jurisdiction.');
      }
      if (count($node->getTranslationLanguages()) !== 1
        || (int) $storage->getLatestRevisionId($nid) !== (int) $node->getRevisionId()) {
        throw new \RuntimeException('Translated reports or pending revisions require individual review.');
      }
      $source = $node->get('field_notes')->getValue();
      $raw = (string) ($source[0]['value'] ?? '');
      $format = $source[0]['format'] ?? NULL;
      $result = ['nid' => $nid, 'format' => $format, 'status' => 'empty'];
      if (!in_array($format, [NULL, '', 'plain_text', 'basic_html'], TRUE)) {
        throw new \RuntimeException('Unsupported source text format.');
      }
      $source_hash = hash('sha256', serialize([$node->uuid(), $node->language()->getId(), $source]));
      $record = $this->readRecord($node->uuid());
      if ($record !== NULL) {
        $this->verifyRecord($node, $record, $source_hash);
        return array_replace($result, ['status' => 'already_migrated']);
      }
      if ($raw === '') {
        return $result;
      }
      $result['status'] = 'would_migrate';
      if (!$apply) {
        return $result;
      }
      $original = $node;
      $node = clone $original;
      $before = $this->snapshot($node);
      $previous_revision = (int) $node->getRevisionId();
      $now = $this->container->get('datetime.time')->getCurrentTime();
      $text = $label . "\n" . gmdate('Y-m-d H:i:s', $now) . " UTC\n\n"
        . ($format === 'basic_html' ? MailFormatHelper::htmlToText($raw) : $raw);
      // Construct directly: even create hooks may trigger tenant automation.
      $paragraph = new Paragraph([], 'paragraph', 'internal_remark');
      $paragraph_values = [
        'type' => 'internal_remark',
        'uuid' => $this->container->get('uuid')->generate(),
        'langcode' => $node->language()->getId(),
        'default_langcode' => 1,
        'status' => 1,
        'created' => $now,
        // Preserve the old field's exclusion from AI form-assist prompts.
        'behavior_settings' => serialize(['markaspot_legacy_notes' => ['exclude_from_ai' => TRUE]]),
        'parent_type' => 'node',
        'parent_id' => (string) $nid,
        'parent_field_name' => 'field_internal_remark',
        'field_author' => [],
        'field_internal_remark_text' => ['value' => $text, 'format' => 'plain_text'],
      ];
      foreach ($paragraph_values as $name => $value) {
        $paragraph->set($name, $value);
      }
      $paragraph->enforceIsNew();
      $paragraph->setNewRevision(TRUE);
      $paragraph->isDefaultRevision(TRUE);
      $this->writer('paragraph')->writeSnapshot($paragraph);
      $node->get('field_internal_remark')->appendItem([
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ]);
      $node->setOriginal($original);
      $node->setNewRevision(TRUE);
      $node->isDefaultRevision(TRUE);
      $node->setRevisionUserId(0);
      $node->setRevisionCreationTime($now);
      $node->setRevisionLogMessage('Legacy field_notes migrated to an internal remark. Original content retained.');
      $this->writer('node')->writeSnapshot($node);
      $storage->resetCache([$nid]);
      $fresh = $storage->loadUnchanged($nid);
      if ($before !== $this->snapshot($fresh)) {
        throw new \RuntimeException('Report fields changed outside the migration allowlist.');
      }
      $expected_references = $node->get('field_internal_remark')->getValue();
      if ($fresh->get('field_internal_remark')->getValue() != $expected_references) {
        throw new \RuntimeException('Internal remark references did not persist.');
      }
      $record = [
        'source_hash' => $source_hash,
        'paragraph_id' => (int) $paragraph->id(),
        'paragraph_uuid' => $paragraph->uuid(),
        'paragraph_revision_id' => (int) $paragraph->getRevisionId(),
        'text_hash' => hash('sha256', $text),
        'target_hash' => hash('sha256', serialize($this->snapshot(
          $this->entityTypeManager->getStorage('paragraph')->loadUnchanged($paragraph->id())
        ))),
        'previous_revision' => $previous_revision,
        'migration_revision' => (int) $fresh->getRevisionId(),
      ];
      $this->verifyRecord($fresh, $record, $source_hash);
      // Same database transaction as both entities. No external KV backend.
      $this->database->insert('key_value')->fields([
        'collection' => self::COLLECTION,
        'name' => $node->uuid(),
        'value' => serialize($record),
      ])->execute();
      unset($transaction);
      $storage->resetCache([$nid]);
      $this->entityTypeManager->getStorage('paragraph')->resetCache([(int) $paragraph->id()]);
      Cache::invalidateTags(array_merge(
        $node->getCacheTagsToInvalidate(),
        $node->getEntityType()->getListCacheTags(),
        $paragraph->getEntityType()->getListCacheTags(),
        ['4xx-response'],
      ));
      $this->container->get('database.replica_kill_switch')->trigger();
      return array_replace($result, ['status' => 'migrated', 'revision' => $record['migration_revision']]);
    }
    catch (\Throwable $error) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      $this->entityTypeManager->getStorage('node')->resetCache([$nid]);
      $this->entityTypeManager->getStorage('paragraph')->resetCache();
      throw $error;
    }
    finally {
      if ($apply) {
        $lock->release($lock_name);
      }
    }
  }

  /**
   * Loads the durable migration marker, failing closed on corrupt data.
   */
  private function readRecord(string $uuid): ?array {
    $value = $this->database->select('key_value', 'k')->fields('k', ['value'])
      ->condition('collection', self::COLLECTION)->condition('name', $uuid)
      ->execute()->fetchField();
    if ($value === FALSE) {
      return NULL;
    }
    $record = unserialize($value, ['allowed_classes' => FALSE]);
    if (!is_array($record)) {
      throw new \RuntimeException('Invalid migration marker.');
    }
    return $record;
  }

  /**
   * Checks source and target, never recreating a removed or edited remark.
   */
  private function verifyRecord(NodeInterface $node, array $record, string $hash): void {
    $references = [];
    foreach ($node->get('field_internal_remark')->getValue() as $reference) {
      $references[(int) $reference['target_id']] = (int) $reference['target_revision_id'];
    }
    $paragraph = $this->entityTypeManager->getStorage('paragraph')->loadUnchanged($record['paragraph_id'] ?? 0);
    if (($record['source_hash'] ?? NULL) !== $hash
      || ($references[$record['paragraph_id'] ?? 0] ?? NULL) !== ($record['paragraph_revision_id'] ?? NULL)
      || !$paragraph || $paragraph->uuid() !== ($record['paragraph_uuid'] ?? NULL)
      || (int) $paragraph->getRevisionId() !== ($record['paragraph_revision_id'] ?? NULL)
      || $paragraph->get('field_internal_remark_text')->format !== 'plain_text'
      || !$paragraph->get('field_author')->isEmpty()
      || hash('sha256', serialize($this->snapshot($paragraph))) !== ($record['target_hash'] ?? NULL)
      || hash('sha256', (string) $paragraph->get('field_internal_remark_text')->value) !== ($record['text_hash'] ?? NULL)
      || $paragraph->get('parent_type')->value !== 'node'
      || (int) $paragraph->get('parent_id')->value !== (int) $node->id()
      || $paragraph->get('parent_field_name')->value !== 'field_internal_remark') {
      throw new \RuntimeException('Migration conflict: source, target or reference changed.');
    }
  }

  /**
   * Captures persisted fields outside the small migration allowlist.
   */
  private function snapshot(ContentEntityInterface $entity): array {
    $snapshot = [];
    $allowed = [
      'vid', 'revision_timestamp', 'revision_uid', 'revision_log',
      'revision_default', 'revision_translation_affected', 'field_internal_remark',
    ];
    foreach ($entity->getFields() as $name => $items) {
      if (!$items->getFieldDefinition()->isComputed() && !in_array($name, $allowed, TRUE)) {
        $snapshot[$name] = $items->getValue();
      }
    }
    return $snapshot;
  }

  /**
   * Creates an isolated writer without changing registered storage handlers.
   */
  private function writer(string $type): LegacyNotesNodeStorage|LegacyNotesParagraphStorage {
    $class = $type === 'node' ? LegacyNotesNodeStorage::class : LegacyNotesParagraphStorage::class;
    return $class::createInstance($this->container, $this->entityTypeManager->getDefinition($type));
  }

}
