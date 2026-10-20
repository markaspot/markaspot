<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Demo\DemoSeed;
use Drupal\markaspot_fastmap\Demo\DemoWorkspace;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Restores template demo workspaces to their seeded state.
 *
 * Two independent conditions gate every write: the slug is listed in
 * markaspot_fastmap.settings:demo_workspaces (operator allowlist) AND the
 * group carries the demo block in field_nuxt_config. Neither alone is
 * enough, so a customer workspace can be touched neither by a typo in the
 * allowlist nor by a demo block someone wrote into its config.
 */
final class DemoWorkspaceReset {

  /**
   * State key of the last cron-triggered reset.
   */
  public const LAST_RUN_STATE = 'markaspot_fastmap.last_demo_workspace_reset';

  /**
   * Key/value collection of markaspot_vision's upload bindings.
   *
   * MediaTenantScopeResolver::STORE: media UUID => the jurisdiction the
   * upload request named. It is the only record of which workspace an
   * upload that never became a report belongs to.
   */
  public const UPLOAD_BINDING_STORE = 'markaspot_vision_media_tenant';

  /**
   * Seconds an unattached upload is kept, so a form in progress keeps it.
   */
  public const ORPHAN_UPLOAD_GRACE = 1800;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DemoWorkspaceSeeder $seeder,
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
    private readonly FileUsageInterface $fileUsage,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly DemoStaffAccount $staffAccount,
    private readonly Connection $database,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly ?TierConfigService $tierConfig = NULL,
  ) {}

  /**
   * Returns the allowlisted demo slugs.
   *
   * @return string[]
   *   Slugs from markaspot_fastmap.settings:demo_workspaces.
   */
  public function allowlist(): array {
    $slugs = $this->configFactory->get('markaspot_fastmap.settings')->get('demo_workspaces');
    return is_array($slugs) ? array_values(array_filter($slugs, 'is_string')) : [];
  }

  /**
   * Runs the cron reset when demo_reset_interval has elapsed.
   */
  public function runIfDue(): void {
    $interval = (int) $this->configFactory->get('markaspot_fastmap.settings')->get('demo_reset_interval');
    if ($interval <= 0) {
      return;
    }
    $now = $this->time->getRequestTime();
    if ($now - (int) $this->state->get(self::LAST_RUN_STATE, 0) < $interval) {
      return;
    }
    $failed = array_filter($this->resetAll(), 'is_string');
    // Only a complete run counts; after a failure the next cron run retries.
    if ($failed === []) {
      $this->state->set(self::LAST_RUN_STATE, $now);
    }
    else {
      $this->logger->warning('Demo workspace cron reset incomplete (@slugs); it is retried at the next cron run.', [
        '@slugs' => implode(', ', array_keys($failed)),
      ]);
    }
  }

  /**
   * Resets every allowlisted demo workspace; one failure does not stop others.
   *
   * @return array<string, array|string>
   *   Per slug either the reset summary or the error message.
   */
  public function resetAll(): array {
    $results = [];
    foreach ($this->allowlist() as $slug) {
      try {
        $results[$slug] = $this->resetWorkspace($slug);
      }
      catch (\Throwable $e) {
        $this->logger->error('Demo workspace reset of @slug failed: @msg', [
          '@slug' => $slug,
          '@msg' => $e->getMessage(),
        ]);
        $results[$slug] = $e->getMessage();
      }
    }
    return $results;
  }

  /**
   * Removes visitor content from one demo workspace and re-applies its seed.
   *
   * @param string $slug
   *   The workspace slug; must be allowlisted.
   * @param \Drupal\markaspot_fastmap\Demo\DemoSeed|null $seed
   *   The seed to apply. NULL loads it from demo_seed_dir or the copy stored
   *   by the last seed run.
   *
   * @return array{group_id: int, slug: string, removed: array<string, int>, reports: array<string, string>}
   *   What was removed per entity type, and the seeded request IDs.
   */
  public function resetWorkspace(string $slug, ?DemoSeed $seed = NULL): array {
    if (!in_array($slug, $this->allowlist(), TRUE)) {
      throw new \RuntimeException(sprintf('"%s" is not listed in markaspot_fastmap.settings:demo_workspaces.', $slug));
    }
    $group = $this->seeder->findWorkspace($slug);
    if ($group === NULL) {
      throw new \RuntimeException(sprintf('Demo workspace "%s" does not exist; seed it first.', $slug));
    }
    if (!DemoWorkspace::isDemo($group)) {
      throw new \RuntimeException(sprintf('Workspace "%s" carries no demo block; refusing to reset it.', $slug));
    }
    $seed ??= $this->loadSeed($slug);
    if ($seed->data['slug'] !== $slug) {
      throw new \RuntimeException(sprintf('Seed is for "%s", not "%s".', $seed->data['slug'], $slug));
    }

    $lockName = 'markaspot_fastmap:demo_reset:' . $slug;
    if (!$this->lock->acquire($lockName, 600.0)) {
      throw new \RuntimeException(sprintf('Another reset of "%s" is in progress.', $slug));
    }
    try {
      // Purge and re-seed are separate steps. Check first that the seed can be
      // applied completely, so a failure never leaves an emptied demo.
      $missing = $this->seeder->unrestorablePhotos($seed);
      if ($missing !== []) {
        $this->logger->error('Demo workspace @slug not reset, photos cannot be restored for: @keys. Visitor content was kept.', [
          '@slug' => $slug,
          '@keys' => implode(', ', $missing),
        ]);
        throw new \RuntimeException(sprintf('Photos of %s cannot be restored; reset skipped, nothing was deleted.', implode(', ', $missing)));
      }
      $removed = $this->seeder->asOperator(fn(): array => $this->purgeVisitorContent($group, $seed));
      $result = $this->seeder->seed($seed);
    }
    finally {
      $this->lock->release($lockName);
    }

    // Visitors of the shared staff account start over with the content.
    $account = $this->staffAccount->load($slug);
    if ($account !== NULL) {
      $this->staffAccount->deleteSessions($account);
    }
    // A fresh AI budget (DemoWorkspace::AI_BUDGET) for the next window.
    $removed['ai_usage'] = $this->tierConfig?->resetAIUsage((int) $group->id()) ?? 0;

    $this->logger->notice('Demo workspace @slug reset: @nodes visitor nodes, @media media, @terms terms removed, staff sessions ended.', [
      '@slug' => $slug,
      '@nodes' => $removed['node'],
      '@media' => $removed['media'],
      '@terms' => $removed['taxonomy_term'],
    ]);
    return [
      'group_id' => $result['group_id'],
      'slug' => $slug,
      'removed' => $removed,
      'reports' => $result['reports'],
    ];
  }

  /**
   * Loads the seed for a slug from demo_seed_dir, else from the stored copy.
   */
  public function loadSeed(string $slug): DemoSeed {
    // The slug becomes part of a file path below.
    if (!preg_match(DemoWorkspace::SLUG_PATTERN, $slug)) {
      throw new \RuntimeException(sprintf('"%s" is not a template demo slug.', $slug));
    }
    $directory = (string) $this->configFactory->get('markaspot_fastmap.settings')->get('demo_seed_dir');
    if ($directory !== '') {
      $path = rtrim($directory, '/') . '/' . $slug . '.yml';
      if (is_file($path)) {
        return DemoSeed::fromFile($path);
      }
    }
    $stored = $this->state->get(DemoWorkspaceSeeder::STATE_PREFIX . $slug);
    if (!is_array($stored)) {
      throw new \RuntimeException(sprintf('No seed found for "%s" (no %s.yml in demo_seed_dir and no stored seed).', $slug, $slug));
    }
    return DemoSeed::fromStorage($stored);
  }

  /**
   * Deletes everything in the workspace that the seed does not own.
   *
   * @return array<string, int>
   *   Number of removed entities per entity type.
   */
  private function purgeVisitorContent(GroupInterface $group, DemoSeed $seed): array {
    $groupId = (int) $group->id();
    $owned = array_map('array_flip', $this->seeder->seededUuids($seed));
    $removed = ['node' => 0, 'paragraph' => 0, 'media' => 0, 'media_kept' => 0, 'file' => 0, 'taxonomy_term' => 0];

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nids = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_jurisdiction', $groupId)
      ->execute();
    foreach (array_chunk(array_values($nids), 50) as $chunk) {
      foreach ($nodeStorage->loadMultiple($chunk) as $node) {
        if (!$node instanceof NodeInterface) {
          continue;
        }
        if (isset($owned['node'][$node->uuid()])) {
          $this->deleteOlderRevisions($node);
          continue;
        }
        $paragraphIds = $this->childParagraphIds((int) $node->id());
        $media = $node->hasField('field_request_media') ? $node->get('field_request_media')->referencedEntities() : [];
        $node->delete();
        $removed['node']++;
        $removed['paragraph'] += $this->deleteParagraphs($paragraphIds);
        foreach ($media as $item) {
          if (!$item instanceof MediaInterface || isset($owned['media'][$item->uuid()])) {
            continue;
          }
          // A visitor can reference any existing media ID in a demo report.
          // Only media used by nothing outside this demo may go; anything
          // else just loses the reference that died with the visitor node.
          if ($this->isReferencedOutside($item, $groupId)) {
            $removed['media_kept']++;
            continue;
          }
          $removed['file'] += $this->deleteMedia($item);
          $removed['media']++;
        }
      }
    }

    $this->purgeOrphanUploads($groupId, $owned['media'] ?? [], $removed);

    // Terms the demo account may have added in the dashboard. Statuses go
    // last; the seeded statuses keep every Open311 mapping covered, so the
    // status integrity guards allow removing the extra ones.
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_jurisdiction', $groupId)
      ->execute();
    $extra = array_filter(
      $tids ? $termStorage->loadMultiple($tids) : [],
      static fn($term): bool => !isset($owned['taxonomy_term'][$term->uuid()]),
    );
    uasort($extra, static fn($a, $b): int => ($a->bundle() === 'service_status') <=> ($b->bundle() === 'service_status'));
    foreach ($extra as $term) {
      try {
        $term->delete();
        $removed['taxonomy_term']++;
      }
      catch (\Throwable $e) {
        $this->logger->warning('Demo reset of @slug kept term @tid: @msg', [
          '@slug' => $seed->data['slug'],
          '@tid' => $term->id(),
          '@msg' => $e->getMessage(),
        ]);
      }
    }

    return $removed;
  }

  /**
   * Deletes uploads of the workspace that never became part of a report.
   *
   * A photo is uploaded before its report exists. Uploads the visitor then
   * abandons stay as unpublished media without any report. They count as
   * workspace content when markaspot_vision bound them to this workspace at
   * upload, no report revision references them, the seed does not own them,
   * and they are older than ORPHAN_UPLOAD_GRACE.
   *
   * @param int $groupId
   *   The workspace.
   * @param array<string, int> $ownedMedia
   *   Seeded media UUIDs as keys.
   * @param array<string, int> $removed
   *   The reset counters, updated in place.
   */
  private function purgeOrphanUploads(int $groupId, array $ownedMedia, array &$removed): void {
    $fieldStorage = $this->entityFieldManager->getFieldStorageDefinitions('node')['field_request_media'] ?? NULL;
    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    if ($fieldStorage === NULL || !$nodeStorage instanceof SqlEntityStorageInterface || !$mediaStorage instanceof SqlEntityStorageInterface) {
      return;
    }
    $nodeMapping = $nodeStorage->getTableMapping();
    $referenceTable = $nodeMapping->getDedicatedRevisionTableName($fieldStorage);
    $referenceColumn = $nodeMapping->getFieldColumnName($fieldStorage, 'target_id');
    $mediaType = $this->entityTypeManager->getDefinition('media');

    // Request images no report revision references, on any workspace. The
    // binding store then tells which of them were uploaded for this one.
    $query = $this->database->select($mediaType->getBaseTable(), 'm');
    $query->fields('m', ['mid', 'uuid']);
    $query->innerJoin($mediaType->getDataTable(), 'd', 'd.mid = m.mid AND d.default_langcode = 1');
    $query->leftJoin($referenceTable, 'r', 'r.' . $referenceColumn . ' = m.mid');
    $query->condition('m.bundle', 'request_image')
      ->condition('d.created', $this->time->getRequestTime() - self::ORPHAN_UPLOAD_GRACE, '<')
      ->isNull('r.entity_id');
    $orphans = array_diff_key($query->execute()->fetchAllKeyed(1, 0), $ownedMedia);
    if ($orphans === []) {
      return;
    }
    $bindings = $this->keyValueFactory->get(self::UPLOAD_BINDING_STORE)->getMultiple(array_keys($orphans));
    $ids = [];
    foreach ($bindings as $uuid => $boundTo) {
      if ((is_int($boundTo) || (is_string($boundTo) && ctype_digit($boundTo))) && (int) $boundTo === $groupId) {
        $ids[] = $orphans[$uuid];
      }
    }
    foreach (array_chunk($ids, 50) as $chunk) {
      foreach ($mediaStorage->loadMultiple($chunk) as $media) {
        if ($media instanceof MediaInterface) {
          $removed['file'] += $this->deleteMedia($media);
          $removed['media']++;
        }
      }
    }
  }

  /**
   * Whether any node outside the workspace references a media item.
   *
   * Every revision counts, and nodes without a jurisdiction count as outside.
   */
  private function isReferencedOutside(MediaInterface $media, int $groupId): bool {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('field_request_media.target_id', $media->id());
    $outside = $query->orConditionGroup()
      ->condition('field_jurisdiction', $groupId, '<>')
      ->notExists('field_jurisdiction');
    return $query->condition($outside)->range(0, 1)->execute() !== [];
  }

  /**
   * Drops the revisions dashboard edits added to a seeded node.
   */
  private function deleteOlderRevisions(NodeInterface $node): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $revisionIds = $storage->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('nid', $node->id())
      ->execute();
    foreach (array_keys($revisionIds) as $revisionId) {
      if ((int) $revisionId !== (int) $node->getRevisionId()) {
        $storage->deleteRevision($revisionId);
      }
    }
  }

  /**
   * Returns IDs of paragraphs whose parent is the given node.
   *
   * @return int[]
   *   Paragraph IDs.
   */
  private function childParagraphIds(int $nid): array {
    if (!$this->entityTypeManager->hasDefinition('paragraph')) {
      return [];
    }
    return array_map('intval', array_values($this->entityTypeManager->getStorage('paragraph')->getQuery()
      ->accessCheck(FALSE)
      ->condition('parent_type', 'node')
      ->condition('parent_id', (string) $nid)
      ->execute()));
  }

  /**
   * Deletes paragraphs by ID.
   */
  private function deleteParagraphs(array $paragraphIds): int {
    if ($paragraphIds === []) {
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage('paragraph');
    $paragraphs = $storage->loadMultiple($paragraphIds);
    $storage->delete($paragraphs);
    return count($paragraphs);
  }

  /**
   * Deletes a visitor media item and its files once nothing else uses them.
   *
   * @return int
   *   Number of deleted files.
   */
  private function deleteMedia(MediaInterface $media): int {
    $files = [];
    foreach ($media->getFieldDefinitions() as $name => $definition) {
      if (in_array($definition->getType(), ['image', 'file'], TRUE)) {
        foreach ($media->get($name)->referencedEntities() as $file) {
          if ($file instanceof FileInterface) {
            $files[(int) $file->id()] = $file;
          }
        }
      }
    }
    $media->delete();
    $deleted = 0;
    foreach ($files as $file) {
      if ($this->fileUsage->listUsage($file) === []) {
        $file->delete();
        $deleted++;
      }
    }
    return $deleted;
  }

}
