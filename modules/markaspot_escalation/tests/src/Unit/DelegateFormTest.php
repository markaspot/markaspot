<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_escalation\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_escalation\Form\DelegateForm;
use Drupal\markaspot_escalation\Service\EscalationServiceInterface;
use Drupal\markaspot_group\Service\OrgHierarchyResolverInterface;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the DelegateForm organisation option tree.
 *
 * @group markaspot_escalation
 * @coversDefaultClass \Drupal\markaspot_escalation\Form\DelegateForm
 */
class DelegateFormTest extends UnitTestCase {

  /**
   * Tests tree-aware organisation options.
   *
   * @covers ::buildOrganisationOptions
   */
  public function testBuildOrganisationOptionsGroupsAndMarksParentOrg(): void {
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    $rootOrg = $this->createMockOrgGroup(10, 'Root Org', 1);
    $parentOrg = $this->createMockOrgGroup(20, 'Parent Team', 1);
    $leafOrg = $this->createMockOrgGroup(30, 'Leaf Team', 1);
    $standaloneOrg = $this->createMockOrgGroup(40, 'Root Org', 1);

    $groupStorage->method('loadByProperties')
      ->willReturnCallback(static function (array $properties) use (
        $rootOrg,
        $parentOrg,
        $leafOrg,
        $standaloneOrg,
      ): array {
        if ($properties === ['type' => 'org', 'status' => 1]) {
          return [$rootOrg, $parentOrg, $leafOrg, $standaloneOrg];
        }
        if (($properties['type'] ?? NULL) === 'jur') {
          return [];
        }
        return [];
      });

    $orgHierarchyResolver = $this->createMock(OrgHierarchyResolverInterface::class);
    $orgHierarchyResolver->method('getRootOrgId')
      ->willReturnCallback(static fn(int $orgId): int => match ($orgId) {
        10, 20, 30 => 10,
        40 => 40,
      });
    $orgHierarchyResolver->method('getDescendantIds')
      ->willReturnCallback(static fn(int $orgId): array => match ($orgId) {
        10 => [10, 20, 30],
        40 => [40],
        default => [],
      });
    $orgHierarchyResolver->method('getAncestorIds')
      ->willReturnCallback(static fn(int $orgId): array => match ($orgId) {
        20 => [10],
        30 => [20, 10],
        default => [],
      });

    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn('jur');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($open311Config);

    $form = new DelegateForm(
      $this->createMock(EscalationServiceInterface::class),
      $entityTypeManager,
      $this->createMock(GeoreportProcessorServiceInterface::class),
      $configFactory,
      $orgHierarchyResolver,
    );
    $form->setStringTranslation($this->getStringTranslationStub());

    $options = $this->invokeMethod($form, 'buildOrganisationOptions', [1, 20, []]);

    $this->assertSame([
      'Root Org' => [
        10 => 'Root Org',
        20 => '  Root Org > Parent Team (übergeordnet)',
        30 => '    Root Org > Parent Team > Leaf Team',
      ],
      'Root Org (#40)' => [
        40 => 'Root Org',
      ],
    ], $options);

    $options = $this->invokeMethod($form, 'buildOrganisationOptions', [1, 20, [30]]);
    $this->assertArrayNotHasKey(30, $options['Root Org']);
  }

  /**
   * Tests an active child is re-rooted when its inactive parent is filtered.
   */
  public function testBuildOrganisationOptionsRerootsActiveChild(): void {
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    $activeChild = $this->createMockOrgGroup(20, 'Active Child', 1);
    $groupStorage->method('loadByProperties')
      ->willReturnCallback(
        static fn(array $properties): array => $properties === ['type' => 'org', 'status' => 1]
          ? [$activeChild]
          : [],
      );

    $orgHierarchyResolver = $this->createMock(OrgHierarchyResolverInterface::class);
    $orgHierarchyResolver->method('getAncestorIds')
      ->with(20)
      ->willReturn([10]);
    $orgHierarchyResolver->method('getDescendantIds')
      ->with(20)
      ->willReturn([20]);

    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn('jur');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($open311Config);

    $form = new DelegateForm(
      $this->createMock(EscalationServiceInterface::class),
      $entityTypeManager,
      $this->createMock(GeoreportProcessorServiceInterface::class),
      $configFactory,
      $orgHierarchyResolver,
    );
    $form->setStringTranslation($this->getStringTranslationStub());

    $this->assertSame(
      ['Active Child' => [20 => 'Active Child']],
      $this->invokeMethod($form, 'buildOrganisationOptions', [1, NULL, []]),
    );
  }

  /**
   * Creates an organisation group mock.
   *
   * @param int $orgId
   *   The organisation group ID.
   * @param string $label
   *   The group label.
   * @param int $jurisdictionId
   *   The jurisdiction group ID.
   *
   * @return \Drupal\group\Entity\GroupInterface
   *   The mocked organisation group.
   */
  protected function createMockOrgGroup(
    int $orgId,
    string $label,
    int $jurisdictionId,
  ): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($orgId);
    $group->method('bundle')->willReturn('org');
    $group->method('label')->willReturn($label);

    $field = new class($jurisdictionId) {

      /**
       * The referenced entity target ID.
       *
       * @var int
       */
      // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
      public int $target_id;

      /**
       * Constructs a field item stub.
       */
      public function __construct(int $targetId) {
        $this->target_id = $targetId;
      }

      /**
       * Checks whether the field item is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $group->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => $name === 'field_jurisdiction');
    $group->method('get')
      ->willReturnCallback(function (string $name) use ($field) {
        if ($name === 'field_jurisdiction') {
          return $field;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    return $group;
  }

  /**
   * Invokes a protected method via reflection.
   *
   * @param object $object
   *   The object instance.
   * @param string $methodName
   *   The method name.
   * @param array $args
   *   The method arguments.
   *
   * @return mixed
   *   The method result.
   */
  protected function invokeMethod(object $object, string $methodName, array $args = []): mixed {
    $reflection = new \ReflectionMethod($object, $methodName);
    $reflection->setAccessible(TRUE);
    return $reflection->invokeArgs($object, $args);
  }

}
