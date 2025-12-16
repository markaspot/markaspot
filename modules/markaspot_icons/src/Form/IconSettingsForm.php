<?php

namespace Drupal\markaspot_icons\Form;

use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Mark-a-Spot icon settings.
 */
class IconSettingsForm extends ConfigFormBase {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a IconSettingsForm object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, ModuleHandlerInterface $module_handler) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['markaspot_icons.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_icons_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_icons.settings');

    $form['icon_system'] = [
      '#type' => 'radios',
      '#title' => $this->t('Icon Field System'),
      '#description' => $this->t('Choose which icon field system to use for categories and status terms.'),
      '#default_value' => $config->get('icon_system') ?? 'fa_icon_class',
      '#options' => $this->getIconSystemOptions(),
      '#required' => TRUE,
    ];

    if ($this->moduleHandler->moduleExists('iconify_field')) {
      $form['iconify_collections'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Available Icon Collections'),
        '#description' => $this->t('Select which icon collections should be available in the Iconify picker.'),
        '#default_value' => $config->get('iconify_collections') ?? ['heroicons', 'lucide', 'fa6-solid'],
        '#options' => [
          'heroicons' => $this->t('Heroicons (Default for Nuxt UI)'),
          'lucide' => $this->t('Lucide Icons'),
          'fa6-solid' => $this->t('FontAwesome 6 Solid'),
          'fa6-regular' => $this->t('FontAwesome 6 Regular'),
          'tabler' => $this->t('Tabler Icons'),
          'phosphor' => $this->t('Phosphor Icons'),
        ],
        '#states' => [
          'visible' => [
            ':input[name="icon_system"]' => ['value' => 'iconify_field'],
          ],
        ],
      ];

      $form['default_collection'] = [
        '#type' => 'select',
        '#title' => $this->t('Default Icon Collection'),
        '#description' => $this->t('The default collection to show first in the icon picker.'),
        '#default_value' => $config->get('default_collection') ?? 'heroicons',
        '#options' => [
          'heroicons' => $this->t('Heroicons'),
          'lucide' => $this->t('Lucide Icons'),
          'fa6-solid' => $this->t('FontAwesome 6 Solid'),
        ],
        '#states' => [
          'visible' => [
            ':input[name="icon_system"]' => ['value' => 'iconify_field'],
          ],
        ],
      ];
    }

    $form['migration'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Migration Tools'),
      '#description' => $this->t('Tools to migrate between different icon field systems.'),
    ];

    $form['migration']['migrate_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Migrate Icon Data'),
      '#url' => Url::fromRoute('markaspot_icons.migrate'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Gets available icon system options.
   */
  protected function getIconSystemOptions() {
    $options = [];

    if ($this->moduleHandler->moduleExists('fa_icon_class')) {
      $options['fa_icon_class'] = $this->t('FontAwesome Icon Class (Legacy)');
    }

    if ($this->moduleHandler->moduleExists('iconify_field')) {
      $options['iconify_field'] = $this->t('Iconify Field (Modern)');
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_icons.settings');
    $config->set('icon_system', $form_state->getValue('icon_system'));

    if ($form_state->getValue('iconify_collections')) {
      $config->set('iconify_collections', array_filter($form_state->getValue('iconify_collections')));
    }

    if ($form_state->getValue('default_collection')) {
      $config->set('default_collection', $form_state->getValue('default_collection'));
    }

    $config->save();

    $this->messenger()->addMessage($this->t('Icon settings have been saved.'));

    parent::submitForm($form, $form_state);
  }

}
