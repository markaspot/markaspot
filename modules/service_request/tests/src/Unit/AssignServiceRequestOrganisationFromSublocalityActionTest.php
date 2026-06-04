<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Unit;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\service_request\Plugin\Action\AssignServiceRequestOrganisationFromSublocality;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the sublocality-based organisation assignment action.
 */
#[CoversClass(AssignServiceRequestOrganisationFromSublocality::class)]
#[Group('service_request')]
class AssignServiceRequestOrganisationFromSublocalityActionTest extends UnitTestCase {

  /**
   * Tests the action plugin id and provider are stable.
   */
  public function testActionPluginIdAndProviderAreStable(): void {
    $source = $this->loadActionSource();

    $this->assertStringContainsString('namespace Drupal\\service_request\\Plugin\\Action;', $source);
    $this->assertStringContainsString('id = "service_request_assign_organisation_from_sublocality"', $source);
  }

  /**
   * Tests the action defaults target the sublocality fields, not category.
   */
  public function testActionDefaultsTargetSublocalityFields(): void {
    $source = $this->loadActionSource();

    $this->assertStringContainsString("'sublocality_field' => 'field_sublocality'", $source);
    $this->assertStringContainsString("'org_sublocality_field' => 'field_sublocality_terms'", $source);
    $this->assertStringNotContainsString("'category_field'", $source);
  }

  /**
   * Tests the action does not sync or create group relationships itself.
   *
   * Relationship sync is delegated to the chained sync action.
   */
  public function testActionDoesNotSyncRelationships(): void {
    $source = $this->loadActionSource();

    $this->assertStringNotContainsString("'sync_relationship'", $source);
    $this->assertStringNotContainsString('function syncRelationships(', $source);
    $this->assertStringNotContainsString('addRelationship', $source);
    $this->assertStringNotContainsString('group_relationship', $source);
  }

  /**
   * Tests access checks node update and field edit access.
   */
  public function testActionChecksMutationAccess(): void {
    $source = $this->loadActionSource();

    $this->assertStringContainsString("access('update'", $source);
    $this->assertStringContainsString("access('edit'", $source);
    $this->assertStringContainsString('AccessResult::forbidden()', $source);
  }

  /**
   * Tests that exactly one matching organisation group is returned.
   */
  public function testDeriveReturnsGroupIdWhenExactlyOneMatch(): void {
    $action = $this->buildAction($this->mockGroupStorage([42]));
    $node = $this->mockNode(7, 1);

    $this->assertSame(42, $this->derive($action, $node, ['org']));
  }

  /**
   * Tests that ambiguous (many) matches skip assignment.
   */
  public function testDeriveReturnsNullWhenAmbiguousMatch(): void {
    $action = $this->buildAction($this->mockGroupStorage([42, 43]));
    $node = $this->mockNode(7, 1);

    $this->assertNull($this->derive($action, $node, ['org']));
  }

  /**
   * Tests that no match returns null.
   */
  public function testDeriveReturnsNullWhenNoMatch(): void {
    $action = $this->buildAction($this->mockGroupStorage([]));
    $node = $this->mockNode(7, 1);

    $this->assertNull($this->derive($action, $node, ['org']));
  }

  /**
   * Tests that an empty sublocality field short-circuits to null.
   */
  public function testDeriveReturnsNullWhenSublocalityEmpty(): void {
    $action = $this->buildAction($this->mockGroupStorage([42]));
    $node = $this->mockNode(0, 1);

    $this->assertNull($this->derive($action, $node, ['org']));
  }

  /**
   * Builds the action with mocked dependencies.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $group_storage
   *   The mocked group storage.
   */
  private function buildAction(EntityStorageInterface $group_storage): AssignServiceRequestOrganisationFromSublocality {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($group_storage);

    // The org bundle exposes both required group fields.
    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->method('getFieldDefinitions')->willReturn([
      'field_jurisdiction' => TRUE,
      'field_sublocality_terms' => TRUE,
    ]);

    return new AssignServiceRequestOrganisationFromSublocality(
      [],
      'service_request_assign_organisation_from_sublocality',
      ['id' => 'service_request_assign_organisation_from_sublocality'],
      $entity_type_manager,
      $field_manager,
      $this->createMock(LoggerInterface::class),
      $this->createMock(AccountInterface::class),
      NULL
    );
  }

  /**
   * Mocks group storage whose query returns the given group IDs.
   *
   * @param int[] $result_ids
   *   The group IDs the entity query should return.
   */
  private function mockGroupStorage(array $result_ids): EntityStorageInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($result_ids);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    // For an exactly-one match the action re-loads the group to verify the
    // jurisdiction. The loaded group exposes no field_jurisdiction; combined
    // with a node that also lacks one (see mockNode), groupMatchesJurisdiction
    // takes the single-tenant fallback and returns TRUE.
    $group = $this->createMock(FieldableEntityInterface::class);
    $group->method('hasField')->willReturn(FALSE);
    $storage->method('load')->willReturn($group);

    return $storage;
  }

  /**
   * Mocks a service request node with the given sublocality and jurisdiction.
   *
   * @param int $sublocality_tid
   *   The sublocality term ID (0 means empty field).
   * @param int $jurisdiction_id
   *   The jurisdiction group ID.
   */
  private function mockNode(int $sublocality_tid, int $jurisdiction_id): NodeInterface {
    $node = $this->createMock(NodeInterface::class);

    // FieldItemListInterface exposes target_id via magic __get (inherited from
    // the typed-data list interface), so the mock must stub __get. The action
    // reads it via the null-coalescing operator (target_id ?? 0), which first
    // calls __isset; that must be stubbed too or the read collapses to NULL.
    $sublocality_item = $this->createMock(FieldItemListInterface::class);
    $sublocality_item->method('isEmpty')->willReturn($sublocality_tid <= 0);
    $sublocality_item->method('__isset')->willReturnCallback(static function (string $name) use ($sublocality_tid) {
      return $name === 'target_id' && $sublocality_tid > 0;
    });
    $sublocality_item->method('__get')->willReturnCallback(static function (string $name) use ($sublocality_tid) {
      return $name === 'target_id' && $sublocality_tid > 0 ? $sublocality_tid : NULL;
    });

    // The node does NOT expose field_jurisdiction in this mock, so the
    // single-tenant jurisdiction fallback applies for the loaded group.
    $node->method('hasField')->willReturnCallback(static function (string $field): bool {
      return $field === 'field_sublocality';
    });
    $node->method('get')->willReturnCallback(function (string $field) use ($sublocality_item) {
      if ($field === 'field_sublocality') {
        return $sublocality_item;
      }
      $empty = $this->createMock(FieldItemListInterface::class);
      $empty->method('isEmpty')->willReturn(TRUE);
      return $empty;
    });

    return $node;
  }

  /**
   * Invokes the protected deriveOrganisationGroupId method.
   *
   * @param \Drupal\service_request\Plugin\Action\AssignServiceRequestOrganisationFromSublocality $action
   *   The action under test.
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param string[] $bundles
   *   Accepted organisation group bundles.
   */
  private function derive(AssignServiceRequestOrganisationFromSublocality $action, NodeInterface $node, array $bundles): ?int {
    $method = new \ReflectionMethod($action, 'deriveOrganisationGroupId');
    $method->setAccessible(TRUE);
    return $method->invoke($action, $node, $bundles);
  }

  /**
   * Loads the action source.
   */
  private function loadActionSource(): string {
    $path = dirname(__DIR__, 3) . '/src/Plugin/Action/AssignServiceRequestOrganisationFromSublocality.php';
    $source = file_get_contents($path);
    $this->assertIsString($source);
    return $source;
  }

}
