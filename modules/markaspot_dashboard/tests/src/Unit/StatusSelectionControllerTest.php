<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembership;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\markaspot_dashboard\Controller\StatusSelectionController;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\StatusTermScope;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the jurisdiction status-selection endpoint contract.
 *
 * @group markaspot_dashboard
 * @coversDefaultClass \Drupal\markaspot_dashboard\Controller\StatusSelectionController
 */
final class StatusSelectionControllerTest extends UnitTestCase {

  /**
   * Mocked group storage.
   */
  private EntityStorageInterface $groupStorage;

  /**
   * Mocked hierarchy resolver.
   */
  private JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * Mocked tree status scope.
   */
  private StatusTermScope $statusTermScope;

  /**
   * Mocked membership access service.
   */
  private GroupMembershipLoaderInterface $membershipLoader;

  /**
   * Mocked cache tag invalidator.
   */
  private CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  /**
   * Controller under test.
   */
  private StatusSelectionController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The DDEV PHPUnit bootstrap maps profile namespaces to the live checkout,
    // while this stacked branch is mounted as an isolated worktree.
    $module_root = dirname(__DIR__, 4);
    require_once $module_root . '/markaspot_group/src/Service/JurisdictionHierarchyResolverInterface.php';
    require_once $module_root . '/markaspot_group/src/Service/StatusTermScope.php';
    require_once $module_root . '/markaspot_group/src/Trait/JurisdictionIdResolverTrait.php';
    require_once $module_root . '/markaspot_dashboard/src/Controller/StatusSelectionController.php';

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($this->groupStorage);

    $this->hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $this->statusTermScope = $this->createMock(StatusTermScope::class);
    $this->membershipLoader = $this->createMock(GroupMembershipLoaderInterface::class);
    $this->cacheTagsInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn('jur');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($config);

    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);

    $this->controller = new StatusSelectionController(
      $entityTypeManager,
      $this->membershipLoader,
      $this->hierarchyResolver,
      $this->statusTermScope,
      $this->cacheTagsInvalidator,
      $configFactory,
    );
  }

  /**
   * Root jurisdictions cannot store a selection.
   *
   * @covers ::patchSelection
   */
  public function testRootPatchReturnsBadRequest(): void {
    $group = $this->createGroup(7);
    $this->groupStorage->method('load')->with(7)->willReturn($group);
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(7)
      ->willReturn(7);
    $this->statusTermScope->expects($this->never())
      ->method('loadTreePoolByProperties');

    $response = $this->controller->patchSelection(
      $this->patchRequest([]),
      '7',
    );

    self::assertSame(400, $response->getStatusCode());
  }

  /**
   * A term outside the root pool is rejected.
   *
   * @covers ::patchSelection
   */
  public function testForeignTermPatchReturnsBadRequest(): void {
    $group = $this->createGroup(12);
    $term = $this->createTerm(101, 0, []);
    $this->groupStorage->method('load')->with(12)->willReturn($group);
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(12)
      ->willReturn(7);
    $this->statusTermScope->method('loadTreePoolByProperties')
      ->with(['vid' => 'service_status', 'status' => 1], 12)
      ->willReturn([$term]);

    $response = $this->controller->patchSelection(
      $this->patchRequest([202]),
      '12',
    );

    self::assertSame(400, $response->getStatusCode());
    self::assertSame(
      'Selection contains a status outside the root pool.',
      $this->decodeResponse($response)['error'],
    );
  }

  /**
   * Duplicate IDs are malformed because a selection is a set.
   *
   * @covers ::patchSelection
   */
  public function testDuplicateTermPatchReturnsBadRequest(): void {
    $group = $this->createGroup(12);
    $this->groupStorage->method('load')->with(12)->willReturn($group);
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(12)
      ->willReturn(7);
    $this->statusTermScope->expects($this->never())
      ->method('loadTreePoolByProperties');

    $response = $this->controller->patchSelection(
      $this->patchRequest([101, 101]),
      '12',
    );

    self::assertSame(400, $response->getStatusCode());
  }

  /**
   * Oversized JSON bodies are rejected before pool loading.
   *
   * @covers ::patchSelection
   */
  public function testOversizedPatchReturnsBadRequest(): void {
    $group = $this->createGroup(12);
    $this->groupStorage->method('load')->with(12)->willReturn($group);
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(12)
      ->willReturn(7);
    $this->statusTermScope->expects($this->never())
      ->method('loadTreePoolByProperties');
    $request = Request::create(
      '/api/dashboard/status-selection/12',
      'PATCH',
      content: '{"selection":[]}' . str_repeat(' ', 65536),
    );

    $response = $this->controller->patchSelection($request, '12');

    self::assertSame(400, $response->getStatusCode());
  }

  /**
   * A valid child selection is written and returned unchanged.
   *
   * @covers ::patchSelection
   */
  public function testHappyPathWritesSelectionAndReturnsShape(): void {
    $group = $this->createGroup(12, [], TRUE);
    $first = $this->createTerm(101, 10, []);
    $second = $this->createTerm(102, 20, []);
    $this->groupStorage->method('load')->with(12)->willReturn($group);
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(12)
      ->willReturn(7);
    $this->statusTermScope->method('loadTreePoolByProperties')
      ->with(['vid' => 'service_status', 'status' => 1], 12)
      ->willReturn([$first, $second]);

    $group->expects($this->once())
      ->method('set')
      ->with('field_service_statuses', [
        ['target_id' => 102],
        ['target_id' => 101],
      ])
      ->willReturnSelf();
    $group->expects($this->once())->method('save');
    $this->cacheTagsInvalidator->expects($this->once())
      ->method('invalidateTags')
      ->with([
        'group:12',
        'group_list',
        'taxonomy_term_list:service_status',
      ]);

    $response = $this->controller->patchSelection(
      $this->patchRequest([102, 101]),
      '12',
    );

    self::assertSame(200, $response->getStatusCode());
    self::assertSame(['selection' => [102, 101]], $this->decodeResponse($response));
  }

  /**
   * Pool objects include notification keys and are sorted by term weight.
   *
   * @covers ::getSelection
   */
  public function testPoolShapeIncludesNotificationKey(): void {
    $group = $this->createGroup(7);
    $later = $this->createTerm(101, 20, [
      'field_status_hex' => [['color' => '#112233']],
      'field_status_icon' => [['value' => 'fa-check']],
      'field_open311_mapping' => [['value' => 'closed']],
      'field_notification_key' => [['value' => 'status_closed']],
    ]);
    $first = $this->createTerm(102, -5, [
      'field_status_hex' => [],
      'field_status_icon' => [],
      'field_open311_mapping' => [['value' => 'open']],
      'field_notification_key' => [],
    ]);
    $this->groupStorage->method('load')->with(7)->willReturn($group);
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(7)
      ->willReturn(7);
    $this->statusTermScope->method('loadTreePoolByProperties')
      ->with(['vid' => 'service_status', 'status' => 1], 7)
      ->willReturn([$later, $first]);

    $response = $this->controller->getSelection('7');
    $payload = $this->decodeResponse($response);

    self::assertSame(200, $response->getStatusCode());
    self::assertTrue($payload['is_root']);
    self::assertSame(7, $payload['root_group_id']);
    self::assertNull($payload['selection']);
    self::assertSame([102, 101], array_column($payload['pool'], 'tid'));
    self::assertSame([
      'tid' => 101,
      'uuid' => 'uuid-101',
      'name' => 'Status 101',
      'hex' => '#112233',
      'icon' => 'fa-check',
      'open311_mapping' => 'closed',
      'notification_key' => 'status_closed',
    ], $payload['pool'][1]);
    self::assertNull($payload['pool'][0]['notification_key']);
  }

  /**
   * Accounts without tenant-admin role are denied before membership loading.
   *
   * @covers ::accessCheck
   */
  public function testAccessDeniedForNonAdmin(): void {
    $group = $this->createGroup(12);
    $this->groupStorage->method('load')->with(12)->willReturn($group);
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(12)
      ->willReturn(7);
    $this->membershipLoader->expects($this->never())->method('loadByUser');

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('42');
    $account->method('getRoles')->willReturn(['authenticated']);

    $result = $this->controller->accessCheck($account, '12');

    self::assertFalse($result->isAllowed());
  }

  /**
   * Tenant admins from another tree cannot access the requested selection.
   *
   * @covers ::accessCheck
   */
  public function testAccessDeniedForForeignTenantAdmin(): void {
    $group = $this->createGroup(12);
    $foreign_group = $this->createGroup(20);
    $this->groupStorage->method('load')->with(12)->willReturn($group);
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(12)
      ->willReturn(7);

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('42');
    $account->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);
    $membership = $this->createMock(GroupMembership::class);
    $membership->method('getGroup')->willReturn($foreign_group);
    $this->membershipLoader->expects($this->once())
      ->method('loadByUser')
      ->with($account, ['jur-tenant_admin'])
      ->willReturn([$membership]);

    $result = $this->controller->accessCheck($account, '12');

    self::assertFalse($result->isAllowed());
  }

  /**
   * A tenant-admin membership on the tree root grants child access.
   *
   * @covers ::accessCheck
   */
  public function testAccessAllowedForRootTenantAdmin(): void {
    $group = $this->createGroup(12);
    $root_group = $this->createGroup(7);
    $this->groupStorage->method('load')->with(12)->willReturn($group);
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(12)
      ->willReturn(7);

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('42');
    $account->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);
    $membership = $this->createMock(GroupMembership::class);
    $membership->method('getGroup')->willReturn($root_group);
    $membership->method('getCacheContexts')->willReturn([]);
    $membership->method('getCacheTags')->willReturn(['group_relationship:99']);
    $membership->method('getCacheMaxAge')->willReturn(-1);
    $this->membershipLoader->expects($this->once())
      ->method('loadByUser')
      ->with($account, ['jur-tenant_admin'])
      ->willReturn([$membership]);

    $result = $this->controller->accessCheck($account, '12');

    self::assertTrue($result->isAllowed());
  }

  /**
   * Builds a published jurisdiction group mock.
   */
  private function createGroup(
    int $group_id,
    array $selection = [],
    bool $has_selection_field = FALSE,
  ): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $group_id);
    $group->method('bundle')->willReturn('jur');
    $group->method('isPublished')->willReturn(TRUE);
    $group->method('hasField')
      ->with('field_service_statuses')
      ->willReturn($has_selection_field);
    $group->method('get')
      ->with('field_service_statuses')
      ->willReturn($this->createFieldList(array_map(
        static fn(int $term_id): array => ['target_id' => $term_id],
        $selection,
      )));
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheTags')->willReturn(['group:' . $group_id]);
    $group->method('getCacheMaxAge')->willReturn(-1);
    return $group;
  }

  /**
   * Builds a status term mock with field values keyed by field name.
   *
   * @param int $term_id
   *   Term ID.
   * @param int $weight
   *   Taxonomy weight.
   * @param array<string, array<int, array<string, mixed>>> $fields
   *   Raw field item values.
   */
  private function createTerm(int $term_id, int $weight, array $fields): TermInterface {
    $field_lists = [];
    foreach ($fields as $field_name => $values) {
      $field_lists[$field_name] = $this->createFieldList($values);
    }

    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn((string) $term_id);
    $term->method('uuid')->willReturn('uuid-' . $term_id);
    $term->method('getName')->willReturn('Status ' . $term_id);
    $term->method('getWeight')->willReturn($weight);
    $term->method('hasField')
      ->willReturnCallback(
        static fn(string $field_name): bool => isset($field_lists[$field_name]),
      );
    $term->method('get')
      ->willReturnCallback(
        static fn(string $field_name): FieldItemListInterface => $field_lists[$field_name],
      );
    return $term;
  }

  /**
   * Builds a field list mock.
   *
   * @param array<int, array<string, mixed>> $values
   *   Raw field item values.
   */
  private function createFieldList(array $values): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($values === []);
    $field->method('getValue')->willReturn($values);
    return $field;
  }

  /**
   * Builds a valid PATCH request.
   *
   * @param int[] $selection
   *   Selection IDs.
   */
  private function patchRequest(array $selection): Request {
    return Request::create(
      '/api/dashboard/status-selection/12',
      'PATCH',
      content: json_encode(
        ['selection' => $selection],
        JSON_THROW_ON_ERROR,
      ),
    );
  }

  /**
   * Decodes a controller response.
   *
   * @return array<string, mixed>
   *   Decoded JSON response.
   */
  private function decodeResponse(JsonResponse $response): array {
    return json_decode(
      (string) $response->getContent(),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
  }

}
