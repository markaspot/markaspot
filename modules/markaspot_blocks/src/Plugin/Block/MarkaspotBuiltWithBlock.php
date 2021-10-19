<?php

namespace Drupal\markaspot_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a 'Powered/Built with Mark-a-Spot block' block.
 *
 * @Block(
 *   id = "Markaspot Built With Block",
 *   admin_label = @Translation("Mark-a-Spot: Built With block")
 * )
 */
class MarkaspotBuiltWithBlock extends BlockBase implements BlockPluginInterface {

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
      '#default_value' => isset($config['logo-invert']) ? $config['logo-invert'] : '',
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

    $language = \Drupal::config('language.negotiation')
      ->get('selected_langcode');
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
