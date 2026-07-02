<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Splits a service request into a new, linked sibling request.
 */
final class SplitRequestService implements SplitRequestServiceInterface {

  use JurisdictionIdResolverTrait;

  /**
   * Fields copied from source to child regardless of copy_reporter.
   */
  private const COPIED_FIELDS = [
    'field_jurisdiction',
    'field_geolocation',
    'field_address',
    'field_approved',
  ];

  /**
   * Reporter contact fields copied only when copy_reporter is TRUE.
   *
   * Field_gdpr travels with the reporter's PII so the consent state the
   * citizen gave stays attached to the contact data on the child.
   */
  private const REPORTER_FIELDS = [
    'field_e_mail',
    'field_first_name',
    'field_last_name',
    'field_phone',
    'field_gdpr',
  ];

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly GeoreportProcessorServiceInterface $georeportProcessor,
    private readonly RequestLinkServiceInterface $requestLinkService,
    private readonly LoggerInterface $logger,
    protected readonly ConfigFactoryInterface $configFactory,
    private readonly ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolveJurisdictionForNode(NodeInterface $node): ?int {
    $relationshipStorage = $this->entityTypeManager->getStorage('group_relationship');
    $relationships = $relationshipStorage->loadByProperties([
      'entity_id' => $node->id(),
      'plugin_id' => 'group_node:service_request',
    ]);
    foreach ($relationships as $relationship) {
      $group = $relationship->getGroup();
      if ($this->isJurisdictionGroup($group)) {
        return (int) $group->id();
      }
    }

    if ($node->hasField('field_jurisdiction') && !$node->get('field_jurisdiction')->isEmpty()) {
      return (int) $node->get('field_jurisdiction')->target_id;
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isCategoryInJurisdiction(int $categoryTid, int $jurisdictionId): bool {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($categoryTid);
    if (!$term instanceof TermInterface || $term->bundle() !== 'service_category') {
      return FALSE;
    }
    if (!$term->hasField('field_jurisdiction') || $term->get('field_jurisdiction')->isEmpty()) {
      return FALSE;
    }

    // Category terms are always stored on the ROOT jurisdiction (invariant
    // enforced by presave; GeoreportProcessorService's status-notes
    // serializer relies on the same assumption), while $jurisdictionId is
    // the source node's own jurisdiction, which may be a more specific
    // CHILD jurisdiction (boundary-matched on insert). Resolve up to the
    // root before comparing so child-jurisdiction tenants are not rejected.
    $rootJurisdictionId = $this->hierarchyResolver?->getRootJurisdictionId($jurisdictionId) ?? $jurisdictionId;

    return (int) $term->get('field_jurisdiction')->target_id === $rootJurisdictionId;
  }

  /**
   * {@inheritdoc}
   */
  public function split(NodeInterface $source, array $payload, AccountInterface $account): array {
    $childLangcode = $source->language()->getId();
    $originalRequestId = $this->requestId($source);

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    /** @var \Drupal\node\NodeInterface $child */
    $child = $nodeStorage->create([
      'type' => 'service_request',
      'langcode' => $childLangcode,
      'title' => $payload['title'],
      'uid' => $account->id(),
    ]);

    foreach (self::COPIED_FIELDS as $field) {
      if ($source->hasField($field) && $child->hasField($field)) {
        $child->set($field, $source->get($field)->getValue());
      }
    }

    if (!empty($payload['copy_reporter'])) {
      foreach (self::REPORTER_FIELDS as $field) {
        if ($source->hasField($field) && $child->hasField($field)) {
          $child->set($field, $source->get($field)->getValue());
        }
      }
    }

    if ($child->hasField('field_request_media')) {
      $mediaValues = array_map(
        static fn(int $mid): array => ['target_id' => $mid],
        $payload['media_ids']
      );
      $child->set('field_request_media', $mediaValues);
    }

    if ($child->hasField('body')) {
      $child->set('body', [
        'value' => $payload['description'],
        'format' => 'plain_text',
      ]);
    }
    if ($child->hasField('field_category')) {
      $child->set('field_category', ['target_id' => $payload['category_tid']]);
    }
    if ($child->hasField('field_notification')) {
      $child->set('field_notification', (bool) $payload['notify_citizen']);
    }

    // Pre-populate the provenance note BEFORE the first save: field_status is
    // deliberately left empty here (markaspot_group's presave assigns the
    // jurisdiction-initial status), and a non-empty field_status_notes
    // suppresses service_request_node_presave's generic auto-note. The
    // provenance text then renders via [node:initial_status_note] in the
    // tenant's create-confirmation mail.
    if ($child->hasField('field_status_notes')) {
      $paragraph = $this->georeportProcessor->createStatusNoteParagraph([
        'note' => self::childProvenanceNote($childLangcode, $originalRequestId),
        'author_id' => $account->id(),
      ], $childLangcode);
      $child->set('field_status_notes', [
        [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ],
      ]);
    }

    $child->save();

    // Append a provenance note to the original. The term is the CURRENT
    // status (no status change), so this does not trigger a citizen mail on
    // the original — the child's own create-mail is the citizen-facing
    // signal for the split.
    $originalLangcode = $source->language()->getId();
    $childRequestId = $this->requestId($child);
    if ($source->hasField('field_status_notes')) {
      $currentStatusTid = $source->hasField('field_status') && !$source->get('field_status')->isEmpty()
        ? (int) $source->get('field_status')->target_id
        : NULL;
      $paragraph = $this->georeportProcessor->createStatusNoteParagraph([
        'status_term_id' => $currentStatusTid,
        'note' => self::originalProvenanceNote($originalLangcode, $childRequestId),
        'author_id' => $account->id(),
      ], $originalLangcode);
      $notes = $source->get('field_status_notes')->getValue();
      $notes[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
      $source->set('field_status_notes', $notes);
    }
    $source->save();

    $this->requestLinkService->storeLink((int) $source->id(), (int) $child->id(), (int) $account->id());

    $this->logger->notice('User @uid split service request @source into @child.', [
      '@uid' => $account->id(),
      '@source' => $source->id(),
      '@child' => $child->id(),
    ]);

    return ['child' => $child, 'original' => $source];
  }

  /**
   * Checks whether every requested media ID belongs to the source's media.
   *
   * @param int[] $requestedMediaIds
   *   The media IDs requested by the payload.
   * @param int[] $sourceMediaIds
   *   The source node's own field_request_media target IDs.
   *
   * @return bool
   *   TRUE when $requestedMediaIds is a subset of $sourceMediaIds.
   */
  public static function isMediaSubset(array $requestedMediaIds, array $sourceMediaIds): bool {
    return array_diff($requestedMediaIds, $sourceMediaIds) === [];
  }

  /**
   * Builds the child's provenance status note text.
   */
  public static function childProvenanceNote(string $langcode, string $originalRequestId): string {
    return $langcode === 'de'
      ? "Aus Meldung #{$originalRequestId} abgetrennt."
      : "Split from report #{$originalRequestId}.";
  }

  /**
   * Builds the original's provenance status note text.
   */
  public static function originalProvenanceNote(string $langcode, string $childRequestId): string {
    return $langcode === 'de'
      ? "Anliegen als Meldung #{$childRequestId} abgetrennt."
      : "Concern split off as report #{$childRequestId}.";
  }

  /**
   * Gets a node's citizen-facing request_id, falling back to its nid.
   */
  private function requestId(NodeInterface $node): string {
    if ($node->hasField('request_id') && !$node->get('request_id')->isEmpty()) {
      return (string) $node->get('request_id')->value;
    }
    return (string) $node->id();
  }

}
