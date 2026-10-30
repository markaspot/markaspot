<?php

declare(strict_types=1);

namespace Drupal\service_request;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;

/**
 * Language-neutral action metadata in the standard revision log field.
 *
 * Contract shared with beantragt.ai: civic.revision.v1. Human explanations are
 * retained verbatim; clients translate the action codes, never the audit data.
 */
final class RevisionLog {

  /**
   * Reference changes shared by hook-based and intentional snapshot writers.
   */
  public const REFERENCE_FIELDS = [
    'field_organisation' => 'responsibility', 'field_assignee' => 'person',
    'field_assigned_team' => 'team', 'field_status' => 'status',
    'field_category' => 'category', 'field_request_media' => 'photos',
  ];

  /**
   * Source fields mapped to all change concepts for view access checks.
   */
  public const CHANGE_FIELDS = self::REFERENCE_FIELDS + ['body' => 'description'];

  /**
   * Publication events expose the node's status field, not field_status.
   */
  public const EVENT_FIELDS = ['published' => 'status', 'unpublished' => 'status'];

  /**
   * Stable automatic causes. Human explanations are kept separately.
   */
  public const EVENTS = [
    'scope_sync', 'assignment_sync', 'escalated', 'delegated',
    'moderation_hidden', 'moderation_auto_hidden', 'privacy_hold',
    'anonymized', 'legacy_notes_migrated',
    'published', 'unpublished',
  ];

  /**
   * Explicit reasons awaiting their entity save.
   *
   * @var \WeakMap<\Drupal\Core\Entity\ContentEntityInterface, string>|null
   */
  private static ?\WeakMap $pendingReasons = NULL;

  /**
   * Automatic causes awaiting their entity save.
   *
   * @var \WeakMap<\Drupal\Core\Entity\ContentEntityInterface, string[]>|null
   */
  private static ?\WeakMap $pendingEvents = NULL;

  /**
   * Marks a server-generated event without storing translated prose or names.
   */
  public static function setEvent(ContentEntityInterface $entity, string $event): void {
    if (!$entity instanceof RevisionLogInterface || !in_array($event, self::EVENTS, TRUE)) {
      throw new \InvalidArgumentException('A supported revision event is required.');
    }
    self::$pendingEvents ??= new \WeakMap();
    self::$pendingEvents[$entity] = array_values(array_unique([
      ...(self::$pendingEvents[$entity] ?? []), $event,
    ]));
  }

  /**
   * Marks an explicit reason, including a deliberate repetition of an old one.
   */
  public static function setMessage(ContentEntityInterface $entity, string $message): void {
    if (!$entity instanceof RevisionLogInterface) {
      throw new \InvalidArgumentException('A revision log entity is required.');
    }
    self::$pendingReasons ??= new \WeakMap();
    self::$pendingReasons[$entity] = $message;
    $entity->setRevisionLogMessage($message);
  }

  /**
   * Returns an explicit pending reason without consuming it.
   */
  public static function pendingMessage(ContentEntityInterface $entity): ?string {
    return self::$pendingReasons !== NULL ? (self::$pendingReasons[$entity] ?? NULL) : NULL;
  }

  /**
   * Builds a log exclusively from the actual entity transition.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being saved.
   * @param array<string, string> $fields
   *   Entity reference field names mapped to stable domain concepts.
   */
  public static function build(ContentEntityInterface $entity, array $fields): string {
    if (!$entity instanceof RevisionLogInterface) {
      throw new \InvalidArgumentException('A revision log entity is required.');
    }
    $original = $entity->getOriginal();
    $message = (string) $entity->getRevisionLogMessage();
    // Only an explicitly supplied reason may repeat the previous message.
    // Different incoming text remains text, never trusted action metadata.
    if (self::$pendingReasons !== NULL && isset(self::$pendingReasons[$entity])) {
      $message = self::$pendingReasons[$entity];
      unset(self::$pendingReasons[$entity]);
    }
    elseif ($original instanceof RevisionLogInterface && $message === (string) $original->getRevisionLogMessage()) {
      $message = '';
    }
    $changes = [];
    foreach ($fields as $field => $concept) {
      if (!$entity->hasField($field)) {
        continue;
      }
      $before = $original instanceof ContentEntityInterface ? self::references($original, $field) : [];
      $after = self::references($entity, $field);
      if (array_column($before, 'id') !== array_column($after, 'id')) {
        $changes[] = ['field' => $concept, 'before' => $before, 'after' => $after];
      }
    }
    $events = self::$pendingEvents !== NULL ? (self::$pendingEvents[$entity] ?? []) : [];
    if (self::$pendingEvents !== NULL) {
      unset(self::$pendingEvents[$entity]);
    }
    // Keep only the fact of a description change. Values, summaries and text
    // formats remain exclusively in the access-controlled entity revision.
    if ($entity->hasField('body')) {
      $before = $original instanceof ContentEntityInterface && $original->hasField('body') ? $original->get('body') : NULL;
      if ($before !== NULL ? !$entity->get('body')->equals($before) : !$entity->get('body')->isEmpty()) {
        $changes[] = ['field' => 'description', 'before' => [], 'after' => []];
      }
    }
    // Initial publication is part of creation, not a publication transition.
    if ($original instanceof ContentEntityInterface && $original->hasField('status') && $entity->hasField('status')
      && (bool) $original->get('status')->value !== (bool) $entity->get('status')->value) {
      $events[] = $entity->get('status')->value ? 'published' : 'unpublished';
    }
    return json_encode([
      'schema' => 'civic.revision.v1',
      'action' => $entity->isNew() ? 'created' : 'updated',
      'changes' => $changes,
      'events' => array_values(array_unique($events)),
      'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

  /**
   * Reads a versioned envelope; legacy free-text logs remain valid.
   */
  public static function decode(string $value): ?array {
    $data = json_decode($value, TRUE);
    if (!is_array($data) || ($data['schema'] ?? NULL) !== 'civic.revision.v1'
      || !in_array($data['action'] ?? NULL, ['created', 'updated'], TRUE)
      || !is_array($data['changes'] ?? NULL) || !array_is_list($data['changes'])
      || !is_string($data['message'] ?? NULL)) {
      return NULL;
    }
    $changes = [];
    foreach ($data['changes'] as $change) {
      if (!is_array($change) || !is_string($change['field'] ?? NULL)) {
        return NULL;
      }
      // A newer writer may add concepts this reader does not understand. Keep
      // the reason and known changes instead of falling back to raw JSON.
      if (!in_array($change['field'], self::CHANGE_FIELDS, TRUE)) {
        continue;
      }
      foreach (['before', 'after'] as $side) {
        if (!is_array($change[$side] ?? NULL) || !array_is_list($change[$side])) {
          return NULL;
        }
        if ($change['field'] === 'description' && $change[$side] !== []) {
          return NULL;
        }
        foreach ($change[$side] as $reference) {
          if (!is_array($reference) || !is_string($reference['id'] ?? NULL) || (isset($reference['label']) && !is_string($reference['label']))) {
            return NULL;
          }
        }
      }
      $changes[] = $change;
    }
    $data['changes'] = $changes;
    if (array_key_exists('events', $data)) {
      if (!is_array($data['events']) || !array_is_list($data['events'])) {
        return NULL;
      }
      // As with unknown change concepts, omit unsupported event codes while
      // retaining the original reason and all understood metadata.
      $data['events'] = array_values(array_unique(array_filter($data['events'],
        static fn($event): bool => is_string($event) && in_array($event, self::EVENTS, TRUE))));
    }
    return $data;
  }

  /**
   * Returns the original message, including existing semantic log prefixes.
   */
  public static function message(string $value): string {
    return self::decode($value)['message'] ?? $value;
  }

  /**
   * Captures stable reference IDs without duplicating names in revision text.
   */
  private static function references(ContentEntityInterface $entity, string $field): array {
    if (!$entity->hasField($field)) {
      return [];
    }
    $result = [];
    foreach ($entity->get($field) as $item) {
      if ($item->target_id !== NULL) {
        $result[] = ['id' => (string) $item->target_id];
      }
    }
    usort($result, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
    return $result;
  }

}
