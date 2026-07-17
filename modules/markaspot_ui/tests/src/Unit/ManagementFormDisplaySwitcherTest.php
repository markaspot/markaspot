<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ui\Unit;

use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\markaspot_ui\Service\ManagementAccessGate;
use Drupal\markaspot_ui\Service\ManagementFormDisplaySwitcher;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

require_once dirname(__DIR__, 3) . '/src/Service/ManagementAccessGate.php';
require_once dirname(__DIR__, 3) . '/src/Service/ManagementFormDisplaySwitcher.php';

/**
 * Tests the service request management form display switcher.
 *
 * @group markaspot_ui
 * @coversDefaultClass \Drupal\markaspot_ui\Service\ManagementFormDisplaySwitcher
 */
final class ManagementFormDisplaySwitcherTest extends UnitTestCase {

  /**
   * Tests that an authorized edit form is switched to the management display.
   *
   * @covers ::prepareForm
   */
  public function testSwitchesToManagementDisplayForAuthorizedUser(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->once())
      ->method('hasPermission')
      ->with(ManagementFormDisplaySwitcher::PERMISSION)
      ->willReturn(TRUE);

    $managementDisplay = $this->createMock(EntityFormDisplayInterface::class);
    $managementDisplay->method('status')->willReturn(TRUE);
    $managementDisplay->method('id')->willReturn('node.service_request.management');

    $defaultDisplay = $this->createMock(EntityFormDisplayInterface::class);
    $defaultDisplay->method('id')->willReturn('node.service_request.default');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with('node.service_request.management')
      ->willReturn($managementDisplay);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('entity_form_display')->willReturn($storage);

    $formState = $this->createMock(FormStateInterface::class);
    $formObject = $this->createMock(ContentEntityFormInterface::class);
    $formObject->method('getFormDisplay')->with($formState)->willReturn($defaultDisplay);
    $formObject->expects($this->once())
      ->method('setFormDisplay')
      ->with($managementDisplay, $formState);
    $formState->method('getFormObject')->willReturn($formObject);

    $switcher = new ManagementFormDisplaySwitcher($account, $entityTypeManager, $this->routeMatch('entity.node.edit_form'), $this->gate(TRUE));
    $switcher->prepareForm($this->serviceRequestNode(), 'edit', $formState);
  }

  /**
   * Tests that users without the permission keep the default display.
   *
   * @covers ::prepareForm
   */
  public function testKeepsDefaultDisplayForUnauthorizedUser(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');
    $formState = $this->createMock(FormStateInterface::class);
    $formState->expects($this->never())->method('getFormObject');

    $switcher = new ManagementFormDisplaySwitcher($account, $entityTypeManager, $this->routeMatch('entity.node.edit_form'), $this->gate(TRUE));
    $switcher->prepareForm($this->serviceRequestNode(), 'edit', $formState);
  }

  /**
   * Tests that the node add route keeps the default display.
   *
   * The management display hides the required title widget, so it must never
   * be applied outside the edit route.
   *
   * @covers ::prepareForm
   */
  public function testKeepsDefaultDisplayOnNodeAddRoute(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->never())->method('hasPermission');
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');
    $formState = $this->createMock(FormStateInterface::class);
    $formState->expects($this->never())->method('getFormObject');

    $switcher = new ManagementFormDisplaySwitcher($account, $entityTypeManager, $this->routeMatch('node.add'), $this->gate(TRUE));
    $switcher->prepareForm($this->serviceRequestNode(), 'edit', $formState);
  }

  /**
   * Tests that a disabled management display is not applied.
   *
   * @covers ::prepareForm
   */
  public function testKeepsDefaultDisplayWhenManagementDisplayIsDisabled(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(TRUE);

    $managementDisplay = $this->createMock(EntityFormDisplayInterface::class);
    $managementDisplay->method('status')->willReturn(FALSE);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($managementDisplay);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $formState = $this->createMock(FormStateInterface::class);
    $formObject = $this->createMock(ContentEntityFormInterface::class);
    $formObject->expects($this->never())->method('setFormDisplay');
    $formState->method('getFormObject')->willReturn($formObject);

    $switcher = new ManagementFormDisplaySwitcher($account, $entityTypeManager, $this->routeMatch('entity.node.edit_form'), $this->gate(TRUE));
    $switcher->prepareForm($this->serviceRequestNode(), 'edit', $formState);
  }

  /**
   * Tests that a form already on the management display is left untouched.
   *
   * @covers ::prepareForm
   */
  public function testSkipsWhenManagementDisplayAlreadyActive(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(TRUE);

    $managementDisplay = $this->createMock(EntityFormDisplayInterface::class);
    $managementDisplay->method('status')->willReturn(TRUE);
    $managementDisplay->method('id')->willReturn('node.service_request.management');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($managementDisplay);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $formState = $this->createMock(FormStateInterface::class);
    $formObject = $this->createMock(ContentEntityFormInterface::class);
    $formObject->method('getFormDisplay')->with($formState)->willReturn($managementDisplay);
    $formObject->expects($this->never())->method('setFormDisplay');
    $formState->method('getFormObject')->willReturn($formObject);

    $switcher = new ManagementFormDisplaySwitcher($account, $entityTypeManager, $this->routeMatch('entity.node.edit_form'), $this->gate(TRUE));
    $switcher->prepareForm($this->serviceRequestNode(), 'edit', $formState);
  }

  /**
   * Tests that a SaaS-gated account keeps the default display.
   *
   * @covers ::prepareForm
   */
  public function testKeepsDefaultDisplayWhenGateBlocks(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(TRUE);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');
    $formState = $this->createMock(FormStateInterface::class);
    $formState->expects($this->never())->method('getFormObject');

    $switcher = new ManagementFormDisplaySwitcher($account, $entityTypeManager, $this->routeMatch('entity.node.edit_form'), $this->gate(FALSE));
    $switcher->prepareForm($this->serviceRequestNode(), 'edit', $formState);
  }

  /**
   * Builds a service request node mock.
   */
  private function serviceRequestNode(): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('getEntityTypeId')->willReturn('node');
    $node->method('bundle')->willReturn('service_request');
    return $node;
  }

  /**
   * Builds an access gate answering with the given decision.
   */
  private function gate(bool $allow): ManagementAccessGate {
    // ManagementAccessGate is final; build a real one with a controlled
    // operating mode. Self-hosted always allows, SaaS denies a non-trusted
    // account.
    new Settings(['markaspot_operating_mode' => $allow ? 'self_hosted' : 'saas']);
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    return new ManagementAccessGate($account);
  }

  /**
   * Builds a route match answering with the given route name.
   */
  private function routeMatch(string $routeName): RouteMatchInterface {
    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getRouteName')->willReturn($routeName);
    return $routeMatch;
  }

}
