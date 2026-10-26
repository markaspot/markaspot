<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Exception;

/**
 * Thrown when a staff-confirmed location lies outside the mail's jurisdiction.
 *
 * Only the confirmed-location promotion path throws it; geocoded points
 * outside the boundary are dropped silently instead. The message never
 * carries the coordinates.
 */
class LocationOutsideJurisdictionException extends \RuntimeException {
}
