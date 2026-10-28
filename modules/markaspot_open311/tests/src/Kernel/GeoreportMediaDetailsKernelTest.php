<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Kernel;

use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\markaspot_open311\Service\RemoteImageFetcher;
use Drupal\markaspot_open311\Service\StatusClassifier;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the display derivatives GeoReport adds to extended media details.
 */
#[Group('markaspot_open311')]
#[RunTestsInSeparateProcesses]
final class GeoreportMediaDetailsKernelTest extends KernelTestBase {

  use ImageFieldCreationTrait;
  use UserCreationTrait;

  private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'file', 'image', 'node',
    'media', 'inline_entity_form', 'markaspot_media',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'file', 'node', 'media'] as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'file', 'image']);
    NodeType::create(['type' => 'service_request', 'name' => 'Request'])->save();
    MediaType::create([
      'id' => 'request_image',
      'label' => 'Request image',
      'source' => 'image',
      'source_configuration' => ['source_field' => 'field_media_image'],
    ])->save();
    $this->createImageField('field_media_image', 'media', 'request_image');
    FieldStorageConfig::create([
      'field_name' => 'field_request_media',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
      'cardinality' => 3,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_request_media',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Images',
    ])->save();

    $this->setUpCurrentUser([], ['access content', 'view media']);
  }

  /**
   * Each photo carries a preview and a thumb next to the original.
   */
  public function testMediaDetailsCarryDisplayDerivatives(): void {
    $node = $this->createRequestWithPhoto('public://photo.png');

    $details = $this->mediaDetails($node);

    $this->assertCount(1, $details);
    $this->assertStringEndsWith('/photo.png', $details[0]['url']);
    // Stock styles may convert, which appends the derivative extension.
    $this->assertMatchesRegularExpression('#/styles/wide/public/photo\.png(\.[a-z]+)?\?itok=#', $details[0]['preview']);
    $this->assertMatchesRegularExpression('#/styles/thumbnail/public/photo\.png(\.[a-z]+)?\?itok=#', $details[0]['thumb']);
    $this->assertArrayNotHasKey('width', $details[0]);
  }

  /**
   * The profile styles win over the stock fallbacks once they exist.
   */
  public function testProfileStylesWinOverFallbacks(): void {
    foreach (['markaspot_request_preview', 'markaspot_request_thumb'] as $style_id) {
      $style = ImageStyle::create(['name' => $style_id, 'label' => $style_id]);
      $style->addImageEffect([
        'id' => 'image_scale',
        'weight' => 0,
        'data' => ['width' => 160, 'height' => 160, 'upscale' => FALSE],
      ]);
      $style->save();
    }
    $node = $this->createRequestWithPhoto('public://profile.png');

    $details = $this->mediaDetails($node);

    $this->assertStringContainsString('/styles/markaspot_request_preview/public/profile.png?itok=', $details[0]['preview']);
    $this->assertStringContainsString('/styles/markaspot_request_thumb/public/profile.png?itok=', $details[0]['thumb']);
  }

  /**
   * Without any usable style the details keep the original only.
   */
  public function testMediaDetailsOmitDerivativesWithoutStyles(): void {
    ImageStyle::load('wide')->delete();
    ImageStyle::load('thumbnail')->delete();
    $node = $this->createRequestWithPhoto('public://plain.png');

    $details = $this->mediaDetails($node);

    $this->assertArrayNotHasKey('preview', $details[0]);
    $this->assertArrayNotHasKey('thumb', $details[0]);
    $this->assertStringEndsWith('/plain.png', $details[0]['url']);
  }

  /**
   * Creates a service request referencing one published photo.
   */
  private function createRequestWithPhoto(string $uri): Node {
    file_put_contents($uri, base64_decode(self::PNG));
    $file = File::create(['uri' => $uri, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'request_image',
      'name' => 'Photo',
      'status' => 1,
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'Photo'],
    ]);
    $media->save();
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Request',
      'field_request_media' => [$media->id()],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Invokes the private media details builder.
   */
  private function mediaDetails(Node $node): array {
    $processor = $this->processor();
    $method = new \ReflectionMethod($processor, 'getMediaDetails');
    return $method->invoke($processor, $node);
  }

  /**
   * Builds the processor from the kernel container.
   */
  private function processor(): GeoreportProcessorService {
    $container = $this->container;
    return new GeoreportProcessorService(
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('datetime.time'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
      $container->get('module_handler'),
      $container->get('entity_field.manager'),
      $container->get('stream_wrapper_manager'),
      $container->get('token'),
      $container->get('language_manager'),
      $container->get('database'),
      $container->get('file_system'),
      $container->get('http_client'),
      $container->get('messenger'),
      $container->get('account_switcher'),
      new StatusClassifier($container->get('config.factory'), $container->get('entity_type.manager'), $container->get('cache.default')),
      NULL,
      NULL,
      new KeyValueMemoryFactory(),
      new RemoteImageFetcher(new Client()),
    );
  }

}
