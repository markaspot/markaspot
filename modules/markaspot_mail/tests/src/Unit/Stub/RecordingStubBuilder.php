<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Stub;

use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;

/**
 * MailBuilderInterface double that records whether build() ran.
 *
 * Used by MailAlterHookTest to assert dispatch behavior: blocklist skips
 * must prevent the builder from running at all, so wasCalled must remain
 * FALSE for blocked modules even when the builder would have claimed
 * support for them.
 */
final class RecordingStubBuilder implements MailBuilderInterface {

  public bool $wasCalled = FALSE;

  public function __construct(
    private readonly MailMessage $returnValue,
  ) {}

  /**
   *
   */
  public function getType(): MailType {
    return MailType::ECA_ESCALATION;
  }

  /**
   *
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_escalation';
  }

  /**
   *
   */
  public function build(MailContext $ctx): ?MailMessage {
    $this->wasCalled = TRUE;
    return $this->returnValue;
  }

}
