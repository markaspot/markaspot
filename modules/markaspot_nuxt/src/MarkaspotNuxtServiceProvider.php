<?php

namespace Drupal\markaspot_nuxt;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Service provider for markaspot_nuxt module.
 *
 * Registers json_form_widget dependent services only when that module is
 * available. This allows the module to be enabled before json_form_widget
 * during updates from older versions.
 *
 * Also overrides jsonapi.entity_resource with CachedCountEntityResource to
 * cache expensive COUNT queries for service_request collections.
 */
class MarkaspotNuxtServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    // Override the JSON:API entity resource controller with our cached version.
    // Guard: only when the jsonapi module is enabled (service must exist).
    if ($container->has('jsonapi.entity_resource')) {
      $definition = $container->getDefinition('jsonapi.entity_resource');
      $definition->setClass('Drupal\markaspot_nuxt\JsonApi\CachedCountEntityResource');
    }

    // Only register json_form dependent services if json_form_widget is active.
    // Check if json_form.string_helper exists (defined by json_form_widget).
    if ($container->has('json_form.string_helper')) {
      // Register boolean helper using fully qualified class name.
      $container->register('markaspot_nuxt.boolean_helper', 'Drupal\markaspot_nuxt\BooleanHelper');

      // Register extended string helper with color format support.
      $string_helper_definition = $container->getDefinition('json_form.string_helper');
      $container->register('markaspot_nuxt.string_helper', 'Drupal\markaspot_nuxt\ExtendedStringHelper')
        ->setArguments($string_helper_definition->getArguments());

      // Override json_form.router to add boolean and color support.
      $definition = $container->getDefinition('json_form.router');
      $definition->setClass('Drupal\markaspot_nuxt\ExtendedFieldTypeRouter');
      $definition->setArguments([
        new Reference('markaspot_nuxt.string_helper'),
        new Reference('json_form.object_helper'),
        new Reference('json_form.array_helper'),
        new Reference('json_form.integer_helper'),
        new Reference('markaspot_nuxt.boolean_helper'),
      ]);

      // Override json_form.value_handler to add boolean support.
      $definition = $container->getDefinition('json_form.value_handler');
      $definition->setClass('Drupal\markaspot_nuxt\ExtendedValueHandler');

      // Override json_form.object_helper to add additionalProperties support.
      $definition = $container->getDefinition('json_form.object_helper');
      $definition->setClass('Drupal\markaspot_nuxt\ExtendedObjectHelper');
    }
  }

}
