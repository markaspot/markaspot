<?php

namespace Drupal\Tests\markaspot_escalation\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\markaspot_escalation\Service\EscalationService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\OrgHierarchyResolverInterface;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests multi-org behavior in EscalationService.
 *
 * Covers canDelegate() with multiple organisations and
 * resolveSourceJurisdiction() when orgs span different jurisdictions.
 *
 * @group markaspot_escalation
 * @coversDefaultClass \Drupal\markaspot_escalation\Service\EscalationService
 */
class EscalationServiceMultiOrgTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_escalation\Service\EscalationService
   */
  protected $service;

  /**
   * Mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * Mocked hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $hierarchyResolver;

  /**
   * Mocked organisation hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\OrgHierarchyResolverInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $orgHierarchyResolver;

  /**
   * Mocked GeoReport processor.
   *
   * @var \Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $processor;

  /**
   * Mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Mocked group storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $groupStorage;

  /**
   * Mocked group_relationship storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $relationshipStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $this->orgHierarchyResolver = $this->createMock(OrgHierarchyResolverInterface::class);
    $this->processor = $this->createMock(GeoreportProcessorServiceInterface::class);
    $currentUser = $this->createMock(AccountInterface::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $time = $this->createMock(TimeInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $mailManager = $this->createMock(MailManagerInterface::class);

    // Set up entity storages.
    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->relationshipStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['group', $this->groupStorage],
        ['group_relationship', $this->relationshipStorage],
      ]);

    $this->orgHierarchyResolver->method('getAncestorIds')
      ->willReturn([]);

    // Default escalation config.
    $escalationConfig = $this->createMock(ImmutableConfig::class);
    $escalationConfig->method('get')
      ->willReturnMap([
        ['escalation_enabled', TRUE],
        ['delegation_enabled', TRUE],
      ]);

    // Default open311 config (status_closed has tid 6).
    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')
      ->willReturnMap([
        ['status_closed', [6 => '6']],
      ]);

    $configFactory->method('get')
      ->willReturnMap([
        ['markaspot_escalation.settings', $escalationConfig],
        ['markaspot_open311.settings', $open311Config],
      ]);

    $this->service = new EscalationService(
      $this->entityTypeManager,
      $this->hierarchyResolver,
      $this->processor,
      $currentUser,
      $configFactory,
      $time,
      $this->logger,
      $mailManager,
      $this->orgHierarchyResolver,
    );
  }

  /**
   * Creates a mock account with configurable permissions.
   *
   * @param int $uid
   *   The user ID.
   * @param array $permissions
   *   List of permission strings the user has.
   *
   * @return \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked account.
   */
  protected function createMockAccount(int $uid, array $permissions = []): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')
      ->willReturnCallback(fn($perm) => in_array($perm, $permissions, TRUE));
    return $account;
  }

  /**
   * Creates a mock organisation group with field_jurisdiction.
   *
   * @param int $orgId
   *   The organisation group ID.
   * @param int|null $jurId
   *   The jurisdiction group ID referenced by field_jurisdiction.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked org group.
   */
  protected function createMockOrgGroup(int $orgId, ?int $jurId = NULL): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($orgId);
    $group->method('bundle')->willReturn('org');
    $group->method('label')->willReturn("Org $orgId");

    $fieldMap = [];

    if ($jurId !== NULL) {
      $fieldMap['field_jurisdiction'] = new class($jurId) {

        /**
         * The referenced entity target ID.
         *
         * @var int
         */
        // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
        public int $target_id;

        /**
         * Constructs a field item stub.
         */
        public function __construct(int $targetId) {
          $this->target_id = $targetId;
        }

        /**
         * Checks whether the field item is empty.
         */
        public function isEmpty(): bool {
          return FALSE;
        }

      };
    }
    else {
      $fieldMap['field_jurisdiction'] = new class() {

        /**
         * Checks whether the field item is empty.
         */
        public function isEmpty(): bool {
          return TRUE;
        }

      };
    }

    $group->method('hasField')
      ->willReturnCallback(fn($name) => isset($fieldMap[$name]));
    $group->method('get')
      ->willReturnCallback(function ($name) use ($fieldMap) {
        if (isset($fieldMap[$name])) {
          return $fieldMap[$name];
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    return $group;
  }

  /**
   * Creates a mock jurisdiction group.
   *
   * @param int $id
   *   The group ID.
   * @param int|null $parentId
   *   The parent jurisdiction ID, or NULL if root.
   * @param string $label
   *   The group label.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked group entity.
   */
  protected function createMockJurGroup(int $id, ?int $parentId = NULL, string $label = ''): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn('jur');
    $group->method('label')->willReturn($label ?: "Jur $id");

    $fieldMap = [];

    if ($parentId !== NULL) {
      $fieldMap['field_parent_jurisdiction'] = new class($parentId) {

        /**
         * The referenced entity target ID.
         *
         * @var int
         */
        // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
        public int $target_id;

        /**
         * Constructs a field item stub.
         */
        public function __construct(int $parentId) {
          $this->target_id = $parentId;
        }

        /**
         * Checks whether the field item is empty.
         */
        public function isEmpty(): bool {
          return FALSE;
        }

      };
    }
    else {
      $fieldMap['field_parent_jurisdiction'] = new class() {

        /**
         * Checks whether the field item is empty.
         */
        public function isEmpty(): bool {
          return TRUE;
        }

      };
    }

    $group->method('hasField')
      ->willReturnCallback(fn($name) => isset($fieldMap[$name]));
    $group->method('get')
      ->willReturnCallback(function ($name) use ($fieldMap) {
        if (isset($fieldMap[$name])) {
          return $fieldMap[$name];
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    return $group;
  }

  /**
   * Creates a mock node with multi-value field_organisation.
   *
   * The field_organisation stub supports referencedEntities() for the
   * multi-org canDelegate() and resolveSourceJurisdiction() logic.
   *
   * @param array $config
   *   Configuration array with keys:
   *   - 'id': Node ID (default: 100).
   *   - 'field_status': target_id or NULL.
   *   - 'field_escalation': target_id or NULL.
   *   - 'field_organisation_entities': array of GroupInterface mocks.
   *   - 'field_organisation_values': array of ['target_id' => int] for
   *     getValue() support.
   *   - 'has_field_escalation': whether the field exists (default: TRUE).
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked node.
   */
  protected function createMockNode(array $config = []): NodeInterface {
    $nodeId = $config['id'] ?? 100;
    $hasFieldEscalation = $config['has_field_escalation'] ?? TRUE;
    $orgEntities = $config['field_organisation_entities'] ?? [];
    $orgValues = $config['field_organisation_values'] ?? [];

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nodeId);

    $fields = [];

    // field_escalation.
    if ($hasFieldEscalation) {
      if (isset($config['field_escalation'])) {
        $fields['field_escalation'] = new class($config['field_escalation']) {

          /**
           * The referenced entity target ID.
           *
           * @var int
           */
          // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
          public int $target_id;

          /**
           * Constructs a field item stub.
           */
          public function __construct(int $targetId) {
            $this->target_id = $targetId;
          }

          /**
           * Checks whether the field item is empty.
           */
          public function isEmpty(): bool {
            return FALSE;
          }

        };
      }
      else {
        $fields['field_escalation'] = new class() {

          /**
           * Checks whether the field item is empty.
           */
          public function isEmpty(): bool {
            return TRUE;
          }

        };
      }
    }

    // field_status.
    if (isset($config['field_status'])) {
      $fields['field_status'] = new class($config['field_status']) {

        /**
         * The referenced entity target ID.
         *
         * @var int
         */
        // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
        public int $target_id;

        /**
         * Constructs a field item stub.
         */
        public function __construct(int $targetId) {
          $this->target_id = $targetId;
        }

        /**
         * Checks whether the field item is empty.
         */
        public function isEmpty(): bool {
          return FALSE;
        }

      };
    }
    else {
      $fields['field_status'] = new class() {

        /**
         * Checks whether the field item is empty.
         */
        public function isEmpty(): bool {
          return TRUE;
        }

      };
    }

    // field_organisation (multi-value entity reference).
    if (!empty($orgEntities)) {
      $fields['field_organisation'] = new class($orgEntities, $orgValues) {

        /**
         * The referenced entities.
         *
         * @var array
         */
        private array $entities;

        /**
         * The raw field values.
         *
         * @var array
         */
        private array $values;

        /**
         * Constructs a multi-value field item list stub.
         */
        public function __construct(array $entities, array $values) {
          $this->entities = $entities;
          $this->values = $values;
        }

        /**
         * Checks whether the field is empty.
         */
        public function isEmpty(): bool {
          return FALSE;
        }

        /**
         * Returns all referenced entities.
         */
        public function referencedEntities(): array {
          return $this->entities;
        }

        /**
         * Returns raw field values.
         */
        public function getValue(): array {
          return $this->values;
        }

      };
    }
    else {
      $fields['field_organisation'] = new class() {

        /**
         * Checks whether the field is empty.
         */
        public function isEmpty(): bool {
          return TRUE;
        }

        /**
         * Returns all referenced entities.
         */
        public function referencedEntities(): array {
          return [];
        }

        /**
         * Returns raw field values.
         */
        public function getValue(): array {
          return [];
        }

      };
    }

    // field_category (empty by default).
    $fields['field_category'] = new class() {

      /**
       * Checks whether the field is empty.
       */
      public function isEmpty(): bool {
        return TRUE;
      }

    };

    $node->method('hasField')
      ->willReturnCallback(function ($name) use ($fields, $hasFieldEscalation) {
        if ($name === 'field_escalation') {
          return $hasFieldEscalation;
        }
        return isset($fields[$name]);
      });

    $node->method('get')
      ->willReturnCallback(function ($name) use ($fields) {
        if (isset($fields[$name])) {
          return $fields[$name];
        }
        $mock = $this->createMock(FieldItemListInterface::class);
        $mock->method('isEmpty')->willReturn(TRUE);
        return $mock;
      });

    return $node;
  }

  /**
   * Invokes a protected/private method via reflection.
   *
   * @param object $object
   *   The object instance.
   * @param string $methodName
   *   The method name.
   * @param array $args
   *   The method arguments.
   *
   * @return mixed
   *   The method's return value.
   */
  protected function invokeMethod(object $object, string $methodName, array $args = []) {
    $ref = new \ReflectionMethod($object, $methodName);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs($object, $args);
  }

  /**
   * Tests canDelegate returns TRUE when any org's jurisdiction matches.
   *
   * Scenario: Node has 3 orgs. Org1 -> jur 4 (no parent match),
   * Org2 -> jur 5 (parent jur 1, user is member), Org3 -> jur 6 (no match).
   * Only org 2's jurisdiction chain leads to a match. Should return TRUE.
   *
   * @covers ::canDelegate
   */
  public function testCanDelegateWithAnyMatchingOrg(): void {
    $org1 = $this->createMockOrgGroup(10, 4);
    $org2 = $this->createMockOrgGroup(20, 5);
    $org3 = $this->createMockOrgGroup(30, 6);

    $node = $this->createMockNode([
      'field_status' => 1,
      'field_organisation_entities' => [$org1, $org2, $org3],
      'field_organisation_values' => [
        ['target_id' => 10],
        ['target_id' => 20],
        ['target_id' => 30],
      ],
    ]);

    $account = $this->createMockAccount(5, ['delegate service requests']);

    // Jur 4 has parent 2, jur 5 has parent 1, jur 6 has parent 3.
    $jurGroup4 = $this->createMockJurGroup(4, 2, 'District A');
    $jurGroup5 = $this->createMockJurGroup(5, 1, 'District B');
    $jurGroup6 = $this->createMockJurGroup(6, 3, 'District C');

    // Parent jur 2 -> user is NOT member.
    $parentJur2 = $this->createMockJurGroup(2, NULL, 'City X');
    $parentJur2->method('getMember')->with($account)->willReturn(FALSE);

    // Parent jur 1 -> user IS member.
    $parentJur1 = $this->createMockJurGroup(1, NULL, 'Amsterdam');
    $membership = $this->createMock(GroupMembership::class);
    $parentJur1->method('getMember')->with($account)->willReturn($membership);

    // Parent jur 3 -> user is NOT member.
    $parentJur3 = $this->createMockJurGroup(3, NULL, 'City Z');
    $parentJur3->method('getMember')->with($account)->willReturn(FALSE);

    $this->groupStorage->method('load')
      ->willReturnCallback(function ($id) use ($jurGroup4, $jurGroup5, $jurGroup6, $parentJur1, $parentJur2, $parentJur3) {
        return match ($id) {
          4 => $jurGroup4,
          5 => $jurGroup5,
          6 => $jurGroup6,
          1 => $parentJur1,
          2 => $parentJur2,
          3 => $parentJur3,
          default => NULL,
        };
      });

    $this->assertTrue($this->service->canDelegate($node, $account));
  }

  /**
   * Tests canDelegate returns FALSE when no org's jurisdiction matches.
   *
   * Scenario: Node has 3 orgs, none of their jurisdiction chains lead to
   * a group where the user is a member.
   *
   * @covers ::canDelegate
   */
  public function testCanDelegateWithNoMatchingOrg(): void {
    $org1 = $this->createMockOrgGroup(10, 4);
    $org2 = $this->createMockOrgGroup(20, 5);
    $org3 = $this->createMockOrgGroup(30, 6);

    $node = $this->createMockNode([
      'field_status' => 1,
      'field_organisation_entities' => [$org1, $org2, $org3],
      'field_organisation_values' => [
        ['target_id' => 10],
        ['target_id' => 20],
        ['target_id' => 30],
      ],
    ]);

    $account = $this->createMockAccount(5, ['delegate service requests']);

    // All parent jurs: user is NOT a member.
    // Note: GroupInterface::getMember() returns FALSE (not NULL) for
    // non-members, and EscalationService checks !== FALSE.
    $jurGroup4 = $this->createMockJurGroup(4, 2, 'District A');
    $jurGroup5 = $this->createMockJurGroup(5, 1, 'District B');
    $jurGroup6 = $this->createMockJurGroup(6, 3, 'District C');

    $parentJur1 = $this->createMockJurGroup(1, NULL, 'City A');
    $parentJur1->method('getMember')->with($account)->willReturn(FALSE);
    $parentJur2 = $this->createMockJurGroup(2, NULL, 'City B');
    $parentJur2->method('getMember')->with($account)->willReturn(FALSE);
    $parentJur3 = $this->createMockJurGroup(3, NULL, 'City C');
    $parentJur3->method('getMember')->with($account)->willReturn(FALSE);

    $this->groupStorage->method('load')
      ->willReturnCallback(function ($id) use ($jurGroup4, $jurGroup5, $jurGroup6, $parentJur1, $parentJur2, $parentJur3) {
        return match ($id) {
          4 => $jurGroup4,
          5 => $jurGroup5,
          6 => $jurGroup6,
          1 => $parentJur1,
          2 => $parentJur2,
          3 => $parentJur3,
          default => NULL,
        };
      });

    $this->assertFalse($this->service->canDelegate($node, $account));
  }

  /**
   * Tests resolveSourceJurisdiction returns the first valid org jurisdiction.
   *
   * Scenario: Node has 3 orgs with different jurisdictions (4, 5, 6).
   * No group_relationships exist, so the method falls through to the
   * field_organisation fallback. Returns the first valid jurisdiction ID.
   *
   * @covers ::resolveSourceJurisdiction
   */
  public function testResolveSourceJurisdictionReturnsFirstValid(): void {
    $org1 = $this->createMockOrgGroup(10, 4);
    $org2 = $this->createMockOrgGroup(20, 5);
    $org3 = $this->createMockOrgGroup(30, 6);

    $node = $this->createMockNode([
      'field_status' => 1,
      'field_organisation_entities' => [$org1, $org2, $org3],
      'field_organisation_values' => [
        ['target_id' => 10],
        ['target_id' => 20],
        ['target_id' => 30],
      ],
    ]);

    // No group_relationships for this node.
    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([]);

    // The method should log a warning about multiple jurisdictions
    // and return the first one (4).
    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('multiple jurisdictions'),
        $this->anything()
      );

    $result = $this->invokeMethod($this->service, 'resolveSourceJurisdiction', [$node]);
    $this->assertEquals(4, $result);
  }

  /**
   * Tests resolveSourceJurisdiction with orgs in the same jurisdiction.
   *
   * Scenario: Node has 2 orgs, both pointing to jur 4. No warning logged,
   * returns 4.
   *
   * @covers ::resolveSourceJurisdiction
   */
  public function testResolveSourceJurisdictionSameJurNoWarning(): void {
    $org1 = $this->createMockOrgGroup(10, 4);
    $org2 = $this->createMockOrgGroup(20, 4);

    $node = $this->createMockNode([
      'field_status' => 1,
      'field_organisation_entities' => [$org1, $org2],
      'field_organisation_values' => [
        ['target_id' => 10],
        ['target_id' => 20],
      ],
    ]);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([]);

    // No warning expected since both orgs share the same jurisdiction.
    $this->logger->expects($this->never())
      ->method('warning');

    $result = $this->invokeMethod($this->service, 'resolveSourceJurisdiction', [$node]);
    $this->assertEquals(4, $result);
  }

  /**
   * Tests that the org ID extraction for form exclusion works correctly.
   *
   * Mirrors the logic in DelegateForm::buildForm() that collects all
   * current org IDs for exclusion from the dropdown options.
   */
  public function testDelegateFormExcludesAllCurrentOrgs(): void {
    $fieldValues = [
      ['target_id' => '1'],
      ['target_id' => '2'],
      ['target_id' => '3'],
    ];

    // Mirror the extraction pattern from DelegateForm.
    $currentOrgIds = array_map('intval', array_column($fieldValues, 'target_id'));

    // Simulated dropdown options.
    $options = [
      1 => 'Org 1',
      2 => 'Org 2',
      3 => 'Org 3',
      4 => 'Org 4',
      5 => 'Org 5',
    ];

    foreach ($currentOrgIds as $id) {
      unset($options[$id]);
    }

    $this->assertEquals([
      4 => 'Org 4',
      5 => 'Org 5',
    ], $options);
  }

}
