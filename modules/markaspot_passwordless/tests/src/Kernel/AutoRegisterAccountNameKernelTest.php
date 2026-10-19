<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that OTP auto-registration does not name accounts after the address.
 *
 * @group markaspot_passwordless
 */
#[RunTestsInSeparateProcesses]
final class AutoRegisterAccountNameKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'markaspot_passwordless'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'user']);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
  }

  /**
   * A new account gets a neutral, unique name and keeps its address as mail.
   */
  public function testAutoRegisteredAccountGetsNeutralName(): void {
    $this->config('markaspot_passwordless.settings')->set('auto_register', TRUE)->save();
    $service = $this->container->get('markaspot_passwordless.otp');
    $method = new \ReflectionMethod($service, 'authenticateUser');

    $first = $method->invoke($service, 'anna.schmidt@example.org');
    $second = $method->invoke($service, 'ben@example.org');

    $this->assertInstanceOf(UserInterface::class, $first);
    $this->assertMatchesRegularExpression('/^member-[0-9a-f]{8}$/', $first->getAccountName());
    $this->assertStringNotContainsString('anna', $first->getAccountName());
    $this->assertSame('anna.schmidt@example.org', $first->getEmail());
    $this->assertNotSame($first->getAccountName(), $second->getAccountName());

    // The next login finds the same account by its address.
    $this->assertSame((int) $first->id(), (int) $method->invoke($service, 'anna.schmidt@example.org')->id());
  }

}
