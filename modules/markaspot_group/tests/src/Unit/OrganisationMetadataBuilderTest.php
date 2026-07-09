<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\OrganisationMetadataBuilder;
use Drupal\markaspot_group\Service\OrgHierarchyResolverInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests organisation picker metadata construction.
 */
#[CoversClass(OrganisationMetadataBuilder::class)]
#[Group('markaspot_group')]
final class OrganisationMetadataBuilderTest extends UnitTestCase {

  /**
   * Tests that metadata is built only once for each organisation ID.
   */
  public function testBuildMemoizesMetadataPerOrganisation(): void {
    $organisation = $this->createMock(GroupInterface::class);
    $organisation->method('id')->willReturn(10);
    $organisation->method('bundle')->willReturn('org');
    $organisation->method('hasField')
      ->with('field_org_code')
      ->willReturn(FALSE);
    $organisation->method('get')
      ->willReturn($this->createMock(FieldItemListInterface::class));

    $parent = $this->createMock(GroupInterface::class);
    $parent->method('label')->willReturn('Parent');
    $parent->method('getCacheTags')->willReturn(['group:20']);
    $parent->method('getCacheContexts')->willReturn([]);
    $parent->method('getCacheMaxAge')->willReturn(-1);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadMultiple')
      ->with([20])
      ->willReturn([20 => $parent]);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($storage);

    $hierarchyResolver = $this->createMock(OrgHierarchyResolverInterface::class);
    $hierarchyResolver->expects($this->once())
      ->method('getAncestorIds')
      ->with(10)
      ->willReturn([20]);
    $hierarchyResolver->expects($this->once())
      ->method('getChildIds')
      ->with(10)
      ->willReturn([]);

    $builder = new OrganisationMetadataBuilder($entityTypeManager, $hierarchyResolver);
    $expected = [
      'code' => '',
      'level' => 1,
      'path_labels' => ['Parent'],
      'child_count' => 0,
    ];

    $this->assertSame($expected, $builder->build($organisation));

    $cacheMetadata = new CacheableMetadata();
    $this->assertSame($expected, $builder->build($organisation, $cacheMetadata));
    $this->assertSame(['group:20'], $cacheMetadata->getCacheTags());
  }

}
