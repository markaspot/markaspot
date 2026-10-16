<?php

namespace Drupal\markaspot_open311\Service;

use Drupal\file\FileInterface;

/**
 * Thrown when an imported media_url file fails the media field validators.
 */
class MediaFieldViolationException extends \RuntimeException {

  /**
   * Constructs the exception.
   *
   * @param \Drupal\file\FileInterface $mediaFile
   *   The stored file the media would have referenced.
   * @param string $message
   *   The first violation message.
   */
  public function __construct(
    public readonly FileInterface $mediaFile,
    string $message,
  ) {
    parent::__construct($message);
  }

}
