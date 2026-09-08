<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_group\Service\WorkspaceVisibilityService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

require_once dirname(__DIR__, 3) . '/src/Service/WorkspaceVisibilityInterface.php';
require_once dirname(__DIR__, 3) . '/src/Service/WorkspaceVisibilityService.php';
require_once dirname(__DIR__, 3) . '/markaspot_group.module';
require_once dirname(__DIR__, 3) . '/markaspot_group.install';
require_once dirname(__DIR__, 4) . '/markaspot_fastmap/markaspot_fastmap.install';

/**
 * Tests translated page repair updates under an anonymous Drush-like account.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class PageScopingUpdateKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'language',
    'node',
    'entity',
    'flexible_permissions',
    'group',
  ];

  /**
   * The translated page under repair.
   */
  private int $pageId;

  /**
   * The blocked jurisdiction assigned to the page.
   */
  private int $jurisdictionId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'system',
      'user',
      'field',
      'node',
      'group',
    ]);

    User::create([
      'uid' => 0,
      'name' => '',
      'status' => 0,
    ])->save();
    $root = User::create([
      'uid' => 1,
      'name' => 'root',
      'status' => 1,
    ]);
    $root->save();
    $this->container->get('current_user')->setAccount($root);

    ConfigurableLanguage::createFromLangcode('de')->save();
    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
    GroupType::create([
      'id' => 'jur',
      'label' => 'Jurisdiction',
    ])->save();
    $this->createVisibilityField();
    $this->createJurisdictionField();

    $jurisdiction = Group::create([
      'type' => 'jur',
      'label' => 'Blocked jurisdiction',
      'field_visibility' => 'blocked',
    ]);
    $jurisdiction->save();
    $this->jurisdictionId = (int) $jurisdiction->id();

    $page = Node::create([
      'type' => 'page',
      'langcode' => 'en',
      'title' => 'Default page',
      'status' => 1,
      'promote' => 1,
      'sticky' => 1,
      'uid' => 1,
      'field_jurisdiction' => ['target_id' => $this->jurisdictionId],
    ]);
    $translation = $page->addTranslation('de', [
      'title' => 'Uebersetzung',
      'status' => 0,
      'promote' => 0,
      'sticky' => 0,
    ]);
    $translation->set('field_jurisdiction', []);
    $page->save();
    $this->pageId = (int) $page->id();

    $this->container->set(
      'markaspot_group.workspace_visibility',
      new WorkspaceVisibilityService(
        $this->container->get('entity_type.manager'),
        $this->container->get('config.factory'),
        $this->container->get('entity_field.manager'),
      ),
    );
    $this->container->get('current_user')->setAccount(
      new AnonymousUserSession(),
    );
  }

  /**
   * Tests jurisdiction repair, publication preservation, and account restore.
   */
  public function testPageScopingUpdatesRepairBlockedWorkspacePage(): void {
    $page = Node::load($this->pageId);
    $this->assertInstanceOf(Node::class, $page);
    try {
      markaspot_group_entity_presave($page);
      $this->fail('Anonymous presave should reject the blocked workspace.');
    }
    catch (AccessDeniedHttpException) {
      $this->addToAssertionCount(1);
    }

    $this->assertSame(
      'Node jurisdiction translation repair: 1 nodes and 1 translations updated; 1 field instances and 1 field storage updated; node access rebuild required.',
      markaspot_group_update_11948(),
    );
    $this->assertSame(0, (int) $this->container->get('current_user')->id());
    $this->assertTrue((bool) node_access_needs_rebuild());
    $this->assertFalse(FieldStorageConfig::loadByName(
      'node',
      'field_jurisdiction',
    )?->isTranslatable());
    $this->assertFalse(FieldConfig::load(
      'node.page.field_jurisdiction',
    )?->isTranslatable());
    $copied_rows = $this->container->get('database')
      ->select('node__field_jurisdiction', 'j')
      ->condition('entity_id', $this->pageId)
      ->condition('langcode', 'de')
      ->condition('field_jurisdiction_target_id', $this->jurisdictionId)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(1, (int) $copied_rows);

    $this->assertSame(
      'Node jurisdiction translation repair: 0 nodes and 0 translations updated; 0 field instances and 0 field storage updated; node access rebuild required.',
      markaspot_group_update_11948(),
    );
    $this->assertSame(0, (int) $this->container->get('current_user')->id());

    $this->assertSame(
      'Page translation publication settings preserved; no data changes required.',
      // The cross-module update is loaded explicitly above.
      // @phpstan-ignore function.notFound
      markaspot_fastmap_update_11928(),
    );
    $this->assertSame(0, (int) $this->container->get('current_user')->id());
    $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->resetCache([$this->pageId]);
    $page = Node::load($this->pageId);
    $this->assertInstanceOf(Node::class, $page);
    foreach (['promote', 'sticky', 'status'] as $field_name) {
      $this->assertSame(
        0,
        (int) $page->getTranslation('de')->get($field_name)->value,
      );
    }

    $this->assertSame(
      'Page translation publication settings preserved; no data changes required.',
      // The cross-module update is loaded explicitly above.
      // @phpstan-ignore function.notFound
      markaspot_fastmap_update_11928(),
    );
    $this->assertSame(0, (int) $this->container->get('current_user')->id());
  }

  /**
   * Preserves both draft translations and translations of draft originals.
   */
  public function testPublicationUpdatePreservesEditorialDecisions(): void {
    $jurisdiction = Group::create([
      'type' => 'jur',
      'label' => 'Public editorial workspace',
      'field_visibility' => 'public',
    ]);
    $jurisdiction->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $page_ids = [];
    foreach ([0, 1] as $original_status) {
      $page = Node::create([
        'type' => 'page',
        'langcode' => 'en',
        'title' => 'Editorial original',
        'status' => $original_status,
        'promote' => $original_status,
        'sticky' => $original_status,
        'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      ]);
      $page->addTranslation('de', [
        'title' => 'Editorial translation',
        'status' => 1 - $original_status,
        'promote' => 1 - $original_status,
        'sticky' => 1 - $original_status,
        'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      ]);
      $page->save();
      $page_ids[$original_status] = (int) $page->id();
    }

    for ($pass = 0; $pass < 2; $pass++) {
      // The cross-module update is loaded explicitly above.
      // @phpstan-ignore function.notFound
      markaspot_fastmap_update_11928();
      $storage->resetCache($page_ids);
      foreach ($page_ids as $original_status => $page_id) {
        $page = $storage->load($page_id);
        $this->assertInstanceOf(Node::class, $page);
        foreach (['status', 'promote', 'sticky'] as $field_name) {
          $this->assertSame($original_status, (int) $page->get($field_name)->value);
          $this->assertSame(1 - $original_status, (int) $page->getTranslation('de')->get($field_name)->value);
        }
      }
    }
  }

  /**
   * Creates the jurisdiction visibility field.
   */
  private function createVisibilityField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_visibility',
      'entity_type' => 'group',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          'public' => 'Public',
          'submission_only' => 'Submission only',
          'authenticated' => 'Authenticated',
          'blocked' => 'Blocked',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_visibility',
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'Visibility',
    ])->save();
  }

  /**
   * Creates a translatable page jurisdiction field for the migration state.
   */
  private function createJurisdictionField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      'translatable' => TRUE,
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Jurisdiction',
      'translatable' => TRUE,
      'settings' => [
        'handler' => 'default:group',
        'handler_settings' => [],
      ],
    ])->save();
    $this->container->get('entity_field.manager')
      ->clearCachedFieldDefinitions();
  }

}
