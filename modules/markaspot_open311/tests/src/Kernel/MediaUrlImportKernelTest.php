<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Kernel;

use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_open311\Exception\GeoreportException;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\markaspot_open311\Service\RemoteImageException;
use Drupal\markaspot_open311\Service\RemoteImageFetcher;
use Drupal\markaspot_open311\Service\StatusClassifier;
use Drupal\markaspot_vision\Service\MediaIntakeScreening;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests how Open311 media_url downloads become request_image media.
 */
#[Group('markaspot_open311')]
#[RunTestsInSeparateProcesses]
final class MediaUrlImportKernelTest extends KernelTestBase {

  use ImageFieldCreationTrait;
  use UserCreationTrait;

  private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
  private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'file', 'image', 'node',
    'media', 'inline_entity_form', 'markaspot_media',
  ];

  /**
   * What the stub fetcher returns or throws, in call order.
   *
   * @var array<int, array|\Throwable>
   */
  private array $fetchResults = [];

  /**
   * Imported media handed to the asynchronous screening queue.
   *
   * @var array<int|string>
   */
  private array $queuedMedia = [];

  /**
   * Jobs actually scheduled after the report succeeds.
   *
   * @var array<int|string>
   */
  private array $scheduledMedia = [];

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
    $this->createImageField('field_media_image', 'media', 'request_image', [], [
      'file_extensions' => 'png gif jpg jpeg',
      'max_filesize' => '10 MB',
    ]);
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
   * Builds the processor with a fetcher that replays $fetchResults.
   */
  private function processor(): GeoreportProcessorService {
    $results = &$this->fetchResults;
    $fetcher = new class(new Client(), $results) extends RemoteImageFetcher {

      public function __construct(Client $client, private array &$results) {
        parent::__construct($client);
      }

      /**
       * {@inheritdoc}
       */
      public function fetch(string $url, int $maxBytes, array $allowedExtensions): array {
        $next = array_shift($this->results);
        if ($next instanceof \Throwable) {
          throw $next;
        }
        return $next;
      }

    };

    $container = $this->container;
    $screening = $this->createMock(MediaIntakeScreening::class);
    $screening->method('register')->willReturnCallback(function ($media): void {
      $this->assertFalse($media->isPublished());
      $this->queuedMedia[] = $media->id();
    });
    $screening->method('enqueue')->willReturnCallback(function ($media): void {
      $this->scheduledMedia[] = $media->id();
    });
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
      $fetcher,
      $screening,
    );
  }

  /**
   * Invokes the private media_url handler.
   */
  private function import(array $requestData): array {
    $processor = $this->processor();
    $method = new \ReflectionMethod($processor, 'handleMediaUrls');
    return $method->invoke($processor, $requestData);
  }

  /**
   * A PNG behind any URL is stored under a generated name, unpublished.
   */
  public function testImageIsStoredUnderGeneratedName(): void {
    $this->fetchResults = [
      ['data' => base64_decode(self::PNG), 'extension' => 'png', 'mime' => 'image/png'],
    ];

    $processor = $this->processor();
    $handler = new \ReflectionMethod($processor, 'handleMediaUrls');
    $references = $handler->invoke($processor, ['media_url' => 'https://images.example.com/../../shell.php']);

    $this->assertCount(1, $references);
    $media = Media::load($references[0]['target_id']);
    $this->assertSame('request_image', $media->bundle());
    $this->assertFalse($media->isPublished());
    $file = File::load($media->get('field_media_image')->target_id);
    $this->assertMatchesRegularExpression('#^public://([^/]+/)*open311-[0-9a-f]{16}\.png$#', $file->getFileUri());
    $this->assertSame('image/png', $file->getMimeType());
    $this->assertTrue($file->isPermanent());
    $this->assertSame([$media->id()], $this->queuedMedia);
    $this->assertSame([], $this->scheduledMedia, 'No queue job runs before successful report creation.');
    $processor->commitImportedMedia();
    $this->assertSame([$media->id()], $this->scheduledMedia);
    // The intake path only stores and enqueues, with no synchronous blur.
    $this->assertSame(base64_decode(self::PNG), file_get_contents($file->getFileUri()));
  }

  /**
   * The field validators run before save; a refused image is dropped.
   */
  public function testFieldValidatorsDropTheImageBeforeSave(): void {
    $field = FieldConfig::loadByName('media', 'request_image', 'field_media_image');
    $field->setSetting('file_extensions', 'gif')->save();
    $this->fetchResults = [
      ['data' => base64_decode(self::PNG), 'extension' => 'png', 'mime' => 'image/png'],
    ];

    $this->assertSame([], $this->import(['media_url' => 'https://images.example.com/a.png']));
    $this->assertSame([], Media::loadMultiple());
    $this->assertSame([], File::loadMultiple());
  }

  /**
   * A refused image in a list keeps the others.
   */
  public function testRefusedImageKeepsTheOthers(): void {
    $field = FieldConfig::loadByName('media', 'request_image', 'field_media_image');
    $field->setSetting('file_extensions', 'png')->save();
    $this->fetchResults = [
      ['data' => base64_decode(self::PNG), 'extension' => 'png', 'mime' => 'image/png'],
      ['data' => base64_decode(self::GIF), 'extension' => 'gif', 'mime' => 'image/gif'],
    ];

    $references = $this->import(['media_url' => 'https://images.example.com/a.png,https://images.example.com/b.gif']);

    $this->assertCount(1, $references);
    $this->assertCount(1, Media::loadMultiple());
    $this->assertCount(1, File::loadMultiple());
  }

  /**
   * Callers can discard a successful import when the request fails later.
   */
  public function testDiscardRemovesImportedMedia(): void {
    $this->fetchResults = [
      ['data' => base64_decode(self::PNG), 'extension' => 'png', 'mime' => 'image/png'],
    ];
    $processor = $this->processor();
    $method = new \ReflectionMethod($processor, 'handleMediaUrls');
    $this->assertCount(1, $method->invoke($processor, ['media_url' => 'https://images.example.com/a.png']));
    $this->assertCount(1, Media::loadMultiple());

    $processor->discardImportedMedia();

    $this->assertSame([], Media::loadMultiple());
    $this->assertSame([], File::loadMultiple());
  }

  /**
   * An unreachable image host is skipped, as before; the rest is imported.
   */
  public function testUnreachableImageIsSkipped(): void {
    $this->fetchResults = [
      new RemoteImageException('transfer', 'The media file could not be downloaded.'),
      ['data' => base64_decode(self::PNG), 'extension' => 'png', 'mime' => 'image/png'],
    ];

    $references = $this->import(['media_url' => 'https://down.example.com/a.png,https://images.example.com/b.png']);

    $this->assertCount(1, $references);
    $this->assertCount(1, Media::loadMultiple());
  }

  /**
   * A fetcher rejection becomes a 400 without a stored file.
   */
  public function testRejectedUrlIsA400(): void {
    $this->fetchResults = [
      new RemoteImageException('blocked_address', 'The media URL points to a non-public address.'),
    ];

    try {
      $this->import(['media_url' => 'http://169.254.169.254/latest/meta-data']);
      $this->fail('A blocked URL must be rejected.');
    }
    catch (GeoreportException $e) {
      $this->assertSame(400, $e->getCode());
      $this->assertStringContainsString('non-public address', $e->getMessage());
    }
    $this->assertSame([], File::loadMultiple());
  }

  /**
   * Non-string input and more URLs than the field holds are rejected.
   */
  public function testMalformedMediaUrlInputIsRejected(): void {
    $tooMany = implode(',', array_map(fn (int $i) => "https://a.example/$i.png", range(1, 4)));
    foreach ([['media_url' => ['https://a.example/a.png']], ['media_url' => $tooMany]] as $input) {
      try {
        $this->import($input);
        $this->fail('Malformed media_url input must be rejected.');
      }
      catch (GeoreportException $e) {
        $this->assertSame(400, $e->getCode());
      }
    }
    $this->assertSame([], $this->import(['media_url' => ' , ']));
  }

}
