<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\PageCache\ChainRequestPolicy;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\jsonapi\EventSubscriber\ResourceObjectNormalizationCacher;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_nuxt\MarkaspotNuxtServiceProvider;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

require_once dirname(__DIR__, 3) . '/src/Language/JsonApiReadLanguage.php';
require_once dirname(__DIR__, 3) . '/src/Language/JsonApiEntityRepository.php';
require_once dirname(__DIR__, 3) . '/src/Language/JsonApiPageCacheRequestPolicy.php';
require_once dirname(__DIR__, 3) . '/src/MarkaspotNuxtServiceProvider.php';
require_once dirname(__DIR__, 3) . '/src/JsonApi/CachedCountEntityResource.php';

/**
 * Exercises the real JSON:API HTTP pipeline with URL-only site negotiation.
 *
 * @group markaspot_nuxt
 */
#[RunTestsInSeparateProcesses]
final class JsonApiReadLanguageKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'taxonomy', 'file',
    'serialization', 'jsonapi', 'language', 'content_translation',
    'page_cache', 'dynamic_page_cache', 'basic_auth',
  ];

  /**
   * The translated page.
   */
  private Node $page;

  /**
   * Registers the shipped API services without unrelated profile modules.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->setParameter('jsonapi.base_path', '/api/jsonapi');
    $container->getDefinition('jsonapi.normalization_cacher')->setClass(JsonApiTestNormalizationCacher::class);
    // Kernel tests run in CLI. Remove only that artificial cache exclusion,
    // preserving the production policy chain and its registered API policy.
    foreach (['page_cache_request_policy', 'dynamic_page_cache_request_policy'] as $id) {
      $container->getDefinition($id)->setClass(JsonApiTestBrowserRequestPolicy::class)->setArguments([]);
    }
    $services = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/markaspot_nuxt.services.yml'))['services'];
    $language_services = [
      'markaspot_nuxt.jsonapi_read_language',
      'markaspot_nuxt.jsonapi_entity_repository',
      'markaspot_nuxt.jsonapi_page_cache_request_policy',
    ];
    foreach ($language_services as $id) {
      $service = $services[$id];
      $arguments = array_map(static fn(string $value) => str_starts_with($value, '@') ? new Reference(substr($value, 1)) : $value, $service['arguments']);
      $definition = $container->register($id, $service['class'])->setArguments($arguments);
      foreach ($service['tags'] ?? [] as $tag) {
        $definition->addTag($tag['name']);
      }
    }
    (new MarkaspotNuxtServiceProvider())->alter($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'taxonomy_term', 'file'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'language', 'jsonapi']);
    $this->config('system.site')->set('default_langcode', 'en')->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', ['language-url' => 0])
      ->set('negotiation.language_content.enabled', ['language-interface' => 0])
      ->save();
    $this->config('language.negotiation')->set('url.prefixes', ['en' => '', 'fr' => 'fr'])->save();
    // Adding the second language invalidates the container. Rebuild now so
    // real language request/path subscribers participate in the HTTP test.
    $this->container->get('kernel')->rebuildContainer();
    $this->config('system.performance')->set('cache.page.max_age', 300)->save();
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    Vocabulary::create(['vid' => 'categories', 'name' => 'Categories'])->save();
    $translations = $this->container->get('content_translation.manager');
    $translations->setEnabled('node', 'page', TRUE);
    $translations->setEnabled('taxonomy_term', 'categories', TRUE);
    FieldStorageConfig::create([
      'field_name' => 'field_category', 'entity_type' => 'node',
      'type' => 'entity_reference', 'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_category', 'entity_type' => 'node', 'bundle' => 'page',
      'translatable' => FALSE,
    ])->save();
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    Role::load('anonymous')->grantPermission('access content')->save();
    $term = Term::create(['vid' => 'categories', 'langcode' => 'fr', 'name' => 'Parcs']);
    $term->addTranslation('en', ['name' => 'Parks']);
    $term->save();
    $this->page = Node::create([
      'type' => 'page', 'langcode' => 'fr', 'title' => 'Bienvenue',
      'status' => 1, 'uid' => 0, 'field_category' => $term->id(),
    ]);
    $this->page->addTranslation('en', ['title' => 'Welcome', 'status' => 1]);
    $this->page->save();
    // The default published-content grant is sufficient; node_access_rebuild()
    // would send a status message and trip the cache kill switch in this CLI
    // request before the first simulated HTTP request even starts.
    $this->container->get('node.grant_storage')->writeDefault();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $this->container->get('router.builder')->rebuild();
    $listener = [$this->container->get('markaspot_nuxt.jsonapi_read_language'), 'onResponse'];
    $this->assertNotNull($this->container->get('event_dispatcher')->getListenerPriority('kernel.response', $listener));
  }

  /**
   * Collection, item, includes and both cache orders select real translations.
   */
  public function testHttpReadTranslationsAndFallback(): void {
    $before = $this->config('language.types')->getRawData();
    $collection = '/api/jsonapi/node/page?filter[id]=' . $this->page->uuid() . '&include=field_category';
    $item = '/api/jsonapi/node/page/' . $this->page->uuid() . '?include=field_category';
    foreach ([$collection, $item] as $path) {
      $headers = $path === $collection
        ? ['fr', 'en', 'fr', 'en', 'fr-CA', 'de', '']
        : ['en', 'fr', 'en', 'fr', 'fr-CA', 'de', ''];
      foreach ($headers as $index => $header) {
        [$title, $category] = in_array($header, ['fr', 'fr-CA'], TRUE)
          ? ['Bienvenue', 'Parcs'] : ['Welcome', 'Parks'];
        $response = $this->request($path, $header);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $document = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
        $data = isset($document['data']['id']) ? $document['data'] : $document['data'][0];
        $this->assertSame($title, $data['attributes']['title']);
        $this->assertSame($category, $document['included'][0]['attributes']['name']);
        $this->assertContains('Accept-Language', $response->getVary(), json_encode($response->headers->all()));
        $this->assertContains('Cookie', $response->getVary());
        $this->assertSame(300, $response->getMaxAge());
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        if ($index === 2 || $index === 3) {
          $this->assertSame('HIT', $response->headers->get('X-Drupal-Dynamic-Cache'), $path . ' ' . $header . ' #' . $index);
        }
        $this->assertSame($title === 'Bienvenue' ? 'fr' : 'en', $response->headers->get('Content-Language'));
      }
    }
    // A supported language with no entity translation uses core's fallback.
    $single = Node::create(['type' => 'page', 'langcode' => 'en', 'title' => 'English only', 'status' => 1, 'uid' => 0]);
    $single->save();
    $response = $this->request('/api/jsonapi/node/page/' . $single->uuid(), 'fr');
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $this->assertSame('English only', json_decode($response->getContent(), TRUE)['data']['attributes']['title']);
    $this->assertSame($before, $this->config('language.types')->getRawData());
  }

  /**
   * A translated read cannot expose an unpublished translation.
   */
  public function testTranslationAccess(): void {
    $this->page->getTranslation('fr')->setUnpublished()->save();
    $path = '/api/jsonapi/node/page/' . $this->page->uuid();
    $this->assertSame(200, $this->request($path, 'en')->getStatusCode());
    // Basic Auth turns anonymous denial into an authentication challenge.
    $this->assertSame(401, $this->request($path, 'fr')->getStatusCode());
    $collection = '/api/jsonapi/node/page?filter[id]=' . $this->page->uuid();
    $response = $this->request($collection, 'en');
    $this->assertSame('Welcome', json_decode($response->getContent(), TRUE)['data'][0]['attributes']['title']);
    $response = $this->request($collection, 'fr');
    $this->assertSame([], json_decode($response->getContent(), TRUE)['data']);
  }

  /**
   * Explicit URL prefixes and mapped language domains keep core precedence.
   */
  public function testExplicitUrlLanguagePrecedesHeader(): void {
    $response = $this->request('/fr/api/jsonapi/node/page/' . $this->page->uuid(), 'en');
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $this->assertSame('Bienvenue', json_decode($response->getContent(), TRUE)['data']['attributes']['title']);
    $this->assertSame('fr', $response->headers->get('Content-Language'));

    $this->config('language.negotiation')
      ->set('url.source', 'domain')
      ->set('url.domains', ['en' => 'en.example.test', 'fr' => 'fr.example.test'])
      ->save();
    $response = $this->request('https://fr.example.test/api/jsonapi/node/page/' . $this->page->uuid(), 'en');
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $this->assertSame('Bienvenue', json_decode($response->getContent(), TRUE)['data']['attributes']['title']);
    $this->assertSame('fr', $response->headers->get('Content-Language'));
  }

  /**
   * PATCH retains the configured content language despite a French header.
   */
  public function testPatchKeepsExistingLanguageSemantics(): void {
    Role::load('authenticated')->grantPermission('access content')->grantPermission('edit any page content')->save();
    $editor = User::create(['name' => 'editor', 'pass' => 'test-only-password', 'status' => 1]);
    $editor->save();
    $path = '/api/jsonapi/node/page/' . $this->page->uuid();
    $request = Request::create($path, 'PATCH', [], [], [], [
      'PHP_AUTH_USER' => 'editor', 'PHP_AUTH_PW' => 'test-only-password',
      'CONTENT_TYPE' => 'application/vnd.api+json',
      'HTTP_ACCEPT' => 'application/vnd.api+json',
      'HTTP_ACCEPT_LANGUAGE' => 'fr',
    ], json_encode(['data' => [
      'type' => 'node--page', 'id' => $this->page->uuid(),
      'attributes' => ['title' => 'Edited English'],
    ]], JSON_THROW_ON_ERROR));
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $page = $storage->load($this->page->id());
    $this->assertSame('Edited English', $page->getTranslation('en')->label());
    $this->assertSame('Bienvenue', $page->getTranslation('fr')->label());
  }

  /**
   * Runs an actual routed HTTP request against the isolated kernel database.
   */
  private function request(string $path, string $language): Response {
    $request = Request::create($path);
    $request->headers->set('Accept', 'application/vnd.api+json');
    if ($language !== '') {
      $request->headers->set('Accept-Language', $language);
    }
    // These services have per-request static state in production. Keep the
    // actual cache bins intact so the following request must find real hits.
    foreach (['variation_cache.dynamic_page_cache', 'variation_cache.jsonapi_normalizations'] as $id) {
      $this->container->get($id)->reset();
    }
    $this->container->get('entity.memory_cache')->deleteAll();
    $this->container->get('language_manager')->reset();
    // DrupalKernel::preHandle() keeps the request available through terminate.
    $stack = $this->container->get('request_stack');
    $stack->push($request);
    try {
      $kernel = $this->container->get('http_kernel');
      $response = $kernel->handle($request);
      $this->assertTrue((bool) $request->attributes->get('_is_jsonapi'), json_encode($request->attributes->keys()));
      $kernel->terminate($request, $response);
      return $response;
    }
    finally {
      $stack->pop();
    }
  }

}

/**
 * Allows cacheable HTTP methods in the isolated CLI kernel test.
 */
final class JsonApiTestBrowserRequestPolicy extends ChainRequestPolicy {

  /**
   * {@inheritdoc}
   */
  public function check(Request $request) {
    return $request->isMethodCacheable() ? (parent::check($request) ?? self::ALLOW) : self::DENY;
  }

}

/**
 * Emulates fresh-request normalization state without deleting cached results.
 */
final class JsonApiTestNormalizationCacher extends ResourceObjectNormalizationCacher {

  /**
   * {@inheritdoc}
   */
  public function onTerminate(TerminateEvent $event): void {
    parent::onTerminate($event);
    $this->toCache = [];
  }

}
