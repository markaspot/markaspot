<?php

/**
 * @file
 * Renders /tmp/mail_hero_code.html and /tmp/mail_card_transactional.html.
 *
 * Invokes the real MailBrandingService + MailHtmlRenderer via the service
 * container. Platform mode uses inline SVG, so file:// previews work without
 * any data-URI workaround.
 *
 * Usage:
 *   ddev drush scr modules/contrib/markaspot/modules/markaspot_mail/scripts/render-preview-samples.php
 */

declare(strict_types=1);

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Render\Markup;

$brandingService = \Drupal::service('markaspot_mail.branding');
$renderer = \Drupal::service('markaspot_mail.renderer');

$branding = $brandingService->getBranding(NULL, 'platform', 'en');
if (!($branding['email_footer_html'] instanceof MarkupInterface)) {
  $branding['email_footer_html'] = Markup::create('');
}

$heroOutput = $renderer->render('hero_code', $branding, [
  'headline' => 'Verify your account',
  'code' => '156 428',
  'subtext' => 'Enter this code in the next 10 minutes. Ignore this email if you did not request a login.',
  'preheader' => 'Your one-time login code for Mark-a-Spot.',
], 'en');

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

file_put_contents('/tmp/mail_hero_code.html', $heroOutput['html']);
file_put_contents('/tmp/mail_card_transactional.html', $cardOutput['html']);

echo "Wrote:\n  /tmp/mail_hero_code.html\n  /tmp/mail_card_transactional.html\n";
echo "Logo: inline SVG (no external dependency, degrades gracefully in Gmail)\n";
