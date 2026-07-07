<?php

declare(strict_types=1);

namespace Drupal\markaspot_archive\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\markaspot_archive\ArchiveServiceInterface;
use Drupal\node\NodeInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for archive operations.
 */
final class ArchiveCommands extends DrushCommands {

  /**
   * Revision log used for staff-triggered GDPR anonymization.
   */
  private const AUDIT_REVISION_LOG = 'Reporter contact data anonymized ' .
    '(GDPR request via drush).';

  /**
   * Optional dashboard service storing split request links.
   */
  private const REQUEST_LINK_SERVICE = 'markaspot_dashboard.request_link';

  public function __construct(
    protected readonly ArchiveServiceInterface $archiveService,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LoggerChannelInterface $archiveLogger,
    protected readonly Connection $database,
    protected readonly ?object $requestLinkService,
  ) {
    parent::__construct();
  }

  /**
   * Instantiates the command via Drush command discovery.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('markaspot_archive.archive'),
      $container->get('config.factory'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory')->get('markaspot_archive'),
      $container->get('database'),
      $container->has(self::REQUEST_LINK_SERVICE)
        ? $container->get(self::REQUEST_LINK_SERVICE) : NULL,
    );
  }

  /**
   * Shows service requests that would be archived.
   */
  #[CLI\Command(
    name: 'markaspot:archive:preview',
    aliases: ['mas:archive-preview'],
  )]
  #[CLI\Option(
    name: 'all',
    description: 'List all archive candidates instead of the cron window.',
  )]
  #[CLI\Usage(
    name: 'markaspot:archive:preview',
    description: 'Preview archive candidates for the normal cron window.',
  )]
  #[CLI\Usage(
    name: 'markaspot:archive:preview --all',
    description: 'Preview all archive candidates without cron caps.'
  )]
  public function preview(array $options = ['all' => FALSE]): void {
    $all = (bool) ($options['all'] ?? FALSE);
    $nodes = $this->archiveService->load($all, FALSE, FALSE);
    $config = $this->configFactory->get('markaspot_archive.settings');
    $anonymize_fields = [];
    if ((bool) $config->get('anonymize')) {
      $anonymize_fields = $this->archiveService->normalizeConfiguredFields(
        (array) $config->get('anonymize_fields')
      );
    }

    $rows = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $retention = $this->archiveService->getArchiveRetention($node);
      $fields = $this->archiveService->previewAnonymizeFields(
        $node,
        $anonymize_fields
      );
      $rows[] = [
        'nid' => $node->id(),
        'request_id' => $this->fieldValue($node, 'request_id'),
        'category' => $this->referencedLabel($node, 'field_category'),
        'status' => $this->referencedLabel($node, 'field_status'),
        'changed' => $this->dateFormatter->format(
          $node->getChangedTime(),
          'custom',
          'Y-m-d H:i:s'
        ),
        'retention' => sprintf(
          '%d days (%s)',
          $retention['days'],
          $retention['source']
        ),
        'fields' => implode(', ', $fields),
      ];
    }

    $this->io()->table([
      'nid',
      'request_id',
      'category',
      'status',
      'changed',
      'retention',
      'fields',
    ], $rows);
  }

  /**
   * Anonymizes reporter contact data for one or more service requests.
   */
  #[CLI\Command(
    name: 'markaspot:anonymize',
    aliases: ['mas:anonymize'],
  )]
  #[CLI\Argument(
    name: 'nid',
    description: 'Node ID of one service_request to anonymize.',
  )]
  #[CLI\Option(
    name: 'by-mail',
    description: 'Match nodes by exact field_e_mail value.',
  )]
  #[CLI\Option(
    name: 'jurisdiction',
    description: 'Limit --by-mail matches to a field_jurisdiction ID.',
  )]
  #[CLI\Option(
    name: 'dry-run',
    description: 'Print fields that would be anonymized without saving.',
  )]
  #[CLI\Option(
    name: 'no-cascade',
    description: 'Do not anonymize linked split requests.',
  )]
  #[CLI\FieldLabels(labels: [
    'nid' => 'nid',
    'request_id' => 'request_id',
    'fields' => 'fields',
  ])]
  #[CLI\Usage(
    name: 'markaspot:anonymize 123',
    description: 'Anonymize reporter contact fields on node 123.',
  )]
  #[CLI\Usage(
    name: 'markaspot:anonymize --by-mail=citizen@example.org',
    description: 'Anonymize all exact field_e_mail matches.',
  )]
  public function anonymize(
    ?int $nid = NULL,
    array $options = [
      'by-mail' => NULL,
      'jurisdiction' => NULL,
      'dry-run' => FALSE,
      'no-cascade' => FALSE,
    ],
  ): ?RowsOfFields {
    $fields = $this->configuredAnonymizeFields();
    $base_nids = $this->targetNodeIds($nid, $options);
    $cascade = empty($options['no-cascade']);
    $target_nids = $this->expandWithLinkedRequests($base_nids, $cascade);
    $dry_run = !empty($options['dry-run']);

    if ($dry_run) {
      return new RowsOfFields($this->previewRows($target_nids, $fields));
    }

    if ($base_nids === []) {
      $this->archiveLogger->notice('No matching service requests found.');
      return NULL;
    }

    if (!empty($options['by-mail']) && !$this->confirmByMailAnonymization(
      count($base_nids),
      count($target_nids)
    )) {
      $this->archiveLogger->notice('Anonymization aborted by operator.');
      return NULL;
    }

    $nodes = $this->entityTypeManager
      ->getStorage('node')
      ->loadMultiple($target_nids);

    $anonymized = 0;
    $unchanged = 0;
    foreach ($target_nids as $target_nid) {
      $node = $nodes[$target_nid] ?? NULL;
      if (!$node instanceof NodeInterface) {
        $this->archiveLogger->warning(
          'Node ID @nid could not be loaded for anonymization.',
          ['@nid' => $target_nid]
        );
        continue;
      }
      if ($node->bundle() !== 'service_request') {
        $this->archiveLogger->warning(
          'Node ID @nid is not a service_request; skipping.',
          ['@nid' => $target_nid]
        );
        continue;
      }

      if ($this->anonymizeNode($node, $fields)) {
        $anonymized++;
      }
      else {
        $unchanged++;
      }
    }

    $this->archiveLogger->notice('@count requests anonymized.', [
      '@count' => $anonymized,
    ]);
    if ($unchanged > 0) {
      $this->archiveLogger->notice(
        '@count requests had no configured reporter contact data to ' .
        'anonymize.',
        ['@count' => $unchanged]
      );
    }
    return NULL;
  }

  /**
   * Returns configured anonymize fields or fails before any writes.
   *
   * @return array<string, string>
   *   Field machine names keyed by field machine name.
   */
  private function configuredAnonymizeFields(): array {
    $config = $this->configFactory->get('markaspot_archive.settings');
    $fields = $this->archiveService->normalizeConfiguredFields(
      (array) $config->get('anonymize_fields')
    );
    if ($fields === []) {
      throw new \RuntimeException(
        'No anonymize fields are configured. Configure ' .
        'markaspot_archive.settings:anonymize_fields before running this ' .
        'command.'
      );
    }
    return $fields;
  }

  /**
   * Resolves the command target node IDs.
   *
   * @param int|null $nid
   *   Optional node ID argument.
   * @param array<string, mixed> $options
   *   Command options.
   *
   * @return int[]
   *   Target node IDs.
   */
  private function targetNodeIds(?int $nid, array $options): array {
    $by_mail = $options['by-mail'] ?? NULL;
    $jurisdiction = $options['jurisdiction'] ?? NULL;
    if ($by_mail !== NULL && $by_mail !== '') {
      if ($nid !== NULL) {
        throw new \InvalidArgumentException(
          'Pass either <nid> or --by-mail, not both.'
        );
      }
      return $this->loadByMailTargetIds((string) $by_mail, $jurisdiction);
    }

    if ($jurisdiction !== NULL && $jurisdiction !== '') {
      throw new \InvalidArgumentException(
        '--jurisdiction can only be used together with --by-mail.'
      );
    }
    if ($nid === NULL) {
      throw new \InvalidArgumentException(
        'Pass a service_request node ID or --by-mail=EMAIL.'
      );
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      throw new \InvalidArgumentException(sprintf(
        'Node ID %d was not found.',
        $nid
      ));
    }
    if ($node->bundle() !== 'service_request') {
      throw new \InvalidArgumentException(sprintf(
        'Node ID %d is not a service_request.',
        $nid
      ));
    }
    return [$nid];
  }

  /**
   * Loads service request IDs matching one exact reporter email value.
   *
   * @param string $email
   *   Exact field_e_mail value to match.
   * @param mixed $jurisdiction
   *   Optional field_jurisdiction target ID.
   *
   * @return int[]
   *   Matching node IDs.
   */
  private function loadByMailTargetIds(
    string $email,
    mixed $jurisdiction,
  ): array {
    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('field_e_mail', $email);

    if ($jurisdiction !== NULL && $jurisdiction !== '') {
      $jurisdiction_id = filter_var(
        $jurisdiction,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
      );
      if ($jurisdiction_id === FALSE) {
        throw new \InvalidArgumentException(
          '--jurisdiction must be a positive integer.'
        );
      }
      $query->condition('field_jurisdiction', (int) $jurisdiction_id);
    }

    return array_map('intval', array_values($query->execute()));
  }

  /**
   * Adds linked split requests unless the operator disabled the cascade.
   *
   * @param int[] $nids
   *   Initial target node IDs.
   * @param bool $cascade
   *   TRUE to resolve linked split requests.
   *
   * @return int[]
   *   Initial and linked node IDs.
   */
  private function expandWithLinkedRequests(array $nids, bool $cascade): array {
    $unique_nids = [];
    foreach ($nids as $nid) {
      $unique_nids[(int) $nid] = (int) $nid;
    }
    if (!$cascade || $unique_nids === []) {
      return array_values($unique_nids);
    }

    $request_link_service = $this->requestLinkService;
    if ($request_link_service === NULL) {
      $this->archiveLogger->notice(
        'Split cascade skipped; request link service is not available.'
      );
      return array_values($unique_nids);
    }

    if (!method_exists($request_link_service, 'getLinksForNode')) {
      $this->archiveLogger->notice(
        'Split cascade skipped; request link service cannot load links.'
      );
      return array_values($unique_nids);
    }

    $expanded = [];
    foreach ($unique_nids as $nid) {
      foreach (self::resolveLinkedNodeIds(
        $nid,
        static function (int $linked_nid) use ($request_link_service): array {
          return $request_link_service->getLinksForNode($linked_nid);
        }
      ) as $linked_nid) {
        $expanded[$linked_nid] = $linked_nid;
      }
    }

    return array_values($expanded);
  }

  /**
   * Walks split links in both directions and protects against cycles.
   *
   * @param int $start_nid
   *   The first node ID.
   * @param callable $links_for_node
   *   Callable returning rows with source_nid and target_nid.
   *
   * @return int[]
   *   Reached node IDs including the start node.
   */
  private static function resolveLinkedNodeIds(
    int $start_nid,
    callable $links_for_node,
  ): array {
    $visited = [$start_nid => $start_nid];
    $queue = [$start_nid];
    while ($queue !== []) {
      $current_nid = array_shift($queue);
      foreach ($links_for_node($current_nid) as $row) {
        foreach (['source_nid', 'target_nid'] as $key) {
          if (!isset($row[$key])) {
            continue;
          }
          $next_nid = (int) $row[$key];
          if ($next_nid <= 0 || isset($visited[$next_nid])) {
            continue;
          }
          $visited[$next_nid] = $next_nid;
          $queue[] = $next_nid;
        }
      }
    }

    return array_values($visited);
  }

  /**
   * Confirms a by-mail write operation without logging the email address.
   */
  private function confirmByMailAnonymization(
    int $matching_count,
    int $target_count,
  ): bool {
    $linked_count = max(0, $target_count - $matching_count);
    $message = sprintf(
      'Anonymize %d matching service request(s)',
      $matching_count
    );
    if ($linked_count > 0) {
      $message .= sprintf(
        ' and %d linked split request(s)',
        $linked_count
      );
    }
    return $this->io()->confirm($message . '?', FALSE);
  }

  /**
   * Builds dry-run rows without exposing field values.
   *
   * @param int[] $target_nids
   *   Target node IDs.
   * @param array<string, string> $fields
   *   Configured anonymize fields.
   *
   * @return array<int, array{nid: int, request_id: string, fields: string}>
   *   Rows for Drush table output.
   */
  private function previewRows(array $target_nids, array $fields): array {
    $nodes = $this->entityTypeManager
      ->getStorage('node')
      ->loadMultiple($target_nids);

    $rows = [];
    foreach ($target_nids as $target_nid) {
      $node = $nodes[$target_nid] ?? NULL;
      if (!$node instanceof NodeInterface) {
        continue;
      }
      if ($node->bundle() !== 'service_request') {
        continue;
      }
      $rows[] = [
        'nid' => (int) $node->id(),
        'request_id' => $this->fieldValue($node, 'request_id'),
        'fields' => implode(
          ', ',
          $this->archiveService->previewAnonymizeFields($node, $fields)
        ),
      ];
    }
    return $rows;
  }

  /**
   * Anonymizes one service request and its historic revisions.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Service request node.
   * @param array<string, string> $fields
   *   Configured anonymize fields.
   *
   * @return bool
   *   TRUE when at least one configured field was anonymized.
   */
  private function anonymizeNode(NodeInterface $node, array $fields): bool {
    $transaction = $this->database->startTransaction();
    try {
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage(self::AUDIT_REVISION_LOG);

      $anonymized_values = $this->archiveService->anonymize($node, $fields);
      $node->save();

      $revision_rows = $this->archiveService->anonymizeRevisions(
        $node,
        $anonymized_values
      );
      unset($transaction);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->archiveLogger->error(
        'Node ID @nid reporter contact data anonymization failed: @error',
        [
          '@nid' => (int) $node->id(),
          '@error' => $e->getMessage(),
        ]
      );
      throw $e;
    }

    if ($anonymized_values === []) {
      $this->archiveLogger->notice(
        'Node ID @nid had no configured reporter contact data to anonymize.',
        ['@nid' => (int) $node->id()]
      );
      return FALSE;
    }

    if ($revision_rows > 0) {
      $this->archiveLogger->notice(
        'Node ID @nid previous revisions anonymized in @rows field rows.',
        [
          '@nid' => (int) $node->id(),
          '@rows' => $revision_rows,
        ]
      );
    }

    $this->archiveLogger->notice(
      'Node ID @nid reporter contact data anonymized.',
      ['@nid' => (int) $node->id()]
    );
    return TRUE;
  }

  /**
   * Returns a string field value without exposing configured PII fields.
   */
  private function fieldValue(NodeInterface $node, string $field_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    return (string) $node->get($field_name)->value;
  }

  /**
   * Returns the label of an entity reference field.
   */
  private function referencedLabel(
    NodeInterface $node,
    string $field_name,
  ): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    $entity = $node->get($field_name)->entity;
    return $entity instanceof EntityInterface ? $entity->label() : '';
  }

}
