<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\markaspot_group\Service\VerticalDefinitionRepository;
use Drupal\Tests\UnitTestCase;

/**
 * Tests shipped vertical definition discovery and validation.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Service\VerticalDefinitionRepository
 */
class VerticalDefinitionRepositoryTest extends UnitTestCase {

  /**
   * Tests all required definitions and measured HOA wording are shipped.
   *
   * @covers ::getAvailableIds
   * @covers ::load
   */
  public function testRequiredDefinitionsAreAvailable(): void {
    $repository = $this->createRepository(dirname(__DIR__, 3));

    $this->assertSame(
      ['hoa', 'municipal'],
      $repository->getAvailableIds(),
    );

    $municipal = $repository->load('municipal');
    $this->assertSame([], $municipal['entities']);
    $this->assertSame([], $municipal['overrides']);
    $this->assertSame([], $municipal['statuses']);

    $hoa = $repository->load('hoa');
    $this->assertSame('Communities', $hoa['entities']['en']['jurisdiction']['plural']);
    $this->assertSame('Dienstleister', $hoa['entities']['de']['organisation']['singular']);
    $this->assertCount(65, $hoa['overrides']['en']);
    $this->assertSame(
      'Assigned to {name}, contractor: {organisation}',
      $hoa['overrides']['en']['dashboard.assignment.assigned_success_with_organisation'],
    );
    $this->assertSame(
      'Contractor',
      $hoa['overrides']['en']['print.organisation'],
    );
    $this->assertSame([
      'match' => 'Assigned to Vendor',
      'name' => 'Assigned to Contractor',
    ], $hoa['statuses']['en'][0]);
  }

  /**
   * Tests missing definition directories are safe and unknown IDs are clear.
   *
   * @covers ::getAvailableIds
   * @covers ::load
   */
  public function testMissingDirectoryAndUnknownVerticalAreHandledCleanly(): void {
    $repository = $this->createRepository(
      dirname(__DIR__, 3) . '/tests/fixtures/no-vertical-module',
    );

    $this->assertSame([], $repository->getAvailableIds());
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
      'Unknown vertical "unknown". Available verticals: (none).',
    );
    $repository->load('unknown');
  }

  /**
   * Tests an unknown ID lists every shipped definition.
   *
   * @covers ::load
   */
  public function testUnknownVerticalListsAvailableDefinitions(): void {
    $repository = $this->createRepository(dirname(__DIR__, 3));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
      'Unknown vertical "unknown". Available verticals: hoa, municipal.',
    );
    $repository->load('unknown');
  }

  /**
   * Creates a repository using a controlled module root.
   */
  protected function createRepository(string $moduleRoot): VerticalDefinitionRepository {
    $moduleExtensionList = $this->createMock(ModuleExtensionList::class);
    $moduleExtensionList->method('getPath')
      ->with('markaspot_group')
      ->willReturn($moduleRoot);
    return new VerticalDefinitionRepository($moduleExtensionList);
  }

}
