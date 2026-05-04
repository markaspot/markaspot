<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_service_provider\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\markaspot_service_provider\Plugin\Validation\Constraint\ServiceProviderOrganisationScopeConstraint;
use Drupal\markaspot_service_provider\Plugin\Validation\Constraint\ServiceProviderOrganisationScopeConstraintValidator;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Tests service provider organisation scope validation.
 *
 * @group markaspot_service_provider
 * @coversDefaultClass \Drupal\markaspot_service_provider\Plugin\Validation\Constraint\ServiceProviderOrganisationScopeConstraintValidator
 */
class ServiceProviderOrganisationScopeConstraintValidatorTest extends UnitTestCase {

  /**
   * Tests the default violation message is exposed to locale extraction.
   *
   * @covers \Drupal\markaspot_service_provider\Plugin\Validation\Constraint\ServiceProviderOrganisationScopeConstraint::__construct
   */
  public function testDefaultConstraintMessageIsTranslatable(): void {
    $constraint = new ServiceProviderOrganisationScopeConstraint();

    $this->assertInstanceOf(TranslatableMarkup::class, $constraint->message);
    $this->assertSame(
      'The selected service provider is not available for the selected organisation.',
      $constraint->message->getUntranslatedString(),
    );
    $this->assertInstanceOf(TranslatableMarkup::class, $constraint->boilerplateMessage);
    $this->assertSame(
      'The selected service provider boilerplate is not available for the selected organisation.',
      $constraint->boilerplateMessage->getUntranslatedString(),
    );
  }

  /**
   * Tests unscoped providers remain jurisdiction-wide.
   *
   * @covers ::validate
   */
  public function testUnscopedProviderIsAccepted(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $validator = new ServiceProviderOrganisationScopeConstraintValidator();
    $validator->initialize($context);
    $validator->validate(
      $this->serviceRequest($this->provider([]), [10]),
      new ServiceProviderOrganisationScopeConstraint(),
    );
  }

  /**
   * Tests provider scopes can match any selected request organisation.
   *
   * @covers ::validate
   */
  public function testMatchingProviderScopeIsAccepted(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $validator = new ServiceProviderOrganisationScopeConstraintValidator();
    $validator->initialize($context);
    $validator->validate(
      $this->serviceRequest($this->provider([20, 30]), [10, 30]),
      new ServiceProviderOrganisationScopeConstraint(),
    );
  }

  /**
   * Tests scoped providers are rejected for other organisations.
   *
   * @covers ::validate
   */
  public function testMismatchedProviderScopeIsRejected(): void {
    $constraint = new ServiceProviderOrganisationScopeConstraint();
    [$context, $paths] = $this->contextExpectingScopeViolations($constraint);

    $validator = new ServiceProviderOrganisationScopeConstraintValidator();
    $validator->initialize($context);
    $validator->validate($this->serviceRequest($this->provider([20]), [10]), $constraint);

    $this->assertSame(['field_service_provider', 'field_organisation'], $paths->getArrayCopy());
  }

  /**
   * Tests scoped providers require a request organisation.
   *
   * @covers ::validate
   */
  public function testScopedProviderWithoutRequestOrganisationIsRejected(): void {
    $constraint = new ServiceProviderOrganisationScopeConstraint();
    [$context, $paths] = $this->contextExpectingScopeViolations($constraint);

    $validator = new ServiceProviderOrganisationScopeConstraintValidator();
    $validator->initialize($context);
    $validator->validate($this->serviceRequest($this->provider([20]), []), $constraint);

    $this->assertSame(['field_service_provider', 'field_organisation'], $paths->getArrayCopy());
  }

  /**
   * Tests matching service-provider boilerplates are accepted.
   *
   * @covers ::validate
   */
  public function testMatchingBoilerplateScopeIsAccepted(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $validator = new ServiceProviderOrganisationScopeConstraintValidator();
    $validator->initialize($context);
    $validator->validate(
      $this->serviceRequest($this->provider([]), [20], $this->boilerplate('service_provider', [20], [5]), [5]),
      new ServiceProviderOrganisationScopeConstraint(),
    );
  }

  /**
   * Tests mismatched service-provider boilerplates are rejected.
   *
   * @covers ::validate
   */
  public function testMismatchedBoilerplateScopeIsRejected(): void {
    $constraint = new ServiceProviderOrganisationScopeConstraint();
    [$context, $paths] = $this->contextExpectingScopeViolations($constraint, $constraint->boilerplateMessage);

    $validator = new ServiceProviderOrganisationScopeConstraintValidator();
    $validator->initialize($context);
    $validator->validate(
      $this->serviceRequest($this->provider([]), [20], $this->boilerplate('service_provider', [30], [5]), [5]),
      $constraint,
    );

    $this->assertSame(['field_boilerplates_sp', 'field_organisation'], $paths->getArrayCopy());
  }

  /**
   * Tests non-service-provider boilerplates are rejected.
   *
   * @covers ::validate
   */
  public function testWrongBoilerplateTypeIsRejected(): void {
    $constraint = new ServiceProviderOrganisationScopeConstraint();
    [$context, $paths] = $this->contextExpectingScopeViolations($constraint, $constraint->boilerplateMessage);

    $validator = new ServiceProviderOrganisationScopeConstraintValidator();
    $validator->initialize($context);
    $validator->validate(
      $this->serviceRequest($this->provider([]), [20], $this->boilerplate('status_notes', [], [5]), [5]),
      $constraint,
    );

    $this->assertSame(['field_boilerplates_sp', 'field_organisation'], $paths->getArrayCopy());
  }

  /**
   * Creates a service request node stub.
   */
  protected function serviceRequest(TermInterface $provider, array $organisation_ids, ?NodeInterface $boilerplate = NULL, array $jurisdiction_ids = []): NodeInterface {
    $provider_field = new class($provider) {

      /**
       * Constructs a provider reference field stub.
       */
      public function __construct(public TermInterface $entity) {}

      /**
       * Checks whether the field item is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $fields = [
      'field_service_provider' => $provider_field,
      'field_organisation' => $this->fieldWithTargetIds($organisation_ids),
      'field_jurisdiction' => $this->fieldWithTargetIds($jurisdiction_ids),
    ];

    if ($boilerplate) {
      $fields['field_boilerplates_sp'] = new class($boilerplate) {

        /**
         * Constructs a boilerplate reference field stub.
         */
        public function __construct(public NodeInterface $entity) {}

        /**
         * Checks whether the field item is empty.
         */
        public function isEmpty(): bool {
          return FALSE;
        }

      };
    }

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $node->method('get')
      ->willReturnCallback(static fn(string $name): mixed => $fields[$name]);

    return $node;
  }

  /**
   * Creates a service provider taxonomy term stub.
   */
  protected function provider(array $organisation_ids): TermInterface {
    $field = $this->fieldWithTargetIds($organisation_ids);

    $term = $this->createMock(TermInterface::class);
    $term->method('hasField')
      ->with('field_organisation')
      ->willReturn(TRUE);
    $term->method('get')
      ->with('field_organisation')
      ->willReturn($field);

    return $term;
  }

  /**
   * Creates a boilerplate node stub.
   */
  protected function boilerplate(string $type, array $organisation_ids, array $jurisdiction_ids): NodeInterface {
    $fields = [
      'field_boilerplate_type' => $this->fieldWithValues([['value' => $type]]),
      'field_organisation' => $this->fieldWithTargetIds($organisation_ids),
      'field_jurisdiction' => $this->fieldWithTargetIds($jurisdiction_ids),
    ];

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('boilerplate');
    $node->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $node->method('get')
      ->willReturnCallback(static fn(string $name): mixed => $fields[$name]);

    return $node;
  }

  /**
   * Creates an entity reference field item list stub.
   */
  protected function fieldWithTargetIds(array $ids): FieldItemListInterface {
    return $this->fieldWithValues(array_map(
      static fn(int $id): array => ['target_id' => $id],
      $ids,
    ));
  }

  /**
   * Creates a field item list stub.
   */
  protected function fieldWithValues(array $values): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($values === []);
    $field->method('getValue')->willReturn($values);

    return $field;
  }

  /**
   * Creates a validation context expecting provider scope violations.
   *
   * @return array{\Symfony\Component\Validator\Context\ExecutionContextInterface, \ArrayObject<int, string>}
   *   The context and captured violation paths.
   */
  protected function contextExpectingScopeViolations(ServiceProviderOrganisationScopeConstraint $constraint, string|TranslatableMarkup|NULL $message = NULL): array {
    $paths = new \ArrayObject();
    $message ??= $constraint->message;
    $expected_message = $message instanceof TranslatableMarkup
      ? $message->getUntranslatedString()
      : $message;
    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->exactly(2))
      ->method('atPath')
      ->willReturnCallback(function (string $path) use ($paths, $builder): ConstraintViolationBuilderInterface {
        $paths->append($path);
        return $builder;
      });
    $builder->expects($this->exactly(2))->method('addViolation');

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->exactly(2))
      ->method('buildViolation')
      ->with($expected_message)
      ->willReturn($builder);

    return [$context, $paths];
  }

}
