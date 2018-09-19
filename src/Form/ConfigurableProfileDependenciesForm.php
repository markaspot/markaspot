<?php

namespace Drupal\markaspot\Form;

use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for selecting available modules during install.
 */
class ConfigurableProfileDependenciesForm extends FormBase {

  /**
   * The module installer service.
   *
   * @var \Drupal\Core\Extension\ModuleInstallerInterface
   */
  protected $moduleInstaller;

  /**
   * Constructs a new configurable profile form.
   *
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $module_installer
   *   The module installer service.
   */
  public function __construct(ModuleInstallerInterface $module_installer) {
    $this->moduleInstaller = $module_installer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_installer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'configurable_profile_dependencies';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['#title'] = $this->t('aGov Module Configuration');
    $install_state = $form_state->getBuildInfo()['args'][0];

    // If we have any configurable_dependencies in the profile then show them
    // to the user so they can be selected.
    if (!empty($install_state['profile_info']['configurable_dependencies'])) {
      $form['configurable_modules'] = [
        '#type' => 'container',
        '#tree' => TRUE,
      ];
      foreach ($install_state['profile_info']['configurable_dependencies'] as $module_name => $info) {
        $form['configurable_modules'][$module_name] = [
          '#title' => $info['label'],
          '#description' => !empty($info['description']) ? $info['description'] : '',
          '#type' => 'checkbox',
          '#default_value' => !empty($info['enabled']),
        ];
      }
    }
    else {
      $form['#suffix'] = $this->t('There are no available modules at this time.');
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and continue'),
      '#button_type' => 'primary',
      '#submit' => ['::submitForm'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $modules_to_install = array_filter($form_state->getValue('configurable_modules'), function ($enabled) {
      return (bool) $enabled;
    });
    $this->moduleInstaller->install(array_keys($modules_to_install));
  }

}
