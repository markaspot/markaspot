<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Exception;

/**
 * Thrown when a raw message cannot be parsed into an InboundMessage.
 */
class MailParseException extends \RuntimeException {
}
