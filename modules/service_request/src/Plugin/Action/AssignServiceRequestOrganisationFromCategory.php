<?php

namespace Drupal\service_request\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Assigns a service request organisation from the selected category.
 *
 * @Action(
 *   id = "service_request_assign_organisation_from_category",
 *   label = @Translation("Assign service request organisation from category"),
 *   type = "node"
 * )
 */
class AssignServiceRequestOrganisationFromCategory extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

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
      'category_field' => 'field_category',
      'category_group_field' => 'field_category_gid',
      'jurisdiction_field' => 'field_jurisdiction',
      'content_plugin' => 'group_node:service_request',
      'organisation_group_type' => '',
      'overwrite_existing' => FALSE,
      'sync_relationship' => TRUE,
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
    $form['category_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Category field'),
      '#default_value' => $this->configuration['category_field'],
      '#required' => TRUE,
    ];
    $form['category_group_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Category organisation field'),
      '#default_value' => $this->configuration['category_group_field'],
      '#required' => TRUE,
    ];
    $form['jurisdiction_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Jurisdiction field'),
      '#default_value' => $this->configuration['jurisdiction_field'],
    ];
    $form['content_plugin'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Group content plugin'),
      '#default_value' => $this->configuration['content_plugin'],
      '#required' => TRUE,
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
    $form['sync_relationship'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create the group relationship'),
      '#default_value' => (bool) $this->configuration['sync_relationship'],
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
      'category_field',
      'category_group_field',
      'jurisdiction_field',
      'content_plugin',
      'organisation_group_type',
    ] as $key) {
      $this->configuration[$key] = $form_state->getValue($key);
    }
    foreach (['overwrite_existing', 'sync_relationship', 'save_entity'] as $key) {
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

    $field_mutated = FALSE;
    $current_group_ids = $this->getReferencedGroupIds($entity, $organisation_field);
    $organisation_bundles = $this->getOrganisationBundles($entity, $organisation_field);
    if ($current_group_ids && empty($this->configuration['overwrite_existing'])) {
      if ($this->shouldSyncRelationships($field_mutated)) {
        $this->syncRelationships($entity, $current_group_ids, $organisation_bundles);
      }
      return;
    }

    $group_id = $this->deriveOrganisationGroupId($entity, $organisation_bundles);
    if (!$group_id) {
      if (!empty($this->configuration['overwrite_existing']) && $current_group_ids) {
        $entity->set($organisation_field, NULL);
        $field_mutated = TRUE;
        if (!empty($this->configuration['save_entity']) && $entity->id()) {
          $this->saveNode($entity);
        }
      }
      if ($this->shouldSyncRelationships($field_mutated)) {
        $this->syncRelationships($entity, [], $organisation_bundles);
      }
      return;
    }

    $entity->set($organisation_field, [['target_id' => $group_id]]);
    $field_mutated = TRUE;

    if (!empty($this->configuration['save_entity']) && $entity->id()) {
      $this->saveNode($entity);
    }

    if ($this->shouldSyncRelationships($field_mutated)) {
      $this->syncRelationships($entity, [$group_id], $organisation_bundles);
    }
  }

  /**
   * Derives the organisation group ID for the service request category.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param string[] $organisation_bundles
   *   Accepted group bundle IDs.
   */
  protected function deriveOrganisationGroupId(NodeInterface $node, array $organisation_bundles): ?int {
    $category_field = (string) $this->configuration['category_field'];
    if (!$node->hasField($category_field) || $node->get($category_field)->isEmpty()) {
      return NULL;
    }

    $term = $node->get($category_field)->entity;
    if (!$term instanceof TermInterface) {
      return NULL;
    }

    $jurisdiction_id = $this->getJurisdictionId($node);
    $category_id = (int) $term->id();
    if ($jurisdiction_id) {
      $jurisdiction_group_id = $this->deriveJurisdictionOrganisationGroupId($category_id, $jurisdiction_id, $organisation_bundles);
      if ($jurisdiction_group_id) {
        return $jurisdiction_group_id;
      }
    }

    $mapped_group_id = $this->getCategoryMappedGroupId($term);
    if (!$mapped_group_id) {
      return NULL;
    }

    $group = $this->loadGroup($mapped_group_id);
    if (!$this->isOrganisationGroup($group, $organisation_bundles)) {
      $this->logger->warning('Category term @tid maps to invalid organisation group @gid.', [
        '@tid' => $category_id,
        '@gid' => $mapped_group_id,
      ]);
      return NULL;
    }

    if (!$this->groupMatchesJurisdiction($group, $node)) {
      $this->logger->warning('Category term @tid maps to organisation group @gid outside service request jurisdiction @jurisdiction.', [
        '@tid' => $category_id,
        '@gid' => $mapped_group_id,
        '@jurisdiction' => $jurisdiction_id ?? 'none',
      ]);
      return NULL;
    }

    return $mapped_group_id;
  }

  /**
   * Derives an organisation by group field_service_categories.
   *
   * @param int $category_id
   *   Service category term ID.
   * @param int $jurisdiction_id
   *   Jurisdiction group ID.
   * @param string[] $organisation_bundles
   *   Accepted group bundle IDs.
   */
  protected function deriveJurisdictionOrganisationGroupId(int $category_id, int $jurisdiction_id, array $organisation_bundles): ?int {
    $group_storage = $this->getGroupStorage();
    if (!$group_storage) {
      return NULL;
    }

    foreach ($organisation_bundles as $bundle) {
      if (!$this->groupBundleHasFields($bundle, ['field_jurisdiction', 'field_service_categories'])) {
        continue;
      }

      try {
        $ids = $group_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', $bundle)
          ->condition('field_jurisdiction', $jurisdiction_id)
          ->condition('field_service_categories', $category_id)
          ->range(0, 2)
          ->execute();
      }
      catch (\Throwable $e) {
        $this->logger->warning('Could not derive organisation for category @tid and jurisdiction @jurisdiction: @message', [
          '@tid' => $category_id,
          '@jurisdiction' => $jurisdiction_id,
          '@message' => $e->getMessage(),
        ]);
        continue;
      }

      $ids = array_values(array_unique(array_map('intval', $ids)));
      if (count($ids) === 1) {
        return reset($ids);
      }
      if (count($ids) > 1) {
        $this->logger->warning('Category @tid maps to multiple organisation groups in jurisdiction @jurisdiction. Skipping automatic assignment.', [
          '@tid' => $category_id,
          '@jurisdiction' => $jurisdiction_id,
        ]);
        return NULL;
      }
    }

    return NULL;
  }

  /**
   * Reads the legacy category-to-group mapping from the category term.
   */
  protected function getCategoryMappedGroupId(TermInterface $term): ?int {
    $field_name = (string) $this->configuration['category_group_field'];
    if (!$term->hasField($field_name) || $term->get($field_name)->isEmpty()) {
      return NULL;
    }

    foreach ($term->get($field_name)->getValue() as $item) {
      $raw_value = $item['target_id'] ?? $item['value'] ?? NULL;
      if ($raw_value === NULL) {
        continue;
      }

      $value = trim((string) $raw_value);
      if ($value === '' || strtolower($value) === 'n/a' || !ctype_digit($value)) {
        continue;
      }

      $group_id = (int) $value;
      if ($group_id > 0) {
        return $group_id;
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
   * Checks whether relationship sync may run for this action execution.
   *
   * If the action changed field_organisation but save_entity is disabled,
   * relationship hooks may persist the node indirectly. Skip sync there.
   *
   * @param bool $field_mutated
   *   Whether the action changed field_organisation.
   */
  protected function shouldSyncRelationships(bool $field_mutated): bool {
    if (empty($this->configuration['sync_relationship'])) {
      return FALSE;
    }

    return !$field_mutated || !empty($this->configuration['save_entity']);
  }

  /**
   * Makes organisation relationships match the desired group IDs.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param int[] $desired_group_ids
   *   Desired organisation group IDs.
   * @param string[] $organisation_bundles
   *   Accepted group bundle IDs.
   */
  protected function syncRelationships(NodeInterface $node, array $desired_group_ids, array $organisation_bundles): void {
    $desired_group_ids = array_values(array_unique(array_filter(array_map('intval', $desired_group_ids))));
    $this->removeStaleRelationships($node, $desired_group_ids, $organisation_bundles);
    $this->addMissingRelationships($node, $desired_group_ids, $organisation_bundles);
  }

  /**
   * Removes organisation relationships no longer present on the field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param int[] $desired_group_ids
   *   Desired organisation group IDs.
   * @param string[] $organisation_bundles
   *   Accepted group bundle IDs.
   */
  protected function removeStaleRelationships(NodeInterface $node, array $desired_group_ids, array $organisation_bundles): void {
    $relationship_storage = $this->getGroupRelationshipStorage();
    if (!$relationship_storage || !$node->id()) {
      return;
    }

    $relationships = $relationship_storage->loadByProperties([
      'entity_id' => $node->id(),
      'plugin_id' => $this->configuration['content_plugin'],
    ]);

    foreach ($relationships as $relationship) {
      if (!$relationship instanceof EntityInterface || !method_exists($relationship, 'getGroup')) {
        continue;
      }

      $group = $relationship->getGroup();
      if (!$this->isOrganisationGroup($group, $organisation_bundles)) {
        continue;
      }

      $group_id = (int) $group->id();
      if (!in_array($group_id, $desired_group_ids, TRUE) || !$this->groupMatchesJurisdiction($group, $node)) {
        $relationship->delete();
      }
    }
  }

  /**
   * Adds missing group relationships for all assigned organisations.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param int[] $group_ids
   *   The assigned organisation group IDs.
   * @param string[] $organisation_bundles
   *   Accepted group bundle IDs.
   */
  protected function addMissingRelationships(NodeInterface $node, array $group_ids, array $organisation_bundles): void {
    $group_storage = $this->getGroupStorage();
    $relationship_storage = $this->getGroupRelationshipStorage();
    if (!$group_storage || !$relationship_storage || !$node->id()) {
      return;
    }

    foreach ($group_ids as $group_id) {
      $group = $group_storage->load($group_id);
      if (!$this->isOrganisationGroup($group, $organisation_bundles) || !$this->groupMatchesJurisdiction($group, $node)) {
        continue;
      }

      $existing = $relationship_storage->loadByProperties([
        'entity_id' => $node->id(),
        'gid' => $group_id,
        'plugin_id' => $this->configuration['content_plugin'],
      ]);
      if ($existing) {
        continue;
      }

      try {
        if (method_exists($group, 'addRelationship')) {
          $group->addRelationship($node, $this->configuration['content_plugin']);
        }
        elseif (method_exists($group, 'addContent')) {
          $group->addContent($node, $this->configuration['content_plugin']);
        }
      }
      catch (\Throwable $e) {
        $this->logger->error('Could not assign service request @nid to organisation group @gid: @message', [
          '@nid' => $node->id(),
          '@gid' => $group_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Checks whether an entity is an accepted organisation group.
   *
   * @param mixed $group
   *   The loaded entity.
   * @param string[] $organisation_bundles
   *   Accepted group bundle IDs.
   */
  protected function isOrganisationGroup($group, array $organisation_bundles): bool {
    return $group instanceof EntityInterface
      && $group->getEntityTypeId() === 'group'
      && in_array($group->bundle(), $organisation_bundles, TRUE);
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
   * Loads a group entity when the Group module is available.
   */
  protected function loadGroup(int $group_id): ?EntityInterface {
    $group_storage = $this->getGroupStorage();
    if (!$group_storage) {
      return NULL;
    }

    $group = $group_storage->load($group_id);
    return $group instanceof EntityInterface ? $group : NULL;
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
   * Gets group relationship storage when the Group module is available.
   */
  protected function getGroupRelationshipStorage() {
    try {
      return $this->entityTypeManager->getStorage('group_relationship');
    }
    catch (\Throwable $e) {
      $this->logger->warning('Cannot sync service request organisation because the group relationship entity type is unavailable.');
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
