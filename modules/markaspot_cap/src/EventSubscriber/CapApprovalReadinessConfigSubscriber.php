<?php

declare(strict_types=1);

namespace Drupal\markaspot_cap\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\markaspot_cap\Support\CapApprovalReadiness;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Revalidates CAP approval safety when its allowlist configuration changes.
 */
final class CapApprovalReadinessConfigSubscriber implements EventSubscriberInterface {

  /**
   * Revalidates after a direct allowlist configuration change.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() === 'markaspot_cap.settings'
      && $event->isChanged('approval_roles')) {
      CapApprovalReadiness::refresh();
    }
  }

  /**
   * Revalidates if the CAP settings object is removed.
   */
  public function onConfigDelete(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() === 'markaspot_cap.settings') {
      CapApprovalReadiness::refresh();
    }
  }

  /**
   * Revalidates after a completed config import.
   */
  public function onConfigImport(ConfigImporterEvent $event): void {
    CapApprovalReadiness::refresh();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => ['onConfigSave'],
      ConfigEvents::DELETE => ['onConfigDelete'],
      // Run after core's snapshot subscriber so active configuration is final.
      ConfigEvents::IMPORT => ['onConfigImport', -100],
    ];
  }

}
