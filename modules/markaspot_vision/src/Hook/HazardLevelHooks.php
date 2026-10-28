<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Hook;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;

/**
 * Carries the AI hazard assessment of request photos to the request.
 *
 * NULL in field_hazard_level means "not assessed". Only a photo the vision
 * analysis actually assessed counts: one without a field_ai_hazard_level
 * value (uploaded before vision was switched on, or never analysed) is
 * ignored, and a request without any assessed photo keeps its value, NULL
 * included. 0 is a real assessment ("None") and is never written in place of
 * a missing one.
 */
final class HazardLevelHooks {

  /**
   * Raises a request's hazard level to that of its assessed photos.
   *
   * Runs before the storage write, so the level is saved with the request.
   * It acts on a new request and on a request whose photo references
   * changed, never on an unrelated save of an existing request, so stored
   * requests are not filled in after the fact. The level is only raised:
   * markaspot_ai stores max(vision, text), and a photo must not lower an
   * assessment made from the description.
   */
  #[Hook('node_presave')]
  public function nodePresave(NodeInterface $node): void {
    if ($node->bundle() !== 'service_request'
      || !$node->hasField('field_hazard_level')
      || !$node->hasField('field_request_media')) {
      return;
    }
    $photos = $node->get('field_request_media');
    if (!$photos instanceof EntityReferenceFieldItemListInterface
      || (!$node->isNew() && !self::photosChanged($node))) {
      return;
    }
    $assessed = self::assessedLevel($photos->referencedEntities());
    if ($assessed === NULL) {
      return;
    }
    $current = $node->get('field_hazard_level');
    if ($current->isEmpty() || $assessed > (int) $current->value) {
      $node->set('field_hazard_level', $assessed);
    }
  }

  /**
   * Returns the highest AI hazard level of the assessed photos.
   *
   * @param array<int|string, mixed> $media
   *   Referenced media entities.
   *
   * @return int|null
   *   The highest level, or NULL when no photo was assessed.
   */
  public static function assessedLevel(array $media): ?int {
    $level = NULL;
    foreach ($media as $item) {
      if (!$item instanceof FieldableEntityInterface || !$item->hasField('field_ai_hazard_level')) {
        continue;
      }
      $assessment = $item->get('field_ai_hazard_level');
      if ($assessment->isEmpty()) {
        continue;
      }
      $level = max($level ?? 0, (int) $assessment->value);
    }
    return $level;
  }

  /**
   * Checks whether the photo references differ from the stored request.
   */
  private static function photosChanged(NodeInterface $node): bool {
    // getOriginal() was added in Drupal 11.2. Drupal 10 exposes the unchanged
    // entity through the legacy public property during presave.
    $original = method_exists($node, 'getOriginal')
      ? $node->getOriginal()
      : ($node->original ?? NULL);
    if (!$original instanceof NodeInterface || !$original->hasField('field_request_media')) {
      return TRUE;
    }
    return self::mediaIds($node) !== self::mediaIds($original);
  }

  /**
   * Returns the referenced media IDs of a request in delta order.
   *
   * @return list<int>
   *   Media IDs.
   */
  private static function mediaIds(NodeInterface $node): array {
    return array_values(array_map('intval', array_column($node->get('field_request_media')->getValue(), 'target_id')));
  }

}
