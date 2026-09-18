<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Language\Language;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\markaspot_nuxt\Language\JsonApiEntityRepository;
use Drupal\markaspot_nuxt\Language\JsonApiReadLanguage;
use Drupal\markaspot_nuxt\Language\JsonApiPageCacheRequestPolicy;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

require_once dirname(__DIR__, 3) . '/src/Language/JsonApiReadLanguage.php';
require_once dirname(__DIR__, 3) . '/src/Language/JsonApiEntityRepository.php';
require_once dirname(__DIR__, 3) . '/src/Language/JsonApiPageCacheRequestPolicy.php';

/**
 * Tests the API header contract and cache boundary.
 *
 * @group markaspot_nuxt
 */
final class JsonApiReadLanguageTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a resolver with installed English/French and a custom URL prefix.
   */
  private function resolver(Request $request): JsonApiReadLanguage {
    $stack = new RequestStack();
    $stack->push($request);
    $manager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $manager->method('getCurrentLanguage')->willReturn(new Language(['id' => 'en']));
    $manager->method('getNegotiatedLanguageMethod')->willReturn(
      str_starts_with($request->getPathInfo(), '/francais/') || $request->getHost() === 'fr.example.test'
        ? 'language-url' : 'language-url-fallback',
    );
    $manager->method('getLanguages')->willReturn([
      'en' => new Language(['id' => 'en']),
      'fr' => new Language(['id' => 'fr']),
    ]);
    return new JsonApiReadLanguage($stack, $manager, $this->getConfigFactoryStub([
      'language.mappings' => ['map' => []],
      'language.negotiation' => ['url' => ['prefixes' => ['en' => '', 'fr' => 'francais']]],
    ]), '/api/jsonapi');
  }

  /**
   * Tests supported languages, fallback, URL scopes and unchanged mutations.
   */
  #[DataProvider('languageCases')]
  public function testLanguage(string $method, string $path, string $header, ?string $expected, bool $read): void {
    $request = Request::create($path, $method);
    $request->headers->set('Accept-Language', $header);
    $request->headers->set('X-Translation-Language', 'fr');
    $resolver = $this->resolver($request);
    $this->assertSame($expected, $resolver->getLangcode());
    $this->assertSame($read, $resolver->isRead());
    $inner = $this->createMock(RequestPolicyInterface::class);
    $inner->method('check')->willReturn(RequestPolicyInterface::ALLOW);
    $policy = new JsonApiPageCacheRequestPolicy($inner, $resolver);
    $this->assertSame($read ? RequestPolicyInterface::DENY : RequestPolicyInterface::ALLOW, $policy->check($request));
  }

  /**
   * Request language cases.
   */
  public static function languageCases(): array {
    return [
      ['GET', '/api/jsonapi/node/page', 'fr', 'fr', TRUE],
      ['GET', '/api/jsonapi/node/page', 'en', 'en', TRUE],
      ['HEAD', '/api/jsonapi/node/page', 'fr-CA,en;q=0.8', 'fr', TRUE],
      ['GET', '/api/jsonapi/node/page', 'fr;q=0,en;q=1', 'en', TRUE],
      ['GET', '/api/jsonapi/node/page', 'de', NULL, TRUE],
      ['GET', '/api/jsonapi/node/page', '', NULL, TRUE],
      ['GET', '/api/jsonapi/node/page', 'bogus<script>', NULL, TRUE],
      ['GET', '/francais/api/jsonapi/node/page', 'en', NULL, TRUE],
      ['GET', 'https://fr.example.test/api/jsonapi/node/page', 'en', NULL, TRUE],
      ['GET', '/api/jsonapi-unrelated', 'fr', NULL, FALSE],
      ['GET', '/node/1', 'fr', NULL, FALSE],
      ['POST', '/api/jsonapi/node/page', 'fr', NULL, FALSE],
      ['PATCH', '/api/jsonapi/node/page/uuid', 'fr', NULL, FALSE],
      ['DELETE', '/api/jsonapi/node/page/uuid', 'fr', NULL, FALSE],
    ];
  }

  /**
   * Explicit translation requests still take precedence over read headers.
   */
  public function testExplicitLanguageIsPreserved(): void {
    $request = Request::create('/api/jsonapi/node/page');
    $request->headers->set('Accept-Language', 'fr');
    $entity = $this->createMock(EntityInterface::class);
    $inner = $this->createMock(EntityRepositoryInterface::class);
    $inner->expects($this->once())->method('getTranslationFromContext')->with($entity, 'en', ['operation' => 'entity_view'])->willReturn($entity);
    $adapter = new JsonApiEntityRepository($inner, $this->resolver($request));
    $this->assertSame($entity, $adapter->getTranslationFromContext($entity, 'en', ['operation' => 'entity_view']));
  }

  /**
   * Empty API responses vary without removing existing Vary headers.
   */
  public function testResponseCacheMetadata(): void {
    $request = Request::create('/api/jsonapi/node/page');
    $request->attributes->set('_is_jsonapi', TRUE);
    $response = new CacheableResponse('{}');
    $response->setVary(['Cookie', 'Accept']);
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    $this->resolver($request)->onResponse($event);
    $this->resolver($request)->onResponseHeaders($event);
    $this->assertSame(['Cookie', 'Accept', 'Accept-Language'], $response->getVary());
    $this->assertContains(JsonApiReadLanguage::CACHE_CONTEXT, $response->getCacheableMetadata()->getCacheContexts());
  }

}
