<?php

namespace Drupal\markaspot_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Powered/Built with Mark-a-Spot block' block.
 *
 * @Block(
 *   id = "Markaspot Built With Block",
 *   admin_label = @Translation("Mark-a-Spot: Built With block")
 * )
 */
class MarkaspotBuiltWithBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a SyndicateBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $configFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);

    $config = $this->getConfiguration();

    $form['logo-invert'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Invert'),
      '#description' => $this->t('Invert Mark-a-Spot logo for dark backgrounds'),
      '#default_value' => $config['logo-invert'] ?? '',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $values = $form_state->getValues();
    $this->configuration['logo-invert'] = $values['logo-invert'];
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    $config = $this->getConfiguration();
    $class = (!empty($config['logo-invert'])) ? ' invert' : ' default';

    $language = $this->configFactory->get('language.negotiation')->get('selected_language');

    $microsite = ($language == "de" || $language == "es") ? $language : 'en';
    $logo = $this->t('Built with <a class="mas" aria-label="Mark-a-Spot" href="@link-to-mas"><span>Mark-a-Spot</span></a>', ['@link-to-mas' => 'http://markaspot.de/' . $microsite]);

    return [
      '#type' => 'markup',
      '#markup' => '
         <div class="built-with' . $class . '">' . $logo . '</div>',
      '#attached' => [
        'library' => [
          'markaspot_blocks/markaspot_blocks',
        ],
      ],
    ];
  }

}
