<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Plugin\TenantAlert;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_nuxt\TenantAlertInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Surfaces an alert when a FastMap workspace is still on default branding.
 *
 * Mirrors the gating used by the dashboard banner shipped in #437: the alert
 * only registers on the shared self-service platform and only on workspaces
 * that have completed tier onboarding. Once an admin completes the branding
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
   * The effective platform and feature scope resolver.
   */
  protected FeatureScopeResolver $featureScopeResolver;

  /**
   * Constructs a BrandingAlert plugin.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\markaspot_nuxt\Service\FeatureScopeResolver $feature_scope_resolver
   *   The effective platform and feature scope resolver.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    FeatureScopeResolver $feature_scope_resolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->featureScopeResolver = $feature_scope_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('markaspot_nuxt.feature_scope_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function check(GroupInterface $group, AccountInterface $account): ?array {
    // Self-service-only feature: dedicated enterprise stacks manage branding
    // through their deployment process and must not see SaaS onboarding.
    if (!$this->featureScopeResolver->isSelfServicePlatform()) {
      return NULL;
    }

    // An empty tier now means pre-onboarding, not an enterprise platform.
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
