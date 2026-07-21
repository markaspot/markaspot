<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Field;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Resolves and preloads referenced dashboard data without JSON:API includes.
 */
final class DashboardReferenceData {

  /**
   * Image style used for dashboard media previews.
   */
  private const DASHBOARD_IMAGE_STYLE = 'markaspot_dashboard_media';

  /**
   * Image style used until the dashboard style update has run.
   */
  private const FALLBACK_IMAGE_STYLE = 'thumbnail';

  /**
   * Preloads all entities used by computed list fields in bounded queries.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   Service request nodes loaded for one JSON:API collection page.
   * @param string[] $requested_fields
   *   Computed sparse fields requested by the collection.
   */
  public static function preload(array $nodes, array $requested_fields): void {
    $entity_type_manager = \Drupal::entityTypeManager();
    $account = \Drupal::currentUser();

    if (in_array('dashboard_media', $requested_fields, TRUE)) {
      $media_ids = self::referenceIds(
        self::viewableNodes($nodes, 'field_request_media', $account),
        'field_request_media'
      );
      $media = $media_ids === []
        ? []
        : $entity_type_manager->getStorage('media')->loadMultiple($media_ids);
      $file_ids = [];
      foreach ($media as $item) {
        if (!$item instanceof MediaInterface) {
          continue;
        }
        $image = self::mediaImageField($item);
        if ($image !== NULL && !$image->isEmpty()) {
          $file_ids[] = (int) $image->target_id;
        }
      }
      if ($file_ids !== []) {
        $entity_type_manager->getStorage('file')->loadMultiple(array_unique($file_ids));
      }
      self::dashboardImageStyle();
    }

    if (in_array('dashboard_status_notes', $requested_fields, TRUE)) {
      $revision_ids = [];
      foreach (self::viewableNodes($nodes, 'field_status_notes', $account) as $node) {
        if (!$node->hasField('field_status_notes')) {
          continue;
        }
        foreach ($node->get('field_status_notes') as $item) {
          if ($item->target_revision_id !== NULL) {
            $revision_ids[] = (int) $item->target_revision_id;
          }
        }
      }

      $paragraphs = $revision_ids === []
        ? []
        : $entity_type_manager->getStorage('paragraph')->loadMultipleRevisions(array_unique($revision_ids));
      $term_ids = self::referenceIds($paragraphs, 'field_status_term');
      if ($term_ids !== []) {
        $entity_type_manager->getStorage('taxonomy_term')->loadMultiple($term_ids);
      }
    }

    if (in_array('assignee_label', $requested_fields, TRUE)) {
      $assignment_nodes = self::viewableNodes($nodes, 'field_assignee', $account);
      $user_ids = self::referenceIds($assignment_nodes, 'field_assignee');
      if ($user_ids !== []) {
        $entity_type_manager->getStorage('user')->loadMultiple($user_ids);
      }
      $team_ids = self::referenceIds($assignment_nodes, 'field_assigned_team');
      if ($team_ids !== []) {
        $entity_type_manager->getStorage('group')->loadMultiple($team_ids);
      }
    }
  }

  /**
   * Returns access-filtered dashboard media data.
   *
   * @return array<int, array<string, string>>
   *   At most one item for each referenced media entity.
   */
  public static function media(NodeInterface $node, AccountInterface $account): array {
    if (!$node->hasField('field_request_media') || $node->get('field_request_media')->isEmpty()) {
      return [];
    }

    $style = self::dashboardImageStyle();
    if (!$style instanceof ImageStyleInterface) {
      return [];
    }

    $items = [];
    foreach ($node->get('field_request_media') as $reference) {
      $media = $reference->entity;
      if (!$media instanceof MediaInterface || !$media->access('view', $account)) {
        continue;
      }

      $image = self::mediaImageField($media);
      if ($image === NULL || $image->isEmpty() || !$image->access('view', $account)) {
        continue;
      }

      $file = $image->entity;
      if (!$file instanceof FileInterface || !$file->access('view', $account)) {
        continue;
      }

      $uri = $file->getFileUri();
      if ($uri === '') {
        continue;
      }

      $items[] = [
        'uuid' => $media->uuid(),
        'url' => $style->buildUrl($uri),
        'cache_key' => $file->uuid(),
        'alt' => trim((string) ($image->alt ?? '')),
      ];
      if ($media->hasField('field_ai_hazard_category')
        && !$media->get('field_ai_hazard_category')->isEmpty()
        && $media->get('field_ai_hazard_category')->access('view', $account)) {
        $items[array_key_last($items)]['hazard_category'] = (string) $media->get('field_ai_hazard_category')->value;
      }
    }

    return $items;
  }

  /**
   * Returns access-filtered status-note summaries.
   *
   * @return array<int, array<string, int|string>>
   *   Note values and an optional visible status-term identifier.
   */
  public static function statusNotes(NodeInterface $node, AccountInterface $account): array {
    if (!$node->hasField('field_status_notes') || $node->get('field_status_notes')->isEmpty()) {
      return [];
    }

    $items = [];
    foreach ($node->get('field_status_notes') as $reference) {
      $paragraph = $reference->entity;
      if (!$paragraph instanceof ParagraphInterface || !$paragraph->access('view', $account)) {
        continue;
      }
      if (!$paragraph->hasField('field_status_note')
        || !$paragraph->get('field_status_note')->access('view', $account)) {
        continue;
      }

      $item = [
        'status_note' => (string) $paragraph->get('field_status_note')->value,
      ];
      if ($paragraph->hasField('created') && $paragraph->get('created')->access('view', $account)) {
        $item['updated_datetime'] = gmdate(\DateTimeInterface::RFC3339, (int) $paragraph->getCreatedTime());
      }
      if ($paragraph->hasField('field_status_term')
        && !$paragraph->get('field_status_term')->isEmpty()
        && $paragraph->get('field_status_term')->access('view', $account)) {
        $term = $paragraph->get('field_status_term')->entity;
        if ($term instanceof TermInterface && $term->access('view', $account)) {
          $item['status_uuid'] = $term->uuid();
          $item['status_tid'] = (int) $term->id();
        }
      }
      $items[] = $item;
    }

    return $items;
  }

  /**
   * Adds referenced-entity cacheability without changing field access.
   */
  public static function addCacheability(AccessResultInterface $result, NodeInterface $node, AccountInterface $account, string $source_field): AccessResultInterface {
    if (!$node->hasField($source_field) || $node->get($source_field)->isEmpty()) {
      return $result->addCacheableDependency($node);
    }
    if ($result->isForbidden()) {
      return $result->addCacheableDependency($node);
    }

    foreach ($node->get($source_field) as $reference) {
      $entity = $reference->entity;
      if (!$entity instanceof EntityInterface) {
        continue;
      }
      $result
        ->addCacheableDependency($entity)
        ->addCacheableDependency($entity->access('view', $account, TRUE));

      if ($entity instanceof MediaInterface) {
        $image = self::mediaImageField($entity);
        if ($image !== NULL) {
          $result->addCacheableDependency($image->access('view', $account, TRUE));
          $file = $image->entity;
          if ($file instanceof FileInterface) {
            $result
              ->addCacheableDependency($file)
              ->addCacheableDependency($file->access('view', $account, TRUE));
          }
        }
        if ($entity->hasField('field_ai_hazard_category')) {
          $result->addCacheableDependency($entity->get('field_ai_hazard_category')->access('view', $account, TRUE));
        }
      }
      elseif ($entity instanceof ParagraphInterface) {
        foreach (['created', 'field_status_note', 'field_status_term'] as $field_name) {
          if ($entity->hasField($field_name)) {
            $result->addCacheableDependency($entity->get($field_name)->access('view', $account, TRUE));
          }
        }
        if ($entity->hasField('field_status_term')) {
          $term = $entity->get('field_status_term')->entity;
          if ($term instanceof TermInterface) {
            $result
              ->addCacheableDependency($term)
              ->addCacheableDependency($term->access('view', $account, TRUE));
          }
        }
      }
    }

    if ($source_field === 'field_request_media') {
      $result->addCacheableDependency(\Drupal::config('image.style.' . self::DASHBOARD_IMAGE_STYLE));
      $style = self::dashboardImageStyle();
      if ($style instanceof ImageStyleInterface) {
        $result->addCacheableDependency($style);
      }
      $result->addCacheableDependency(\Drupal::config('image.settings'));
    }

    return $result;
  }

  /**
   * Resolves the preferred source image field on request media.
   */
  private static function mediaImageField(MediaInterface $media): ?FieldItemListInterface {
    foreach (['field_media_image', 'thumbnail'] as $field_name) {
      if ($media->hasField($field_name) && !$media->get($field_name)->isEmpty()) {
        return $media->get($field_name);
      }
    }
    return NULL;
  }

  /**
   * Loads the dashboard image style with a migration-safe fallback.
   */
  private static function dashboardImageStyle(): ?ImageStyleInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('image_style');
    foreach ([self::DASHBOARD_IMAGE_STYLE, self::FALLBACK_IMAGE_STYLE] as $style_id) {
      $style = $storage->load($style_id);
      if ($style instanceof ImageStyleInterface) {
        return $style;
      }
    }
    return NULL;
  }

  /**
   * Keeps preloading within the account's source-field visibility.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   Candidate service requests.
   * @param string $field_name
   *   Source reference field.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Account receiving the JSON:API response.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Nodes whose source field may be viewed.
   */
  private static function viewableNodes(array $nodes, string $field_name, AccountInterface $account): array {
    return array_values(array_filter(
      $nodes,
      static fn (NodeInterface $node): bool => $node->hasField($field_name)
        && $node->get($field_name)->access('view', $account)
    ));
  }

  /**
   * Collects unique integer target IDs from entity reference fields.
   *
   * @param array<int, \Drupal\Core\Entity\FieldableEntityInterface> $entities
   *   Fieldable entities.
   * @param string $field_name
   *   Entity reference field name.
   *
   * @return int[]
   *   Referenced entity IDs.
   */
  private static function referenceIds(array $entities, string $field_name): array {
    $ids = [];
    foreach ($entities as $entity) {
      if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
        continue;
      }
      foreach ($entity->get($field_name) as $item) {
        if ($item->target_id !== NULL) {
          $ids[] = (int) $item->target_id;
        }
      }
    }
    return array_values(array_unique($ids));
  }

}
