<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * Tests user-delete cleanup for group memberships.
 *
 * @group markaspot_group
 */
class UserDeleteCascadeTest extends UnitTestCase {

  /**
   * Tests deleting a user deletes only their group membership relationships.
   */
  public function testUserDeleteDeletesGroupMembershipRelationships(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_group.module';

    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn(42);

    $membershipA = $this->createMock(GroupRelationshipInterface::class);
    $membershipA->expects($this->once())->method('delete');
    $membershipB = $this->createMock(GroupRelationshipInterface::class);
    $membershipB->expects($this->once())->method('delete');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadByProperties')
      ->with([
        'entity_id' => 42,
        'plugin_id' => 'group_membership',
      ])
      ->willReturn([$membershipA, $membershipB]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('group_relationship')
      ->willReturn($storage);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entityTypeManager);
    Drupal::setContainer($container);

    markaspot_group_user_delete($account);
  }

}
