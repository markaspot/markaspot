<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

/**
 * Generates deterministic placeholder signets.
 */
interface PlaceholderSignetGeneratorInterface {

  /**
   * Generates an SVG signet for a seed and primary color.
   */
  public function generate(string $seed, string $primaryHex): string;

}
