<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;

/**
 * Shared fidelity / degradation checks for the email intake.
 *
 * The rebuild design promises discoverability of the active fidelity level
 * ("intake on but no IMAP", "no private filesystem", "outbound unavailable").
 * hook_requirements() and the dashboard API (InboundMailApiController's list
 * "fidelity" block) need the same answers, so the checks live here once.
 */
class MailIntakeFidelity {

  /**
   * Constructs the fidelity service.
   *
   * @param \Drupal\markaspot_mail_inbound\Service\MailboxResolver $mailboxResolver
   *   The mailbox resolver.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   Stream wrapper manager.
   * @param object|null $suggestionService
   *   Optional markaspot_mail_inbound.category_suggestion service (@?).
   */
  public function __construct(
    protected MailboxResolver $mailboxResolver,
    protected ConfigFactoryInterface $configFactory,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected ?object $suggestionService = NULL,
  ) {
  }

  /**
   * Whether at least one enabled mailbox has usable IMAP credentials.
   */
  public function imapConfigured(): bool {
    foreach ($this->mailboxResolver->getMailboxes() as $mailbox) {
      if (!empty($mailbox['imap']['host']) && !empty($mailbox['imap']['username'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the outbound return channel can send mail at all.
   *
   * Pragmatic check: Drupal has a default mail plugin configured. When this
   * is FALSE the reply endpoints degrade to record-only ("in-system notes
   * only, no send" in the degradation matrix).
   */
  public function outboundAvailable(): bool {
    $interface = $this->configFactory->get('system.mail')->get('interface');
    return is_array($interface) && !empty($interface['default']);
  }

  /**
   * Whether the private filesystem is configured for staged attachments.
   */
  public function privateFilesystemConfigured(): bool {
    return $this->streamWrapperManager->isValidScheme('private');
  }

  /**
   * Whether AI categorization is active (module setting + service available).
   *
   * "Active" means the module setting ai_suggestions_enabled is TRUE and at
   * least one AI service (vision or text) is wired. The feature flag and token
   * budget are runtime / per-mail gates checked by the worker, not here.
   *
   * @return bool
   *   TRUE when AI suggestions could run on this install.
   */
  public function aiAvailable(): bool {
    if ($this->suggestionService === NULL) {
      return FALSE;
    }
    if (!method_exists($this->suggestionService, 'couldRun')) {
      return FALSE;
    }
    return (bool) $this->suggestionService->couldRun();
  }

  /**
   * Returns the fidelity summary the dashboard API embeds in list responses.
   *
   * @return array{imap_configured: bool, outbound_available: bool, private_fs: bool, ai_available: bool}
   *   The summary, keys are part of the frontend contract (#482).
   */
  public function summary(): array {
    return [
      'imap_configured' => $this->imapConfigured(),
      'outbound_available' => $this->outboundAvailable(),
      'private_fs' => $this->privateFilesystemConfigured(),
      'ai_available' => $this->aiAvailable(),
    ];
  }

}
