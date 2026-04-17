<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Enum;

/**
 * Canonical classification of mails handled by markaspot_mail.
 *
 * Used for logging, metrics and service-discovery keying. A builder declares
 * the single MailType it handles via MailBuilderInterface::getType(); which
 * Drupal $message['module'] + $message['key'] pairs the builder intercepts
 * is a separate concern (see MailBuilderInterface::supports()) so a single
 * classification can fan out to multiple legacy mail keys during migration.
 *
 * Adding a type here is cheap and non-breaking; only wired-up builders
 * ever reach the rendering pipeline.
 */
enum MailType: string {

  // ECA notifications (Stage 2).
  case ECA_ESCALATION = 'eca_escalation';
  case ECA_RESUBMISSION = 'eca_resubmission';
  case ECA_FEEDBACK = 'eca_feedback';
  case ECA_MODERATION = 'eca_moderation';
  case ECA_GROUP_INVITATION = 'eca_group_invitation';
  case ECA_GROUP_ORG_NOTIFICATION = 'eca_group_org_notification';

  // ECA-driven action_send_email_action workflows (Stage 2c). Catches
  // everything the ECA editor fires via Drupal core's EmailAction plugin
  // (module=system, key=action_send_email), irrespective of the
  // specific ECA process that produced it.
  case ECA_ACTION = 'eca_action';

  // CivicSpot / FastMap workspace mails (Stage 3).
  case FASTMAP_WORKSPACE_VERIFICATION = 'fastmap_workspace_verification';
  case FASTMAP_DEMO_EXPIRY = 'fastmap_demo_expiry';

  // Passwordless login / verification OTP (Stage 4).
  case PASSWORDLESS_OTP = 'passwordless_otp';

}
