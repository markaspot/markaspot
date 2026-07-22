<?php

namespace Drupal\markaspot_vision\Service;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\media\MediaInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Binds anonymous media analysis requests to the original upload context.
 */
class MediaAnalysisAccessGuard {

  /**
   * Expirable key/value collection for upload analysis fingerprints.
   */
  public const STORE = 'markaspot_vision_media_analysis';

  /**
   * Time in seconds an uploaded media may be analyzed by its upload session.
   */
  public const TTL = 21600;

  /**
   * Constructs the media analysis access guard.
   */
  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected KeyValueExpirableFactoryInterface $keyValueExpirable,
    protected RequestStack $requestStack,
  ) {
  }

  /**
   * Records the upload context for a request_image media entity.
   */
  public function recordUpload(MediaInterface $media, ?Request $request = NULL): void {
    if ($media->bundle() !== 'request_image') {
      return;
    }

    $fingerprint = $this->getRequestFingerprint($request ?? $this->requestStack->getCurrentRequest());
    if ($fingerprint === NULL) {
      return;
    }

    $this->keyValueExpirable
      ->get(self::STORE)
      ->setWithExpire($media->uuid(), $fingerprint, self::TTL);
  }

  /**
   * Checks whether the current request may analyze the media entity.
   */
  public function canAnalyze(MediaInterface $media, Request $request): bool {
    if ($media->bundle() !== 'request_image') {
      return FALSE;
    }

    $fingerprint = $this->getRequestFingerprint($request);
    if ($fingerprint !== NULL) {
      $stored = $this->keyValueExpirable
        ->get(self::STORE)
        ->get($media->uuid());

      if (is_string($stored) && hash_equals($stored, $fingerprint)) {
        return TRUE;
      }
    }

    // Shared API-key users can own many uploads, so owner/update bypasses are
    // only trusted for real cookie-backed user sessions.
    if (!$this->hasAuthenticatedSession($request)) {
      return FALSE;
    }

    if ($media->access('update')) {
      return TRUE;
    }

    if (!$this->currentUser->isAnonymous() && (int) $media->getOwnerId() === (int) $this->currentUser->id()) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Returns the private upload-session identifier used for rate limiting.
   */
  public function getRateLimitIdentifier(Request $request): ?string {
    return $this->getRequestFingerprint($request);
  }

  /**
   * Determines whether the request carries a real authenticated user session.
   */
  protected function hasAuthenticatedSession(Request $request): bool {
    if ($this->currentUser->isAnonymous() || !$request->hasSession()) {
      return FALSE;
    }

    try {
      return (int) $request->getSession()->get('uid', 0) === (int) $this->currentUser->id();
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Builds a stable, non-reversible request fingerprint.
   */
  protected function getRequestFingerprint(?Request $request): ?string {
    if (!$request) {
      return NULL;
    }

    $salt = Settings::getHashSalt();

    $csrf = trim((string) $request->headers->get('X-CSRF-Token', ''));
    if ($csrf !== '') {
      return 'csrf:' . hash_hmac('sha256', $csrf, $salt);
    }

    if ($request->hasSession()) {
      try {
        $session_id = $request->getSession()->getId();
        if ($session_id !== '') {
          return 'sid:' . hash_hmac('sha256', $session_id, $salt);
        }
      }
      catch (\Throwable) {
        // Stateless requests can expose no usable session.
      }
    }

    // Deliberately do not bind to API keys. They can be shared by server-side
    // integrations and are not a per-upload proof.
    return NULL;
  }

}
