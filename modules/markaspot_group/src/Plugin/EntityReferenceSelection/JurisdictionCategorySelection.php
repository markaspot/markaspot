<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\EntityReferenceSelection;

use Drupal\group\Entity\GroupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Plugin\EntityReferenceSelection\TermSelection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters service_category terms by the group's root jurisdiction.
 *
 * When editing a child jurisdiction (one with field_parent_jurisdiction), only
 * service_category terms belonging to the root jurisdiction are shown. When
 * editing a root jurisdiction, its own categories are shown.
 *
 * The jurisdiction is resolved from the group entity being edited, either via
 * the handler configuration (passed by the form) or from the current route.
 */
#[EntityReferenceSelection(
  id: 'jurisdiction_category:taxonomy_term',
  label: new TranslatableMarkup('Jurisdiction Category Selection'),
  entity_types: ['taxonomy_term'],
  group: 'jurisdiction_category',
  weight: 5,
)]
class JurisdictionCategorySelection extends TermSelection {

  /**
   * The jurisdiction hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface
   */
  protected JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * Constructs a JurisdictionCategorySelection object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $hierarchy_resolver
   *   The jurisdiction hierarchy resolver.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    AccountInterface $current_user,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityRepositoryInterface $entity_repository,
    JurisdictionHierarchyResolverInterface $hierarchy_resolver,
    RouteMatchInterface $route_match,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entity_type_manager,
      $module_handler,
      $current_user,
      $entity_field_manager,
      $entity_type_bundle_info,
      $entity_repository,
    );

    $this->hierarchyResolver = $hierarchy_resolver;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('current_user'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity.repository'),
      $container->get('markaspot_group.hierarchy_resolver'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getReferenceableEntities($match = NULL, $match_operator = 'CONTAINS', $limit = 0) {
    $root_jurisdiction_id = $this->resolveRootJurisdictionId();

    // If we cannot determine the jurisdiction, fall back to parent behavior.
    if ($root_jurisdiction_id === NULL) {
      return parent::getReferenceableEntities($match, $match_operator, $limit);
    }

    // When searching or limiting, use the entity query approach.
    if ($match || $limit) {
      return parent::getReferenceableEntities($match, $match_operator, $limit);
    }

    // Load all terms from target bundles filtered by jurisdiction.
    $options = [];
    $bundles = $this->entityTypeBundleInfo->getBundleInfo('taxonomy_term');
    $bundle_names = $this->getConfiguration()['target_bundles'] ?: array_keys($bundles);
    $has_admin_access = $this->currentUser->hasPermission('administer taxonomy');

    foreach ($bundle_names as $bundle) {
      $vocabulary = Vocabulary::load($bundle);
      if (!$vocabulary) {
        continue;
      }

      /** @var \Drupal\taxonomy\TermInterface[] $terms */
      $terms = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadTree($vocabulary->id(), 0, NULL, TRUE);

      if (empty($terms)) {
        continue;
      }

      $unpublished_terms = [];
      foreach ($terms as $term) {
        // Skip unpublished terms for non-admins.
        if (!$has_admin_access && (!$term->isPublished() || in_array($term->parent->target_id, $unpublished_terms))) {
          $unpublished_terms[] = $term->id();
          continue;
        }

        // Filter by jurisdiction: term must have field_jurisdiction pointing
        // to the root jurisdiction.
        if (!$term->hasField('field_jurisdiction') || $term->get('field_jurisdiction')->isEmpty()) {
          continue;
        }

        $term_jurisdiction_id = (int) $term->get('field_jurisdiction')->target_id;
        if ($term_jurisdiction_id !== $root_jurisdiction_id) {
          continue;
        }

        // Use the translated label for display.
        $translated_term = $this->entityRepository->getTranslationFromContext($term);
        $options[$vocabulary->id()][$term->id()] = str_repeat('-', $term->depth) . Html::escape($translated_term->label());
      }
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery($match, $match_operator);

    $root_jurisdiction_id = $this->resolveRootJurisdictionId();
    if ($root_jurisdiction_id !== NULL) {
      $query->condition('field_jurisdiction', $root_jurisdiction_id);
    }

    return $query;
  }

  /**
   * Resolves the root jurisdiction ID for the group being edited.
   *
   * Attempts to find the group entity from:
   * 1. The handler configuration (passed by the entity form).
   * 2. The current route parameter.
   *
   * For child jurisdictions (those with field_parent_jurisdiction), resolves
   * upward to the root. For root jurisdictions, returns the group's own ID.
   *
   * @return int|null
   *   The root jurisdiction group ID, or NULL if it cannot be determined.
   */
  protected function resolveRootJurisdictionId(): ?int {
    $group = $this->getGroupFromContext();
    if (!$group) {
      return NULL;
    }

    $group_id = (int) $group->id();

    // For new (unsaved) groups that have a parent jurisdiction set, resolve
    // the root from the parent.
    if ($group->isNew() && $group->hasField('field_parent_jurisdiction') && !$group->get('field_parent_jurisdiction')->isEmpty()) {
      $parent_id = (int) $group->get('field_parent_jurisdiction')->target_id;
      return $this->hierarchyResolver->getRootJurisdictionId($parent_id);
    }

    // For existing groups, the resolver handles the traversal.
    if (!$group->isNew()) {
      return $this->hierarchyResolver->getRootJurisdictionId($group_id);
    }

    // New group without a parent: cannot determine jurisdiction yet.
    return NULL;
  }

  /**
   * Gets the group entity from handler configuration or route.
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The group entity, or NULL if not available.
   */
  protected function getGroupFromContext(): ?GroupInterface {
    // Try the handler configuration first (set by SelectionPluginManager).
    $configuration = $this->getConfiguration();
    if (!empty($configuration['entity']) && $configuration['entity'] instanceof GroupInterface) {
      $entity = $configuration['entity'];
      if ($entity->bundle() === 'jur') {
        return $entity;
      }
    }

    // Fall back to the route parameter.
    $group = $this->routeMatch->getParameter('group');
    if ($group instanceof GroupInterface && $group->bundle() === 'jur') {
      return $group;
    }

    return NULL;
  }

}
