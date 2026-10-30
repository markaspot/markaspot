<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\group\Entity\Group as GroupEntity;
use Drupal\group\Entity\GroupType;
use Drupal\markaspot_nuxt\Resource\ServiceRequestVersionHistory;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\service_request\RevisionLog;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises saved revision metadata and its field-access projection.
 */
#[Group('service_request')]
#[RunTestsInSeparateProcesses]
final class RevisionHistoryChangesKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'taxonomy',
    'file', 'image', 'media', 'media_test_source', 'field_permissions',
    'serialization', 'jsonapi', 'service_request',
  ];

  /**
   * Exercise real permissions even for the first test user.
   */
  protected bool $usesSuperUserAccessPolicy = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'taxonomy_term', 'file', 'media'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'file', 'image', 'media']);
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Fictional report'])->save();
    Vocabulary::create(['vid' => 'history_test', 'name' => 'Fictional categories'])->save();
    foreach ([
      'field_organisation' => 'node',
      'field_assignee' => 'user',
      'field_assigned_team' => 'node',
      'field_status' => 'taxonomy_term',
      'field_category' => 'taxonomy_term',
      'field_request_media' => 'media',
    ] as $field => $target) {
      $this->field($field, 'entity_reference', ['target_type' => $target]);
    }
    $this->field('body', 'text_with_summary');
    $media_type = MediaType::create(['id' => 'history_photo', 'label' => 'Fictional photo', 'source' => 'test']);
    $media_type->save();
    $source = $media_type->getSource()->createSourceField($media_type);
    $source->getFieldStorageDefinition()->save();
    $source->save();
    $media_type->set('source_configuration', ['source_field' => $source->getName()])->save();
    $this->setCurrentUser($this->createUser(['access content', 'view media']));
  }

  /**
   * Creates a public report field, configurable using real field_permissions.
   */
  private function field(string $name, string $type, array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $name, 'entity_type' => 'node', 'type' => $type,
      'settings' => $settings, 'cardinality' => $type === 'entity_reference' ? -1 : 1,
    ])->save();
    FieldConfig::create([
      'field_name' => $name, 'entity_type' => 'node', 'bundle' => 'service_request',
    ])->save();
  }

  /**
   * Each new kind is collected by the real save hook; resaves stay quiet.
   */
  public function testNewChangesAndPrivacyOnSave(): void {
    $first = Term::create(['vid' => 'history_test', 'name' => 'Fictional road']);
    $first->save();
    $second = Term::create(['vid' => 'history_test', 'name' => 'Fictional park']);
    $second->save();
    $photo_a = $this->photo('Fictional photo A');
    $photo_b = $this->photo('Fictional photo B');
    $node = Node::create([
      'type' => 'service_request', 'title' => 'Fictional history', 'status' => 0,
      'field_category' => $first->id(), 'body' => 'Confidential fictional original',
      'field_request_media' => [$photo_a->id()],
    ]);
    $node->save();
    $created = $this->entry($node);
    $this->assertSame('created', $created['action']);
    $this->assertSame(['category', 'photos', 'description'], array_column($created['changes'], 'field'));
    $this->assertSame([], $created['events']);
    $old_vid = $node->getRevisionId();
    $node->set('field_category', $second->id());
    $node->set('body', ['value' => 'Confidential fictional replacement', 'summary' => 'Secret fictional summary']);
    $node->set('field_request_media', [$photo_b->id()]);
    $node->setPublished();
    RevisionLog::setEvent($node, 'delegated');
    RevisionLog::setMessage($node, 'Fictional explicit reason');
    $node->save();
    $entry = $this->entry($node);
    $this->assertSame([
      [
        'field' => 'category', 'before' => [['id' => (string) $first->id()]],
        'after' => [['id' => (string) $second->id()]],
      ],
      [
        'field' => 'photos', 'before' => [['id' => (string) $photo_a->id()]],
        'after' => [['id' => (string) $photo_b->id()]],
      ],
      ['field' => 'description', 'before' => [], 'after' => []],
    ], $entry['changes']);
    $this->assertSame(['delegated', 'published'], $entry['events']);
    $this->assertSame('Fictional explicit reason', $entry['message']);
    $this->assertNotSame($old_vid, $node->getRevisionId());
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->assertSame('Confidential fictional original', $storage->loadRevision($old_vid)->get('body')->value);
    $raw = (string) $node->getRevisionLogMessage();
    foreach (['Confidential', 'Secret', 'private://', 'Fictional photo'] as $secret) {
      $this->assertStringNotContainsString($secret, $raw);
    }
    $node->save();
    $this->assertSame([], $this->entry($node)['changes']);
    $this->assertSame([], $this->entry($node)['events']);
    $this->assertSame('', $this->entry($node)['message']);
    $node->setUnpublished();
    $node->set('body', NULL);
    $node->set('field_request_media', []);
    $node->save();
    $this->assertSame(['unpublished'], $this->entry($node)['events']);
    $this->assertSame([
      ['field' => 'photos', 'before' => [['id' => (string) $photo_b->id()]], 'after' => []],
      ['field' => 'description', 'before' => [], 'after' => []],
    ], $this->entry($node)['changes']);
    $node->set('field_request_media', [$photo_a->id(), $photo_b->id()])->save();
    $this->assertSame([], $this->entry($node)['changes'][0]['before']);
    $node->set('field_request_media', [$photo_b->id(), $photo_a->id()])->save();
    $this->assertSame([], $this->entry($node)['changes'], 'Photo ordering is not an addition/removal.');
  }

  /**
   * Original concepts retain their exact before/after ID-only save format.
   */
  public function testExistingReferenceFieldsAreUnchanged(): void {
    $node = Node::create(['type' => 'service_request', 'title' => 'Fictional legacy fields']);
    foreach (array_slice(RevisionLog::REFERENCE_FIELDS, 0, 4, TRUE) as $field => $concept) {
      $node->set($field, ['target_id' => 101]);
    }
    $node->save();
    $expected = [];
    foreach ([
      'field_organisation' => 'responsibility', 'field_assignee' => 'person',
      'field_assigned_team' => 'team', 'field_status' => 'status',
    ] as $field => $concept) {
      $node->set($field, ['target_id' => 202]);
      $expected[] = ['field' => $concept, 'before' => [['id' => '101']], 'after' => [['id' => '202']]];
    }
    $node->save();
    $this->assertSame($expected, $this->entry($node)['changes']);
  }

  /**
   * Real form permissions hide whole changes; labels never expose media data.
   */
  public function testFieldPermissionsFilterHistory(): void {
    foreach (['body', 'field_category', 'field_request_media'] as $field) {
      FieldStorageConfig::load('node.' . $field)
        ->setThirdPartySetting('field_permissions', 'permission_type', 'custom')->save();
    }
    $allowed = $this->createUser([
      'access content', 'view media', 'view body',
      'view field_category', 'view field_request_media',
    ]);
    $denied = $this->createUser(['access content', 'view media']);
    $term = Term::create(['vid' => 'history_test', 'name' => 'Fictional visible category']);
    $term->save();
    $photo = $this->photo('Fictional visible photo');
    $node = Node::create(['type' => 'service_request', 'title' => 'Fictional private fields', 'status' => 0]);
    $node->save();
    $node->set('field_category', $term->id())->set('field_request_media', $photo->id())->set('body', 'Fictional secret body')->setPublished()->save();
    $visible = $this->history($node, $allowed);
    $this->assertSame(['category', 'photos', 'description'], array_column($visible[1]['changes'], 'field'));
    $this->assertSame(['id' => (string) $term->id(), 'label' => 'Fictional visible category'], $visible[1]['changes'][0]['after'][0]);
    $this->assertSame(['id' => (string) $photo->id(), 'label' => 'Fictional visible photo'], $visible[1]['changes'][1]['after'][0]);
    $this->assertSame(['published'], $visible[1]['events']);
    $this->assertStringNotContainsString('private://', json_encode($visible, JSON_THROW_ON_ERROR));
    $this->assertStringNotContainsString('Fictional secret body', json_encode($visible, JSON_THROW_ON_ERROR));
    foreach (['body', 'field_category', 'field_request_media'] as $field) {
      $this->assertFalse($node->get($field)->access('view', $denied));
      $this->assertTrue($node->get($field)->access('view', $allowed));
    }
    $hidden = $this->history($node, $denied);
    $this->assertSame([], $hidden[1]['changes']);
    $this->assertSame(['published'], $hidden[1]['events']);
    // Missing or inaccessible references retain IDs, with safe label fallback.
    $photo->setUnpublished()->save();
    $hidden_label = $this->history($node, $allowed);
    $this->assertSame('#' . $photo->id(), $hidden_label[1]['changes'][1]['after'][0]['label']);
    $photo->delete();
    $this->assertSame('#' . $photo->id(), $this->history($node, $allowed)[1]['changes'][1]['after'][0]['label']);
  }

  /**
   * Both live and historical field decisions apply, including status events.
   */
  public function testCurrentAndHistoricalFieldAccess(): void {
    $account = $this->createUser(['access content']);
    $node = Node::create(['type' => 'service_request', 'title' => 'Historical restricted', 'status' => 0]);
    $node->save();
    $node->set('body', 'Fictional historical secret')->setPublished()->save();
    $node->setTitle('Current allowed')->set('body', 'Fictional current secret')->setUnpublished()->save();
    $this->container->get('state')->set('history_test.deny_title', 'Historical restricted');
    $items = $this->history($node, $account);
    $this->assertCount(3, $items);
    $this->assertSame([], $items[1]['changes']);
    $this->assertSame([], $items[1]['events']);
    $this->assertSame(['description'], array_column($items[2]['changes'], 'field'));
    $this->assertSame(['unpublished'], $items[2]['events']);
    $this->container->get('state')->set('history_test.deny_title', 'Current allowed');
    $items = $this->history($node, $account);
    foreach ($items as $item) {
      $this->assertSame([], $item['changes']);
      $this->assertSame([], $item['events']);
    }
  }

  /**
   * Catalog labels require tenant scope, including missing or empty fields.
   */
  public function testCategoryAndStatusLabelsRespectJurisdictionScope(): void {
    $viewer = $this->createUser(['access content']);
    $outsider = $this->createUser(['access content']);
    $bypass = $this->createUser(['access content', 'bypass node access']);
    $this->enableModules(['entity', 'flexible_permissions', 'group', 'gnode', 'markaspot_group']);
    foreach (['group', 'group_relationship', 'group_config_wrapper'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['group']);
    $group_type = GroupType::create(['id' => 'jur', 'label' => 'Fictional jurisdiction']);
    $group_type->save();
    $relationships = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$relationships->load('jur-group_membership')) {
      $relationships->createFromPlugin($group_type, 'group_membership')->save();
    }
    $relationships->createFromPlugin($group_type, 'group_node:service_request')->save();
    GroupType::create(['id' => 'org', 'label' => 'Fictional organisation'])->save();
    foreach ([
      ['group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']],
      ['group', 'org', 'field_service_categories', 'entity_reference', ['target_type' => 'taxonomy_term']],
      ['user', 'user', 'field_all_groups_member', 'boolean', []],
      ['group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']],
    ] as [$entity_type, $bundle, $name, $type, $settings]) {
      FieldStorageConfig::create([
        'field_name' => $name, 'entity_type' => $entity_type,
        'type' => $type, 'settings' => $settings,
      ])->save();
      FieldConfig::create([
        'field_name' => $name, 'entity_type' => $entity_type, 'bundle' => $bundle,
      ])->save();
    }
    $local = GroupEntity::create(['type' => 'jur', 'label' => 'Fictional local jurisdiction']);
    $local->save();
    $foreign = GroupEntity::create(['type' => 'jur', 'label' => 'Fictional foreign jurisdiction']);
    $foreign->save();
    $local->addMember($viewer);
    $foreign->addMember($outsider);
    Vocabulary::create(['vid' => 'service_category', 'name' => 'Fictional service categories'])->save();
    Vocabulary::create(['vid' => 'service_status', 'name' => 'Fictional service statuses'])->save();
    $local->addMember($bypass);
    $this->field('field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $node = Node::create([
      'type' => 'service_request', 'title' => 'Fictional unscoped catalog',
      'field_jurisdiction' => $local->id(),
    ]);
    $terms = [];
    foreach (['service_status' => 'field_status', 'service_category' => 'field_category'] as $bundle => $field) {
      $unscoped = Term::create(['vid' => $bundle, 'name' => 'Fictional ' . $bundle, 'status' => 1]);
      $unscoped->save();
      $this->assertFalse($unscoped->hasField('field_jurisdiction'));
      $this->assertTrue($unscoped->access('view', $viewer));
      $terms[] = $unscoped;
      $node->set($field, $unscoped->id());
    }
    $node->save();
    $this->assertFalse(\_markaspot_group_account_bypasses_private_field_scope($viewer));
    foreach ($terms as $index => $unscoped) {
      $this->assertSame('#' . $unscoped->id(), $this->history($node, $viewer)[0]['changes'][$index]['after'][0]['label']);
      $this->assertSame($unscoped->label(), $this->history($node, $bypass)[0]['changes'][$index]['after'][0]['label']);
    }
    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction', 'entity_type' => 'taxonomy_term',
      'type' => 'entity_reference', 'settings' => ['target_type' => 'group'],
    ])->save();
    foreach (['service_status', 'service_category'] as $bundle) {
      FieldConfig::create([
        'field_name' => 'field_jurisdiction', 'entity_type' => 'taxonomy_term',
        'bundle' => $bundle,
      ])->save();
    }
    $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->resetCache();
    foreach ($terms as $index => $unscoped) {
      $reloaded = Term::load($unscoped->id());
      $this->assertTrue($reloaded->get('field_jurisdiction')->isEmpty());
      $this->assertSame('#' . $unscoped->id(), $this->history($node, $viewer)[0]['changes'][$index]['after'][0]['label']);
      $this->assertSame($unscoped->label(), $this->history($node, $bypass)[0]['changes'][$index]['after'][0]['label']);
    }
    $term = Term::create([
      'vid' => 'service_category', 'name' => 'Fictional scoped category',
      'field_jurisdiction' => $local->id(), 'status' => 1,
    ]);
    $term->save();
    $this->assertTrue($term->access('view', $outsider), 'Public term access alone must not grant a historical catalog label.');
    $method = new \ReflectionMethod(ServiceRequestVersionHistory::class, 'canViewReferenceLabel');
    foreach ([[$viewer, TRUE], [$outsider, FALSE]] as [$account, $expected]) {
      $resource = new ServiceRequestVersionHistory(
        $this->container->get('entity_type.manager'), $account,
        $this->container->get('date.formatter'), $this->container->get('logger.factory')->get('history_test'),
        $this->container->get('database'), $this->container->get('language_manager'),
      );
      $this->assertSame($expected, $method->invoke($resource, $term));
    }
  }

  /**
   * Simulates a contextual field policy, including base-field publication.
   */
  #[Hook('entity_field_access')]
  public function fieldAccess(string $operation, FieldDefinitionInterface $definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResult {
    $title = $this->container->get('state')->get('history_test.deny_title');
    if ($operation === 'view' && in_array($definition->getName(), ['body', 'status'], TRUE)
      && $title && $items?->getEntity()->label() === $title) {
      return AccessResult::forbidden();
    }
    return AccessResult::neutral();
  }

  /**
   * Saves synthetic media whose source data must never enter revision logs.
   */
  private function photo(string $name): Media {
    $type = MediaType::load('history_photo');
    $source = $type->getSource()->getSourceFieldDefinition($type)->getName();
    $media = Media::create([
      'bundle' => 'history_photo', 'name' => $name, 'status' => 1,
      $source => 'private://fictional-confidential/photo.jpg',
    ]);
    $media->save();
    return $media;
  }

  /**
   * Reads persisted metadata, not only the in-memory presave result.
   */
  private function entry(NodeInterface $node): array {
    $revision = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($node->getRevisionId());
    $entry = RevisionLog::decode((string) $revision->getRevisionLogMessage());
    $this->assertNotNull($entry);
    return $entry;
  }

  /**
   * Calls the endpoint's real projection with real storage and field access.
   */
  private function history(NodeInterface $node, AccountInterface $account): array {
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->container->get('entity_type.manager')->getAccessControlHandler('media')->resetCache();
    $resource = new ServiceRequestVersionHistory(
      $this->container->get('entity_type.manager'), $account,
      $this->container->get('date.formatter'), $this->container->get('logger.factory')->get('history_test'),
      $this->container->get('database'), $this->container->get('language_manager'),
    );
    $method = new \ReflectionMethod(ServiceRequestVersionHistory::class, 'buildRevisionItems');
    $items = $method->invoke($resource, $node)['items'];
    $keys = ['vid', 'author', 'timestamp', 'log_message', 'action', 'changes', 'events'];
    return array_map(static fn($item): array => array_combine(
      $keys, array_map(static fn(string $key) => $item->getField($key), $keys),
    ), $items);
  }

}
