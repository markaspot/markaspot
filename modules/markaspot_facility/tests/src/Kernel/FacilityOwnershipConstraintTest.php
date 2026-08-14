<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_facility\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_facility\Plugin\Validation\Constraint\FacilityOwnershipConstraint;
use Drupal\markaspot_facility\Plugin\Validation\Constraint\FacilityOwnershipConstraintValidator;
use Drupal\markaspot_facility\Service\FacilityManager;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
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
#[RunTestsInSeparateProcesses]
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
    300 => [],
    400 => ['facility_child'],
  ];

  /**
   * Child-to-root jurisdiction map used by the hierarchy resolver double.
   *
   * @var array<int, int>
   */
  private array $rootMap = [
    100 => 100,
    200 => 200,
    300 => 100,
    400 => 100,
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

    $hierarchy_resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy_resolver->method('getRootJurisdictionId')
      ->willReturnCallback(fn(int $gid): ?int => $this->rootMap[$gid] ?? NULL);
    $hierarchy_resolver->method('getDescendantIds')
      ->willReturnCallback(fn(int $gid): array => match ($gid) {
        100 => [100, 300, 400],
        200 => [200],
        default => [],
      });
    $this->container->set('markaspot_group.hierarchy_resolver', $hierarchy_resolver);

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
   * An anonymous create resolves its jurisdiction from the category term.
   *
   * @covers ::validate
   */
  public function testCategoryJurisdictionAllowsAnonymousCreate(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');
    $this->validator->initialize($context);

    $this->validator->validate(
      $this->serviceRequest('facility_a', 0, 100),
      new FacilityOwnershipConstraint()
    );
  }

  /**
   * A root-owned category can resolve a facility from a child catalogue.
   *
   * Service category jurisdictions are normalized to their canonical root,
   * while facility catalogues may be managed by a child jurisdiction.
   *
   * @covers ::validate
   */
  public function testCategoryRootAllowsChildFacility(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');
    $this->validator->initialize($context);

    $this->validator->validate(
      $this->serviceRequest('facility_child', 0, 100),
      new FacilityOwnershipConstraint()
    );
  }

  /**
   * Category fallback still rejects a facility owned by another tenant.
   *
   * @covers ::validate
   */
  public function testCategoryJurisdictionRejectsForeignFacility(): void {
    $constraint = new FacilityOwnershipConstraint();
    [$context, $paths] = $this->contextExpectingViolation($constraint);
    $this->validator->initialize($context);

    $this->validator->validate(
      $this->serviceRequest('facility_b', 0, 100),
      $constraint
    );

    $this->assertSame(['field_facility'], $paths->getArrayCopy());
  }

  /**
   * Direct and category jurisdictions must resolve to the same tenant root.
   *
   * @covers ::validate
   */
  public function testDirectJurisdictionCannotOverrideCategoryTenant(): void {
    $constraint = new FacilityOwnershipConstraint();
    [$context, $paths] = $this->contextExpectingViolation($constraint);
    $this->validator->initialize($context);

    $this->validator->validate(
      $this->serviceRequest('facility_b', 200, 100),
      $constraint
    );

    $this->assertSame(['field_facility'], $paths->getArrayCopy());
  }

  /**
   * A second jurisdiction from another tenant cannot hide behind the first.
   *
   * @covers ::validate
   */
  public function testMultipleTenantRootsAreRejected(): void {
    $constraint = new FacilityOwnershipConstraint();
    [$context, $paths] = $this->contextExpectingViolation($constraint);
    $this->validator->initialize($context);

    $this->validator->validate(
      $this->serviceRequest('facility_a', [100, 200], 100),
      $constraint
    );

    $this->assertSame(['field_facility'], $paths->getArrayCopy());
  }

  /**
   * Multiple jurisdictions in one canonical tenant tree are still rejected.
   *
   * @covers ::validate
   */
  public function testMultipleJurisdictionsInSameRootAreRejected(): void {
    $constraint = new FacilityOwnershipConstraint();
    [$context, $paths] = $this->contextExpectingViolation($constraint);
    $this->validator->initialize($context);

    $this->validator->validate(
      $this->serviceRequest('facility_a', [300, 100], 100),
      $constraint
    );

    $this->assertSame(['field_facility'], $paths->getArrayCopy());
  }

  /**
   * A child jurisdiction may retain a facility owned by its root jurisdiction.
   *
   * Boundary routing can set field_jurisdiction to the child after create
   * validation has resolved the facility against the root. The ownership guard
   * must keep that request editable without allowing cross-tenant ids.
   *
   * @covers ::validate
   */
  public function testChildJurisdictionAcceptsRootFacility(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');
    $this->validator->initialize($context);

    $this->validator->validate(
      $this->serviceRequest('facility_a', 300),
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
    $root_map = $this->rootMap;

    // phpcs:disable
    return new class($catalogue, $root_map) extends FacilityManager {
      /**
       * @param array<int, string[]> $catalogue
       *   Facility ids keyed by jurisdiction group id.
       * @param array<int, int> $rootMap
       *   Canonical root ids keyed by jurisdiction group id.
       */
      public function __construct(
        private array $catalogue,
        private array $rootMap,
      ) {}

      public function getDashboardSettings(?GroupInterface $group): array {
        $gid = $group ? (int) $group->id() : 0;
        $ids = $this->catalogue[$gid] ?? [];
        return [
          'enabled' => TRUE,
          'mode' => 'exclusive',
          'items' => array_map(static fn(string $id): array => ['id' => $id], $ids),
        ];
      }

      public function getPublicSettings(?GroupInterface $group): array {
        return $this->getDashboardSettings($group);
      }

      public function resolveFacilityOwnerFromCategory(
        NodeInterface $node,
        string $facility_id,
        bool $public,
      ): ?int {
        $category = $node->get('field_category')->entity;
        $category_id = (int) ($category?->get('field_jurisdiction')->target_id ?? 0);
        $root_id = $this->rootMap[$category_id] ?? NULL;
        $owners = [];
        foreach ($this->catalogue as $jurisdiction_id => $ids) {
          if (($this->rootMap[$jurisdiction_id] ?? NULL) === $root_id
            && in_array($facility_id, $ids, TRUE)) {
            $owners[] = $jurisdiction_id;
          }
        }
        return count($owners) === 1 ? $owners[0] : NULL;
      }
    };
    // phpcs:enable
  }

  /**
   * Builds a service_request node test double.
   *
   * @param string $facility_id
   *   The field_facility value. An empty string yields an empty field.
   * @param int|int[] $jurisdiction_id
   *   The field_jurisdiction target ids. 0 yields an empty jurisdiction field.
   * @param int $category_jurisdiction_id
   *   The category term's jurisdiction. 0 yields an empty category field.
   */
  private function serviceRequest(
    string $facility_id,
    int|array $jurisdiction_id,
    int $category_jurisdiction_id = 0,
  ): NodeInterface {
    // phpcs:disable
    $facility_field = new class($facility_id) {
      public function __construct(public string $value) {}
      public function isEmpty(): bool { return $this->value === ''; }
    };
    $jurisdiction_ids = is_array($jurisdiction_id)
      ? array_values($jurisdiction_id)
      : ($jurisdiction_id > 0 ? [$jurisdiction_id] : []);
    $jurisdiction_field = new class($jurisdiction_ids) {
      /** @param int[] $jurisdiction_ids */
      public function __construct(private array $jurisdiction_ids) {}
      public function isEmpty(): bool { return $this->jurisdiction_ids === []; }
      public function first(): ?object {
        return $this->jurisdiction_ids === []
          ? NULL
          : (object) ['target_id' => $this->jurisdiction_ids[0]];
      }
      public function getValue(): array {
        return array_map(
          static fn(int $id): array => ['target_id' => $id],
          $this->jurisdiction_ids,
        );
      }
    };

    $category_term = NULL;
    if ($category_jurisdiction_id > 0) {
      $term_jurisdiction_field = new class($category_jurisdiction_id) {
        public function __construct(public int $target_id) {}
        public function isEmpty(): bool { return FALSE; }
      };
      $category_term = $this->createMock(ContentEntityInterface::class);
      $category_term->method('hasField')
        ->with('field_jurisdiction')
        ->willReturn(TRUE);
      $category_term->method('get')
        ->with('field_jurisdiction')
        ->willReturn($term_jurisdiction_field);
    }
    $category_field = new class($category_term) {
      public function __construct(public ?ContentEntityInterface $entity) {}
      public function isEmpty(): bool { return $this->entity === NULL; }
    };
    // phpcs:enable

    $fields = [
      'field_facility' => $facility_field,
      'field_jurisdiction' => $jurisdiction_field,
      'field_category' => $category_field,
    ];

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('isNew')->willReturn(TRUE);
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
