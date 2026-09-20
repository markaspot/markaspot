<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_tenant_import\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\group\Entity\GroupRole;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\markaspot_tenant_import\Service\TenantDemoContent;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Real fixture creation, file/media references and unchanged replay.
 */
#[TestGroup('markaspot_tenant_import')]
#[RunTestsInSeparateProcesses]
final class TenantDemoContentKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'filter', 'text', 'options', 'taxonomy',
    'node', 'language', 'image', 'media', 'telephone', 'entity', 'flexible_permissions',
    'group', 'gnode', 'paragraphs', 'entity_reference_revisions', 'service_request',
    'markaspot_tenant_import', 'markaspot_group', 'services_api_key_auth',
    'address', 'color_field',
  ];

  /**
   * Creates a minimal real reporting model with ordinary entity hooks.
   */
  protected function setUp(): void {
    parent::setUp();
    foreach ([
      'user',
      'file',
      'node',
      'taxonomy_term',
      'group',
      'group_relationship',
      'group_config_wrapper',
      'paragraph',
      'media',
    ] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'taxonomy', 'group', 'image', 'media']);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $root = User::create(['uid' => 1, 'name' => 'operator', 'mail' => 'operator@example.invalid', 'status' => 1]);
    Role::create([
      'id' => 'administrator',
      'label' => 'Operator',
      'permissions' => ['administer group', 'bypass node access', 'administer nodes', 'access content'],
    ])->save();
    $root->addRole('administrator');
    $root->save();
    $this->container->get('current_user')->setAccount($root);
    $this->config('system.site')->set('uuid', '12345678-1234-1234-1234-123456789abc')->save();
    $this->field('user', 'user', 'field_all_groups_member', 'boolean');
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupRole::create([
      'id' => 'jur-member',
      'label' => 'Member',
      'group_type' => 'jur',
      'scope' => 'insider',
      'global_role' => 'authenticated',
      'permissions' => ['view group'],
    ])->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Request'])->save();
    $this->container->get('entity_type.manager')->getStorage('group_relationship_type')->createFromPlugin(GroupType::load('jur'), 'group_node:service_request')->save();
    foreach (['service_category', 'service_status'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
      $this->field('taxonomy_term', $vid, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    }
    $this->field('taxonomy_term', 'service_status', 'field_open311_mapping', 'string');
    $this->field('node', 'service_request', 'field_jurisdiction', 'entity_reference', [
      'target_type' => 'group',
    ], ['handler' => 'default:group', 'handler_settings' => ['target_bundles' => ['jur' => 'jur']]]);
    foreach (['field_category', 'field_status'] as $field) {
      $this->field('node', 'service_request', $field, 'entity_reference', ['target_type' => 'taxonomy_term']);
    }
    $this->field('node', 'service_request', 'body', 'text_long');
    $this->field('node', 'service_request', 'field_e_mail', 'email');
    $this->field('node', 'service_request', 'field_phone', 'telephone');
    $this->field('node', 'service_request', 'field_notification', 'boolean');
    foreach (['status', 'internal_remark'] as $bundle) {
      ParagraphsType::create(['id' => $bundle, 'label' => $bundle])->save();
      $this->field('paragraph', $bundle, 'field_author', 'entity_reference', ['target_type' => 'user']);
    }
    $this->field('paragraph', 'status', 'field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->field('paragraph', 'status', 'field_status_note', 'text_long');
    $this->field('paragraph', 'internal_remark', 'field_internal_remark_text', 'text_long');
    foreach (['field_status_notes', 'field_internal_remark'] as $field) {
      $this->field('node', 'service_request', $field, 'entity_reference_revisions', [
        'target_type' => 'paragraph',
      ], [], -1);
    }
    $this->field('node', 'service_request', 'field_attachment', 'file', [
      'target_type' => 'file',
      'uri_scheme' => 'public',
    ], ['file_extensions' => 'txt pdf'], -1);
    MediaType::create([
      'id' => 'request_image',
      'label' => 'Request image',
      'source' => 'image',
      'source_configuration' => ['source_field' => 'field_media_image'],
    ])->save();
    $this->field('media', 'request_image', 'field_media_image', 'image', [
      'target_type' => 'file',
      'uri_scheme' => 'public',
    ], ['file_extensions' => 'png jpg jpeg', 'alt_field' => TRUE, 'alt_field_required' => TRUE]);
    $this->field('node', 'service_request', 'field_request_media', 'entity_reference', [
      'target_type' => 'media',
    ], [], -1);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Creates only the schema needed by this isolated fixture proof.
   */
  private function field(string $type, string $bundle, string $name, string $fieldType, array $storageSettings = [], array $settings = [], int $cardinality = 1): void {
    if (!FieldStorageConfig::loadByName($type, $name)) {
      FieldStorageConfig::create([
        'entity_type' => $type,
        'field_name' => $name,
        'type' => $fieldType,
        'settings' => $storageSettings,
        'cardinality' => $cardinality,
      ])->save();
    }
    FieldConfig::create([
      'entity_type' => $type,
      'bundle' => $bundle,
      'field_name' => $name,
      'label' => $name,
      'settings' => $settings,
    ])->save();
  }

  /**
   * Uses the actual canonical history method, with only its needed dependency.
   */
  private function service(): TenantDemoContent {
    $reflection = new \ReflectionClass(GeoreportProcessorService::class);
    $processor = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('languageManager')->setValue($processor, $this->container->get('language_manager'));
    foreach ([
      'configFactory' => 'config.factory',
      'entityTypeManager' => 'entity_type.manager',
      'hierarchyResolver' => 'markaspot_group.hierarchy_resolver',
    ] as $property => $serviceId) {
      $reflection->getProperty($property)->setValue($processor, $this->container->get($serviceId));
    }
    $this->container->set('markaspot_open311.processor', $processor);
    return new TenantDemoContent(
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      $this->container->get('config.factory'),
      $this->container->get('state'),
      $this->container->get('lock'),
      $this->container->get('database'),
      $this->container->get('file_system'),
      $processor,
    );
  }

  /**
   * Checks empty preview, persisted content and duplicate-free replay.
   */
  public function testCreatesContentAndProtectsEditedReplay(): void {
    $jur = Group::create(['type' => 'jur', 'label' => 'Synthetic jurisdiction']);
    $jur->save();
    $jur->addMember(User::load(1));
    $actor = User::create(['name' => 'synthetic-staff', 'mail' => 'staff@example.invalid', 'status' => 1]);
    $actor->save();
    $jur->addMember($actor);
    foreach (['service_category' => 'Road', 'service_status' => 'Open'] as $vid => $name) {
      Term::create(['vid' => $vid, 'name' => $name, 'field_jurisdiction' => $jur->id()])->save();
    }
    $assets = sys_get_temp_dir() . '/demo-test-' . bin2hex(random_bytes(8));
    mkdir($assets);
    file_put_contents($assets . '/synthetic.txt', 'Synthetic attachment, not a real report.');
    file_put_contents($assets . '/synthetic.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aTskAAAAASUVORK5CYII='));
    $fixture = [
      'version' => 1, 'synthetic' => TRUE, 'fixture_id' => 'kernel-demo',
      'requests' => [[
        'key' => 'one', 'title' => 'Synthetic report', 'category' => 'Road', 'status' => 'Open',
        'fields' => [
          'body' => 'Synthetic description',
          'field_e_mail' => 'demo@example.invalid',
          'field_phone' => '+49 000 000000',
          'field_notification' => FALSE,
        ],
        'status_history' => [
          ['status' => 'Open', 'note' => 'Synthetic status', 'author_email' => 'staff@example.invalid'],
        ],
        'internal_remarks' => [['text' => 'Synthetic internal remark', 'author_email' => 'staff@example.invalid']],
        'files' => [
          [
            'field' => 'field_attachment',
            'basename' => 'synthetic.txt',
            'sha256' => hash_file('sha256', $assets . '/synthetic.txt'),
          ],
          [
            'field' => 'field_request_media',
            'basename' => 'synthetic.png',
            'sha256' => hash_file('sha256', $assets . '/synthetic.png'),
            'alt' => 'Synthetic pixel',
          ],
        ],
      ]],
    ];
    $oldContext = getenv('MARKASPOT_DEPLOY_CONTEXT');
    $oldMail = getenv('MARKASPOT_MAIL_MODE');
    try {
      putenv('MARKASPOT_DEPLOY_CONTEXT=nonproduction');
      putenv('MARKASPOT_MAIL_MODE=mailpit');
      $service = $this->service();
      $site = (string) $this->config('system.site')->get('uuid');
      $preview = $service->seed($fixture, $assets, $site, (int) $jur->id(), TRUE);
      $this->assertSame('preview', $preview['action']);
      $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute());
      $created = $service->seed($fixture, $assets, $site, (int) $jur->id(), TRUE, TRUE);
      $this->assertSame('created', $created['action']);
      $nodes = $this->container->get('entity_type.manager')->getStorage('node')->loadMultiple();
      $this->assertCount(1, $nodes);
      $node = reset($nodes);
      $this->assertSame('+49 000 000000', $node->get('field_phone')->value);
      $this->assertCount(1, $node->get('field_status_notes'));
      $this->assertSame('Synthetic status', $node->get('field_status_notes')->entity->get('field_status_note')->value);
      $this->assertSame('Synthetic internal remark', $node->get('field_internal_remark')->entity->get('field_internal_remark_text')->value);
      $this->assertSame('Synthetic attachment, not a real report.', file_get_contents($node->get('field_attachment')->entity->getFileUri()));
      $this->assertNotNull($node->get('field_request_media')->entity->get('field_media_image')->entity);
      $repeat = $service->seed($fixture, $assets, $site, (int) $jur->id(), TRUE, TRUE);
      $this->assertSame('unchanged', $repeat['action']);
      $this->assertFalse($repeat['applied']);
      $node->setTitle('Edited by an operator');
      $node->save();
      $this->expectExceptionMessage('edited or removed');
      $service->seed($fixture, $assets, $site, (int) $jur->id(), TRUE, TRUE);
    }
    finally {
      unlink($assets . '/synthetic.txt');
      unlink($assets . '/synthetic.png');
      rmdir($assets);
      putenv($oldContext === FALSE ? 'MARKASPOT_DEPLOY_CONTEXT' : 'MARKASPOT_DEPLOY_CONTEXT=' . $oldContext);
      putenv($oldMail === FALSE ? 'MARKASPOT_MAIL_MODE' : 'MARKASPOT_MAIL_MODE=' . $oldMail);
    }
  }

}
