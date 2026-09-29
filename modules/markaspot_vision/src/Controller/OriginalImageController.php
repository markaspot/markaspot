<?php

namespace Drupal\markaspot_vision\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\markaspot_vision\Service\OriginalImageStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tells the staff dashboard which photos have an original they may open.
 */
class OriginalImageController extends ControllerBase {

  /**
   * Media looked up per request, at most.
   */
  protected const MAX_MEDIA = 10;

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected OriginalImageStore $originalImageStore,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore-next-line new.static
    return new static(
      $container->get('markaspot_vision.original_image_store'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * Lists the originals the current account may open.
   *
   * Query parameter "media": comma-separated media UUIDs. The answer maps
   * each UUID with a visible original to its private download URL; media
   * without an original, or out of the account's reach, are left out.
   */
  public function list(Request $request): JsonResponse {
    $uuids = array_slice(array_values(array_filter(array_map(
      'trim',
      explode(',', (string) $request->query->get('media', '')),
    ))), 0, self::MAX_MEDIA);

    $originals = [];
    if ($uuids && $this->originalImageStore->isEnabled()) {
      $media_items = $this->entityTypeManager()->getStorage('media')->loadByProperties(['uuid' => $uuids]);
      foreach ($media_items as $media) {
        $original = $this->originalImageStore->find((int) $media->id());
        if ($original && $this->originalImageStore->canView($media, $this->currentUser())) {
          // Path only: the dashboard loads it through its own image proxy.
          $originals[$media->uuid()] = (string) parse_url($this->fileUrlGenerator->generateString($original['uri']), PHP_URL_PATH);
        }
      }
    }

    $response = new JsonResponse(['originals' => (object) $originals]);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
