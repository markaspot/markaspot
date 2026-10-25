<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\Session\UserSessionRepositoryInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\markaspot_fastmap\Service\DemoStaffAccount;
use Drupal\markaspot_fastmap\Demo\DemoWorkspace;
use Drupal\user\UserInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_passwordless\Controller\FormLayoutPreferencesController;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserDataInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 3) . '/src/Controller/FormLayoutPreferencesController.php';

/**
 * Tests ownership, workspace separation and the bounded preference contract.
 *
 * @group markaspot_passwordless
 */
final class FormLayoutPreferencesTest extends UnitTestCase {

  /**
   * Returns the layout requested by Krefeld.
   */
  private function layout(): array {
    return [
      'version' => 1,
      'left' => ['details', 'location', 'media', 'contact', 'status_history'],
      'right' => ['status_actions', 'context', 'service_provider', 'internal', 'related'],
    ];
  }

  /**
   * Creates a controller with a fixed session account.
   */
  private function controller(UserDataInterface $data, bool $authenticated = TRUE, bool $allowed = TRUE, ?DemoStaffAccount $demo = NULL, ?AccountInterface $account = NULL): FormLayoutPreferencesController {
    $account ??= $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(42);
    $account->method('isAuthenticated')->willReturn($authenticated);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(69);
    $group->method('bundle')->willReturn('jur');
    $group->method('isPublished')->willReturn(TRUE);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($group);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('group')->willReturn($storage);
    $visibility = $this->createMock(WorkspaceVisibilityInterface::class);
    $visibility->method('allowsReadFor')->with($account, 69)->willReturn($allowed);
    return new FormLayoutPreferencesController($account, $manager, $data, $visibility, $demo);
  }

  /**
   * Stores only under the authenticated account and resolved workspace.
   */
  public function testSaveUsesSessionAccountAndCanonicalJurisdiction(): void {
    $data = $this->createMock(UserDataInterface::class);
    $data->expects($this->once())->method('set')
      ->with('markaspot_passwordless', 42, 'form_layout.69', $this->layout());
    $response = $this->controller($data)->preferences(
      Request::create('/', 'PATCH', [], [], [], [], json_encode(['layout' => $this->layout()])), '69');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
  }

  /**
   * A fresh request retrieves the same account preference.
   */
  public function testReloadUsesSameAccountAndWorkspace(): void {
    $data = $this->createMock(UserDataInterface::class);
    $data->expects($this->once())->method('get')
      ->with('markaspot_passwordless', 42, 'form_layout.69')->willReturn($this->layout());
    $response = $this->controller($data)->preferences(Request::create('/', 'GET'), '69');
    $this->assertSame($this->layout(), json_decode($response->getContent(), TRUE)['layout']);
  }

  /**
   * Reset removes exactly one account and workspace preference.
   */
  public function testResetDeletesOnlyThisPreference(): void {
    $data = $this->createMock(UserDataInterface::class);
    $data->expects($this->once())->method('delete')->with('markaspot_passwordless', 42, 'form_layout.69');
    $response = $this->controller($data)->preferences(Request::create('/', 'PATCH', [], [], [], [], '{"layout":null}'), '69');
    $this->assertNull(json_decode($response->getContent(), TRUE)['layout']);
  }

  /**
   * Malformed payloads cannot mutate preferences.
   */
  #[DataProvider('invalidBodies')]
  public function testInvalidWritesDoNotReachStorage(string $body): void {
    $data = $this->createMock(UserDataInterface::class);
    $data->expects($this->never())->method('set');
    $data->expects($this->never())->method('delete');
    $response = $this->controller($data)->preferences(Request::create('/', 'PATCH', [], [], [], [], $body), '69');
    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * Provides malformed, oversized and foreign-account payloads.
   */
  public static function invalidBodies(): iterable {
    yield 'invalid JSON' => ['{'];
    yield 'missing layout' => ['{}'];
    yield 'other account' => ['{"layout":null,"uid":43}'];
    yield 'unknown version' => ['{"layout":{"version":2,"left":[],"right":[]}}'];
    yield 'missing sections' => ['{"layout":{"version":1,"left":[],"right":[]}}'];
    yield 'duplicate sections' => [json_encode(['layout' => [
      'version' => 1, 'left' => array_fill(0, 10, 'contact'), 'right' => [],
    ]])];
    yield 'nested ID' => [json_encode(['layout' => [
      'version' => 1, 'left' => array_fill(0, 10, ['contact']), 'right' => [],
    ]])];
    yield 'oversized body' => [str_repeat(' ', 2049)];
  }

  /**
   * Anonymous and blocked workspace requests cannot reach storage.
   */
  public function testAnonymousAndBlockedWorkspacesNeverReadOrWrite(): void {
    $data = $this->createMock(UserDataInterface::class);
    $data->expects($this->never())->method('get');
    $data->expects($this->never())->method('set');
    foreach ([[FALSE, TRUE], [TRUE, FALSE]] as [$authenticated, $allowed]) {
      $response = $this->controller($data, $authenticated, $allowed)->preferences(Request::create('/', 'GET'), '69');
      $this->assertSame(403, $response->getStatusCode());
    }
  }

  /**
   * The real demo recognizer prevents reads and writes to user.data.
   */
  public function testSharedDemoKeepsNoPreference(): void {
    $site_uuid = '11bd3935-672b-41b3-9f90-aefecbc86350';
    $account = $this->createMock(UserInterface::class);
    $account->method('getAccountName')->willReturn(DemoStaffAccount::accountName('demo-layout'));
    $account->method('uuid')->willReturn(DemoWorkspace::uuid($site_uuid, 'demo-layout', 'user', 'staff'));
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(42)->willReturn($account);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('user')->willReturn($storage);
    $demo = new DemoStaffAccount(
      $manager,
      $this->getConfigFactoryStub(['system.site' => ['uuid' => $site_uuid]]),
      $this->createMock(UserSessionRepositoryInterface::class),
      $this->createMock(TimeInterface::class),
    );
    $data = $this->createMock(UserDataInterface::class);
    foreach (['get', 'set', 'delete'] as $method) {
      $data->expects($this->never())->method($method);
    }
    $controller = $this->controller($data, TRUE, TRUE, $demo, $account);
    $response = $controller->preferences(Request::create('/', 'GET'), '69');
    $this->assertSame(['layout' => NULL, 'writable' => FALSE], json_decode($response->getContent(), TRUE));
    $response = $controller->preferences(Request::create('/', 'PATCH', [], [], [], [], '{"layout":null}'), '69');
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * The declared cookie route applies core CSRF checking to preference writes.
   */
  public function testRouteRequiresCookieAuthenticationAndCsrf(): void {
    $routes = Yaml::parseFile(dirname(__DIR__, 3) . '/markaspot_passwordless.routing.yml');
    $definition = $routes['markaspot_passwordless.form_layout_preferences'];
    $this->assertSame(['cookie'], $definition['options']['_auth']);
    $this->assertSame('TRUE', $definition['requirements']['_user_is_logged_in']);
    $this->assertSame(['GET', 'PATCH'], $definition['methods']);
    $route = new Route($definition['path'], $definition['defaults'], $definition['requirements']);
    $session = $this->createMock(SessionConfigurationInterface::class);
    $session->method('hasSession')->willReturn(TRUE);
    $token = $this->createMock(CsrfTokenGenerator::class);
    $token->method('validate')->willReturnCallback(static fn(string $value, string $key): bool => $value === 'valid' && $key === CsrfRequestHeaderAccessCheck::TOKEN_KEY);
    $check = new CsrfRequestHeaderAccessCheck($session, $token);
    $this->assertTrue($check->applies($route));
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $this->assertTrue($check->access(Request::create('/', 'GET'), $account)->isAllowed());
    $patch = Request::create('/', 'PATCH');
    $this->assertTrue($check->access($patch, $account)->isForbidden());
    $patch->headers->set('X-CSRF-Token', 'invalid');
    $this->assertTrue($check->access($patch, $account)->isForbidden());
    $patch->headers->set('X-CSRF-Token', 'valid');
    $this->assertTrue($check->access($patch, $account)->isAllowed());
  }

  /**
   * Invalid stored versions fall back safely without repairing other data.
   */
  public function testCorruptStoredPreferenceFallsBackWithoutWriting(): void {
    $data = $this->createMock(UserDataInterface::class);
    $data->method('get')->willReturn(['version' => 999]);
    $data->expects($this->never())->method('set');
    $response = $this->controller($data)->preferences(Request::create('/', 'GET'), '69');
    $this->assertNull(json_decode($response->getContent(), TRUE)['layout']);
  }

}
