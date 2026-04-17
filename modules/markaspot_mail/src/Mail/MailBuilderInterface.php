<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail;

use Drupal\markaspot_mail\Enum\MailType;

/**
 * Contract for a class that turns a Drupal mail message into a MailMessage.
 *
 * Builders are discovered by the container via the "markaspot_mail.builder"
 * service tag and indexed in MailBuilderRegistry. The hook asks the registry
 * "who supports this ($module, $key)?" and, if found, invokes build().
 *
 * Builders are stateless by contract. Per-request state (e.g. an entity
 * loaded by id from $ctx->params) lives only for the duration of build();
 * if you find yourself wanting instance fields holding request data,
 * you're holding the builder wrong.
 */
interface MailBuilderInterface {

  /**
   * Classification token for logging / metrics / reverse lookup.
   */
  public function getType(): MailType;

  /**
   * Does this builder handle a given Drupal ($module, $key) pair?
   *
   * Typically a simple `$module === 'x' && $key === 'y'`, but nothing
   * prevents a builder from handling multiple keys at once (e.g. all ECA
   * escalation variants that share a single template payload).
   */
  public function supports(string $module, string $key): bool;

  /**
   * Produces a rendered MailMessage, or NULL to skip this mail entirely.
   *
   * Returning NULL lets the Drupal mail continue untouched (same behavior
   * as if no builder had been registered). Use this for graceful bail-out
   * when $ctx is missing data the builder depends on.
   *
   * Builders MUST NOT throw for recoverable conditions; log via an injected
   * logger and return NULL instead. Unrecoverable programming errors
   * (type mismatches, container misconfiguration) may propagate.
   */
  public function build(MailContext $ctx): ?MailMessage;

}
