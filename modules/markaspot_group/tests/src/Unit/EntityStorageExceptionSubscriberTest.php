<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\markaspot_group\EventSubscriber\EntityStorageExceptionSubscriber;
use Drupal\markaspot_group\Exception\OrganisationDeleteBlockedException;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

require_once dirname(__DIR__, 3) . '/src/Exception/OrganisationDeleteBlockedException.php';
require_once dirname(__DIR__, 3) . '/src/EventSubscriber/EntityStorageExceptionSubscriber.php';

/**
 * Tests storage exception HTTP mapping.
 */
#[CoversClass(EntityStorageExceptionSubscriber::class)]
#[Group('markaspot_group')]
final class EntityStorageExceptionSubscriberTest extends UnitTestCase {

  /**
   * Tests a wrapped organisation blocker becomes HTTP 409.
   */
  public function testOrganisationDeleteBlockerBecomesConflict(): void {
    $blocked = new OrganisationDeleteBlockedException('Organisation is referenced.');
    $event = $this->event(new EntityStorageException($blocked->getMessage(), 0, $blocked));

    (new EntityStorageExceptionSubscriber())->onException($event);

    $this->assertInstanceOf(ConflictHttpException::class, $event->getThrowable());
    $this->assertSame(409, $event->getThrowable()->getStatusCode());
    $this->assertSame('Organisation is referenced.', $event->getThrowable()->getMessage());
  }

  /**
   * Tests generic wrapped HTTP exceptions retain their status.
   */
  public function testWrappedHttpExceptionIsRestored(): void {
    $denied = new AccessDeniedHttpException('Denied.');
    $event = $this->event(new EntityStorageException($denied->getMessage(), 0, $denied));

    (new EntityStorageExceptionSubscriber())->onException($event);

    $this->assertSame($denied, $event->getThrowable());
  }

  /**
   * Creates an exception event.
   */
  private function event(\Throwable $throwable): ExceptionEvent {
    return new ExceptionEvent(
      $this->createMock(KernelInterface::class),
      Request::create('/jsonapi/group/org/uuid', 'DELETE'),
      HttpKernelInterface::MAIN_REQUEST,
      $throwable,
    );
  }

}
