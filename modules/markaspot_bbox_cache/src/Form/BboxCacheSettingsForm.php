<?php

namespace Drupal\markaspot_bbox_cache\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure bbox cache settings.
 */
class BboxCacheSettingsForm extends ConfigFormBase {

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  /**
   * Constructs a BboxCacheSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    CacheBackendInterface $cache
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('cache.markaspot_bbox_cache')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['markaspot_bbox_cache.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_bbox_cache_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_bbox_cache.settings');

    $form['cache_time'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache Time'),
      '#description' => $this->t('How long to cache bbox requests in seconds. Default: 180 (3 minutes).'),
      '#default_value' => $config->get('cache_time') ?? 180,
      '#min' => 30,
      '#max' => 3600,
      '#required' => TRUE,
    ];

    $form['cache_by_zoom'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cache by Zoom Level Groups'),
      '#description' => $this->t('Group zoom levels together for better cache efficiency. This reduces cache fragmentation by grouping similar zoom levels.'),
      '#default_value' => $config->get('cache_by_zoom') ?? FALSE,
    ];

    $form['exclude_params'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Exclude Parameters'),
      '#description' => $this->t('Query parameters to exclude from the cache key (one per line). These parameters will not affect caching.'),
      '#default_value' => implode("\n", $config->get('exclude_params') ?? []),
    ];

    $form['cache_statistics'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Cache Statistics'),
    ];

    $cache_info = $this->getCacheStatistics();

    $form['cache_statistics']['info'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Backend: @backend', ['@backend' => $cache_info['backend']]),
        $this->t('Cached items: @count', ['@count' => $cache_info['count']]),
        $this->t('Cache TTL: @ttl seconds', ['@ttl' => $config->get('cache_time') ?? 180]),
      ],
    ];

    $form['actions']['clear_cache'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear Bbox Cache'),
      '#submit' => ['::clearCache'],
      '#weight' => 10,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $exclude_params = array_filter(
      array_map('trim', explode("\n", $form_state->getValue('exclude_params')))
    );

    $this->config('markaspot_bbox_cache.settings')
      ->set('cache_time', $form_state->getValue('cache_time'))
      ->set('cache_by_zoom', $form_state->getValue('cache_by_zoom'))
      ->set('exclude_params', $exclude_params)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Clear the bbox cache.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function clearCache(array &$form, FormStateInterface $form_state) {
    Cache::invalidateTags(['markaspot_bbox_cache']);
    $this->cache->deleteAll();
    
    $this->messenger->addMessage($this->t('Bbox cache has been cleared.'));
  }

  /**
   * Get cache statistics.
   *
   * @return array
   *   Cache statistics.
   */
  protected function getCacheStatistics(): array {
    $count = 0;
    $backend = get_class($this->cache);

    // For DatabaseBackend, we can count items in the cache table.
    if ($this->cache instanceof \Drupal\Core\Cache\DatabaseBackend) {
      try {
        $count = \Drupal::database()
          ->select('cache_markaspot_bbox_cache', 'c')
          ->countQuery()
          ->execute()
          ->fetchField();
      }
      catch (\Exception $e) {
        $count = 'N/A';
      }
    }

    return [
      'backend' => str_replace('Drupal\\Core\\Cache\\', '', $backend),
      'count' => $count,
    ];
  }

}