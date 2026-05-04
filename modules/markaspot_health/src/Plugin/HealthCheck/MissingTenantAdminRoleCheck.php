<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects missing tenant_admin group roles.
 *
 * The jurisdiction tenant-admin group role is created by the profile install
 * config. It is lost after a drush cim cycle when the group role is not
 * exported into config/sync. Symptom: tenant_admin Settings menu invisible
 * despite the Drupal-side tenant_admin user role being present.
 *
 * @HealthCheck(
 *   id = "missing_tenant_admin_role",
 *   label = @Translation("Missing tenant_admin group role"),
 *   severity = "error",
 *   description = @Translation("Verifies the jurisdiction tenant_admin group role exists; without it, tenant administrators cannot access the settings menu in the dashboard."),
 *   fix_hint = @Translation("Export the configured jurisdiction tenant_admin group role into config/sync."),
 * )
 */
class MissingTenantAdminRoleCheck extends HealthCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->entityTypeManager->hasDefinition('group_role')) {
      return $this->pass('Group module not enabled; check skipped.');
    }
    $role_id = $this->jurisdictionGroupType() . '-tenant_admin';
    $role = $this->entityTypeManager->getStorage('group_role')->load($role_id);
    if ($role === NULL) {
      return $this->fail(1, sprintf('Group role "%s" is missing.', $role_id));
    }
    return $this->pass(sprintf('Group role "%s" exists.', $role_id));
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

}
