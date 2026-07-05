<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Plugin\Validation\Constraint\OrgParentReferenceConstraint;
use Drupal\markaspot_group\Plugin\Validation\Constraint\OrgParentReferenceConstraintValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Tests organisation parent reference validation.
 */
#[CoversClass(OrgParentReferenceConstraintValidator::class)]
#[Group('markaspot_group')]
class OrgParentReferenceConstraintValidatorTest extends UnitTestCase {

  /**
   * Field maps keyed by the mock object ID.
   *
   * @var array<int, \ArrayObject<string, \Drupal\Core\Field\FieldItemListInterface>>
   */
  protected array $fieldMaps = [];

  /**
   * Tests a valid parent in the same jurisdiction is accepted.
   */
  public function testValidParentInSameJurisdictionIsAccepted(): void {
    $parent = $this->org(20, 1);
    $child = $this->org(10, 1, $parent);

    $validator = $this->validatorExpectingNoViolation();
    $validator->validate($child, new OrgParentReferenceConstraint());
  }

  /**
   * Tests a parent from another bundle is rejected.
   */
  public function testParentMustBeOrganisation(): void {
    $constraint = new OrgParentReferenceConstraint();
    $parent = $this->group(20, 'jur');
    $child = $this->org(10, 1, $parent);

    $validator = $this->validatorExpectingViolation($constraint, $constraint->parentTypeMessage);
    $validator->validate($child, $constraint);
  }

  /**
   * Tests an organisation cannot reference itself as parent.
   */
  public function testSelfParentReferenceIsRejected(): void {
    $constraint = new OrgParentReferenceConstraint();
    $org = $this->org(10, 1);
    $this->addField($org, 'field_parent_org', $this->parentField($org));

    $validator = $this->validatorExpectingViolation($constraint, $constraint->selfReferenceMessage);
    $validator->validate($org, $constraint);
  }

  /**
   * Tests a parent in another jurisdiction is rejected.
   */
  public function testParentMustBelongToSameJurisdiction(): void {
    $constraint = new OrgParentReferenceConstraint();
    $parent = $this->org(20, 2);
    $child = $this->org(10, 1, $parent);

    $validator = $this->validatorExpectingViolation($constraint, $constraint->jurisdictionMismatchMessage);
    $validator->validate($child, $constraint);
  }

  /**
   * Tests a direct A -> B -> A cycle is rejected.
   */
  public function testDirectCycleIsRejected(): void {
    $constraint = new OrgParentReferenceConstraint();
    $a = $this->org(10, 1);
    $b = $this->org(20, 1, $a);
    $this->addField($a, 'field_parent_org', $this->parentField($b));

    $validator = $this->validatorExpectingViolation($constraint, $constraint->circularReferenceMessage);
    $validator->validate($a, $constraint);
  }

  /**
   * Tests an empty parent field is accepted.
   */
  public function testEmptyParentFieldIsAccepted(): void {
    $org = $this->org(10, 1);

    $validator = $this->validatorExpectingNoViolation();
    $validator->validate($org, new OrgParentReferenceConstraint());
  }

  /**
   * Creates a validator whose context expects no violations.
   */
  protected function validatorExpectingNoViolation(): OrgParentReferenceConstraintValidator {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $validator = new OrgParentReferenceConstraintValidator();
    $validator->initialize($context);

    return $validator;
  }

  /**
   * Creates a validator whose context expects one violation.
   */
  protected function validatorExpectingViolation(
    OrgParentReferenceConstraint $constraint,
    string $message,
  ): OrgParentReferenceConstraintValidator {
    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())
      ->method('atPath')
      ->with('field_parent_org')
      ->willReturnSelf();
    $builder->expects($this->once())
      ->method('addViolation');

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with($message)
      ->willReturn($builder);

    $validator = new OrgParentReferenceConstraintValidator();
    $validator->initialize($context);

    return $validator;
  }

  /**
   * Creates an organisation group with jurisdiction and parent fields.
   */
  protected function org(int $id, int $jurisdictionId, ?GroupInterface $parent = NULL): GroupInterface {
    $org = $this->group($id, 'org');
    $this->addField($org, 'field_jurisdiction', $this->jurisdictionField($jurisdictionId));
    $this->addField($org, 'field_parent_org', $this->parentField($parent));

    return $org;
  }

  /**
   * Creates a group mock with mutable field storage.
   */
  protected function group(int $id, string $bundle): GroupInterface {
    $fields = new \ArrayObject();
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('bundle')->willReturn($bundle);
    $group->method('hasField')
      ->willReturnCallback(static fn(string $field): bool => $fields->offsetExists($field));
    $group->method('get')
      ->willReturnCallback(static function (string $field) use ($fields): FieldItemListInterface {
        if (!$fields->offsetExists($field)) {
          throw new \InvalidArgumentException(sprintf('Field "%s" is not configured on the group mock.', $field));
        }

        return $fields[$field];
      });

    $this->fieldMaps[spl_object_id($group)] = $fields;

    return $group;
  }

  /**
   * Adds or replaces a field on a group mock.
   */
  protected function addField(GroupInterface $group, string $fieldName, FieldItemListInterface $field): void {
    $this->fieldMaps[spl_object_id($group)][$fieldName] = $field;
  }

  /**
   * Creates a parent organisation reference field.
   */
  protected function parentField(?GroupInterface $parent): EntityReferenceFieldItemListInterface {
    $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($parent === NULL);
    $field->method('referencedEntities')->willReturn($parent ? [$parent] : []);

    return $field;
  }

  /**
   * Creates a jurisdiction reference field.
   */
  protected function jurisdictionField(int $jurisdictionId): EntityReferenceFieldItemListInterface {
    $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')
      ->with('target_id')
      ->willReturn(TRUE);
    $field->method('__get')
      ->with('target_id')
      ->willReturn($jurisdictionId);

    return $field;
  }

}
