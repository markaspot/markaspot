<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Plugin\Validation\Constraint\OrgRootJurisdictionReferenceConstraint;
use Drupal\markaspot_group\Plugin\Validation\Constraint\OrgRootJurisdictionReferenceConstraintValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

require_once dirname(__DIR__, 3) . '/src/Plugin/Validation/Constraint/OrgRootJurisdictionReferenceConstraint.php';
require_once dirname(__DIR__, 3) . '/src/Plugin/Validation/Constraint/OrgRootJurisdictionReferenceConstraintValidator.php';

/**
 * Tests organisation root jurisdiction validation.
 */
#[CoversClass(OrgRootJurisdictionReferenceConstraintValidator::class)]
#[Group('markaspot_group')]
class OrgRootJurisdictionReferenceConstraintValidatorTest extends UnitTestCase {

  /**
   * Tests root jurisdictions are accepted.
   */
  public function testRootJurisdictionIsAccepted(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $validator = $this->validatorWithResolvedRoot(6);
    $validator->initialize($context);
    $validator->validate(
      $this->orgReferencing($this->jurisdiction(FALSE)),
      new OrgRootJurisdictionReferenceConstraint(),
    );
  }

  /**
   * Tests child jurisdictions are accepted for presave normalization.
   */
  public function testChildJurisdictionIsAccepted(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $validator = $this->validatorWithResolvedRoot(6);
    $validator->initialize($context);
    $validator->validate(
      $this->orgReferencing($this->jurisdiction(TRUE)),
      new OrgRootJurisdictionReferenceConstraint(),
    );
  }

  /**
   * Tests an invalid hierarchy is rejected.
   */
  public function testInvalidJurisdictionHierarchyIsRejected(): void {
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

    $validator = $this->validatorWithResolvedRoot(NULL);
    $validator->initialize($context);
    $validator->validate(
      $this->orgReferencing($this->jurisdiction(TRUE)),
      $constraint,
    );
  }

  /**
   * Tests non-organisation groups are ignored.
   */
  public function testNonOrganisationGroupIsIgnored(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $validator = $this->validatorWithResolvedRoot(6);
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
    $jurisdiction->method('id')->willReturn($hasParent ? 9 : 6);
    $jurisdiction->method('bundle')->willReturn('jur');
    $jurisdiction->method('hasField')
      ->with('field_parent_jurisdiction')
      ->willReturn(TRUE);
    $jurisdiction->method('get')
      ->with('field_parent_jurisdiction')
      ->willReturn($parent_field);

    return $jurisdiction;
  }

  /**
   * Creates a validator whose hierarchy lookup returns the supplied root.
   */
  protected function validatorWithResolvedRoot(?int $root_id): OrgRootJurisdictionReferenceConstraintValidator {
    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->method('getRootJurisdictionId')->willReturn($root_id);
    return new OrgRootJurisdictionReferenceConstraintValidator(NULL, $resolver);
  }

}
