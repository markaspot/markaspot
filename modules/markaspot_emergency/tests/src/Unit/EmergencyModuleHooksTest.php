<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_emergency\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Attribute\LegacyHook;
use Drupal\markaspot_emergency\EventSubscriber\EmergencySubmissionGuardSubscriber;
use Drupal\markaspot_emergency\Exception\EmergencySubmissionReplayException;
use Drupal\markaspot_emergency\Hook\EmergencyEntityHooks;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Drupal\markaspot_emergency\Service\EmergencySubmissionIdempotencyLedgerInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests emergency module hooks that do not require a kernel.
 *
 * @group markaspot_emergency
 */
class EmergencyModuleHooksTest extends UnitTestCase {

  private const NODE_UUID = '33333333-3333-4333-8333-333333333333';

  /**
   * Loads the procedural module hooks.
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    require_once dirname(__DIR__, 3) . '/markaspot_emergency.module';
  }

  /**
   * Tests the authoritative mode widget replaces the legacy boolean widget.
   */
  public function testServiceCategoryFormDisplayUsesModeWidget(): void {
    $display = $this->createMock(EntityFormDisplayInterface::class);
    $display->method('getTargetEntityTypeId')->willReturn('taxonomy_term');
    $display->method('getTargetBundle')->willReturn('service_category');
    $display->expects($this->once())
      ->method('setComponent')
      ->with('field_emergency_modes', [
        'type' => 'options_buttons',
        'weight' => 15,
        'region' => 'content',
        'settings' => [],
        'third_party_settings' => [],
      ])
      ->willReturnSelf();
    $display->expects($this->once())
      ->method('removeComponent')
      ->with('field_emergency_category')
      ->willReturnSelf();

    \markaspot_emergency_entity_form_display_alter($display, []);
  }

  /**
   * Tests unrelated form displays remain untouched.
   */
  public function testUnrelatedFormDisplayIsNotAltered(): void {
    $display = $this->createMock(EntityFormDisplayInterface::class);
    $display->method('getTargetEntityTypeId')->willReturn('taxonomy_term');
    $display->method('getTargetBundle')->willReturn('tags');
    $display->expects($this->never())->method('setComponent');
    $display->expects($this->never())->method('removeComponent');

    \markaspot_emergency_entity_form_display_alter($display, []);
  }

  /**
   * Drupal 11 uses the native hook while Drupal 10 keeps the wrapper.
   */
  public function testEntityPresaveKeepsTheDrupal10AndDrupal11Bridge(): void {
    $method = new \ReflectionMethod(EmergencyEntityHooks::class, 'entityPresave');
    $this->assertSame([Hook::class], array_map(
      static fn(\ReflectionAttribute $attribute): string => $attribute->getName(),
      $method->getAttributes(),
    ));

    $function = new \ReflectionFunction('markaspot_emergency_entity_presave');
    $this->assertSame([LegacyHook::class], array_map(
      static fn(\ReflectionAttribute $attribute): string => $attribute->getName(),
      $function->getAttributes(),
    ));
  }

  /**
   * The final entity values arm the transaction-scoped persistence guard once.
   */
  public function testServiceRequestPresaveConsumesPreparedGuard(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->once())
      ->method('acquireSubmissionGuard')
      ->with(7, 12, 40, [7], 9, 'active', TRUE, TRUE, TRUE);
    $ledger = $this->createMock(EmergencySubmissionIdempotencyLedgerInterface::class);
    $ledger->expects($this->never())->method('reserve');
    [$request, $requestStack] = $this->installGuardContainer($service, $ledger);
    $request->attributes->set(
      EmergencySubmissionGuardSubscriber::CONTEXT_ATTRIBUTE,
      $this->guardContext(),
    );

    \markaspot_emergency_entity_presave($this->serviceRequestEntity(40, 9));

    $this->assertFalse($request->attributes->has(
      EmergencySubmissionGuardSubscriber::CONTEXT_ATTRIBUTE,
    ));
    $requestStack->pop();
  }

  /**
   * The ledger reservation is part of the active entity-save transaction.
   */
  public function testIdempotentPresaveReservesThePreparedKey(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->once())
      ->method('acquireSubmissionGuard')
      ->with(7, 12, 40, [7], 9, 'active', TRUE, TRUE, TRUE);
    $ledger = $this->createMock(EmergencySubmissionIdempotencyLedgerInterface::class);
    $ledger->expects($this->once())
      ->method('reserve')
      ->with(
        7,
        12,
        '44444444-4444-4444-8444-444444444444',
        str_repeat('a', 64),
        self::NODE_UUID,
      )
      ->willReturn(NULL);
    [$request, $requestStack] = $this->installGuardContainer($service, $ledger);
    $context = $this->guardContext();
    $context['idempotency_key'] = '44444444-4444-4444-8444-444444444444';
    $context['idempotency_request_hash'] = str_repeat('a', 64);
    $request->attributes->set(
      EmergencySubmissionGuardSubscriber::CONTEXT_ATTRIBUTE,
      $context,
    );

    \markaspot_emergency_entity_presave($this->serviceRequestEntity(40, 9));
    $requestStack->pop();
  }

  /**
   * A concurrent matching reservation aborts the second node save for replay.
   */
  public function testIdempotentPresaveSignalsResolvedDuplicate(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->once())
      ->method('acquireSubmissionGuard')
      ->with(7, 12, 40, [7], 9, 'active', TRUE, TRUE, TRUE);
    $ledger = $this->createMock(EmergencySubmissionIdempotencyLedgerInterface::class);
    $ledger->expects($this->once())
      ->method('reserve')
      ->willReturn('55555555-5555-4555-8555-555555555555');
    [$request, $requestStack] = $this->installGuardContainer($service, $ledger);
    $context = $this->guardContext();
    $context['idempotency_key'] = '44444444-4444-4444-8444-444444444444';
    $context['idempotency_request_hash'] = str_repeat('a', 64);
    $request->attributes->set(
      EmergencySubmissionGuardSubscriber::CONTEXT_ATTRIBUTE,
      $context,
    );

    try {
      \markaspot_emergency_entity_presave($this->serviceRequestEntity(40, 9));
      $this->fail('A matching duplicate must stop the second node save.');
    }
    catch (EmergencySubmissionReplayException $exception) {
      $this->assertSame(
        '55555555-5555-4555-8555-555555555555',
        $exception->getNodeUuid(),
      );
      $this->assertSame(
        '55555555-5555-4555-8555-555555555555',
        $request->attributes->get(EmergencySubmissionGuardSubscriber::REPLAY_ATTRIBUTE),
      );
    }
    finally {
      $requestStack->pop();
    }
  }

  /**
   * A presave mutation cannot swap the category prepared from JSON:API input.
   */
  public function testServiceRequestPresaveRejectsChangedCategory(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->never())->method('acquireSubmissionGuard');
    [$request, $requestStack] = $this->installGuardContainer($service);
    $request->attributes->set(
      EmergencySubmissionGuardSubscriber::CONTEXT_ATTRIBUTE,
      $this->guardContext(),
    );

    try {
      \markaspot_emergency_entity_presave($this->serviceRequestEntity(41, 9));
      $this->fail('A category mutation must fail before persistence.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('category changed', $exception->getMessage());
      $this->assertSame(
        $exception->getMessage(),
        $request->attributes->get(EmergencySubmissionGuardSubscriber::FAILURE_ATTRIBUTE),
      );
    }
    finally {
      $requestStack->pop();
    }
  }

  /**
   * Installs request and service doubles in Drupal's static container.
   *
   * @return array{0: \Symfony\Component\HttpFoundation\Request, 1: \Symfony\Component\HttpFoundation\RequestStack}
   *   Main request and its stack.
   */
  private function installGuardContainer(
    EmergencyModeService $service,
    ?EmergencySubmissionIdempotencyLedgerInterface $ledger = NULL,
  ): array {
    $request = Request::create('/jsonapi/node/service_request', 'POST');
    $requestStack = new RequestStack();
    $requestStack->push($request);
    $container = new ContainerBuilder();
    $container->set('request_stack', $requestStack);
    $container->set('markaspot_emergency.service', $service);
    $ledger ??= $this->createMock(EmergencySubmissionIdempotencyLedgerInterface::class);
    $container->set(
      EmergencyEntityHooks::class,
      new EmergencyEntityHooks($requestStack, $service, $ledger),
    );
    \Drupal::setContainer($container);
    return [$request, $requestStack];
  }

  /**
   * Builds a new service request with final entity reference values.
   */
  private function serviceRequestEntity(int $categoryId, ?int $jurisdictionId): ContentEntityInterface {
    $category = $this->createMock(FieldItemListInterface::class);
    $category->method('getValue')->willReturn([['target_id' => $categoryId]]);
    $jurisdiction = $this->createMock(FieldItemListInterface::class);
    $jurisdiction->method('getValue')->willReturn(
      $jurisdictionId === NULL ? [] : [['target_id' => $jurisdictionId]],
    );

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('service_request');
    $entity->method('isNew')->willReturn(TRUE);
    $entity->method('uuid')->willReturn(self::NODE_UUID);
    $entity->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array(
        $field,
        ['field_category', 'field_jurisdiction'],
        TRUE,
      ),
    );
    $entity->method('get')->willReturnCallback(
      static fn(string $field): FieldItemListInterface => $field === 'field_category'
        ? $category
        : $jurisdiction,
    );
    return $entity;
  }

  /**
   * Returns normalized request preparation output.
   */
  private function guardContext(): array {
    return [
      'root_id' => 7,
      'expected_revision' => 12,
      'expected_status' => 'active',
      'require_lite_ui' => TRUE,
      'require_published_category' => TRUE,
      'require_lite_compatible_category' => TRUE,
      'category_id' => 40,
      'category_jurisdiction_ids' => [7],
      'jurisdiction_id' => 9,
    ];
  }

}
