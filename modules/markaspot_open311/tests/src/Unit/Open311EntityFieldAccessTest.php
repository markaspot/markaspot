<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\markaspot_nuxt\Service\FeatureFlagChecker;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require_once __DIR__ . '/../../../markaspot_open311.module';

/**
 * Tests markaspot_open311_entity_field_access() access gating.
 *
 * Covers the two internal status-attribute fields:
 * - field_status_definition (taxonomy_term / service_status and
 *   internal_status): the internal status-attribute schema, staff-only.
 * - field_status_attributes (paragraph / status): captured internal process
 *   data, never interactively writable.
 * - taxonomy_term / internal_status: term labels are staff-only because they
 *   describe back-office workflow state.
 *
 * @group markaspot_open311
 */
class Open311EntityFieldAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // AccessResult cacheability calls (addCacheContexts/cachePerPermissions)
    // validate context tokens against the cache_contexts_manager service.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * Registers a FeatureFlagChecker double on the container.
   *
   * @param bool $enabled
   *   The value isEnabled() should return for features.statusAttributes.
   */
  private function setFeatureFlag(bool $enabled): void {
    $checker = $this->createMock(FeatureFlagChecker::class);
    $checker->method('isEnabled')->willReturn($enabled);
    \Drupal::getContainer()->set('markaspot_nuxt.feature_flag_checker', $checker);
  }

  /**
   * Builds a field definition double.
   *
   * @param string $name
   *   The field name.
   * @param string $entityType
   *   The target entity type id.
   * @param string $bundle
   *   The target bundle.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked field definition.
   */
  private function fieldDefinition(string $name, string $entityType, string $bundle): FieldDefinitionInterface {
    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getName')->willReturn($name);
    $definition->method('getTargetEntityTypeId')->willReturn($entityType);
    $definition->method('getTargetBundle')->willReturn($bundle);
    return $definition;
  }

  /**
   * Builds an account double granting the given permissions.
   *
   * @param string[] $permissions
   *   Permission strings the account holds.
   *
   * @return \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked account.
   */
  private function account(array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $account;
  }

  /**
   * Builds a service_status term field item list double.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked item list whose getEntity() returns a TermInterface.
   */
  private function statusTermItems(): FieldItemListInterface {
    $term = $this->createMock(TermInterface::class);
    $term->method('hasField')->with('field_jurisdiction')->willReturn(FALSE);
    $term->method('getCacheContexts')->willReturn([]);
    $term->method('getCacheTags')->willReturn([]);
    $term->method('getCacheMaxAge')->willReturn(-1);

    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($term);
    return $items;
  }

  /**
   * Builds a service_request field item list double.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   Optional entity to return from getEntity().
   *
   * @return \Drupal\Core\Field\FieldItemListInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked item list.
   */
  private function serviceRequestItems(?EntityInterface $entity = NULL): FieldItemListInterface {
    $entity ??= $this->entity('node', 'service_request');

    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($entity);
    return $items;
  }

  /**
   * Registers a GeoReport processor double for service-request author gates.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The node entity expected by resolveNodeJurisdictionId().
   * @param int|null $jurisdictionId
   *   Jurisdiction id returned for the node.
   * @param bool $hasJurisdictionGroups
   *   Whether the install has jurisdiction groups.
   * @param bool $isMember
   *   Whether the tested account is a jurisdiction member.
   */
  private function setGeoreportProcessor(EntityInterface $entity, ?int $jurisdictionId, bool $hasJurisdictionGroups, bool $isMember): void {
    $processor = $this->createMock(GeoreportProcessorServiceInterface::class);
    $processor->method('resolveNodeJurisdictionId')
      ->with($entity)
      ->willReturn($jurisdictionId);
    $processor->method('hasJurisdictionGroups')->willReturn($hasJurisdictionGroups);
    $processor->method('isJurisdictionMember')->willReturn($isMember);
    \Drupal::getContainer()->set('markaspot_open311.georeport_processor', $processor);
  }

  /**
   * Builds an entity double with the given type and bundle.
   *
   * @return \Drupal\Core\Entity\EntityInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked entity.
   */
  private function entity(string $entityTypeId, string $bundle): EntityInterface {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn($entityTypeId);
    $entity->method('bundle')->willReturn($bundle);
    $entity->method('getCacheContexts')->willReturn([]);
    $entity->method('getCacheTags')->willReturn([]);
    $entity->method('getCacheMaxAge')->willReturn(-1);
    return $entity;
  }

  /**
   * Field_status_definition is hidden from anonymous users even when enabled.
   *
   * Anonymous holds no permissions; the feature flag is on.
   *
   * @dataProvider definitionOperationProvider
   */
  public function testStatusDefinitionForbiddenForAnonymous(string $operation): void {
    $this->setFeatureFlag(TRUE);
    $result = markaspot_open311_entity_field_access(
      $operation,
      $this->fieldDefinition('field_status_definition', 'taxonomy_term', 'service_status'),
      $this->account([]),
      $this->statusTermItems(),
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
    $this->assertContains('user.permissions', $result->getCacheContexts());
  }

  /**
   * Field_status_definition is hidden from citizens even when enabled.
   *
   * An authenticated citizen with non-staff permissions still lacks
   * 'manage dashboard notes'; the feature flag is on.
   *
   * @dataProvider definitionOperationProvider
   */
  public function testStatusDefinitionForbiddenForCitizen(string $operation): void {
    $this->setFeatureFlag(TRUE);
    $result = markaspot_open311_entity_field_access(
      $operation,
      $this->fieldDefinition('field_status_definition', 'taxonomy_term', 'service_status'),
      // Typical citizen permissions, none of which are staff permissions.
      $this->account(['access content', 'create field_address']),
      $this->statusTermItems(),
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * Staff with 'manage dashboard notes' get access when the flag is enabled.
   *
   * @dataProvider definitionOperationProvider
   */
  public function testStatusDefinitionAllowedForStaffWhenEnabled(string $operation): void {
    $this->setFeatureFlag(TRUE);
    $result = markaspot_open311_entity_field_access(
      $operation,
      $this->fieldDefinition('field_status_definition', 'taxonomy_term', 'service_status'),
      $this->account(['manage dashboard notes']),
      $this->statusTermItems(),
    );
    $this->assertFalse($result->isForbidden(), 'Staff access is not forbidden when the flag is enabled.');
  }

  /**
   * Staff with 'manage dashboard notes' are denied when the flag is disabled.
   *
   * @dataProvider definitionOperationProvider
   */
  public function testStatusDefinitionForbiddenForStaffWhenDisabled(string $operation): void {
    $this->setFeatureFlag(FALSE);
    $result = markaspot_open311_entity_field_access(
      $operation,
      $this->fieldDefinition('field_status_definition', 'taxonomy_term', 'service_status'),
      $this->account(['manage dashboard notes']),
      $this->statusTermItems(),
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * Platform operators ('administer taxonomy') keep access regardless of flag.
   */
  public function testStatusDefinitionAllowedForOperator(): void {
    $this->setFeatureFlag(FALSE);
    $result = markaspot_open311_entity_field_access(
      'edit',
      $this->fieldDefinition('field_status_definition', 'taxonomy_term', 'service_status'),
      $this->account(['administer taxonomy']),
      $this->statusTermItems(),
    );
    $this->assertFalse($result->isForbidden(), 'Operators keep taxonomy access.');
  }

  /**
   * Internal status definitions use the same staff gate as service statuses.
   */
  public function testInternalStatusDefinitionForbiddenForCitizen(): void {
    $this->setFeatureFlag(TRUE);
    $result = markaspot_open311_entity_field_access(
      'view',
      $this->fieldDefinition('field_status_definition', 'taxonomy_term', 'internal_status'),
      $this->account(['access content']),
      $this->statusTermItems(),
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * Staff can read internal status definitions when the feature is enabled.
   */
  public function testInternalStatusDefinitionAllowedForStaffWhenEnabled(): void {
    $this->setFeatureFlag(TRUE);
    $result = markaspot_open311_entity_field_access(
      'view',
      $this->fieldDefinition('field_status_definition', 'taxonomy_term', 'internal_status'),
      $this->account(['manage dashboard notes']),
      $this->statusTermItems(),
    );
    $this->assertFalse($result->isForbidden(), 'Staff access is not forbidden for internal status definitions.');
  }

  /**
   * Internal status term labels are hidden from citizens.
   */
  public function testInternalStatusTermViewForbiddenForCitizen(): void {
    $result = markaspot_open311_entity_access(
      $this->entity('taxonomy_term', 'internal_status'),
      'view',
      $this->account(['access content']),
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * Staff term access remains neutral so normal taxonomy access can apply.
   */
  public function testInternalStatusTermViewNeutralForStaff(): void {
    $result = markaspot_open311_entity_access(
      $this->entity('taxonomy_term', 'internal_status'),
      'view',
      $this->account(['manage dashboard notes']),
    );
    $this->assertTrue($result->isNeutral(), 'Staff internal-status term access is delegated to normal taxonomy access.');
  }

  /**
   * Creating internal status terms is also staff-only.
   */
  public function testInternalStatusTermCreateForbiddenForCitizen(): void {
    $result = markaspot_open311_entity_create_access(
      $this->account(['access content']),
      ['entity_type_id' => 'taxonomy_term'],
      'internal_status',
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * Unrelated taxonomy terms are not affected by the internal status guard.
   */
  public function testServiceStatusEntityAccessIsNeutral(): void {
    $result = markaspot_open311_entity_access(
      $this->entity('taxonomy_term', 'service_status'),
      'view',
      $this->account(['access content']),
    );
    $this->assertTrue($result->isNeutral(), 'Service status term access is not changed.');
  }

  /**
   * Field_status_attributes edit is forbidden for everyone.
   *
   * Even an account explicitly holding 'edit field_status_attributes' cannot
   * write the field interactively; the dashboard controller writes it
   * programmatically, which does not invoke hook_entity_field_access.
   */
  public function testStatusAttributesEditForbiddenEvenWithPermission(): void {
    $this->setFeatureFlag(TRUE);
    $result = markaspot_open311_entity_field_access(
      'edit',
      $this->fieldDefinition('field_status_attributes', 'paragraph', 'status'),
      $this->account([
        'edit field_status_attributes',
        'view field_status_attributes',
        'administer taxonomy',
      ]),
      NULL,
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * Field_status_attributes view is forbidden without the field permission.
   */
  public function testStatusAttributesViewForbiddenWithoutPermission(): void {
    $this->setFeatureFlag(TRUE);
    $result = markaspot_open311_entity_field_access(
      'view',
      $this->fieldDefinition('field_status_attributes', 'paragraph', 'status'),
      $this->account([]),
      NULL,
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * Field_status_attributes view is allowed with the view field permission.
   */
  public function testStatusAttributesViewAllowedWithPermission(): void {
    $this->setFeatureFlag(TRUE);
    $result = markaspot_open311_entity_field_access(
      'view',
      $this->fieldDefinition('field_status_attributes', 'paragraph', 'status'),
      $this->account(['view field_status_attributes']),
      NULL,
    );
    $this->assertFalse($result->isForbidden(), 'View is permitted with the field permission.');
  }

  /**
   * Service request authors are hidden from anonymous JSON:API consumers.
   */
  public function testServiceRequestAuthorForbiddenForAnonymous(): void {
    $result = markaspot_open311_entity_field_access(
      'view',
      $this->fieldDefinition('uid', 'node', 'service_request'),
      $this->account([]),
      $this->serviceRequestItems(),
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * Service request authors are hidden from non-manager authenticated users.
   */
  public function testServiceRequestAuthorForbiddenForNonManager(): void {
    $result = markaspot_open311_entity_field_access(
      'view',
      $this->fieldDefinition('uid', 'node', 'service_request'),
      $this->account(['access open311 extension']),
      $this->serviceRequestItems(),
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * Dashboard managers can read authors inside their own jurisdiction.
   */
  public function testServiceRequestAuthorNeutralForJurisdictionManager(): void {
    $entity = $this->entity('node', 'service_request');
    $account = $this->account(['access open311 advanced properties']);
    $this->setGeoreportProcessor($entity, 42, TRUE, TRUE);

    $result = markaspot_open311_entity_field_access(
      'view',
      $this->fieldDefinition('uid', 'node', 'service_request'),
      $account,
      $this->serviceRequestItems($entity),
    );
    $this->assertTrue($result->isNeutral(), 'Jurisdiction managers keep normal uid field access.');
    $this->assertContains('user', $result->getCacheContexts());
    $this->assertContains('user.permissions', $result->getCacheContexts());
    $this->assertSame(0, $result->getCacheMaxAge());
  }

  /**
   * Dashboard managers cannot read authors for foreign jurisdictions.
   */
  public function testServiceRequestAuthorForbiddenForForeignManager(): void {
    $entity = $this->entity('node', 'service_request');
    $account = $this->account(['access open311 advanced properties']);
    $this->setGeoreportProcessor($entity, 42, TRUE, FALSE);

    $result = markaspot_open311_entity_field_access(
      'view',
      $this->fieldDefinition('uid', 'node', 'service_request'),
      $account,
      $this->serviceRequestItems($entity),
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
    $this->assertContains('user', $result->getCacheContexts());
    $this->assertContains('user.permissions', $result->getCacheContexts());
    $this->assertSame(0, $result->getCacheMaxAge());
  }

  /**
   * Unresolvable jurisdictions fail closed in multi-tenant installs.
   */
  public function testServiceRequestAuthorForbiddenWhenJurisdictionIsUnknownInMultiTenant(): void {
    $entity = $this->entity('node', 'service_request');
    $account = $this->account(['access open311 advanced properties']);
    $this->setGeoreportProcessor($entity, NULL, TRUE, FALSE);

    $result = markaspot_open311_entity_field_access(
      'view',
      $this->fieldDefinition('uid', 'node', 'service_request'),
      $account,
      $this->serviceRequestItems($entity),
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * Legacy single-tenant installs keep normal author field access for managers.
   */
  public function testServiceRequestAuthorNeutralWhenJurisdictionIsUnknownInSingleTenant(): void {
    $entity = $this->entity('node', 'service_request');
    $account = $this->account(['access open311 advanced properties']);
    $this->setGeoreportProcessor($entity, NULL, FALSE, FALSE);

    $result = markaspot_open311_entity_field_access(
      'view',
      $this->fieldDefinition('uid', 'node', 'service_request'),
      $account,
      $this->serviceRequestItems($entity),
    );
    $this->assertTrue($result->isNeutral(), 'Single-tenant managers keep normal uid field access.');
  }

  /**
   * Unrelated fields return a neutral result.
   */
  public function testUnrelatedFieldIsNeutral(): void {
    $this->setFeatureFlag(TRUE);
    $result = markaspot_open311_entity_field_access(
      'view',
      $this->fieldDefinition('field_address', 'node', 'service_request'),
      $this->account([]),
      NULL,
    );
    $this->assertTrue($result->isNeutral(), 'Unrelated fields are not gated.');
  }

  /**
   * Operations on field_status_definition under test.
   *
   * @return array<string, array{string}>
   *   The view and edit operations.
   */
  public static function definitionOperationProvider(): array {
    return [
      'view' => ['view'],
      'edit' => ['edit'],
    ];
  }

}
