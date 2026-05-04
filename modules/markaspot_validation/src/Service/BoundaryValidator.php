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
   * Loads the GeoJSON boundary from a jurisdiction group entity.
   */
  protected function loadJurisdictionBoundary(int $jurisdictionId): ?GeoJsonBoundary {
    $group = $this->entityTypeManager
      ->getStorage('group')
      ->load($jurisdictionId);

    if (!$group) {
      $this->logMissingBoundary($jurisdictionId, 'missing_jurisdiction');
      return NULL;
    }

    if (!$group->hasField('field_boundary') || $group->get('field_boundary')->isEmpty()) {
      $this->logMissingBoundary($jurisdictionId, 'missing_boundary');
      return NULL;
    }

    $boundary = GeoJsonBoundary::fromJson((string) $group->get('field_boundary')->getString());
    if (!$boundary) {
      $this->logMissingBoundary($jurisdictionId, 'invalid_boundary');
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
