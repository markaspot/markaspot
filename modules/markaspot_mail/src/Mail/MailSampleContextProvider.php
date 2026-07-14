<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\node\NodeInterface;

/**
 * Builds representative MailContext samples for every markaspot_mail.builder.
 *
 * Single source of truth for "what does a realistic mail for this builder
 * look like", shared by scripts/render-preview-samples.php (visual review
 * in /tmp) and the `markaspot:mail-render-test` drush command (CI-grade
 * coverage gate), so both consume identical sample data instead of two
 * copies drifting apart.
 *
 * Five builders (feedback, ECA action, resubmission, moderation,
 * escalation) resolve jurisdiction / category / address data from a real
 * loaded entity, so a synthetic in-memory node would mask real rendering
 * issues (those fields would just resolve empty). This provider prefers an
 * actually-loaded service_request node and 'org' group over fabricating
 * one; when storage has none, the corresponding MailType is simply absent
 * from getSamples() and callers treat that as "no sample available", not
 * as a failure.
 */
final class MailSampleContextProvider {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds one representative MailContext per MailType with sample data.
   *
   * @param string $langcode
   *   Langcode to stamp on every sample context.
   *
   * @return array<string, MailContext>
   *   Keyed by MailType::value. A missing key means no sample content was
   *   available for that type (e.g. no service_request node exists yet).
   */
  public function getSamples(string $langcode = 'en'): array {
    $samples = [
      MailType::ECA_GROUP_INVITATION->value => new MailContext(
        module: 'markaspot_group',
        key: 'member_invitation',
        langcode: $langcode,
        params: [
          'group_name' => 'Stadt Amsterdam',
          'site_name' => 'CivicSpot',
          'claim_url' => 'https://amsterdam.civicspot.io/auth/invite?token=sample-token',
        ],
        to: 'invitee@example.com',
      ),
      MailType::FASTMAP_WORKSPACE_VERIFICATION->value => new MailContext(
        module: 'markaspot_fastmap',
        key: 'workspace_verification',
        langcode: $langcode,
        params: [
          'workspace_name' => 'Amsterdam Demo',
          'site_name' => 'CivicSpot',
          'verify_url' => 'https://civicspot.io/verify?token=sample-verify-token',
          'cleanup_days' => '7',
        ],
        to: 'creator@example.com',
      ),
      MailType::FASTMAP_WORKSPACE_WELCOME->value => new MailContext(
        module: 'markaspot_fastmap',
        key: 'workspace_welcome',
        langcode: $langcode,
        params: [
          'workspace_name' => 'Amsterdam Demo',
          'site_name' => 'CivicSpot',
          'workspace_url' => 'https://amsterdam-demo.civicspot.io',
        ],
        to: 'creator@example.com',
      ),
      MailType::FASTMAP_DEMO_EXPIRY->value => new MailContext(
        module: 'markaspot_fastmap',
        key: 'demo_expiry_reminder',
        langcode: $langcode,
        params: [
          'workspace_name' => 'Amsterdam Demo',
          'expiry_date' => '2026-05-01',
          'workspace_slug' => 'amsterdam-demo',
        ],
        to: 'owner@example.com',
      ),
      MailType::PASSWORDLESS_OTP->value => new MailContext(
        module: 'markaspot_passwordless',
        key: 'verification_code',
        langcode: $langcode,
        params: [
          'code' => '156428',
          'expires_in' => 10,
          'platform_name' => 'Amsterdam',
          'jurisdiction_id' => 1,
        ],
        to: 'citizen@example.com',
      ),
      MailType::INBOUND_TRIAGE_REPLY->value => new MailContext(
        module: 'markaspot_mail_inbound',
        key: 'triage_reply',
        langcode: $langcode,
        params: [
          'subject' => 'Re: Broken street light on Elm Street',
          'body' => "Thank you for your message.\n\nWe could not match your report to an existing case. Please resend it with a street address or map location so we can route it to the right team.",
        ],
        to: 'citizen@example.com',
      ),
    ];

    $node = $this->findSampleServiceRequestNode();
    if ($node !== NULL) {
      $samples[MailType::ECA_ASSIGNEE_NOTIFICATION->value] = new MailContext(
        module: 'markaspot_group',
        key: 'assignee_notification',
        langcode: $langcode,
        params: ['node' => $node],
        to: 'assignee@example.com',
      );

      $samples[MailType::ECA_FEEDBACK->value] = new MailContext(
        module: 'markaspot_feedback',
        key: 'feedback_request',
        langcode: $langcode,
        params: ['node' => $node],
        to: 'citizen@example.com',
      );

      $ecaBody = "Good day!\n\nThank you for your contribution. Your request has been forwarded to the responsible department and is now being processed.\n\nYou will receive a final notification by email after it has been completed.";
      $samples[MailType::ECA_ACTION->value] = new MailContext(
        module: 'system',
        key: 'action_send_email',
        langcode: $langcode,
        params: [
          'context' => [
            'subject' => '[node:title] is being processed',
            'message' => $ecaBody,
            'node' => $node,
          ],
        ],
        to: 'citizen@example.com',
        subject: 'Your report is being processed',
        body: [$ecaBody],
      );

      $samples[MailType::ECA_RESUBMISSION->value] = new MailContext(
        module: 'markaspot_resubmission',
        key: 'resubmit_request',
        langcode: $langcode,
        params: ['node' => $node],
        to: 'citizen@example.com',
      );

      // One representative notification_key is enough to cover the
      // NOTIFICATION_CONFIG builder itself; MailRenderTestCommands
      // additionally iterates every markaspot_mail.texts key x language,
      // since a single builder instance handles all of them.
      $samples[MailType::NOTIFICATION_CONFIG->value] = new MailContext(
        module: 'markaspot_mail',
        key: 'notification_report_confirmation',
        langcode: $langcode,
        params: ['node' => $node, 'notification_key' => 'report_confirmation'],
        to: 'citizen@example.com',
      );

      $samples[MailType::ECA_MODERATION->value] = new MailContext(
        module: 'markaspot_moderation',
        key: 'flag_threshold',
        langcode: $langcode,
        params: [
          'subject' => 'A report has been flagged for review',
          'body' => "This report was flagged by multiple citizens and needs moderator review.\n\nPlease review it in the dashboard.",
          'node' => $node,
        ],
        to: 'moderator@example.com',
      );

      $samples[MailType::ECA_ESCALATION->value] = new MailContext(
        module: 'markaspot_escalation',
        key: 'escalation_notification',
        langcode: $langcode,
        params: [
          'subject' => 'A report has been escalated to your jurisdiction',
          'body' => "This report was escalated from a neighboring jurisdiction.\n\nPlease review it in the dashboard.",
          'node' => $node,
        ],
        to: 'staff@example.com',
      );

      $organisation = $this->findSampleOrganisation();
      if ($organisation !== NULL) {
        $samples[MailType::ECA_GROUP_ORG_NOTIFICATION->value] = new MailContext(
          module: 'markaspot_group',
          key: 'org_notification',
          langcode: $langcode,
          params: ['node' => $node, 'organisation' => $organisation],
          to: 'org-staff@example.com',
        );
      }
    }

    return $samples;
  }

  /**
   * Loads a real service_request node for content-dependent samples.
   */
  public function findSampleServiceRequestNode(): ?NodeInterface {
    if (!$this->entityTypeManager->hasDefinition('node')) {
      return NULL;
    }
    $nodes = $this->entityTypeManager->getStorage('node')
      ->loadByProperties(['type' => 'service_request']);
    $node = reset($nodes) ?: NULL;
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Loads a real 'org' group entity for the org_notification sample.
   */
  public function findSampleOrganisation(): ?GroupInterface {
    if (!$this->entityTypeManager->hasDefinition('group')) {
      return NULL;
    }
    $groups = $this->entityTypeManager->getStorage('group')
      ->loadByProperties(['type' => 'org']);
    $group = reset($groups) ?: NULL;
    return $group instanceof GroupInterface ? $group : NULL;
  }

}
