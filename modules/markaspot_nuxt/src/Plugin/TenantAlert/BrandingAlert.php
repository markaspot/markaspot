<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Plugin\TenantAlert;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\TenantAlertInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Surfaces an alert when a FastMap workspace is still on default branding.
 *
 * Mirrors the gating used by the dashboard banner shipped in #437: the alert
 * only registers in FastMap-enabled installs and only on workspaces that
 * have a tier assigned. Once an admin completes the first-run branding
 * setup (field_nuxt_config.setup.brandingCompleted = TRUE), the alert
 * disappears for everyone, regardless of per-user "handled" state.
 *
 * @TenantAlert(
 *   id = "branding",
 *   category = "setup",
 *   severity = "info",
 * )
 */
class BrandingAlert extends PluginBase implements TenantAlertInterface, ContainerFactoryPluginInterface {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Constructs a BrandingAlert plugin.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function check(GroupInterface $group, AccountInterface $account): ?array {
    // FastMap-only feature: outside FastMap installs the alert never registers.
    if (!$this->moduleHandler->moduleExists('markaspot_fastmap')) {
      return NULL;
    }

    // Workspaces without a tier are on-premise / pre-onboarding; the
    // first-run branding flow does not apply to them.
    if (!$group->hasField('field_tier') || $group->get('field_tier')->isEmpty()) {
      return NULL;
    }

    // Admin completed the first-run setup: silence the alert globally.
    $config = $this->readNuxtConfig($group);
    $brandingCompleted = $config['setup']['brandingCompleted'] ?? FALSE;
    if ($brandingCompleted === TRUE) {
      return NULL;
    }

    return [
      'id' => $this->getPluginId(),
      'category' => $this->getPluginDefinition()['category'] ?? 'setup',
      'severity' => $this->getPluginDefinition()['severity'] ?? 'info',
      'title_key' => 'branding_title',
      'body_key' => 'branding_body',
      'cta' => [
        'label_key' => 'branding_cta',
        'path_template' => '/dashboard/settings/branding',
      ],
    ];
  }

  /**
   * Reads the field_nuxt_config JSON blob from the default translation.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   *
   * @return array<string, mixed>
   *   The decoded config, or an empty array when missing or invalid.
   */
  protected function readNuxtConfig(GroupInterface $group): array {
    if (!$group->hasField('field_nuxt_config')) {
      return [];
    }
    $source = $group->isDefaultTranslation() ? $group : $group->getUntranslated();
    if ($source->get('field_nuxt_config')->isEmpty()) {
      return [];
    }
    $raw = $source->get('field_nuxt_config')->value;
    $decoded = json_decode((string) $raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

}
