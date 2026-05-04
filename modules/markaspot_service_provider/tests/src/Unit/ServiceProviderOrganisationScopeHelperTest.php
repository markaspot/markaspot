<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_service_provider\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\markaspot_service_provider\ServiceProviderOrganisationScopeHelper;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests service provider organisation scope matching.
 *
 * @group markaspot_service_provider
 * @coversDefaultClass \Drupal\markaspot_service_provider\ServiceProviderOrganisationScopeHelper
 */
class ServiceProviderOrganisationScopeHelperTest extends UnitTestCase {

  /**
   * Tests unscoped providers remain available for any request organisation.
   *
   * @covers ::providerMatchesRequest
   */
  public function testUnscopedProviderMatchesRequest(): void {
    $this->assertTrue(ServiceProviderOrganisationScopeHelper::providerMatchesRequest(
      $this->provider([]),
      $this->serviceRequest([10]),
    ));
  }

  /**
   * Tests scoped providers match intersecting request organisations.
   *
   * @covers ::providerMatchesRequest
   */
  public function testScopedProviderMatchesIntersectingRequest(): void {
    $this->assertTrue(ServiceProviderOrganisationScopeHelper::providerMatchesRequest(
      $this->provider([20, 30]),
      $this->serviceRequest([10, 30]),
    ));
  }

  /**
   * Tests scoped providers do not match other request organisations.
   *
   * @covers ::providerMatchesRequest
   */
  public function testScopedProviderDoesNotMatchOtherRequestOrganisation(): void {
    $this->assertFalse(ServiceProviderOrganisationScopeHelper::providerMatchesRequest(
      $this->provider([20]),
      $this->serviceRequest([10]),
    ));
  }

  /**
   * Tests scoped providers do not match requests without organisation.
   *
   * @covers ::providerMatchesRequest
   */
  public function testScopedProviderDoesNotMatchMissingRequestOrganisation(): void {
    $this->assertFalse(ServiceProviderOrganisationScopeHelper::providerMatchesRequest(
      $this->provider([20]),
      $this->serviceRequest([]),
    ));
  }

  /**
   * Tests scoped service-provider boilerplates match request scope.
   *
   * @covers ::boilerplateMatchesRequest
   */
  public function testScopedBoilerplateMatchesRequestScope(): void {
    $this->assertTrue(ServiceProviderOrganisationScopeHelper::boilerplateMatchesRequest(
      $this->boilerplate('service_provider', [20], [5]),
      $this->serviceRequest([20], [5]),
    ));
  }

  /**
   * Tests boilerplates scoped to other organisations are rejected.
   *
   * @covers ::boilerplateMatchesRequest
   */
  public function testScopedBoilerplateDoesNotMatchOtherOrganisation(): void {
    $this->assertFalse(ServiceProviderOrganisationScopeHelper::boilerplateMatchesRequest(
      $this->boilerplate('service_provider', [30], [5]),
      $this->serviceRequest([20], [5]),
    ));
  }

  /**
   * Tests non-service-provider boilerplates are rejected.
   *
   * @covers ::boilerplateMatchesRequest
   */
  public function testStatusBoilerplateDoesNotMatchServiceProviderSlot(): void {
    $this->assertFalse(ServiceProviderOrganisationScopeHelper::boilerplateMatchesRequest(
      $this->boilerplate('status_notes', [], [5]),
      $this->serviceRequest([20], [5]),
    ));
  }

  /**
   * Creates a service request node stub.
   */
  protected function serviceRequest(array $organisation_ids, array $jurisdiction_ids = []): NodeInterface {
    $fields = [
      'field_organisation' => $this->fieldWithTargetIds($organisation_ids),
      'field_jurisdiction' => $this->fieldWithTargetIds($jurisdiction_ids),
    ];

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $node->method('get')
      ->willReturnCallback(static fn(string $name): mixed => $fields[$name]);

    return $node;
  }

  /**
   * Creates a service provider taxonomy term stub.
   */
  protected function provider(array $organisation_ids): TermInterface {
    $field = $this->fieldWithTargetIds($organisation_ids);

    $term = $this->createMock(TermInterface::class);
    $term->method('hasField')
      ->with('field_organisation')
      ->willReturn(TRUE);
    $term->method('get')
      ->with('field_organisation')
      ->willReturn($field);

    return $term;
  }

  /**
   * Creates a boilerplate node stub.
   */
  protected function boilerplate(string $type, array $organisation_ids, array $jurisdiction_ids): NodeInterface {
    $fields = [
      'field_boilerplate_type' => $this->fieldWithValues([['value' => $type]]),
      'field_organisation' => $this->fieldWithTargetIds($organisation_ids),
      'field_jurisdiction' => $this->fieldWithTargetIds($jurisdiction_ids),
    ];

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('boilerplate');
    $node->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $node->method('get')
      ->willReturnCallback(static fn(string $name): mixed => $fields[$name]);

    return $node;
  }

  /**
   * Creates an entity reference field item list stub.
   */
  protected function fieldWithTargetIds(array $ids): FieldItemListInterface {
    return $this->fieldWithValues(array_map(
      static fn(int $id): array => ['target_id' => $id],
      $ids,
    ));
  }

  /**
   * Creates a field item list stub.
   */
  protected function fieldWithValues(array $values): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($values === []);
    $field->method('getValue')->willReturn($values);

    return $field;
  }

}
