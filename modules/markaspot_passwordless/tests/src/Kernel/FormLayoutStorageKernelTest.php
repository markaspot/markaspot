<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Kernel;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_passwordless\Controller\FormLayoutPreferencesController;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__, 3) . '/src/Controller/FormLayoutPreferencesController.php';

/**
 * Tests cross-session persistence and isolated account/workspace layouts.
 *
 * @group markaspot_passwordless
 */
#[RunTestsInSeparateProcesses]
final class FormLayoutStorageKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
  }

  /**
   * Creates a fresh controller/session with real persistent user data.
   */
  private function controller(int $uid, int $jurisdiction): FormLayoutPreferencesController {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('id')->willReturn($uid);
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('id')->willReturn($jurisdiction);
    $group->method('isPublished')->willReturn(TRUE);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($group);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturn($storage);
    $visibility = $this->createMock(WorkspaceVisibilityInterface::class);
    $visibility->method('allowsReadFor')->willReturn(TRUE);
    return new FormLayoutPreferencesController($account, $manager, $this->container->get('user.data'), $visibility);
  }

  /**
   * Fresh sessions reload the owner's layout; other accounts/workspaces do not.
   */
  public function testPersistenceAndIsolation(): void {
    $layout = ['version' => 1, 'left' => FormLayoutPreferencesController::SECTIONS, 'right' => []];
    $save = $this->controller(42, 69)->preferences(Request::create('/', 'PATCH', [], [], [], [], json_encode(['layout' => $layout])), '69');
    $this->assertSame(200, $save->getStatusCode());
    $read = static fn($response) => json_decode($response->getContent(), TRUE)['layout'];
    $this->assertSame($layout, $read($this->controller(42, 69)->preferences(Request::create('/'), '69')));
    $this->assertNull($read($this->controller(43, 69)->preferences(Request::create('/'), '69')));
    $this->assertNull($read($this->controller(42, 70)->preferences(Request::create('/'), '70')));
    $this->container->get('user.data')->set('markaspot_passwordless', 42, 'form_layout.70', $layout);
    $this->controller(42, 69)->preferences(Request::create('/', 'PATCH', [], [], [], [], '{"layout":null}'), '69');
    $this->assertNull($read($this->controller(42, 69)->preferences(Request::create('/'), '69')));
    $this->assertSame($layout, $read($this->controller(42, 70)->preferences(Request::create('/'), '70')));
  }

}
