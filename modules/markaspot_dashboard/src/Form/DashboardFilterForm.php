<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter form for the dashboard admin report page.
 *
 * Uses GET method so filter values appear as query parameters in the URL,
 * making filtered views bookmarkable and shareable.
 */
class DashboardFilterForm extends FormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a DashboardFilterForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_dashboard_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();

    $form['#method'] = 'get';

    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dashboard-filters']],
    ];

    $form['filters']['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Start date'),
      '#default_value' => $request->query->get('start_date', ''),
    ];

    $form['filters']['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('End date'),
      '#default_value' => $request->query->get('end_date', ''),
    ];

    $form['filters']['jurisdiction_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Jurisdiction'),
      '#options' => $this->getJurisdictionOptions(),
      '#default_value' => $request->query->get('jurisdiction_id', ''),
      '#empty_option' => $this->t('- All jurisdictions -'),
    ];

    $form['filters']['category_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Category'),
      '#options' => $this->getCategoryOptions(),
      '#default_value' => $request->query->get('category_id', ''),
      '#empty_option' => $this->t('- All categories -'),
    ];

    $form['filters']['actions'] = [
      '#type' => 'actions',
    ];

    $form['filters']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
    ];

    // Remove form_build_id, form_id, and form_token for GET forms.
    $form['form_build_id']['#access'] = FALSE;
    $form['form_token']['#access'] = FALSE;
    $form['form_id']['#access'] = FALSE;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // GET forms are handled by the browser. No server-side submit needed.
  }

  /**
   * Loads jurisdiction group options for the select element.
   *
   * @return array
   *   Associative array of group ID => group label.
   */
  protected function getJurisdictionOptions(): array {
    $options = [];

    try {
      $storage = $this->entityTypeManager->getStorage('group');
      $groups = $storage->loadByProperties([
        'type' => $this->jurisdictionGroupType(),
        'status' => 1,
      ]);

      foreach ($groups as $group) {
        $options[$group->id()] = $group->label();
      }
    }
    catch (\Exception $e) {
      // Group module may not be installed. Return empty options.
    }

    asort($options);
    return $options;
  }

  /**
   * Gets the configured jurisdiction group type.
   */
  protected function jurisdictionGroupType(): string {
    $configured = $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type');

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

  /**
   * Loads service category term options for the select element.
   *
   * @return array
   *   Associative array of term ID => term name.
   */
  protected function getCategoryOptions(): array {
    $options = [];

    try {
      $storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $terms = $storage->loadByProperties([
        'vid' => 'service_category',
        'status' => 1,
      ]);

      foreach ($terms as $term) {
        $options[$term->id()] = $term->label();
      }
    }
    catch (\Exception $e) {
      // Vocabulary may not exist. Return empty options.
    }

    asort($options);
    return $options;
  }

}
