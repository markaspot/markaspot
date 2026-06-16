<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_open311\Exception\GeoreportException;
use Drupal\markaspot_open311\RateLimit\Open311RateLimitTrait;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the configurable, create-specific Open311 flood control (#474).
 *
 * @group markaspot_open311
 */
class Open311RateLimitTraitTest extends UnitTestCase {

  /**
   * Builds a trait test subject with mocked dependencies.
   *
   * @param array $config
   *   Rate-limit values keyed by dotted config key.
   * @param bool $anonymous
   *   Whether the current user is anonymous.
   * @param array $roles
   *   The current user's roles.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood mock.
   *
   * @return object
   *   The anonymous trait subject exposing the protected methods.
   */
  private function makeSubject(array $config, bool $anonymous, array $roles, FloodInterface $flood): object {
    $immutable = $this->createMock(ImmutableConfig::class);
    $immutable->method('get')->willReturnCallback(static fn ($key) => $config[$key] ?? NULL);

    $account = $this->createMock(AccountInterface::class);
    $account->method('isAnonymous')->willReturn($anonymous);
    $account->method('getRoles')->willReturn($roles);
    $account->method('id')->willReturn($anonymous ? 0 : 7);

    $request = Request::create('/georeport/v2/requests.json', 'POST');
    $request->server->set('REMOTE_ADDR', '203.0.113.5');
    $stack = new RequestStack();
    $stack->push($request);

    return new class($flood, $account, $stack, $immutable, $this->createMock(LoggerInterface::class)) {

      use Open311RateLimitTrait;

      /**
       * Constructs the trait subject from the injected contract.
       */
      public function __construct(
        public FloodInterface $flood,
        public AccountInterface $currentUser,
        public RequestStack $requestStack,
        public ImmutableConfig $config,
        public LoggerInterface $logger,
      ) {}

      /**
       * Exposes the protected threshold resolver.
       */
      public function threshold(string $name): int {
        return $this->rateLimitThreshold($name);
      }

      /**
       * Exposes the protected window resolver.
       */
      public function window(): int {
        return $this->rateLimitWindow();
      }

      /**
       * Exposes the protected flood check.
       */
      public function check(string $name): void {
        $this->checkRateLimit($name);
      }

    };
  }

  /**
   * The create threshold comes from config, falling back to the strict default.
   */
  public function testCreateThresholdConfigAndDefault(): void {
    $flood = $this->createMock(FloodInterface::class);

    $configured = $this->makeSubject(['rate_limit.create_threshold' => 3], TRUE, [], $flood);
    $this->assertSame(3, $configured->threshold('georeport_api_create'));

    $default = $this->makeSubject([], TRUE, [], $flood);
    $this->assertSame(5, $default->threshold('georeport_api_create'));
  }

  /**
   * Read/search/update events keep the general threshold, not the create one.
   */
  public function testReadThresholdIsSeparateFromCreate(): void {
    $flood = $this->createMock(FloodInterface::class);
    $subject = $this->makeSubject(['rate_limit.create_threshold' => 3], TRUE, [], $flood);
    $this->assertSame(60, $subject->threshold('georeport_api_get'));
    $this->assertSame(60, $subject->threshold('georeport_api_post'));
  }

  /**
   * The window comes from config, falling back to 60 seconds.
   */
  public function testWindowConfigAndDefault(): void {
    $flood = $this->createMock(FloodInterface::class);
    $this->assertSame(30, $this->makeSubject(['rate_limit.window' => 30], TRUE, [], $flood)->window());
    $this->assertSame(60, $this->makeSubject([], TRUE, [], $flood)->window());
  }

  /**
   * Over-limit anonymous create throws 429 with a Retry-After header.
   */
  public function testOverLimitThrows429WithRetryAfter(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('georeport_api_create', 5, 60, '203.0.113.5')
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $subject = $this->makeSubject([], TRUE, [], $flood);
    try {
      $subject->check('georeport_api_create');
      $this->fail('Expected a 429 GeoreportException.');
    }
    catch (GeoreportException $e) {
      $this->assertSame(429, $e->getCode());
      $headers = $e->getHeaders();
      $this->assertArrayHasKey('Retry-After', $headers);
      $this->assertGreaterThanOrEqual(60, (int) $headers['Retry-After']);
    }
  }

  /**
   * Under-limit create registers the event under the configured window.
   */
  public function testUnderLimitRegisters(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);
    $flood->expects($this->once())
      ->method('register')
      ->with('georeport_api_create', 60, '203.0.113.5');

    $subject = $this->makeSubject([], TRUE, [], $flood);
    $subject->check('georeport_api_create');
  }

  /**
   * Exempt staff roles bypass flood control entirely.
   */
  public function testExemptRoleBypassesFlood(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->never())->method('isAllowed');
    $flood->expects($this->never())->method('register');

    $subject = $this->makeSubject([], FALSE, ['moderator'], $flood);
    $subject->check('georeport_api_create');
  }

}
