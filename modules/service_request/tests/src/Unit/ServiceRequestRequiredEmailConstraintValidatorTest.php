<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\service_request\Plugin\Validation\Constraint\ServiceRequestRequiredEmailConstraint;
use Drupal\service_request\Plugin\Validation\Constraint\ServiceRequestRequiredEmailConstraintValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

require_once dirname(__DIR__, 3) . '/service_request.module';

/**
 * Tests lifecycle-aware required email validation.
 */
#[CoversClass(ServiceRequestRequiredEmailConstraintValidator::class)]
final class ServiceRequestRequiredEmailConstraintValidatorTest extends UnitTestCase {

  /**
   * New reports still require the configured email field.
   */
  public function testNewEmptyEmailIsRejected(): void {
    [$validator, $context, $field] = $this->validator(TRUE, NULL);
    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with('This value should not be empty.')
      ->willReturn($builder);
    $builder->expects($this->once())->method('addViolation');

    $validator->validate($field, new ServiceRequestRequiredEmailConstraint());
  }

  /**
   * A historical empty email does not block an unrelated update.
   */
  public function testUnchangedHistoricalEmptyEmailIsAccepted(): void {
    [$validator, $context, $field] = $this->validator(FALSE, TRUE);
    $context->expects($this->never())->method('buildViolation');

    $validator->validate($field, new ServiceRequestRequiredEmailConstraint());
  }

  /**
   * Clearing a previously populated required email remains invalid.
   */
  public function testClearingExistingEmailIsRejected(): void {
    [$validator, $context, $field] = $this->validator(FALSE, FALSE);
    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $context->expects($this->once())->method('buildViolation')->willReturn($builder);
    $builder->expects($this->once())->method('addViolation');

    $validator->validate($field, new ServiceRequestRequiredEmailConstraint());
  }

  /**
   * Optional configuration and historical empty values remain editable.
   */
  public function testNodeFormRequiredStateFollowsConfigurationAndLifecycle(): void {
    $this->assertFalse(_service_request_email_form_required($this->formNode(TRUE, TRUE), FALSE));
    $this->assertTrue(_service_request_email_form_required($this->formNode(TRUE, TRUE), TRUE));
    $this->assertFalse(_service_request_email_form_required($this->formNode(FALSE, TRUE), TRUE));
    $this->assertTrue(_service_request_email_form_required($this->formNode(FALSE, FALSE), TRUE));
  }

  /**
   * Builds a node fixture for the Drupal form requirement helper.
   */
  private function formNode(bool $is_new, bool $email_empty): NodeInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($email_empty);
    $node = $this->createMock(NodeInterface::class);
    $node->method('isNew')->willReturn($is_new);
    $node->method('get')->with('field_e_mail')->willReturn($field);

    return $node;
  }

  /**
   * Builds the validator and field fixture.
   *
   * @return array{0: \Drupal\service_request\Plugin\Validation\Constraint\ServiceRequestRequiredEmailConstraintValidator, 1: \Symfony\Component\Validator\Context\ExecutionContextInterface, 2: \Drupal\Core\Field\FieldItemListInterface}
   *   Validator, context, and field.
   */
  private function validator(bool $is_new, ?bool $original_empty): array {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('isNew')->willReturn($is_new);
    $node->method('id')->willReturn(123);

    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    $field->method('getEntity')->willReturn($node);

    $storage = $this->createMock(EntityStorageInterface::class);
    if (!$is_new) {
      $original = $this->createMock(NodeInterface::class);
      $original->method('hasField')->with('field_e_mail')->willReturn(TRUE);
      $original_field = $this->createMock(FieldItemListInterface::class);
      $original_field->method('isEmpty')->willReturn($original_empty);
      $original->method('get')->with('field_e_mail')->willReturn($original_field);
      $storage->expects($this->once())->method('loadUnchanged')->with(123)->willReturn($original);
    }

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    if (!$is_new) {
      $entity_type_manager->expects($this->once())->method('getStorage')->with('node')->willReturn($storage);
    }

    $context = $this->createMock(ExecutionContextInterface::class);
    $validator = new ServiceRequestRequiredEmailConstraintValidator($entity_type_manager);
    $validator->initialize($context);

    return [$validator, $context, $field];
  }

}
