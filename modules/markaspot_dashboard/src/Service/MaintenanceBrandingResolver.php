<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\group\Entity\GroupInterface;

/**
 * Builds a maintenance-safe subset of public jurisdiction branding.
 */
final class MaintenanceBrandingResolver implements MaintenanceBrandingResolverInterface {

  /**
   * Constructs the branding resolver.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(?string $jurisdiction = NULL): ?array {
    try {
      $group = $this->resolveJurisdiction(trim((string) $jurisdiction));
      if (!$group) {
        return NULL;
      }

      $nuxtConfig = $this->nuxtConfig($group);
      $tenantName = trim((string) ($nuxtConfig['client']['name'] ?? $group->label()));
      if ($tenantName === '') {
        return NULL;
      }

      $theme = is_array($nuxtConfig['theme'] ?? NULL)
        ? $nuxtConfig['theme']
        : [];
      $logos = is_array($theme['logos'] ?? NULL)
        ? $theme['logos']
        : [];
      $logoLight = $this->filePath($group, 'field_logo_light');
      $logoDark = $this->filePath($group, 'field_logo_dark');

      return [
        'tenantName' => $tenantName,
        'logoLight' => $logoLight !== ''
          ? $logoLight
          : $this->publicAssetPath($logos['light'] ?? $theme['logoLight'] ?? ''),
        'logoDark' => $logoDark !== ''
          ? $logoDark
          : $this->publicAssetPath($logos['dark'] ?? $theme['logoDark'] ?? ''),
        'defaultLocale' => $this->defaultLocale($nuxtConfig),
      ];
    }
    catch (\Throwable) {
      // Branding is optional. Never make the maintenance status unavailable
      // because a tenant field, file entity, or legacy config is malformed.
      return NULL;
    }
  }

  /**
   * Resolves one published jurisdiction without widening public visibility.
   */
  private function resolveJurisdiction(string $jurisdiction): ?GroupInterface {
    $jurisdictionType = (string) ($this->configFactory
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type') ?: 'jur');
    $storage = $this->entityTypeManager->getStorage('group');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $jurisdictionType)
      ->condition('status', 1)
      ->sort('id', 'ASC')
      ->range(0, 2);

    if ($jurisdiction !== '') {
      if (ctype_digit($jurisdiction) && (int) $jurisdiction > 0) {
        $query->condition('id', (int) $jurisdiction);
      }
      else {
        $query->condition('field_slug', $jurisdiction);
      }
    }

    $ids = array_values($query->execute());
    if (count($ids) !== 1) {
      return NULL;
    }

    $group = $storage->load($ids[0]);
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Reads the jurisdiction's public Nuxt configuration.
   *
   * @return array<string, mixed>
   *   Decoded configuration, or an empty array for legacy/malformed values.
   */
  private function nuxtConfig(GroupInterface $group): array {
    if (!$group->hasField('field_nuxt_config') || $group->get('field_nuxt_config')->isEmpty()) {
      return [];
    }

    $decoded = json_decode((string) $group->get('field_nuxt_config')->value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Returns the public file path used by the Nuxt image proxy.
   */
  private function filePath(GroupInterface $group, string $fieldName): string {
    if (!$group->hasField($fieldName) || $group->get($fieldName)->isEmpty()) {
      return '';
    }

    $file = $group->get($fieldName)->entity;
    if (!$file || !method_exists($file, 'getFileUri')) {
      return '';
    }

    return $this->publicAssetPath((string) $file->getFileUri());
  }

  /**
   * Normalizes a public logo URI without exposing arbitrary external paths.
   */
  private function publicAssetPath(mixed $value): string {
    if (!is_string($value)) {
      return '';
    }

    $value = trim($value);
    if ($value === '' || str_contains($value, "\0") || str_contains($value, '\\')) {
      return '';
    }

    $publicBasePath = trim(PublicStream::basePath(), '/');
    if (StreamWrapperManager::getScheme($value) === 'public') {
      $target = ltrim((string) StreamWrapperManager::getTarget($value), '/');
    }
    elseif (str_starts_with($value, '/' . $publicBasePath . '/')) {
      if (strpbrk($value, '?#') !== FALSE) {
        return '';
      }
      $target = substr($value, strlen('/' . $publicBasePath . '/'));
    }
    else {
      return '';
    }

    $decodedTarget = rawurldecode($target);
    if (
      $target === ''
      || str_contains($target, '%')
      || preg_match('@(^|/)\.\.(/|$)@', $decodedTarget) === 1
      || preg_match('/\.(?:svg|png|jpe?g|webp)$/i', $decodedTarget) !== 1
    ) {
      return '';
    }

    return '/' . $publicBasePath . '/' . ltrim($target, '/');
  }

  /**
   * Resolves the tenant locale without exposing the full Nuxt configuration.
   *
   * @param array<string, mixed> $nuxtConfig
   *   Decoded jurisdiction config.
   */
  private function defaultLocale(array $nuxtConfig): string {
    $languages = is_array($nuxtConfig['languages'] ?? NULL)
      ? $nuxtConfig['languages']
      : [];
    $locale = trim((string) ($languages['default'] ?? $languages['defaultLocale'] ?? ''));
    if ($locale !== '') {
      return $locale;
    }

    return trim((string) $this->configFactory
      ->get('system.site')
      ->get('default_langcode'));
  }

}
