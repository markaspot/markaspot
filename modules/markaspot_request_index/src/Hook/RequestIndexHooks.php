<?php

declare(strict_types=1);

namespace Drupal\markaspot_request_index\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_request_index\RequestIndexProjector;
use Drupal\markaspot_request_index\RequestIndexRebuilder;
use Drupal\markaspot_request_index\RequestIndexWriter;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the request index in step with service request writes.
 *
 * Node hooks write inside the entity save transaction and project the stored
 * state of the request, re-read from the database, never the entity object
 * of the save: another hook may change that object after the write without
 * saving it. Group relationship writes need no hook of their own: the index
 * reads field_organisation, and Group re-saves the grouped node on every
 * relationship insert and delete, which runs node_update. A failing
 * projection never fails the save: it is logged and queued, and a stale row
 * is only ever a false negative because entity access still runs per row
 * once the index is read.
 */
final class RequestIndexHooks {

  use StringTranslationTrait;

  /**
   * Term fields whose values are copied into index rows.
   */
  private const TERM_FIELDS = ['field_service_code', 'field_open311_mapping'];

  /**
   * Constructs the request index hooks.
   */
  public function __construct(
    private readonly RequestIndexProjector $projector,
    private readonly RequestIndexWriter $writer,
    private readonly RequestIndexRebuilder $rebuilder,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Projects a new service request.
   *
   * The stored state is projected, so hook order does not decide the row.
   * Running last only saves a projection that a resave by another insert
   * hook (markaspot_group_node_insert() derives groups and saves again)
   * would replace anyway.
   */
  #[Hook('node_insert', order: Order::Last)]
  public function nodeInsert(NodeInterface $node): void {
    $this->project($node);
  }

  /**
   * Re-projects an updated service request.
   */
  #[Hook('node_update', order: Order::Last)]
  public function nodeUpdate(NodeInterface $node): void {
    $this->project($node);
  }

  /**
   * Removes a deleted service request from the index.
   */
  #[Hook('node_delete')]
  public function nodeDelete(NodeInterface $node): void {
    if (!$this->projector->applies($node)) {
      return;
    }
    $nid = (int) $node->id();
    $this->guard($nid, function () use ($nid): ?string {
      $this->writer->delete([$nid]);
      return NULL;
    });
  }

  /**
   * Queues re-projections when a group change moves requests in the tree.
   *
   * An organisation's field_jurisdiction move re-stamps its requests through
   * node saves already; the queued pass catches requests that markaspot_group
   * skipped or failed to re-stamp. A reparented jurisdiction changes root_gid
   * for every request in its subtree without any node save.
   */
  #[Hook('group_update')]
  public function groupUpdate(GroupInterface $group): void {
    $original = $group->getOriginal();
    if (!$original instanceof GroupInterface) {
      return;
    }
    if ($group->bundle() === 'org'
      && $this->targetIds($group, 'field_jurisdiction') !== $this->targetIds($original, 'field_jurisdiction')) {
      $this->rebuilder->enqueueReference('organisation', (int) $group->id());
    }
    if ($group->bundle() === $this->jurisdictionGroupType()
      && $this->targetIds($group, 'field_parent_jurisdiction') !== $this->targetIds($original, 'field_parent_jurisdiction')) {
      $this->rebuilder->enqueueReference('jurisdiction', (int) $group->id());
    }
  }

  /**
   * Queues re-projections when a copied term value changes.
   */
  #[Hook('taxonomy_term_update')]
  public function taxonomyTermUpdate(TermInterface $term): void {
    $original = $term->getOriginal();
    if (!$original instanceof TermInterface) {
      return;
    }
    foreach (self::TERM_FIELDS as $field) {
      if ($term->hasField($field) && $original->hasField($field)
        && $term->get($field)->getValue() !== $original->get($field)->getValue()) {
        $this->projector->reset();
        $this->rebuilder->enqueueReference('term', (int) $term->id());
        return;
      }
    }
  }

  /**
   * Reports an empty, incomplete or still rebuilding index.
   *
   * @return array<string, array<string, mixed>>
   *   The status report entry.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $status = $this->rebuilder->status();
    $problems = [];
    if ($status['indexed'] === 0 && $status['requests'] > 0) {
      $problems[] = (string) $this->t('The index is empty.');
    }
    elseif ($status['indexed'] !== $status['requests']) {
      $problems[] = (string) $this->t('@indexed of @requests service requests are indexed.', [
        '@indexed' => $status['indexed'],
        '@requests' => $status['requests'],
      ]);
    }
    if ($status['queued'] > 0) {
      $problems[] = (string) $this->t('@count re-projection batches are queued.', ['@count' => $status['queued']]);
    }

    $requirement = [
      'title' => $this->t('Mark-a-Spot request index'),
      'value' => $problems === []
        ? $this->t('@count service requests indexed', ['@count' => $status['indexed']])
        : implode(' ', $problems),
      'severity' => $problems === [] ? RequirementSeverity::OK : RequirementSeverity::Warning,
    ];
    if ($problems !== []) {
      $requirement['description'] = $this->t('Nothing reads the index yet. Run <code>drush mas:request-index:rebuild</code> to fill it, then <code>drush mas:request-index:verify</code>.');
    }
    return ['markaspot_request_index' => $requirement];
  }

  /**
   * Projects the stored default revision of a service request.
   *
   * A save of a forward (non-default) revision leaves the stored default
   * revision, and with it the row, unchanged.
   */
  private function project(NodeInterface $node): void {
    if (!$this->projector->applies($node) || !$node->isDefaultRevision()) {
      return;
    }
    $nid = (int) $node->id();
    $this->guard($nid, fn(): ?string => $this->writer->reproject([$nid])['failed'][$nid] ?? NULL);
  }

  /**
   * Runs an index write; on failure logs it and queues a retry.
   *
   * The writer rolls back its savepoint, so the node save continues. The
   * retry relies on the queue table that hook_install() created: creating it
   * here would be DDL inside the save transaction. The log line carries no
   * field values (see RequestIndexWriter::describeFailure()).
   *
   * @param int $nid
   *   The node ID.
   * @param callable(): ?string $write
   *   The write; returns a failure description, NULL on success.
   */
  private function guard(int $nid, callable $write): void {
    try {
      $failure = $write();
    }
    catch (\Throwable $throwable) {
      $failure = RequestIndexWriter::describeFailure($throwable);
    }
    if ($failure === NULL) {
      return;
    }
    $this->logger->error('Request index write for node @nid failed and was queued for retry: @failure', [
      '@nid' => $nid,
      '@failure' => $failure,
    ]);
    try {
      $this->rebuilder->enqueueNids([$nid]);
    }
    catch (\Throwable) {
      // The status report and verify surface the stale row.
    }
  }

  /**
   * Returns the sorted target IDs of a group reference field.
   *
   * @return list<int>
   *   Target IDs.
   */
  private function targetIds(FieldableEntityInterface $entity, string $field): array {
    if (!$entity->hasField($field)) {
      return [];
    }
    $ids = array_map('intval', array_column($entity->get($field)->getValue(), 'target_id'));
    sort($ids);
    return $ids;
  }

  /**
   * Returns the jurisdiction group type, as markaspot_group resolves it.
   */
  private function jurisdictionGroupType(): string {
    return (string) ($this->configFactory->get('markaspot_open311.settings')->get('jurisdiction_group_type') ?: 'jur');
  }

}
