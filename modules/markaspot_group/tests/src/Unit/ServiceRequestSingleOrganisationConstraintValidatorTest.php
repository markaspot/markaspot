<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\markaspot_group\Plugin\Validation\Constraint\ServiceRequestSingleOrganisationConstraint;
use Drupal\markaspot_group\Plugin\Validation\Constraint\ServiceRequestSingleOrganisationConstraintValidator;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Tests the optional service request organisation limit.
 */
#[CoversClass(ServiceRequestSingleOrganisationConstraintValidator::class)]
#[Group('markaspot_group')]
class ServiceRequestSingleOrganisationConstraintValidatorTest extends UnitTestCase {

  /**
   * Tests that multiple organisations are accepted when the setting is off.
   */
  public function testMultipleOrganisationsAcceptedWhenDisabled(): void {
    $validator = $this->validator(FALSE, FALSE);

    $validator->validate(
      $this->organisationField(2),
      new ServiceRequestSingleOrganisationConstraint(),
    );
  }

  /**
   * Tests allowed organisation counts when the setting is on.
   */
  #[DataProvider('allowedOrganisationCounts')]
  public function testZeroOrOneOrganisationAcceptedWhenEnabled(int $count): void {
    $validator = $this->validator(TRUE, FALSE);

    $validator->validate(
      $this->organisationField($count),
      new ServiceRequestSingleOrganisationConstraint(),
    );
  }

  /**
   * Provides organisation counts that remain valid.
   *
   * @return array<string, array{int}>
   *   Valid organisation counts.
   */
  public static function allowedOrganisationCounts(): array {
    return [
      'empty' => [0],
      'single' => [1],
    ];
  }

  /**
   * Tests that multiple organisations are rejected when the setting is on.
   */
  public function testMultipleOrganisationsRejectedWhenEnabled(): void {
    $constraint = new ServiceRequestSingleOrganisationConstraint();
    $validator = $this->validator(TRUE, TRUE, $constraint->message);

    $validator->validate($this->organisationField(2), $constraint);
  }

  /**
   * Creates a validator with the requested setting and expectation.
   */
  protected function validator(
    bool $enabled,
    bool $expectsViolation,
    string $message = '',
  ): ServiceRequestSingleOrganisationConstraintValidator {
    $context = $this->createMock(ExecutionContextInterface::class);
    if ($expectsViolation) {
      $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
      $builder->expects($this->once())->method('addViolation');
      $context->expects($this->once())
        ->method('buildViolation')
        ->with($message)
        ->willReturn($builder);
    }
    else {
      $context->expects($this->never())->method('buildViolation');
    }

    $config_factory = $this->getConfigFactoryStub([
      'markaspot_group.settings' => [
        'single_organisation_assignment' => $enabled,
      ],
    ]);
    $validator = new ServiceRequestSingleOrganisationConstraintValidator(
      $config_factory,
    );
    $validator->initialize($context);

    return $validator;
  }

  /**
   * Creates a service request organisation field with the requested count.
   */
  protected function organisationField(int $count): FieldItemListInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');

    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getEntity')->willReturn($node);
    $field->method('count')->willReturn($count);

    return $field;
  }

}
