<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_tenant_import\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\group\Entity\GroupRole;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\markaspot_tenant_import\Service\TenantDemoContent;
use Drupal\markaspot_tenant_import\Drush\Commands\TenantDemoContentCommands;
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
   * Creates scoped templates and refuses ownership crossing jurisdictions.
   */
  public function testBoilerplateOwnership(): void {
    NodeType::create(['type' => 'boilerplate', 'name' => 'Template'])->save();
    $this->container->get('entity_type.manager')->getStorage('group_relationship_type')->createFromPlugin(GroupType::load('jur'), 'group_node:boilerplate')->save();
    $this->field('node', 'boilerplate', 'body', 'text_long');
    $this->field('node', 'boilerplate', 'field_boilerplate_type', 'string');
    $this->field('node', 'boilerplate', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->field('node', 'boilerplate', 'field_organisation', 'entity_reference', ['target_type' => 'group'], [], -1);
    $jur = Group::create(['type' => 'jur', 'label' => 'Template jurisdiction']);
    $jur->save();
    $foreign = Group::create(['type' => 'jur', 'label' => 'Foreign jurisdiction']);
    $foreign->save();
    $entities = $this->container->get('entity_type.manager');
    $owned = $this->container->get('keyvalue')->get('template-test');
    $rows = [['key' => 'received', 'title' => 'Received', 'text' => 'Your report has been received.', 'type' => 'status_notes', 'active' => TRUE]];
    $created = \Drupal\markaspot_tenant_import\Service\TenantBoilerplates::apply($rows, $jur, $entities, $owned, 'en');
    $this->assertSame('create', $created[0]['action']);
    $nodes = $entities->getStorage('node')->loadByProperties(['uuid' => $created[0]['uuid']]);
    $node = reset($nodes);
    $this->assertSame('plain_text', $node->get('body')->format);
    $this->assertCount(1, $jur->getRelationshipsByEntity($node, 'group_node:boilerplate'));
    $this->assertSame((int) $jur->id(), (int) $node->get('field_jurisdiction')->target_id);
    $rows[0]['active'] = FALSE;
    $updated = \Drupal\markaspot_tenant_import\Service\TenantBoilerplates::apply($rows, $jur, $entities, $owned, 'en');
    $this->assertSame($created[0]['uuid'], $updated[0]['uuid']);
    $this->assertFalse($node->isPublished());
    $this->assertSame(1, (int) $entities->getStorage('node')->getQuery()->accessCheck(FALSE)->condition('type', 'boilerplate')->count()->execute());
    $node->set('field_jurisdiction', $foreign->id())->save();
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('outside its jurisdiction');
    \Drupal\markaspot_tenant_import\Service\TenantBoilerplates::apply($rows, $jur, $entities, $owned, 'en');
  }

  /**
   * Registers real private storage before the test container is compiled.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.private', 'Drupal\Core\StreamWrapper\PrivateStream')
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

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
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $this->field('group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    GroupRole::create([
      'id' => 'jur-member',
      'label' => 'Member',
      'group_type' => 'jur',
      'scope' => 'individual',
      'permissions' => ['view group'],
    ])->save();
    foreach (['jur', 'org'] as $type) {
      GroupRole::create([
        'id' => $type . '-admin',
        'label' => 'Administrator',
        'group_type' => $type,
        'admin' => TRUE,
        'scope' => 'insider',
        'global_role' => 'administrator',
        'permissions' => [],
      ])->save();
    }
    GroupRole::create([
      'id' => 'jur-org_member',
      'label' => 'Organisation member',
      'group_type' => 'jur',
      'scope' => 'individual',
      'permissions' => ['view group'],
    ])->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Request'])->save();
    foreach (['jur', 'org'] as $type) {
      $this->container->get('entity_type.manager')->getStorage('group_relationship_type')->createFromPlugin(GroupType::load($type), 'group_node:service_request')->save();
    }
    foreach (['service_category', 'service_status'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
      $this->field('taxonomy_term', $vid, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    }
    $this->field('taxonomy_term', 'service_status', 'field_open311_mapping', 'string');
    $this->field('node', 'service_request', 'field_jurisdiction', 'entity_reference', [
      'target_type' => 'group',
    ], ['handler' => 'default:group', 'handler_settings' => ['target_bundles' => ['jur' => 'jur']]]);
    $this->field('node', 'service_request', 'field_organisation', 'entity_reference', [
      'target_type' => 'group',
    ], ['handler' => 'default:group', 'handler_settings' => ['target_bundles' => ['org' => 'org']]], -1);
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
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
    $this->container->get('stream_wrapper_manager')->register();
    $this->field('node', 'service_request', 'field_service_provider_files', 'file', [
      'target_type' => 'file',
      'uri_scheme' => 'private',
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
      $this->container->get('current_user'),
    );
  }

  /**
   * Checks empty preview, persisted content and duplicate-free replay.
   */
  public function testCreatesContentAndProtectsEditedReplay(): void {
    $jur = Group::create(['type' => 'jur', 'label' => 'Synthetic jurisdiction']);
    $jur->save();
    $this->assertNotNull($jur->getMember(User::load(1)));
    $actor = User::create(['name' => 'synthetic-staff', 'mail' => 'staff@example.invalid', 'status' => 1]);
    $actor->save();
    $jur->addMember($actor);
    $org = Group::create(['type' => 'org', 'label' => 'Synthetic department', 'field_jurisdiction' => $jur->id()]);
    $org->save();
    $this->assertNotNull($org->getMember(User::load(1)));
    foreach (['service_category' => 'Road', 'service_status' => 'Open'] as $vid => $name) {
      $values = ['vid' => $vid, 'name' => $name, 'field_jurisdiction' => $jur->id()];
      if ($vid === 'service_status') {
        $values['field_open311_mapping'] = 'initial';
      }
      Term::create($values)->save();
    }
    $closed = Term::create([
      'vid' => 'service_status',
      'name' => 'Closed',
      'field_jurisdiction' => $jur->id(),
      'field_open311_mapping' => 'closed',
    ]);
    $closed->save();
    $assets = sys_get_temp_dir() . '/demo-test-' . bin2hex(random_bytes(8));
    mkdir($assets);
    file_put_contents($assets . '/synthetic.txt', 'Synthetic attachment, not a real report.');
    file_put_contents($assets . '/private.txt', 'Synthetic private attachment.');
    file_put_contents($assets . '/synthetic.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aTskAAAAASUVORK5CYII='));
    $fixture = [
      'version' => 1, 'synthetic' => TRUE, 'fixture_id' => 'kernel-demo',
      'requests' => [[
        'key' => 'one', 'title' => 'Synthetic report', 'category' => 'Road', 'status' => 'Closed',
        'organisations' => ['Synthetic department'],
        'fields' => [
          'body' => 'Synthetic description',
          'field_e_mail' => 'demo@example.invalid',
          'field_phone' => '+49 000 000000',
          'field_notification' => FALSE,
        ],
        'status_history' => [
          ['status' => 'Closed', 'note' => 'Synthetic status', 'author_email' => 'staff@example.invalid'],
        ],
        'internal_remarks' => [['text' => 'Synthetic internal remark', 'author_email' => 'staff@example.invalid']],
        'files' => [
          [
            'field' => 'field_service_provider_files',
            'basename' => 'private.txt',
            'sha256' => hash_file('sha256', $assets . '/private.txt'),
          ],
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
      $this->container->get('current_user')->setAccount(User::load(0));
      try {
        $service->seed($fixture, $assets, $site, (int) $jur->id(), TRUE);
        $this->fail('Anonymous group reference validation must remain denied.');
      }
      catch (\RuntimeException $error) {
        $this->assertStringContainsString('field_jurisdiction.0.target_id', $error->getMessage());
        $this->assertStringContainsString('field_organisation.0.target_id', $error->getMessage());
      }
      file_put_contents($assets . '/fixture.json', json_encode($fixture, JSON_THROW_ON_ERROR));
      $command = TenantDemoContentCommands::create($this->container);
      $options = [
        'assets-dir' => $assets,
        'expected-site-uuid' => $site,
        'jurisdiction-id' => $jur->id(),
        'confirm-test-data' => TRUE,
      ];
      $preview = $command->seed($assets . '/fixture.json', $options)->getArrayCopy()[0];
      $this->assertSame('preview', $preview['action']);
      $this->assertSame(0, (int) $this->container->get('current_user')->id());
      $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute());
      $options['apply'] = TRUE;
      $created = $command->seed($assets . '/fixture.json', $options)->getArrayCopy()[0];
      $this->assertSame('created', $created['action']);
      $this->assertSame(0, (int) $this->container->get('current_user')->id());
      $nodes = $this->container->get('entity_type.manager')->getStorage('node')->loadMultiple();
      $this->assertCount(1, $nodes);
      $node = reset($nodes);
      $this->assertSame(0, (int) $node->getOwnerId());
      $this->assertSame((int) $closed->id(), (int) $node->get('field_status')->target_id);
      $this->assertSame((int) $closed->id(), (int) $node->get('field_status_notes')->entity->get('field_status_term')->target_id);
      $this->assertSame((int) $org->id(), (int) $node->get('field_organisation')->target_id);
      $this->assertSame('+49 000 000000', $node->get('field_phone')->value);
      $this->assertCount(1, $node->get('field_status_notes'));
      $this->assertSame('Synthetic status', $node->get('field_status_notes')->entity->get('field_status_note')->value);
      $this->assertSame('Synthetic internal remark', $node->get('field_internal_remark')->entity->get('field_internal_remark_text')->value);
      $this->assertSame((int) $actor->id(), (int) $node->get('field_status_notes')->entity->get('field_author')->target_id);
      $this->assertSame((int) $actor->id(), (int) $node->get('field_internal_remark')->entity->get('field_author')->target_id);
      $this->assertSame('Synthetic attachment, not a real report.', file_get_contents($node->get('field_attachment')->entity->getFileUri()));
      $this->assertSame('Synthetic private attachment.', file_get_contents($node->get('field_service_provider_files')->entity->getFileUri()));
      $this->assertSame(1, (int) $node->get('field_service_provider_files')->entity->getOwnerId());
      $this->assertNotNull($node->get('field_request_media')->entity->get('field_media_image')->entity);
      $repeat = $command->seed($assets . '/fixture.json', $options)->getArrayCopy()[0];
      $this->assertSame('unchanged', $repeat['action']);
      $this->assertFalse($repeat['applied']);
      $node->setTitle('Edited by an operator');
      $node->save();
      $this->expectExceptionMessage('edited or removed');
      try {
        $command->seed($assets . '/fixture.json', $options);
      }
      finally {
        $this->assertSame(0, (int) $this->container->get('current_user')->id());
      }
    }
    finally {
      unlink($assets . '/synthetic.txt');
      unlink($assets . '/private.txt');
      unlink($assets . '/synthetic.png');
      if (file_exists($assets . '/fixture.json')) {
        unlink($assets . '/fixture.json');
      }
      rmdir($assets);
      putenv($oldContext === FALSE ? 'MARKASPOT_DEPLOY_CONTEXT' : 'MARKASPOT_DEPLOY_CONTEXT=' . $oldContext);
      putenv($oldMail === FALSE ? 'MARKASPOT_MAIL_MODE' : 'MARKASPOT_MAIL_MODE=' . $oldMail);
    }
  }

}
