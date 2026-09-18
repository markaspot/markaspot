<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Service;

/**
 * Validates local raster logo bytes before any provisioning write.
 */
final class TenantLogoAsset {

  /**
   * Reads a bounded PNG from an explicitly supplied asset directory.
   */
  public static function read(string $name, ?string $directory): ?array {
    if ($name === '') {
      return NULL;
    }
    $root = $directory === NULL ? FALSE : realpath($directory);
    if ($root === FALSE || !is_dir($root) || str_contains($name, "\0") || str_starts_with($name, '/') || str_contains($name, '\\')) {
      throw new \RuntimeException('A logo requires an existing --assets-dir and a relative PNG file name.');
    }
    $path = realpath($root . DIRECTORY_SEPARATOR . $name);
    if ($path === FALSE || !str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) || !is_file($path) || !is_readable($path)) {
      throw new \RuntimeException('Logo must resolve to a readable file inside --assets-dir.');
    }
    // Read a bounded snapshot, then inspect exactly the bytes to be persisted.
    $bytes = file_get_contents($path, FALSE, NULL, 0, 512001);
    if ($bytes === FALSE || strlen($bytes) > 512000) {
      throw new \RuntimeException('Logo exceeds the 500 KiB size limit or cannot be read.');
    }
    $image = @getimagesizefromstring($bytes);
    if ($image === FALSE || ($image['mime'] ?? '') !== 'image/png' || $image[0] > 4096 || $image[1] > 4096 || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'png') {
      throw new \RuntimeException('Logo must be a valid PNG no larger than 4096 pixels per side. SVG and other formats are not accepted.');
    }
    return ['bytes' => $bytes, 'hash' => hash('sha256', $bytes)];
  }

}
