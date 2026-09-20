<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Language;

use Drupal\Component\Utility\UserAgent;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUrl;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves the Nuxt JSON:API read language and protects its response caches.
 *
 * This does not change Drupal's interface/content negotiation configuration.
 * Mutation requests keep their existing URL and explicit-language semantics.
 */
final class JsonApiReadLanguage implements EventSubscriberInterface {

  /**
   * Cache context for the API's existing HTTP language contract.
   */
  public const CACHE_CONTEXT = 'headers:Accept-Language';

  /**
   * Configuration affecting whether and how a header language is selected.
   */
  public const CACHE_TAGS = [
    'config:configurable_language_list',
    'config:language.mappings',
    'config:language.negotiation',
    'config:language.types',
  ];

  /**
   * Constructs the API language resolver.
   */
  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly string $basePath,
  ) {}

  /**
   * Whether the current request is an API read.
   */
  public function isRead(): bool {
    $request = $this->requestStack->getCurrentRequest();
    return $request !== NULL && $request->isMethodCacheable() && $this->isApiPath($request);
  }

  /**
   * Gets a supported header language, or NULL for normal Drupal negotiation.
   */
  public function getLangcode(): ?string {
    if (!$this->isRead()) {
      return NULL;
    }
    if ($this->languageManager instanceof ConfigurableLanguageManagerInterface) {
      // An explicit language prefix or language domain keeps its existing
      // precedence. Unprefixed Nuxt requests use language-url-fallback instead.
      $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_URL);
      if ($this->languageManager->getNegotiatedLanguageMethod(LanguageInterface::TYPE_URL) === LanguageNegotiationUrl::METHOD_ID) {
        return NULL;
      }
    }
    $header = $this->requestStack->getCurrentRequest()->headers->get('Accept-Language', '');
    $langcode = UserAgent::getBestMatchingLangcode(
      $header,
      array_keys($this->languageManager->getLanguages()),
      $this->configFactory->get('language.mappings')->get('map') ?? [],
    );
    return $langcode ?: NULL;
  }

  /**
   * Adds variation even to empty collections and error responses.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    if (!$request->isMethodCacheable() || !$request->attributes->get('_is_jsonapi')) {
      return;
    }
    $response = $event->getResponse();
    if ($response instanceof CacheableResponseInterface) {
      $response->getCacheableMetadata()
        ->addCacheContexts([self::CACHE_CONTEXT])
        ->addCacheTags(self::CACHE_TAGS);
    }
  }

  /**
   * Finishes HTTP language headers after core's response finalization.
   */
  public function onResponseHeaders(ResponseEvent $event): void {
    $request = $event->getRequest();
    if (!$request->isMethodCacheable() || !$request->attributes->get('_is_jsonapi')) {
      return;
    }
    // FinishResponseSubscriber removes Vary on private responses and writes
    // the interface language. API reads instead target the negotiated content
    // audience; individual resources can still use core's entity fallback.
    $response = $event->getResponse();
    $response->setVary('Accept-Language', FALSE);
    if ($langcode = $this->getLangcode()) {
      $response->headers->set('Content-Language', $langcode);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After JSON:API serialization (128), before Dynamic Page Cache (7).
    return [KernelEvents::RESPONSE => [
      ['onResponse', 100],
      ['onResponseHeaders', -10],
    ]];
  }

  /**
   * Matches the actual JSON:API base path and configured language prefixes.
   *
   * This is also used before routing by Internal Page Cache. No path is ever
   * rewritten, and custom JSON:API paths or non-code language prefixes work.
   */
  public function isApiPath(Request $request): bool {
    $path = $request->getPathInfo();
    $prefixes = ['', ...array_values($this->configFactory->get('language.negotiation')->get('url.prefixes') ?? [])];
    foreach (array_unique($prefixes) as $prefix) {
      $base = ($prefix === '' ? '' : '/' . trim($prefix, '/')) . '/' . trim($this->basePath, '/');
      if ($path === $base || str_starts_with($path, $base . '/')) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
