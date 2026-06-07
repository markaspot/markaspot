<?php

/**
 * @file
 * Token hooks for Mark-a-Spot Nuxt module.
 */

use Drupal\Core\Render\BubbleableMetadata;

/**
 * Implements hook_token_info().
 */
function markaspot_nuxt_token_info() {
  $info['types']['markaspot_frontend'] = [
    'name' => t('Mark-a-Spot frontend'),
    'description' => t('Tokens exposing the configured Nuxt frontend.'),
  ];

  $info['tokens']['markaspot_frontend']['url'] = [
    'name' => t('Frontend base URL'),
    'description' => t('Returns the configured Nuxt frontend base URL. Returns an empty value when no safe public frontend URL is configured.'),
  ];

  // Node-level token for complete frontend request detail URL.
  $info['tokens']['node']['markaspot_frontend_url'] = [
    'name' => t('Frontend request URL'),
    'description' => t('Full frontend URL for this service request, including jurisdiction slug. Example: https://example.com/amsterdam/requests/407-2026'),
  ];

  $info['tokens']['node']['markaspot_media_url'] = [
    'name' => t('Frontend media URL'),
    'description' => t('Returns the first media image URL proxied through the Nuxt frontend (e.g. https://frontend/api/sites/default/files/2024-03/photo.jpg).'),
  ];

  return $info;
}

/**
 * Implements hook_tokens().
 */
function markaspot_nuxt_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $replacements = [];

  if ($type === 'markaspot_frontend') {
    /** @var \Drupal\markaspot_nuxt\Service\FrontendUrlService $frontend_url_service */
    $frontend_url_service = \Drupal::service('markaspot_nuxt.frontend_url');
    $config = \Drupal::config('markaspot_nuxt.settings');
    $bubbleable_metadata->addCacheableDependency($config);

    foreach ($tokens as $name => $original) {
      switch ($name) {
        case 'url':
          $frontend_url = $frontend_url_service->getFrontendBaseUrl();
          $replacements[$original] = $frontend_url ?: '';
          break;
      }
    }
  }

  // Handle [node:markaspot_frontend_url] for service_request nodes.
  if ($type === 'node' && !empty($data['node'])) {
    $node = $data['node'];

    foreach ($tokens as $name => $original) {
      if ($name === 'markaspot_media_url') {
        $replacements[$original] = _markaspot_nuxt_get_frontend_media_url($node, $bubbleable_metadata);
        continue;
      }

      if ($name !== 'markaspot_frontend_url') {
        continue;
      }

      // Only service_request nodes have request IDs and jurisdictions.
      if ($node->bundle() !== 'service_request') {
        continue;
      }

      /** @var \Drupal\markaspot_nuxt\Service\FrontendUrlService $frontend_url_service */
      $frontend_url_service = \Drupal::service('markaspot_nuxt.frontend_url');
      $config = \Drupal::config('markaspot_nuxt.settings');
      $bubbleable_metadata->addCacheableDependency($config);

      // Get notification-safe frontend base URL.
      $base_url = $frontend_url_service->getNotificationFrontendBaseUrl();
      if (!$base_url) {
        $replacements[$original] = '';
        continue;
      }
      $base_url = rtrim($base_url, '/');

      // Get jurisdiction slug (URL-encoded for safety).
      $jur_slug = '';
      if ($node->hasField('field_jurisdiction') && !$node->get('field_jurisdiction')->isEmpty()) {
        $jur = $node->get('field_jurisdiction')->entity;
        if ($jur && $jur->hasField('field_slug') && !$jur->get('field_slug')->isEmpty()) {
          $jur_slug = rawurlencode($jur->get('field_slug')->value);
          $bubbleable_metadata->addCacheableDependency($jur);
        }
      }

      // Get request ID (URL-encoded for defense in depth).
      $request_id = $node->hasField('request_id') && !$node->get('request_id')->isEmpty()
        ? rawurlencode($node->get('request_id')->value)
        : $node->id();

      // Build: {base_url}/{slug}/requests/{request_id}.
      $path = $jur_slug ? "$jur_slug/requests/$request_id" : "requests/$request_id";
      $replacements[$original] = "$base_url/$path";
    }
  }

  return $replacements;
}

/**
 * Build frontend-proxied media URLs for all images on a node.
 *
 * Returns one URL per line for use in email templates.
 */
function _markaspot_nuxt_get_frontend_media_url($node, BubbleableMetadata $bubbleable_metadata) {
  if (!$node->hasField('field_request_media') || $node->get('field_request_media')->isEmpty()) {
    return '';
  }

  // Get frontend base URL once.
  $config = \Drupal::config('markaspot_nuxt.settings');
  $bubbleable_metadata->addCacheableDependency($config);

  /** @var \Drupal\markaspot_nuxt\Service\FrontendUrlService $frontend_url_service */
  $frontend_url_service = \Drupal::service('markaspot_nuxt.frontend_url');
  $frontend_url = $frontend_url_service->getNotificationFrontendBaseUrl();
  if (!$frontend_url) {
    return '';
  }
  $base = rtrim($frontend_url, '/') . '/api/';

  $urls = [];
  foreach ($node->get('field_request_media') as $item) {
    $media = $item->entity;
    if (!$media || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      continue;
    }

    $file = $media->get('field_media_image')->entity;
    if (!$file) {
      continue;
    }

    $uri = $file->getFileUri();
    $stream_wrapper = \Drupal::service('stream_wrapper_manager')->getViaUri($uri);
    if (!$stream_wrapper) {
      continue;
    }
    $relative_path = $stream_wrapper->getDirectoryPath() . '/' . \Drupal::service('stream_wrapper_manager')->getTarget($uri);
    $urls[] = $base . $relative_path;
  }

  return implode("\n", $urls);
}
