<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Plugin\Validation\Constraint\OrgServiceCategoriesJurisdictionConstraint;
use Drupal\markaspot_group\Plugin\Validation\Constraint\OrgServiceCategoriesJurisdictionConstraintValidator;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

require_once dirname(__DIR__, 3) . '/src/Plugin/Validation/Constraint/OrgServiceCategoriesJurisdictionConstraint.php';
require_once dirname(__DIR__, 3) . '/src/Plugin/Validation/Constraint/OrgServiceCategoriesJurisdictionConstraintValidator.php';

/**
 * Tests organisation category jurisdiction validation.
 */
#[CoversClass(OrgServiceCategoriesJurisdictionConstraintValidator::class)]
#[Group('markaspot_group')]
final class OrgServiceCategoriesJurisdictionConstraintValidatorTest extends UnitTestCase {

  /**
   * Tests categories from another root create a source-addressable violation.
   */
  public function testForeignCategoryIsRejected(): void {
    $constraint = new OrgServiceCategoriesJurisdictionConstraint();
    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())
      ->method('atPath')
      ->with('field_service_categories')
      ->willReturnSelf();
    $builder->expects($this->once())->method('addViolation');
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with($constraint->message, ['@ids' => '55'])
      ->willReturn($builder);

    $term = $this->createMock(TermInterface::class);
    $term->method('hasField')->with('field_jurisdiction')->willReturn(TRUE);
    $term->method('get')
      ->with('field_jurisdiction')
      ->willReturn($this->targetField(20));
    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termStorage->method('load')->with(55)->willReturn($term);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('taxonomy_term')
      ->willReturn($termStorage);
    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->method('getRootJurisdictionId')
      ->willReturnMap([
        [9, 6],
        [20, 20],
      ]);

    $validator = new OrgServiceCategoriesJurisdictionConstraintValidator(
      $entityTypeManager,
      $resolver,
    );
    $validator->initialize($context);
    $validator->validate($this->organisation(9, [55]), $constraint);
  }

  /**
   * Creates an organisation with jurisdiction and category targets.
   *
   * @param int $jurisdictionId
   *   Jurisdiction group ID.
   * @param int[] $categoryIds
   *   Category term IDs.
   */
  private function organisation(int $jurisdictionId, array $categoryIds): GroupInterface {
    $items = [];
    foreach ($categoryIds as $categoryId) {
      $item = $this->createMock(FieldItemInterface::class);
      $item->method('__get')->with('target_id')->willReturn($categoryId);
      $items[] = $item;
    }
    $categoryField = $this->createMock(FieldItemList::class);
    $categoryField->method('isEmpty')->willReturn($items === []);
    $categoryField->method('getIterator')
      ->willReturn(new \ArrayIterator($items));
    $fields = [
      'field_jurisdiction' => $this->targetField($jurisdictionId),
      'field_service_categories' => $categoryField,
    ];

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('org');
    $group->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $group->method('get')
      ->willReturnCallback(static fn(string $name): FieldItemListInterface => $fields[$name]);
    return $group;
  }

  /**
   * Creates a target-ID field.
   */
  private function targetField(int $targetId): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->with('target_id')->willReturn($targetId);
    return $field;
  }

}
