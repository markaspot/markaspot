<?php

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\NullStorageExpirable;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;
use Drupal\media\MediaInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Tests media analysis access binding.
 */
#[CoversClass(MediaAnalysisAccessGuard::class)]
#[Group('markaspot_vision')]
class MediaAnalysisAccessGuardTest extends UnitTestCase {

  /**
   * Creates a mocked request_image media entity.
   */
  protected function createMedia(bool $updateAccess = FALSE, int $ownerId = 0): MediaInterface {
    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('request_image');
    $media->method('uuid')->willReturn('media-uuid');
    $media->method('getOwnerId')->willReturn($ownerId);
    $media->method('access')
      ->with('update')
      ->willReturn($updateAccess);
    return $media;
  }

  /**
   * Creates a request with a CSRF token header.
   */
  protected function createRequest(string $token): Request {
    $request = Request::create('/api/vision/analyze', 'POST');
    $request->headers->set('X-CSRF-Token', $token);
    return $request;
  }

  /**
   * Creates a guard with the given store.
   */
  protected function createGuard(
    KeyValueStoreExpirableInterface $store,
    bool $isAnonymous = TRUE,
    int $uid = 0,
  ): MediaAnalysisAccessGuard {
    new Settings(['hash_salt' => 'markaspot-vision-test-salt']);

    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAnonymous')->willReturn($isAnonymous);
    $account->method('id')->willReturn($uid);

    $factory = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $factory->method('get')
      ->with(MediaAnalysisAccessGuard::STORE)
      ->willReturn($store);

    return new MediaAnalysisAccessGuard($account, $factory, new RequestStack());
  }

  /**
   * Creates an in-memory expirable key/value store for unit tests.
   */
  protected function createMemoryStore(): KeyValueStoreExpirableInterface {
    return new class(MediaAnalysisAccessGuard::STORE) extends NullStorageExpirable {

      /**
       * Stored values.
       *
       * @var array<string, mixed>
       */
      protected array $values = [];

      /**
       * {@inheritdoc}
       */
      public function get($key, $default = NULL) {
        return $this->values[$key] ?? $default;
      }

      /**
       * {@inheritdoc}
       */
      public function setWithExpire($key, $value, $expire) {
        $this->values[$key] = $value;
      }

    };
  }

  /**
   */
  public function testApiKeyUpdateAccessWithoutUploadFingerprintIsDenied(): void {
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')->willReturn(NULL);

    $guard = $this->createGuard($store, FALSE, 42);
    $request = Request::create('/api/vision/analyze', 'POST');
    $request->headers->set('apikey', 'shared-api-key');

    $this->assertFalse($guard->canAnalyze($this->createMedia(TRUE, 42), $request));
  }

  /**
   */
  public function testMatchingUploadFingerprintAllowsAnalysis(): void {
    $store = $this->createMemoryStore();
    $guard = $this->createGuard($store);
    $media = $this->createMedia(FALSE);
    $request = $this->createRequest('csrf-token');

    $guard->recordUpload($media, $request);
    $this->assertTrue($guard->canAnalyze($media, $request));
  }

  /**
   */
  public function testMismatchedUploadFingerprintDeniesAnalysis(): void {
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')
      ->with('media-uuid')
      ->willReturn('csrf:not-the-current-token');

    $guard = $this->createGuard($store);
    $this->assertFalse($guard->canAnalyze($this->createMedia(FALSE), $this->createRequest('csrf-token')));
  }

  /**
   */
  public function testAuthenticatedSessionUpdateAccessAllowsAnalysis(): void {
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')->willReturn(NULL);

    $session = $this->createMock(SessionInterface::class);
    $session->method('get')->with('uid', 0)->willReturn(42);

    $request = Request::create('/api/vision/analyze', 'POST');
    $request->setSession($session);

    $guard = $this->createGuard($store, FALSE, 42);
    $this->assertTrue($guard->canAnalyze($this->createMedia(TRUE, 42), $request));
  }

}
