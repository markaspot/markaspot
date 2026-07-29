<?php

namespace Drupal\service_request\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Assigns a service request organisation from the geocoded sublocality.
 *
 * This action mirrors the structure of the category-based assignment action
 * (service_request_assign_organisation_from_category), but derives the
 * organisation group from the node's field_sublocality (Stadtteil) instead of
 * its category. It only resolves and sets field_organisation; creating or
 * reconciling the group relationship is delegated to the chained
 * service_request_sync_organisations action.
 *
 * @todo Several small helpers here are intentionally duplicated from
 *   AssignServiceRequestOrganisationFromCategory to avoid colliding with
 *   in-flight work on that file. Once both actions are stable they should be
 *   consolidated into a shared abstract base class.
 *
 * @Action(
 *   id = "service_request_assign_organisation_from_sublocality",
 *   label = @Translation("Assign service request organisation from sublocality"),
 *   type = "node"
 * )
 */
class AssignServiceRequestOrganisationFromSublocality extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  /**
   * Nodes currently being saved by this action.
   *
   * @var array<int, bool>
   */
  protected static array $savingNodeIds = [];

  /**
   * The optional jurisdiction hierarchy resolver.
   */
  protected ?object $hierarchyResolver;

  /**
   * Constructs the action plugin.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, LoggerInterface $logger, AccountInterface $current_user, ?object $hierarchy_resolver = NULL) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->logger = $logger;
    $this->currentUser = $current_user;
    $this->hierarchyResolver = $hierarchy_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('logger.factory')->get('service_request'),
      $container->get('current_user'),
      $container->has('markaspot_group.hierarchy_resolver')
        ? $container->get('markaspot_group.hierarchy_resolver')
        : NULL
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'organisation_field' => 'field_organisation',
      'sublocality_field' => 'field_sublocality',
      'org_sublocality_field' => 'field_sublocality_terms',
      'jurisdiction_field' => 'field_jurisdiction',
      'organisation_group_type' => '',
      'overwrite_existing' => FALSE,
      'save_entity' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['organisation_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organisation field'),
      '#default_value' => $this->configuration['organisation_field'],
      '#required' => TRUE,
    ];
    $form['sublocality_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sublocality field'),
      '#description' => $this->t('Node field referencing the geocoded sublocality term.'),
      '#default_value' => $this->configuration['sublocality_field'],
      '#required' => TRUE,
    ];
    $form['org_sublocality_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organisation sublocality field'),
      '#description' => $this->t('Group field listing the sublocality terms an organisation handles.'),
      '#default_value' => $this->configuration['org_sublocality_field'],
      '#required' => TRUE,
    ];
    $form['jurisdiction_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Jurisdiction field'),
      '#default_value' => $this->configuration['jurisdiction_field'],
    ];
    $form['organisation_group_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organisation group type'),
      '#default_value' => $this->configuration['organisation_group_type'],
      '#description' => $this->t('Leave empty to use the target bundles from the organisation field.'),
    ];
    $form['overwrite_existing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Overwrite existing organisation values'),
      '#default_value' => (bool) $this->configuration['overwrite_existing'],
    ];
    $form['save_entity'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Save the service request after assignment'),
      '#default_value' => (bool) $this->configuration['save_entity'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    foreach ([
      'organisation_field',
      'sublocality_field',
      'org_sublocality_field',
      'jurisdiction_field',
      'organisation_group_type',
    ] as $key) {
      $this->configuration[$key] = $form_state->getValue($key);
    }
    foreach (['overwrite_existing', 'save_entity'] as $key) {
      $this->configuration[$key] = (bool) $form_state->getValue($key);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'service_request') {
      return;
    }

    $organisation_field = (string) $this->configuration['organisation_field'];
    if (!$entity->hasField($organisation_field)) {
      return;
    }

    $current_group_ids = $this->getReferencedGroupIds($entity, $organisation_field);
    if ($current_group_ids && empty($this->configuration['overwrite_existing'])) {
      // Sticky: respect a manually or previously assigned organisation.
      return;
    }

    $organisation_bundles = $this->getOrganisationBundles($entity, $organisation_field);
    $group_id = $this->deriveOrganisationGroupId($entity, $organisation_bundles);

    if (!$group_id) {
      if (!empty($this->configuration['overwrite_existing']) && $current_group_ids) {
        $entity->set($organisation_field, NULL);
        if (!empty($this->configuration['save_entity']) && $entity->id()) {
          $this->saveNode($entity);
        }
      }
      return;
    }

    $entity->set($organisation_field, [['target_id' => $group_id]]);

    if (!empty($this->configuration['save_entity']) && $entity->id()) {
      $this->saveNode($entity);
    }
  }

  /**
   * Derives the organisation group ID from the service request sublocality.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param string[] $organisation_bundles
   *   Accepted group bundle IDs.
   */
  protected function deriveOrganisationGroupId(NodeInterface $node, array $organisation_bundles): ?int {
    $sublocality_field = (string) $this->configuration['sublocality_field'];
    if (!$node->hasField($sublocality_field) || $node->get($sublocality_field)->isEmpty()) {
      return NULL;
    }

    $sublocality_tid = (int) ($node->get($sublocality_field)->target_id ?? 0);
    if ($sublocality_tid <= 0) {
      return NULL;
    }

    $group_storage = $this->getGroupStorage();
    if (!$group_storage) {
      return NULL;
    }

    $jurisdiction_field = (string) ($this->configuration['jurisdiction_field'] ?? '');
    $org_sublocality_field = (string) $this->configuration['org_sublocality_field'];
    $jurisdiction_id = $this->getJurisdictionId($node);
    if ($jurisdiction_id) {
      return $this->deriveJurisdictionOrganisationGroupId(
        $sublocality_tid,
        $jurisdiction_id,
        $organisation_bundles,
      );
    }

    foreach ($organisation_bundles as $bundle) {
      $required_fields = [$org_sublocality_field];
      if ($jurisdiction_field !== '') {
        $required_fields[] = $jurisdiction_field;
      }
      if (!$this->groupBundleHasFields($bundle, $required_fields)) {
        continue;
      }

      try {
        $query = $group_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', $bundle)
          ->condition('status', TRUE)
          ->condition($org_sublocality_field, $sublocality_tid)
          ->range(0, 2);
        if ($jurisdiction_field !== '' && $jurisdiction_id) {
          $query->condition($jurisdiction_field, $jurisdiction_id);
        }
        $ids = $query->execute();
      }
      catch (\Throwable $e) {
        $this->logger->warning('Could not derive organisation for sublocality @tid and jurisdiction @jurisdiction: @message', [
          '@tid' => $sublocality_tid,
          '@jurisdiction' => $jurisdiction_id ?? 'none',
          '@message' => $e->getMessage(),
        ]);
        continue;
      }

      $ids = array_values(array_unique(array_map('intval', $ids)));
      if (count($ids) === 1) {
        $group_id = reset($ids);
        $group = $group_storage->load($group_id);
        if (!$this->groupMatchesJurisdiction($group, $node)) {
          $this->logger->warning('Sublocality @tid maps to organisation group @gid outside service request jurisdiction @jurisdiction.', [
            '@tid' => $sublocality_tid,
            '@gid' => $group_id,
            '@jurisdiction' => $jurisdiction_id ?? 'none',
          ]);
          return NULL;
        }
        return $group_id;
      }
      if (count($ids) > 1) {
        $this->logger->warning('Sublocality @tid maps to multiple organisation groups in jurisdiction @jurisdiction. Skipping automatic assignment.', [
          '@tid' => $sublocality_tid,
          '@jurisdiction' => $jurisdiction_id ?? 'none',
        ]);
        return NULL;
      }
    }

    return NULL;
  }

  /**
   * Derives an organisation by jurisdiction and sublocality.
   *
   * @param int $sublocality_tid
   *   Sublocality term ID.
   * @param int $jurisdiction_id
   *   Jurisdiction group ID.
   * @param string[] $organisation_bundles
   *   Accepted group bundle IDs.
   */
  protected function deriveJurisdictionOrganisationGroupId(int $sublocality_tid, int $jurisdiction_id, array $organisation_bundles): ?int {
    $group_storage = $this->getGroupStorage();
    if (!$group_storage) {
      return NULL;
    }

    $org_sublocality_field = (string) $this->configuration['org_sublocality_field'];
    $jurisdiction_ids = [$jurisdiction_id];
    if ($this->hierarchyResolver && method_exists($this->hierarchyResolver, 'getAncestorIds')) {
      $jurisdiction_ids = array_merge(
        $jurisdiction_ids,
        $this->hierarchyResolver->getAncestorIds($jurisdiction_id),
      );
    }
    elseif ($this->hierarchyResolver && method_exists($this->hierarchyResolver, 'getRootJurisdictionId')) {
      $root_id = $this->hierarchyResolver->getRootJurisdictionId($jurisdiction_id);
      if ($root_id !== NULL) {
        $jurisdiction_ids[] = $root_id;
      }
    }
    $jurisdiction_ids = array_values(array_unique(array_map('intval', $jurisdiction_ids)));

    foreach ($jurisdiction_ids as $level => $candidate_jurisdiction_id) {
      foreach ($organisation_bundles as $bundle) {
        if (!$this->groupBundleHasFields($bundle, [
          'field_jurisdiction',
          $org_sublocality_field,
        ])) {
          continue;
        }

        try {
          $ids = $group_storage->getQuery()
            ->accessCheck(FALSE)
            ->condition('type', $bundle)
            ->condition('status', TRUE)
            ->condition('field_jurisdiction', $candidate_jurisdiction_id)
            ->condition($org_sublocality_field, $sublocality_tid)
            ->range(0, 2)
            ->execute();
        }
        catch (\Throwable $e) {
          $this->logger->warning('Could not derive organisation for sublocality @tid and jurisdiction @jurisdiction: @message', [
            '@tid' => $sublocality_tid,
            '@jurisdiction' => $candidate_jurisdiction_id,
            '@message' => $e->getMessage(),
          ]);
          continue;
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids !== [] && $level === 0) {
          $group_id = (int) reset($ids);
          if (count($ids) > 1) {
            $this->logger->warning('Sublocality @tid maps to multiple organisation groups in its own jurisdiction @jurisdiction. Using first match @org_id for compatibility.', [
              '@tid' => $sublocality_tid,
              '@jurisdiction' => $candidate_jurisdiction_id,
              '@org_id' => $group_id,
            ]);
          }
          return $group_id;
        }
        if (count($ids) === 1) {
          return (int) reset($ids);
        }
        if (count($ids) > 1) {
          $this->logger->warning('Sublocality @tid maps to multiple organisation groups in jurisdiction @jurisdiction. Skipping automatic assignment.', [
            '@tid' => $sublocality_tid,
            '@jurisdiction' => $candidate_jurisdiction_id,
          ]);
          return NULL;
        }
      }
    }

    return NULL;
  }

  /**
   * Gets referenced organisation group IDs from a node field.
   *
   * @return int[]
   *   The referenced group IDs.
   */
  protected function getReferencedGroupIds(NodeInterface $node, string $field_name): array {
    $group_ids = [];
    foreach ($node->get($field_name)->getValue() as $item) {
      if (!empty($item['target_id'])) {
        $group_ids[] = (int) $item['target_id'];
      }
    }
    return array_values(array_unique($group_ids));
  }

  /**
   * Gets the organisation group bundles accepted by this action.
   *
   * @return string[]
   *   The accepted group bundle IDs.
   */
  protected function getOrganisationBundles(NodeInterface $node, string $field_name): array {
    $configured = trim((string) ($this->configuration['organisation_group_type'] ?? ''));
    if ($configured !== '') {
      return [$configured];
    }

    $handler_settings = $node->getFieldDefinition($field_name)->getSetting('handler_settings') ?: [];
    $target_bundles = $handler_settings['target_bundles'] ?? [];
    if (is_array($target_bundles) && $target_bundles) {
      return array_values(array_unique(array_filter(array_map('strval', array_values($target_bundles)))));
    }

    return ['organisation', 'org'];
  }

  /**
   * Gets the service request jurisdiction ID.
   */
  protected function getJurisdictionId(NodeInterface $node): ?int {
    $jurisdiction_field = (string) ($this->configuration['jurisdiction_field'] ?? '');
    if ($jurisdiction_field === '' || !$node->hasField($jurisdiction_field) || $node->get($jurisdiction_field)->isEmpty()) {
      return NULL;
    }

    $target_id = (int) ($node->get($jurisdiction_field)->target_id ?? 0);
    return $target_id > 0 ? $target_id : NULL;
  }

  /**
   * Checks whether the group belongs to the service request jurisdiction.
   *
   * Old single-tenant projects do not have field_jurisdiction on organisation
   * groups. In that shape there is no tenant boundary to enforce here.
   *
   * @param mixed $group
   *   The loaded group entity.
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   */
  protected function groupMatchesJurisdiction($group, NodeInterface $node): bool {
    if (!$group instanceof FieldableEntityInterface) {
      return FALSE;
    }

    $node_has_jurisdiction_field = $this->nodeHasJurisdictionField($node);
    $group_has_jurisdiction_field = $group->hasField('field_jurisdiction');

    if (!$node_has_jurisdiction_field && !$group_has_jurisdiction_field) {
      return TRUE;
    }
    if (!$node_has_jurisdiction_field || !$group_has_jurisdiction_field) {
      return FALSE;
    }

    $jurisdiction_id = $this->getJurisdictionId($node);
    if (!$jurisdiction_id || $group->get('field_jurisdiction')->isEmpty()) {
      return FALSE;
    }

    $group_jurisdiction_id = (int) $group->get('field_jurisdiction')->target_id;
    if ($group_jurisdiction_id === $jurisdiction_id) {
      return TRUE;
    }

    if ($this->hierarchyResolver && method_exists($this->hierarchyResolver, 'getRootJurisdictionId')) {
      $root_jurisdiction_id = $this->hierarchyResolver->getRootJurisdictionId($jurisdiction_id);
      return $root_jurisdiction_id !== NULL && $group_jurisdiction_id === $root_jurisdiction_id;
    }

    return FALSE;
  }

  /**
   * Checks whether the service request exposes a jurisdiction field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   */
  protected function nodeHasJurisdictionField(NodeInterface $node): bool {
    $jurisdiction_field = (string) ($this->configuration['jurisdiction_field'] ?? '');
    return $jurisdiction_field !== '' && $node->hasField($jurisdiction_field);
  }

  /**
   * Checks whether the group bundle exposes the required fields.
   *
   * @param string $bundle
   *   The group bundle ID.
   * @param string[] $field_names
   *   Field names to check.
   */
  protected function groupBundleHasFields(string $bundle, array $field_names): bool {
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('group', $bundle);
    foreach ($field_names as $field_name) {
      if (!isset($field_definitions[$field_name])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Saves the node without re-entering this action for the same node.
   */
  protected function saveNode(NodeInterface $node): void {
    $node_id = (int) $node->id();
    if ($node_id <= 0 || !empty(static::$savingNodeIds[$node_id])) {
      return;
    }

    static::$savingNodeIds[$node_id] = TRUE;
    try {
      $node->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not save service request @nid after assigning organisation: @message', [
        '@nid' => $node_id,
        '@message' => $e->getMessage(),
      ]);
    }
    finally {
      unset(static::$savingNodeIds[$node_id]);
    }
  }

  /**
   * Gets group entity storage when the Group module is available.
   */
  protected function getGroupStorage() {
    try {
      return $this->entityTypeManager->getStorage('group');
    }
    catch (\Throwable $e) {
      $this->logger->warning('Cannot assign service request organisation because the group entity type is unavailable.');
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account ??= $this->currentUser;
    $organisation_field = (string) $this->configuration['organisation_field'];

    if (!$object instanceof NodeInterface || $object->bundle() !== 'service_request' || !$object->hasField($organisation_field)) {
      $result = AccessResult::forbidden();
      return $return_as_object ? $result : $result->isAllowed();
    }

    $result = $object->access('update', $account, TRUE)
      ->andIf($object->get($organisation_field)->access('edit', $account, TRUE));
    return $return_as_object ? $result : $result->isAllowed();
  }

}
