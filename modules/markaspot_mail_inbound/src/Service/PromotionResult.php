<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Service;

use Drupal\node\NodeInterface;

/**
 * Outcome of one promotion: the node plus where its content came from.
 *
 * The dashboard API returns the sources so the frontend can tell staff what
 * the new report carries (confirmed pin, geocoded hint, regex match, nothing).
 */
final class PromotionResult {

  /**
   * The location was confirmed by staff (coordinates in the request).
   */
  public const LOCATION_CONFIRMED = 'confirmed';

  /**
   * The location was geocoded from the AI-suggested address.
   */
  public const LOCATION_SUGGESTED = 'suggested';

  /**
   * The location was geocoded from a street found by the regex scan.
   */
  public const LOCATION_REGEX = 'regex';

  /**
   * The description body is the AI summary.
   */
  public const DESCRIPTION_SUGGESTED = 'suggested';

  /**
   * The description body is the original citizen message.
   */
  public const DESCRIPTION_ORIGINAL = 'original';

  /**
   * Constructs a PromotionResult.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The created, saved service request node.
   * @param string|null $locationSource
   *   One of the LOCATION_* constants, or NULL when the report is
   *   ungeolocated.
   * @param string $descriptionSource
   *   One of the DESCRIPTION_* constants.
   * @param bool $addressTextApplied
   *   TRUE when the suggested address text was written to field_address of
   *   a report without coordinates.
   */
  public function __construct(
    public readonly NodeInterface $node,
    public readonly ?string $locationSource,
    public readonly string $descriptionSource,
    public readonly bool $addressTextApplied,
  ) {
  }

}
