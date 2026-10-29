<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_feedback\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Exercises feedback reads with fictional data through Drupal's read paths.
 *
 * @group markaspot_feedback
 */
#[RunTestsInSeparateProcesses]
final class FeedbackReadAccessKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'field_permissions', 'file', 'filter', 'text',
    'node', 'taxonomy', 'entity', 'flexible_permissions', 'group', 'gnode',
    'image', 'serialization', 'jsonapi', 'views', 'rest',
    'csv_serialization', 'views_data_export', 'markaspot_group', 'markaspot_feedback',
  ];

  /**
   * Disable uid 1 bypass so fixture accounts use actual permissions.
   */
  protected bool $usesSuperUserAccessPolicy = FALSE;

  /**
   * Fictional read-path fixture: two tenants, reports and their readers.
   */
  private array $fixture;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_feedback.install';
    foreach (['user', 'node', 'file', 'group', 'group_relationship', 'group_config_wrapper'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group']);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'fixture-root', 'status' => 1])->save();
    // Let revision requests reach field access rather than only testing a
    // route denial. These are fictional fixture grants, not shipped grants.
    foreach (['anonymous', 'authenticated'] as $id) {
      Role::load($id)->grantPermission('access content')->grantPermission('view all revisions')->save();
    }
    $this->field('user', 'user', 'field_all_groups_member', 'boolean');
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    NodeType::create(['type' => 'reference', 'name' => 'Public report reference'])->save();
    foreach (['jur', 'org'] as $id) {
      $type = GroupType::create(['id' => $id, 'label' => $id]);
      $type->save();
      $storage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
      foreach (['group_membership', 'group_node:service_request'] as $plugin) {
        $relationship_type = $storage->createFromPlugin($type, $plugin);
        if (!$storage->load($relationship_type->id())) {
          $relationship_type->save();
        }
      }
      // Keep published report access open so the tests exercise the PII
      // boundary, including when the report is a Group relationship.
      foreach (['insider', 'outsider'] as $scope) {
        GroupRole::create([
          'id' => "$id-reader-$scope", 'label' => 'Published report reader',
          'group_type' => $id, 'scope' => $scope, 'global_role' => 'authenticated',
          'permissions' => ['view group_node:service_request entity'],
        ])->save();
      }
      GroupRole::create([
        'id' => "$id-public", 'label' => 'Public report reader',
        'group_type' => $id, 'scope' => 'outsider', 'global_role' => 'anonymous',
        'permissions' => ['view group_node:service_request entity'],
      ])->save();
    }
    foreach (['jur-org_member', 'jur-moderator'] as $id) {
      GroupRole::create([
        'id' => $id, 'label' => $id, 'group_type' => 'jur', 'scope' => 'individual',
      ])->save();
    }
    $this->field('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->field('group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->field('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->field('node', 'service_request', 'field_organisation', 'entity_reference', ['target_type' => 'group']);
    $this->field('node', 'reference', 'field_request', 'entity_reference', ['target_type' => 'node']);
    $this->field('node', 'service_request', 'field_e_mail', 'email', [], 'custom');
    FieldStorageConfig::loadByName('node', 'field_feedback')?->delete();
    $this->field('node', 'service_request', 'field_feedback', 'text_long', [], 'public');
    Role::create([
      'id' => 'moderator', 'label' => 'Moderator', 'permissions' => ['view field_e_mail'],
    ])->save();
    Role::create(['id' => 'contractor', 'label' => 'Service provider'])->save();
    markaspot_feedback_update_9004();

    $tenant_a = Group::create(['type' => 'jur', 'label' => 'Fictional tenant A']);
    $tenant_a->save();
    $tenant_b = Group::create(['type' => 'jur', 'label' => 'Fictional tenant B']);
    $tenant_b->save();
    $organisation = Group::create([
      'type' => 'org', 'label' => 'Fictional service provider', 'field_jurisdiction' => $tenant_a->id(),
    ]);
    $organisation->save();
    $author = $this->createUser([], 'fictional-citizen');
    $contractor = $this->createUser([], 'fictional-provider');
    $contractor->addRole('contractor')->save();
    $organisation->addMember($contractor);
    $org_member = $this->createUser([], 'fictional-org-member');
    $organisation->addMember($org_member);
    $group_moderator = $this->createUser([], 'fictional-group-moderator');
    $tenant_a->addMember($group_moderator, ['group_roles' => ['jur-moderator']]);
    $this->assertSame(['authenticated'], User::load($group_moderator->id())->getRoles());
    $moderator = $this->createUser([], 'fictional-site-moderator');
    $moderator->addRole('moderator')->save();
    $tenant_a->addMember($moderator, ['group_roles' => ['jur-moderator']]);
    $report_a = $this->report($tenant_a, $organisation, $author, 'A');
    $report_b = $this->report($tenant_b, NULL, $author, 'B');
    $reference = Node::create([
      'type' => 'reference', 'title' => 'Fictional public reference', 'status' => 1,
      'field_request' => $report_a->id(),
    ]);
    $reference->save();
    $this->fixture = compact('author', 'contractor', 'org_member', 'group_moderator', 'moderator', 'report_a', 'report_b', 'reference');
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Non-PII readers cannot see feedback through any supported read path.
   */
  public function testUnprivilegedReadPaths(): void {
    $report = $this->fixture['report_a'];
    $revision = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($report->getRevisionId() - 1);
    $this->assertSame($report->id(), $revision->id());
    $readers = ['anonymous' => User::getAnonymousUser()];
    foreach (['author', 'contractor', 'org_member', 'group_moderator'] as $key) {
      $readers[$key] = $this->fixture[$key];
    }
    foreach ($readers as $persona => $reader) {
      $this->setCurrentUser($reader);
      $this->assertTrue($report->access('view', $reader), $persona . ': published report remains readable');
      $this->assertFalse($report->get('field_feedback')->access('view', $reader), $persona);
      $this->assertFalse($report->get('field_feedback')->access('edit', $reader), $persona);
      $this->assertFalse($revision->get('field_feedback')->access('view', $reader), $persona . ': revision field');
      $direct = $this->json('/jsonapi/node/service_request/' . $report->uuid());
      $this->assertFeedbackAbsent($direct['data'], $persona . ': direct JSON:API');
      $included = $this->json('/jsonapi/node/reference/' . $this->fixture['reference']->uuid() . '?include=field_request');
      $resources = array_values(array_filter($included['included'] ?? [], static fn(array $resource): bool => $resource['type'] === 'node--service_request'));
      $this->assertCount(1, $resources, $persona . ': included report was actually serialized');
      $this->assertFeedbackAbsent($resources[0], $persona . ': include');
      $historic = $this->json('/jsonapi/node/service_request/' . $report->uuid() . '?resourceVersion=id:' . $revision->getRevisionId());
      $this->assertFeedbackAbsent($historic['data'], $persona . ': revision JSON:API');
      $csv = $this->csv($report);
      $this->assertStringContainsString('Fictional report A', $csv, $persona . ': export actually rendered the report');
      $this->assertStringNotContainsString('Fictional confidential feedback', $csv, $persona . ': CSV');
    }
  }

  /**
   * Mirrored staff grants retain the same tenant boundary as the e-mail field.
   */
  public function testSiteModeratorReadPathsAreScoped(): void {
    $this->setCurrentUser($this->fixture['moderator']);
    foreach (['report_a' => TRUE, 'report_b' => FALSE] as $key => $allowed) {
      $report = $this->fixture[$key];
      foreach (['field_e_mail', 'field_feedback'] as $field) {
        $this->assertSame($allowed, $report->get($field)->access('view', $this->fixture['moderator']), "$key/$field");
      }
      $document = $this->json('/jsonapi/node/service_request/' . $report->uuid());
      $this->assertSame($allowed, isset($document['data']['attributes']['field_feedback']), $key);
      if (!$allowed) {
        $this->assertStringNotContainsString('Fictional confidential feedback', $this->csv($report), $key . ': CSV');
      }
    }
    $report = $this->fixture['report_a'];
    $this->assertStringContainsString('Fictional confidential feedback A current', $this->csv($report));
    $historic = $this->json('/jsonapi/node/service_request/' . $report->uuid() . '?resourceVersion=id:' . ($report->getRevisionId() - 1));
    $this->assertSame('Fictional confidential feedback A historic', $historic['data']['attributes']['field_feedback']['value']);
  }

  /**
   * Filtering and sorting cannot disclose feedback through collection results.
   */
  public function testFeedbackFilterAndSortAreRefused(): void {
    foreach ([User::getAnonymousUser(), $this->fixture['moderator']] as $reader) {
      $this->setCurrentUser($reader);
      $report = $this->fixture['report_b'];
      $this->assertTrue($report->access('view', $reader));
      $this->assertFalse($report->get('field_feedback')->access('view', $reader));
      foreach ([
        ['filter' => ['id' => $report->uuid(), 'field_feedback.value' => 'Fictional confidential feedback B current']],
        ['filter' => ['id' => $report->uuid()], 'sort' => 'field_feedback.value'],
      ] as $query) {
        $document = $this->json('/jsonapi/node/service_request?' . http_build_query($query), 403);
        $this->assertArrayNotHasKey('data', $document);
        $this->assertStringContainsString('field_feedback', $document['errors'][0]['detail']);
        $this->assertStringNotContainsString('Fictional confidential feedback', json_encode($document, JSON_THROW_ON_ERROR));
      }
    }
  }

  /**
   * Creates a field with optional custom/public field permissions.
   */
  private function field(string $entity_type, string $bundle, string $name, string $type, array $settings = [], ?string $permission_type = NULL): void {
    if (!FieldStorageConfig::loadByName($entity_type, $name)) {
      FieldStorageConfig::create([
        'field_name' => $name, 'entity_type' => $entity_type, 'type' => $type,
        'settings' => $settings,
        'third_party_settings' => $permission_type === NULL ? [] : [
          'field_permissions' => ['permission_type' => $permission_type],
        ],
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name, 'entity_type' => $entity_type, 'bundle' => $bundle,
    ])->save();
  }

  /**
   * Creates two revisions with distinct fictional confidential values.
   */
  private function report(Group $tenant, ?Group $organisation, AccountInterface $author, string $label): NodeInterface {
    $report = Node::create([
      'type' => 'service_request', 'title' => 'Fictional report ' . $label, 'status' => 1,
      'uid' => $author->id(), 'field_jurisdiction' => $tenant->id(),
      'field_organisation' => $organisation?->id(), 'field_e_mail' => 'fictional@example.org',
      'field_feedback' => ['value' => "Fictional confidential feedback $label historic", 'format' => 'plain_text'],
    ]);
    $report->save();
    $report->setNewRevision(TRUE);
    $report->set('field_feedback', [
      'value' => "Fictional confidential feedback $label current", 'format' => 'plain_text',
    ]);
    $report->save();
    return $report;
  }

  /**
   * Makes a real kernel JSON:API request and checks the expected status.
   */
  private function json(string $uri, int $status = 200): array {
    $request = Request::create($uri);
    $request->headers->set('Accept', 'application/vnd.api+json');
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame($status, $response->getStatusCode(), (string) $response->getContent());
    return json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Checks both the attribute and the serialized resource for feedback text.
   */
  private function assertFeedbackAbsent(array $resource, string $context): void {
    $this->assertArrayNotHasKey('field_feedback', $resource['attributes'] ?? [], $context);
    $this->assertStringNotContainsString('Fictional confidential feedback', json_encode($resource, JSON_THROW_ON_ERROR), $context);
  }

  /**
   * Renders the real Views Data Export field-row serializer as CSV.
   *
   * No shipped View exposes feedback today. This fixture deliberately adds
   * it to prove the export stays gated if an installation exposes the field.
   */
  private function csv(NodeInterface $report): string {
    $view = View::create([
      'id' => 'feedback_export', 'base_table' => 'node_field_data', 'base_field' => 'nid',
      'display' => ['default' => [
        'id' => 'default', 'display_title' => 'Export', 'display_plugin' => 'default',
        'display_options' => [
          'access' => ['type' => 'none'], 'cache' => ['type' => 'none'],
          'style' => ['type' => 'data_export', 'options' => ['formats' => ['csv' => 'csv']]],
          'row' => ['type' => 'data_field'],
          'fields' => [
            'title' => [
              'id' => 'title', 'table' => 'node_field_data', 'field' => 'title',
              'entity_type' => 'node', 'entity_field' => 'title', 'plugin_id' => 'field',
            ],
            'field_feedback' => [
              'id' => 'field_feedback', 'table' => 'node__field_feedback', 'field' => 'field_feedback',
              'entity_type' => 'node', 'entity_field' => 'field_feedback', 'plugin_id' => 'field',
            ],
          ],
          'filters' => ['nid' => [
            'id' => 'nid', 'table' => 'node_field_data', 'field' => 'nid',
            'value' => ['value' => $report->id()], 'plugin_id' => 'numeric',
          ]],
        ],
      ]],
    ])->getExecutable();
    $view->setDisplay('default');
    $renderer = $this->container->get('renderer');
    return $renderer->executeInRenderContext(new RenderContext(), static function () use ($view, $renderer): string {
      $output = $view->render();
      return (string) $renderer->render($output);
    });
  }

}
