<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_escalation\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_escalation\Service\EscalationServiceInterface;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

require_once __DIR__ . '/../../../markaspot_escalation.module';

/**
 * Tests markaspot_escalation_markaspot_open311_request_alter().
 *
 * @group markaspot_escalation
 */
class EscalationRequestAlterTest extends UnitTestCase {

  /**
   * Tests the gated escalation target attributes for an organisation target.
   */
  public function testRequestAlterAddsOrganisationEscalationTarget(): void {
    $node = $this->createNode(FALSE);
    $this->setServices(
      $node,
      ['escalate service requests'],
      200,
      'org',
      delegationNoteRequired: TRUE,
    );

    $request = [];
    markaspot_escalation_markaspot_open311_request_alter($request, $node);

    $markaspot = $request['extended_attributes']['markaspot'];
    $this->assertFalse($markaspot['escalated']);
    $this->assertSame(200, $markaspot['escalation_target_id']);
    $this->assertSame('org', $markaspot['escalation_target_kind']);
    $this->assertSame('Org 200', $markaspot['escalation_target_label']);
    $this->assertTrue($markaspot['note_required']);
    $this->assertArrayHasKey('delegate', $markaspot['permissions']);
  }

  /**
   * Tests the gated escalation target attributes for a jurisdiction target.
   */
  public function testRequestAlterAddsJurisdictionEscalationTarget(): void {
    $node = $this->createNode(FALSE);
    $this->setServices($node, ['escalate service requests'], 14, 'jur');

    $request = [];
    markaspot_escalation_markaspot_open311_request_alter($request, $node);

    $markaspot = $request['extended_attributes']['markaspot'];
    $this->assertSame(14, $markaspot['escalation_target_id']);
    $this->assertSame('jur', $markaspot['escalation_target_kind']);
    $this->assertSame('Jur 14', $markaspot['escalation_target_label']);
    $this->assertFalse($markaspot['note_required']);
  }

  /**
   * Tests the gated escalation target attributes when no target resolves.
   */
  public function testRequestAlterAddsNullEscalationTarget(): void {
    $node = $this->createNode(FALSE);
    $this->setServices($node, ['escalate service requests'], NULL, NULL);

    $request = [];
    markaspot_escalation_markaspot_open311_request_alter($request, $node);

    $markaspot = $request['extended_attributes']['markaspot'];
    $this->assertNull($markaspot['escalation_target_id']);
    $this->assertNull($markaspot['escalation_target_kind']);
    $this->assertNull($markaspot['escalation_target_label']);
  }

  /**
   * Tests escalation target attributes are hidden without staff permissions.
   */
  public function testRequestAlterHidesTargetWithoutEscalationPermissions(): void {
    $node = $this->createNode(FALSE);
    $this->setServices($node, [], 200, 'org', FALSE, FALSE);

    $request = [];
    markaspot_escalation_markaspot_open311_request_alter($request, $node);

    $markaspot = $request['extended_attributes']['markaspot'];
    $this->assertArrayNotHasKey('escalation_target_id', $markaspot);
    $this->assertArrayNotHasKey('escalation_target_kind', $markaspot);
    $this->assertArrayNotHasKey('escalation_target_label', $markaspot);
    $this->assertArrayNotHasKey('note_required', $markaspot);
    $this->assertArrayNotHasKey('permissions', $markaspot);
  }

  /**
   * Tests escalation target attributes are hidden for foreign-tenant staff.
   */
  public function testRequestAlterHidesTargetForForeignTenantStaff(): void {
    $node = $this->createNode(FALSE);
    $this->setServices(
      $node,
      ['escalate service requests'],
      200,
      'org',
      TRUE,
      FALSE,
      14,
      TRUE,
      FALSE,
    );

    $request = [];
    markaspot_escalation_markaspot_open311_request_alter($request, $node);

    $markaspot = $request['extended_attributes']['markaspot'];
    $this->assertArrayNotHasKey('escalation_target_id', $markaspot);
    $this->assertArrayNotHasKey('escalation_target_kind', $markaspot);
    $this->assertArrayNotHasKey('escalation_target_label', $markaspot);
    $this->assertArrayNotHasKey('note_required', $markaspot);
    $this->assertArrayNotHasKey('permissions', $markaspot);
  }

  /**
   * Tests escalation target attributes are hidden for unresolved tenants.
   */
  public function testRequestAlterHidesTargetForUnresolvedTenant(): void {
    $node = $this->createNode(FALSE);
    $this->setServices(
      $node,
      ['escalate service requests'],
      200,
      'org',
      TRUE,
      FALSE,
      NULL,
    );

    $request = [];
    markaspot_escalation_markaspot_open311_request_alter($request, $node);

    $markaspot = $request['extended_attributes']['markaspot'];
    $this->assertArrayNotHasKey('escalation_target_id', $markaspot);
    $this->assertArrayNotHasKey('escalation_target_kind', $markaspot);
    $this->assertArrayNotHasKey('escalation_target_label', $markaspot);
    $this->assertArrayNotHasKey('note_required', $markaspot);
    $this->assertArrayNotHasKey('permissions', $markaspot);
  }

  /**
   * Creates a service request node stub.
   *
   * @param bool $escalated
   *   Whether the request already has field_escalation set.
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked node.
   */
  private function createNode(bool $escalated): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $field = $escalated
      ? new EscalationFieldStub(FALSE, $this->createGroup(14, 'jur', 'Parent Jurisdiction'))
      : new EscalationFieldStub(TRUE);

    $node->method('hasField')
      ->willReturnCallback(static fn(string $fieldName): bool => $fieldName === 'field_escalation');
    $node->method('get')
      ->willReturnCallback(static fn(string $fieldName): ?EscalationFieldStub => $fieldName === 'field_escalation' ? $field : NULL);

    return $node;
  }

  /**
   * Registers container services used by the alter hook.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node expected by processor and escalation service calls.
   * @param string[] $permissions
   *   Permissions granted to the current user.
   * @param int|null $targetGroupId
   *   Escalation target ID returned by the service.
   * @param string|null $targetBundle
   *   Bundle returned by the target group.
   * @param bool $expectProcessor
   *   Whether the tenant processor is expected to be called.
   * @param bool $expectEscalationService
   *   Whether the escalation service is expected to be called.
   * @param int|null $jurisdictionId
   *   Jurisdiction ID returned by the processor.
   * @param bool $hasJurisdictionGroups
   *   Whether jurisdiction groups exist.
   * @param bool $isJurisdictionMember
   *   Whether the current user is a member of the node jurisdiction.
   * @param bool $delegationNoteRequired
   *   Note requirement returned by the escalation service.
   */
  private function setServices(
    NodeInterface $node,
    array $permissions,
    ?int $targetGroupId,
    ?string $targetBundle,
    bool $expectProcessor = TRUE,
    bool $expectEscalationService = TRUE,
    ?int $jurisdictionId = 14,
    bool $hasJurisdictionGroups = TRUE,
    bool $isJurisdictionMember = TRUE,
    bool $delegationNoteRequired = FALSE,
  ): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));

    $processor = $this->createMock(GeoreportProcessorServiceInterface::class);
    $processor->expects($expectProcessor ? $this->once() : $this->never())
      ->method('resolveNodeJurisdictionId')
      ->with($node)
      ->willReturn($jurisdictionId);
    $processor->method('isJurisdictionMember')
      ->with($jurisdictionId, $account)
      ->willReturn($isJurisdictionMember);
    $processor->method('hasJurisdictionGroups')->willReturn($hasJurisdictionGroups);

    $escalationService = $this->createMock(EscalationServiceInterface::class);
    $escalationService->expects($expectEscalationService ? $this->once() : $this->never())
      ->method('resolveEscalationTarget')
      ->with($node)
      ->willReturn($targetGroupId);
    $escalationService->expects($expectEscalationService ? $this->once() : $this->never())
      ->method('isDelegationNoteRequired')
      ->with($node)
      ->willReturn($delegationNoteRequired);
    $escalationService->method('canEscalate')->willReturn($targetGroupId !== NULL);
    $escalationService->method('canDelegate')->willReturn(FALSE);

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    if ($expectEscalationService && $targetGroupId !== NULL && $targetBundle !== NULL) {
      $groupStorage->expects($this->once())
        ->method('load')
        ->with($targetGroupId)
        ->willReturn($this->createGroup($targetGroupId, $targetBundle));
    }
    else {
      $groupStorage->expects($this->never())->method('load');
    }

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    $container->set('markaspot_open311.processor', $processor);
    $container->set('markaspot_escalation.service', $escalationService);
    $container->set('entity_type.manager', $entityTypeManager);
    \Drupal::setContainer($container);
  }

  /**
   * Creates a group stub.
   *
   * @param int $id
   *   The group ID.
   * @param string $bundle
   *   The group bundle.
   * @param string|null $label
   *   Optional group label.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked group.
   */
  private function createGroup(int $id, string $bundle, ?string $label = NULL): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('bundle')->willReturn($bundle);
    $group->method('label')->willReturn($label ?? ucfirst($bundle) . ' ' . $id);
    return $group;
  }

}

/**
 * Minimal field stub for field_escalation.
 */
final class EscalationFieldStub {

  /**
   * The referenced entity.
   *
   * @var object|null
   */
  public ?object $entity;

  /**
   * Constructs an escalation field stub.
   *
   * @param bool $empty
   *   Whether the field is empty.
   * @param object|null $entity
   *   The referenced entity.
   */
  public function __construct(
    private bool $empty,
    ?object $entity = NULL,
  ) {
    $this->entity = $entity;
  }

  /**
   * Checks whether the field is empty.
   *
   * @return bool
   *   TRUE when the field is empty.
   */
  public function isEmpty(): bool {
    return $this->empty;
  }

}
