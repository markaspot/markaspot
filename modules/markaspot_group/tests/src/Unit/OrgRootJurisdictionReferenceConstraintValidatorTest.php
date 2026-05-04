<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Plugin\Validation\Constraint\OrgRootJurisdictionReferenceConstraint;
use Drupal\markaspot_group\Plugin\Validation\Constraint\OrgRootJurisdictionReferenceConstraintValidator;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Tests organisation root jurisdiction validation.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Plugin\Validation\Constraint\OrgRootJurisdictionReferenceConstraintValidator
 */
class OrgRootJurisdictionReferenceConstraintValidatorTest extends UnitTestCase {

  /**
   * Tests root jurisdictions are accepted.
   *
   * @covers ::validate
   */
  public function testRootJurisdictionIsAccepted(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $validator = new OrgRootJurisdictionReferenceConstraintValidator();
    $validator->initialize($context);
    $validator->validate(
      $this->orgReferencing($this->jurisdiction(FALSE)),
      new OrgRootJurisdictionReferenceConstraint(),
    );
  }

  /**
   * Tests child jurisdictions are rejected.
   *
   * @covers ::validate
   */
  public function testChildJurisdictionIsRejected(): void {
    $constraint = new OrgRootJurisdictionReferenceConstraint();
    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())
      ->method('atPath')
      ->with('field_jurisdiction')
      ->willReturnSelf();
    $builder->expects($this->once())
      ->method('addViolation');

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with($constraint->message)
      ->willReturn($builder);

    $validator = new OrgRootJurisdictionReferenceConstraintValidator();
    $validator->initialize($context);
    $validator->validate($this->orgReferencing($this->jurisdiction(TRUE)), $constraint);
  }

  /**
   * Tests non-organisation groups are ignored.
   *
   * @covers ::validate
   */
  public function testNonOrganisationGroupIsIgnored(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $validator = new OrgRootJurisdictionReferenceConstraintValidator();
    $validator->initialize($context);
    $validator->validate($this->jurisdiction(TRUE), new OrgRootJurisdictionReferenceConstraint());
  }

  /**
   * Creates an organisation group referencing a jurisdiction.
   */
  protected function orgReferencing(GroupInterface $jurisdiction): GroupInterface {
    $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('referencedEntities')->willReturn([$jurisdiction]);

    $org = $this->createMock(GroupInterface::class);
    $org->method('bundle')->willReturn('org');
    $org->method('hasField')
      ->with('field_jurisdiction')
      ->willReturn(TRUE);
    $org->method('get')
      ->with('field_jurisdiction')
      ->willReturn($field);

    return $org;
  }

  /**
   * Creates a jurisdiction group.
   */
  protected function jurisdiction(bool $hasParent): GroupInterface {
    $parent_field = $this->createMock(FieldItemListInterface::class);
    $parent_field->method('isEmpty')->willReturn(!$hasParent);

    $jurisdiction = $this->createMock(GroupInterface::class);
    $jurisdiction->method('bundle')->willReturn('jur');
    $jurisdiction->method('hasField')
      ->with('field_parent_jurisdiction')
      ->willReturn(TRUE);
    $jurisdiction->method('get')
      ->with('field_parent_jurisdiction')
      ->willReturn($parent_field);

    return $jurisdiction;
  }

}
