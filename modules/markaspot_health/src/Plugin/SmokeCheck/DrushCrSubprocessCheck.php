<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\SmokeCheck;

use Drupal\Core\DrupalKernelInterface;
use Drupal\markaspot_health\SmokeCheckPluginBase;
use Drupal\markaspot_health\SmokeCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Spawns "drush cr" as a subprocess and asserts exit code 0.
 *
 * Subprocess is required: a type-variance fatal in any controller/form
 * subclass crashes the container compile inside drupal_flush_all_caches().
 * If we called that in-process from this plugin, the smoke command would
 * crash and the report would never reach the operator. The subprocess
 * isolates the failure mode so we can capture exit code + stderr and
 * present them as evidence.
 *
 * Mark-a-Spot 11.9.72 / 73 / 74 hotfixes were exactly this class of bug.
 *
 * @SmokeCheck(
 *   id = "drush_cr_subprocess",
 *   label = @Translation("drush cr subprocess"),
 *   severity = "error",
 *   category = "drupal_internal",
 *   description = @Translation("Spawns drush cr; non-zero exit code indicates a fatal during container compile."),
 *   fix_hint = @Translation("Read stderr for the offending class. Typical cause: typed promoted property in a ControllerBase / FormBase subclass conflicting with the parent untyped property."),
 * )
 */
class DrushCrSubprocessCheck extends SmokeCheckPluginBase {

  /**
   * Process timeout in seconds.
   *
   * Small tenants rebuild caches in a few seconds, but local cloud-style DDEV
   * tenants with full config exports and multiple languages can exceed 90
   * seconds while still healthy.
   */
  protected const TIMEOUT_SECONDS = 180;

  /**
   * Bytes of stderr to retain in the evidence payload.
   */
  protected const STDERR_TAIL_BYTES = 2048;

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected DrupalKernelInterface $kernel,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('kernel'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): SmokeCheckResult {
    $mode = $this->mode($context);

    $drush = $this->resolveDrushBinary();
    if ($drush === NULL) {
      return $this->skip('drush binary not found in vendor/bin or PATH; check skipped.', [], $mode);
    }

    $process = new Process([$drush, 'cr'], $this->kernel->getAppRoot());
    $process->setTimeout(self::TIMEOUT_SECONDS);

    try {
      $process->run();
    }
    catch (ProcessTimedOutException $e) {
      return $this->fail(1, sprintf('drush cr timed out after %ds.', self::TIMEOUT_SECONDS), [
        'binary' => $drush,
        'exit_code' => NULL,
        'stderr_tail' => $this->tail($process->getErrorOutput()),
      ], $mode);
    }

    $exit = $process->getExitCode();
    $evidence = [
      'binary' => $drush,
      'exit_code' => $exit,
      'stderr_tail' => $this->tail($process->getErrorOutput()),
    ];

    if ($exit === 0) {
      return $this->pass('drush cr exited 0.', $evidence, $mode);
    }
    return $this->fail(1, sprintf('drush cr exited %d.', (int) $exit), $evidence, $mode);
  }

  /**
   * Resolves a drush binary path, preferring vendor/bin over PATH lookup.
   *
   * Repository-relative path is preferred so the smoke check tests the
   * exact same drush version that the deploy uses. PATH fallback covers
   * containers that ship a system-installed drush instead.
   */
  protected function resolveDrushBinary(): ?string {
    $vendorBin = $this->kernel->getAppRoot() . '/../vendor/bin/drush';
    if (is_file($vendorBin) && is_executable($vendorBin)) {
      return $vendorBin;
    }
    // ExecutableFinder shells out via Symfony's hardened resolver instead of
    // raw shell_exec(); it does not invoke a shell, so PATH is consulted but
    // no command-string interpolation can occur.
    $found = (new ExecutableFinder())->find('drush');
    if ($found !== NULL && is_executable($found)) {
      return $found;
    }
    return NULL;
  }

  /**
   * Returns the trailing N bytes of stderr, scrubbed for credential leaks.
   *
   * Drupal's bootstrap fatal-prints raw PDO exceptions to stderr on a DB
   * connection failure, which can include the username and (with some PDO
   * driver / PHP version combinations) the password from the DSN string.
   * The smoke endpoint is reachable by anyone with view smoke checks
   * permission, so DSN credentials must never reach the JSON evidence.
   */
  protected function tail(string $stderr): string {
    $truncated = strlen($stderr) <= self::STDERR_TAIL_BYTES
      ? $stderr
      : '...' . substr($stderr, -self::STDERR_TAIL_BYTES);
    // Scrub common credential patterns: PDO DSN, env-var assignments, "using
    // password: YES" hint that confirms credential presence.
    $scrubbed = preg_replace(
      [
        '/(?:password|passwd|pwd)\s*[=:]\s*\S+/i',
        '/(?:user|username)\s*[=:]\s*\S+/i',
        '/(using password:\s*\w+)/i',
      ],
      ['password=[redacted]', 'user=[redacted]', 'using password: [redacted]'],
      $truncated,
    );
    return $scrubbed ?? $truncated;
  }

}
