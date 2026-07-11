<?php

declare(strict_types=1);

namespace Drupal\markaspot_emergency\EntityReferenceSelection;

use Drupal\Component\Plugin\FallbackPluginManagerInterface;
use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\markaspot_emergency\EventSubscriber\EmergencySubmissionGuardSubscriber;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Decorates selection handling for one prepared Lite jurisdiction field.
 *
 * This transparent decorator delegates discovery and cache ownership to Core.
 */
final class EmergencySelectionPluginManager implements SelectionPluginManagerInterface, FallbackPluginManagerInterface, CachedDiscoveryInterface, CacheableDependencyInterface {

  /**
   * Constructs the manager decorator.
   */
  // @phpstan-ignore-next-line pluginManagerSetsCacheBackend.missingCacheBackend
  public function __construct(
    private readonly SelectionPluginManagerInterface $inner,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
    return $this->inner->getDefinition($plugin_id, $exception_on_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    return $this->inner->getDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id) {
    return $this->inner->hasDefinition($plugin_id);
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    return $this->inner->createInstance($plugin_id, $configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options) {
    return $this->inner->getInstance($options);
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginId($target_type, $base_plugin_id) {
    return $this->inner->getPluginId($target_type, $base_plugin_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getSelectionGroups($entity_type_id) {
    return $this->inner->getSelectionGroups($entity_type_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getFallbackPluginId($plugin_id, array $configuration = []) {
    if (!$this->inner instanceof FallbackPluginManagerInterface) {
      throw new \LogicException('The configured entity reference manager has no fallback support.');
    }
    return $this->inner->getFallbackPluginId($plugin_id, $configuration);
  }

  /**
   * {@inheritdoc}
   *
   * The decorated manager is tagged for Drupal's global plugin-cache clearer.
   * Keep that contract intact so cache rebuilds never call a missing method on
   * this narrow selection-handler decorator.
   */
  public function clearCachedDefinitions() {
    $this->cachedDiscoveryManager()->clearCachedDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function useCaches($use_caches = FALSE) {
    $this->cachedDiscoveryManager()->useCaches($use_caches);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return $this->cacheableDependencyManager()->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return $this->cacheableDependencyManager()->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return $this->cacheableDependencyManager()->getCacheMaxAge();
  }

  /**
   * {@inheritdoc}
   */
  public function getSelectionHandler(FieldDefinitionInterface $field_definition, ?EntityInterface $entity = NULL) {
    $handler = $this->inner->getSelectionHandler($field_definition, $entity);
    $jurisdictionId = $this->getPreparedJurisdictionId($field_definition, $entity);
    return $jurisdictionId === NULL
      ? $handler
      : new EmergencyJurisdictionSelection($handler, $jurisdictionId);
  }

  /**
   * Returns the exact prepared jurisdiction for the guarded field validation.
   */
  private function getPreparedJurisdictionId(
    FieldDefinitionInterface $fieldDefinition,
    ?EntityInterface $entity,
  ): ?int {
    if ($fieldDefinition->getName() !== 'field_jurisdiction'
      || $fieldDefinition->getFieldStorageDefinition()->getSetting('target_type') !== 'group'
      || !$entity instanceof ContentEntityInterface
      || $entity->getEntityTypeId() !== 'node'
      || $entity->bundle() !== 'service_request'
      || !$entity->isNew()
      || !$entity->hasField('field_jurisdiction')) {
      return NULL;
    }

    $request = $this->requestStack->getMainRequest();
    if ($request === NULL
      || $request->getMethod() !== 'POST'
      || $request->attributes->get('_route') !== 'jsonapi.node--service_request.collection.post') {
      return NULL;
    }
    $context = $request->attributes->get(
      EmergencySubmissionGuardSubscriber::CONTEXT_ATTRIBUTE,
    );
    if (!is_array($context)
      || ($context['expected_status'] ?? NULL) !== 'active'
      || ($context['require_lite_ui'] ?? NULL) !== TRUE) {
      return NULL;
    }

    $jurisdictionId = (int) ($context['jurisdiction_id'] ?? 0);
    $values = $entity->get('field_jurisdiction')->getValue();
    $submittedId = count($values) === 1
      ? (int) ($values[0]['target_id'] ?? 0)
      : 0;
    return $jurisdictionId > 0 && $submittedId === $jurisdictionId
      ? $jurisdictionId
      : NULL;
  }

  /**
   * Returns the inner cached-discovery manager with a fail-closed contract.
   */
  private function cachedDiscoveryManager(): CachedDiscoveryInterface {
    if (!$this->inner instanceof CachedDiscoveryInterface) {
      throw new \LogicException('The configured entity reference manager has no cached discovery support.');
    }
    return $this->inner;
  }

  /**
   * Returns the inner cacheable dependency manager with a fail-closed contract.
   */
  private function cacheableDependencyManager(): CacheableDependencyInterface {
    if (!$this->inner instanceof CacheableDependencyInterface) {
      throw new \LogicException('The configured entity reference manager has no cacheability metadata support.');
    }
    return $this->inner;
  }

}
