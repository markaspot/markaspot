<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Cookie-authenticated AI write routes require the CSRF request header.
 *
 * The routes are compiled by the real router builder (via a test-only route
 * callback that returns the markaspot_ai routing definitions) and matched
 * through the access-aware router, so the access check runs exactly where it
 * runs in production: before any controller, mutation or provider call.
 */
#[TestGroup('markaspot_ai')]
#[RunTestsInSeparateProcesses]
final class AiWriteRouteCsrfKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node',
    'markaspot_ai_route_test',
  ];

  /**
   * The seven cookie-authenticated AI write routes from #617.
   */
  private const WRITE_ROUTES = [
    'markaspot_ai.duplicate_scan' => '/api/ai/duplicates/{nid}/scan',
    'markaspot_ai.sentiment_analyze' => '/api/ai/sentiment/{nid}/analyze',
    'markaspot_ai.processing_queue' => '/api/ai/processing/queue',
    'markaspot_ai.processing_run' => '/api/ai/processing/run',
    'markaspot_ai.attributes.fill' => '/api/ai/attributes/fill',
    'markaspot_ai.attributes.queue' => '/api/ai/attributes/queue',
    'markaspot_ai.attributes.describe' => '/api/ai/attributes/describe',
  ];

  /**
   * Account that passes every permission check (UID 1).
   */
  private UserInterface $superUser;

  /**
   * Authenticated account without AI permissions.
   */
  private UserInterface $plainUser;

  /**
   * Node used for the routes with a {node} parameter.
   */
  private int $nid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node']);

    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    $node = Node::create(['type' => 'service_request', 'title' => 'Report']);
    $node->save();
    $this->nid = (int) $node->id();

    // UID 1 bypasses permission checks, so only the CSRF check can deny it.
    $this->superUser = $this->createUser([], 'admin', FALSE, ['uid' => 1]);
    $this->plainUser = $this->createUser([], 'plain');

    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Every POST route in markaspot_ai is compiled with the header check.
   */
  public function testEveryAiWriteRouteCompilesTheHeaderCheck(): void {
    $provider = $this->container->get('router.route_provider');
    $writeRoutes = [];
    foreach ($provider->getAllRoutes() as $name => $route) {
      if (!str_starts_with($name, 'markaspot_ai.')) {
        continue;
      }
      $writes = array_diff($route->getMethods(), ['GET', 'HEAD', 'OPTIONS', 'TRACE']);
      if ($route->getMethods() === [] || $writes === []) {
        continue;
      }
      // Admin pages are browser navigation: routes without declared methods
      // get GET and POST from core's RouteMethodSubscriber. Their state
      // changes are covered by the form token or the link token below.
      if (!str_starts_with($route->getPath(), '/api/')) {
        continue;
      }
      $writeRoutes[] = $name;
      $this->assertSame('TRUE', $route->getRequirement('_csrf_request_header_token'), "$name requires the CSRF request header.");
      $this->assertContains('access_check.header.csrf', (array) $route->getOption('_access_checks'), "$name compiles the header access check.");
    }
    // The seven routes of #617 plus the already protected assistant route.
    foreach (array_keys(self::WRITE_ROUTES) as $name) {
      $this->assertContains($name, $writeRoutes);
    }
    $this->assertContains('markaspot_ai.attributes.assist', $writeRoutes);

    // The admin actions that change state carry the link token.
    foreach (['markaspot_ai.admin_queue_missing', 'markaspot_ai.admin_process_queue'] as $name) {
      $route = $provider->getRouteByName($name);
      $this->assertSame('TRUE', $route->getRequirement('_csrf_token'), "$name keeps its link token.");
      $this->assertContains('access_check.csrf', (array) $route->getOption('_access_checks'));
    }
    $this->assertTrue($provider->getRouteByName('markaspot_ai.admin_duplicate_review')->hasDefault('_form'), 'The review page is a form with its own form token.');
  }

  /**
   * A cookie session without a token is refused before the controller.
   */
  public function testCookieSessionWithoutTokenIsRefused(): void {
    foreach (self::WRITE_ROUTES as $name => $path) {
      $request = $this->cookieRequest($path);
      $this->assertAccessDenied($request, 'X-CSRF-Token request header is missing', $name);
    }
  }

  /**
   * A cookie session with an invalid token is refused.
   */
  public function testCookieSessionWithInvalidTokenIsRefused(): void {
    foreach (self::WRITE_ROUTES as $name => $path) {
      $request = $this->cookieRequest($path);
      $request->headers->set('X-CSRF-Token', 'not-a-valid-token');
      $this->assertAccessDenied($request, 'X-CSRF-Token request header is invalid', $name);
    }
  }

  /**
   * A cookie session with the session token reaches the route.
   */
  public function testCookieSessionWithValidTokenIsAllowed(): void {
    foreach (self::WRITE_ROUTES as $name => $path) {
      $request = $this->cookieRequest($path);
      $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
      $this->assertSame($name, $this->match($request), "$name accepts a valid session token.");
    }
  }

  /**
   * Stateless (API-key) requests carry no session cookie and need no token.
   */
  public function testStatelessRequestWithoutTokenIsAllowed(): void {
    // The api_key_auth provider authenticates the account; core's header
    // check only enforces the token when a session cookie is present.
    $this->container->get('current_user')->setAccount($this->superUser);
    foreach (self::WRITE_ROUTES as $name => $path) {
      $request = Request::create($this->path($path), 'POST');
      $this->assertSame($name, $this->match($request), "$name keeps stateless API-key integrations working.");
    }
  }

  /**
   * A valid token never replaces the route permission.
   */
  public function testValidTokenDoesNotBypassPermissions(): void {
    $this->container->get('current_user')->setAccount($this->plainUser);
    foreach (self::WRITE_ROUTES as $name => $path) {
      $request = $this->cookieRequest($path, FALSE);
      $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
      try {
        $this->match($request);
        $this->fail("$name must stay permission-protected.");
      }
      catch (AccessDeniedHttpException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Builds a POST request that carries the session cookie.
   */
  private function cookieRequest(string $path, bool $asSuperUser = TRUE): Request {
    if ($asSuperUser) {
      $this->container->get('current_user')->setAccount($this->superUser);
    }
    $request = Request::create($this->path($path), 'POST');
    $name = $this->container->get('session_configuration')->getOptions($request)['name'];
    $request->cookies->set($name, 'kernel-session');
    $this->assertTrue($this->container->get('session_configuration')->hasSession($request));
    return $request;
  }

  /**
   * Resolves the node placeholder.
   */
  private function path(string $path): string {
    return str_replace('{nid}', (string) $this->nid, $path);
  }

  /**
   * Matches a request through the access-aware router.
   */
  private function match(Request $request): string {
    $this->container->get('request_stack')->push($request);
    // The router listener does this per request in production.
    $this->container->get('router.request_context')->fromRequest($request);
    try {
      return (string) $this->container->get('router')->matchRequest($request)['_route'];
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
  }

  /**
   * Asserts the router refuses the request with the given reason.
   */
  private function assertAccessDenied(Request $request, string $reason, string $name): void {
    try {
      $this->match($request);
      $this->fail("$name must be refused without a valid CSRF header.");
    }
    catch (AccessDeniedHttpException $e) {
      $this->assertSame($reason, $e->getMessage(), "$name is refused by the CSRF header check.");
    }
  }

}
