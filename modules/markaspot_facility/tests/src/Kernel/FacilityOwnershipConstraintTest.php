<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_facility\Kernel;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_facility\Plugin\Validation\Constraint\FacilityOwnershipConstraint;
use Drupal\markaspot_facility\Plugin\Validation\Constraint\FacilityOwnershipConstraintValidator;
use Drupal\markaspot_facility\Service\FacilityManager;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Tests the FacilityOwnership cross-tenant injection guard (issue #367).
 *
 * Boots a minimal kernel, builds the validator through its container factory
 * (FacilityOwnershipConstraintValidator::create) so the dependency injection
 * wiring is exercised, then drives validate() against mocked service_request
 * nodes. The FacilityManager is replaced with a lightweight subclass returning
 * a per-jurisdiction facility catalogue, so the ownership comparison is tested
 * without installing the full group/node entity stack.
 *
 * The constraint validators in this profile (ServiceProviderOrganisationScope,
 * TierLimit) are conventionally verified by mocking ExecutionContextInterface
 * and ConstraintViolationBuilderInterface; this test follows that pattern.
 *
 * @group markaspot_facility
 * @coversDefaultClass \Drupal\markaspot_facility\Plugin\Validation\Constraint\FacilityOwnershipConstraintValidator
 */
class FacilityOwnershipConstraintTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Tenant A (gid 100) owns facility_a; tenant B (gid 200) owns facility_b.
   *
   * @var array<int, string[]>
   */
  private array $catalogue = [
    100 => ['facility_a'],
    200 => ['facility_b'],
  ];

  /**
   * The validator under test, built via its container factory.
   *
   * @var \Drupal\markaspot_facility\Plugin\Validation\Constraint\FacilityOwnershipConstraintValidator
   */
  private FacilityOwnershipConstraintValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Group storage returns a mocked group for the two known jurisdiction ids.
    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('load')->willReturnCallback(function (mixed $id): ?GroupInterface {
      $gid = (int) $id;
      if (!isset($this->catalogue[$gid])) {
        return NULL;
      }
      $group = $this->createMock(GroupInterface::class);
      $group->method('id')->willReturn((string) $gid);
      return $group;
    });

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturnCallback(
      fn(string $type) => $type === 'group'
        ? $group_storage
        : $this->createMock(EntityStorageInterface::class)
    );
    $this->container->set('entity_type.manager', $etm);

    // Replace the facility manager with a stub catalogue keyed by group id.
    $this->container->set(
      'markaspot_facility.manager',
      $this->stubFacilityManager()
    );

    // Build the validator exactly as Drupal would, through its factory.
    $this->validator = FacilityOwnershipConstraintValidator::create($this->container);
  }

  /**
   * Tenant A's facility id on a tenant B request must be rejected.
   *
   * @covers ::validate
   */
  public function testCrossTenantFacilityProducesViolation(): void {
    $constraint = new FacilityOwnershipConstraint();
    [$context, $paths] = $this->contextExpectingViolation($constraint);
    $this->validator->initialize($context);

    $this->validator->validate($this->serviceRequest('facility_a', 200), $constraint);

    $this->assertSame(['field_facility'], $paths->getArrayCopy());
  }

  /**
   * A same-tenant facility id passes without violations.
   *
   * @covers ::validate
   */
  public function testSameTenantFacilityPasses(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');
    $this->validator->initialize($context);

    $this->validator->validate(
      $this->serviceRequest('facility_b', 200),
      new FacilityOwnershipConstraint()
    );
  }

  /**
   * An empty facility value passes (the field is optional).
   *
   * @covers ::validate
   */
  public function testEmptyFacilityPasses(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');
    $this->validator->initialize($context);

    $this->validator->validate(
      $this->serviceRequest('', 200),
      new FacilityOwnershipConstraint()
    );
  }

  /**
   * A facility tag on a node without a resolvable jurisdiction is rejected.
   *
   * @covers ::validate
   */
  public function testFacilityWithoutJurisdictionIsRejected(): void {
    $constraint = new FacilityOwnershipConstraint();
    [$context, $paths] = $this->contextExpectingViolation($constraint);
    $this->validator->initialize($context);

    $this->validator->validate($this->serviceRequest('facility_a', 0), $constraint);

    $this->assertSame(['field_facility'], $paths->getArrayCopy());
  }

  /**
   * Builds a FacilityManager double returning the per-jurisdiction catalogue.
   *
   * The subclass overrides the constructor (no parent call) and
   * getDashboardSettings() so no real services are required.
   */
  private function stubFacilityManager(): FacilityManager {
    $catalogue = $this->catalogue;

    // phpcs:disable
    return new class($catalogue) extends FacilityManager {
      /**
       * @param array<int, string[]> $catalogue
       *   Facility ids keyed by jurisdiction group id.
       */
      public function __construct(private array $catalogue) {}

      public function getDashboardSettings(?GroupInterface $group): array {
        $gid = $group ? (int) $group->id() : 0;
        $ids = $this->catalogue[$gid] ?? [];
        return [
          'enabled' => TRUE,
          'mode' => 'exclusive',
          'items' => array_map(static fn(string $id): array => ['id' => $id], $ids),
        ];
      }
    };
    // phpcs:enable
  }

  /**
   * Builds a service_request node test double.
   *
   * @param string $facility_id
   *   The field_facility value. An empty string yields an empty field.
   * @param int $jurisdiction_id
   *   The field_jurisdiction target id. 0 yields an empty jurisdiction field.
   */
  private function serviceRequest(string $facility_id, int $jurisdiction_id): NodeInterface {
    // phpcs:disable
    $facility_field = new class($facility_id) {
      public function __construct(public string $value) {}
      public function isEmpty(): bool { return $this->value === ''; }
    };
    $jurisdiction_field = new class($jurisdiction_id) {
      public function __construct(private int $jurisdiction_id) {}
      public function isEmpty(): bool { return $this->jurisdiction_id <= 0; }
      public function first(): object { return (object) ['target_id' => $this->jurisdiction_id]; }
    };
    // phpcs:enable

    $fields = [
      'field_facility' => $facility_field,
      'field_jurisdiction' => $jurisdiction_field,
    ];

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $node->method('get')
      ->willReturnCallback(static fn(string $name): mixed => $fields[$name]
        ?? throw new \LogicException("Unexpected field access: $name"));

    return $node;
  }

  /**
   * Creates a context expecting exactly one violation on field_facility.
   *
   * @return array{\Symfony\Component\Validator\Context\ExecutionContextInterface, \ArrayObject<int, string>}
   *   The mocked context and the captured violation paths.
   */
  private function contextExpectingViolation(FacilityOwnershipConstraint $constraint): array {
    $paths = new \ArrayObject();
    $expected_message = $constraint->message instanceof \Stringable
      && method_exists($constraint->message, 'getUntranslatedString')
      ? $constraint->message->getUntranslatedString()
      : (string) $constraint->message;

    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())
      ->method('atPath')
      ->willReturnCallback(function (string $path) use ($paths, $builder): ConstraintViolationBuilderInterface {
        $paths->append($path);
        return $builder;
      });
    $builder->expects($this->once())->method('addViolation');

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with($expected_message)
      ->willReturn($builder);

    return [$context, $paths];
  }

}
