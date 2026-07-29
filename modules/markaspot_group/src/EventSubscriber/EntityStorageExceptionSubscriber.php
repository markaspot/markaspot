<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\EventSubscriber;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\markaspot_group\Exception\OrganisationDeleteBlockedException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Restores HTTP semantics hidden by entity storage exception wrapping.
 */
final class EntityStorageExceptionSubscriber implements EventSubscriberInterface {

  /**
   * Maps known or wrapped storage exceptions to their HTTP exceptions.
   */
  public function onException(ExceptionEvent $event): void {
    $throwable = $event->getThrowable();
    if (!$throwable instanceof EntityStorageException) {
      return;
    }

    for ($candidate = $throwable; $candidate !== NULL; $candidate = $candidate->getPrevious()) {
      if ($candidate instanceof OrganisationDeleteBlockedException) {
        $event->setThrowable(new ConflictHttpException(
          $candidate->getMessage(),
          $throwable,
        ));
        return;
      }
    }

    $previous = $throwable->getPrevious();
    if ($previous instanceof HttpExceptionInterface) {
      $event->setThrowable($previous);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::EXCEPTION => ['onException', 75],
    ];
  }

}
