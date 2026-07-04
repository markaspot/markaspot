<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Service;

/**
 * Validates and normalizes tenant boundary GeoJSON.
 *
 * Accepts a FeatureCollection, a single Feature, or a bare Polygon/
 * MultiPolygon geometry and normalizes it into a FeatureCollection of
 * Features with Polygon/MultiPolygon geometry only. Open rings are closed
 * automatically; everything else that violates the shape (wrong geometry
 * type, out-of-range coordinates, too few ring positions, oversized
 * payloads) is rejected with a concrete error message.
 *
 * This is a plain value class with no Drupal dependencies so it can be unit
 * tested in isolation, mirroring
 * \Drupal\markaspot_validation\Plugin\Validation\Geo\GeoJsonBoundary.
 */
final class BoundaryGeoJsonValidator {

  /**
   * Maximum number of features accepted in a single boundary.
   */
  const MAX_FEATURES = 25;

  /**
   * Maximum number of coordinate positions accepted across all features.
   */
  const MAX_VERTICES = 100000;

  /**
   * Maximum accepted request payload size in bytes, checked before decoding.
   */
  const MAX_PAYLOAD_BYTES = 2 * 1024 * 1024;

  /**
   * Maximum serialized size of a single feature's properties, in bytes.
   */
  const MAX_PROPERTIES_BYTES = 4096;

  /**
   * Validates and normalizes a decoded boundary value.
   *
   * @param mixed $data
   *   The decoded 'boundary' value from the request body: a
   *   FeatureCollection, Feature, Polygon, MultiPolygon, or NULL to clear the
   *   stored boundary.
   *
   * @return array{valid: bool, error: string|null, normalized: array|null}
   *   'valid' is TRUE when the input is acceptable. 'error' holds a concrete,
   *   English validation message when invalid. 'normalized' holds the
   *   resulting FeatureCollection, only meaningful when 'valid' is TRUE.
   *   'normalized' is NULL both when clearing (input was NULL) and when the
   *   input normalizes to zero features, so an empty boundary never turns
   *   into a boundary that rejects every point.
   */
  public static function validate(mixed $data): array {
    if ($data === NULL) {
      return ['valid' => TRUE, 'error' => NULL, 'normalized' => NULL];
    }

    if (!is_array($data) || !isset($data['type']) || !is_string($data['type'])) {
      return self::invalid('boundary must be a GeoJSON object with a type.');
    }

    switch ($data['type']) {
      case 'FeatureCollection':
        if (!isset($data['features']) || !is_array($data['features'])) {
          return self::invalid('FeatureCollection must have a features array.');
        }
        $features = $data['features'];
        break;

      case 'Feature':
        $features = [$data];
        break;

      case 'Polygon':
      case 'MultiPolygon':
        $features = [[
          'type' => 'Feature',
          'properties' => [],
          'geometry' => $data,
        ]];
        break;

      default:
        return self::invalid(sprintf(
          "Unsupported GeoJSON type '%s'. Expected FeatureCollection, Feature, Polygon, or MultiPolygon.",
          $data['type']
        ));
    }

    if (count($features) > self::MAX_FEATURES) {
      return self::invalid(sprintf(
        'boundary may contain at most %d features (found %d).',
        self::MAX_FEATURES,
        count($features)
      ));
    }

    $normalizedFeatures = [];
    $vertexCount = 0;

    foreach ($features as $index => $feature) {
      if (!is_array($feature) || ($feature['type'] ?? NULL) !== 'Feature') {
        return self::invalid("Feature at index $index must be a GeoJSON Feature.");
      }

      $geometry = $feature['geometry'] ?? NULL;
      if (!is_array($geometry) || !isset($geometry['type'])) {
        return self::invalid("Feature at index $index is missing a valid geometry.");
      }

      $geomType = $geometry['type'];
      if (!in_array($geomType, ['Polygon', 'MultiPolygon'], TRUE)) {
        return self::invalid(sprintf(
          "Feature at index %d has unsupported geometry type '%s'. Only Polygon and MultiPolygon are allowed.",
          $index,
          $geomType
        ));
      }

      $geometryResult = self::validateGeometry($geomType, $geometry['coordinates'] ?? NULL, $index);
      if ($geometryResult['error'] !== NULL) {
        return self::invalid($geometryResult['error']);
      }

      $vertexCount += $geometryResult['vertexCount'];
      if ($vertexCount > self::MAX_VERTICES) {
        return self::invalid(sprintf('boundary may contain at most %d vertices in total.', self::MAX_VERTICES));
      }

      $properties = is_array($feature['properties'] ?? NULL) ? $feature['properties'] : [];
      $propertiesSize = strlen(json_encode($properties) ?: '');
      if ($propertiesSize > self::MAX_PROPERTIES_BYTES) {
        return self::invalid(sprintf(
          'Feature at index %d has properties larger than %d bytes (found %d).',
          $index,
          self::MAX_PROPERTIES_BYTES,
          $propertiesSize
        ));
      }

      $normalizedFeatures[] = [
        'type' => 'Feature',
        'properties' => $properties,
        'geometry' => [
          'type' => $geomType,
          'coordinates' => $geometryResult['coordinates'],
        ],
      ];
    }

    // An empty FeatureCollection (e.g. all features were removed on the
    // frontend canvas) is treated the same as clearing the boundary: a
    // GeoJsonBoundary built from zero features would make ::contains()
    // always return FALSE, which would reject every citizen report in the
    // jurisdiction. "boundary never blocks" wins over persisting the empty
    // shape.
    if (empty($normalizedFeatures)) {
      return ['valid' => TRUE, 'error' => NULL, 'normalized' => NULL];
    }

    return [
      'valid' => TRUE,
      'error' => NULL,
      'normalized' => [
        'type' => 'FeatureCollection',
        'features' => $normalizedFeatures,
      ],
    ];
  }

  /**
   * Builds the invalid-result shape for a given error message.
   */
  private static function invalid(string $message): array {
    return ['valid' => FALSE, 'error' => $message, 'normalized' => NULL];
  }

  /**
   * Builds the geometry-error shape for a given error message.
   */
  private static function geometryError(string $message): array {
    return ['error' => $message, 'coordinates' => NULL, 'vertexCount' => 0];
  }

  /**
   * Validates and normalizes a Polygon or MultiPolygon coordinate tree.
   *
   * @return array
   *   Keyed array with 'error' (string|null), 'coordinates' (array|null),
   *   and 'vertexCount' (int).
   */
  private static function validateGeometry(string $type, mixed $coordinates, int $featureIndex): array {
    if (!is_array($coordinates) || empty($coordinates)) {
      return self::geometryError("Feature at index $featureIndex has empty coordinates.");
    }

    if ($type === 'Polygon') {
      return self::validatePolygonRings($coordinates, $featureIndex);
    }

    // MultiPolygon: an array of Polygon ring-sets.
    $normalizedPolygons = [];
    $vertexCount = 0;
    foreach ($coordinates as $polygonIndex => $rings) {
      if (!is_array($rings)) {
        return self::geometryError("Feature at index $featureIndex has an invalid polygon at position $polygonIndex.");
      }
      $result = self::validatePolygonRings($rings, $featureIndex);
      if ($result['error'] !== NULL) {
        return $result;
      }
      $normalizedPolygons[] = $result['coordinates'];
      $vertexCount += $result['vertexCount'];
    }

    return ['error' => NULL, 'coordinates' => $normalizedPolygons, 'vertexCount' => $vertexCount];
  }

  /**
   * Validates and auto-closes the rings of a single Polygon.
   *
   * @return array
   *   Keyed array with 'error' (string|null), 'coordinates' (array|null),
   *   and 'vertexCount' (int).
   */
  private static function validatePolygonRings(array $rings, int $featureIndex): array {
    if (empty($rings)) {
      return self::geometryError("Feature at index $featureIndex must have at least an exterior ring.");
    }

    $normalizedRings = [];
    $vertexCount = 0;

    foreach ($rings as $ringIndex => $ring) {
      if (!is_array($ring) || empty($ring)) {
        return self::geometryError("Feature at index $featureIndex, ring $ringIndex must be a non-empty array of positions.");
      }

      $normalizedPositions = [];
      foreach ($ring as $positionIndex => $position) {
        $validated = self::validatePosition($position);
        if ($validated === NULL) {
          return self::geometryError("Feature at index $featureIndex, ring $ringIndex has an invalid coordinate at position $positionIndex.");
        }
        $normalizedPositions[] = $validated;
      }

      // Auto-close an open ring by appending its first position.
      $first = $normalizedPositions[0];
      $last = $normalizedPositions[count($normalizedPositions) - 1];
      if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
        $normalizedPositions[] = $first;
      }

      if (count($normalizedPositions) < 4) {
        return self::geometryError("Feature at index $featureIndex, ring $ringIndex must have at least 4 positions once closed.");
      }

      $normalizedRings[] = $normalizedPositions;
      $vertexCount += count($normalizedPositions);
    }

    return ['error' => NULL, 'coordinates' => $normalizedRings, 'vertexCount' => $vertexCount];
  }

  /**
   * Validates a single [lng, lat] coordinate position.
   *
   * @return array{0: float, 1: float}|null
   *   The normalized [lng, lat] pair, or NULL if invalid.
   */
  private static function validatePosition(mixed $position): ?array {
    if (!is_array($position) || count($position) < 2) {
      return NULL;
    }

    [$lng, $lat] = array_values($position);
    if (!is_numeric($lng) || !is_numeric($lat)) {
      return NULL;
    }

    $lng = (float) $lng;
    $lat = (float) $lat;
    if ($lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) {
      return NULL;
    }

    return [$lng, $lat];
  }

}
