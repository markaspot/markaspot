<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_resubmission\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\markaspot_resubmission\Entity\ResubmissionReminder;
use Drupal\markaspot_resubmission\Event\ResubmissionReminderEvent;
use Drupal\markaspot_resubmission\Plugin\QueueWorker\ResubmissionQueueWorker;
use Drupal\markaspot_resubmission\ReminderManager;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

require_once dirname(__DIR__, 3) . '/src/Plugin/QueueWorker/ResubmissionQueueWorker.php';

/**
 * Tests the resubmission queue worker recipient resolution.
 *
 * @group markaspot_resubmission
 * @coversDefaultClass \Drupal\markaspot_resubmission\Plugin\QueueWorker\ResubmissionQueueWorker
 */
class ResubmissionQueueWorkerTest extends UnitTestCase {

  /**
   * Tests missing scope config defaults to org and term fallback is used.
   *
   * @covers ::processItem
   * @covers ::resolveRecipient
   * @covers ::getEffectiveReminderScope
   */
  public function testMissingScopeDefaultsToOrgAndUsesTermFallback(): void {
    $node = $this->mockNode(42);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('warning');

    $worker = $this->createWorker(
      node: $node,
      default_scope: NULL,
      group_module_enabled: FALSE,
      group_email: NULL,
      term_email: 'term@example.com',
      logger: $logger,
      expected_email: 'term@example.com',
      expected_scope: ResubmissionReminder::REMINDER_SCOPE_ORG,
    );

    $worker->processItem(['nid' => 42]);

    $this->assertSame(0, $worker->groupFieldCalls);
    $this->assertSame(1, $worker->termFieldCalls);
  }

  /**
   * Tests user scope without an assignee falls back to operational org scope.
   *
   * @covers ::processItem
   * @covers ::resolveRecipient
   * @covers ::getEffectiveReminderScope
   */
  public function testUserScopeFallsBackToOrgWithWarning(): void {
    $node = $this->mockNode(43);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        'user scope requested but service request @nid is unassigned; falling back to org',
        ['@nid' => 43]
      );

    $worker = $this->createWorker(
      node: $node,
      default_scope: ResubmissionReminder::REMINDER_SCOPE_USER,
      group_module_enabled: FALSE,
      group_email: NULL,
      term_email: 'term@example.com',
      logger: $logger,
      expected_email: 'term@example.com',
      expected_scope: ResubmissionReminder::REMINDER_SCOPE_ORG,
    );

    $worker->processItem(['nid' => 43]);

    $this->assertSame(1, $worker->assigneeEmailCalls);
    $this->assertSame(0, $worker->groupFieldCalls);
    $this->assertSame(1, $worker->termFieldCalls);
  }

  /**
   * Tests user scope uses the assigned user's email.
   *
   * @covers ::processItem
   * @covers ::resolveRecipient
   */
  public function testUserScopeUsesAssigneeEmail(): void {
    $node = $this->mockNode(46);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('warning');

    $worker = $this->createWorker(
      node: $node,
      default_scope: ResubmissionReminder::REMINDER_SCOPE_USER,
      group_module_enabled: TRUE,
      group_email: 'group@example.com',
      term_email: 'term@example.com',
      logger: $logger,
      expected_email: 'assignee@example.com',
      expected_scope: ResubmissionReminder::REMINDER_SCOPE_USER,
      assignee_email: 'assignee@example.com',
      expect_group_module_check: FALSE,
    );

    $worker->processItem(['nid' => 46]);

    $this->assertSame(1, $worker->assigneeEmailCalls);
    $this->assertSame(0, $worker->groupFieldCalls);
    $this->assertSame(0, $worker->termFieldCalls);
  }

  /**
   * Tests markaspot_group is checked and group email wins over term email.
   *
   * @covers ::processItem
   * @covers ::resolveRecipient
   */
  public function testGroupEmailIsPreferredWhenMarkaspotGroupIsEnabled(): void {
    $node = $this->mockNode(44);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('warning');

    $worker = $this->createWorker(
      node: $node,
      default_scope: ResubmissionReminder::REMINDER_SCOPE_ORG,
      group_module_enabled: TRUE,
      group_email: 'group@example.com',
      term_email: 'term@example.com',
      logger: $logger,
      expected_email: 'group@example.com',
      expected_scope: ResubmissionReminder::REMINDER_SCOPE_ORG,
    );

    $worker->processItem(['nid' => 44]);

    $this->assertSame(1, $worker->groupFieldCalls);
    $this->assertSame(0, $worker->termFieldCalls);
  }

  /**
   * Tests term email is used when the group path has no email.
   *
   * @covers ::processItem
   * @covers ::resolveRecipient
   */
  public function testTermEmailIsFallbackWhenGroupEmailIsEmpty(): void {
    $node = $this->mockNode(45);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('warning');

    $worker = $this->createWorker(
      node: $node,
      default_scope: ResubmissionReminder::REMINDER_SCOPE_ORG,
      group_module_enabled: TRUE,
      group_email: '',
      term_email: 'term@example.com',
      logger: $logger,
      expected_email: 'term@example.com',
      expected_scope: ResubmissionReminder::REMINDER_SCOPE_ORG,
    );

    $worker->processItem(['nid' => 45]);

    $this->assertSame(1, $worker->groupFieldCalls);
    $this->assertSame(1, $worker->termFieldCalls);
  }

  /**
   * Tests field_organisation is used before legacy group relationships.
   *
   * @covers ::getGroupField
   * @covers ::getOrganisationGroupIds
   * @covers ::getRelationshipGroupIds
   * @covers ::getFirstGroupEmail
   */
  public function testGroupFieldUsesOrganisationFieldBeforeRelationships(): void {
    $organisation_field = $this->createMock(FieldItemListInterface::class);
    $organisation_field->expects($this->once())
      ->method('isEmpty')
      ->willReturn(FALSE);
    $organisation_field->expects($this->once())
      ->method('getValue')
      ->willReturn([
        ['target_id' => 7],
      ]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')
      ->with('field_organisation')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('field_organisation')
      ->willReturn($organisation_field);

    $email_field = $this->createMock(FieldItemListInterface::class);
    $email_field->expects($this->once())
      ->method('getString')
      ->willReturn('field-org@example.com');

    $group = $this->createMock(ContentEntityInterface::class);
    $group->expects($this->once())
      ->method('hasField')
      ->with('field_head_organisation_e_mail')
      ->willReturn(TRUE);
    $group->expects($this->once())
      ->method('get')
      ->with('field_head_organisation_e_mail')
      ->willReturn($email_field);

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->expects($this->once())
      ->method('load')
      ->with(7)
      ->willReturn($group);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->once())
      ->method('getStorage')
      ->with('group')
      ->willReturn($group_storage);

    $worker = new FieldAwareResubmissionQueueWorker(
      [],
      'markaspot_resubmission_queue_worker',
      [],
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $entity_type_manager,
      $this->createMock(MailManagerInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(ReminderManager::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(Token::class),
      $this->createMock(EventDispatcherInterface::class),
    );

    $this->assertSame('field-org@example.com', $worker->exposeGroupField($node));
    $this->assertSame(0, $worker->relationshipFieldCalls);
  }

  /**
   * Creates a testable worker with mocked dependencies.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node loaded by the worker.
   * @param string|null $default_scope
   *   The configured default reminder scope.
   * @param bool $group_module_enabled
   *   Whether markaspot_group is enabled.
   * @param string|null $group_email
   *   The group resolver result.
   * @param string|null $term_email
   *   The term resolver result.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger mock.
   * @param string $expected_email
   *   The recipient expected in the event and audit call.
   * @param string $expected_scope
   *   The resolved scope expected in the audit call.
   * @param string|null $assignee_email
   *   The assignee resolver result.
   * @param bool $expect_group_module_check
   *   Whether markaspot_group should be checked.
   *
   * @return \Drupal\Tests\markaspot_resubmission\Unit\TestableResubmissionQueueWorker
   *   The testable queue worker.
   */
  private function createWorker(
    NodeInterface $node,
    ?string $default_scope,
    bool $group_module_enabled,
    ?string $group_email,
    ?string $term_email,
    LoggerChannelInterface $logger,
    string $expected_email,
    string $expected_scope,
    ?string $assignee_email = NULL,
    bool $expect_group_module_check = TRUE,
  ): TestableResubmissionQueueWorker {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['mailtext', 'Reminder text'],
        ['default_reminder_scope', $default_scope],
      ]);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('markaspot_resubmission.settings')
      ->willReturn($config);

    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->expects($this->once())
      ->method('load')
      ->with($node->id())
      ->willReturn($node);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->with('node')
      ->willReturn($node_storage);

    $reminder_manager = $this->createMock(ReminderManager::class);
    $reminder_manager->expects($this->once())
      ->method('getReminderCount')
      ->with($node->id())
      ->willReturn(0);
    $reminder_manager->expects($this->once())
      ->method('createReminder')
      ->with($node, $expected_email, 'sent', NULL, $expected_scope);

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->expects($expect_group_module_check ? $this->once() : $this->never())
      ->method('moduleExists')
      ->with('markaspot_group')
      ->willReturn($group_module_enabled);

    $event_dispatcher = $this->createMock(EventDispatcherInterface::class);
    $event_dispatcher->expects($this->once())
      ->method('dispatch')
      ->with(
        $this->callback(function (ResubmissionReminderEvent $event) use ($node, $expected_email): bool {
          return $event->getNode() === $node
            && $event->getRecipientEmail() === $expected_email
            && $event->getMailText() === 'Reminder text'
            && $event->getReminderCount() === 1;
        }),
        ResubmissionReminderEvent::EVENT_NAME
      )
      ->willReturnCallback(static function (object $event): object {
        return $event;
      });

    $worker = new TestableResubmissionQueueWorker(
      [],
      'markaspot_resubmission_queue_worker',
      [],
      $logger,
      $config_factory,
      $entity_type_manager,
      $this->createMock(MailManagerInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $reminder_manager,
      $module_handler,
      $this->createMock(Token::class),
      $event_dispatcher,
    );
    $worker->groupEmail = $group_email;
    $worker->termEmail = $term_email;
    $worker->assigneeEmail = $assignee_email;

    return $worker;
  }

  /**
   * Creates a node mock.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return \Drupal\node\NodeInterface
   *   The node mock.
   */
  private function mockNode(int $nid): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nid);

    return $node;
  }

}

/**
 * Test adapter for protected recipient resolver methods.
 */
class TestableResubmissionQueueWorker extends ResubmissionQueueWorker {

  /**
   * The group email returned by the test adapter.
   *
   * @var string|null
   */
  public ?string $groupEmail = NULL;

  /**
   * The term email returned by the test adapter.
   *
   * @var string|null
   */
  public ?string $termEmail = NULL;

  /**
   * The assignee email returned by the test adapter.
   *
   * @var string|null
   */
  public ?string $assigneeEmail = NULL;

  /**
   * Number of group field resolver calls.
   *
   * @var int
   */
  public int $groupFieldCalls = 0;

  /**
   * Number of term field resolver calls.
   *
   * @var int
   */
  public int $termFieldCalls = 0;

  /**
   * Number of assignee email resolver calls.
   *
   * @var int
   */
  public int $assigneeEmailCalls = 0;

  /**
   * {@inheritdoc}
   */
  protected function getAssigneeEmail($node) {
    $this->assigneeEmailCalls++;
    return $this->assigneeEmail;
  }

  /**
   * {@inheritdoc}
   */
  protected function getGroupField($node) {
    $this->groupFieldCalls++;
    return $this->groupEmail;
  }

  /**
   * {@inheritdoc}
   */
  protected function getOrganisationTermField($node) {
    $this->termFieldCalls++;
    return $this->termEmail;
  }

}

/**
 * Test adapter that exposes real group field resolution.
 */
class FieldAwareResubmissionQueueWorker extends ResubmissionQueueWorker {

  /**
   * Number of relationship fallback calls.
   *
   * @var int
   */
  public int $relationshipFieldCalls = 0;

  /**
   * Exposes protected group field resolution.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string|null
   *   The resolved email, if any.
   */
  public function exposeGroupField(NodeInterface $node): ?string {
    return $this->getGroupField($node);
  }

  /**
   * {@inheritdoc}
   */
  protected function getRelationshipGroupIds($node) {
    $this->relationshipFieldCalls++;
    return [999];
  }

}
