<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_cap\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_cap\Access\EmergencyActiveAccessCheck;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Tests jurisdiction-scoped CAP access.
 *
 * @group markaspot_cap
 * @coversDefaultClass \Drupal\markaspot_cap\Access\EmergencyActiveAccessCheck
 */
class EmergencyActiveAccessCheckTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * @covers ::access
   */
  public function testDeniesWhenResolvedJurisdictionIsOff(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->once())
      ->method('resolveRootJurisdictionId')
      ->with('amsterdam')
      ->willReturn(7);
    $service->method('isActive')->with(7)->willReturn(FALSE);

    $result = $this->checker($service, 'amsterdam')->access(
      new Route('/api/cap/v1/alerts'),
      $this->createMock(AccountInterface::class),
    );

    $this->assertFalse($result->isAllowed());
  }

  /**
   * @covers ::access
   */
  public function testAllowsOnlyResolvedActiveJurisdiction(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->method('resolveRootJurisdictionId')->with('7')->willReturn(7);
    $service->method('isActive')->with(7)->willReturn(TRUE);

    $result = $this->checker($service, '7')->access(
      new Route('/api/cap/v1/alerts'),
      $this->createMock(AccountInterface::class),
    );

    $this->assertTrue($result->isAllowed());
    $this->assertContains('markaspot_emergency:status:7', $result->getCacheTags());
    $this->assertContains('url.query_args:jurisdiction_id', $result->getCacheContexts());
  }

  /**
   * @covers ::access
   */
  public function testInvalidOrMissingMultiTenantScopeFailsClosed(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->method('resolveRootJurisdictionId')
      ->willThrowException(new \InvalidArgumentException('jurisdiction required'));

    $result = $this->checker($service, NULL)->access(
      new Route('/api/cap/v1/alerts'),
      $this->createMock(AccountInterface::class),
    );

    $this->assertFalse($result->isAllowed());
    $this->assertContains(EmergencyModeService::CACHE_TAG, $result->getCacheTags());
  }

  /**
   * @covers ::access
   */
  public function testArrayJurisdictionFailsClosedBeforeServiceCall(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->never())->method('resolveRootJurisdictionId');
    $request = Request::create('/api/cap/v1/alerts?jurisdiction_id[]=7');
    $requestStack = new RequestStack();
    $requestStack->push($request);
    $checker = new EmergencyActiveAccessCheck($service, $requestStack);

    $result = $checker->access(
      new Route('/api/cap/v1/alerts'),
      $this->createMock(AccountInterface::class),
    );

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Builds the checker with a current request.
   */
  private function checker(EmergencyModeService $service, ?string $jurisdiction): EmergencyActiveAccessCheck {
    $request = Request::create('/api/cap/v1/alerts');
    if ($jurisdiction !== NULL) {
      $request->query->set('jurisdiction_id', $jurisdiction);
    }
    $requestStack = new RequestStack();
    $requestStack->push($request);
    return new EmergencyActiveAccessCheck($service, $requestStack);
  }

}
