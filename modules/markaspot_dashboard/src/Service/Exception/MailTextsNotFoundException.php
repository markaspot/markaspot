<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service\Exception;

/**
 * Thrown when a mail-text key does not exist in markaspot_mail.texts.
 */
final class MailTextsNotFoundException extends \RuntimeException {}
