<?php

namespace Drupal\markaspot_open311\Exception;

use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Exception;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

/**
 * A class to represent a 400
 *
 */
class GeoreportException extends Exception {

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
   * @param int|string $code
   *
   * @return $this
   */
  public function setCode($code): self
  {
    $this->code = $code;

    return $this;
  }

  /**
   * @return $this
   */
  public function setHeaders(array $headers): self
  {
    $this->headers = $headers;

    return $this;
  }
}
