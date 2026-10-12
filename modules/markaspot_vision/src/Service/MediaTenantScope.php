<?php

namespace Drupal\markaspot_vision\Service;

use Drupal\group\Entity\GroupInterface;

/**
 * The tenant scope of a media analysis request, or why it was refused.
 */
final class MediaTenantScope {

  /**
   * Constructs the scope result.
   */
  private function __construct(
    public readonly ?GroupInterface $jurisdiction,
    public readonly ?string $error,
    public readonly int $status,
  ) {
  }

  /**
   * A scope bound to a trusted jurisdiction.
   */
  public static function resolved(GroupInterface $jurisdiction): self {
    return new self($jurisdiction, NULL, 200);
  }

  /**
   * An installation without any jurisdiction: nothing to bind.
   */
  public static function unscoped(): self {
    return new self(NULL, NULL, 200);
  }

  /**
   * A refused scope.
   */
  public static function refuse(string $error, int $status): self {
    return new self(NULL, $error, $status);
  }

  /**
   * Whether the request may proceed.
   */
  public function isAllowed(): bool {
    return $this->error === NULL;
  }

}
