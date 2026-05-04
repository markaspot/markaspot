<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Plugin\Validation\Constraint\JurisdictionParentReferenceConstraint;
use Drupal\markaspot_group\Plugin\Validation\Constraint\JurisdictionParentReferenceConstraintValidator;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Tests jurisdiction parent reference validation.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Plugin\Validation\Constraint\JurisdictionParentReferenceConstraintValidator
 */
class JurisdictionParentReferenceConstraintValidatorTest extends UnitTestCase {

  /**
   * Tests a jurisdiction cannot reference itself as parent.
   *
   * @covers ::validate
   */
  public function testSelfParentReferenceIsRejected(): void {
    $constraint = new JurisdictionParentReferenceConstraint();
    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())
      ->method('atPath')
      ->with('field_parent_jurisdiction')
      ->willReturnSelf();
    $builder->expects($this->once())
      ->method('addViolation');

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with($constraint->selfReferenceMessage)
      ->willReturn($builder);

    $jurisdiction = $this->jurisdiction(7);
    $this->setParent($jurisdiction, $jurisdiction);

    $validator = new JurisdictionParentReferenceConstraintValidator();
    $validator->initialize($context);
    $validator->validate($jurisdiction, $constraint);
  }

  /**
   * Tests a different parent jurisdiction is accepted.
   *
   * @covers ::validate
   */
  public function testDifferentParentReferenceIsAccepted(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $jurisdiction = $this->jurisdiction(7);
    $this->setParent($jurisdiction, $this->jurisdiction(1));

    $validator = new JurisdictionParentReferenceConstraintValidator();
    $validator->initialize($context);
    $validator->validate($jurisdiction, new JurisdictionParentReferenceConstraint());
  }

  /**
   * Tests non-jurisdiction groups are ignored.
   *
   * @covers ::validate
   */
  public function testNonJurisdictionGroupIsIgnored(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('org');

    $validator = new JurisdictionParentReferenceConstraintValidator();
    $validator->initialize($context);
    $validator->validate($group, new JurisdictionParentReferenceConstraint());
  }

  /**
   * Creates a jurisdiction group.
   */
  protected function jurisdiction(int $id): GroupInterface {
    $jurisdiction = $this->createMock(GroupInterface::class);
    $jurisdiction->method('id')->willReturn((string) $id);
    $jurisdiction->method('bundle')->willReturn('jur');
    return $jurisdiction;
  }

  /**
   * Sets a parent field mock on a jurisdiction group.
   */
  protected function setParent(GroupInterface $jurisdiction, GroupInterface $parent): void {
    $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('referencedEntities')->willReturn([$parent]);

    $jurisdiction->method('hasField')
      ->with('field_parent_jurisdiction')
      ->willReturn(TRUE);
    $jurisdiction->method('get')
      ->with('field_parent_jurisdiction')
      ->willReturn($field);
  }

}
