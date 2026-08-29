<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot\Unit;

use Drupal\markaspot_icons\Routing\IconApiRouteSubscriber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

require_once dirname(__DIR__, 3) . '/modules/markaspot_icons/src/Routing/IconApiRouteSubscriber.php';

/**
 * Tests access hardening for local Iconify endpoints.
 *
 * @group markaspot
 */
class MarkaspotIconsRouteAccessTest extends UnitTestCase {

  /**
   * Tests that Iconify API routes require an administrative session.
   */
  #[DataProvider('protectedRouteProvider')]
  public function testIconifyApiRouteRequiresAdministrationAccess(
    string $route_name,
    string $path,
    ?string $parameter,
    ?string $pattern,
  ): void {
    $collection = new RouteCollection();
    $collection->add($route_name, new Route($path, requirements: [
      '_access' => 'TRUE',
    ]));

    $this->subscriber()->alter($collection);

    $requirements = $collection->get($route_name)?->getRequirements();
    self::assertSame('access administration pages', $requirements['_permission'] ?? NULL);
    self::assertArrayNotHasKey('_access', $requirements ?? []);
    if ($parameter !== NULL) {
      self::assertSame($pattern, $requirements[$parameter] ?? NULL);
    }
  }

  /**
   * Provides local Iconify API routes.
   *
   * @return array<string, array{string, string, ?string, ?string}>
   *   Route names keyed by a readable test label.
   */
  public static function protectedRouteProvider(): array {
    return [
      'collection metadata' => [
        'iconify_field.api.collections',
        '/api/iconify_field/collections',
        NULL,
        NULL,
      ],
      'collection icons' => [
        'iconify_field.api.icons',
        '/api/iconify_field/icons/{collection}',
        'collection',
        '(?:lucide|heroicons|fa6-solid|fa6-regular|tabler|phosphor|ph)',
      ],
      'rendered icon' => [
        'fa_icon_class.api.render',
        '/api/iconify_field/render/{icon}',
        'icon',
        '(?:lucide|heroicons|fa6-solid|fa6-regular|tabler|phosphor|ph):[a-z0-9_-]+',
      ],
    ];
  }

  /**
   * Tests that unrelated routes remain unchanged.
   */
  public function testUnrelatedRouteRemainsUnchanged(): void {
    $collection = new RouteCollection();
    $collection->add('example.public', new Route('/public', requirements: [
      '_access' => 'TRUE',
    ]));

    $this->subscriber()->alter($collection);

    self::assertSame(
      ['_access' => 'TRUE'],
      $collection->get('example.public')?->getRequirements(),
    );
  }

  /**
   * Tests that unsupported large collections never reach a controller.
   */
  public function testOnlySupportedCollectionsMatchHardenedRoutes(): void {
    $collection = new RouteCollection();
    $collection->add(
      'iconify_field.api.icons',
      new Route('/api/iconify_field/icons/{collection}', requirements: ['_access' => 'TRUE']),
    );
    $collection->add(
      'fa_icon_class.api.render',
      new Route('/api/iconify_field/render/{icon}', requirements: ['_access' => 'TRUE']),
    );
    $this->subscriber()->alter($collection);

    $matcher = new UrlMatcher($collection, new RequestContext());
    foreach ([
      'lucide',
      'heroicons',
      'fa6-solid',
      'fa6-regular',
      'tabler',
      'phosphor',
      'ph',
    ] as $supported_collection) {
      self::assertSame(
        'iconify_field.api.icons',
        $matcher->match('/api/iconify_field/icons/' . $supported_collection)['_route'],
      );
      self::assertSame(
        'fa_icon_class.api.render',
        $matcher->match('/api/iconify_field/render/' . $supported_collection . ':home')['_route'],
      );
    }

    foreach ([
      '/api/iconify_field/icons/fluent-emoji',
      '/api/iconify_field/render/fluent-emoji:missing',
    ] as $path) {
      try {
        $matcher->match($path);
        self::fail(sprintf('Unsupported Iconify path %s matched a route.', $path));
      }
      catch (ResourceNotFoundException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Builds a subscriber with a public test entry point.
   */
  private function subscriber(): MarkaspotIconsTestRouteSubscriber {
    return new MarkaspotIconsTestRouteSubscriber();
  }

}

/**
 * Exposes the protected route alter method for unit tests.
 */
class MarkaspotIconsTestRouteSubscriber extends IconApiRouteSubscriber {

  /**
   * Applies route alterations.
   */
  public function alter(RouteCollection $collection): void {
    $this->alterRoutes($collection);
  }

}
