<?php

namespace Drupal\markaspot_emergency\Commands;

use Symfony\Component\HttpFoundation\Request;
use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\markaspot_emergency\Controller\EmergencyModeController;

/**
 * Drush commands for emergency mode operations.
 */
class EmergencyCommands extends DrushCommands {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The emergency mode controller.
   *
   * @var \Drupal\markaspot_emergency\Controller\EmergencyModeController
   */
  protected $emergencyController;

  /**
   * EmergencyCommands constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EmergencyModeController $emergency_controller) {
    parent::__construct();
    $this->configFactory = $config_factory;
    $this->emergencyController = $emergency_controller;
  }

  /**
   * Get emergency mode status.
   */
  #[CLI\Command(name: 'emergency:status', aliases: ['emer:status'])]
  #[CLI\Usage(name: 'emergency:status', description: 'Show current emergency mode status.')]
  public function status() {
    $config = $this->configFactory->get('markaspot_emergency.settings');
    $status = $config->get('emergency_mode.status');
    $mode_type = $config->get('emergency_mode.mode_type');
    $activated_at = $config->get('emergency_mode.activated_at');

    $this->output()->writeln('Emergency Mode Status: ' . strtoupper($status));

    if ($status === 'active') {
      $this->output()->writeln('Mode Type: ' . $mode_type);
      if ($activated_at) {
        $this->output()->writeln('Activated At: ' . date('Y-m-d H:i:s', $activated_at));
      }
    }
  }

  /**
   * Activate emergency mode.
   */
  #[CLI\Command(name: 'emergency:activate', aliases: ['emer:on'])]
  #[CLI\Option(name: 'mode-type', description: 'The type of emergency mode (disaster, crisis, maintenance).')]
  #[CLI\Usage(name: 'emergency:activate', description: 'Activate emergency mode with default settings.')]
  #[CLI\Usage(name: 'emergency:activate --mode-type=disaster', description: 'Activate disaster mode specifically.')]
  public function activate($options = ['mode-type' => 'disaster']) {
    $config = $this->configFactory->get('markaspot_emergency.settings');

    if ($config->get('emergency_mode.status') === 'active') {
      $this->output()->writeln('Emergency mode is already active.');
      return;
    }

    // Call controller without request to bypass permission check (CLI is trusted).
    // Controller will use default values for category handling.
    $this->emergencyController->activate(NULL);

    // Update mode type if specified (controller uses 'disaster' default).
    if ($options['mode-type'] !== 'disaster') {
      $this->configFactory->getEditable('markaspot_emergency.settings')
        ->set('emergency_mode.mode_type', $options['mode-type'])
        ->save();
    }

    $this->output()->writeln('✅ Emergency mode activated successfully.');
    $this->output()->writeln('Mode Type: ' . $options['mode-type']);
    $this->output()->writeln('Activated At: ' . date('Y-m-d H:i:s'));
  }

  /**
   * Deactivate emergency mode.
   */
  #[CLI\Command(name: 'emergency:deactivate', aliases: ['emer:off'])]
  #[CLI\Option(name: 'restore-categories', description: 'Restore regular categories to published state.')]
  #[CLI\Usage(name: 'emergency:deactivate', description: 'Deactivate emergency mode and restore regular categories.')]
  #[CLI\Usage(name: 'emergency:deactivate --restore-categories=0', description: 'Deactivate without restoring categories.')]
  public function deactivate($options = ['restore-categories' => TRUE]) {
    $config = $this->configFactory->get('markaspot_emergency.settings');

    if ($config->get('emergency_mode.status') !== 'active') {
      $this->output()->writeln('Emergency mode is not currently active.');
      return;
    }

    // Call controller without request to bypass permission check (CLI is trusted).
    // Pass NULL so controller skips HTTP permission check but still does category work.
    $this->emergencyController->deactivate(NULL);

    $this->output()->writeln('✅ Emergency mode deactivated successfully.');
    if ($options['restore-categories']) {
      $this->output()->writeln('Regular categories have been restored.');
    }
  }

}
