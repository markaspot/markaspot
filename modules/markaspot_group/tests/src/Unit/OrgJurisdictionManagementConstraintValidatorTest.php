<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Plugin\Validation\Constraint\OrgJurisdictionManagementConstraint;
use Drupal\markaspot_group\Plugin\Validation\Constraint\OrgJurisdictionManagementConstraintValidator;
use Drupal\markaspot_group\Service\OrganisationManagementAccess;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

require_once dirname(__DIR__, 3) . '/src/Plugin/Validation/Constraint/OrgJurisdictionManagementConstraint.php';
require_once dirname(__DIR__, 3) . '/src/Plugin/Validation/Constraint/OrgJurisdictionManagementConstraintValidator.php';

/**
 * Tests organisation jurisdiction management validation.
 */
#[CoversClass(OrgJurisdictionManagementConstraintValidator::class)]
#[Group('markaspot_group')]
final class OrgJurisdictionManagementConstraintValidatorTest extends UnitTestCase {

  /**
   * Tests a foreign jurisdiction creates a field-addressable violation.
   */
  public function testForeignJurisdictionIsRejected(): void {
    $constraint = new OrgJurisdictionManagementConstraint();
    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())
      ->method('atPath')
      ->with('field_jurisdiction')
      ->willReturnSelf();
    $builder->expects($this->once())->method('addViolation');
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with($constraint->message)
      ->willReturn($builder);

    $account = $this->account(12);
    $access = $this->createMock(OrganisationManagementAccess::class);
    $access->method('hasBypass')->willReturn(FALSE);
    $access->method('canManageJurisdiction')
      ->with($account, 9)
      ->willReturn(FALSE);
    $validator = new OrgJurisdictionManagementConstraintValidator($account, $access);
    $validator->initialize($context);

    $validator->validate($this->organisation(9), $constraint);
  }

  /**
   * Tests uid 0 programmatic validation does not depend on a user context.
   */
  public function testUidZeroProgrammaticSaveIsAccepted(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');
    $account = $this->account(0);
    $access = $this->createMock(OrganisationManagementAccess::class);
    $access->expects($this->never())->method('canManageJurisdiction');
    $validator = new OrgJurisdictionManagementConstraintValidator($account, $access);
    $validator->initialize($context);

    $validator->validate(
      $this->organisation(9),
      new OrgJurisdictionManagementConstraint(),
    );
  }

  /**
   * Creates an organisation with one jurisdiction target.
   */
  private function organisation(int $jurisdictionId): GroupInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->with('target_id')->willReturn($jurisdictionId);

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('org');
    $group->method('hasField')->with('field_jurisdiction')->willReturn(TRUE);
    $group->method('get')->with('field_jurisdiction')->willReturn($field);
    return $group;
  }

  /**
   * Creates an account with the supplied ID.
   */
  private function account(int $id): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($id);
    return $account;
  }

}
