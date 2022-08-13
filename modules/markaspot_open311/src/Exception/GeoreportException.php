<?php

namespace Drupal\markaspot_open311\Exception;

use Drupal\Core\Entity\EntityConstraintViolationListInterface;

/**
 * A class to represent a 400.
 *
 * @property array $headers
 */
class GeoreportException extends \Exception {

  /**
   * The constraint violations associated with this exception.
   *
   * @var \Drupal\Core\Entity\EntityConstraintViolationListInterface
   */
  protected $violations;

  /**
   * Gets the constraint violations associated with this exception.
   *
   * @return \Drupal\Core\Entity\EntityConstraintViolationListInterface
   *   The constraint violations.
   */
  public function getViolations() {
    return $this->violations;
  }

  /**
   * Sets the constraint violations associated with this exception.
   *
   * @param \Drupal\Core\Entity\EntityConstraintViolationListInterface $violations
   *   The constraint violations.
   */
  public function setViolations(EntityConstraintViolationListInterface $violations) {
    $this->violations = $violations;
  }

  /**
   * Set the exceptopn code.
   *
   * @param int|string $code
   *   The code.
   *
   * @return $this
   */
  public function setCode($code): self {
    $this->code = $code;

    return $this;
  }

  /**
   * Get http Headers.
   */
  public function getHeaders() {
    return $this->headers;
  }

  /**
   * Set http headers.
   *
   * @return $this
   */
  public function setHeaders(array $headers): self {
    $this->headers = $headers;

    return $this;
  }

}
