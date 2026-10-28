<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Image;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\image\ImageStyleInterface;

/**
 * Resolves the image styles that deliver request photos at display size.
 *
 * Request photos are private, so every download passes Drupal's access
 * check. A smaller derivative is the lever that keeps that check and still
 * cuts the bytes. Config-sync tenants lack the profile styles until their
 * config export lands; each size then falls back to a stock style.
 */
final class RequestImageStyles {

  /**
   * Styles tried in order for the detail preview.
   */
  public const PREVIEW = ['markaspot_request_preview', 'wide'];

  /**
   * Styles tried in order for list thumbnails.
   */
  public const THUMB = ['markaspot_request_thumb', 'thumbnail'];

  /**
   * Returns the style for the detail preview, or NULL when none is usable.
   */
  public static function preview(): ?ImageStyleInterface {
    return self::resolve(self::PREVIEW);
  }

  /**
   * Returns the style for list thumbnails, or NULL when none is usable.
   */
  public static function thumb(): ?ImageStyleInterface {
    return self::resolve(self::THUMB);
  }

  /**
   * Builds the derivative URL of a file in a style.
   *
   * @return string|null
   *   The absolute derivative URL, or NULL without a style or URI.
   */
  public static function url(?ImageStyleInterface $style, string $uri): ?string {
    if (!$style instanceof ImageStyleInterface || $uri === '') {
      return NULL;
    }
    return $style->buildUrl($uri);
  }

  /**
   * Adds every candidate style config as a cache dependency.
   *
   * A style created or deleted later changes which candidate wins.
   *
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $metadata
   *   The cacheability to extend.
   * @param string[] $candidates
   *   One of the candidate lists of this class.
   */
  public static function addCacheableDependencies(RefinableCacheableDependencyInterface $metadata, array $candidates): void {
    foreach ($candidates as $style_id) {
      $metadata->addCacheableDependency(\Drupal::config('image.style.' . $style_id));
    }
  }

  /**
   * Returns the first candidate whose output format the toolkit can write.
   *
   * A WebP style on an image without WebP support in GD would answer every
   * derivative request with an error, so it counts as missing.
   *
   * @param string[] $candidates
   *   Image style IDs in order of preference.
   */
  private static function resolve(array $candidates): ?ImageStyleInterface {
    $entity_type_manager = \Drupal::entityTypeManager();
    if (!$entity_type_manager->hasDefinition('image_style')) {
      return NULL;
    }
    $storage = $entity_type_manager->getStorage('image_style');
    $supported = \Drupal::service('image.factory')->getSupportedExtensions();
    foreach ($candidates as $style_id) {
      $style = $storage->load($style_id);
      if ($style instanceof ImageStyleInterface
        && in_array($style->getDerivativeExtension('jpg'), $supported, TRUE)) {
        return $style;
      }
    }
    return NULL;
  }

}
