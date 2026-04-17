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
$ecaActionBuilder = \Drupal::service('markaspot_mail.builder.eca_action_email');
$workspaceVerificationBuilder = \Drupal::service('markaspot_mail.builder.workspace_verification');
$demoExpiryBuilder = \Drupal::service('markaspot_mail.builder.demo_expiry_reminder');
$otpBuilder = \Drupal::service('markaspot_mail.builder.passwordless_otp');
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

// 5. EcaActionEmailBuilder: simulates what the four ECA processes
//    (process_confirm_report, process_tunr6d6 etc.) produce once their
//    action_send_email_action has been post-processed by system_mail().
//    We skip the real ECA trigger and feed a synthetic MailContext that
//    matches the shape hook_mail_alter would see at runtime.
$builderEcaHtml = '';
$ecaNode = !empty($nodes) ? reset($nodes) : NULL;
$ecaSubject = 'Your report #51-2026 is being processed';
$ecaBody = "Good day!\n\nThank you for your contribution. Your request has been forwarded to the responsible department and is now being processed.\n\nYou will receive a final notification by email after it has been completed.\n\n-- This is an automatically generated email, and unfortunately, you cannot reply to it.";

$ctx = new MailContext(
  module: 'system',
  key: 'action_send_email',
  langcode: 'en',
  params: [
    'context' => [
      'subject' => '[node:title] is being processed',
      'message' => $ecaBody,
      'node' => $ecaNode,
    ],
  ],
  to: 'citizen@example.com',
  subject: $ecaSubject,
  body: [$ecaBody],
);
$msg = $ecaActionBuilder->build($ctx);
if ($msg !== NULL) {
  $b = $brandingService->getBranding($msg->jurisdictionId, $msg->mode, $ctx->langcode);
  $out = $renderer->render($msg->variant, $b, $msg->content, $ctx->langcode, $msg->plainText);
  $builderEcaHtml = $out['html'];
  fwrite(STDOUT, "ECA action: mode={$msg->mode}, jur=" . ($msg->jurisdictionId ?? 'NULL') . "\n");
  fwrite(STDOUT, "  Subject: {$msg->subject}\n");
}

// 6. WorkspaceVerificationBuilder (FastMap sign-up).
$builderWvHtml = '';
$ctx = new MailContext(
  module: 'markaspot_fastmap',
  key: 'workspace_verification',
  langcode: 'en',
  params: [
    'workspace_name' => 'Amsterdam Demo',
    'site_name' => 'CivicSpot',
    'verify_url' => 'https://civicspot.io/verify?token=demo-verify-token',
    'cleanup_days' => '7',
  ],
  to: 'creator@example.com',
);
$msg = $workspaceVerificationBuilder->build($ctx);
if ($msg !== NULL) {
  $b = $brandingService->getBranding($msg->jurisdictionId, $msg->mode, $ctx->langcode);
  $out = $renderer->render($msg->variant, $b, $msg->content, $ctx->langcode, $msg->plainText);
  $builderWvHtml = $out['html'];
  fwrite(STDOUT, "Workspace verification: mode={$msg->mode}\n");
  fwrite(STDOUT, "  Subject: {$msg->subject}\n");
}

// 7. DemoExpiryReminderBuilder (FastMap demo expiring).
$builderDemoHtml = '';
$ctx = new MailContext(
  module: 'markaspot_fastmap',
  key: 'demo_expiry_reminder',
  langcode: 'en',
  params: [
    'workspace_name' => 'Amsterdam Demo',
    'expiry_date' => '2026-05-01',
    'workspace_slug' => 'amsterdam-demo',
  ],
  to: 'owner@example.com',
);
$msg = $demoExpiryBuilder->build($ctx);
if ($msg !== NULL) {
  $b = $brandingService->getBranding($msg->jurisdictionId, $msg->mode, $ctx->langcode);
  $out = $renderer->render($msg->variant, $b, $msg->content, $ctx->langcode, $msg->plainText);
  $builderDemoHtml = $out['html'];
  fwrite(STDOUT, "Demo expiry: mode={$msg->mode}\n");
  fwrite(STDOUT, "  Subject: {$msg->subject}\n");
}

// 8. PasswordlessOtpBuilder (verification_code OTP, jurisdiction mode).
//    Uses the hero_code variant: colored hero card + monospaced code.
$builderOtpHtml = '';
$ctx = new MailContext(
  module: 'markaspot_passwordless',
  key: 'verification_code',
  langcode: 'en',
  params: [
    'code' => '156428',
    'expires_in' => 10,
    'platform_name' => 'Amsterdam',
    'jurisdiction_id' => 1,
  ],
  to: 'citizen@example.com',
);
$msg = $otpBuilder->build($ctx);
if ($msg !== NULL) {
  $b = $brandingService->getBranding($msg->jurisdictionId, $msg->mode, $ctx->langcode);
  $out = $renderer->render($msg->variant, $b, $msg->content, $ctx->langcode, $msg->plainText);
  $builderOtpHtml = $out['html'];
  fwrite(STDOUT, "OTP: mode={$msg->mode}, jur=" . ($msg->jurisdictionId ?? 'NULL') . "\n");
  fwrite(STDOUT, "  Subject: {$msg->subject}\n");
  fwrite(STDOUT, "  Code: {$msg->content['code']}\n");
}

file_put_contents('/tmp/mail_hero_code.html', $heroOutput['html']);
file_put_contents('/tmp/mail_card_transactional.html', $cardOutput['html']);
if ($builderFeedbackHtml !== '') {
  file_put_contents('/tmp/mail_builder_feedback.html', $builderFeedbackHtml);
}
if ($builderInviteHtml !== '') {
  file_put_contents('/tmp/mail_builder_invitation.html', $builderInviteHtml);
}
if ($builderEcaHtml !== '') {
  file_put_contents('/tmp/mail_builder_eca_action.html', $builderEcaHtml);
}
if ($builderWvHtml !== '') {
  file_put_contents('/tmp/mail_builder_workspace_verification.html', $builderWvHtml);
}
if ($builderDemoHtml !== '') {
  file_put_contents('/tmp/mail_builder_demo_expiry.html', $builderDemoHtml);
}
if ($builderOtpHtml !== '') {
  file_put_contents('/tmp/mail_builder_otp.html', $builderOtpHtml);
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
