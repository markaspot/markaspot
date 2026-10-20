<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AnonymousUserSession;
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
use Drupal\markaspot_fastmap\Controller\BillingController;
use Drupal\markaspot_fastmap\Controller\DemoSessionController;
use Drupal\markaspot_fastmap\Controller\WorkspaceUsageController;
use Drupal\markaspot_fastmap\Demo\DemoSeed;
use Drupal\markaspot_fastmap\Demo\DemoWorkspace;
use Drupal\markaspot_fastmap\EventSubscriber\DemoSessionSubscriber;
use Drupal\markaspot_fastmap\Service\DemoStaffAccount;
use Drupal\markaspot_fastmap\Service\DemoWorkspaceReset;
use Drupal\markaspot_group\Controller\GroupInvitationController;
use Drupal\markaspot_group\Controller\GroupMembersController;
use Drupal\markaspot_nuxt\Controller\TenantSettingsController;
use Drupal\markaspot_passwordless\Controller\PasswordlessAuthController;
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
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

/**
 * Tests seeding and resetting template demo workspaces.
 */
#[CoversClass(DemoWorkspaceSeeder::class)]
#[CoversClass(DemoWorkspaceReset::class)]
#[CoversClass(DemoStaffAccount::class)]
#[CoversClass(DemoSessionController::class)]
#[CoversClass(DemoSessionSubscriber::class)]
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
    foreach (['jur-member', 'jur-org_member', 'jur-moderator'] as $roleId) {
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
    $this->assertSame(Yaml::decode((string) file_get_contents($this->seedDir . '/demo-school-routes.yml'))['seed_version'], $config['demo']['seedVersion']);
    $this->assertStringStartsWith('Öffne die gefährliche Querung', $config['demo']['task']['de']);
    $this->assertSame('Gefahrenstellen auf dem Schulweg melden', $config['client']['claim']['de']);

    // Categories and statuses carry real term translations.
    $categories = $this->terms('service_category', (int) $group->id());
    $this->assertCount(5, $categories);
    $crossing = $this->seededEntity('taxonomy_term', 'category', 'crossing');
    $this->assertSame('Dangerous crossing', $crossing->label());
    $this->assertSame('Gefährliche Querung', $crossing->getTranslation('de')->label());
    $this->assertSame('Cruce peligroso', $crossing->getTranslation('es')->label());
    $this->assertCount(5, $this->terms('service_status', (int) $group->id()));
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

    // The question case: four history entries, oldest first, ending handed
    // over to the school or city, which stays open.
    $crossingReport = $this->seededEntity('node', 'report', 'dangerous-crossing');
    $this->assertInstanceOf(NodeInterface::class, $crossingReport);
    $notes = $crossingReport->get('field_status_notes')->referencedEntities();
    $this->assertCount(4, $notes);
    $this->assertLessThan($notes[1]->get('created')->value, $notes[0]->get('created')->value);
    $this->assertLessThan($notes[2]->get('created')->value, $notes[1]->get('created')->value);
    $this->assertLessThan($notes[3]->get('created')->value, $notes[2]->get('created')->value);
    $inProgress = $this->seededEntity('taxonomy_term', 'status', 'in-progress');
    $this->assertSame((int) $inProgress->id(), (int) $notes[2]->get('field_status_term')->target_id);
    $handedOver = $this->seededEntity('taxonomy_term', 'status', 'handed-over');
    $this->assertSame('open', $handedOver->get('field_open311_mapping')->value);
    $this->assertSame('An Schule oder Stadt übergeben', $handedOver->getTranslation('de')->label());
    $this->assertSame((int) $handedOver->id(), (int) $crossingReport->get('field_status')->target_id);
    $this->assertSame((int) $handedOver->id(), (int) $notes[3]->get('field_status_term')->target_id);
    $this->assertStringContainsString('crossing guard', (string) $notes[3]->get('field_status_note')->value);
    $this->assertStringContainsString('afternoon', (string) $notes[1]->get('field_status_note')->value);
    $this->assertSame(
      'Gracias por el aviso. ¿Ocurre sobre todo antes de que empiecen las clases o también por la tarde?',
      $translations[$crossingReport->get('request_id')->value]['es']['statusNotes'][1],
    );
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->assertEqualsWithDelta($now - 21 * 86400, (int) $crossingReport->getCreatedTime(), 86400);
    // Submitted on the way to school, answered in the evening.
    $timezone = new \DateTimeZone((string) ($this->config('system.date')->get('timezone.default') ?: date_default_timezone_get()));
    $this->assertSame('07:52', (new \DateTimeImmutable('@' . $crossingReport->getCreatedTime()))->setTimezone($timezone)->format('H:i'));
    $this->assertSame('20:40', (new \DateTimeImmutable('@' . $notes[1]->get('created')->value))->setTimezone($timezone)->format('H:i'));
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
    $hedgePhoto = $this->seededEntity('node', 'report', 'hedge-corner')->get('field_request_media')->entity;
    $this->assertInstanceOf(Media::class, $hedgePhoto);
    $this->assertTrue($hedgePhoto->isPublished());
    $this->assertStringStartsWith('A tall, dense hedge', $hedgePhoto->get('field_media_image')->alt);
    $this->assertSame(960, (int) $hedgePhoto->get('field_media_image')->height);
    // Every report of the reference seed carries a published photo.
    foreach ($this->reports((int) $group->id()) as $report) {
      $photo = $report->get('field_request_media')->entity;
      $this->assertInstanceOf(Media::class, $photo, sprintf('Report %s has a photo.', $report->get('request_id')->value));
      $this->assertTrue($photo->isPublished());
      $this->assertNotSame('', (string) $photo->get('field_media_image')->alt);
    }
    $this->assertStringStartsWith('A car drives past a 30 km/h sign', $this->seededEntity('node', 'report', 'speeding')->get('field_request_media')->entity->get('field_media_image')->alt);

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
    $sidewalkPhotoBefore = $this->seededEntity('media', 'media', 'blocked-sidewalk');
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

    // Seeded photos survive the reset unchanged.
    $sidewalkPhoto = $this->seededEntity('media', 'media', 'blocked-sidewalk');
    $this->assertSame((int) $sidewalkPhotoBefore->id(), (int) $sidewalkPhoto->id());
    $this->assertSame((int) $sidewalkPhotoBefore->get('field_media_image')->target_id, (int) $sidewalkPhoto->get('field_media_image')->target_id);
    $this->assertFileExists($sidewalkPhoto->get('field_media_image')->entity->getFileUri());
    $this->assertSame((int) $sidewalkPhoto->id(), (int) $this->seededEntity('node', 'report', 'blocked-sidewalk')->get('field_request_media')->target_id);

    // Seeded content is back in its seeded state, with the same request IDs.
    $restored = $this->seededEntity('node', 'report', 'dangerous-crossing');
    $this->assertSame((int) $seeded->id(), (int) $restored->id());
    $this->assertStringStartsWith('There is no crossing', (string) $restored->get('body')->value);
    $this->assertSame((int) $this->seededEntity('taxonomy_term', 'status', 'handed-over')->id(), (int) $restored->get('field_status')->target_id);
    $this->assertCount(4, $restored->get('field_status_notes'));
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
   * The settings API delivers the demo block only for allowlisted demos.
   */
  public function testSettingsDeliveryNeedsAllowlist(): void {
    $demo = Group::load($this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'))['group_id']);
    $customer = Group::create(['type' => 'jur', 'label' => 'Customer', 'field_slug' => 'customer']);
    $customer->save();

    $this->config('markaspot_fastmap.settings')->set('demo_workspaces', ['customer'])->save();
    [$settings, $metadata] = $this->settingsFor($demo);
    $this->assertArrayNotHasKey('demo', $settings, 'A demo outside the allowlist delivers nothing.');
    $this->assertContains('config:markaspot_fastmap.settings', $metadata->getCacheTags());
    $this->assertArrayNotHasKey('demo', $this->settingsFor($customer)[0], 'An allowlisted group without block delivers nothing.');

    $this->config('markaspot_fastmap.settings')->set('demo_workspaces', ['demo-school-routes'])->save();
    [$settings, $metadata] = $this->settingsFor($demo);
    $this->assertSame('safe-routes', $settings['demo']['template']);
    $this->assertCount(7, $settings['demo']['translations']);
    $this->assertContains('config:markaspot_fastmap.settings', $metadata->getCacheTags());

    // The showcase request is the live first report; a reset keeps its node
    // and therefore its request ID.
    $showcase = $this->seededEntity('node', 'report', 'dangerous-crossing')->get('request_id')->value;
    $this->assertSame($showcase, $settings['demo']['showcase_request_id']);
    $this->reset()->resetWorkspace('demo-school-routes', DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
    $this->assertSame($showcase, $this->settingsFor(Group::load($demo->id()))[0]['demo']['showcase_request_id']);
  }

  /**
   * Media a visitor borrowed from another workspace survives the reset.
   */
  public function testResetKeepsMediaUsedOutsideTheDemo(): void {
    $demo = Group::load($this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'))['group_id']);
    $this->config('markaspot_fastmap.settings')->set('demo_workspaces', ['demo-school-routes'])->save();
    $customer = Group::create(['type' => 'jur', 'label' => 'Customer', 'field_slug' => 'customer']);
    $customer->save();
    $customerCategory = Term::create([
      'vid' => 'service_category',
      'name' => 'Pothole',
      'field_jurisdiction' => $customer->id(),
    ]);
    $customerCategory->save();

    // A customer photo in the current revision of a customer report, and one
    // that only an older revision of that report still references.
    $customerPhoto = $this->createVisitorMedia('customer.png');
    $historicPhoto = $this->createVisitorMedia('historic.png');
    $customerReport = $this->createReport($customer, (int) $customerCategory->id(), $historicPhoto);
    $customerReport->set('field_request_media', [['target_id' => $customerPhoto->id()]]);
    $customerReport->setNewRevision(TRUE);
    $customerReport->save();

    // A visitor references both by ID from a demo report.
    $crossing = $this->seededEntity('taxonomy_term', 'category', 'crossing');
    $visitor = $this->createReport($demo, (int) $crossing->id(), $customerPhoto);
    $visitor->set('field_request_media', [
      ['target_id' => $customerPhoto->id()],
      ['target_id' => $historicPhoto->id()],
    ]);
    $visitor->save();

    $result = $this->reset()->resetWorkspace('demo-school-routes');

    $this->assertNull(Node::load($visitor->id()));
    $this->assertSame(0, $result['removed']['media']);
    $this->assertSame(2, $result['removed']['media_kept']);
    foreach ([$customerPhoto, $historicPhoto] as $photo) {
      $kept = Media::load($photo->id());
      $this->assertInstanceOf(Media::class, $kept);
      $this->assertFileExists($kept->get('field_media_image')->entity->getFileUri());
    }
    $this->assertSame((int) $customerPhoto->id(), (int) Node::load($customerReport->id())->get('field_request_media')->target_id);
  }

  /**
   * A reset from the stored seed falls back to the seeder's photo copy.
   */
  public function testResetRestoresPhotoFromSeededCopy(): void {
    $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
    $this->config('markaspot_fastmap.settings')->set('demo_workspaces', ['demo-school-routes'])->save();
    // The seed directory is not mounted where cron runs, and the photo media
    // got lost; only the seeder's copy is left.
    unlink($this->seedDir . '/images/crossing.png');
    $this->seededEntity('media', 'media', 'dangerous-crossing')->delete();

    $this->reset()->resetWorkspace('demo-school-routes');

    $media = $this->seededEntity('node', 'report', 'dangerous-crossing')->get('field_request_media')->entity;
    $this->assertInstanceOf(Media::class, $media);
    $this->assertFileExists($media->get('field_media_image')->entity->getFileUri());
  }

  /**
   * Nothing is deleted when the seed could not be re-applied completely.
   */
  public function testResetSkipsPurgeWhenPhotoCannotBeRestored(): void {
    $group = Group::load($this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'))['group_id']);
    $this->config('markaspot_fastmap.settings')
      ->set('demo_workspaces', ['demo-school-routes'])
      ->set('demo_reset_interval', 3600)
      ->save();
    $visitor = $this->createReport($group, (int) $this->seededEntity('taxonomy_term', 'category', 'crossing')->id());
    unlink($this->seedDir . '/images/crossing.png');
    $this->seededEntity('media', 'media', 'dangerous-crossing')->delete();
    unlink('public://demo-seeds/demo-school-routes/dangerous-crossing-crossing.png');

    // Cron: the failed run is not recorded, so the next cron run retries.
    $this->reset()->runIfDue();
    $this->assertNotNull(Node::load($visitor->id()));
    $this->assertNull($this->container->get('state')->get(DemoWorkspaceReset::LAST_RUN_STATE));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('reset skipped, nothing was deleted');
    try {
      $this->reset()->resetWorkspace('demo-school-routes');
    }
    finally {
      $this->assertNotNull(Node::load($visitor->id()));
    }
  }

  /**
   * Seeding never runs as whatever account happens to be current.
   */
  public function testSeedRequiresActiveOperator(): void {
    User::load(1)->block()->save();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('technical operator');
    $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
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
   * The seed creates one shared staff account, idempotently.
   */
  public function testSeedCreatesDemoStaffAccount(): void {
    $seed = DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml');
    $groupId = $this->seeder()->seed($seed)['group_id'];
    $first = $this->staffAccount()->load('demo-school-routes');
    $this->assertInstanceOf(User::class, $first);
    $hash = $first->getPassword();

    $this->seeder()->seed($seed);
    $account = $this->staffAccount()->load('demo-school-routes');
    $this->assertSame($first->id(), $account->id(), 'A second run reuses the account.');
    $this->assertCount(1, User::loadMultiple(\Drupal::entityQuery('user')->accessCheck(FALSE)->condition('name', 'demo-school-routes-team')->execute()));

    $this->assertSame('demo-school-routes-team', $account->getAccountName());
    $this->assertSame('demo-school-routes-team@civicspot.invalid', $account->getEmail());
    $this->assertTrue($account->isActive());
    // The role set is part of the security contract: changing it must be a
    // deliberate edit of this test as well.
    $this->assertSame([], DemoStaffAccount::GLOBAL_ROLES);
    $this->assertSame(['member', 'moderator'], DemoStaffAccount::MEMBERSHIP_ROLES);
    $this->assertSame(['authenticated'], $account->getRoles(), 'No global roles.');
    $this->assertNotSame($hash, $account->getPassword(), 'The random password rotates on every run.');
    $this->assertSame($this->staffAccount()->uuid('demo-school-routes'), $account->uuid());
    $this->assertTrue($this->staffAccount()->isDemoAccount($account));
    $this->assertSame('demo-school-routes', $this->staffAccount()->workspaceSlug($account));
    $this->assertSame('', $account->getPreferredLangcode(FALSE), 'No preferred language that would pin every visitor.');

    $memberships = $this->container->get('entity_type.manager')->getStorage('group_relationship')
      ->loadByProperties(['plugin_id' => 'group_membership', 'entity_id' => $account->id()]);
    $this->assertCount(1, $memberships, 'Member of the demo workspace only.');
    $membership = reset($memberships);
    $this->assertSame($groupId, (int) $membership->get('gid')->target_id);
    $this->assertSame(['jur-member', 'jur-moderator'], array_column($membership->get('group_roles')->getValue(), 'target_id'));

    // A look-alike account is not a demo account.
    $impostor = User::create(['name' => 'demo-other-team', 'mail' => 'x@example.com', 'status' => 1]);
    $impostor->save();
    $this->assertFalse($this->staffAccount()->isDemoAccount($impostor));
  }

  /**
   * A seed run renames an account that carries an older name scheme.
   */
  public function testSeedRenamesDemoStaffAccount(): void {
    $seed = DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml');
    $this->seeder()->seed($seed);
    $account = $this->staffAccount()->load('demo-school-routes');
    $uid = $account->id();

    // The first scheme doubled the prefix: demo-demo-school-routes.
    $this->container->get('current_user')->setAccount(User::load(1));
    $account->setUsername('demo-demo-school-routes')->setEmail('demo+demo-school-routes@civicspot.invalid')->save();
    $this->assertNull($this->staffAccount()->load('demo-school-routes'));

    $this->seeder()->seed($seed);
    $renamed = User::load($uid);
    $this->assertSame('demo-school-routes-team', $renamed->getAccountName());
    $this->assertSame('demo-school-routes-team@civicspot.invalid', $renamed->getEmail());
    $this->assertSame($uid, $this->staffAccount()->load('demo-school-routes')->id());
  }

  /**
   * Without an individual moderator role, the seed skips the account.
   */
  public function testSeedSkipsStaffAccountWithoutModeratorRole(): void {
    GroupRole::load('jur-moderator')->delete();
    $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
    $this->assertNull($this->staffAccount()->load('demo-school-routes'));
  }

  /**
   * The endpoint answers 404 for anything but an allowlisted, seeded demo.
   */
  public function testDemoSessionNotFound(): void {
    $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));

    // Seeded but not allowlisted.
    $this->assertSame(404, $this->postDemoSession('demo-school-routes')->getStatusCode());

    // Allowlisted, but a customer workspace without the demo block.
    Group::create(['type' => 'jur', 'label' => 'Customer', 'field_slug' => 'demo-customer'])->save();
    $this->allowlist(['demo-school-routes', 'demo-customer', 'demo-missing']);
    $this->assertSame(404, $this->postDemoSession('demo-customer')->getStatusCode());
    $this->assertSame(404, $this->postDemoSession('demo-missing')->getStatusCode());
    $this->assertSame(404, $this->postDemoSession('../etc')->getStatusCode());

    // A blocked account turns the demo login off. Only administrators (here
    // uid 1) may block it.
    $this->container->get('current_user')->setAccount(User::load(1));
    $this->staffAccount()->load('demo-school-routes')->block()->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $this->assertSame(404, $this->postDemoSession('demo-school-routes')->getStatusCode());

    $this->assertSame(400, $this->postDemoSession(NULL)->getStatusCode());
    $this->assertSame(415, $this->postDemoSession('demo-school-routes', 'text/plain')->getStatusCode());
    $this->assertTrue($this->container->get('current_user')->isAnonymous());
  }

  /**
   * A visitor is logged in as the demo account; a real session stays.
   */
  public function testDemoSessionLogin(): void {
    $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
    $this->allowlist(['demo-school-routes']);
    $account = $this->staffAccount()->load('demo-school-routes');

    // A real session is never replaced.
    $real = User::create(['name' => 'staff', 'mail' => 'staff@example.com', 'status' => 1]);
    $real->save();
    $this->container->get('current_user')->setAccount($real);
    $this->assertSame(409, $this->postDemoSession('demo-school-routes')->getStatusCode());
    $this->assertSame((int) $real->id(), (int) $this->container->get('current_user')->id());

    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $response = $this->postDemoSession('demo-school-routes');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['ok' => TRUE, 'dashboard' => '/demo-school-routes/dashboard'], json_decode((string) $response->getContent(), TRUE));
    $this->assertSame((int) $account->id(), (int) $this->container->get('current_user')->id());
    $session = $this->container->get('request_stack')->getCurrentRequest()->getSession();
    $this->assertFalse($this->staffAccount()->isSessionExpired($this->container->get('current_user'), $session));

    // Already the demo account: no new login, no flood entry.
    $flood = $this->container->get('flood');
    $response = $this->postDemoSession('demo-school-routes');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue($flood->isAllowed(DemoSessionController::FLOOD_EVENT, 2, DemoSessionController::FLOOD_WINDOW));
  }

  /**
   * New demo sessions are limited per client IP.
   */
  public function testDemoSessionFloodControl(): void {
    $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
    $this->allowlist(['demo-school-routes']);
    for ($i = 0; $i < DemoSessionController::FLOOD_THRESHOLD; $i++) {
      $this->container->get('flood')->register(DemoSessionController::FLOOD_EVENT, DemoSessionController::FLOOD_WINDOW);
    }
    $response = $this->postDemoSession('demo-school-routes');
    $this->assertSame(429, $response->getStatusCode());
    $this->assertSame('3600', $response->headers->get('Retry-After'));
    $this->assertTrue($this->container->get('current_user')->isAnonymous());
  }

  /**
   * Visitors cannot change the shared account or move it elsewhere.
   */
  public function testDemoAccountGuardRails(): void {
    $groupId = $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'))['group_id'];
    $account = $this->staffAccount()->load('demo-school-routes');
    $this->container->get('current_user')->setAccount($account);

    $this->assertFalse($account->access('update', $account));
    $this->assertFalse($account->access('delete', $account));

    // Neither credentials nor the shared language and time zone change.
    $changes = [
      'mail' => 'visitor@example.com',
      'preferred_langcode' => 'de',
      'timezone' => 'Europe/Paris',
      'init' => 'visitor@example.com',
    ];
    foreach ($changes as $field => $value) {
      $changed = $this->staffAccount()->load('demo-school-routes');
      try {
        $changed->set($field, $value)->save();
        $this->fail(sprintf('A visitor changed the demo account %s.', $field));
      }
      catch (EntityStorageException $e) {
        $this->assertStringContainsString('cannot be changed', $e->getMessage());
      }
      $this->container->get('entity_type.manager')->getStorage('user')->resetCache([$changed->id()]);
    }
    $preferences = Request::create('/api/auth/preferences', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"preferred_langcode":"de"}');
    $this->assertSame(403, PasswordlessAuthController::create($this->container)->updatePreferences($preferences)->getStatusCode());

    // No membership outside the demo workspace.
    $this->container->get('current_user')->setAccount(User::load(1));
    $other = Group::create(['type' => 'jur', 'label' => 'Other', 'field_slug' => 'other']);
    $other->save();
    try {
      $other->addMember($this->staffAccount()->load('demo-school-routes'));
      $this->fail('The demo account joined another workspace.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('only be a member of its demo workspace', $e->getMessage());
    }
    $this->assertNotFalse(Group::load($groupId)->getMember($account));

    // Mail to the reserved domain is dropped.
    $message = ['to' => 'demo-school-routes-team@civicspot.invalid', 'send' => TRUE];
    markaspot_fastmap_mail_alter($message);
    $this->assertFalse($message['send']);
    $message = ['to' => 'Demo <demo-school-routes-team@civicspot.invalid>, staff@example.com', 'send' => TRUE];
    markaspot_fastmap_mail_alter($message);
    $this->assertTrue($message['send']);
    $this->assertSame('staff@example.com', $message['to']);
  }

  /**
   * Only the endpoint starts a demo session; old sessions end.
   */
  public function testDemoSessionLifetime(): void {
    $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
    $this->allowlist(['demo-school-routes']);
    $account = $this->staffAccount()->load('demo-school-routes');
    $currentUser = $this->container->get('current_user');

    // Any other login path is ended right away.
    user_login_finalize($account);
    $this->assertTrue($currentUser->isAnonymous());

    $this->assertSame(200, $this->postDemoSession('demo-school-routes')->getStatusCode());
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $subscriber = $this->container->get('markaspot_fastmap.demo_session_subscriber');
    $event = new RequestEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
    $subscriber->onRequest($event);
    $this->assertSame((int) $account->id(), (int) $currentUser->id(), 'A fresh session stays.');

    $marker = $request->getSession()->get(DemoStaffAccount::SESSION_KEY);
    // The login ran on a later request time than the test's main request.
    $marker['started'] -= DemoStaffAccount::MAX_SESSION_AGE + 60;
    $request->getSession()->set(DemoStaffAccount::SESSION_KEY, $marker);
    $subscriber->onRequest($event);
    $this->assertTrue($currentUser->isAnonymous(), 'An expired session is logged out.');
  }

  /**
   * The reset ends every session of the demo account.
   */
  public function testResetDeletesDemoSessions(): void {
    $seed = DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml');
    $this->seeder()->seed($seed);
    $this->allowlist(['demo-school-routes']);
    $account = $this->staffAccount()->load('demo-school-routes');

    $database = $this->container->get('database');
    $database->schema()->createTable('sessions', [
      'fields' => [
        'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'sid' => ['type' => 'varchar_ascii', 'length' => 128, 'not null' => TRUE],
        'timestamp' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
      ],
      'primary key' => ['sid'],
    ]);
    foreach (['a' => $account->id(), 'b' => $account->id(), 'c' => 1] as $sid => $uid) {
      $database->insert('sessions')->fields(['sid' => $sid, 'uid' => $uid, 'timestamp' => 1])->execute();
    }

    // Leave the password alone: core also ends sessions when it changes.
    $this->container->get('markaspot_fastmap.demo_staff_account')->deleteSessions($account);
    $this->assertSame(['c'], $database->select('sessions', 's')->fields('s', ['sid'])->execute()->fetchCol());

    $database->insert('sessions')->fields(['sid' => 'd', 'uid' => $account->id(), 'timestamp' => 1])->execute();
    $this->reset()->resetWorkspace('demo-school-routes', $seed);
    $this->assertSame(['c'], $database->select('sessions', 's')->fields('s', ['sid'])->execute()->fetchCol());
  }

  /**
   * The auth status tells the frontend about the demo account.
   */
  public function testAuthStatusReportsDemoAccount(): void {
    $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
    $currentUser = $this->container->get('current_user');
    $request = Request::create('/api/auth/status');

    $currentUser->setAccount($this->staffAccount()->load('demo-school-routes'));
    $status = json_decode((string) PasswordlessAuthController::create($this->container)->status($request)->getContent(), TRUE);
    $this->assertTrue($status['user']['is_demo_account']);

    $currentUser->setAccount(User::load(1));
    $status = json_decode((string) PasswordlessAuthController::create($this->container)->status($request)->getContent(), TRUE);
    $this->assertFalse($status['user']['is_demo_account']);
  }

  /**
   * The demo account works in its own workspace and nowhere else.
   */
  public function testDemoAccountIsScopedToItsWorkspace(): void {
    // Site and group permissions as the profile ships them for staff
    // memberships and for outsiders of a public workspace.
    Role::load('authenticated')->grantPermission('access content')->save();
    GroupRole::load('jur-member')->grantPermissions([
      'view group',
      'view group_node:service_request entity',
      'view unpublished group_node:service_request entity',
      'update any group_node:service_request entity',
    ])->save();
    GroupRole::create([
      'id' => 'jur-outsider',
      'label' => 'Outsider',
      'group_type' => 'jur',
      'scope' => 'outsider',
      'global_role' => 'authenticated',
      'permissions' => ['view group', 'view group_node:service_request entity'],
    ])->save();

    $result = $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
    $account = $this->staffAccount()->load('demo-school-routes');
    $own = $this->seededEntity('node', 'report', 'dangerous-crossing');
    // Per-jurisdiction sequence: the first seeded report is the deep link
    // target of the solution pages.
    $this->assertSame('1-' . date('Y'), $result['reports']['dangerous-crossing']);
    $this->assertSame($own->get('request_id')->value, DemoWorkspace::block(Group::load($result['group_id']))['showcase_request_id']);

    $this->writeSeed('demo-other');
    $otherId = $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-other.yml'))['group_id'];
    $foreign = $this->createReport(Group::load($otherId), (int) $this->terms('service_category', $otherId)[array_key_first($this->terms('service_category', $otherId))]->id());
    $foreign->setUnpublished()->save();

    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('node');
    $handler->resetCache();
    $this->assertTrue($own->access('update', $account), 'Edits reports of its demo workspace.');
    $this->assertFalse($own->access('delete', $account));
    $this->assertFalse($foreign->access('update', $account), 'Cannot edit reports of another workspace.');
    $this->assertFalse($foreign->access('delete', $account));
    $this->assertFalse($foreign->access('view', $account), 'Cannot see unpublished reports of another workspace.');

    // No workspace administration, here or elsewhere.
    $group = Group::load($result['group_id']);
    foreach (['update', 'delete'] as $operation) {
      $this->assertFalse($group->access($operation, $account));
      $this->assertFalse(Group::load($otherId)->access($operation, $account));
    }
    $this->assertFalse($group->hasPermission('administer members', $account));
    $this->assertFalse($account->hasPermission('administer users'));
  }

  /**
   * Administrative functions answer 403 to a demo session, by category.
   */
  #[DataProvider('deniedPathProvider')]
  public function testDemoSessionIsDeniedAdministration(string $category, array $denied): void {
    $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
    $this->allowlist(['demo-school-routes']);
    $this->assertSame(200, $this->postDemoSession('demo-school-routes')->getStatusCode());
    $account = $this->staffAccount()->load('demo-school-routes');
    $groupId = (string) $this->seeder()->findWorkspace('demo-school-routes')->id();

    foreach ($denied as [$method, $routePath]) {
      $path = str_replace(['{gid}', '{uid}'], [$groupId, (string) $account->id()], $routePath);
      // The request path as sent, in upper case, and with a language prefix
      // (only the matched route reveals what a prefixed path is).
      foreach ([[$path, NULL], [strtoupper($path), NULL], ['/de' . $path, $routePath]] as [$requestPath, $matchedRoute]) {
        try {
          $this->dispatchAsCurrentSession($requestPath, $method, $matchedRoute);
          $this->fail(sprintf('%s: %s %s was not denied.', $category, $method, $requestPath));
        }
        catch (AccessDeniedHttpException) {
          $this->addToAssertionCount(1);
        }
      }
      $this->assertSame((int) $account->id(), (int) $this->container->get('current_user')->id(), 'A denied request keeps the session.');
    }

    // The controllers deny the demo account on their own as well.
    $resolver = $this->container->get('class_resolver');
    $controllerAccess = match ($category) {
      'billing and tier' => [
        $resolver->getInstanceFromDefinition(BillingController::class)->access('demo-school-routes', $account),
        $resolver->getInstanceFromDefinition(WorkspaceUsageController::class)->access('demo-school-routes', $account),
      ],
      'team and invitations' => [
        $resolver->getInstanceFromDefinition(GroupMembersController::class)->accessCheck($account),
        $resolver->getInstanceFromDefinition(GroupInvitationController::class)->accessCheck($account),
      ],
      'workspace settings' => [
        $resolver->getInstanceFromDefinition(TenantSettingsController::class)->accessCheck($account, $groupId),
        AccessResult::allowedIf((bool) Group::load($groupId)->access('update', $account)),
      ],
      'workspace deletion' => [
        AccessResult::allowedIf((bool) Group::load($groupId)->access('delete', $account)),
      ],
      'account self-service' => [
        AccessResult::allowedIf($account->access('delete', $account)),
      ],
      'functions outside report work' => [
        AccessResult::allowedIfHasPermission($account, 'access dashboard kpis'),
      ],
      'feedback and operator terms' => [
        AccessResult::allowedIf($account->access('update', $account)),
      ],
      'user administration' => [
        AccessResult::allowedIf($account->access('update', $account)),
        AccessResult::allowedIfHasPermission($account, 'administer users'),
      ],
    };
    foreach ($controllerAccess as $index => $access) {
      $this->assertFalse($access->isAllowed(), sprintf('%s: check %d allows the demo account.', $category, $index));
    }
  }

  /**
   * Denied paths per category.
   */
  public static function deniedPathProvider(): array {
    return [
      'billing and tier' => ['billing and tier', [
        ['GET', '/api/fastmap/billing/demo-school-routes'],
        ['PATCH', '/api/fastmap/billing/demo-school-routes'],
        ['POST', '/api/fastmap/billing/demo-school-routes/sync-token'],
        ['GET', '/api/fastmap/usage/demo-school-routes'],
        ['POST', '/api/fastmap/create-workspace'],
      ]],
      'workspace deletion' => ['workspace deletion', [
        ['POST', '/api/fastmap/workspace-deletion/request/{gid}'],
        ['POST', '/api/fastmap/workspace-deletion/peek'],
        ['POST', '/api/fastmap/workspace-deletion/confirm'],
        ['POST', '/api/fastmap/demo-content/remove'],
      ]],
      'account self-service' => ['account self-service', [
        ['POST', '/user/{uid}/cancel'],
        ['DELETE', '/jsonapi/user/user/00000000-0000-0000-0000-000000000000'],
        ['PATCH', '/api/auth/preferences'],
        ['POST', '/api/auth/request-code'],
        ['POST', '/api/auth/verify-code'],
        ['GET', '/api/auth/session-handoff/start'],
        ['POST', '/api/auth/claim-token'],
      ]],
      'functions outside report work' => ['functions outside report work', [
        ['GET', '/api/dashboard/kpis'],
        ['GET', '/api/dashboard/time-series/volume'],
        ['GET', '/api/fastmap/report-stats'],
        ['POST', '/api/ai/assist'],
        ['GET', '/api/ai/duplicates/1'],
        ['GET', '/api/moderation/flagged-requests'],
        ['GET', '/api/inbound-mail'],
        ['GET', '/api/vision/originals'],
        ['POST', '/api/escalation/1-2026'],
        ['POST', '/api/delegation/1-2026'],
        ['GET', '/api/export-jurisdictions'],
        ['GET', '/api/dashboard/mail-texts'],
        ['PATCH', '/api/dashboard/status-selection/{gid}'],
        ['PATCH', '/api/platform/maintenance'],
      ]],
      'team and invitations' => ['team and invitations', [
        ['GET', '/api/group-members'],
        ['GET', '/api/group-members/{uid}/detail'],
        ['PATCH', '/api/group-members/{uid}'],
        ['POST', '/api/group-members/invite'],
        ['GET', '/api/group-members/invitations'],
        ['DELETE', '/api/group-members/invitations/1'],
        ['POST', '/api/group-members/claim/' . str_repeat('a', 64)],
        ['POST', '/jsonapi/group_relationship/jur-group_membership'],
      ]],
      'workspace settings' => ['workspace settings', [
        ['GET', '/api/tenant-settings/{gid}/general'],
        ['PATCH', '/api/tenant-settings/{gid}/general'],
        ['PATCH', '/api/tenant-settings/{gid}/features'],
        ['POST', '/api/tenant-settings/{gid}/logo'],
        ['PATCH', '/jsonapi/group/jur/00000000-0000-0000-0000-000000000000'],
        ['GET', '/group/{gid}/edit'],
      ]],
      'user administration' => ['user administration', [
        ['GET', '/user/{uid}/edit'],
        ['POST', '/user/{uid}/cancel'],
        ['PATCH', '/jsonapi/user/user/00000000-0000-0000-0000-000000000000'],
        ['GET', '/jsonapi/user/user'],
        ['GET', '/admin/people'],
        ['GET', '/api/admin/jurisdictions'],
        ['POST', '/api/auth/switch-user'],
        ['GET', '/user/{uid}'],
        ['POST', '/user/password'],
        ['GET', '/node/add/page'],
      ]],
      'feedback and operator terms' => ['feedback and operator terms', [
        ['POST', '/api/feedback/00000000-0000-0000-0000-000000000000'],
        ['GET', '/feedback/00000000-0000-0000-0000-000000000000'],
        ['POST', '/api/accept-tos'],
      ]],
    ];
  }

  /**
   * Report work and the demo's own session paths stay open.
   */
  public function testDemoSessionKeepsWorkPaths(): void {
    $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'));
    $this->allowlist(['demo-school-routes']);
    $this->assertSame(200, $this->postDemoSession('demo-school-routes')->getStatusCode());
    foreach ([
      ['GET', '/api/auth/status'],
      ['POST', '/api/auth/logout'],
      ['POST', '/api/fastmap/demo-session'],
      ['GET', '/jsonapi/node/service_request'],
      ['PATCH', '/jsonapi/node/service_request/00000000-0000-0000-0000-000000000000'],
      ['GET', '/jsonapi/group/jur'],
      ['GET', '/api/mark-a-spot-settings'],
      ['POST', '/jsonapi/node/service_request'],
      ['POST', '/api/dashboard/status-notes'],
      ['PATCH', '/api/request-responsibility/1'],
      ['GET', '/api/request-assignees/1'],
      ['GET', '/api/dashboard/status-selection/1'],
      ['GET', '/api/platform/maintenance'],
    ] as [$method, $path]) {
      $this->dispatchAsCurrentSession($path, $method);
      $this->dispatchAsCurrentSession('/de' . $path, $method, $path);
    }
    $this->assertFalse($this->container->get('current_user')->isAnonymous());

    // Regular accounts are not affected by the list.
    $this->container->get('current_user')->setAccount(User::load(1));
    $this->dispatchAsCurrentSession('/api/group-members', 'GET');
    $this->addToAssertionCount(1);
  }

  /**
   * Runs the demo session subscriber for a request on the current session.
   */
  private function dispatchAsCurrentSession(string $path, string $method, ?string $routePath = NULL): void {
    $session = $this->container->get('request_stack')->getCurrentRequest()->getSession();
    $request = Request::create($path, $method);
    $request->setSession($session);
    $event = new RequestEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
    $subscriber = $this->container->get('markaspot_fastmap.demo_session_subscriber');
    $subscriber->onRequest($event);
    // What the router would have matched for the path.
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route($routePath ?? $path));
    $subscriber->onRoutedRequest($event);
  }

  /**
   * The reset removes abandoned uploads of the demo, nothing else.
   */
  public function testResetRemovesOrphanUploads(): void {
    $seed = DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml');
    $demoId = (int) $this->seeder()->seed($seed)['group_id'];
    $this->writeSeed('demo-other');
    $otherId = (int) $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-other.yml'))['group_id'];
    $this->config('markaspot_fastmap.settings')->set('demo_workspaces', ['demo-school-routes'])->save();
    $bindings = $this->container->get('keyvalue')->get(DemoWorkspaceReset::UPLOAD_BINDING_STORE);

    $now = $this->container->get('datetime.time')->getRequestTime();
    $upload = function (string $name, int $boundTo, bool $old) use ($bindings, $now): Media {
      $media = $this->createVisitorMedia($name . '.png');
      $media->setUnpublished();
      $media->setCreatedTime($old ? $now - DemoWorkspaceReset::ORPHAN_UPLOAD_GRACE - 60 : $now);
      $media->save();
      $bindings->set($media->uuid(), $boundTo);
      return $media;
    };
    $abandoned = $upload('abandoned', $demoId, TRUE);
    $inProgress = $upload('in-progress', $demoId, FALSE);
    $elsewhere = $upload('elsewhere', $otherId, TRUE);
    $usedOutside = $upload('used-outside', $demoId, TRUE);
    $this->createReport(Group::load($otherId), (int) $this->seededEntityOf('demo-other', 'taxonomy_term', 'category', 'crossing')->id(), $usedOutside);
    // Seeded photos are never orphans, even if bound to the workspace.
    $seededPhoto = $this->seededEntity('node', 'report', 'dangerous-crossing')->get('field_request_media')->entity;
    $bindings->set($seededPhoto->uuid(), $demoId);

    $result = $this->reset()->resetWorkspace('demo-school-routes', $seed);

    $this->assertNull(Media::load($abandoned->id()), 'An abandoned upload of the demo is removed.');
    $this->assertSame(1, $result['removed']['media']);
    $this->assertNotNull(Media::load($inProgress->id()), 'An upload of a form in progress stays.');
    $this->assertNotNull(Media::load($elsewhere->id()), 'Uploads of other workspaces stay.');
    $this->assertNotNull(Media::load($usedOutside->id()), 'Uploads used by a report elsewhere stay.');
    $this->assertNotNull(Media::load($seededPhoto->id()), 'Seeded photos stay.');
  }

  /**
   * Loads a seeded entity of another demo by its seed key.
   */
  private function seededEntityOf(string $slug, string $entityTypeId, string $kind, string $key): object {
    $uuid = DemoWorkspace::uuid((string) $this->config('system.site')->get('uuid'), $slug, $kind, $key);
    $matches = $this->container->get('entity_type.manager')->getStorage($entityTypeId)->loadByProperties(['uuid' => $uuid]);
    return reset($matches);
  }

  /**
   * A demo gets DemoWorkspace::AI_BUDGET per reset window, others their tier.
   */
  public function testDemoAiBudgetPerResetWindow(): void {
    $this->installSchema('markaspot_fastmap', ['markaspot_ai_usage']);
    $seed = DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml');
    $demoId = (int) $this->seeder()->seed($seed)['group_id'];
    $this->writeSeed('demo-other');
    $unlistedDemoId = (int) $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-other.yml'))['group_id'];
    $customer = Group::create([
      'type' => 'jur',
      'label' => 'Customer',
      'field_slug' => 'customer',
      'field_tier' => 'heart',
    ]);
    $customer->save();
    $customerId = (int) $customer->id();
    $this->config('markaspot_fastmap.settings')->set('demo_workspaces', ['demo-school-routes'])->save();
    $tierConfig = $this->container->get('markaspot_fastmap.tier_config');

    $this->assertSame(30, DemoWorkspace::AI_BUDGET);
    $this->assertSame(DemoWorkspace::AI_BUDGET, $tierConfig->getEffectiveAIBudget($demoId, 'heart'));
    $this->assertSame(100, $tierConfig->getEffectiveAIBudget($customerId, 'heart'), 'A regular heart workspace keeps its tier budget.');
    $this->assertSame(100, $tierConfig->getEffectiveAIBudget($unlistedDemoId, 'heart'), 'Only allowlisted demos get the demo budget.');

    for ($i = 1; $i < DemoWorkspace::AI_BUDGET; $i++) {
      $tierConfig->recordAIAnalysis($demoId);
      $tierConfig->recordAIAnalysis($customerId);
    }
    $this->assertFalse($tierConfig->isAIBudgetExhausted($demoId, 'heart'));
    $tierConfig->recordAIAnalysis($demoId);
    $tierConfig->recordAIAnalysis($customerId);
    $this->assertTrue($tierConfig->isAIBudgetExhausted($demoId, 'heart'), 'The demo is blocked at 30 analyses.');
    $this->assertFalse($tierConfig->isAIBudgetExhausted($customerId, 'heart'), 'A regular heart workspace goes on to 100.');

    $result = $this->reset()->resetWorkspace('demo-school-routes', $seed);
    $this->assertSame(DemoWorkspace::AI_BUDGET, $result['removed']['ai_usage']);
    $this->assertFalse($tierConfig->isAIBudgetExhausted($demoId, 'heart'), 'The reset lifts the block.');
    $this->assertSame(0, $tierConfig->countAIAnalyses($demoId));
    $this->assertSame(DemoWorkspace::AI_BUDGET, $tierConfig->countAIAnalyses($customerId), 'The reset never touches other workspaces.');

    // The settings API reports the demo budget to the frontend.
    $budget = $this->settingsFor(Group::load($demoId))[0]['jurisdiction']['ai_budget'];
    $this->assertSame(['limit' => DemoWorkspace::AI_BUDGET, 'used' => 0, 'remaining' => DemoWorkspace::AI_BUDGET], $budget);
  }

  /**
   * Content of a demo session carries no mail trigger, contact or markup.
   */
  public function testDemoSessionContentIsNeutralized(): void {
    $groupId = (int) $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'))['group_id'];
    $currentUser = $this->container->get('current_user');
    $values = [
      'type' => 'service_request',
      'title' => 'Visitor report',
      'field_jurisdiction' => $groupId,
      'field_notification' => TRUE,
      'field_e_mail' => 'someone@example.com',
      'body' => [
        'value' => '<p onclick="x()">Hello <a href="https://example.com">link</a></p>',
        'format' => 'basic_html',
      ],
    ];

    $currentUser->setAccount($this->staffAccount()->load('demo-school-routes'));
    $report = Node::create($values);
    $report->save();
    $this->assertFalse((bool) $report->get('field_notification')->value, 'No notification mail to a typed-in address.');
    $this->assertTrue($report->get('field_e_mail')->isEmpty(), 'No contact data stays for the next visitor.');
    $this->assertSame('plain_text', $report->get('body')->format, 'Text is stored as plain text.');
    $note = Paragraph::create([
      'type' => 'status',
      'field_status_note' => ['value' => '<b>note</b>', 'format' => 'basic_html'],
    ]);
    $note->save();
    $this->assertSame('plain_text', $note->get('field_status_note')->format);

    // Regular accounts keep what they enter.
    $currentUser->setAccount(User::load(1));
    $regular = Node::create($values);
    $regular->save();
    $this->assertTrue((bool) $regular->get('field_notification')->value);
    $this->assertSame('someone@example.com', $regular->get('field_e_mail')->value);
    $this->assertSame('basic_html', $regular->get('body')->format);
  }

  /**
   * A demo session writes reports of its demo workspace only.
   */
  public function testDemoSessionWritesOnlyItsWorkspace(): void {
    $demoId = (int) $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'))['group_id'];
    $customer = Group::create(['type' => 'jur', 'label' => 'Customer', 'field_slug' => 'customer']);
    $customer->save();
    $category = $this->seededEntity('taxonomy_term', 'category', 'crossing');
    $foreign = $this->createReport($customer, (int) $category->id());
    $foreign->set('field_e_mail', 'citizen@example.org')->save();
    $page = Node::create(['type' => 'page', 'title' => 'Start', 'field_jurisdiction' => $demoId]);
    $page->save();

    $account = $this->staffAccount()->load('demo-school-routes');
    $this->container->get('current_user')->setAccount($account);
    $nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');
    $refused = function (callable $save, string $message): void {
      try {
        $save();
        $this->fail($message);
      }
      catch (EntityStorageException) {
        // Refused, by the demo guard or an earlier jurisdiction guard.
        $this->addToAssertionCount(1);
      }
    };

    // A foreign report (the feedback path saves any report it names) is
    // refused before the neutralisation touches its contact data.
    $refused(fn() => $nodeStorage->load($foreign->id())->set('title', 'Feedback')->save(), 'A foreign report was saved.');
    $nodeStorage->resetCache([$foreign->id()]);
    $this->assertSame('citizen@example.org', $nodeStorage->load($foreign->id())->get('field_e_mail')->value);

    $refused(fn() => Node::create(['type' => 'service_request', 'title' => 'x', 'field_jurisdiction' => $customer->id()])->save(), 'A report was created in another workspace.');
    $own = $this->seededEntity('node', 'report', 'dangerous-crossing');
    $refused(fn() => $own->set('field_jurisdiction', [$demoId, $customer->id()])->save(), 'A report was moved into another workspace.');
    $refused(fn() => $nodeStorage->load($page->id())->set('title', 'Defaced')->save(), 'A page was changed.');
    $refused(fn() => Node::create(['type' => 'page', 'title' => 'x', 'field_jurisdiction' => $demoId])->save(), 'A page was created.');

    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('node');
    $handler->resetCache();
    $this->assertFalse($nodeStorage->load($page->id())->access('update', $account));
    $this->assertFalse($nodeStorage->load($page->id())->access('delete', $account));
    $this->assertFalse($nodeStorage->load($foreign->id())->access('update', $account));
    $this->assertFalse($handler->createAccess('page', $account));

    // A new report in the demo workspace is fine.
    $report = Node::create(['type' => 'service_request', 'title' => 'Visitor', 'field_jurisdiction' => $demoId]);
    $report->save();
    $this->assertNotNull($report->id());
  }

  /**
   * Photos of a demo session are bound to and analysed for the demo only.
   */
  public function testDemoSessionUploadsAndAnalysisStayInTheDemo(): void {
    $demoId = (int) $this->seeder()->seed(DemoSeed::fromFile($this->seedDir . '/demo-school-routes.yml'))['group_id'];
    Group::create(['type' => 'jur', 'label' => 'Customer', 'field_slug' => 'customer'])->save();
    $this->allowlist(['demo-school-routes']);
    $this->assertSame(200, $this->postDemoSession('demo-school-routes')->getStatusCode());
    $requestStack = $this->container->get('request_stack');

    foreach (['customer' => FALSE, NULL => FALSE, 'demo-school-routes' => TRUE, (string) $demoId => TRUE] as $header => $allowed) {
      $request = Request::create('/jsonapi/media/request_image', 'POST');
      if ($header !== '') {
        $request->headers->set(DemoStaffAccount::UPLOAD_JURISDICTION_HEADER, (string) $header);
      }
      $requestStack->push($request);
      try {
        $this->createVisitorMedia('upload-' . md5((string) $header) . '.png');
        $this->assertTrue($allowed, sprintf('Upload for "%s" was accepted.', $header));
      }
      catch (EntityStorageException $e) {
        $this->assertFalse($allowed, sprintf('Upload for "%s" was refused.', $header));
      }
      finally {
        $requestStack->pop();
      }
    }

    $subscriber = $this->container->get('markaspot_fastmap.demo_session_subscriber');
    foreach (['customer' => FALSE, 'demo-school-routes' => TRUE, $demoId => TRUE] as $jurisdiction => $allowed) {
      $session = $requestStack->getCurrentRequest()->getSession();
      $body = (string) json_encode(['media_ids' => ['x'], 'jurisdiction_id' => $jurisdiction]);
      $request = Request::create('/api/vision/analyze', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
      $request->setSession($session);
      $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/api/vision/analyze'));
      $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'markaspot_vision.image_processing');
      try {
        $subscriber->onRoutedRequest(new RequestEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST));
        $this->assertTrue($allowed, sprintf('Analysis for "%s" was accepted.', $jurisdiction));
      }
      catch (AccessDeniedHttpException) {
        $this->assertFalse($allowed, sprintf('Analysis for "%s" was refused.', $jurisdiction));
      }
    }
  }

  /**
   * JSON:API rules follow the configured base path.
   */
  public function testDeniedPathsFollowJsonApiBasePath(): void {
    $this->assertTrue(DemoStaffAccount::isDeniedPath('/api/jsonapi/user/user', 'GET', '/api/jsonapi'));
    $this->assertTrue(DemoStaffAccount::isDeniedPath('/api/jsonapi/group/jur/1', 'PATCH', '/api/jsonapi'));
    $this->assertFalse(DemoStaffAccount::isDeniedPath('/api/jsonapi/node/service_request', 'GET', '/api/jsonapi'));
    $this->assertTrue(DemoStaffAccount::isDeniedPath('/jsonapi/user/user', 'GET'));
  }

  /**
   * Returns the demo staff account service.
   */
  private function staffAccount(): DemoStaffAccount {
    return $this->container->get('markaspot_fastmap.demo_staff_account');
  }

  /**
   * Sets the demo allowlist.
   */
  private function allowlist(array $slugs): void {
    $this->config('markaspot_fastmap.settings')->set('demo_workspaces', $slugs)->save();
  }

  /**
   * Posts to the demo session endpoint on the current session.
   */
  private function postDemoSession(?string $slug, string $contentType = 'application/json'): JsonResponse {
    $requestStack = $this->container->get('request_stack');
    $session = $requestStack->getCurrentRequest()->getSession();
    $body = $slug === NULL ? '{}' : (string) json_encode(['workspace' => $slug]);
    $request = Request::create('/api/fastmap/demo-session', 'POST', [], [], [], ['CONTENT_TYPE' => $contentType], $body);
    $request->setSession($session);
    $requestStack->push($request);
    try {
      return DemoSessionController::create($this->container)->start($request);
    }
    finally {
      $requestStack->pop();
    }
  }

  /**
   * Runs the fastmap settings alter hook for a workspace.
   *
   * @return array{0: array, 1: \Drupal\Core\Cache\CacheableMetadata}
   *   The altered settings and the collected cache metadata.
   */
  private function settingsFor(GroupInterface $group): array {
    // The hook reports the AI budget; prime its usage counter so the test
    // needs no markaspot_ai_usage table.
    $month = date('Y-m', $this->container->get('datetime.time')->getRequestTime());
    $this->container->get('cache.default')->set('markaspot_ai_usage:' . $group->id() . ':' . $month, 0);
    $settings = [];
    $metadata = new CacheableMetadata();
    markaspot_fastmap_markaspot_nuxt_settings_alter($settings, $group, $metadata);
    return [$settings, $metadata];
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
    // CivicSpot demos have no organisations; the seeder still supports them
    // for installations with the organisations feature.
    $raw['organisations'] = [['key' => 'traffic', 'name' => 'Traffic Department']];
    $raw['reports'][0]['organisation'] = 'traffic';
    // The shipped photos of the other reports, under their own names.
    foreach (array_slice($raw['reports'], 1) as $report) {
      if (isset($report['photo'])) {
        copy(dirname(__DIR__, 3) . '/demo_seeds/' . $report['photo']['file'], $this->seedDir . '/' . $report['photo']['file']);
      }
    }
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
  private function createVisitorMedia(string $filename = 'visitor.png'): Media {
    $uri = 'public://' . $filename;
    imagepng(imagecreatetruecolor(4, 4), $uri);
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
