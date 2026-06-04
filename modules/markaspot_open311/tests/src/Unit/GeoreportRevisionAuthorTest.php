<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestResource;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests revision authorship in the Open311 update path.
 *
 * GeoreportRequestResource::specialFieldHandling() creates a new node
 * revision whenever a revision_log_message is supplied. The revision must be
 * attributed to the acting user so the dashboard "last edited by" indicator
 * (markaspot-ui#472) reflects the editor rather than an empty author.
 *
 * specialFieldHandling() is protected and the resource has a wide constructor;
 * the revision branch only depends on the injected currentUser and time
 * services, so the test instantiates the resource without its constructor and
 * sets just those two properties via reflection. A $values array carrying only
 * revision_log_message bypasses the field_status / field_status_notes branches
 * (which would need the processor service), isolating the revision logic.
 *
 * @group markaspot_open311
 *
 * @coversDefaultClass \Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestResource
 */
class GeoreportRevisionAuthorTest extends UnitTestCase {

  /**
   * The request time used by the stubbed time service.
   */
  private const REQUEST_TIME = 1717488000;

  /**
   * Builds a resource instance with currentUser + time wired via reflection.
   *
   * @param int $uid
   *   The user id the stubbed currentUser should report.
   *
   * @return \Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestResource
   *   The partially-initialised resource.
   */
  private function buildResource(int $uid): GeoreportRequestResource {
    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('id')->willReturn($uid);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::REQUEST_TIME);

    $reflection = new \ReflectionClass(GeoreportRequestResource::class);
    /** @var \Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestResource $resource */
    $resource = $reflection->newInstanceWithoutConstructor();

    $userProperty = $reflection->getProperty('currentUser');
    $userProperty->setAccessible(TRUE);
    $userProperty->setValue($resource, $currentUser);

    $timeProperty = $reflection->getProperty('time');
    $timeProperty->setAccessible(TRUE);
    $timeProperty->setValue($resource, $time);

    return $resource;
  }

  /**
   * Invokes the protected specialFieldHandling() method.
   *
   * @param \Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestResource $resource
   *   The resource instance.
   * @param \Drupal\node\NodeInterface $node
   *   The node being updated.
   * @param array $values
   *   The prepared values array.
   */
  private function invokeSpecialFieldHandling(GeoreportRequestResource $resource, NodeInterface $node, array $values): void {
    $method = new \ReflectionMethod(GeoreportRequestResource::class, 'specialFieldHandling');
    $method->setAccessible(TRUE);
    $method->invoke($resource, $node, $values);
  }

  /**
   * The created revision is attributed to the acting user.
   *
   * @covers ::specialFieldHandling
   */
  public function testRevisionIsAttributedToActingUser(): void {
    $resource = $this->buildResource(42);

    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->once())->method('setNewRevision')->with(TRUE);
    $node->expects($this->once())->method('setRevisionLogMessage')->with('Status updated via Open311');
    $node->expects($this->once())->method('setRevisionCreationTime')->with(self::REQUEST_TIME);
    // The regression guard: the revision must carry the editor's uid.
    $node->expects($this->once())->method('setRevisionUserId')->with(42);

    $this->invokeSpecialFieldHandling($resource, $node, [
      'revision_log_message' => 'Status updated via Open311',
    ]);
  }

  /**
   * Anonymous Open311 writes attribute the revision to uid 0, not nothing.
   *
   * @covers ::specialFieldHandling
   */
  public function testAnonymousRevisionIsAttributedToUidZero(): void {
    $resource = $this->buildResource(0);

    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->once())->method('setNewRevision')->with(TRUE);
    $node->expects($this->once())->method('setRevisionUserId')->with(0);

    $this->invokeSpecialFieldHandling($resource, $node, [
      'revision_log_message' => 'Anonymous status update',
    ]);
  }

  /**
   * Without a revision_log_message no revision (and no author) is set.
   *
   * @covers ::specialFieldHandling
   */
  public function testNoRevisionWithoutLogMessage(): void {
    $resource = $this->buildResource(42);

    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->never())->method('setNewRevision');
    $node->expects($this->never())->method('setRevisionUserId');

    $this->invokeSpecialFieldHandling($resource, $node, []);
  }

}
