<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_fastmap\Demo\DemoSeed;
use Drupal\markaspot_fastmap\Demo\DemoWorkspace;
use Drupal\markaspot_fastmap\Service\DemoWorkspaceReset;
use Drupal\markaspot_fastmap\Service\DemoWorkspaceSeeder;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests seeding and resetting template demo workspaces.
 */
#[CoversClass(DemoWorkspaceSeeder::class)]
#[CoversClass(DemoWorkspaceReset::class)]
#[TestGroup('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class DemoWorkspaceSeedResetKernelTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'file',
    'image',
    'media',
    'taxonomy',
    'options',
    'datetime',
    'language',
    'content_translation',
    'color_field',
    'geolocation',
    'entity',
    'entity_reference_revisions',
    'paragraphs',
    'flexible_permissions',
    'group',
    'gnode',
    'field_permissions',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'markaspot_passwordless',
    'markaspot_tenant_admin',
    'markaspot_request_id',
    'markaspot_fastmap',
  ];

  /**
   * Seed languages besides English.
   */
  private const LANGUAGES = ['es', 'fr', 'de'];

  /**
   * Temporary seed directory with a photo.
   */
  private string $seedDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $entityTypes = [
      'user', 'node', 'file', 'media', 'taxonomy_term', 'paragraph',
      'group', 'group_relationship', 'group_config_wrapper',
    ];
    foreach ($entityTypes as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('markaspot_request_id', ['markaspot_request_id']);
    $this->installConfig([
      'system', 'user', 'field', 'filter', 'node', 'file', 'image', 'media',
      'group', 'language', 'markaspot_request_id',
    ]);
    $this->config('system.site')->set('default_langcode', 'en')->save();

    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    foreach (self::LANGUAGES as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    foreach (['jur-member', 'jur-org_member'] as $roleId) {
      GroupRole::create(['id' => $roleId, 'label' => $roleId, 'group_type' => 'jur', 'scope' => 'individual'])->save();
    }
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $relationshipTypes = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    $plugins = [
      'jur' => ['group_membership', 'group_node:service_request', 'group_node:page'],
      // Assigning a report to an organisation relates it to the org group.
      'org' => ['group_membership', 'group_node:service_request'],
    ];
    foreach ($plugins as $groupType => $pluginIds) {
      foreach ($pluginIds as $pluginId) {
        if (!$relationshipTypes->load($groupType . '-' . str_replace(':', '-', $pluginId))) {
          $relationshipTypes->createFromPlugin(GroupType::load($groupType), $pluginId)->save();
        }
      }
    }

    $jurFields = [
      'field_slug' => 'string',
      'field_boundary' => 'text_long',
      'field_nuxt_config' => 'text_long',
      'field_expiry_date' => 'timestamp',
      'field_platform_name' => 'string',
    ];
    foreach ($jurFields as $name => $type) {
      $this->createField('group', 'jur', $name, $type);
    }
    $this->createField('group', 'jur', 'field_tier', 'list_string', [
      'allowed_values' => ['free' => 'Free', 'heart' => 'Heart'],
    ]);
    $this->createField('group', 'jur', 'field_visibility', 'list_string', [
      'allowed_values' => ['public' => 'Public', 'blocked' => 'Blocked'],
    ]);
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'jur', 'field_service_categories', 'entity_reference', ['target_type' => 'taxonomy_term'], -1);
    $this->createField('group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);

    foreach (['service_category', 'service_status'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
      $this->createField('taxonomy_term', $vid, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    }
    $this->createField('taxonomy_term', 'service_status', 'field_open311_mapping', 'string');
    $this->createField('taxonomy_term', 'service_category', 'field_service_code', 'string');

    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $this->createField('paragraph', 'status', 'field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('paragraph', 'status', 'field_status_note', 'text_long');

    $this->createMediaType('image', ['id' => 'request_image', 'label' => 'Request image']);

    foreach (['service_request', 'page'] as $bundle) {
      $this->createField('node', $bundle, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
      $this->createField('node', $bundle, 'body', 'text_long');
    }
    $this->createField('node', 'service_request', 'field_category', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_status_notes', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
    $this->createField('node', 'service_request', 'field_geolocation', 'geolocation');
    $this->createField('node', 'service_request', 'field_request_media', 'entity_reference', ['target_type' => 'media'], -1);
    $this->createField('node', 'service_request', 'field_organisation', 'entity_reference', ['target_type' => 'group'], -1);
    $this->createField('node', 'service_request', 'field_notification', 'boolean');
    $this->createField('node', 'service_request', 'field_e_mail', 'email');
    $this->createField('node', 'service_request', 'field_priority', 'string');
    ParagraphsType::create(['id' => 'internal_remark', 'label' => 'Internal remark'])->save();
    $this->createField('paragraph', 'internal_remark', 'field_internal_remark_text', 'text_long');
    $this->createField('node', 'service_request', 'field_internal_remark', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);

    $translation = $this->container->get('content_translation.manager');
    $translation->setEnabled('taxonomy_term', 'service_category', TRUE);
    $translation->setEnabled('taxonomy_term', 'service_status', TRUE);
    $translation->setEnabled('node', 'page', TRUE);
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    // A seed directory with the reference seed plus a photo for one report.
    // A real directory, not the vfs site directory: seed paths are resolved
    // with realpath(), which stream wrappers do not support.
    $this->seedDir = sys_get_temp_dir() . '/markaspot-demo-seed-' . bin2hex(random_bytes(6));
    mkdir($this->seedDir . '/images', 0777, TRUE);
    $image = imagecreatetruecolor(8, 6);
    imagepng($image, $this->seedDir . '/images/crossing.png');
    $this->writeSeed('demo-school-routes');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (glob($this->seedDir . '/{,images/}*', GLOB_BRACE) ?: [] as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }
    @rmdir($this->seedDir . '/images');
    @rmdir($this->seedDir);
    parent::tearDown();
  }

  /**
   * A seed creates a permanent, multilingual demo workspace.
   */
  public function testSeedCreatesPermanentMultilingualDemo(): void {
    $result = $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
    $group = Group::load($result['group_id']);
    $this->assertInstanceOf(GroupInterface::class, $group);

    $this->assertTrue($result['created']);
    $this->assertSame('Lindenhof School Routes', $group->label());
    $this->assertSame('heart', $group->get('field_tier')->value);
    $this->assertSame('public', $group->get('field_visibility')->value);
    $this->assertTrue($group->get('field_expiry_date')->isEmpty(), 'A template demo is permanent.');

    $config = DemoWorkspace::nuxtConfig($group);
    $this->assertSame(['en', 'es', 'fr', 'de'], $config['languages']['available']);
    $this->assertSame('en', $config['languages']['default']);
    $this->assertSame('safe-routes', $config['demo']['template']);
    $this->assertSame(1, $config['demo']['seedVersion']);
    $this->assertStringStartsWith('Öffne die gefährliche Querung', $config['demo']['task']['de']);
    $this->assertSame('Gefahrenstellen auf dem Schulweg melden', $config['client']['claim']['de']);

    // Categories and statuses carry real term translations.
    $categories = $this->terms('service_category', (int) $group->id());
    $this->assertCount(5, $categories);
    $crossing = $this->seededEntity('taxonomy_term', 'category', 'crossing');
    $this->assertSame('Dangerous crossing', $crossing->label());
    $this->assertSame('Gefährliche Querung', $crossing->getTranslation('de')->label());
    $this->assertSame('Cruce peligroso', $crossing->getTranslation('es')->label());
    $this->assertCount(4, $this->terms('service_status', (int) $group->id()));
    $this->assertSame('Rückfrage', $this->seededEntity('taxonomy_term', 'status', 'question')->getTranslation('de')->label());
    $this->assertCount(5, $group->get('field_service_categories'));

    // Reports: language neutral, translated through the demo block.
    $reports = $this->reports((int) $group->id());
    $this->assertCount(7, $reports);
    $translations = $config['demo']['translations'];
    $this->assertSame(array_values($result['reports']), array_keys($translations));
    foreach ($reports as $report) {
      $this->assertSame('und', $report->language()->getId());
      $requestId = (string) $report->get('request_id')->value;
      $this->assertArrayHasKey($requestId, $translations);
      $this->assertSame(['en', 'es', 'fr', 'de'], array_keys($translations[$requestId]));
      $this->assertCount($report->get('field_status_notes')->count(), $translations[$requestId]['fr']['statusNotes']);
      $this->assertStringNotContainsString('[demo-content]', (string) $report->get('body')->value);
      $this->assertFalse((bool) $report->get('field_notification')->value);
    }

    // The question case: three history entries, oldest first, final status.
    $crossingReport = $this->seededEntity('node', 'report', 'dangerous-crossing');
    $this->assertInstanceOf(NodeInterface::class, $crossingReport);
    $notes = $crossingReport->get('field_status_notes')->referencedEntities();
    $this->assertCount(3, $notes);
    $this->assertLessThan($notes[1]->get('created')->value, $notes[0]->get('created')->value);
    $this->assertLessThan($notes[2]->get('created')->value, $notes[1]->get('created')->value);
    $inProgress = $this->seededEntity('taxonomy_term', 'status', 'in-progress');
    $this->assertSame((int) $inProgress->id(), (int) $crossingReport->get('field_status')->target_id);
    $this->assertSame((int) $inProgress->id(), (int) $notes[2]->get('field_status_term')->target_id);
    $this->assertStringContainsString('afternoon', (string) $notes[1]->get('field_status_note')->value);
    $this->assertSame(
      'Gracias por el aviso. ¿Ocurre sobre todo antes de que empiecen las clases o también por la tarde?',
      $translations[$crossingReport->get('request_id')->value]['es']['statusNotes'][1],
    );
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->assertEqualsWithDelta($now - 21 * 86400, (int) $crossingReport->getCreatedTime(), 86400);
    $this->assertNotEmpty($crossingReport->get('field_organisation')->target_id, 'The report is assigned to the seeded organisation.');

    // The solved case.
    $sidewalk = $this->seededEntity('node', 'report', 'blocked-sidewalk');
    $this->assertSame((int) $this->seededEntity('taxonomy_term', 'status', 'resolved')->id(), (int) $sidewalk->get('field_status')->target_id);

    // The photo is stored as published request media.
    $media = $crossingReport->get('field_request_media')->entity;
    $this->assertInstanceOf(Media::class, $media);
    $this->assertTrue($media->isPublished());
    $this->assertFileExists($media->get('field_media_image')->entity->getFileUri());
    $this->assertTrue($this->container->get('keyvalue')->get('markaspot_open311.media_publication_manual')->get($media->id()));

    // The start page is translated and carries the mini task.
    $page = $this->seededEntity('node', 'page', 'start');
    $this->assertTrue($page->isPromoted());
    $this->assertSame('Willkommen bei der Schulwegkarte', $page->getTranslation('de')->label());
    $this->assertStringContainsString('Melde dann eine weitere Stelle', (string) $page->getTranslation('de')->get('body')->value);

    // The public contract shape.
    $delivered = DemoWorkspace::sanitizeForDelivery(DemoWorkspace::block($group));
    $this->assertSame($translations, $delivered['translations']);
  }

  /**
   * Seeding twice changes nothing.
   */
  public function testSeedIsIdempotent(): void {
    $seed = DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml');
    $first = $this->seeder()->seed($seed);
    $before = $this->inventory($first['group_id']);

    $second = $this->seeder()->seed($seed);

    $this->assertFalse($second['created']);
    $this->assertSame($first['group_id'], $second['group_id']);
    $this->assertSame($first['reports'], $second['reports']);
    $this->assertSame($before, $this->inventory($second['group_id']));
  }

  /**
   * A seed never takes over a workspace without a demo block.
   */
  public function testSeedRefusesCustomerWorkspace(): void {
    Group::create(['type' => 'jur', 'label' => 'Customer', 'field_slug' => 'demo-school-routes'])->save();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('without a demo block');
    $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
  }

  /**
   * Reset restores the allowlisted demo and touches nothing else.
   */
  public function testResetOnlyTouchesAllowlistedDemoWorkspaces(): void {
    $demo = Group::load($this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'))['group_id']);
    $this->writeSeed('demo-other');
    $otherDemo = Group::load($this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-other.yml'))['group_id']);
    $customer = Group::create(['type' => 'jur', 'label' => 'Customer', 'field_slug' => 'customer']);
    $customer->save();
    $this->config('markaspot_fastmap.settings')->set('demo_workspaces', ['demo-school-routes', 'customer'])->save();

    $requestIdsBefore = DemoWorkspace::block($demo)['translations'];
    $crossing = $this->seededEntity('taxonomy_term', 'category', 'crossing');

    // Visitor content in the allowlisted demo.
    $visitorMedia = $this->createVisitorMedia();
    $visitorReport = $this->createReport($demo, (int) $crossing->id(), $visitorMedia);
    $visitorNote = $visitorReport->get('field_status_notes')->entity;
    // Dashboard changes to seeded content.
    $seeded = $this->seededEntity('node', 'report', 'dangerous-crossing');
    $remark = Paragraph::create(['type' => 'internal_remark', 'field_internal_remark_text' => 'Staff only']);
    $remark->save();
    $seeded->set('field_internal_remark', [
      ['target_id' => $remark->id(), 'target_revision_id' => $remark->getRevisionId()],
    ]);
    $seeded->set('field_priority', 'high');
    $seeded->set('body', ['value' => 'Edited by a visitor', 'format' => 'plain_text']);
    $seeded->set('field_status', $this->seededEntity('taxonomy_term', 'status', 'resolved')->id());
    $seeded->setNewRevision(TRUE);
    $seeded->save();
    $crossing->set('name', 'Renamed')->save();
    $extraTerm = Term::create(['vid' => 'service_category', 'name' => 'Extra', 'field_jurisdiction' => $demo->id()]);
    $extraTerm->save();

    // Content the reset must not touch.
    $customerCategory = Term::create([
      'vid' => 'service_category',
      'name' => 'Pothole',
      'field_jurisdiction' => $customer->id(),
    ]);
    $customerCategory->save();
    $customerReport = $this->createReport($customer, (int) $customerCategory->id());
    $otherCategory = $this->terms('service_category', (int) $otherDemo->id());
    $otherVisitorReport = $this->createReport($otherDemo, (int) reset($otherCategory)->id());

    $results = $this->reset()->resetAll();

    $this->assertIsArray($results['demo-school-routes']);
    $this->assertSame(1, $results['demo-school-routes']['removed']['node']);
    $this->assertStringContainsString('no demo block', $results['customer']);
    $this->assertArrayNotHasKey('demo-other', $results);

    // Visitor content is gone, including its history, media and file.
    $this->assertNull(Node::load($visitorReport->id()));
    $this->assertNull(Paragraph::load($visitorNote->id()));
    $this->assertNull(Media::load($visitorMedia->id()));
    $this->assertNull(File::load($visitorMedia->get('field_media_image')->target_id));
    $this->assertNull(Term::load($extraTerm->id()));

    // Seeded content is back in its seeded state, with the same request IDs.
    $restored = $this->seededEntity('node', 'report', 'dangerous-crossing');
    $this->assertSame((int) $seeded->id(), (int) $restored->id());
    $this->assertStringStartsWith('There is no crossing', (string) $restored->get('body')->value);
    $this->assertSame((int) $this->seededEntity('taxonomy_term', 'status', 'in-progress')->id(), (int) $restored->get('field_status')->target_id);
    $this->assertCount(3, $restored->get('field_status_notes'));
    $this->assertTrue($restored->get('field_priority')->isEmpty(), 'Dashboard-only fields are cleared.');
    $this->assertTrue($restored->get('field_internal_remark')->isEmpty());
    $this->assertNull(Paragraph::load($remark->id()), 'Internal remarks on seeded reports are deleted.');
    $this->assertSame('Dangerous crossing', $this->seededEntity('taxonomy_term', 'category', 'crossing')->label());
    $this->assertSame(array_keys($requestIdsBefore), array_keys(DemoWorkspace::block(Group::load($demo->id()))['translations']));
    $this->assertCount(7, $this->reports((int) $demo->id()));

    // Other workspaces are untouched.
    $this->assertNotNull(Node::load($customerReport->id()));
    $this->assertNotNull(Term::load($customerCategory->id()));
    $this->assertNotNull(Node::load($otherVisitorReport->id()), 'A demo workspace outside the allowlist is not reset.');
    $this->assertCount(8, $this->reports((int) $otherDemo->id()));

    // A second reset is a no-op.
    $inventory = $this->inventory((int) $demo->id());
    $again = $this->reset()->resetWorkspace('demo-school-routes');
    $this->assertSame(0, $again['removed']['node']);
    $this->assertSame($inventory, $this->inventory((int) $demo->id()));
  }

  /**
   * Reset refuses slugs outside the allowlist, even demo-flagged ones.
   */
  public function testResetRefusesSlugOutsideAllowlist(): void {
    $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
    $this->config('markaspot_fastmap.settings')->set('demo_workspaces', [])->save();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('is not listed');
    $this->reset()->resetWorkspace('demo-school-routes');
  }

  /**
   * Cron resets only when an interval is configured and has elapsed.
   */
  public function testCronResetRespectsInterval(): void {
    $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
    $group = $this->seeder()->findWorkspace('demo-school-routes');
    $crossing = $this->seededEntity('taxonomy_term', 'category', 'crossing');
    $visitor = $this->createReport($group, (int) $crossing->id());
    $this->config('markaspot_fastmap.settings')->set('demo_workspaces', ['demo-school-routes'])->save();

    // Interval 0: off.
    $this->config('markaspot_fastmap.settings')->set('demo_reset_interval', 0)->save();
    $this->reset()->runIfDue();
    $this->assertNotNull(Node::load($visitor->id()));

    $this->config('markaspot_fastmap.settings')->set('demo_reset_interval', 3600)->save();
    $this->reset()->runIfDue();
    $this->assertNull(Node::load($visitor->id()));

    // Within the interval nothing runs.
    $second = $this->createReport($group, (int) $crossing->id());
    $this->reset()->runIfDue();
    $this->assertNotNull(Node::load($second->id()));
  }

  /**
   * Returns the seeder service.
   */
  private function seeder(): object {
    $this->stubProcessor();
    return $this->container->get('markaspot_fastmap.demo_workspace_seeder');
  }

  /**
   * Returns the reset service.
   */
  private function reset(): object {
    $this->stubProcessor();
    return $this->container->get('markaspot_fastmap.demo_workspace_reset');
  }

  /**
   * Stubs the Open311 processor markaspot_group's node presave consults.
   *
   * Like production, a new report is forced to the jurisdiction's initial
   * status, so the seeder's status repair runs. Set again before each use:
   * a container rebuild drops instances set at runtime.
   */
  private function stubProcessor(): void {
    $termStorage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $this->container->set('markaspot_open311.processor', new class($termStorage) {

      public function __construct(
        private readonly EntityStorageInterface $termStorage,
      ) {}

      /**
       * Returns the lowest-weight initial status of a jurisdiction.
       */
      public function getInitialStatusTid(?int $jurisdictionId = NULL): ?int {
        $tids = $this->termStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('vid', 'service_status')
          ->condition('field_jurisdiction', $jurisdictionId)
          ->condition('field_open311_mapping', 'initial')
          ->sort('weight')
          ->range(0, 1)
          ->execute();
        return $tids ? (int) reset($tids) : NULL;
      }

    });
  }

  /**
   * Writes the reference seed under another slug, with a photo on report 0.
   */
  private function writeSeed(string $slug): void {
    $raw = Yaml::decode((string) file_get_contents(dirname(__DIR__, 3) . '/demo_seeds/demo-school-routes.yml'));
    $raw['slug'] = $slug;
    $raw['reports'][0]['photo'] = [
      'file' => 'images/crossing.png',
      'alt' => [
        'en' => 'Parked cars at the school gate',
        'es' => 'Coches aparcados en la puerta del colegio',
        'fr' => 'Voitures garées devant l\'école',
        'de' => 'Parkende Autos am Schultor',
      ],
    ];
    file_put_contents($this->seedDir . '/' . $slug . '.yml', Yaml::encode($raw));
  }

  /**
   * Loads a seeded entity of the school route demo by its seed key.
   */
  private function seededEntity(string $entityTypeId, string $kind, string $key): object {
    $uuid = DemoWorkspace::uuid((string) $this->config('system.site')->get('uuid'), 'demo-school-routes', $kind, $key);
    $matches = $this->container->get('entity_type.manager')->getStorage($entityTypeId)->loadByProperties(['uuid' => $uuid]);
    $this->assertCount(1, $matches, sprintf('Seeded %s %s exists.', $kind, $key));
    return reset($matches);
  }

  /**
   * Loads the terms of a vocabulary in a workspace.
   */
  private function terms(string $vid, int $groupId): array {
    return $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->loadByProperties([
      'vid' => $vid,
      'field_jurisdiction' => $groupId,
    ]);
  }

  /**
   * Loads the reports of a workspace.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The reports.
   */
  private function reports(int $groupId): array {
    return $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties([
      'type' => 'service_request',
      'field_jurisdiction' => $groupId,
    ]);
  }

  /**
   * Snapshot of the entity IDs a workspace consists of.
   */
  private function inventory(int $groupId): array {
    $storage = $this->container->get('entity_type.manager');
    $nids = $storage->getStorage('node')->getQuery()->accessCheck(FALSE)->condition('field_jurisdiction', $groupId)->sort('nid')->execute();
    $tids = $storage->getStorage('taxonomy_term')->getQuery()->accessCheck(FALSE)->condition('field_jurisdiction', $groupId)->sort('tid')->execute();
    $paragraphs = 0;
    $requestIds = [];
    foreach (Node::loadMultiple($nids) as $node) {
      if ($node->bundle() === 'service_request') {
        $paragraphs += $node->get('field_status_notes')->count();
        $requestIds[] = $node->get('request_id')->value;
      }
    }
    return [
      'nodes' => array_values($nids),
      'terms' => array_values($tids),
      'paragraphs' => $paragraphs,
      'request_ids' => $requestIds,
      'media' => count(Media::loadMultiple()),
      'files' => count(File::loadMultiple()),
    ];
  }

  /**
   * Creates a published visitor photo.
   */
  private function createVisitorMedia(): Media {
    $uri = 'public://visitor.png';
    imagepng(imagecreatetruecolor(4, 4), $this->container->get('file_system')->realpath('public://') . '/visitor.png');
    $file = File::create(['uri' => $uri, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'request_image',
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'x'],
      'status' => 1,
    ]);
    $media->save();
    return $media;
  }

  /**
   * Creates a visitor report with one status note.
   */
  private function createReport(GroupInterface $group, int $categoryId, ?Media $media = NULL): NodeInterface {
    $note = Paragraph::create(['type' => 'status', 'field_status_note' => 'Visitor note']);
    $note->save();
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Visitor report',
      'field_jurisdiction' => $group->id(),
      'field_category' => $categoryId,
      'field_status_notes' => [['target_id' => $note->id(), 'target_revision_id' => $note->getRevisionId()]],
      'field_request_media' => $media ? [['target_id' => $media->id()]] : [],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates a field storage (once) and attaches it to a bundle.
   */
  private function createField(string $entityType, string $bundle, string $fieldName, string $type, array $storageSettings = [], int $cardinality = 1): void {
    if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => $entityType,
        'type' => $type,
        'cardinality' => $cardinality,
        'settings' => $storageSettings,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $fieldName,
    ])->save();
  }

}
