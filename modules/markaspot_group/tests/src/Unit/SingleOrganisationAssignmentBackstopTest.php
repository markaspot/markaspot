<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the programmatic-save backstop for single-org assignments.
 *
 * @group markaspot_group
 */
final class SingleOrganisationAssignmentBackstopTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_group.module';
  }

  /**
   * Programmatic saves cannot persist multiple org values when enabled.
   */
  public function testRejectsMultipleValuesWhenEnabled(): void {
    $this->setSingleOrganisationAssignment(TRUE);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Service request 42 cannot be assigned to more than one organisation.');

    _markaspot_group_enforce_single_organisation_assignment($this->nodeWithOrganisationCount(2));
  }

  /**
   * Multi-org tenants retain their existing programmatic-save behavior.
   */
  public function testAllowsMultipleValuesWhenDisabled(): void {
    $this->setSingleOrganisationAssignment(FALSE);

    _markaspot_group_enforce_single_organisation_assignment($this->nodeWithOrganisationCount(2));
    $this->addToAssertionCount(1);
  }

  /**
   * Internal relationship reconciliation has the same hard backstop.
   */
  public function testRejectsMultipleRelationshipIdsWhenEnabled(): void {
    $this->setSingleOrganisationAssignment(TRUE);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Service request 42 cannot reconcile more than one organisation.');

    _markaspot_group_assert_single_organisation_ids([10, 20], 42);
  }

  /**
   * Installs a config factory with the requested feature state.
   */
  private function setSingleOrganisationAssignment(bool $enabled): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('single_organisation_assignment')
      ->willReturn($enabled);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('markaspot_group.settings')
      ->willReturn($config);

    $container = new ContainerBuilder();
    $container->set('config.factory', $config_factory);
    \Drupal::setContainer($container);
  }

  /**
   * Creates a service request with a mocked organisation field count.
   */
  private function nodeWithOrganisationCount(int $count): NodeInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($count === 0);
    $field->method('getValue')->willReturn(array_map(
      static fn(int $target_id): array => ['target_id' => $target_id],
      range(1, $count),
    ));

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(42);
    $node->method('hasField')
      ->with('field_organisation')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('field_organisation')
      ->willReturn($field);

    return $node;
  }

}
