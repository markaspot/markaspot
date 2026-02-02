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
 */
class MarkaspotNuxtServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    // Only register json_form dependent services if json_form_widget is active.
    // Check if the json_form.string_helper service exists (defined by json_form_widget).
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
    }
  }

}
