<?php

declare(strict_types=1);

namespace Drupal\markaspot_passwordless\Service;

use Drupal\user\UserInterface;

/**
 * Defines the narrowly scoped Core-maintenance recovery OTP contract.
 */
interface BreakGlassOtpServiceInterface {

  /**
   * Requests a generic recovery-code response for a syntactically valid email.
   *
   * @return array{success: bool, message: string, expiresIn: int}
   *   A generic result that must not disclose account eligibility.
   */
  public function requestCode(string $email, string $langcode = ''): array;

  /**
   * Consumes a recovery code and returns the rechecked eligible account.
   */
  public function verifyCode(string $email, string $code): ?UserInterface;

}
