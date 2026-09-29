<?php

namespace Drupal\markaspot_search_meilisearch;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\markaspot_search_meilisearch\Api\RestrictedMeilisearchApiService;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Swaps in the Meilisearch API service that applies search restrictions.
 */
class MarkaspotSearchMeilisearchServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if (!$container->hasDefinition('search_api_meilisearch.api')) {
      return;
    }
    $container->getDefinition('search_api_meilisearch.api')
      ->setClass(RestrictedMeilisearchApiService::class)
      ->addMethodCall('setRestriction', [new Reference('markaspot_search_meilisearch.search_restriction')]);
  }

}
