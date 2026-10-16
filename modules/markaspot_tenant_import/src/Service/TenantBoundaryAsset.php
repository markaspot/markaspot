<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Service;

use Drupal\markaspot_nuxt\Service\BoundaryGeoJsonValidator;
use Drupal\markaspot_validation\Plugin\Validation\Geo\GeoJsonBoundary;

/**
 * Validates a local jurisdiction boundary before any provisioning write.
 *
 * The file is read from the same explicit asset directory as logos. Fetching
 * official geometry (for example BKG VG25 by municipality key) stays outside
 * Drupal, so the module never needs network access during onboarding.
 */
final class TenantBoundaryAsset {

  /**
   * Reads, normalizes and sanity-checks a GeoJSON boundary file.
   *
   * @param string $name
   *   Relative file name inside the asset directory, or '' for none.
   * @param string|null $directory
   *   The explicitly supplied asset directory.
   * @param array|null $mapCenter
   *   The tenant map center as [longitude, latitude], if configured.
   *
   * @return array|null
   *   NULL without a file, otherwise 'json' (normalized FeatureCollection as
   *   stored in field_boundary) and 'hash' (SHA-256 of that string).
   */
  public static function read(string $name, ?string $directory, ?array $mapCenter = NULL): ?array {
    if ($name === '') {
      return NULL;
    }
    $root = $directory === NULL ? FALSE : realpath($directory);
    if ($root === FALSE || !is_dir($root) || str_contains($name, "\0") || str_starts_with($name, '/') || str_contains($name, '\\')) {
      throw new \RuntimeException('A boundary requires an existing --assets-dir and a relative GeoJSON file name.');
    }
    if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['geojson', 'json'], TRUE)) {
      throw new \RuntimeException('Boundary file must have a .geojson or .json extension.');
    }
    $path = realpath($root . DIRECTORY_SEPARATOR . $name);
    if ($path === FALSE || !str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) || !is_file($path) || !is_readable($path)) {
      throw new \RuntimeException('Boundary must resolve to a readable file inside --assets-dir.');
    }
    if (!class_exists(BoundaryGeoJsonValidator::class) || !class_exists(GeoJsonBoundary::class)) {
      throw new \RuntimeException('Boundary import requires the markaspot_nuxt and markaspot_validation modules.');
    }
    $limit = BoundaryGeoJsonValidator::MAX_PAYLOAD_BYTES;
    $bytes = file_get_contents($path, FALSE, NULL, 0, $limit + 1);
    if ($bytes === FALSE || strlen($bytes) > $limit) {
      throw new \RuntimeException(sprintf('Boundary exceeds the %d MiB size limit or cannot be read.', $limit / 1024 / 1024));
    }
    $data = json_decode($bytes, TRUE);
    if (!is_array($data)) {
      throw new \RuntimeException('Boundary file is not valid JSON.');
    }
    $result = BoundaryGeoJsonValidator::validate($data);
    if (!$result['valid'] || $result['normalized'] === NULL) {
      throw new \RuntimeException('Boundary is not a usable polygon: ' . ($result['error'] ?? 'empty geometry.'));
    }
    $json = json_encode($result['normalized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $boundary = GeoJsonBoundary::fromJson($json);
    if ($boundary === NULL) {
      throw new \RuntimeException('Boundary could not be parsed by the location validator.');
    }
    // A wrong municipality key yields a valid polygon elsewhere. The map
    // center is explicit bootstrap input, so it must lie inside the boundary.
    if ($mapCenter !== NULL && !$boundary->contains((float) $mapCenter[0], (float) $mapCenter[1])) {
      throw new \RuntimeException('tenant.map_center lies outside the boundary file. Check that the boundary belongs to this municipality.');
    }
    return ['json' => $json, 'hash' => hash('sha256', $json)];
  }

}
