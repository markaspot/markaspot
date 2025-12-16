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
    'description' => t('Returns the configured Nuxt frontend base URL, falling back to the Drupal site URL if disabled.'),
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

          if (!$frontend_url) {
            $frontend_url = \Drupal::service('router.request_context')->getCompleteBaseUrl();
            $bubbleable_metadata->addCacheContexts(['url.site']);
          }

          $replacements[$original] = $frontend_url;
          break;
      }
    }
  }

  return $replacements;
}
