<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\StatusTermScope;
use Drupal\markaspot_group\Service\StatusTermSeedPlanner;
use Drupal\taxonomy\TermInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command for seeding effective statuses between jurisdictions.
 */
class StatusSeedCommands extends DrushCommands {

  /**
   * Fields copied from the source term when they are installed.
   */
  private const COPY_FIELDS = [
    'field_status_hex',
    'field_status_icon',
    'field_open311_mapping',
    'field_notification_key',
    'field_status_definition',
  ];

  /**
   * Constructs a StatusSeedCommands object.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StatusTermScope $statusTermScope,
    protected StatusTermSeedPlanner $planner,
    protected ConfigFactoryInterface $configFactory,
    protected JurisdictionHierarchyResolverInterface $hierarchyResolver,
  ) {
    parent::__construct();
  }

  /**
   * Drush 13 auto-discovery factory.
   *
   * Instantiation happens lazily at command invocation (after any cache
   * rebuild), which avoids the legacy drush.services.yml deploy trap where
   * drush boots command services from the STALE cached container and crashes
   * before `drush cr` can run (see markaspot_archive, commit 4540a9ad).
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('markaspot_group.status_term_scope'),
      $container->get('markaspot_group.status_term_seed_planner'),
      $container->get('config.factory'),
      $container->get('markaspot_group.hierarchy_resolver'),
    );
  }

  /**
   * Seeds service statuses from a parent or explicit jurisdiction.
   *
   * Within one tree it copies only the effective term selection. Across roots
   * it copies terms into the target root. Both modes replace the target's
   * explicit selection with the resulting set. The default is a dry-run.
   *
   * @param int $targetGroupId
   *   Target jurisdiction group ID.
   * @param array<string, mixed> $options
   *   Command options.
   *
   * @option from
   *   Source jurisdiction group ID. Defaults to field_parent_jurisdiction on
   *   the target group.
   * @option apply
   *   Apply the planned selection and term copies. Without this option the
   *   command is a dry-run.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Plan or result rows.
   */
  #[CLI\Command(name: 'markaspot:status-seed', aliases: ['mas:status-seed'])]
  #[CLI\Help(
    description: 'Replace a jurisdiction status selection, copying terms only between roots.',
    synopsis: 'Replace the target selection with a parent or explicit jurisdiction effective status set.',
  )]
  #[CLI\Argument(name: 'targetGroupId', description: 'Target jurisdiction group ID.')]
  #[CLI\Option(
    name: 'from',
    description: 'Source jurisdiction group ID. Defaults to the target field_parent_jurisdiction value.',
  )]
  #[CLI\Option(
    name: 'apply',
    description: 'Replace the target selection and apply copies. Without this option the command is a dry-run.',
  )]
  #[CLI\FieldLabels(labels: [
    'source_tid' => 'Source TID',
    'name' => 'Name',
    'result' => 'Result',
    'target_tid' => 'Target TID',
    'reason' => 'Reason',
  ])]
  #[CLI\Usage(
    name: 'drush markaspot:status-seed 12',
    description: 'Dry-run using group 12 parent jurisdiction as the source.',
  )]
  #[CLI\Usage(
    name: 'drush markaspot:status-seed 12 --from=4 --apply',
    description: 'Seed effective statuses from jurisdiction 4 into group 12.',
  )]
  public function seed(
    int $targetGroupId,
    array $options = [
      'from' => NULL,
      'apply' => FALSE,
    ],
  ): RowsOfFields {
    $groupStorage = $this->entityTypeManager->getStorage('group');
    $target = $groupStorage->load($targetGroupId);
    if (!$target instanceof GroupInterface) {
      throw new \RuntimeException(sprintf('Target group %d does not exist.', $targetGroupId));
    }
    $this->assertJurisdictionGroup($target, 'Target');

    $sourceGroupId = $this->resolveSourceGroupId($target, $options['from'] ?? NULL);
    $source = $groupStorage->load($sourceGroupId);
    if (!$source instanceof GroupInterface) {
      throw new \RuntimeException(sprintf('Source group %d does not exist.', $sourceGroupId));
    }
    $this->assertJurisdictionGroup($source, 'Source');
    $this->assertStatusSelectionField($target);
    if (!$this->statusTermScope->canScope($sourceGroupId)) {
      throw new \RuntimeException(
        'Status terms cannot be jurisdiction-scoped because field_jurisdiction storage is unavailable.',
      );
    }

    $sourceRootId = $this->hierarchyResolver
      ->getRootJurisdictionId($sourceGroupId);
    $targetRootId = $this->hierarchyResolver
      ->getRootJurisdictionId($targetGroupId);
    if ($sourceRootId === NULL || $sourceRootId <= 0
      || $targetRootId === NULL || $targetRootId <= 0) {
      throw new \RuntimeException('Source or target jurisdiction hierarchy is invalid.');
    }

    $sourceTerms = $this->loadStatusTerms($sourceGroupId);
    if ($sourceTerms === []) {
      throw new \RuntimeException(sprintf('Source group %d has no service status terms.', $sourceGroupId));
    }

    $sourceRows = [];
    foreach ($sourceTerms as $term) {
      $sourceRows[] = [
        'source_tid' => (int) $term->id(),
        'name' => (string) $term->getUntranslated()->label(),
      ];
    }

    $apply = !empty($options['apply']);
    if ($sourceRootId === $targetRootId) {
      return $this->seedSelection($target, $sourceRows, $apply);
    }

    return $this->seedCopies(
      $target,
      $sourceTerms,
      $sourceRows,
      $targetRootId,
      $apply,
    );
  }

  /**
   * Applies or reports an in-tree status selection.
   *
   * @param \Drupal\group\Entity\GroupInterface $target
   *   Target jurisdiction group.
   * @param list<array{source_tid: int, name: string}> $sourceRows
   *   Effective source status rows.
   * @param bool $apply
   *   Whether to save the selection.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Dry-run or applied selection rows.
   */
  protected function seedSelection(
    GroupInterface $target,
    array $sourceRows,
    bool $apply,
  ): RowsOfFields {
    $plan = $this->planner->planSelection($sourceRows);
    $replacementIds = array_column($plan, 'target_tid');
    $deselections = $this->planner->planDeselections(
      $this->loadCurrentSelectionRows($target),
      $replacementIds,
    );
    if ($apply) {
      $this->saveSelection($target, $replacementIds);
    }

    $rows = [];
    foreach ($plan as $item) {
      $rows[] = [
        'source_tid' => (string) $item['source_tid'],
        'name' => $item['name'],
        'result' => $apply ? 'selected' : 'would-select',
        'target_tid' => (string) $item['target_tid'],
        'reason' => $item['reason'],
      ];
    }
    $rows = array_merge(
      $rows,
      $this->formatDeselectionRows($deselections, $apply),
    );

    return new RowsOfFields($rows);
  }

  /**
   * Applies or reports cross-root status term copies and target selection.
   *
   * @param \Drupal\group\Entity\GroupInterface $target
   *   Target jurisdiction group.
   * @param \Drupal\taxonomy\TermInterface[] $sourceTerms
   *   Effective source terms keyed by term ID.
   * @param list<array{source_tid: int, name: string}> $sourceRows
   *   Source plan rows.
   * @param int $targetRootId
   *   Target root jurisdiction group ID.
   * @param bool $apply
   *   Whether to create terms and save the target selection.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Dry-run or applied copy rows.
   */
  protected function seedCopies(
    GroupInterface $target,
    array $sourceTerms,
    array $sourceRows,
    int $targetRootId,
    bool $apply,
  ): RowsOfFields {
    $currentSelection = $this->loadCurrentSelectionRows($target);
    $targetTerms = $this->loadTreePoolStatusTerms((int) $target->id());
    $targetRows = [];
    foreach ($targetTerms as $term) {
      $targetRows[] = [
        'target_tid' => (int) $term->id(),
        'name' => (string) $term->getUntranslated()->label(),
      ];
    }
    $plan = $this->planner->planCopies($sourceRows, $targetRows);

    $termsById = [];
    foreach ($sourceTerms as $term) {
      $termsById[(int) $term->id()] = $term;
    }
    $targetTermsById = [];
    foreach ($targetTerms as $term) {
      $targetTermsById[(int) $term->id()] = $term;
    }
    $this->assertCollisionSemantics(
      $plan,
      $termsById,
      $targetTermsById,
    );

    $rows = [];
    $selectedTargetIds = [];
    foreach ($plan as $item) {
      $targetTid = $item['target_tid'];
      if ($item['action'] === 'skip') {
        $result = $apply
          ? 'skipped-copy-and-selected'
          : 'would-skip-copy-and-select';
        if ($apply && $targetTid !== NULL) {
          $selectedTargetIds[] = $targetTid;
        }
      }
      elseif (!$apply) {
        $result = 'would-create-and-select';
      }
      else {
        $copy = $this->createCopy(
          $termsById[$item['source_tid']],
          $targetRootId,
        );
        $targetTid = (int) $copy->id();
        $selectedTargetIds[] = $targetTid;
        $result = 'created-and-selected';
      }

      $rows[] = [
        'source_tid' => (string) $item['source_tid'],
        'name' => $item['name'],
        'result' => $result,
        'target_tid' => $targetTid === NULL ? '' : (string) $targetTid,
        'reason' => $item['reason'],
      ];
    }

    $replacementIds = $apply
      ? $selectedTargetIds
      : array_values(array_filter(
        array_column($plan, 'target_tid'),
        static fn(?int $termId): bool => $termId !== NULL,
      ));
    $deselections = $this->planner->planDeselections(
      $currentSelection,
      $replacementIds,
    );
    $rows = array_merge(
      $rows,
      $this->formatDeselectionRows($deselections, $apply),
    );

    if ($apply) {
      $this->saveSelection($target, $selectedTargetIds);
    }

    return new RowsOfFields($rows);
  }

  /**
   * Rejects name collisions that would substitute different status semantics.
   *
   * @param list<array<string, mixed>> $plan
   *   Cross-root copy plan.
   * @param array<int, \Drupal\taxonomy\TermInterface> $sourceTerms
   *   Source terms keyed by term ID.
   * @param array<int, \Drupal\taxonomy\TermInterface> $targetTerms
   *   Target root terms keyed by term ID.
   */
  protected function assertCollisionSemantics(
    array $plan,
    array $sourceTerms,
    array $targetTerms,
  ): void {
    foreach ($plan as $item) {
      if ($item['action'] !== 'skip') {
        continue;
      }

      $source = $sourceTerms[$item['source_tid']] ?? NULL;
      $match = $item['target_tid'] !== NULL
        ? ($targetTerms[$item['target_tid']] ?? NULL)
        : ($sourceTerms[$item['match_source_tid']] ?? NULL);
      if (!$source instanceof TermInterface || !$match instanceof TermInterface) {
        throw new \LogicException('A planned status name collision could not be resolved.');
      }

      if ($this->termSemantics($source) !== $this->termSemantics($match)) {
        throw new \RuntimeException(sprintf(
          'Cannot seed "%s": a same-name status has different field or translation semantics.',
          $item['name'],
        ));
      }
    }
  }

  /**
   * Builds the status semantics copied or reused across roots.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   Source or target status term.
   *
   * @return array<string, mixed>
   *   Comparable base fields, copied fields, and translation values.
   */
  protected function termSemantics(TermInterface $term): array {
    $default = $term->getUntranslated();
    if (!$default instanceof TermInterface) {
      throw new \LogicException('The status default translation is not a taxonomy term.');
    }

    $translations = [];
    foreach ($term->getTranslationLanguages(FALSE) as $langcode => $language) {
      $translation = $term->getTranslation($langcode);
      if (!$translation instanceof TermInterface) {
        throw new \LogicException(sprintf(
          'The %s status translation is not a taxonomy term.',
          $langcode,
        ));
      }
      $translations[$language->getId()] = [
        'name' => $translation->label(),
        'fields' => $this->copyFieldValues($translation, TRUE),
      ];
    }
    ksort($translations);

    return [
      'status' => $default->isPublished(),
      'weight' => $default->getWeight(),
      'fields' => $this->copyFieldValues($default),
      'translations' => $translations,
    ];
  }

  /**
   * Loads the target's current explicit status selection for change reporting.
   *
   * @param \Drupal\group\Entity\GroupInterface $target
   *   Target jurisdiction group.
   *
   * @return list<array{target_tid: int, name: string}>
   *   Selected terms in stable term-ID order.
   */
  protected function loadCurrentSelectionRows(GroupInterface $target): array {
    $termIds = [];
    foreach ($target->get('field_service_statuses')->getValue() as $item) {
      $termId = (int) ($item['target_id'] ?? 0);
      if ($termId > 0) {
        $termIds[$termId] = $termId;
      }
    }
    ksort($termIds, SORT_NUMERIC);

    $terms = $termIds === []
      ? []
      : $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadMultiple($termIds);
    $rows = [];
    foreach ($termIds as $termId) {
      $term = $terms[$termId] ?? NULL;
      $rows[] = [
        'target_tid' => $termId,
        'name' => $term instanceof TermInterface
          ? (string) $term->getUntranslated()->label()
          : sprintf('Missing term %d', $termId),
      ];
    }

    return $rows;
  }

  /**
   * Formats target terms removed by replacement selection.
   *
   * @param list<array{target_tid: int, name: string, action: string, reason: string}> $deselections
   *   Planned deselections.
   * @param bool $apply
   *   Whether the replacement is being applied.
   *
   * @return list<array<string, string>>
   *   Operator-facing output rows.
   */
  protected function formatDeselectionRows(
    array $deselections,
    bool $apply,
  ): array {
    return array_map(
      static fn(array $item): array => [
        'source_tid' => '',
        'name' => $item['name'],
        'result' => $apply ? 'deselected' : 'would-deselect',
        'target_tid' => (string) $item['target_tid'],
        'reason' => $item['reason'],
      ],
      $deselections,
    );
  }

  /**
   * Ensures the target can store a status selection.
   *
   * @param \Drupal\group\Entity\GroupInterface $target
   *   Target jurisdiction group.
   */
  protected function assertStatusSelectionField(GroupInterface $target): void {
    if (!$target->hasField('field_service_statuses')) {
      throw new \RuntimeException(sprintf(
        'Target group %d does not have field_service_statuses. Run database updates first.',
        $target->id(),
      ));
    }
  }

  /**
   * Replaces the target's explicit service status selection.
   *
   * @param \Drupal\group\Entity\GroupInterface $target
   *   Target jurisdiction group.
   * @param int[] $termIds
   *   Selected root-owned term IDs.
   */
  protected function saveSelection(GroupInterface $target, array $termIds): void {
    $termIds = array_values(array_unique(array_filter(
      array_map('intval', $termIds),
      static fn(int $termId): bool => $termId > 0,
    )));
    $target->set(
      'field_service_statuses',
      array_map(
        static fn(int $termId): array => ['target_id' => $termId],
        $termIds,
      ),
    );
    $target->save();
  }

  /**
   * Resolves --from or the target's parent jurisdiction reference.
   *
   * @param \Drupal\group\Entity\GroupInterface $target
   *   Target jurisdiction group.
   * @param mixed $from
   *   Explicit source option value.
   *
   * @return int
   *   Source jurisdiction group ID.
   */
  protected function resolveSourceGroupId(GroupInterface $target, mixed $from): int {
    if ($from !== NULL && $from !== '') {
      if (!is_numeric($from) || (int) $from <= 0) {
        throw new \RuntimeException('--from must be a positive group ID.');
      }
      return (int) $from;
    }

    if (!$target->hasField('field_parent_jurisdiction')
      || $target->get('field_parent_jurisdiction')->isEmpty()) {
      throw new \RuntimeException(sprintf(
        'Target group %d has no parent jurisdiction. Supply --from=<group-id>.',
        $target->id(),
      ));
    }

    $sourceGroupId = (int) $target->get('field_parent_jurisdiction')->target_id;
    if ($sourceGroupId <= 0) {
      throw new \RuntimeException(sprintf(
        'Target group %d has no valid parent jurisdiction. Supply --from=<group-id>.',
        $target->id(),
      ));
    }
    return $sourceGroupId;
  }

  /**
   * Rejects organisation or other group IDs before status ownership is written.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Candidate jurisdiction group.
   * @param string $role
   *   Operator-facing source or target label.
   */
  protected function assertJurisdictionGroup(GroupInterface $group, string $role): void {
    $configuredType = $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type');
    $jurisdictionType = is_string($configuredType) && $configuredType !== ''
      ? $configuredType
      : 'jur';
    if ($group->bundle() !== $jurisdictionType) {
      throw new \RuntimeException(sprintf(
        '%s group %d is type %s, not the configured jurisdiction type %s.',
        $role,
        $group->id(),
        $group->bundle(),
        $jurisdictionType,
      ));
    }
  }

  /**
   * Loads a jurisdiction's status terms in stable term-ID order.
   *
   * @param int $jurisdictionId
   *   Jurisdiction group ID.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   Status terms keyed by term ID.
   */
  protected function loadStatusTerms(int $jurisdictionId): array {
    $terms = array_filter(
      $this->statusTermScope->loadByProperties(
        ['vid' => 'service_status'],
        $jurisdictionId,
      ),
      static fn($term): bool => $term instanceof TermInterface,
    );
    ksort($terms, SORT_NUMERIC);
    return $terms;
  }

  /**
   * Loads all status terms owned by a jurisdiction's root.
   *
   * @param int $jurisdictionId
   *   Jurisdiction group ID.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   Root pool terms keyed by term ID.
   */
  protected function loadTreePoolStatusTerms(int $jurisdictionId): array {
    $terms = array_filter(
      $this->statusTermScope->loadTreePoolByProperties(
        ['vid' => 'service_status'],
        $jurisdictionId,
      ),
      static fn($term): bool => $term instanceof TermInterface,
    );
    ksort($terms, SORT_NUMERIC);
    return $terms;
  }

  /**
   * Creates one status term copy including installed translated fields.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   Source status term.
   * @param int $targetRootId
   *   Target root jurisdiction group ID.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   Saved target status term.
   */
  protected function createCopy(TermInterface $source, int $targetRootId): TermInterface {
    $sourceDefault = $source->getUntranslated();
    if (!$sourceDefault instanceof TermInterface) {
      throw new \LogicException('The source default translation is not a taxonomy term.');
    }
    $values = [
      'vid' => 'service_status',
      'langcode' => $sourceDefault->language()->getId(),
      'name' => $sourceDefault->label(),
      'status' => $sourceDefault->isPublished(),
      'weight' => $sourceDefault->getWeight(),
      'description' => $sourceDefault->get('description')->getValue(),
      'field_jurisdiction' => ['target_id' => $targetRootId],
    ] + $this->copyFieldValues($sourceDefault);

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $copy = $storage->create($values);
    if (!$copy instanceof TermInterface) {
      throw new \LogicException('Taxonomy term storage did not create a term entity.');
    }

    foreach ($source->getTranslationLanguages(FALSE) as $langcode => $language) {
      $translation = $source->getTranslation($langcode);
      if (!$translation instanceof TermInterface) {
        throw new \LogicException(sprintf('The %s source translation is not a taxonomy term.', $langcode));
      }
      $translationValues = [
        'name' => $translation->label(),
      ] + $this->copyFieldValues($translation, TRUE);
      $copy->addTranslation($language->getId(), $translationValues);
    }

    $copy->save();
    return $copy;
  }

  /**
   * Copies installed field values, limiting translations to translatable data.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   Source default or translated status term.
   * @param bool $translationOnly
   *   TRUE to copy only translatable fields.
   *
   * @return array<string, array<int, array<string, mixed>>>
   *   Entity create or translation values.
   */
  protected function copyFieldValues(TermInterface $source, bool $translationOnly = FALSE): array {
    $values = [];
    foreach (self::COPY_FIELDS as $fieldName) {
      if (!$source->hasField($fieldName)) {
        continue;
      }
      $field = $source->get($fieldName);
      if ($translationOnly && !$field->getFieldDefinition()->isTranslatable()) {
        continue;
      }
      if (!$field->isEmpty()) {
        $values[$fieldName] = $field->getValue();
      }
    }
    return $values;
  }

}
