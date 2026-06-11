<?php

namespace Drupal\markaspot_emergency\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;

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
   * The emergency mode service.
   *
   * @var \Drupal\markaspot_emergency\Service\EmergencyModeService
   */
  protected EmergencyModeService $emergencyService;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * EmergencyCommands constructor.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EmergencyModeService $emergency_service,
    StateInterface $state,
  ) {
    parent::__construct();
    $this->configFactory = $config_factory;
    $this->emergencyService = $emergency_service;
    $this->state = $state;
  }

  /**
   * Get emergency mode status.
   */
  #[CLI\Command(name: 'markaspot:emergency:status', aliases: ['emergency:status', 'emer:status'])]
  #[CLI\Usage(name: 'markaspot:emergency:status', description: 'Show current emergency mode status.')]
  public function status(): void {
    $status = $this->emergencyService->getStatus();
    $config = $this->configFactory->get('markaspot_emergency.settings');
    $modeType = $config->get('emergency_mode.mode_type');
    $activatedAt = $this->emergencyService->getActivatedAt();

    $this->output()->writeln('Emergency Mode Status: ' . strtoupper($status));

    if ($status === 'active') {
      $this->output()->writeln('Mode Type: ' . $modeType);
      if ($activatedAt) {
        $this->output()->writeln('Activated At: ' . date('Y-m-d H:i:s', $activatedAt));
      }
    }
  }

  /**
   * Activate emergency mode.
   */
  #[CLI\Command(name: 'markaspot:emergency:activate', aliases: ['emergency:activate', 'emer:on'])]
  #[CLI\Option(name: 'mode-type', description: 'The type of emergency mode (disaster, crisis, maintenance).')]
  #[CLI\Option(name: 'no-unpublish', description: 'Skip unpublishing regular categories.')]
  #[CLI\Usage(name: 'markaspot:emergency:activate', description: 'Activate emergency mode with default settings.')]
  #[CLI\Usage(name: 'markaspot:emergency:activate --mode-type=disaster', description: 'Activate disaster mode specifically.')]
  #[CLI\Usage(name: 'markaspot:emergency:activate --mode-type=maintenance', description: 'Activate maintenance mode.')]
  public function activate(array $options = ['mode-type' => 'disaster', 'no-unpublish' => FALSE]): void {
    if ($this->emergencyService->isActive()) {
      $this->output()->writeln('Emergency mode is already active.');
      return;
    }

    $modeType = $options['mode-type'];
    $allowedTypes = ['disaster', 'crisis', 'maintenance'];
    if (!in_array($modeType, $allowedTypes, TRUE)) {
      $this->logger()->error('Invalid --mode-type "@type". Allowed values: disaster, crisis, maintenance.', ['@type' => $modeType]);
      return;
    }

    $unpublish = !$options['no-unpublish'];

    // Read force_redirect from the config key that matches the mode type.
    $config = $this->configFactory->get('markaspot_emergency.settings');
    if ($modeType === 'maintenance') {
      $forceRedirect = (bool) $config->get('maintenance.force_redirect');
    }
    else {
      $forceRedirect = (bool) $config->get('emergency_mode.force_redirect');
    }

    $this->emergencyService->activate(
      modeType: $modeType,
      forceRedirect: $forceRedirect,
      liteUi: TRUE,
      unpublishCategories: $unpublish,
      createEmergencyCategories: TRUE,
    );

    $this->output()->writeln('Emergency mode activated successfully.');
    $this->output()->writeln('Mode Type: ' . $modeType);
    $this->output()->writeln('Activated At: ' . date('Y-m-d H:i:s'));
  }

  /**
   * Deactivate emergency mode.
   */
  #[CLI\Command(name: 'markaspot:emergency:deactivate', aliases: ['emergency:deactivate', 'emer:off'])]
  #[CLI\Option(name: 'restore-categories', description: 'Restore regular categories to published state (default: 1).')]
  #[CLI\Usage(name: 'markaspot:emergency:deactivate', description: 'Deactivate emergency mode and restore regular categories.')]
  #[CLI\Usage(name: 'markaspot:emergency:deactivate --restore-categories=0', description: 'Deactivate without restoring categories.')]
  public function deactivate(array $options = ['restore-categories' => TRUE]): void {
    if (!$this->emergencyService->isActive()) {
      $this->output()->writeln('Emergency mode is not currently active.');
      return;
    }

    $restore = (bool) $options['restore-categories'];

    $this->emergencyService->deactivate(restoreCategories: $restore);

    $this->output()->writeln('Emergency mode deactivated successfully.');
    if ($restore) {
      $this->output()->writeln('Regular categories have been restored.');
    }
    else {
      $this->output()->writeln('Category restore skipped.');
    }
  }

}
