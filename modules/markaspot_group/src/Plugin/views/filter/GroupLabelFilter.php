<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\views\filter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\JurisdictionScopeValidator;
use Drupal\search_api\Plugin\views\filter\SearchApiNumeric;
use Drupal\views\Attribute\ViewsFilter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Search API numeric filter with labelled Group options.
 *
 * The option list is scoped to the Groups the account may view, but this is a
 * usability aid, not an access boundary: the underlying management view is not
 * tenant-isolated (node access is flat), so a hand-crafted request with a
 * foreign Group id still filters. Tenant isolation on SaaS is enforced by
 * keeping the Drupal management surface admin-only (ManagementAccessGate),
 * while tenant staff use the Nuxt dashboard. Do not treat these options as a
 * security boundary.
 */
#[ViewsFilter('markaspot_group_label')]
final class GroupLabelFilter extends SearchApiNumeric implements ContainerFactoryPluginInterface {

  /**
   * Constructs a Group label filter.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly JurisdictionScopeValidator $jurisdictionScopeValidator,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('markaspot_group.jurisdiction_scope_validator'),
      $container->get('markaspot_group.hierarchy_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    $options['group_bundle'] = ['default' => 'org'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);
    $form['group_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Group type'),
      '#options' => [
        'org' => $this->t('Organisation'),
        'jur' => $this->t('Jurisdiction'),
      ],
      '#default_value' => $this->options['group_bundle'],
      '#required' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // The exposed options are scoped per account (group memberships), so the
    // rendered form must never be shared across users via dynamic page cache.
    $contexts = parent::getCacheContexts();
    $contexts[] = 'user';
    return $contexts;
  }

  /**
   * {@inheritdoc}
   */
  public function acceptExposedInput($input) {
    $identifier = $this->options['expose']['identifier'] ?? '';
    if ($identifier !== '' && array_key_exists($identifier, $input)) {
      $raw = $input[$identifier];
      if ($raw === 'All' || $raw === '' || $raw === NULL) {
        return FALSE;
      }
    }
    return parent::acceptExposedInput($input);
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state): void {
    parent::valueForm($form, $form_state);

    if (isset($form['value']['value']) && is_array($form['value']['value'])) {
      $element = &$form['value']['value'];
    }
    else {
      $element = &$form['value'];
    }

    $element['#type'] = 'select';
    $element['#options'] = $this->groupOptions();
    unset($element['#size'], $element['#states']);
    if ($form_state->get('exposed')) {
      // Views prepends the "All" choice via exposedTranslate(); an empty
      // string default would fail select validation on GET requests.
      if (($element['#default_value'] ?? '') === '') {
        $element['#default_value'] = 'All';
      }
      // NumericFilter::valueForm() injects its empty default into the user
      // input when the identifier is absent; normalize it to the "All"
      // choice so select validation accepts requests without parameters.
      $identifier = $this->options['expose']['identifier'] ?? '';
      $user_input = $form_state->getUserInput();
      if ($identifier !== '' && ($user_input[$identifier] ?? NULL) === '') {
        $user_input[$identifier] = 'All';
        $form_state->setUserInput($user_input);
      }
    }
  }

  /**
   * Builds options from labels of the configured Group bundle.
   *
   * @return array<int|string, string>
   *   Group IDs keyed to labels.
   */
  private function groupOptions(): array {
    $groupType = (string) ($this->options['group_bundle'] ?? 'org');
    $groups = $this->entityTypeManager
      ->getStorage('group')
      ->loadByProperties(['type' => $groupType]);
    $hasGlobalScope = $this->currentUser->hasPermission('bypass node access');
    [$allowedJurisdictions, $allowedRoots] = $hasGlobalScope
      ? [[], []]
      : $this->allowedJurisdictionScope();

    $options = [];
    foreach ($groups as $group) {
      if (!$group instanceof GroupInterface
        || !$group->access('view', $this->currentUser)
        || (!$hasGlobalScope && !$this->isGroupInScope($group, $groupType, $allowedJurisdictions, $allowedRoots))) {
        continue;
      }
      $options[$group->id()] = (string) $group->label();
    }
    uasort($options, 'strnatcasecmp');

    return $options;
  }

  /**
   * Resolves directly managed jurisdictions, their descendants, and roots.
   *
   * @return array{0: int[], 1: int[]}
   *   Allowed jurisdiction IDs followed by their root jurisdiction IDs.
   */
  private function allowedJurisdictionScope(): array {
    $jurisdictionIds = [];
    $rootIds = [];
    foreach ($this->jurisdictionScopeValidator->getAllowedJurisdictionIds($this->currentUser) as $jurisdictionId) {
      $jurisdictionIds = array_merge(
        $jurisdictionIds,
        $this->hierarchyResolver->getDescendantIds($jurisdictionId),
      );
      $rootId = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
      if ($rootId !== NULL) {
        $rootIds[] = $rootId;
      }
    }

    return [
      array_values(array_unique(array_map('intval', $jurisdictionIds))),
      array_values(array_unique(array_map('intval', $rootIds))),
    ];
  }

  /**
   * Checks whether a Group belongs to the current account's tenant scope.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The Group entity.
   * @param string $groupType
   *   The configured Group bundle.
   * @param int[] $allowedJurisdictions
   *   Directly managed jurisdiction IDs and their descendants.
   * @param int[] $allowedRoots
   *   Root IDs for the directly managed jurisdictions.
   */
  private function isGroupInScope(GroupInterface $group, string $groupType, array $allowedJurisdictions, array $allowedRoots): bool {
    if ($groupType === 'jur') {
      return in_array((int) $group->id(), $allowedJurisdictions, TRUE);
    }

    if (!$group->hasField('field_jurisdiction')) {
      return FALSE;
    }
    $jurisdictionField = $group->get('field_jurisdiction');
    if ($jurisdictionField->isEmpty()) {
      return FALSE;
    }
    $values = $jurisdictionField->getValue();

    return in_array(
      (int) ($values[0]['target_id'] ?? 0),
      $allowedRoots,
      TRUE,
    );
  }

}
