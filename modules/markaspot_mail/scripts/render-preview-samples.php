<?php

/**
 * @file
 * Renders preview HTML files in /tmp for visual review.
 *
 * Two generic previews (hardcoded content, no builder):
 *   /tmp/mail_hero_code.html             Generic OTP preview
 *   /tmp/mail_card_transactional.html    Generic card preview.
 *
 * Plus one /tmp/mail_builder_<slug>.html per markaspot_mail.builder that
 * has a sample available from MailSampleContextProvider (feedback,
 * invitation, eca_action, workspace_verification, workspace_welcome,
 * demo_expiry, otp; escalation/resubmission/moderation/org_notification
 * join the list once a service_request node or 'org' group exists in this
 * database).
 *
 * Usage:
 *   ddev drush scr profiles/contrib/markaspot/modules/markaspot_mail/scripts/render-preview-samples.php
 *
 * Sample data comes from MailSampleContextProvider -- the same class the
 * `markaspot:mail-render-test` drush command uses -- so this preview and
 * that CI-grade coverage gate never drift apart. Builders needing a real
 * node run against actual DB content loaded by storage, so preview output
 * reflects real jurisdiction resolution + token replacement.
 */

declare(strict_types=1);

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Render\Markup;
use Drupal\markaspot_mail\Enum\MailType;

$brandingService = \Drupal::service('markaspot_mail.branding');
$renderer = \Drupal::service('markaspot_mail.renderer');
$sampleContextProvider = \Drupal::service('markaspot_mail.sample_context_provider');
// Registry is public: false; access builders directly by service name for
// preview rendering. Production dispatch goes through MailAlterHook which
// has the registry injected.
$builderServices = [
  MailType::ECA_FEEDBACK->value => [
    'markaspot_mail.builder.feedback_request', 'mail_builder_feedback.html', 'Feedback',
  ],
  MailType::ECA_GROUP_INVITATION->value => [
    'markaspot_mail.builder.group_member_invitation', 'mail_builder_invitation.html', 'Invitation',
  ],
  MailType::ECA_ACTION->value => [
    'markaspot_mail.builder.eca_action_email', 'mail_builder_eca_action.html', 'ECA action',
  ],
  MailType::FASTMAP_WORKSPACE_VERIFICATION->value => [
    'markaspot_mail.builder.workspace_verification', 'mail_builder_workspace_verification.html', 'Workspace verification',
  ],
  MailType::FASTMAP_WORKSPACE_WELCOME->value => [
    'markaspot_mail.builder.workspace_welcome', 'mail_builder_workspace_welcome.html', 'Workspace welcome',
  ],
  MailType::FASTMAP_DEMO_EXPIRY->value => [
    'markaspot_mail.builder.demo_expiry_reminder', 'mail_builder_demo_expiry.html', 'Demo expiry',
  ],
  MailType::PASSWORDLESS_OTP->value => [
    'markaspot_mail.builder.passwordless_otp', 'mail_builder_otp.html', 'OTP',
  ],
  MailType::ECA_RESUBMISSION->value => [
    'markaspot_mail.builder.resubmission_request', 'mail_builder_resubmission.html', 'Resubmission',
  ],
  MailType::ECA_MODERATION->value => [
    'markaspot_mail.builder.moderation_notice', 'mail_builder_moderation.html', 'Moderation',
  ],
  MailType::ECA_ESCALATION->value => [
    'markaspot_mail.builder.escalation_notification', 'mail_builder_escalation.html', 'Escalation',
  ],
  MailType::ECA_GROUP_ORG_NOTIFICATION->value => [
    'markaspot_mail.builder.group_org_notification', 'mail_builder_org_notification.html', 'Org notification',
  ],
];

$branding = $brandingService->getBranding(NULL, 'platform', 'en');
if (!($branding['email_footer_html'] instanceof MarkupInterface)) {
  $branding['email_footer_html'] = Markup::create('');
}

// 1. Generic hero_code (OTP preview, content hardcoded).
$heroOutput = $renderer->render('hero_code', $branding, [
  'headline' => 'Verify your account',
  'code' => '156 428',
  'subtext' => 'Enter this code in the next 10 minutes. Ignore this email if you did not request a login.',
  'preheader' => 'Your one-time login code for Mark-a-Spot.',
], 'en');
file_put_contents('/tmp/mail_hero_code.html', $heroOutput['html']);
fwrite(STDOUT, "Wrote /tmp/mail_hero_code.html\n");

// 2. Generic card_transactional (content hardcoded, platform mode).
$cardOutput = $renderer->render('card_transactional', $branding, [
  'headline' => 'Your report was updated',
  'intro' => 'We moved your report to "In progress".',
  'body_blocks' => [
    'Thank you for contributing to your neighborhood. A team member is now looking at this issue and will update you when the status changes.',
  ],
  'cta_label' => 'View report',
  'cta_url' => 'https://mark-a-spot.com/amsterdam/requests/42',
  'features_block' => [
    ['Report' => '#42'],
    ['Status' => 'In progress'],
    ['Jurisdiction' => 'Amsterdam'],
  ],
  'contact_block' => [
    'Questions? support@civic-patches.com',
  ],
  'preheader' => 'Report #42 update',
], 'en');
file_put_contents('/tmp/mail_card_transactional.html', $cardOutput['html']);
fwrite(STDOUT, "Wrote /tmp/mail_card_transactional.html\n");

// 3. Every builder with a sample available (see MailSampleContextProvider).
$samples = $sampleContextProvider->getSamples();
foreach ($builderServices as $typeValue => [$serviceName, $filename, $label]) {
  $ctx = $samples[$typeValue] ?? NULL;
  if ($ctx === NULL) {
    fwrite(STDOUT, "{$label}: no sample available (missing service_request node or org group), skipping\n");
    continue;
  }

  $builder = \Drupal::service($serviceName);
  $msg = $builder->build($ctx);
  if ($msg === NULL) {
    fwrite(STDOUT, "{$label}: builder returned NULL for sample context\n");
    continue;
  }

  $b = $brandingService->getBranding($msg->jurisdictionId, $msg->mode, $ctx->langcode);
  $out = $renderer->render($msg->variant, $b, $msg->content, $ctx->langcode, $msg->plainText);
  file_put_contents("/tmp/{$filename}", $out['html']);
  fwrite(STDOUT, "{$label}: mode={$msg->mode}, jur=" . ($msg->jurisdictionId ?? 'NULL') . "\n");
  fwrite(STDOUT, "  Subject: {$msg->subject}\n");
  fwrite(STDOUT, "  Wrote /tmp/{$filename}\n");
}
