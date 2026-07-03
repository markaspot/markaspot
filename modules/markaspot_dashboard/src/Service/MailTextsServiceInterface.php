<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service;

use Drupal\Core\Session\AccountInterface;

/**
 * Manages markaspot_mail.texts notification templates for the dashboard.
 *
 * Backs the /api/dashboard/mail-texts* routes: read the full catalog (texts
 * + standard-key flags + the generated token catalog), create/update a
 * key's six wording slots, delete a custom key (guarded against standard
 * keys and ECA-referenced custom keys), and resolve tokens for the editor's
 * live-preview panel. V1 is default-language only; per-locale overrides
 * stay on core Config Translation (see MailTextsForm) and are not exposed
 * here.
 */
interface MailTextsServiceInterface {

  /**
   * Builds the full catalog: texts, standard-key list, token catalog.
   *
   * @return array{texts: array<string, array<string, mixed>>, standard_keys: list<string>, tokens: list<array<string, string>>}
   *   The catalog, wire-shaped exactly as GET /api/dashboard/mail-texts
   *   returns it (snake_case keys throughout).
   */
  public function getCatalog(): array;

  /**
   * Creates or updates a notification key's wording slots.
   *
   * @param string $key
   *   The notification key. Must match ^[a-z0-9_]{3,64}$.
   * @param array<string, mixed> $slots
   *   Any subset of subject/headline/intro/body_blocks/cta_label/preheader.
   *   Slots absent from the array are left unchanged for an existing key,
   *   or left empty for a newly created one.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The acting user, for the audit log.
   *
   * @return array{key: string, text: array<string, mixed>, created: bool}
   *   The saved key, its full six-slot text (plus `standard`), and whether
   *   the key was newly created.
   *
   * @throws \InvalidArgumentException
   *   The key fails the regex, or a slot fails validation (length/type).
   */
  public function saveText(string $key, array $slots, AccountInterface $account): array;

  /**
   * Deletes a custom notification key.
   *
   * @param string $key
   *   The notification key to delete.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The acting user, for the audit log.
   *
   * @return string
   *   The deleted key.
   *
   * @throws \Drupal\markaspot_dashboard\Service\Exception\MailTextsNotFoundException
   *   The key does not exist.
   * @throws \Drupal\markaspot_dashboard\Service\Exception\MailTextsForbiddenException
   *   The key is a standard (shipped) key.
   * @throws \Drupal\markaspot_dashboard\Service\Exception\MailTextsConflictException
   *   The key is still referenced by an active
   *   markaspot_mail_send_notification ECA action.
   */
  public function deleteText(string $key, AccountInterface $account): string;

  /**
   * Resolves tokens in a subject/intro/body_blocks draft for editor preview.
   *
   * @param array<string, mixed> $input
   *   Any subset of subject/intro/body_blocks.
   *
   * @return array{subject: string, intro: string, body_blocks: list<string>, sample_request_id: string|null}
   *   The same slots with tokens resolved (Token::replace, clear TRUE)
   *   against the newest service_request node, plus that node's citizen-
   *   facing request_id (NULL when no service_request node exists yet).
   */
  public function preview(array $input): array;

}
