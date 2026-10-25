<?php

namespace Drupal\markaspot_validation\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_validation\Plugin\Validation\Geo\GeoJsonBoundary;
use Psr\Log\LoggerInterface;

/**
 * Checks coordinates against a jurisdiction's GeoJSON boundary.
 */
class BoundaryValidator {

  /**
   * Constructs a BoundaryValidator object.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Checks whether a point is inside a jurisdiction boundary.
   *
   * Missing or invalid boundaries are accepted and logged so onboarding
   * tenants are not blocked by incomplete geometry.
   */
  public function isWithinJurisdictionBoundary(int $jurisdictionId, float $lat, float $lng): bool {
    $boundary = $this->loadJurisdictionBoundary($jurisdictionId);
    if (!$boundary) {
      return TRUE;
    }

    return $boundary->contains($lng, $lat);
  }

  /**
   * Checks a point against the first usable boundary of ordered jurisdictions.
   *
   * Callers pass the jurisdictions from most to least specific (typically a
   * child, then its root). The first jurisdiction with usable geometry
   * decides, mirroring ValidLatLonConstraintValidator: a child without its
   * own boundary falls back to its root instead of accepting everything.
   * When no jurisdiction has usable geometry the point is accepted and the
   * condition is logged once for the most specific jurisdiction.
   *
   * @param int[] $jurisdictionIds
   *   Jurisdiction group ids, most specific first.
   * @param float $lat
   *   Latitude.
   * @param float $lng
   *   Longitude.
   *
   * @return bool
   *   TRUE when inside the deciding boundary or when none is usable.
   */
  public function isWithinFirstUsableBoundary(array $jurisdictionIds, float $lat, float $lng): bool {
    $firstFailure = NULL;
    foreach (array_values(array_unique(array_map('intval', $jurisdictionIds))) as $jurisdictionId) {
      if ($jurisdictionId <= 0) {
        continue;
      }
      $reason = NULL;
      $boundary = $this->resolveJurisdictionBoundary($jurisdictionId, $reason);
      if ($boundary) {
        return $boundary->contains($lng, $lat);
      }
      $firstFailure ??= [$jurisdictionId, (string) $reason];
    }

    if ($firstFailure !== NULL) {
      $this->logMissingBoundary($firstFailure[0], $firstFailure[1]);
    }
    return TRUE;
  }

  /**
   * Loads the GeoJSON boundary from a jurisdiction group entity.
   */
  protected function loadJurisdictionBoundary(int $jurisdictionId): ?GeoJsonBoundary {
    $reason = NULL;
    $boundary = $this->resolveJurisdictionBoundary($jurisdictionId, $reason);
    if (!$boundary && $reason !== NULL) {
      $this->logMissingBoundary($jurisdictionId, $reason);
    }
    return $boundary;
  }

  /**
   * Resolves the GeoJSON boundary of a jurisdiction without logging.
   *
   * @param int $jurisdictionId
   *   The jurisdiction group id.
   * @param string|null $reason
   *   Set to the reason when no usable boundary exists.
   */
  protected function resolveJurisdictionBoundary(int $jurisdictionId, ?string &$reason): ?GeoJsonBoundary {
    $group = $this->entityTypeManager
      ->getStorage('group')
      ->load($jurisdictionId);

    if (!$group) {
      $reason = 'missing_jurisdiction';
      return NULL;
    }

    if (!$group->hasField('field_boundary') || $group->get('field_boundary')->isEmpty()) {
      $reason = 'missing_boundary';
      return NULL;
    }

    $boundary = GeoJsonBoundary::fromJson((string) $group->get('field_boundary')->getString());
    if (!$boundary) {
      $reason = 'invalid_boundary';
    }

    return $boundary;
  }

  /**
   * Logs an accepted missing-boundary condition.
   */
  protected function logMissingBoundary(int $jurisdictionId, string $reason): void {
    $this->logger->warning(
      'submission.boundary_missing jurisdiction=@jurisdiction result=accepted reason=@reason',
      [
        '@jurisdiction' => $jurisdictionId,
        '@reason' => $reason,
      ]
    );
  }

}
