<?php

namespace Drupal\markaspot_emergency\Commands;

use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;

/**
 * Drush commands for emergency mode operations.
 */
class EmergencyCommands extends DrushCommands {

  /**
   * The emergency mode service.
   *
   * @var \Drupal\markaspot_emergency\Service\EmergencyModeService
   */
  protected EmergencyModeService $emergencyService;

  /**
   * EmergencyCommands constructor.
   */
  public function __construct(
    EmergencyModeService $emergency_service,
  ) {
    parent::__construct();
    $this->emergencyService = $emergency_service;
  }

  /**
   * Get emergency mode status.
   */
  #[CLI\Command(name: 'markaspot:emergency:status', aliases: ['emergency:status', 'emer:status'])]
  #[CLI\Option(name: 'jurisdiction', description: 'Root jurisdiction ID or slug. Required on multi-tenant installations.')]
  #[CLI\Usage(name: 'markaspot:emergency:status', description: 'Show current emergency mode status.')]
  public function status(array $options = ['jurisdiction' => NULL]): void {
    try {
      $rootId = $this->emergencyService->resolveRootJurisdictionId($options['jurisdiction']);
      $state = $this->emergencyService->getModeState($rootId);
    }
    catch (\InvalidArgumentException $exception) {
      throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
    }

    $this->output()->writeln('Root Jurisdiction: ' . $rootId);
    $this->output()->writeln('Emergency Mode Status: ' . strtoupper($state['status']));
    $this->output()->writeln('Revision: ' . $state['revision']);

    if ($state['status'] === 'active') {
      $this->output()->writeln('Mode Type: ' . $state['mode_type']);
      if ($state['activated_at']) {
        $this->output()->writeln('Activated At: ' . date('Y-m-d H:i:s', $state['activated_at']));
      }
    }
  }

  /**
   * Activate emergency mode.
   */
  #[CLI\Command(name: 'markaspot:emergency:activate', aliases: ['emergency:activate', 'emer:on'])]
  #[CLI\Option(name: 'mode-type', description: 'The type of emergency mode (disaster, crisis, maintenance).')]
  #[CLI\Option(name: 'no-unpublish', description: 'Skip unpublishing regular categories.')]
  #[CLI\Option(name: 'jurisdiction', description: 'Root jurisdiction ID or slug. Required on multi-tenant installations.')]
  #[CLI\Usage(name: 'markaspot:emergency:activate', description: 'Activate emergency mode with default settings.')]
  #[CLI\Usage(name: 'markaspot:emergency:activate --mode-type=disaster', description: 'Activate disaster mode specifically.')]
  #[CLI\Usage(name: 'markaspot:emergency:activate --mode-type=maintenance', description: 'Activate maintenance mode.')]
  public function activate(array $options = ['mode-type' => 'disaster', 'no-unpublish' => FALSE, 'jurisdiction' => NULL]): void {
    try {
      $rootId = $this->emergencyService->resolveRootJurisdictionId($options['jurisdiction']);
    }
    catch (\InvalidArgumentException $exception) {
      throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
    }

    $alreadyActive = $this->emergencyService->isActive($rootId);

    $modeType = $options['mode-type'];
    $allowedTypes = ['disaster', 'crisis', 'maintenance'];
    if (!in_array($modeType, $allowedTypes, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'Invalid --mode-type "%s". Allowed values: disaster, crisis, maintenance.',
        $modeType,
      ));
    }

    $policy = $this->emergencyService->getPolicy($rootId);
    if ($modeType === 'maintenance') {
      $forceRedirect = $policy['maintenance']['force_redirect'];
      $unpublish = $policy['maintenance']['unpublish_non_selected'];
    }
    else {
      $forceRedirect = $policy['force_redirect'];
      $unpublish = $policy['unpublish_regular'];
    }
    if ($options['no-unpublish']) {
      $unpublish = FALSE;
    }

    $state = $this->emergencyService->activate(
      modeType: $modeType,
      forceRedirect: $forceRedirect,
      liteUi: $policy['lite_ui'],
      unpublishCategories: $unpublish,
      createEmergencyCategories: TRUE,
      jurisdictionId: $rootId,
    );

    $this->output()->writeln($alreadyActive
      ? 'Emergency mode profile switched successfully.'
      : 'Emergency mode activated successfully.');
    $this->output()->writeln('Root Jurisdiction: ' . $rootId);
    $this->output()->writeln('Mode Type: ' . $modeType);
    if ($state['activated_at']) {
      $this->output()->writeln('Activated At: ' . date('Y-m-d H:i:s', $state['activated_at']));
    }
    $this->output()->writeln('Revision: ' . $state['revision']);
  }

  /**
   * Deactivate emergency mode.
   */
  #[CLI\Command(name: 'markaspot:emergency:deactivate', aliases: ['emergency:deactivate', 'emer:off'])]
  #[CLI\Option(name: 'jurisdiction', description: 'Root jurisdiction ID or slug. Required on multi-tenant installations.')]
  #[CLI\Usage(name: 'markaspot:emergency:deactivate', description: 'Deactivate emergency mode and restore regular categories.')]
  public function deactivate(array $options = ['jurisdiction' => NULL]): void {
    try {
      $rootId = $this->emergencyService->resolveRootJurisdictionId($options['jurisdiction']);
    }
    catch (\InvalidArgumentException $exception) {
      throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
    }

    if (!$this->emergencyService->isActive($rootId)) {
      $this->output()->writeln('Emergency mode is not currently active.');
      return;
    }

    $this->emergencyService->deactivate($rootId);

    $this->output()->writeln('Emergency mode deactivated successfully.');
    $this->output()->writeln('Root Jurisdiction: ' . $rootId);
    $this->output()->writeln('The exact pre-activation category set has been restored.');
  }

}
