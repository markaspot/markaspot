<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_vision\Service\OriginalImageStore;
use Drupal\media\MediaInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Pins the one file download hook the profile ships.
 *
 * FileAccessHookAbsenceTest in markaspot_nuxt allows this hook by name. This
 * test keeps that exception honest: no opinion outside the originals, a
 * denial inside them unless the store holds that exact file and the account
 * may see it, and headers only then.
 */
#[Group('markaspot_vision')]
class FileDownloadHookTest extends UnitTestCase {

  private const URI = OriginalImageStore::DIRECTORY . '/9b1c2e3f.jpg';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_vision.module';
  }

  /**
   * Other private files stay with core; the hook does not even look.
   */
  public function testNoOpinionOutsideTheOriginals(): void {
    $store = $this->createMock(OriginalImageStore::class);
    $store->expects($this->never())->method($this->anything());
    $this->boot($store, NULL);

    foreach ([
      'private://report.pdf',
      'public://markaspot_vision/originals/9b1c2e3f.jpg',
      'private://markaspot_vision/originals-copy/9b1c2e3f.jpg',
      OriginalImageStore::DIRECTORY,
    ] as $uri) {
      self::assertNull(markaspot_vision_file_download($uri), $uri);
    }
  }

  /**
   * Switched off, every original is denied, even to its editors.
   */
  public function testDeniedWhileSwitchedOff(): void {
    $store = $this->store(FALSE, ['mid' => 7, 'mime' => 'image/jpeg'], TRUE);
    $store->expects($this->never())->method('findByUri');
    $store->expects($this->never())->method('logView');
    $this->boot($store, $this->createMock(MediaInterface::class));

    self::assertSame(-1, markaspot_vision_file_download(self::URI));
  }

  /**
   * A path the store does not hold is denied.
   *
   * Traversal is core's to collapse before any hook runs; the store then
   * matches the exact URI (OriginalImageStoreTest).
   */
  public function testDeniedWithoutStoredOriginal(): void {
    $store = $this->store(TRUE, NULL, TRUE);
    $store->expects($this->never())->method('logView');
    $this->boot($store, $this->createMock(MediaInterface::class));

    self::assertSame(-1, markaspot_vision_file_download(self::URI));
  }

  /**
   * An original whose media is gone is denied.
   */
  public function testDeniedWhenTheMediaIsGone(): void {
    $store = $this->store(TRUE, ['mid' => 7, 'mime' => 'image/jpeg'], TRUE);
    $store->expects($this->never())->method('logView');
    $this->boot($store, NULL);

    self::assertSame(-1, markaspot_vision_file_download(self::URI));
  }

  /**
   * An account the store turns away is denied and not logged as a viewer.
   */
  public function testDeniedWhenTheAccountMayNotSeeIt(): void {
    $store = $this->store(TRUE, ['mid' => 7, 'mime' => 'image/jpeg'], FALSE);
    $store->expects($this->never())->method('logView');
    $this->boot($store, $this->createMock(MediaInterface::class));

    self::assertSame(-1, markaspot_vision_file_download(self::URI));
  }

  /**
   * Only an allowed account gets the file, uncached, and the view is logged.
   */
  public function testServedAndLoggedForAnAllowedAccount(): void {
    $media = $this->createMock(MediaInterface::class);
    $store = $this->createMock(OriginalImageStore::class);
    $store->method('isEnabled')->willReturn(TRUE);
    $store->method('findByUri')->willReturn(['mid' => 7, 'mime' => 'image/png']);
    $account = $this->boot($store, $media);
    $store->expects($this->once())->method('canView')->with($media, $account)->willReturn(TRUE);
    $store->expects($this->once())->method('logView')->with(7, $account);

    self::assertSame([
      'Content-Type' => 'image/png',
      'Cache-Control' => 'private, no-store',
      'X-Content-Type-Options' => 'nosniff',
      'Content-Disposition' => 'inline',
    ], markaspot_vision_file_download(self::URI));
  }

  /**
   * Anything but an image is handed over as a download, never rendered.
   */
  public function testNonImageIsAnAttachment(): void {
    $store = $this->store(TRUE, ['mid' => 7, 'mime' => 'text/html'], TRUE);
    $this->boot($store, $this->createMock(MediaInterface::class));

    self::assertSame('attachment', markaspot_vision_file_download(self::URI)['Content-Disposition']);
  }

  /**
   * A store stub with the given switch, stored row and access answer.
   */
  private function store(bool $enabled, ?array $row, bool $can_view): OriginalImageStore&MockObject {
    $store = $this->createMock(OriginalImageStore::class);
    $store->method('isEnabled')->willReturn($enabled);
    $store->method('findByUri')->willReturnCallback(
      static fn (string $uri): ?array => $uri === self::URI ? $row : NULL,
    );
    $store->method('canView')->willReturn($can_view);
    return $store;
  }

  /**
   * Registers the services the hook reaches through \Drupal.
   */
  private function boot(OriginalImageStore $store, ?MediaInterface $media): AccountProxyInterface {
    $account = $this->createMock(AccountProxyInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(
      static fn ($id): ?MediaInterface => (int) $id === 7 ? $media : NULL,
    );
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnCallback(
      static fn (string $type): EntityStorageInterface => $type === 'media' ? $storage : throw new \LogicException($type),
    );
    $repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $repository->method('getEntityTypeFromClass')->willReturn('media');

    $container = new ContainerBuilder();
    $container->set('markaspot_vision.original_image_store', $store);
    $container->set('current_user', $account);
    $container->set('entity_type.manager', $manager);
    $container->set('entity_type.repository', $repository);
    \Drupal::setContainer($container);
    return $account;
  }

}
