<?php

namespace Drupal\markaspot_media\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Ensures S3-backed private uploads have a bucket to write to.
 */
class RequestImageUploadStorageGuard {

  protected const STATE_PREFIX = 'markaspot_media.s3_bucket_ready.';

  protected const READY_TTL = 300;

  /**
   * Constructs the request image upload storage guard.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected mixed $s3fs = NULL,
  ) {}

  /**
   * Ensures the configured private S3 bucket exists before upload handling.
   */
  public function ensureReady(): void {
    if (!$this->usesS3BackedPrivateStorage()) {
      return;
    }

    $config = $this->getS3fsConfig();
    $bucket = (string) ($config['bucket'] ?? '');
    if ($bucket === '' || !$this->isS3fsAvailable()) {
      return;
    }

    $stateKey = $this->stateKey($config);
    if ($this->isRecentlyReady($stateKey)) {
      return;
    }

    $lockName = 'markaspot_media.s3_bucket_guard.' . hash('sha256', $stateKey);
    $hasLock = $this->lock->acquire($lockName, 10.0);
    if (!$hasLock) {
      $this->lock->wait($lockName, 2);
      if ($this->isRecentlyReady($stateKey)) {
        return;
      }
    }

    try {
      $this->ensureBucketExists($config, $bucket);
      $this->state->set($stateKey, $this->time->getRequestTime());
    }
    finally {
      if ($hasLock) {
        $this->lock->release($lockName);
      }
    }
  }

  /**
   * Checks whether private:// is backed by S3FS.
   */
  protected function usesS3BackedPrivateStorage(): bool {
    return (bool) Settings::get('s3fs.use_s3_for_private', FALSE);
  }

  /**
   * Returns the runtime-overridden s3fs config needed to build the client.
   *
   * @return array<string, mixed>
   *   S3FS config values.
   */
  protected function getS3fsConfig(): array {
    $config = $this->configFactory->get('s3fs.settings');
    $keys = [
      'bucket',
      'region',
      'use_customhost',
      'hostname',
      'use_https',
      'use_path_style_endpoint',
      'root_folder',
      'public_folder',
      'private_folder',
      'credentials_file',
      'use_credentials_cache',
      'credentials_cache_dir',
      'keymodule',
      'disable_cert_verify',
      'disable_shared_config_files',
    ];

    $values = [];
    foreach ($keys as $key) {
      $values[$key] = $config->get($key);
    }

    return $values;
  }

  /**
   * Ensures the bucket exists, creating it when the credentials permit it.
   *
   * @param array<string, mixed> $config
   *   S3FS config values.
   * @param string $bucket
   *   Bucket name.
   */
  protected function ensureBucketExists(array $config, string $bucket): void {
    $client = $this->getS3Client($config);

    if ($this->bucketExists($client, $bucket)) {
      return;
    }

    $args = ['Bucket' => $bucket];
    $region = (string) ($config['region'] ?? '');
    if ($region !== '' && $region !== 'us-east-1') {
      $args['CreateBucketConfiguration'] = ['LocationConstraint' => $region];
    }

    try {
      $this->callClient($client, 'createBucket', [$args]);
      if ($this->clientHasMethod($client, 'waitUntil')) {
        $this->callClient($client, 'waitUntil', ['BucketExists', ['Bucket' => $bucket]]);
      }
      $this->logger->notice('Created missing S3 bucket @bucket for request-image uploads.', [
        '@bucket' => $bucket,
      ]);
    }
    catch (\Throwable $e) {
      if ($this->isAlreadyOwnedBucketException($e) && $this->bucketExists($client, $bucket)) {
        return;
      }

      $this->logger->error('Could not prepare S3 bucket @bucket for request-image upload: @message', [
        '@bucket' => $bucket,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Checks if the S3 bucket already exists.
   */
  protected function bucketExists(mixed $client, string $bucket): bool {
    if ($this->clientHasMethod($client, 'doesBucketExistV2')) {
      return (bool) $this->callClient($client, 'doesBucketExistV2', [$bucket, TRUE]);
    }

    if ($this->clientHasMethod($client, 'doesBucketExist')) {
      return (bool) $this->callClient($client, 'doesBucketExist', [$bucket]);
    }

    if ($this->clientHasMethod($client, 'headBucket')) {
      try {
        $this->callClient($client, 'headBucket', [['Bucket' => $bucket]]);
        return TRUE;
      }
      catch (\Throwable) {
        return FALSE;
      }
    }

    throw new \RuntimeException('S3 client does not support bucket existence checks.');
  }

  /**
   * Returns the S3 client from the optional s3fs module.
   *
   * @param array<string, mixed> $config
   *   S3FS config values.
   */
  protected function getS3Client(array $config): mixed {
    return $this->callClient($this->s3fs, 'getAmazonS3Client', [$config]);
  }

  /**
   * Checks if s3fs is available in the current container.
   */
  protected function isS3fsAvailable(): bool {
    return is_object($this->s3fs);
  }

  /**
   * Checks whether the readiness state is recent enough to skip S3 calls.
   */
  protected function isRecentlyReady(string $stateKey): bool {
    $readyAt = (int) $this->state->get($stateKey, 0);
    return $readyAt > 0 && ($this->time->getRequestTime() - $readyAt) < static::READY_TTL;
  }

  /**
   * Builds a readiness-state key scoped to the exact bucket endpoint.
   *
   * @param array<string, mixed> $config
   *   S3FS config values.
   */
  protected function stateKey(array $config): string {
    $parts = [
      (string) ($config['hostname'] ?? ''),
      (string) ($config['bucket'] ?? ''),
      (string) ($config['root_folder'] ?? ''),
      (string) ($config['private_folder'] ?? ''),
    ];
    return static::STATE_PREFIX . hash('sha256', implode('|', $parts));
  }

  /**
   * Calls a dynamic method on the optional S3 client/service.
   *
   * @param mixed $client
   *   The S3 client or s3fs service.
   * @param string $method
   *   Method name.
   * @param array<int, mixed> $args
   *   Method arguments.
   */
  protected function callClient(mixed $client, string $method, array $args = []): mixed {
    if (!$this->clientHasMethod($client, $method)) {
      throw new \RuntimeException(sprintf('S3 client does not support %s().', $method));
    }

    return call_user_func_array([$client, $method], $args);
  }

  /**
   * Checks whether a dynamic client method exists.
   */
  protected function clientHasMethod(mixed $client, string $method): bool {
    return is_object($client) && (method_exists($client, $method) || is_callable([$client, $method]));
  }

  /**
   * Checks if a createBucket failure can be treated as idempotent success.
   */
  protected function isAlreadyOwnedBucketException(\Throwable $e): bool {
    $code = method_exists($e, 'getAwsErrorCode')
      ? (string) $e->getAwsErrorCode()
      : (string) $e->getCode();

    return in_array($code, ['BucketAlreadyOwnedByYou'], TRUE);
  }

}
