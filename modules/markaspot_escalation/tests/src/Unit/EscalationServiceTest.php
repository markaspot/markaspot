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
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the EscalationService.
 *
 * Covers canEscalate(), resolveEscalationTarget(), canDelegate(), and the
 * protected resolveSourceJurisdiction() priority order (tested indirectly
 * through the public methods).
 *
 * @group markaspot_escalation
 * @coversDefaultClass \Drupal\markaspot_escalation\Service\EscalationService
 */
class EscalationServiceTest extends UnitTestCase {

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
   * Mocked GeoReport processor.
   *
   * @var \Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $processor;

  /**
   * Mocked current user.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * Mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mocked time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $time;

  /**
   * Mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Mocked mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $mailManager;

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
   * Mocked escalation config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $escalationConfig;

  /**
   * Mocked open311 config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $open311Config;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $this->processor = $this->createMock(GeoreportProcessorServiceInterface::class);
    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->mailManager = $this->createMock(MailManagerInterface::class);

    // Set up entity storages.
    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->relationshipStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['group', $this->groupStorage],
        ['group_relationship', $this->relationshipStorage],
      ]);

    // Default escalation config.
    $this->escalationConfig = $this->createMock(ImmutableConfig::class);
    $this->escalationConfig->method('get')
      ->willReturnMap([
        ['escalation_enabled', TRUE],
        ['delegation_enabled', TRUE],
      ]);

    // Default open311 config (status_closed has tid 6).
    $this->open311Config = $this->createMock(ImmutableConfig::class);
    $this->open311Config->method('get')
      ->willReturnMap([
        ['status_closed', [6 => '6']],
      ]);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['markaspot_escalation.settings', $this->escalationConfig],
        ['markaspot_open311.settings', $this->open311Config],
      ]);

    $this->service = new EscalationService(
      $this->entityTypeManager,
      $this->hierarchyResolver,
      $this->processor,
      $this->currentUser,
      $this->configFactory,
      $this->time,
      $this->logger,
      $this->mailManager,
    );
  }

  // ===========================================================================
  // Helper methods for building mock objects.
  // ===========================================================================

  /**
   * Creates a mock jurisdiction group.
   *
   * @param int $id
   *   The group ID.
   * @param string $bundle
   *   The group type (default: 'jur').
   * @param int|null $parentId
   *   The parent jurisdiction ID, or NULL if root.
   * @param string $label
   *   The group label.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked group entity.
   */
  protected function createMockGroup(int $id, string $bundle = 'jur', ?int $parentId = NULL, string $label = ''): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn($bundle);
    $group->method('label')->willReturn($label ?: "Group $id");

    // Build field definitions based on group type.
    $fieldMap = [];

    if ($parentId !== NULL) {
      $fieldItem = new class($parentId) {

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
      $fieldMap['field_parent_jurisdiction'] = $fieldItem;
    }
    else {
      $fieldItem = new class() {

        /**
         * Checks whether the field item is empty.
         */
        public function isEmpty(): bool {
          return TRUE;
        }

      };
      $fieldMap['field_parent_jurisdiction'] = $fieldItem;
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
   * Creates a mock group_relationship for a jur group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group the relationship belongs to.
   *
   * @return object
   *   A mock relationship with getGroup().
   */
  protected function createMockRelationship(GroupInterface $group): object {
    $relationship = new class($group) {

      /**
       * The group entity.
       *
       * @var object
       */
      private object $group;

      /**
       * Constructs a relationship stub.
       */
      public function __construct(object $group) {
        $this->group = $group;
      }

      /**
       * Returns the group entity.
       */
      public function getGroup(): object {
        return $this->group;
      }

    };
    return $relationship;
  }

  /**
   * Creates a mock node with configurable fields.
   *
   * @param array $config
   *   Configuration array with keys:
   *   - 'id': Node ID (default: 100).
   *   - 'field_escalation': target_id or NULL.
   *   - 'field_status': target_id or NULL.
   *   - 'field_organisation': entity mock or NULL.
   *   - 'field_category': entity mock or NULL.
   *   - 'has_field_escalation': whether the field exists (default: TRUE).
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked node.
   */
  protected function createMockNode(array $config = []): NodeInterface {
    $nodeId = $config['id'] ?? 100;
    $hasFieldEscalation = $config['has_field_escalation'] ?? TRUE;

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nodeId);

    // Track which fields exist and their values.
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

    // field_organisation.
    if (isset($config['field_organisation'])) {
      $fields['field_organisation'] = new class($config['field_organisation']) {

        /**
         * The referenced entity.
         *
         * @var object
         */
        public object $entity;

        /**
         * Constructs a field item stub.
         */
        public function __construct(object $entity) {
          $this->entity = $entity;
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
      $fields['field_organisation'] = new class() {

        /**
         * Checks whether the field item is empty.
         */
        public function isEmpty(): bool {
          return TRUE;
        }

      };
    }

    // field_category.
    if (isset($config['field_category'])) {
      $fields['field_category'] = new class($config['field_category']) {

        /**
         * The referenced entity.
         *
         * @var object
         */
        public object $entity;

        /**
         * Constructs a field item stub.
         */
        public function __construct(object $entity) {
          $this->entity = $entity;
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
      $fields['field_category'] = new class() {

        /**
         * Checks whether the field item is empty.
         */
        public function isEmpty(): bool {
          return TRUE;
        }

      };
    }

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
   * Creates a mock category term.
   *
   * @param int $tid
   *   The term ID.
   * @param int|null $jurId
   *   Jurisdiction ID for field_jurisdiction.
   * @param int|null $escalationTargetId
   *   Escalation target ID for field_escalation_target.
   *
   * @return object
   *   The mocked category term.
   */
  protected function createMockCategoryTerm(int $tid, ?int $jurId = NULL, ?int $escalationTargetId = NULL): object {
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

    if ($escalationTargetId !== NULL) {
      $fieldMap['field_escalation_target'] = new class($escalationTargetId) {

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

    $term = new class($tid, $fieldMap) {

      /**
       * The term ID.
       *
       * @var int
       */
      private int $tid;

      /**
       * Map of field name to field item stub.
       *
       * @var array
       */
      private array $fieldMap;

      /**
       * Constructs a category term stub.
       */
      public function __construct(int $tid, array $fieldMap) {
        $this->tid = $tid;
        $this->fieldMap = $fieldMap;
      }

      /**
       * Returns the term ID.
       */
      public function id(): int {
        return $this->tid;
      }

      /**
       * Checks whether a field exists.
       */
      public function hasField(string $name): bool {
        return isset($this->fieldMap[$name]);
      }

      /**
       * Returns a field item stub.
       */
      public function get(string $name): object {
        if (isset($this->fieldMap[$name])) {
          return $this->fieldMap[$name];
        }
        return new class() {

          /**
           * Checks whether the field item is empty.
           */
          public function isEmpty(): bool {
            return TRUE;
          }

        };
      }

    };

    return $term;
  }

  /**
   * Creates a mock account with configurable permissions and group membership.
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

  // ===========================================================================
  // canEscalate() tests.
  // ===========================================================================

  /**
   * @covers ::canEscalate
   */
  public function testCanEscalateReturnsFalseWhenDisabled(): void {
    // Override escalation config to disabled.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['escalation_enabled', FALSE],
        ['delegation_enabled', TRUE],
      ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->willReturnMap([
        ['markaspot_escalation.settings', $config],
        ['markaspot_open311.settings', $this->open311Config],
      ]);

    $service = new EscalationService(
      $this->entityTypeManager,
      $this->hierarchyResolver,
      $this->processor,
      $this->currentUser,
      $configFactory,
      $this->time,
      $this->logger,
      $this->mailManager,
    );

    $node = $this->createMockNode();
    $account = $this->createMockAccount(5, ['escalate service requests']);

    $this->assertFalse($service->canEscalate($node, $account));
  }

  /**
   * @covers ::canEscalate
   */
  public function testCanEscalateReturnsFalseWhenNodeHasNoFieldEscalation(): void {
    $node = $this->createMockNode(['has_field_escalation' => FALSE]);
    $account = $this->createMockAccount(5, ['escalate service requests']);

    $this->assertFalse($this->service->canEscalate($node, $account));
  }

  /**
   * @covers ::canEscalate
   */
  public function testCanEscalateReturnsFalseWhenUserLacksPermission(): void {
    $node = $this->createMockNode();
    $account = $this->createMockAccount(5, []);

    $this->assertFalse($this->service->canEscalate($node, $account));
  }

  /**
   * @covers ::canEscalate
   */
  public function testCanEscalateReturnsFalseWhenRequestIsClosed(): void {
    // Status tid 6 is configured as closed.
    $node = $this->createMockNode(['field_status' => 6]);
    $account = $this->createMockAccount(5, ['escalate service requests']);

    $this->assertFalse($this->service->canEscalate($node, $account));
  }

  /**
   * @covers ::canEscalate
   */
  public function testCanEscalateReturnsFalseWhenNoTarget(): void {
    // Node with open status, no group_relationships, no org, no category.
    $node = $this->createMockNode(['field_status' => 1]);
    $account = $this->createMockAccount(5, ['escalate service requests']);

    // No group_relationships found.
    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([]);

    $this->assertFalse($this->service->canEscalate($node, $account));
  }

  /**
   * @covers ::canEscalate
   */
  public function testCanEscalateReturnsFalseWhenSourceIsRoot(): void {
    // Node belongs to root jur (Amsterdam, id:1) which has no parent.
    $node = $this->createMockNode(['field_status' => 1]);
    $account = $this->createMockAccount(5, ['escalate service requests']);

    $rootJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');
    $rootRelationship = $this->createMockRelationship($rootJur);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([$rootRelationship]);

    // Root jur has no parent, so getParentJurisdictionId returns NULL.
    $this->groupStorage->method('load')
      ->willReturnMap([
        [1, $rootJur],
      ]);

    $this->assertFalse($this->service->canEscalate($node, $account));
  }

  /**
   * @covers ::canEscalate
   */
  public function testCanEscalateReturnsTrueWhenAllConditionsMet(): void {
    // Node belongs to child jur (Noord, id:4, parent:1). User is member.
    $node = $this->createMockNode(['field_status' => 1]);
    $account = $this->createMockAccount(5, ['escalate service requests']);

    $childJur = $this->createMockGroup(4, 'jur', 1, 'Noord');
    $parentJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');

    $childRelationship = $this->createMockRelationship($childJur);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([$childRelationship]);

    // Group storage: load child jur (for getParentJurisdictionId) and
    // parent jur (to verify it exists), and child jur again for membership.
    $membership = $this->createMock(GroupMembership::class);
    $childJurForMembership = $this->createMockGroup(4, 'jur', 1, 'Noord');
    $childJurForMembership->method('getMember')
      ->with($account)
      ->willReturn($membership);

    $this->groupStorage->method('load')
      ->willReturnCallback(function ($id) use ($parentJur, $childJurForMembership) {
        if ($id === 4) {
          // Return the membership-enabled mock for isGroupMember.
          return $childJurForMembership;
        }
        if ($id === 1) {
          return $parentJur;
        }
        return NULL;
      });

    $this->assertTrue($this->service->canEscalate($node, $account));
  }

  /**
   * @covers ::canEscalate
   */
  public function testCanEscalateReturnsFalseWhenUserNotMemberOfSourceJur(): void {
    // Node belongs to child jur (Noord, id:4, parent:1). User is NOT a member.
    $node = $this->createMockNode(['field_status' => 1]);
    $account = $this->createMockAccount(5, ['escalate service requests']);

    $childJur = $this->createMockGroup(4, 'jur', 1, 'Noord');
    $parentJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');

    $childRelationship = $this->createMockRelationship($childJur);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([$childRelationship]);

    // User is NOT a member of child jur.
    $childJurForMembership = $this->createMockGroup(4, 'jur', 1, 'Noord');
    $childJurForMembership->method('getMember')
      ->with($account)
      ->willReturn(FALSE);

    $this->groupStorage->method('load')
      ->willReturnCallback(function ($id) use ($parentJur, $childJurForMembership) {
        if ($id === 4) {
          return $childJurForMembership;
        }
        if ($id === 1) {
          return $parentJur;
        }
        return NULL;
      });

    $this->assertFalse($this->service->canEscalate($node, $account));
  }

  // ===========================================================================
  // resolveEscalationTarget() tests.
  // ===========================================================================

  /**
   * @covers ::resolveEscalationTarget
   */
  public function testResolveTargetReturnsCategoryOverride(): void {
    // Category has field_escalation_target pointing to group 10.
    $category = $this->createMockCategoryTerm(50, NULL, 10);
    $node = $this->createMockNode(['field_category' => $category]);

    $targetGroup = $this->createMockGroup(10, 'jur', NULL, 'Special Jur');

    $this->groupStorage->method('load')
      ->willReturnMap([
        [10, $targetGroup],
      ]);

    $result = $this->service->resolveEscalationTarget($node);
    $this->assertEquals(10, $result);
  }

  /**
   * @covers ::resolveEscalationTarget
   */
  public function testResolveTargetSkipsCategoryOverrideWhenAlreadyEscalatedThere(): void {
    // Category has field_escalation_target = 10, but node is already
    // escalated to 10. Should fall through to hierarchy traversal.
    $category = $this->createMockCategoryTerm(50, NULL, 10);
    $node = $this->createMockNode([
      'field_category' => $category,
      'field_escalation' => 10,
    ]);

    // field_escalation = 10. getParentJurisdictionId(10) needs the jur group.
    $currentJur = $this->createMockGroup(10, 'jur', 5, 'Mid Jur');
    $parentJur = $this->createMockGroup(5, 'jur', NULL, 'Top Jur');

    $this->groupStorage->method('load')
      ->willReturnMap([
        [10, $currentJur],
        [5, $parentJur],
      ]);

    $result = $this->service->resolveEscalationTarget($node);
    // Should return parent of current escalation jur (5).
    $this->assertEquals(5, $result);
  }

  /**
   * @covers ::resolveEscalationTarget
   */
  public function testResolveTargetReturnsParentForReEscalation(): void {
    // Node already escalated to jur 4 (parent is 1).
    $node = $this->createMockNode(['field_escalation' => 4]);

    $currentJur = $this->createMockGroup(4, 'jur', 1, 'Noord');
    $parentJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');

    $this->groupStorage->method('load')
      ->willReturnMap([
        [4, $currentJur],
        [1, $parentJur],
      ]);

    $result = $this->service->resolveEscalationTarget($node);
    $this->assertEquals(1, $result);
  }

  /**
   * @covers ::resolveEscalationTarget
   */
  public function testResolveTargetReturnsParentOfSourceForFirstEscalation(): void {
    // First escalation: field_escalation is empty.
    // Node has group_relationship to child jur 4 (parent 1).
    $node = $this->createMockNode();

    $childJur = $this->createMockGroup(4, 'jur', 1, 'Noord');
    $parentJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');

    $childRelationship = $this->createMockRelationship($childJur);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([$childRelationship]);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [4, $childJur],
        [1, $parentJur],
      ]);

    $result = $this->service->resolveEscalationTarget($node);
    // Parent of source jur 4 is 1.
    $this->assertEquals(1, $result);
  }

  /**
   * @covers ::resolveEscalationTarget
   */
  public function testResolveTargetReturnsNullWhenSourceIsRoot(): void {
    // Node has group_relationship to root jur 1 (no parent).
    $node = $this->createMockNode();

    $rootJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');
    $rootRelationship = $this->createMockRelationship($rootJur);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([$rootRelationship]);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [1, $rootJur],
      ]);

    $result = $this->service->resolveEscalationTarget($node);
    $this->assertNull($result);
  }

  // ===========================================================================
  // canDelegate() tests.
  // ===========================================================================

  /**
   * @covers ::canDelegate
   */
  public function testCanDelegateReturnsFalseWhenDisabled(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['escalation_enabled', TRUE],
        ['delegation_enabled', FALSE],
      ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->willReturnMap([
        ['markaspot_escalation.settings', $config],
        ['markaspot_open311.settings', $this->open311Config],
      ]);

    $service = new EscalationService(
      $this->entityTypeManager,
      $this->hierarchyResolver,
      $this->processor,
      $this->currentUser,
      $configFactory,
      $this->time,
      $this->logger,
      $this->mailManager,
    );

    $node = $this->createMockNode();
    $account = $this->createMockAccount(5, ['delegate service requests']);

    $this->assertFalse($service->canDelegate($node, $account));
  }

  /**
   * @covers ::canDelegate
   */
  public function testCanDelegateReturnsTrueWhenEscalatedAndMember(): void {
    // Request is escalated to jur 1. User is a member of jur 1.
    $node = $this->createMockNode([
      'field_status' => 1,
      'field_escalation' => 1,
    ]);
    $account = $this->createMockAccount(5, ['delegate service requests']);

    $jurGroup = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');
    $membership = $this->createMock(GroupMembership::class);
    $jurGroup->method('getMember')
      ->with($account)
      ->willReturn($membership);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [1, $jurGroup],
      ]);

    $this->assertTrue($this->service->canDelegate($node, $account));
  }

  /**
   * @covers ::canDelegate
   */
  public function testCanDelegateReturnsFalseWhenClosedStatus(): void {
    // Status tid 6 is closed.
    $node = $this->createMockNode([
      'field_status' => 6,
      'field_escalation' => 1,
    ]);
    $account = $this->createMockAccount(5, ['delegate service requests']);

    $this->assertFalse($this->service->canDelegate($node, $account));
  }

  /**
   * @covers ::canDelegate
   */
  public function testCanDelegateNotEscalatedChecksParentJurMembership(): void {
    // Not escalated. Node has field_organisation pointing to org whose
    // field_jurisdiction is jur 4 (parent 1). User must be member of parent 1.
    $orgGroup = $this->createMockOrgGroup(10, 4);
    $node = $this->createMockNode([
      'field_status' => 1,
      'field_organisation' => $orgGroup,
    ]);
    $account = $this->createMockAccount(5, ['delegate service requests']);

    $childJur = $this->createMockGroup(4, 'jur', 1, 'Noord');
    $parentJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');
    $membership = $this->createMock(GroupMembership::class);
    $parentJur->method('getMember')
      ->with($account)
      ->willReturn($membership);

    $this->groupStorage->method('load')
      ->willReturnCallback(function ($id) use ($childJur, $parentJur) {
        if ($id === 4) {
          return $childJur;
        }
        if ($id === 1) {
          return $parentJur;
        }
        return NULL;
      });

    $this->assertTrue($this->service->canDelegate($node, $account));
  }

  // ===========================================================================
  // resolveSourceJurisdiction() priority order tests.
  // Tested indirectly through canEscalate() and resolveEscalationTarget().
  // ===========================================================================

  /**
   * Tests that child jur from group_relationships takes priority over root.
   *
   * Scenario: Node has BOTH child jur (Noord, id:4) and root jur (Amsterdam,
   * id:1) in group_relationships. Organisation points to Amsterdam (root).
   * The service should resolve to Noord (child), NOT Amsterdam.
   *
   * @covers ::canEscalate
   */
  public function testSourceJurPrefersChildJurOverRoot(): void {
    // Setup: org points to Amsterdam (root jur 1).
    $orgGroup = $this->createMockOrgGroup(10, 1);

    $node = $this->createMockNode([
      'field_status' => 1,
      'field_organisation' => $orgGroup,
    ]);
    $account = $this->createMockAccount(5, ['escalate service requests']);

    // Group_relationships contain BOTH root and child jur.
    $rootJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');
    $childJur = $this->createMockGroup(4, 'jur', 1, 'Noord');

    $rootRelationship = $this->createMockRelationship($rootJur);
    $childRelationship = $this->createMockRelationship($childJur);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([$rootRelationship, $childRelationship]);

    // getParentJurisdictionId(4) should return 1 (Amsterdam).
    // isGroupMember(4, account) should return TRUE.
    $parentJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');

    // We need the child jur mock to support getMember for isGroupMember.
    $childJurForMembership = $this->createMockGroup(4, 'jur', 1, 'Noord');
    $membership = $this->createMock(GroupMembership::class);
    $childJurForMembership->method('getMember')
      ->with($account)
      ->willReturn($membership);

    $this->groupStorage->method('load')
      ->willReturnCallback(function ($id) use ($childJurForMembership, $parentJur) {
        if ($id === 4) {
          return $childJurForMembership;
        }
        if ($id === 1) {
          return $parentJur;
        }
        return NULL;
      });

    // canEscalate should be TRUE. The source is child jur 4,
    // the target is parent jur 1.
    $this->assertTrue($this->service->canEscalate($node, $account));

    // Also verify resolveEscalationTarget returns parent of child (1),
    // not parent of root (NULL).
    $result = $this->service->resolveEscalationTarget($node);
    $this->assertEquals(1, $result, 'Escalation target should be parent of child jur (Amsterdam), proving child was chosen over root.');
  }

  /**
   * Tests that root jur is used when only root exists in group_relationships.
   *
   * @covers ::resolveEscalationTarget
   */
  public function testSourceJurUsesRootWhenOnlyRootInRelationships(): void {
    // Node has only root jur in group_relationships, no org.
    $node = $this->createMockNode();

    $rootJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');
    $rootRelationship = $this->createMockRelationship($rootJur);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([$rootRelationship]);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [1, $rootJur],
      ]);

    // Root has no parent, so escalation target is NULL.
    $result = $this->service->resolveEscalationTarget($node);
    $this->assertNull($result, 'Root jur has no parent, so no escalation target exists.');
  }

  /**
   * Tests fallback to field_organisation when no group_relationships exist.
   *
   * @covers ::resolveEscalationTarget
   */
  public function testSourceJurFallsBackToOrgJurisdiction(): void {
    // No group_relationships. Organisation has field_jurisdiction = 4.
    $orgGroup = $this->createMockOrgGroup(10, 4);
    $node = $this->createMockNode([
      'field_organisation' => $orgGroup,
    ]);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([]);

    $childJur = $this->createMockGroup(4, 'jur', 1, 'Noord');
    $parentJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');

    $this->groupStorage->method('load')
      ->willReturnMap([
        [4, $childJur],
        [1, $parentJur],
      ]);

    $result = $this->service->resolveEscalationTarget($node);
    // Source is org's jur 4, parent is 1.
    $this->assertEquals(1, $result, 'Should use org field_jurisdiction as fallback source, escalating to its parent.');
  }

  /**
   * Tests last-resort fallback to category field_jurisdiction.
   *
   * @covers ::resolveEscalationTarget
   */
  public function testSourceJurFallsBackToCategoryJurisdiction(): void {
    // No group_relationships, no org. Category has field_jurisdiction = 4.
    $category = $this->createMockCategoryTerm(50, 4);
    $node = $this->createMockNode([
      'field_category' => $category,
    ]);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([]);

    $childJur = $this->createMockGroup(4, 'jur', 1, 'Noord');
    $parentJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');

    $this->groupStorage->method('load')
      ->willReturnMap([
        [4, $childJur],
        [1, $parentJur],
      ]);

    $result = $this->service->resolveEscalationTarget($node);
    // Source is category's jur 4, parent is 1.
    $this->assertEquals(1, $result, 'Should use category field_jurisdiction as last-resort source.');
  }

  /**
   * Tests that group_relationships take priority over field_organisation.
   *
   * Scenario: group_relationships point to child jur 4 (parent 1), but
   * field_organisation points to org whose jur is root 1. The source should
   * resolve to child jur 4 (from relationships), NOT root 1 (from org).
   *
   * @covers ::resolveEscalationTarget
   */
  public function testSourceJurGroupRelationshipsPriorityOverOrg(): void {
    // Org points to root jur 1.
    $orgGroup = $this->createMockOrgGroup(10, 1);

    // Category also points to root jur 1.
    $category = $this->createMockCategoryTerm(50, 1);

    $node = $this->createMockNode([
      'field_organisation' => $orgGroup,
      'field_category' => $category,
    ]);

    // Group_relationships contain only child jur 4.
    $childJur = $this->createMockGroup(4, 'jur', 1, 'Noord');
    $childRelationship = $this->createMockRelationship($childJur);

    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([$childRelationship]);

    $parentJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');

    $this->groupStorage->method('load')
      ->willReturnMap([
        [4, $childJur],
        [1, $parentJur],
      ]);

    $result = $this->service->resolveEscalationTarget($node);
    // If group_relationships were ignored and org was used, source would be 1
    // (root, no parent), returning NULL. But we expect 1 as the parent of 4.
    $this->assertEquals(1, $result, 'group_relationships (child jur 4) must take priority over org (root jur 1).');
  }

  /**
   * Tests that field_organisation takes priority over field_category.
   *
   * When no group_relationships exist, org's jurisdiction should be used
   * before category's jurisdiction.
   *
   * @covers ::resolveEscalationTarget
   */
  public function testSourceJurOrgPriorityOverCategory(): void {
    // Org points to child jur 4, category points to root jur 1.
    $orgGroup = $this->createMockOrgGroup(10, 4);
    $category = $this->createMockCategoryTerm(50, 1);

    $node = $this->createMockNode([
      'field_organisation' => $orgGroup,
      'field_category' => $category,
    ]);

    // No group_relationships.
    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([]);

    $childJur = $this->createMockGroup(4, 'jur', 1, 'Noord');
    $parentJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');

    $this->groupStorage->method('load')
      ->willReturnMap([
        [4, $childJur],
        [1, $parentJur],
      ]);

    $result = $this->service->resolveEscalationTarget($node);
    // Source from org is jur 4. Parent is 1.
    // If category were used instead, source would be 1 (root, no parent) = NULL.
    $this->assertEquals(1, $result, 'field_organisation jur (4) must take priority over field_category jur (1).');
  }

  // ===========================================================================
  // isRequestClosed() (tested indirectly via canEscalate/canDelegate).
  // ===========================================================================

  /**
   * Tests that a non-closed status allows escalation to proceed.
   *
   * @covers ::canEscalate
   */
  public function testOpenStatusDoesNotBlockEscalation(): void {
    // Status tid 1 (open) is not in the closed list [6].
    $node = $this->createMockNode(['field_status' => 1]);
    $account = $this->createMockAccount(5, ['escalate service requests']);

    // No group_relationships means no target, so canEscalate returns FALSE
    // for a different reason. We verify it gets past the isRequestClosed check
    // by checking that it reaches resolveEscalationTarget (which needs
    // relationship storage).
    $this->relationshipStorage->expects($this->atLeastOnce())
      ->method('loadByProperties')
      ->willReturn([]);

    $this->service->canEscalate($node, $account);
    // The test passes if loadByProperties was called, proving we got past
    // the closed status check.
  }

  /**
   * Tests that a node without field_status is not considered closed.
   *
   * @covers ::canEscalate
   */
  public function testMissingStatusFieldIsNotClosed(): void {
    // Node without field_status should not be considered closed.
    $node = $this->createMockNode();
    $account = $this->createMockAccount(5, ['escalate service requests']);

    // If isRequestClosed returned TRUE, we would never reach the
    // relationship storage. Verify it does.
    $this->relationshipStorage->expects($this->atLeastOnce())
      ->method('loadByProperties')
      ->willReturn([]);

    $this->service->canEscalate($node, $account);
  }

  // ===========================================================================
  // Re-escalation membership check.
  // ===========================================================================

  /**
   * Tests re-escalation checks membership of the current escalation jur.
   *
   * When a request is already escalated, canEscalate should check whether
   * the user is a member of the CURRENT escalation jur, not the source jur.
   *
   * @covers ::canEscalate
   */
  public function testReEscalationChecksMembershipOfCurrentEscalationJur(): void {
    // Node is already escalated to jur 1 (Amsterdam). Parent of 1 is jur 99
    // (Netherlands). User must be member of jur 1 (the receiving jur).
    $node = $this->createMockNode([
      'field_status' => 1,
      'field_escalation' => 1,
    ]);
    $account = $this->createMockAccount(5, ['escalate service requests']);

    $parentJur = $this->createMockGroup(99, 'jur', NULL, 'Netherlands');

    $membership = $this->createMock(GroupMembership::class);

    // For resolveEscalationTarget, we need group loads.
    // For isGroupMember, we need getMember on jur 1.
    $jurForMembership = $this->createMockGroup(1, 'jur', 99, 'Amsterdam');
    $jurForMembership->method('getMember')
      ->with($account)
      ->willReturn($membership);

    $this->groupStorage->method('load')
      ->willReturnCallback(function ($id) use ($jurForMembership, $parentJur) {
        if ($id === 1) {
          return $jurForMembership;
        }
        if ($id === 99) {
          return $parentJur;
        }
        return NULL;
      });

    $this->assertTrue($this->service->canEscalate($node, $account));
  }

  /**
   * Tests re-escalation denied when user is not member of escalation jur.
   *
   * @covers ::canEscalate
   */
  public function testReEscalationDeniedWhenNotMemberOfEscalationJur(): void {
    $node = $this->createMockNode([
      'field_status' => 1,
      'field_escalation' => 1,
    ]);
    $account = $this->createMockAccount(5, ['escalate service requests']);

    $parentJur = $this->createMockGroup(99, 'jur', NULL, 'Netherlands');

    // User is NOT a member.
    $jurForMembership = $this->createMockGroup(1, 'jur', 99, 'Amsterdam');
    $jurForMembership->method('getMember')
      ->with($account)
      ->willReturn(FALSE);

    $this->groupStorage->method('load')
      ->willReturnCallback(function ($id) use ($jurForMembership, $parentJur) {
        if ($id === 1) {
          return $jurForMembership;
        }
        if ($id === 99) {
          return $parentJur;
        }
        return NULL;
      });

    $this->assertFalse($this->service->canEscalate($node, $account));
  }

  // ===========================================================================
  // Edge cases.
  // ===========================================================================

  /**
   * Tests that category escalation target with invalid group is skipped.
   *
   * @covers ::resolveEscalationTarget
   */
  public function testCategoryEscalationTargetInvalidGroupFallsThrough(): void {
    // Category has escalation_target = 999, but group 999 does not exist.
    $category = $this->createMockCategoryTerm(50, NULL, 999);
    $node = $this->createMockNode(['field_category' => $category]);

    // No group_relationships, no org.
    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([]);

    $this->groupStorage->method('load')
      ->willReturn(NULL);

    // Logger should warn about invalid target.
    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Category escalation target'),
        $this->anything()
      );

    $result = $this->service->resolveEscalationTarget($node);
    $this->assertNull($result);
  }

  /**
   * Tests that non-jur group_relationships are skipped when resolving source.
   *
   * @covers ::resolveEscalationTarget
   */
  public function testOrgGroupRelationshipsAreSkipped(): void {
    // Node has group_relationships: one org (should skip), one child jur.
    $orgGroupRel = $this->createMockGroup(10, 'org');
    $childJur = $this->createMockGroup(4, 'jur', 1, 'Noord');

    $orgRelationship = $this->createMockRelationship($orgGroupRel);
    $childRelationship = $this->createMockRelationship($childJur);

    $node = $this->createMockNode();

    $this->relationshipStorage->method('loadByProperties')
      ->willReturn([$orgRelationship, $childRelationship]);

    $parentJur = $this->createMockGroup(1, 'jur', NULL, 'Amsterdam');

    $this->groupStorage->method('load')
      ->willReturnMap([
        [4, $childJur],
        [1, $parentJur],
      ]);

    $result = $this->service->resolveEscalationTarget($node);
    // Should resolve to parent of child jur 4, which is 1.
    $this->assertEquals(1, $result, 'Org relationships should be skipped, only jur relationships used.');
  }

}
