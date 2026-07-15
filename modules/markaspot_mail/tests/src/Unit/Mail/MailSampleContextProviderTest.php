<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Mail;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Mail\MailSampleContextProvider;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests bounded sample-entity lookup for the mail render gate.
 *
 * @group markaspot_mail
 * @coversDefaultClass \Drupal\markaspot_mail\Mail\MailSampleContextProvider
 */
final class MailSampleContextProviderTest extends UnitTestCase {

  /**
   * Tests service-request lookup queries and loads one entity only.
   *
   * @covers ::findSampleServiceRequestNode
   */
  public function testFindSampleServiceRequestNodeUsesBoundedQuery(): void {
    $node = $this->createMock(NodeInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $query = $this->createMock(QueryInterface::class);

    $query->expects($this->once())->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->expects($this->once())->method('condition')->with('type', 'service_request')->willReturnSelf();
    $query->expects($this->once())->method('range')->with(0, 1)->willReturnSelf();
    $query->expects($this->once())->method('execute')->willReturn([42 => 42]);
    $storage->expects($this->once())->method('getQuery')->willReturn($query);
    $storage->expects($this->once())->method('load')->with(42)->willReturn($node);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())->method('hasDefinition')->with('node')->willReturn(TRUE);
    $entityTypeManager->expects($this->once())->method('getStorage')->with('node')->willReturn($storage);

    $provider = new MailSampleContextProvider($entityTypeManager);

    $this->assertSame($node, $provider->findSampleServiceRequestNode());
  }

  /**
   * Tests organisation lookup queries and loads one entity only.
   *
   * @covers ::findSampleOrganisation
   */
  public function testFindSampleOrganisationUsesBoundedQuery(): void {
    $group = $this->createMock(GroupInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $query = $this->createMock(QueryInterface::class);

    $query->expects($this->once())->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->expects($this->once())->method('condition')->with('type', 'org')->willReturnSelf();
    $query->expects($this->once())->method('range')->with(0, 1)->willReturnSelf();
    $query->expects($this->once())->method('execute')->willReturn([7 => 7]);
    $storage->expects($this->once())->method('getQuery')->willReturn($query);
    $storage->expects($this->once())->method('load')->with(7)->willReturn($group);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())->method('hasDefinition')->with('group')->willReturn(TRUE);
    $entityTypeManager->expects($this->once())->method('getStorage')->with('group')->willReturn($storage);

    $provider = new MailSampleContextProvider($entityTypeManager);

    $this->assertSame($group, $provider->findSampleOrganisation());
  }

}
