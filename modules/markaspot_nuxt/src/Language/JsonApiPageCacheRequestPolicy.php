<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Language;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Bypasses only Internal Page Cache, whose URL keys cannot vary by language.
 *
 * Injected only into the page-cache middleware, never the shared policy chain:
 * HTTP cache headers and Dynamic Page Cache retain their existing behavior.
 */
final class JsonApiPageCacheRequestPolicy implements RequestPolicyInterface {

  /**
   * Constructs the middleware-specific policy.
   */
  public function __construct(
    private readonly RequestPolicyInterface $inner,
    private readonly JsonApiReadLanguage $readLanguage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function check(Request $request) {
    if ($request->isMethodCacheable() && $this->readLanguage->isApiPath($request)) {
      return self::DENY;
    }
    return $this->inner->check($request);
  }

}
