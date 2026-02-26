<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Geo;

/**
 * Checks whether a point falls within a GeoJSON boundary.
 *
 * Supports FeatureCollection, Feature, Polygon (with holes), and MultiPolygon.
 * Coordinates follow the GeoJSON convention: [lng, lat].
 */
class GeoJsonBoundary {

  /**
   * The resolved geometry array.
   *
   * @var array
   */
  private array $geometry;

  /**
   * Constructs a GeoJsonBoundary from a decoded GeoJSON array.
   *
   * @param array $geojson
   *   Decoded GeoJSON (FeatureCollection, Feature, or geometry).
   */
  public function __construct(array $geojson) {
    $this->geometry = $geojson;
  }

  /**
   * Creates an instance from a JSON string.
   *
   * @param string $json
   *   Raw GeoJSON string.
   *
   * @return static|null
   *   The boundary object, or NULL if the JSON is invalid.
   */
  public static function fromJson(string $json): ?static {
    // Strip HTML tags that may wrap the JSON (e.g. from CKEditor widget).
    $clean = strip_tags($json);
    $data = json_decode($clean, TRUE);
    if (!is_array($data) || empty($data['type'])) {
      return NULL;
    }
    return new static($data);
  }

  /**
   * Checks whether the given point is inside the boundary.
   *
   * @param float $lng
   *   Longitude.
   * @param float $lat
   *   Latitude.
   *
   * @return bool
   *   TRUE if the point is inside any polygon of the boundary.
   */
  public function contains(float $lng, float $lat): bool {
    return self::checkGeometry($lng, $lat, $this->geometry);
  }

  /**
   * Recursively resolves GeoJSON wrappers and checks containment.
   */
  private static function checkGeometry(float $lng, float $lat, array $geo): bool {
    $type = $geo['type'] ?? '';

    if ($type === 'FeatureCollection') {
      foreach ($geo['features'] ?? [] as $feature) {
        if (self::checkGeometry($lng, $lat, $feature)) {
          return TRUE;
        }
      }
      return FALSE;
    }

    if ($type === 'Feature') {
      return self::checkGeometry($lng, $lat, $geo['geometry'] ?? []);
    }

    if ($type === 'MultiPolygon') {
      foreach ($geo['coordinates'] ?? [] as $rings) {
        if (self::pointInPolygonWithHoles($lng, $lat, $rings)) {
          return TRUE;
        }
      }
      return FALSE;
    }

    if ($type === 'Polygon') {
      return self::pointInPolygonWithHoles($lng, $lat, $geo['coordinates'] ?? []);
    }

    return FALSE;
  }

  /**
   * Checks a polygon with exterior ring and optional holes.
   *
   * @param float $lng
   *   Longitude.
   * @param float $lat
   *   Latitude.
   * @param array $rings
   *   Array of rings: first = exterior, subsequent = holes.
   *
   * @return bool
   *   TRUE if inside exterior and not inside any hole.
   */
  private static function pointInPolygonWithHoles(float $lng, float $lat, array $rings): bool {
    if (empty($rings[0])) {
      return FALSE;
    }

    // Must be inside the exterior ring.
    if (!self::pointInRing($lng, $lat, $rings[0])) {
      return FALSE;
    }

    // Must not be inside any hole.
    for ($i = 1, $count = count($rings); $i < $count; $i++) {
      if (self::pointInRing($lng, $lat, $rings[$i])) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Ray-casting algorithm for point-in-polygon on a single ring.
   *
   * @param float $lng
   *   Longitude of the test point.
   * @param float $lat
   *   Latitude of the test point.
   * @param array $ring
   *   Array of [lng, lat] coordinate pairs.
   *
   * @return bool
   *   TRUE if the point is inside the ring.
   */
  private static function pointInRing(float $lng, float $lat, array $ring): bool {
    $inside = FALSE;
    $count = count($ring);

    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
      $xi = $ring[$i][0];
      $yi = $ring[$i][1];
      $xj = $ring[$j][0];
      $yj = $ring[$j][1];

      if (($yi > $lat) !== ($yj > $lat)
        && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
        $inside = !$inside;
      }
    }

    return $inside;
  }

}
