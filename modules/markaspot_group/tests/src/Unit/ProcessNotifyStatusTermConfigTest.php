<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Guards the shipped term-driven status notification model.
 */
#[Group('markaspot_group')]
final class ProcessNotifyStatusTermConfigTest extends UnitTestCase {

  /**
   * The action must pass ECA's acted-upon entity token.
   */
  public function testActionCarriesEntityObjectConfiguration(): void {
    $path = dirname(__DIR__, 3) . '/config/optional/eca.eca.process_notify_status_term.yml';
    $this->assertFileExists($path);

    $model = Yaml::decode((string) file_get_contents($path));
    $action = $model['actions']['Activity_send_term_notification'];

    $this->assertSame('markaspot_mail_send_term_notification', $action['plugin']);
    $this->assertSame('entity', $action['configuration']['object']);
    $this->assertSame('content_entity:update', $model['events']['Event_status_update']['plugin']);
  }

}
