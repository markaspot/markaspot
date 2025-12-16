<?php

namespace Drupal\markaspot_emergency\Commands;

use Symfony\Component\HttpFoundation\Request;
use Drush\Commands\DrushCommands;
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
    $this->configFactory = $config_factory;
    $this->emergencyController = $emergency_controller;
  }

  /**
   * Get emergency mode status.
   *
   * @command emergency:status
   * @aliases emer:status
   * @usage emergency:status
   *   Show current emergency mode status.
   */
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
   *
   * @command emergency:activate
   * @aliases emer:on
   * @option mode-type The type of emergency mode (disaster, crisis, maintenance).
   * @usage emergency:activate
   *   Activate emergency mode with default settings.
   * @usage emergency:activate --mode-type=disaster
   *   Activate disaster mode specifically.
   */
  public function activate($options = ['mode-type' => 'disaster']) {
    $config = $this->configFactory->get('markaspot_emergency.settings');

    if ($config->get('emergency_mode.status') === 'active') {
      $this->output()->writeln('Emergency mode is already active.');
      return;
    }

    // Simulate request for controller.
    $request = new Request([], [], [], [], [], [], json_encode([
      'mode_type' => $options['mode-type'],
      'unpublish_categories' => TRUE,
      'create_emergency_categories' => TRUE,
    ]));

    $response = $this->emergencyController->activate($request);
    $data = json_decode($response->getContent(), TRUE);

    if ($data['status'] === 'success') {
      $this->output()->writeln('✅ Emergency mode activated successfully.');
      $this->output()->writeln('Mode Type: ' . $options['mode-type']);
    }
    else {
      $this->output()->writeln('❌ Failed to activate emergency mode.');
    }
  }

  /**
   * Deactivate emergency mode.
   *
   * @command emergency:deactivate
   * @aliases emer:off
   * @option restore-categories Restore regular categories to published state.
   * @usage emergency:deactivate
   *   Deactivate emergency mode and restore regular categories.
   * @usage emergency:deactivate --restore-categories=0
   *   Deactivate without restoring categories.
   */
  public function deactivate($options = ['restore-categories' => TRUE]) {
    $config = $this->configFactory->get('markaspot_emergency.settings');

    if ($config->get('emergency_mode.status') !== 'active') {
      $this->output()->writeln('Emergency mode is not currently active.');
      return;
    }

    // Simulate request for controller.
    $request = new Request([], [], [], [], [], [], json_encode([
      'restore_categories' => $options['restore-categories'],
    ]));

    $response = $this->emergencyController->deactivate($request);
    $data = json_decode($response->getContent(), TRUE);

    if ($data['status'] === 'success') {
      $this->output()->writeln('✅ Emergency mode deactivated successfully.');
      if ($options['restore-categories']) {
        $this->output()->writeln('Regular categories have been restored.');
      }
    }
    else {
      $this->output()->writeln('❌ Failed to deactivate emergency mode.');
    }
  }

}
