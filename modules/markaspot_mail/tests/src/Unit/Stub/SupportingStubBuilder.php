<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Stub;

use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;

/**
 * Minimal MailBuilderInterface double used by the registry tests.
 *
 * Claims support for a fixed ($module, $key) pair given at construction
 * and returns a boilerplate MailMessage from build(). Registry tests
 * assert lookup + ordering behavior, not build() semantics, so the
 * returned MailMessage content is intentionally sparse.
 */
final class SupportingStubBuilder implements MailBuilderInterface {

  public function __construct(
    private readonly MailType $type,
    private readonly string $module,
    private readonly string $key,
  ) {}

  /**
   *
   */
  public function getType(): MailType {
    return $this->type;
  }

  /**
   *
   */
  public function supports(string $module, string $key): bool {
    return $module === $this->module && $key === $this->key;
  }

  /**
   *
   */
  public function build(MailContext $ctx): ?MailMessage {
    return new MailMessage(
      subject: 'Stub subject',
      variant: 'card_transactional',
      content: [],
    );
  }

}
