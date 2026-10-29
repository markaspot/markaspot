<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Drupal\Core\Cache\NullBackend;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\jsonapi_extras\Entity\JsonapiResourceConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that raw AI analysis stays out of request image JSON:API responses.
 *
 * @group markaspot_nuxt
 */
#[RunTestsInSeparateProcesses]
final class RequestImageAiJsonApiKernelTest extends KernelTestBase {

  /**
   * Analysis fields that must never be exposed by the media resource.
   */
  private const ANALYSIS_FIELDS = [
    'field_ai_metadata',
    'field_ai_privacy_issues',
    'field_ai_hazard_issues',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'image', 'media',
    'serialization', 'jsonapi', 'jsonapi_extras',
  ];

  /**
   * The installed optional resource configuration.
   */
  private JsonapiResourceConfig $resource;

  /**
   * A published request image containing analysis and public flags.
   */
  private Media $media;

  /**
   * Keeps Extras from serializing services from the isolated test container.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('test.jsonapi_resource_cache', NullBackend::class)
      ->setArguments(['jsonapi_resource_types']);
    $container->getDefinition('jsonapi.resource_type.repository')
      ->replaceArgument(3, new Reference('test.jsonapi_resource_cache'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_nuxt.install';

    foreach (['user', 'file', 'media'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'user', 'field', 'file', 'image', 'media', 'jsonapi', 'jsonapi_extras']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    Role::load('anonymous')->grantPermission('view media')->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());

    MediaType::create([
      'id' => 'request_image',
      'label' => 'Request image',
      'source' => 'image',
      'source_configuration' => ['source_field' => 'field_media_image'],
    ])->save();
    $this->createField('field_media_image', 'image');
    foreach (self::ANALYSIS_FIELDS as $field_name) {
      $this->createField($field_name, 'string_long');
    }
    foreach (['field_ai_privacy_flag', 'field_ai_hazard_flag'] as $field_name) {
      $this->createField($field_name, 'boolean');
    }
    $this->media = Media::create([
      'bundle' => 'request_image',
      'name' => 'Published request image',
      'uid' => 0,
      'status' => 1,
      'field_ai_metadata' => '{"privacy_issues":["Private model finding"]}',
      'field_ai_privacy_issues' => 'Private model finding',
      'field_ai_hazard_issues' => 'Hazard model finding',
      'field_ai_privacy_flag' => TRUE,
      'field_ai_hazard_flag' => FALSE,
    ]);
    $this->media->save();

    $source = new FileStorage(dirname(__DIR__, 3) . '/config/optional');
    $data = $source->read('jsonapi_extras.jsonapi_resource_config.media--request_image');
    $this->assertIsArray($data);
    $this->resource = JsonapiResourceConfig::create($data);
    $this->resource->save();
  }

  /**
   * Fresh installs hide all three fields in config and anonymous responses.
   */
  public function testShippedConfigHidesAnalysis(): void {
    foreach (self::ANALYSIS_FIELDS as $field_name) {
      $this->assertTrue($this->config('jsonapi_extras.jsonapi_resource_config.media--request_image')->get("resourceFields.$field_name.disabled"), $field_name);
    }
    $this->assertAnalysisIsHidden();
  }

  /**
   * Existing exposure is removed while aliases and other fields are preserved.
   */
  public function testUpdateHidesAnalysisAndIsIdempotent(): void {
    $fields = $this->resource->get('resourceFields');
    foreach (self::ANALYSIS_FIELDS as $field_name) {
      $fields[$field_name]['disabled'] = FALSE;
    }
    $fields['field_ai_metadata']['publicName'] = 'raw_analysis';
    $fields['name']['publicName'] = 'caption';
    $this->resource->set('resourceFields', $fields)->save();
    $before = $this->resource->toArray();

    // Prove the request really serializes nonempty analysis before the update.
    $attributes = $this->getAttributes();
    $this->assertSame($this->media->get('field_ai_metadata')->value, $attributes['raw_analysis']);
    foreach (['field_ai_privacy_issues', 'field_ai_hazard_issues'] as $field_name) {
      $this->assertSame($this->media->get($field_name)->value, $attributes[$field_name]);
    }

    markaspot_nuxt_update_11919();
    $this->resource = JsonapiResourceConfig::load('media--request_image');
    $expected = $before;
    foreach (self::ANALYSIS_FIELDS as $field_name) {
      $expected['resourceFields'][$field_name]['disabled'] = TRUE;
    }
    $this->assertSame($expected, $this->resource->toArray());
    $this->assertAnalysisIsHidden('caption');

    markaspot_nuxt_update_11919();
    $this->assertSame($expected, JsonapiResourceConfig::load('media--request_image')->toArray());
  }

  /**
   * Fields missing from a tenant's resource override are explicitly disabled.
   */
  public function testUpdateAddsMissingFieldEntries(): void {
    $fields = $this->resource->get('resourceFields');
    foreach (self::ANALYSIS_FIELDS as $field_name) {
      unset($fields[$field_name]);
    }
    $this->resource->set('resourceFields', $fields)->save();

    markaspot_nuxt_update_11919();
    $updated = JsonapiResourceConfig::load('media--request_image')->get('resourceFields');
    foreach (self::ANALYSIS_FIELDS as $field_name) {
      $this->assertTrue($updated[$field_name]['disabled']);
      $this->assertSame($field_name, $updated[$field_name]['fieldName']);
      unset($updated[$field_name]);
    }
    $this->assertSame($fields, $updated);
    $this->assertAnalysisIsHidden();
  }

  /**
   * An absent optional config is not created by the update.
   */
  public function testUpdateWithoutResourceDoesNothing(): void {
    $this->resource->delete();
    $before = $this->container->get('config.storage')->listAll();

    markaspot_nuxt_update_11919();
    markaspot_nuxt_update_11919();

    $this->assertNull(JsonapiResourceConfig::load('media--request_image'));
    $this->assertSame($before, $this->container->get('config.storage')->listAll());
  }

  /**
   * Creates one field on the request image bundle.
   */
  private function createField(string $name, string $type): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'media',
      'type' => $type,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'media',
      'bundle' => 'request_image',
    ])->save();
  }

  /**
   * Fetches published media through JSON:API as an anonymous user.
   */
  private function getAttributes(): array {
    $this->container->get('router.builder')->rebuild();
    $this->assertTrue($this->container->get('current_user')->isAnonymous());
    $request = Request::create('/jsonapi/media/request_image/' . $this->media->uuid());
    $request->headers->set('Accept', 'application/vnd.api+json');
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $data = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($this->media->uuid(), $data['data']['id']);
    return $data['data']['attributes'];
  }

  /**
   * Analysis disappears while the media name and boolean flags remain readable.
   */
  private function assertAnalysisIsHidden(string $name = 'name'): void {
    $attributes = $this->getAttributes();
    foreach ([...self::ANALYSIS_FIELDS, 'raw_analysis'] as $field_name) {
      $this->assertArrayNotHasKey($field_name, $attributes);
    }
    $this->assertSame('Published request image', $attributes[$name]);
    $this->assertTrue($attributes['field_ai_privacy_flag']);
    $this->assertFalse($attributes['field_ai_hazard_flag']);
  }

}
