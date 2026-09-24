<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot\Unit;

use Drupal\Core\DrupalKernelInterface;
use Drupal\Core\Update\UpdateKernel;
use Drupal\markaspot\Access\SystemWriteContext;
use Drupal\Tests\UnitTestCase;

require_once dirname(__DIR__, 3) . '/src/Access/SystemWriteContext.php';

/**
 * Tests which processes may write without a staff scope.
 *
 * @group markaspot
 * @coversDefaultClass \Drupal\markaspot\Access\SystemWriteContext
 */
final class SystemWriteContextTest extends UnitTestCase {

  /**
   * Drush, including updatedb and cron, is a trusted system context.
   *
   * @covers ::isTrusted
   */
  public function testCommandLineIsTrusted(): void {
    $context = new SystemWriteContext($this->createMock(DrupalKernelInterface::class), 'cli');
    $this->assertTrue($context->isTrusted());
  }

  /**
   * Web and API requests stay subject to the presave guards.
   *
   * @covers ::isTrusted
   */
  public function testWebRequestsAreNotTrusted(): void {
    foreach (['fpm-fcgi', 'apache2handler', 'cgi-fcgi', 'cli-server', 'phpdbg'] as $sapi) {
      $context = new SystemWriteContext($this->createMock(DrupalKernelInterface::class), $sapi);
      $this->assertFalse($context->isTrusted(), $sapi);
    }
  }

  /**
   * The update.php batch runs on the update kernel of a web server.
   *
   * @covers ::isTrusted
   */
  public function testUpdateKernelIsTrusted(): void {
    $context = new SystemWriteContext($this->createMock(UpdateKernel::class), 'fpm-fcgi');
    $this->assertTrue($context->isTrusted());
  }

}
