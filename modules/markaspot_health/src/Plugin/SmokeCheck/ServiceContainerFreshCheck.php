<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\SmokeCheck;

use Drupal\Core\DrupalKernelInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\markaspot_health\SmokeCheckPluginBase;
use Drupal\markaspot_health\SmokeCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Warns when the compiled service container is older than 24 hours.
 *
 * Heuristic check: catches the "operator forgot drush cr after a config
 * import" failure mode in dev/test environments. Severity is intentionally
 * "warning" (not "error") because cloud-image deploys bake the container
 * into the image, where a 36-hour-old timestamp is "stable", not "stale".
 *
 * The check skips gracefully when the container file path is not writable
 * or unreadable (read-only image layers, Twig-cache disabled), making it
 * safe to run on every tenant.
 *
 * @SmokeCheck(
 *   id = "service_container_fresh",
 *   label = @Translation("Compiled service container freshness"),
 *   severity = "warning",
 *   category = "drupal_internal",
 *   description = @Translation("Warns when the compiled service container PHP files are older than 24 hours."),
 *   fix_hint = @Translation("Run drush cr if the tenant was recently changed; ignore on cloud-image tenants where the container is baked into the image."),
 * )
 */
class ServiceContainerFreshCheck extends SmokeCheckPluginBase {

  /**
   * Maximum acceptable container age in seconds (24 hours).
   */
  protected const MAX_AGE_SECONDS = 86400;

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected DrupalKernelInterface $kernel,
    protected FileSystemInterface $fileSystem,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('kernel'),
      $container->get('file_system'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): SmokeCheckResult {
    $mode = $this->mode($context);

    $sitePath = $this->kernel->getSitePath();
    if ($sitePath === '') {
      return $this->skip('Drupal site path unresolved; check skipped.', [], $mode);
    }
    $phpDir = $this->kernel->getAppRoot() . '/' . $sitePath . '/files/php';
    if (!is_dir($phpDir) || !is_readable($phpDir)) {
      return $this->skip('PHP storage directory not present or not readable; check skipped.', [
        'path' => $phpDir,
      ], $mode);
    }

    $containerFiles = glob($phpDir . '/Container_*.php') ?: [];
    if ($containerFiles === []) {
      return $this->skip('No compiled Container_*.php found; check skipped.', [
        'path' => $phpDir,
      ], $mode);
    }

    $newest = 0;
    foreach ($containerFiles as $file) {
      $mtime = filemtime($file);
      if ($mtime !== FALSE && $mtime > $newest) {
        $newest = $mtime;
      }
    }
    $age = time() - $newest;
    $evidence = [
      'newest_compiled_at' => gmdate('c', $newest),
      'age_seconds' => $age,
      'files_count' => count($containerFiles),
    ];

    if ($age <= self::MAX_AGE_SECONDS) {
      return $this->pass(sprintf('Container compiled %d second(s) ago.', $age), $evidence, $mode);
    }
    // Reported as warning (severity from annotation), not as fail; see
    // class-level docblock for why error severity is wrong here.
    return $this->fail(
      1,
      sprintf('Container compiled %d hour(s) ago, exceeds 24h heuristic.', (int) ($age / 3600)),
      $evidence,
      $mode,
    );
  }

}
