<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service\Exception;

/**
 * Thrown when a DELETE targets a standard (shipped) mail text key.
 *
 * Standard keys can never be removed: their slots may be emptied, but the
 * key itself must keep existing so mail_coverage's HealthCheck and the
 * markaspot_mail_send_notification ECA action's key list stay meaningful.
 */
final class MailTextsForbiddenException extends \RuntimeException {}
