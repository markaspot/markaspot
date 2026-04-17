<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Psr\Log\LoggerInterface;

/**
 * Builder for markaspot_group:member_invitation mails.
 *
 * Produces the "you have been invited to join @group_name" mail sent by
 * GroupInvitationController::sendInvitation(). The existing hook_mail
 * implementation renders a plain-text body with @site_name / @group_name
 * / @claim_url token replacements; this builder rebuilds the same
 * information as structured card_transactional content so the Stage 1
 * renderer applies branding, adds a pill-shaped CTA, and attaches the
 * Zone-2 platform footer.
 *
 * Required params:
 *   - group_name (string)
 *   - claim_url  (string, absolute URL with token)
 *
 * Optional params:
 *   - site_name  (string, defaults to the resolved platform name)
 *   - group_id   (int, future use for jurisdiction-scoped branding —
 *                  currently ignored because the invitation is a
 *                  platform-level flow that may target any group type)
 *
 * Stays in platform mode for now: the invitation may be to a jur OR an
 * org group, and the Zone-1 Jurisdiction footer is not the right fit for
 * an invite-to-join. Follow-up can switch to jurisdiction mode per
 * group_id once the controller passes the entity along.
 */
final class GroupMemberInvitationBuilder implements MailBuilderInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::ECA_GROUP;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_group' && $key === 'member_invitation';
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $groupName = trim((string) ($ctx->params['group_name'] ?? ''));
    $claimUrl = trim((string) ($ctx->params['claim_url'] ?? ''));

    if ($groupName === '' || $claimUrl === '') {
      $this->logger->warning('member_invitation: missing group_name or claim_url, skipping branded render.');
      return NULL;
    }

    $siteName = trim((string) ($ctx->params['site_name'] ?? ''));
    $langcode = $ctx->langcode;

    $subject = (string) $this->t('You have been invited to "@group"', [
      '@group' => $groupName,
    ], ['langcode' => $langcode]);

    $intro = $siteName !== ''
      ? (string) $this->t('You have been invited to join "@group" on @site.', [
        '@group' => $groupName,
        '@site' => $siteName,
      ], ['langcode' => $langcode])
      : (string) $this->t('You have been invited to join "@group".', [
        '@group' => $groupName,
      ], ['langcode' => $langcode]);

    $content = [
      'preheader' => (string) $this->t('Accept your invitation to @group', [
        '@group' => $groupName,
      ], ['langcode' => $langcode]),
      'headline' => (string) $this->t('Join @group', ['@group' => $groupName], ['langcode' => $langcode]),
      'intro' => $intro,
      'body_blocks' => [
        (string) $this->t('Click the button below to accept this invitation. The link expires in 7 days.', [], ['langcode' => $langcode]),
        (string) $this->t('If you did not expect this invitation, you can safely ignore this email.', [], ['langcode' => $langcode]),
      ],
      'cta_label' => (string) $this->t('Accept invitation', [], ['langcode' => $langcode]),
      'cta_url' => $claimUrl,
    ];

    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: $content,
      mode: 'platform',
    );
  }

}
