<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\markaspot_nuxt\Language\JsonApiEntityRepository;
use Drupal\markaspot_nuxt\Language\JsonApiReadLanguage;
use Drupal\markaspot_nuxt\Language\JsonApiPageCacheRequestPolicy;
use Drupal\markaspot_nuxt\MarkaspotNuxtServiceProvider;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

require_once dirname(__DIR__, 3) . '/src/Language/JsonApiReadLanguage.php';
require_once dirname(__DIR__, 3) . '/src/Language/JsonApiEntityRepository.php';
require_once dirname(__DIR__, 3) . '/src/Language/JsonApiPageCacheRequestPolicy.php';
require_once dirname(__DIR__, 3) . '/src/MarkaspotNuxtServiceProvider.php';

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

  /**
   * Compiles optional API integration without changing unrelated services.
   */
  #[DataProvider('moduleCombinations')]
  public function testOptionalJsonApiContainer(bool $jsonapi, bool $page_cache): void {
    $container = new ContainerBuilder();
    foreach ([
      'request_stack' => RequestStack::class,
      'language_manager' => LanguageManagerInterface::class,
      'config.factory' => ConfigFactoryInterface::class,
      'entity.repository' => EntityRepositoryInterface::class,
      'page_cache_request_policy' => RequestPolicyInterface::class,
    ] as $id => $class) {
      $container->register($id, $class)->setSynthetic(TRUE)->setPublic(TRUE);
    }
    $shared_policy = $container->getDefinition('page_cache_request_policy');
    $unrelated_service = $container->register('markaspot_nuxt.public_url_validator', \stdClass::class)->setPublic(TRUE);

    // Load the actual parameter references from the shipped service file so
    // compilation reproduces the missing-jsonapi.base_path regression.
    $definitions = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/markaspot_nuxt.services.yml'))['services'];
    $language_services = [
      'markaspot_nuxt.jsonapi_read_language',
      'markaspot_nuxt.jsonapi_entity_repository',
      'markaspot_nuxt.jsonapi_page_cache_request_policy',
    ];
    foreach ($language_services as $id) {
      $definition = $definitions[$id];
      $arguments = array_map(static fn(string $value) => str_starts_with($value, '@') ? new Reference(substr($value, 1)) : $value, $definition['arguments']);
      $container->register($id, $definition['class'])->setArguments($arguments)->setPublic(TRUE);
    }
    if ($page_cache) {
      $container->register('http_middleware.page_cache', \stdClass::class)
        ->setSynthetic(TRUE)->setPublic(TRUE)
        ->setArguments([NULL, new Reference('page_cache_request_policy'), NULL]);
    }
    if ($jsonapi) {
      $container->setParameter('jsonapi.base_path', '/api/jsonapi');
      $container->register('jsonapi.entity_resource', \stdClass::class)->setSynthetic(TRUE)->setPublic(TRUE);
      $container->register('jsonapi.entity_access_checker', \stdClass::class)
        ->setSynthetic(TRUE)->setPublic(TRUE)
        ->setArguments([NULL, NULL, NULL, new Reference('entity.repository')]);
      $container->register('paramconverter.entity', \stdClass::class)
        ->setSynthetic(TRUE)->setPublic(TRUE)
        ->setArguments([NULL, new Reference('entity.repository')]);
      // The actual converter inherits its repository argument from core.
      $container->setDefinition('paramconverter.jsonapi.entity_uuid',
        (new ChildDefinition('paramconverter.entity'))->setSynthetic(TRUE)->setPublic(TRUE),
      );
    }

    (new MarkaspotNuxtServiceProvider())->alter($container);
    $this->assertSame($shared_policy, $container->getDefinition('page_cache_request_policy'));
    $this->assertSame($unrelated_service, $container->getDefinition('markaspot_nuxt.public_url_validator'));
    $container->compile();

    foreach ($language_services as $id) {
      $this->assertSame($jsonapi, $container->hasDefinition($id), $id);
    }
    $this->assertSame($page_cache, $container->hasDefinition('http_middleware.page_cache'));
    if ($page_cache) {
      $expected_policy = $jsonapi ? 'markaspot_nuxt.jsonapi_page_cache_request_policy' : 'page_cache_request_policy';
      $this->assertSame($expected_policy, (string) $container->getDefinition('http_middleware.page_cache')->getArgument(1));
    }
    if ($jsonapi) {
      $this->assertSame('markaspot_nuxt.jsonapi_entity_repository', (string) $container->getDefinition('jsonapi.entity_access_checker')->getArgument(3));
      $this->assertSame('markaspot_nuxt.jsonapi_entity_repository', (string) $container->getDefinition('paramconverter.jsonapi.entity_uuid')->getArgument(1));
    }
    else {
      $this->assertFalse($container->hasParameter('jsonapi.base_path'));
    }
  }

  /**
   * JSON:API and Internal Page Cache are independently optional.
   */
  public static function moduleCombinations(): array {
    return [[FALSE, FALSE], [FALSE, TRUE], [TRUE, FALSE], [TRUE, TRUE]];
  }

}
