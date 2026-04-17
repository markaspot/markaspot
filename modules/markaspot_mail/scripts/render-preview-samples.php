<?php

/**
 * @file
 * Renders preview HTML files in /tmp for visual review.
 *
 * Four previews:
 *   /tmp/mail_hero_code.html             Generic OTP preview (no builder)
 *   /tmp/mail_card_transactional.html    Generic card preview (no builder)
 *   /tmp/mail_builder_feedback.html      Real FeedbackRequestBuilder output
 *   /tmp/mail_builder_invitation.html    Real GroupMemberInvitationBuilder output.
 *
 * Usage:
 *   ddev drush scr profiles/contrib/markaspot/modules/markaspot_mail/scripts/render-preview-samples.php
 *
 * Runs against a real service_request node loaded by storage, so preview
 * output reflects actual jurisdiction resolution + token replacement.
 */

declare(strict_types=1);

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Render\Markup;
use Drupal\markaspot_mail\Mail\MailContext;

$brandingService = \Drupal::service('markaspot_mail.branding');
$renderer = \Drupal::service('markaspot_mail.renderer');
// Registry is public: false; access builders directly by service name for
// preview rendering. Production dispatch goes through MailAlterHook which
// has the registry injected.
$feedbackBuilder = \Drupal::service('markaspot_mail.builder.feedback_request');
$inviteBuilder = \Drupal::service('markaspot_mail.builder.group_member_invitation');
$etm = \Drupal::entityTypeManager();

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

// 3. FeedbackRequestBuilder end-to-end: load a service_request node, run
//    the full dispatcher path (build, brand, render). Exercises the token
//    replacement for the [node:request_id] placeholder in the config
//    subject template.
$builderFeedbackHtml = '';
$nodes = $etm->getStorage('node')
  ->loadByProperties(['type' => 'service_request']);
if (!empty($nodes)) {
  $node = reset($nodes);
  $ctx = new MailContext(
    module: 'markaspot_feedback',
    key: 'feedback_request',
    langcode: 'en',
    params: ['node' => $node],
    to: 'citizen@example.com',
  );
  $msg = $feedbackBuilder->build($ctx);
  if ($msg !== NULL) {
    $b = $brandingService->getBranding($msg->jurisdictionId, $msg->mode, $ctx->langcode);
    $out = $renderer->render($msg->variant, $b, $msg->content, $ctx->langcode, $msg->plainText);
    $builderFeedbackHtml = $out['html'];
    fwrite(STDOUT, "Feedback: node #{$node->id()} \"{$node->label()}\", mode={$msg->mode}, jur=" . ($msg->jurisdictionId ?? 'NULL') . "\n");
    fwrite(STDOUT, "  Subject: {$msg->subject}\n");
  }
  else {
    fwrite(STDOUT, "Feedback: builder returned NULL for node #{$node->id()}\n");
  }
}
else {
  fwrite(STDOUT, "Feedback: no service_request node found, skipping\n");
}

// 4. GroupMemberInvitationBuilder -- platform mode, synthetic params.
$builderInviteHtml = '';
$ctx = new MailContext(
  module: 'markaspot_group',
  key: 'member_invitation',
  langcode: 'en',
  params: [
    'group_name' => 'Stadt Amsterdam',
    'site_name' => 'CivicSpot',
    'claim_url' => 'https://amsterdam.civicspot.io/auth/invite?token=demo-token-xyz',
  ],
  to: 'invitee@example.com',
);
$msg = $inviteBuilder->build($ctx);
if ($msg !== NULL) {
  $b = $brandingService->getBranding($msg->jurisdictionId, $msg->mode, $ctx->langcode);
  $out = $renderer->render($msg->variant, $b, $msg->content, $ctx->langcode, $msg->plainText);
  $builderInviteHtml = $out['html'];
  fwrite(STDOUT, "Invitation: mode={$msg->mode}\n");
  fwrite(STDOUT, "  Subject: {$msg->subject}\n");
}

file_put_contents('/tmp/mail_hero_code.html', $heroOutput['html']);
file_put_contents('/tmp/mail_card_transactional.html', $cardOutput['html']);
if ($builderFeedbackHtml !== '') {
  file_put_contents('/tmp/mail_builder_feedback.html', $builderFeedbackHtml);
}
if ($builderInviteHtml !== '') {
  file_put_contents('/tmp/mail_builder_invitation.html', $builderInviteHtml);
}

fwrite(STDOUT, "\nWrote:\n");
fwrite(STDOUT, "  /tmp/mail_hero_code.html\n");
fwrite(STDOUT, "  /tmp/mail_card_transactional.html\n");
if ($builderFeedbackHtml !== '') {
  fwrite(STDOUT, "  /tmp/mail_builder_feedback.html\n");
}
if ($builderInviteHtml !== '') {
  fwrite(STDOUT, "  /tmp/mail_builder_invitation.html\n");
}
