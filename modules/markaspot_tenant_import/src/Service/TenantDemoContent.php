<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;

/**
 * Explicit synthetic fixtures for an already configured test jurisdiction.
 *
 * This service never changes schema, roles, memberships or configuration.
 */
final class TenantDemoContent {

  /**
   * Scalar fields that are deliberately supported by the fixture contract.
   */
  private const SCALARS = [
    'body', 'field_address', 'field_geolocation', 'field_first_name',
    'field_last_name', 'field_e_mail', 'field_phone', 'field_notification',
    'field_notes', 'field_object_id', 'field_priority',
    'field_approved', 'field_source', 'field_feedback',
    'field_service_provider_feedback', 'field_hazard_level',
    'field_request_attributes',
  ];

  /**
   * Known former fields that must never imply current support.
   */
  private const RETIRED = ['field_request_image', 'field_status_internal', 'field_gdpr'];

  /**
   * Constructs the fixture service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly EntityFieldManagerInterface $fields,
    private readonly ConfigFactoryInterface $config,
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
    private readonly Connection $database,
    private readonly FileSystemInterface $fileSystem,
    private readonly GeoreportProcessorServiceInterface $processor,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Validates bounded, explicitly synthetic JSON before any entity operation.
   */
  public static function validateFixture(array $fixture): void {
    if (array_diff(array_keys($fixture), ['version', 'synthetic', 'fixture_id', 'requests'])) {
      throw new \RuntimeException('Unknown fixture properties.');
    }
    if (($fixture['version'] ?? NULL) !== 1 || ($fixture['synthetic'] ?? NULL) !== TRUE
      || !is_string($fixture['fixture_id'] ?? NULL)
      || !preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $fixture['fixture_id'])
      || !is_array($fixture['requests'] ?? NULL)
      || !array_is_list($fixture['requests']) || count($fixture['requests']) < 1 || count($fixture['requests']) > 20) {
      throw new \RuntimeException('Expected a bounded v1 synthetic fixture with a stable fixture_id.');
    }
    $keys = [];
    foreach ($fixture['requests'] as $row) {
      if (!is_array($row) || !is_string($row['key'] ?? NULL) || !preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $row['key']) || isset($keys[$row['key']])) {
        throw new \RuntimeException('Request fixture keys must be unique stable identifiers.');
      }
      $keys[$row['key']] = TRUE;
      if (array_diff(array_keys($row), [
        'key',
        'title',
        'category',
        'status',
        'fields',
        'organisations',
        'assignee_email',
        'internal_status',
        'status_history',
        'internal_remarks',
        'files',
      ])) {
        throw new \RuntimeException('Unknown request fixture properties.');
      }
      foreach (['title', 'category', 'status'] as $key) {
        if (!is_string($row[$key] ?? NULL) || trim($row[$key]) === '' || strlen($row[$key]) > 255) {
          throw new \RuntimeException('Every fixture needs bounded title, category and status labels.');
        }
      }
      if (!is_array($row['fields'] ?? NULL) || array_diff(array_keys($row['fields']), self::SCALARS)) {
        throw new \RuntimeException('Unknown or non-scalar fixture field; use the scoped reference contract.');
      }
      $values = $row['fields'];
      if (isset($values['field_e_mail']) && (!is_string($values['field_e_mail']) || !preg_match('/^[a-zA-Z0-9.+_-]+@example\.invalid$/D', $values['field_e_mail']))) {
        throw new \RuntimeException('Synthetic reporter email must use example.invalid.');
      }
      foreach (['field_first_name', 'field_last_name'] as $field) {
        if (isset($values[$field]) && !in_array($values[$field], ['Demo', 'Test', 'Synthetic'], TRUE)) {
          throw new \RuntimeException('Reporter names must be Demo, Test or Synthetic.');
        }
      }
      if (isset($values['field_phone']) && $values['field_phone'] !== '+49 000 000000') {
        throw new \RuntimeException('Use the documented synthetic telephone placeholder.');
      }
      if (isset($values['field_notification']) && $values['field_notification'] !== FALSE) {
        throw new \RuntimeException('Synthetic fixtures must disable citizen notification.');
      }
      foreach (['organisations', 'status_history', 'internal_remarks', 'files'] as $key) {
        if (isset($row[$key]) && (!is_array($row[$key]) || !array_is_list($row[$key]) || count($row[$key]) > 10)) {
          throw new \RuntimeException('Fixture collections must be bounded arrays.');
        }
      }
      foreach (['status_history', 'internal_remarks', 'files'] as $key) {
        foreach ($row[$key] ?? [] as $item) {
          if (!is_array($item)) {
            throw new \RuntimeException('Fixture collection entries must be objects.');
          }
        }
      }
      if (empty($row['status_history'])) {
        throw new \RuntimeException('Supply explicit synthetic status history.');
      }
    }
  }

  /**
   * Stable identifiers bind fixture keys to the exact installation and group.
   */
  public static function fixtureUuid(string $siteUuid, string $jurUuid, string $key): string {
    $hex = hash('sha256', $siteUuid . ':' . $jurUuid . ':' . $key);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3) . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
  }

  /**
   * Plans or creates a fixture; existing edited fixtures are never overwritten.
   */
  public function seed(array $fixture, string $assets, string $expectedSite, int $jurisdictionId, bool $confirmedTest, bool $apply = FALSE): array {
    self::validateFixture($fixture);
    if (!$confirmedTest || getenv('MARKASPOT_DEPLOY_CONTEXT') !== 'nonproduction' || getenv('MARKASPOT_MAIL_MODE') !== 'mailpit') {
      throw new \RuntimeException('Demo content requires explicit test confirmation, nonproduction context and Mailpit.');
    }
    $siteUuid = (string) $this->config->get('system.site')->get('uuid');
    if ($expectedSite === '' || $siteUuid !== $expectedSite) {
      throw new \RuntimeException('Expected site UUID does not match this installation.');
    }
    $jur = $this->entities->getStorage('group')->load($jurisdictionId);
    if (!$jur instanceof GroupInterface || $jur->bundle() !== 'jur' || ($jur->hasField('field_parent_jurisdiction') && !$jur->get('field_parent_jurisdiction')->isEmpty())) {
      throw new \RuntimeException('An existing root jurisdiction is required.');
    }
    $key = 'markaspot_tenant_import.demo_content.' . $jurisdictionId . '.' . $fixture['fixture_id'];
    $inputHash = hash('sha256', json_encode($fixture, JSON_THROW_ON_ERROR));
    $binding = ['site_uuid' => $siteUuid, 'jurisdiction_uuid' => $jur->uuid(), 'input_sha256' => $inputHash];
    if (!$this->lock->acquire($key, 900)) {
      throw new \RuntimeException('Another fixture operation holds the lock.');
    }
    try {
      $existing = $this->state->get($key);
      if ($existing !== NULL) {
        return $this->verifyExisting($existing, $binding);
      }
      $plans = [];
      foreach ($fixture['requests'] as $row) {
        $id = self::fixtureUuid($siteUuid, $jur->uuid(), $fixture['fixture_id'] . ':' . $row['key']);
        if ($this->entities->getStorage('node')->loadByProperties(['uuid' => $id])) {
          throw new \RuntimeException('An unowned node already uses a fixture UUID; no adoption permitted.');
        }
        $plans[] = $this->planRequest($row, $jur, $id, $assets);
      }
      $coverage = $this->coverage($plans);
      $result = [
        'site_uuid' => $siteUuid,
        'jurisdiction_id' => $jurisdictionId,
        'fixture_id' => $fixture['fixture_id'],
        'applied' => FALSE,
        'action' => 'preview',
        'requests' => count($plans),
        'field_coverage' => $coverage,
        'related_field_coverage' => $this->relatedCoverage($plans),
        'functional_acceptance' => 'pending',
      ];
      if (!$apply) {
        return $result;
      }
      // Persist before the DB transaction: files and hook effects are not all
      // transactional. An interrupted run needs explicit operator recovery.
      $this->state->set($key, ['binding' => $binding, 'stage' => 'started']);
      $transaction = $this->database->startTransaction();
      $created = [];
      try {
        foreach ($plans as $plan) {
          $this->saveRequest($plan, $created);
        }
        unset($transaction);
      }
      catch (\Throwable $error) {
        $transaction->rollBack();
        throw new \RuntimeException('Demo creation failed; partial fixture state requires explicit recovery. No retry or overwrite was attempted.', 0, $error);
      }
      $evidence = [];
      foreach ($created as $entity) {
        $fresh = $this->entities->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
        $evidence[] = $this->fingerprint($fresh);
      }
      $result['applied'] = TRUE;
      $result['action'] = 'created';
      $this->state->set($key, [
        'binding' => $binding,
        'stage' => 'complete',
        'entities' => $evidence,
        'result' => $result,
      ]);
      return $result;
    }
    finally {
      $this->lock->release($key);
    }
  }

  /**
   * Builds unsaved entities and validates ordinary field/entity constraints.
   */
  private function planRequest(array $row, GroupInterface $jur, string $uuid, string $assets): array {
    $values = [
      'type' => 'service_request',
      'uuid' => $uuid,
      'title' => '[DEMO] ' . $row['title'],
      'uid' => 0,
      'status' => 1,
      'field_jurisdiction' => $jur->id(),
    ];
    $values['field_category'] = $this->term('service_category', $row['category'], (int) $jur->id())->id();
    $values['field_status'] = $this->term('service_status', $row['status'], (int) $jur->id())->id();
    $definitions = $this->fields->getFieldDefinitions('node', 'service_request');
    foreach ($row['fields'] as $field => $value) {
      if (!isset($definitions[$field])) {
        throw new \RuntimeException('Requested fixture field is absent: ' . $field);
      }
      $type = $definitions[$field]->getType();
      if (in_array($type, ['text', 'text_long', 'text_with_summary'], TRUE)) {
        if (!is_string($value)) {
          throw new \RuntimeException('Formatted text fixtures must be plain strings.');
        }
        $value = ['value' => $value, 'format' => 'plain_text'];
      }
      elseif (!in_array($type, [
        'string',
        'string_long',
        'email',
        'telephone',
        'boolean',
        'integer',
        'list_integer',
        'list_string',
        'address',
        'geolocation',
      ], TRUE)) {
        throw new \RuntimeException('Fixture field type is unsupported: ' . $field);
      }
      $values[$field] = $value;
    }
    if (isset($definitions['field_notification'])) {
      $values['field_notification'] = FALSE;
    }
    foreach ($row['organisations'] ?? [] as $label) {
      $matches = $this->entities->getStorage('group')->loadByProperties([
        'type' => 'org',
        'label' => $label,
        'field_jurisdiction' => $jur->id(),
      ]);
      if (count($matches) !== 1) {
        throw new \RuntimeException('Organisation label must resolve uniquely inside the jurisdiction.');
      }
      $values['field_organisation'][] = ['target_id' => reset($matches)->id()];
    }
    if (!empty($row['assignee_email'])) {
      $values['field_assignee'] = $this->account($row['assignee_email'], $jur)->id();
    }
    if (!empty($row['internal_status'])) {
      $values['field_status_internal_term'] = $this->term('internal_status', $row['internal_status'], (int) $jur->id())->id();
    }
    $paragraphs = [];
    foreach ($row['status_history'] as $history) {
      $term = $this->term('service_status', $history['status'] ?? '', (int) $jur->id());
      $author = $this->account($history['author_email'] ?? '', $jur);
      $note = $this->plain($history['note'] ?? NULL);
      $paragraph = $this->entities->getStorage('paragraph')->create([
        'type' => 'status',
        'field_status_term' => $term->id(),
        'field_status_note' => ['value' => $note, 'format' => 'plain_text'],
        'field_author' => $author->id(),
      ]);
      $this->validate($paragraph);
      $values['field_status_notes'][] = ['entity' => $paragraph];
      $paragraphs[] = [
        'kind' => 'status',
        'entity' => $paragraph,
        'fields' => ['status_term_id' => (int) $term->id(), 'note' => $note, 'author_id' => (int) $author->id()],
      ];
    }
    if ((int) $term->id() !== (int) $values['field_status']) {
      throw new \RuntimeException('Final history status must match the current status.');
    }
    foreach ($row['internal_remarks'] ?? [] as $remark) {
      $author = $this->account($remark['author_email'] ?? '', $jur);
      $paragraph = $this->entities->getStorage('paragraph')->create([
        'type' => 'internal_remark',
        'field_internal_remark_text' => ['value' => $this->plain($remark['text'] ?? NULL), 'format' => 'plain_text'],
        'field_author' => $author->id(),
      ]);
      $this->validate($paragraph);
      $values['field_internal_remark'][] = ['entity' => $paragraph];
      $paragraphs[] = ['kind' => 'internal_remark', 'entity' => $paragraph];
    }
    $files = [];
    $names = [];
    foreach ($row['files'] ?? [] as $fileRow) {
      if (isset($names[$fileRow['basename'] ?? ''])) {
        throw new \RuntimeException('Asset basenames must be unique within one request fixture.');
      }
      $filePlan = $this->planFile($fileRow, $assets, $uuid);
      $names[$fileRow['basename']] = TRUE;
      $field = $fileRow['field'];
      $values[$field][] = [
        'entity' => $filePlan['media'] ?? $filePlan['file'],
      ] + ($field !== 'field_request_media' ? ['description' => $filePlan['description']] : []);
      $files[] = $filePlan;
    }
    $node = $this->entities->getStorage('node')->create($values);
    $this->validate($node);
    return ['node' => $node, 'paragraphs' => $paragraphs, 'files' => $files, 'supplied' => array_keys($values)];
  }

  /**
   * Checks one hash-pinned, local synthetic file and constructs unsaved media.
   */
  private function planFile(array $row, string $assets, string $uuid): array {
    $name = $row['basename'] ?? '';
    $field = $row['field'] ?? '';
    if (!is_string($name) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,100}$/D', $name) || !in_array($field, [
      'field_attachment',
      'field_service_provider_files',
      'field_sp_attachment',
      'field_request_media',
    ], TRUE)) {
      throw new \RuntimeException('Only local basename attachments or modern request media are supported.');
    }
    $directory = realpath($assets);
    $path = $assets . '/' . $name;
    if ($directory === FALSE || is_link($path) || !is_file($path) || dirname((string) realpath($path)) !== $directory || filesize($path) > 5 * 1024 * 1024) {
      throw new \RuntimeException('Synthetic asset is missing, oversized or outside the assets directory.');
    }
    $hash = hash_file('sha256', $path);
    if (!is_string($row['sha256'] ?? NULL) || $hash !== $row['sha256']) {
      throw new \RuntimeException('Synthetic asset hash differs from the fixture.');
    }
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, $field === 'field_request_media' ? [
      'png',
      'jpg',
      'jpeg',
    ] : ['txt', 'pdf', 'png', 'jpg', 'jpeg'], TRUE)) {
      throw new \RuntimeException('Unsupported synthetic asset extension.');
    }
    $definition = $this->fields->getFieldDefinitions('node', 'service_request')[$field] ?? NULL;
    if ($definition === NULL) {
      throw new \RuntimeException('Requested attachment/media field is absent.');
    }
    $storage = $field !== 'field_request_media' ? $definition->getFieldStorageDefinition() : ($this->fields->getFieldDefinitions('media', 'request_image')['field_media_image'] ?? NULL)?->getFieldStorageDefinition();
    $scheme = $storage?->getSetting('uri_scheme');
    if (!in_array($scheme, ['public', 'private'], TRUE)) {
      throw new \RuntimeException('Synthetic fixtures require a configured local public/private file scheme.');
    }
    $allowedExtensions = preg_split('/\s+/', (string) $definition->getSetting('file_extensions'));
    if ($field !== 'field_request_media' && !in_array($extension, $allowedExtensions, TRUE)) {
      throw new \RuntimeException('Synthetic attachment extension is not enabled on the target field.');
    }
    $uri = $scheme . '://demo-fixtures/' . $uuid . '/' . $name;
    if (file_exists($uri) || $this->entities->getStorage('file')->loadByProperties(['uri' => $uri])) {
      throw new \RuntimeException('Synthetic asset destination already exists without a completed ownership record.');
    }
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    $file = $this->entities->getStorage('file')->create([
      'uri' => $uri,
      'filename' => $name,
      'filemime' => $mime,
      'filesize' => filesize($path),
      // Detached private uploads are referenceable only by their uploader.
      // The report and paragraph authors retain their explicit fixture values.
      'uid' => (int) $this->currentUser->id(),
      'status' => 1,
    ]);
    $plan = [
      'file' => $file,
      'source' => $path,
      'sha256' => $hash,
      'field' => $field,
      'description' => isset($row['description']) ? $this->plain($row['description']) : '',
    ];
    if ($field === 'field_request_media') {
      $size = getimagesize($path);
      if ($size === FALSE || !in_array($mime, ['image/png', 'image/jpeg'], TRUE)) {
        throw new \RuntimeException('Synthetic request media must be a valid PNG or JPEG.');
      }
      $image = [
        'entity' => $file,
        'alt' => $this->plain($row['alt'] ?? NULL),
        'width' => $size[0],
        'height' => $size[1],
      ];
      $plan['media'] = $this->entities->getStorage('media')->create([
        'bundle' => 'request_image',
        'name' => '[DEMO] ' . $name,
        'uid' => 0,
        'status' => 1,
        'field_media_image' => $image,
      ]);
      $this->validate($plan['media']);
    }
    return $plan;
  }

  /**
   * Saves via canonical history helper and entity/reference APIs.
   */
  private function saveRequest(array $plan, array &$created): void {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $plan['node'];
    $notes = [];
    $remarks = [];
    foreach ($plan['paragraphs'] as $item) {
      if ($item['kind'] === 'status') {
        $paragraph = $this->processor->createStatusNoteParagraph($item['fields']);
        $notes[] = ['target_id' => $paragraph->id(), 'target_revision_id' => $paragraph->getRevisionId()];
      }
      else {
        $paragraph = $item['entity'];
        $paragraph->save();
        $remarks[] = ['target_id' => $paragraph->id(), 'target_revision_id' => $paragraph->getRevisionId()];
      }
      $created[] = $paragraph;
    }
    $node->set('field_status_notes', $notes);
    if ($remarks) {
      $node->set('field_internal_remark', $remarks);
    }
    $fileFields = [];
    foreach ($plan['files'] as $item) {
      $file = $item['file'];
      if (hash_file('sha256', $item['source']) !== $item['sha256']) {
        throw new \RuntimeException('Synthetic source changed after preview.');
      }
      $directory = dirname($file->getFileUri());
      if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
        throw new \RuntimeException('Cannot prepare synthetic file destination.');
      }
      $destination = fopen($file->getFileUri(), 'xb');
      if ($destination === FALSE) {
        throw new \RuntimeException('Cannot exclusively create synthetic file.');
      }
      try {
        $source = fopen($item['source'], 'rb');
        if ($source === FALSE) {
          throw new \RuntimeException('Cannot open synthetic source.');
        }
        try {
          stream_copy_to_stream($source, $destination);
        }
        finally {
          fclose($source);
        }
      }
      finally {
        fclose($destination);
      }
      if (hash_file('sha256', $file->getFileUri()) !== $item['sha256']) {
        throw new \RuntimeException('Synthetic file copy hash mismatch.');
      }
      $this->validate($file);
      $file->save();
      $created[] = $file;
      $reference = $file;
      if (isset($item['media'])) {
        $reference = $item['media'];
        $reference->get('field_media_image')->target_id = $file->id();
        $this->validate($reference);
        $reference->save();
        $created[] = $reference;
      }
      $fileFields[$item['field']][] = [
        'target_id' => $reference->id(),
      ] + ($item['field'] !== 'field_request_media' ? ['description' => $item['description']] : []);
    }
    foreach ($fileFields as $field => $items) {
      $node->set($field, $items);
    }
    $this->validate($node);
    $expectedReferences = [];
    foreach ([
      'field_jurisdiction',
      'field_category',
      'field_status',
      'field_organisation',
      'field_assignee',
    ] as $field) {
      if ($node->hasField($field)) {
        $expectedReferences[$field] = array_map('strval', array_column($node->get($field)->getValue(), 'target_id'));
      }
    }
    $node->save();
    $saved = $this->entities->getStorage('node')->loadUnchanged($node->id());
    foreach ($expectedReferences as $field => $ids) {
      if (array_map('strval', array_column($saved->get($field)->getValue(), 'target_id')) !== $ids) {
        throw new \RuntimeException('A normal entity hook changed requested scoped references; fixture creation stopped.');
      }
    }
    $created[] = $saved;
  }

  /**
   * Resolves a uniquely labelled taxonomy term in the exact jurisdiction.
   */
  private function term(string $vocabulary, string $label, int $jurisdiction): ContentEntityInterface {
    $matches = $this->entities->getStorage('taxonomy_term')->loadByProperties([
      'vid' => $vocabulary,
      'name' => $label,
      'field_jurisdiction' => $jurisdiction,
    ]);
    if (count($matches) !== 1) {
      throw new \RuntimeException('Taxonomy label must resolve uniquely inside the jurisdiction.');
    }
    return reset($matches);
  }

  /**
   * Resolves existing accounts only, with direct jurisdiction membership.
   */
  private function account(string $email, GroupInterface $jur): ContentEntityInterface {
    $matches = $this->entities->getStorage('user')->loadByProperties(['mail' => $email, 'status' => 1]);
    if (count($matches) !== 1 || !$jur->getMember(reset($matches))) {
      throw new \RuntimeException('Fixture actor must be an existing active member of this jurisdiction.');
    }
    return reset($matches);
  }

  /**
   * Bounded plain paragraph text.
   */
  private function plain(mixed $value): string {
    if (!is_string($value) || trim($value) === '' || strlen($value) > 10000 || strip_tags($value) !== $value) {
      throw new \RuntimeException('Fixture notes and alt text must be bounded nonempty plain text.');
    }
    return $value;
  }

  /**
   * Constraint diagnostics name fields, never reporter values.
   */
  private function validate(ContentEntityInterface $entity): void {
    $violations = $entity->validate();
    if ($violations->count()) {
      $paths = [];
      foreach ($violations as $violation) {
        $paths[] = $violation->getPropertyPath();
      }
      throw new \RuntimeException('Fixture entity validation failed at: ' . implode(', ', array_unique($paths)) . '. Fix the fixture or schema; constraints were not bypassed.');
    }
  }

  /**
   * Inventories every active field without claiming unexercised support.
   */
  private function coverage(array $plans): array {
    $used = [];
    foreach ($plans as $plan) {
      $used = array_merge($used, $plan['supplied']);
    }
    $coverage = [];
    $supported = array_merge(self::SCALARS, [
      'field_jurisdiction',
      'field_category',
      'field_status',
      'field_organisation',
      'field_assignee',
      'field_status_internal_term',
      'field_status_notes',
      'field_internal_remark',
      'field_attachment',
      'field_service_provider_files',
      'field_sp_attachment',
      'field_request_media',
    ]);
    foreach ($this->fields->getFieldDefinitions('node', 'service_request') as $name => $definition) {
      $status = match (TRUE) {
        in_array($name, self::RETIRED, TRUE) => 'retired_not_seeded',
        in_array($name, $used, TRUE) => 'supplied',
        $definition->isComputed(), $definition->isReadOnly() => 'computed_or_read_only',
        in_array($name, $supported, TRUE) => 'optional_not_supplied',
        !$definition->getFieldStorageDefinition()->isBaseField() => 'unsupported_not_seeded',
        default => 'system_default',
      };
      $coverage[$name] = [
        'status' => $status,
        'type' => $definition->getType(),
        'required' => $definition->isRequired(),
      ];
    }
    foreach (self::RETIRED as $name) {
      $coverage[$name] ??= ['status' => 'retired_absent'];
    }
    return $coverage;
  }

  /**
   * Inventories related paragraph/media/file fields as well as node fields.
   */
  private function relatedCoverage(array $plans): array {
    $used = [];
    foreach ($plans as $plan) {
      $entities = array_column($plan['paragraphs'], 'entity');
      foreach ($plan['files'] as $file) {
        $entities[] = $file['file'];
        if (isset($file['media'])) {
          $entities[] = $file['media'];
        }
      }
      foreach ($entities as $entity) {
        $key = $entity->getEntityTypeId() . '.' . $entity->bundle();
        foreach ($entity->getFieldDefinitions() as $name => $definition) {
          $status = $definition->isComputed() || $definition->isReadOnly() ? 'computed_or_read_only' : ($entity->get($name)->isEmpty() ? 'optional_not_supplied' : 'supplied_or_default');
          $used[$key][$name] = [
            'status' => $status,
            'type' => $definition->getType(),
            'required' => $definition->isRequired(),
          ];
        }
      }
    }
    return $used;
  }

  /**
   * Fingerprints persisted entities and fixture file contents.
   */
  private function fingerprint(ContentEntityInterface $entity): array {
    $values = $entity->toArray();
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->isComputed()) {
        unset($values[$name]);
      }
    }
    $result = [
      'type' => $entity->getEntityTypeId(),
      'id' => (string) $entity->id(),
      'uuid' => $entity->uuid(),
      'sha256' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)),
    ];
    if ($entity->getEntityTypeId() === 'file') {
      $result['content_sha256'] = hash_file('sha256', $entity->get('uri')->value);
    }
    return $result;
  }

  /**
   * Repeats verify ownership and all saved content before returning unchanged.
   */
  private function verifyExisting(array $record, array $binding): array {
    if (($record['binding'] ?? NULL) !== $binding || ($record['stage'] ?? NULL) !== 'complete' || empty($record['entities'])) {
      throw new \RuntimeException('Fixture identity changed or an interrupted run needs explicit recovery.');
    }
    foreach ($record['entities'] as $expected) {
      $entity = $this->entities->getStorage($expected['type'])->loadUnchanged($expected['id']);
      if (!$entity instanceof ContentEntityInterface || $this->fingerprint($entity) !== $expected) {
        throw new \RuntimeException('An owned fixture entity or file was edited or removed; refusing overwrite.');
      }
    }
    return array_replace($record['result'], ['applied' => FALSE, 'action' => 'unchanged']);
  }

}
