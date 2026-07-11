<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_emergency\Unit;

use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Component\Plugin\FallbackPluginManagerInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\markaspot_emergency\EntityReferenceSelection\EmergencyJurisdictionSelection;
use Drupal\markaspot_emergency\EntityReferenceSelection\EmergencySelectionPluginManager;
use Drupal\markaspot_emergency\EventSubscriber\EmergencySubmissionGuardSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the validation-only entity-reference manager decorator.
 *
 * @group markaspot_emergency
 */
class EmergencySelectionPluginManagerTest extends UnitTestCase {

  /**
   * The exact prepared Lite target bypasses only reference access filtering.
   */
  public function testExactPreparedLiteJurisdictionIsReferenceable(): void {
    $innerHandler = $this->createMock(SelectionInterface::class);
    $innerHandler->expects($this->once())
      ->method('validateReferenceableEntities')
      ->with([9])
      ->willReturn([]);
    $manager = $this->manager($innerHandler, $this->request(TRUE));

    $handler = $manager->getSelectionHandler(
      $this->fieldDefinition(),
      $this->serviceRequestEntity(9),
    );

    $this->assertInstanceOf(EmergencyJurisdictionSelection::class, $handler);
    $this->assertSame([9 => 9], $handler->validateReferenceableEntities([9]));
  }

  /**
   * Off-mode and mismatched targets retain core's configured handler.
   */
  public function testUnarmedOrMismatchedReferenceKeepsCoreHandler(): void {
    $innerHandler = $this->createMock(SelectionInterface::class);
    $manager = $this->manager($innerHandler, $this->request(FALSE));
    $this->assertSame(
      $innerHandler,
      $manager->getSelectionHandler(
        $this->fieldDefinition(),
        $this->serviceRequestEntity(9),
      ),
    );

    $manager = $this->manager($innerHandler, $this->request(TRUE));
    $this->assertSame(
      $innerHandler,
      $manager->getSelectionHandler(
        $this->fieldDefinition(),
        $this->serviceRequestEntity(10),
      ),
    );
  }

  /**
   * Cache clearers retain the decorated core manager's full cache contract.
   */
  public function testDecoratedManagerDelegatesPluginCacheContract(): void {
    $inner = new EmergencySelectionPluginManagerCacheTestDouble();
    $manager = new EmergencySelectionPluginManager($inner, new RequestStack());

    $manager->clearCachedDefinitions();
    $manager->useCaches(FALSE);
    $this->assertTrue($inner->definitionsCleared);
    $this->assertFalse($inner->usesCaches);
    $this->assertSame(['languages:language_interface'], $manager->getCacheContexts());
    $this->assertSame(['config:core.extension'], $manager->getCacheTags());
    $this->assertSame(3600, $manager->getCacheMaxAge());
  }

  /**
   * Builds the decorated manager around one configured handler.
   */
  private function manager(
    SelectionInterface $handler,
    Request $request,
  ): EmergencySelectionPluginManager {
    $inner = $this->createMock(SelectionPluginManagerInterface::class);
    $inner->method('getSelectionHandler')->willReturn($handler);
    $stack = new RequestStack();
    $stack->push($request);
    return new EmergencySelectionPluginManager($inner, $stack);
  }

  /**
   * Builds the exact JSON:API request context armed by the subscriber.
   */
  private function request(bool $liteActive): Request {
    $request = Request::create('/jsonapi/node/service_request', 'POST');
    $request->attributes->set('_route', 'jsonapi.node--service_request.collection.post');
    $request->attributes->set(
      EmergencySubmissionGuardSubscriber::CONTEXT_ATTRIBUTE,
      [
        'root_id' => 7,
        'expected_revision' => 12,
        'expected_status' => $liteActive ? 'active' : 'off',
        'require_lite_ui' => $liteActive,
        'category_id' => 40,
        'jurisdiction_id' => 9,
      ],
    );
    return $request;
  }

  /**
   * Builds the service-request jurisdiction field definition.
   */
  private function fieldDefinition(): FieldDefinitionInterface {
    $storage = $this->createMock(FieldStorageDefinitionInterface::class);
    $storage->method('getSetting')->with('target_type')->willReturn('group');
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getName')->willReturn('field_jurisdiction');
    $field->method('getFieldStorageDefinition')->willReturn($storage);
    return $field;
  }

  /**
   * Builds a new service request containing one jurisdiction target.
   */
  private function serviceRequestEntity(int $jurisdictionId): ContentEntityInterface {
    $jurisdiction = $this->createMock(FieldItemListInterface::class);
    $jurisdiction->method('getValue')->willReturn([
      ['target_id' => $jurisdictionId],
    ]);
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('service_request');
    $entity->method('isNew')->willReturn(TRUE);
    $entity->method('hasField')->with('field_jurisdiction')->willReturn(TRUE);
    $entity->method('get')->with('field_jurisdiction')->willReturn($jurisdiction);
    return $entity;
  }

}

/**
 * Minimal concrete inner manager avoids PHPUnit's duplicate interface methods.
 */
final class EmergencySelectionPluginManagerCacheTestDouble implements SelectionPluginManagerInterface, FallbackPluginManagerInterface, CachedDiscoveryInterface, CacheableDependencyInterface {

  /**
   * Whether plugin definitions were cleared.
   */
  public bool $definitionsCleared = FALSE;

  /**
   * Whether the delegated manager currently uses caches.
   */
  public bool $usesCaches = TRUE;

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    throw new \LogicException('Not used by this cache-contract test.');
  }

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options) {
    throw new \LogicException('Not used by this cache-contract test.');
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginId($target_type, $base_plugin_id) {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getSelectionGroups($entity_type_id) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSelectionHandler(FieldDefinitionInterface $field_definition, ?EntityInterface $entity = NULL) {
    throw new \LogicException('Not used by this cache-contract test.');
  }

  /**
   * {@inheritdoc}
   */
  public function getFallbackPluginId($plugin_id, array $configuration = []) {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions() {
    $this->definitionsCleared = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function useCaches($use_caches = FALSE) {
    $this->usesCaches = (bool) $use_caches;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return ['languages:language_interface'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return ['config:core.extension'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 3600;
  }

}
