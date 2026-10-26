<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Service;

/**
 * Staff choices for one promotion (dashboard confirmation dialog).
 *
 * The defaults reproduce the behavior before the dialog existed, so the
 * admin promote form and the auto-promote worker are unaffected:
 * - the AI summary becomes the description when one is stored,
 * - the suggested address is not used (the worker passes it as a hint),
 * - the location is geocoded server side (hint, then the regex scan),
 * - the promotion counts as automatic (missing-location auto-reply is sent).
 *
 * Location modes:
 * - auto: server-side geocoding as described above, boundary-checked.
 * - confirmed: staff confirmed $lat/$lng; the server never geocodes.
 * - none: staff saw the geocoding result and wants no coordinates.
 */
final class PromoteOptions {

  /**
   * Server-side geocoding (hint, then regex scan).
   */
  public const LOCATION_AUTO = 'auto';

  /**
   * Staff-confirmed coordinates.
   */
  public const LOCATION_CONFIRMED = 'confirmed';

  /**
   * No coordinates and no server-side geocoding.
   */
  public const LOCATION_NONE = 'none';

  /**
   * Constructs PromoteOptions.
   *
   * @param bool $useSuggestedDescription
   *   Use the stored AI summary as the description body when present.
   *   FALSE uses the original citizen message even when a summary exists.
   * @param bool $useSuggestedAddress
   *   Use the stored AI address: as the geocoding hint in auto mode, and as
   *   field_address text when the report ends up without coordinates.
   * @param string $locationMode
   *   One of the LOCATION_* constants.
   * @param float|null $lat
   *   Latitude, required in confirmed mode.
   * @param float|null $lng
   *   Longitude, required in confirmed mode.
   * @param bool $staffInitiated
   *   TRUE when staff promoted by hand (dashboard API, admin form). Staff
   *   completes the location in moderation, so the citizen gets no
   *   missing-location auto-reply; the auto-promote worker leaves it FALSE.
   *
   * @throws \InvalidArgumentException
   *   On an unknown mode, or confirmed mode without valid coordinates.
   */
  public function __construct(
    public readonly bool $useSuggestedDescription = TRUE,
    public readonly bool $useSuggestedAddress = FALSE,
    public readonly string $locationMode = self::LOCATION_AUTO,
    public readonly ?float $lat = NULL,
    public readonly ?float $lng = NULL,
    public readonly bool $staffInitiated = FALSE,
  ) {
    if (!in_array($locationMode, [self::LOCATION_AUTO, self::LOCATION_CONFIRMED, self::LOCATION_NONE], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown location mode "%s".', $locationMode));
    }
    if ($locationMode === self::LOCATION_CONFIRMED && !self::isValidCoordinate($lat, $lng)) {
      throw new \InvalidArgumentException('Confirmed location mode needs finite, in-range coordinates.');
    }
  }

  /**
   * Whether a latitude/longitude pair is finite and in range.
   */
  public static function isValidCoordinate(?float $lat, ?float $lng): bool {
    return $lat !== NULL && $lng !== NULL
      && is_finite($lat) && is_finite($lng)
      && $lat >= -90.0 && $lat <= 90.0
      && $lng >= -180.0 && $lng <= 180.0;
  }

}
