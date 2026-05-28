<?php

namespace Drupal\Tests\markaspot_media\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_media\Service\RequestImageUploadStorageGuard;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * Tests S3 bucket preparation for request-image uploads.
 *
 * @group markaspot_media
 * @coversDefaultClass \Drupal\markaspot_media\Service\RequestImageUploadStorageGuard
 */
class RequestImageUploadStorageGuardTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings(['s3fs.use_s3_for_private' => TRUE]);
  }

  /**
   * @covers ::ensureReady
   */
  public function testCreatesMissingBucketForPrivateRequestImages(): void {
    $client = new RecordingS3Client(FALSE);
    $state = new MemoryState();

    $guard = $this->createGuard($client, $state);
    $guard->ensureReady();

    $this->assertSame([['bonn-mobility', TRUE]], $client->existenceChecks);
    $this->assertSame([['Bucket' => 'bonn-mobility']], $client->createdBuckets);
    $this->assertSame([['BucketExists', ['Bucket' => 'bonn-mobility']]], $client->waits);
    $this->assertNotSame([], $state->values);
  }

  /**
   * @covers ::ensureReady
   */
  public function testCreatesBucketThroughAwsSdkMagicMethod(): void {
    $client = new MagicRecordingS3Client(FALSE);

    $guard = $this->createGuard($client, new MemoryState());
    $guard->ensureReady();

    $this->assertSame([['Bucket' => 'bonn-mobility']], $client->createdBuckets);
  }

  /**
   * @covers ::ensureReady
   */
  public function testSkipsWhenBucketReadinessWasRecentlyRecorded(): void {
    $client = new RecordingS3Client(FALSE);
    $state = new MemoryState();
    $state->values[$this->expectedStateKey()] = 980;

    $guard = $this->createGuard($client, $state, requestTime: 1000);
    $guard->ensureReady();

    $this->assertSame([], $client->createdBuckets);
  }

  /**
   * @covers ::ensureReady
   */
  public function testSkipsWhenBucketIsNotConfigured(): void {
    $client = new RecordingS3Client(FALSE);

    $guard = $this->createGuard($client, new MemoryState(), bucket: '');
    $guard->ensureReady();

    $this->assertSame([], $client->createdBuckets);
  }

  /**
   * @covers ::ensureReady
   */
  public function testSkipsWhenS3fsPrivateWrapperIsDisabled(): void {
    new Settings(['s3fs.use_s3_for_private' => FALSE]);
    $client = new RecordingS3Client(FALSE);

    $guard = $this->createGuard($client, new MemoryState());
    $guard->ensureReady();

    $this->assertSame([], $client->createdBuckets);
  }

  /**
   * Creates a testable guard.
   */
  private function createGuard(
    object $client,
    MemoryState $state,
    string $bucket = 'bonn-mobility',
    int $requestTime = 1000,
  ): RequestImageUploadStorageGuard {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($requestTime);

    return new class(
      $this->createConfigFactory($bucket),
      $state,
      $lock,
      $time,
      new NullLogger(),
      $client,
    ) extends RequestImageUploadStorageGuard {

      public function __construct(
        ConfigFactoryInterface $configFactory,
        StateInterface $state,
        LockBackendInterface $lock,
        TimeInterface $time,
        NullLogger $logger,
        protected object $client,
      ) {
        parent::__construct($configFactory, $state, $lock, $time, $logger, new \stdClass());
      }

      /**
       * {@inheritdoc}
       */
      protected function isS3fsAvailable(): bool {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      protected function getS3Client(array $config): mixed {
        return $this->client;
      }

    };
  }

  /**
   * Creates a config factory mock with the relevant active config.
   */
  private function createConfigFactory(string $bucket): ConfigFactoryInterface {
    $s3Config = $this->createConfig([
      'bucket' => $bucket,
      'region' => 'us-east-1',
      'use_customhost' => TRUE,
      'hostname' => 'http://minio:9000',
      'use_https' => FALSE,
      'use_path_style_endpoint' => TRUE,
      'root_folder' => '',
      'public_folder' => '',
      'private_folder' => '',
      'credentials_file' => '',
      'use_credentials_cache' => FALSE,
      'credentials_cache_dir' => '',
      'keymodule' => [],
      'disable_cert_verify' => FALSE,
      'disable_shared_config_files' => FALSE,
    ]);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['s3fs.settings', $s3Config],
    ]);

    return $factory;
  }

  /**
   * Creates a config mock.
   *
   * @param array<string, mixed> $values
   *   Config values keyed by config path.
   */
  private function createConfig(array $values): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? NULL);
    return $config;
  }

  /**
   * Calculates the expected readiness state key for the test config.
   */
  private function expectedStateKey(): string {
    return 'markaspot_media.s3_bucket_ready.' . hash('sha256', 'http://minio:9000|bonn-mobility||');
  }

}

/**
 * Recording S3 client test double.
 */
class RecordingS3Client {

  /**
   * Bucket existence checks.
   *
   * @var array<int, array{0: string, 1: bool}>
   */
  public array $existenceChecks = [];

  /**
   * Created buckets.
   *
   * @var array<int, array<string, mixed>>
   */
  public array $createdBuckets = [];

  /**
   * waitUntil calls.
   *
   * @var array<int, array{0: string, 1: array<string, mixed>}>
   */
  public array $waits = [];

  public function __construct(
    public bool $exists,
  ) {}

  /**
   * Simulates AWS SDK bucket existence check.
   */
  public function doesBucketExistV2(string $bucket, bool $accept403 = FALSE): bool {
    $this->existenceChecks[] = [$bucket, $accept403];
    return $this->exists;
  }

  /**
   * Simulates AWS SDK bucket creation.
   *
   * @param array<string, mixed> $args
   *   CreateBucket args.
   */
  public function createBucket(array $args): void {
    $this->createdBuckets[] = $args;
    $this->exists = TRUE;
  }

  /**
   * Simulates AWS SDK waitUntil.
   *
   * @param array<string, mixed> $args
   *   Waiter args.
   */
  public function waitUntil(string $waiter, array $args): void {
    $this->waits[] = [$waiter, $args];
  }

}

/**
 * Recording S3 client that exposes createBucket through __call like AWS SDK.
 */
class MagicRecordingS3Client {

  /**
   * Created buckets.
   *
   * @var array<int, array<string, mixed>>
   */
  public array $createdBuckets = [];

  /**
   * waitUntil calls.
   *
   * @var array<int, array{0: string, 1: array<string, mixed>}>
   */
  public array $waits = [];

  public function __construct(
    public bool $exists,
  ) {}

  /**
   * Simulates AWS SDK bucket existence check.
   */
  public function doesBucketExistV2(string $bucket, bool $accept403 = FALSE): bool {
    return $this->exists;
  }

  /**
   * Handles AWS SDK-style magic calls.
   *
   * @param array<int, mixed> $args
   *   Method arguments.
   */
  public function __call(string $name, array $args): mixed {
    if ($name === 'createBucket') {
      $this->createdBuckets[] = $args[0];
      $this->exists = TRUE;
      return NULL;
    }

    if ($name === 'waitUntil') {
      $this->waits[] = [$args[0], $args[1]];
      return NULL;
    }

    throw new \BadMethodCallException($name);
  }

}

/**
 * In-memory StateInterface test double.
 */
class MemoryState implements StateInterface {

  /**
   * Stored values.
   *
   * @var array<string, mixed>
   */
  public array $values = [];

  /**
   * {@inheritdoc}
   */
  public function get($key, $default = NULL) {
    return $this->values[$key] ?? $default;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(array $keys) {
    return array_intersect_key($this->values, array_flip($keys));
  }

  /**
   * {@inheritdoc}
   */
  public function set($key, $value): void {
    $this->values[$key] = $value;
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $data): void {
    foreach ($data as $key => $value) {
      $this->set($key, $value);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($key): void {
    unset($this->values[$key]);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $keys): void {
    foreach ($keys as $key) {
      $this->delete($key);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(): void {}

  /**
   * {@inheritdoc}
   */
  public function getValuesSetDuringRequest(string $key): ?array {
    return isset($this->values[$key])
      ? ['value' => $this->values[$key], 'original' => NULL]
      : NULL;
  }

}
