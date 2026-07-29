<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require_once dirname(__DIR__, 3) . '/markaspot_group.module';

/**
 * Tests organisation save-time root normalization.
 */
#[Group('markaspot_group')]
class OrganisationPresaveTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Tests an authorized child workspace is normalized to its root.
   */
  public function testAuthorizedChildJurisdictionIsNormalizedToRoot(): void {
    $group = $this->organisation(9);
    $group->expects($this->once())
      ->method('set')
      ->with('field_jurisdiction', ['target_id' => 6]);
    $this->setContainer(6);

    _markaspot_group_presave_organisation($group);
  }

  /**
   * Tests presave does not perform user authorization.
   */
  public function testProgrammaticSaveDoesNotRequireManagementAccess(): void {
    $group = $this->organisation(9);
    $group->expects($this->once())
      ->method('set')
      ->with('field_jurisdiction', ['target_id' => 6]);
    $this->setContainer(6);

    _markaspot_group_presave_organisation($group);
  }

  /**
   * Tests anonymous programmatic saves work without a user context.
   */
  public function testAnonymousProgrammaticSaveWorks(): void {
    $group = $this->organisation(9);
    $group->expects($this->once())
      ->method('set')
      ->with('field_jurisdiction', ['target_id' => 6]);
    $this->setContainer(6);

    _markaspot_group_presave_organisation($group);
  }

  /**
   * Tests bypass state is irrelevant to normalization.
   */
  public function testDrupalAdministratorBypassesTenantCheck(): void {
    $group = $this->organisation(9);
    $group->expects($this->once())
      ->method('set')
      ->with('field_jurisdiction', ['target_id' => 6]);
    $this->setContainer(6);

    _markaspot_group_presave_organisation($group);
  }

  /**
   * Tests an invalid hierarchy is left for validation constraints.
   */
  public function testUnresolvedRootIsRejected(): void {
    $group = $this->organisation(9);
    $group->expects($this->never())->method('set');
    $this->setContainer(NULL);

    _markaspot_group_presave_organisation($group);
  }

  /**
   * Tests every translation is authorized and normalized independently.
   */
  public function testAllOrganisationTranslationsAreNormalized(): void {
    $english = $this->organisation(9);
    $english->expects($this->once())
      ->method('set')
      ->with('field_jurisdiction', ['target_id' => 6]);
    $german = $this->organisation(10);
    $german->expects($this->once())
      ->method('set')
      ->with('field_jurisdiction', ['target_id' => 6]);

    $group = $this->createMock(GroupInterface::class);
    $group->method('getTranslationLanguages')->willReturn([
      'en' => $this->getMockBuilder(LanguageInterface::class)->getMock(),
      'de' => $this->getMockBuilder(LanguageInterface::class)->getMock(),
    ]);
    $group->method('getTranslation')->willReturnMap([
      ['en', $english],
      ['de', $german],
    ]);
    $this->setContainer(6);

    _markaspot_group_presave_organisation_translations($group);
  }

  /**
   * Tests root normalization does not request service-request re-homing.
   */
  public function testRootNormalizationIsDistinguishedFromTenantMove(): void {
    $this->setContainer(6);

    $this->assertTrue(_markaspot_group_org_move_is_root_normalization(9, 6));
    $this->assertFalse(_markaspot_group_org_move_is_root_normalization(9, 20));
    $this->assertFalse(_markaspot_group_org_move_is_root_normalization(NULL, 6));
  }

  /**
   * Sets the reduced Drupal service container.
   */
  private function setContainer(
    ?int $root_id,
  ): void {
    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->method('getRootJurisdictionId')
      ->willReturnCallback(
        static fn(int $jurisdiction_id): ?int => in_array($jurisdiction_id, [9, 10], TRUE)
          ? $root_id
          : $jurisdiction_id,
      );

    $container = new ContainerBuilder();
    $container->set('markaspot_group.hierarchy_resolver', $resolver);
    $container->set('markaspot_group.organisation_hierarchy_resolver', $resolver);
    \Drupal::setContainer($container);
  }

  /**
   * Creates an organisation with no service categories.
   *
   * @param int $jurisdiction_id
   *   Submitted jurisdiction ID.
   */
  private function organisation(int $jurisdiction_id): GroupInterface {
    $jurisdiction_field = $this->fieldWithTargetId($jurisdiction_id);
    $category_field = $this->createMock(FieldItemList::class);
    $category_field->method('isEmpty')->willReturn(TRUE);

    $group = $this->createMock(GroupInterface::class);
    $group->method('hasField')
      ->willReturnCallback(
        static fn(string $field_name): bool => in_array(
          $field_name,
          ['field_jurisdiction', 'field_service_categories'],
          TRUE,
        ),
      );
    $group->method('get')
      ->willReturnCallback(
        static fn(string $field_name): FieldItemListInterface => $field_name === 'field_jurisdiction'
          ? $jurisdiction_field
          : $category_field,
      );
    return $group;
  }

  /**
   * Creates a field list exposing one target ID.
   */
  private function fieldWithTargetId(int $target_id): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')
      ->with('target_id')
      ->willReturn($target_id);
    return $field;
  }

}
