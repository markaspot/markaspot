<?php

declare(strict_types=1);

namespace Drupal\markaspot\Access;

use Drupal\Core\DrupalKernelInterface;
use Drupal\Core\Installer\InstallerKernel;
use Drupal\Core\Update\UpdateKernel;

/**
 * Identifies trusted system runs that write content without a web actor.
 *
 * Drush commands, including updatedb and cron, run as the anonymous user.
 * Presave guards that model staff acting through the UI or an API (template
 * scope, blocked workspaces, tenant taxonomy scope) rejected every write of
 * an update hook and aborted updatedb for the whole instance. Only the
 * process type decides trust: anonymous web requests, API clients and the
 * PHP built-in web server ("cli-server") always remain subject to the guards.
 *
 * The service lives in the install profile, which every Mark-a-Spot module
 * can rely on without a module dependency. Guards look it up with
 * \Drupal::hasService() and treat a missing service as untrusted.
 */
final class SystemWriteContext {

  /**
   * Constructs the system write context.
   *
   * @param \Drupal\Core\DrupalKernelInterface $kernel
   *   The running kernel; update.php and drush updatedb run on the update
   *   kernel.
   * @param string $sapi
   *   The server API of the running process.
   */
  public function __construct(
    private readonly DrupalKernelInterface $kernel,
    private readonly string $sapi = PHP_SAPI,
  ) {}

  /**
   * Whether the running process is a trusted system context.
   */
  public function isTrusted(): bool {
    return $this->sapi === 'cli'
      || $this->kernel instanceof UpdateKernel
      || InstallerKernel::installationAttempted();
  }

}
