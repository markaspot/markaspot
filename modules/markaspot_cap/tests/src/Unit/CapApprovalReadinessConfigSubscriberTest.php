<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_cap\Unit;

use Drupal\Core\Config\ConfigEvents;
use Drupal\markaspot_cap\EventSubscriber\CapApprovalReadinessConfigSubscriber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests CAP approval readiness config event coverage.
 */
#[Group('markaspot_cap')]
final class CapApprovalReadinessConfigSubscriberTest extends UnitTestCase {

  /**
   * The role allowlist is revalidated for direct changes and config imports.
   */
  public function testSubscribesToTheRequiredConfigEvents(): void {
    $events = CapApprovalReadinessConfigSubscriber::getSubscribedEvents();

    $this->assertSame(['onConfigSave'], $events[ConfigEvents::SAVE]);
    $this->assertSame(['onConfigDelete'], $events[ConfigEvents::DELETE]);
    $this->assertSame(
      ['onConfigImport', -100],
      $events[ConfigEvents::IMPORT],
    );
  }

}
