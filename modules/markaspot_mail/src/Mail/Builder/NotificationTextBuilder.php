<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Mail\ResolveJurisdictionFromNodeTrait;
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builder for markaspot_mail:notification_* mails.
 *
 * Renders the admin-editable markaspot_mail.texts templates (subject,
 * headline, intro, body_blocks, cta_label, preheader) resolved via
 * MailTextResolver, with Drupal token replacement against the acted-upon
 * service_request node. Fired by the markaspot_mail_send_notification ECA
 * action plugin, which sets $message['params']['notification_key'] to the
 * markaspot_mail.texts top-level key to render (e.g. report_confirmation,
 * status_open, status_closed, status_not_responsible).
 *
 * Required params:
 *   - node: NodeInterface (service_request)
 *   - notification_key: string, a top-level key in markaspot_mail.texts
 *
 * The CTA always points at the node's own canonical URL — the same
 * "[site:url][node:url:path]" self-link the legacy ECA texts hardcoded —
 * computed here rather than sourced from config, since it never varies
 * per notification key. The jurisdiction footer (field_email_footer) and
 * Reply-To (field_jurisdiction_e_mail) are NOT duplicated into body_blocks:
 * MailBrandingService already renders both automatically for jurisdiction-
 * mode mails (Zone-1 footer + Reply-To header), so the config-driven body
 * omits them entirely to avoid double-printing.
 *
 * Mode: jurisdiction when the node's field_jurisdiction resolves to a jur
 * group; platform otherwise.
 */
final class NotificationTextBuilder implements MailBuilderInterface {

  use ResolveJurisdictionFromNodeTrait;

  private const CONFIG_NAME = 'markaspot_mail.texts';

  private const KEY_PREFIX = 'notification_';

  public function __construct(
    private readonly MailTextResolver $textResolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::NOTIFICATION_CONFIG;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_mail' && str_starts_with($key, self::KEY_PREFIX);
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $node = $ctx->params['node'] ?? NULL;
    if (!$node instanceof NodeInterface) {
      $this->logger->warning('@key: missing or invalid "node" param, skipping branded render.', ['@key' => $ctx->key]);
      return NULL;
    }

    $notificationKey = $this->resolveNotificationKey($ctx);
    if ($notificationKey === '') {
      $this->logger->warning('@key: could not determine notification_key, skipping branded render.', ['@key' => $ctx->key]);
      return NULL;
    }

    $slots = $this->textResolver->resolve(self::CONFIG_NAME, $notificationKey, $ctx->langcode);
    $slots = $this->textResolver->replaceTokens($slots, ['node' => $node], $ctx->langcode);

    if ($slots['subject'] === '') {
      $this->logger->warning('notification "@notification_key": empty subject after resolve, skipping branded render.', ['@notification_key' => $notificationKey]);
      return NULL;
    }

    [$mode, $jurisdictionId] = $this->resolveJurisdictionFromNode($node);

    $content = [
      'preheader' => $slots['preheader'],
      'headline' => $slots['headline'],
      'intro' => $slots['intro'],
      'body_blocks' => $slots['body_blocks'],
    ];
    if ($slots['cta_label'] !== '') {
      $content['cta_label'] = $slots['cta_label'];
      $content['cta_url'] = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    }

    return new MailMessage(
      subject: $slots['subject'],
      variant: 'card_transactional',
      content: $content,
      mode: $mode,
      jurisdictionId: $jurisdictionId,
    );
  }

  /**
   * Resolves the markaspot_mail.texts key for this mail.
   *
   * Prefers the explicit $params['notification_key'] the ECA action plugin
   * sets; falls back to stripping the "notification_" prefix off the
   * Drupal mail key for callers that only set the key.
   */
  private function resolveNotificationKey(MailContext $ctx): string {
    $fromParams = trim((string) ($ctx->params['notification_key'] ?? ''));
    if ($fromParams !== '') {
      return $fromParams;
    }
    return substr($ctx->key, strlen(self::KEY_PREFIX));
  }

}
