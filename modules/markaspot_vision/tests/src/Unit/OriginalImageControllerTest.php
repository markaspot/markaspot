<?php

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_vision\Controller\OriginalImageController;
use Drupal\markaspot_vision\Service\OriginalImageStore;
use Drupal\media\MediaInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the list of originals the staff dashboard may open.
 */
#[CoversClass(OriginalImageController::class)]
#[Group('markaspot_vision')]
class OriginalImageControllerTest extends UnitTestCase {

  /**
   * Builds the controller over media 1 (viewable) and 2 (not viewable).
   *
   * @param array|null $requestedUuids
   *   Receives the UUIDs the controller looked up.
   * @param bool $enabled
   *   Whether the site keeps originals.
   */
  private function controller(?array &$requestedUuids, bool $enabled = TRUE): OriginalImageController {
    $media = [];
    foreach ([1, 2, 3] as $id) {
      $item = $this->createMock(MediaInterface::class);
      $item->method('id')->willReturn($id);
      $item->method('uuid')->willReturn('uuid-' . $id);
      $media['uuid-' . $id] = $item;
    }
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturnCallback(function (array $values) use ($media, &$requestedUuids) {
      $requestedUuids = $values['uuid'];
      return array_intersect_key($media, array_flip($values['uuid']));
    });
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('media')->willReturn($storage);

    $store = $this->createMock(OriginalImageStore::class);
    $store->method('isEnabled')->willReturn($enabled);
    // Media 3 was never blurred, so there is no original.
    $store->method('find')->willReturnCallback(fn (int $mid) => $mid === 3 ? NULL : [
      'uri' => OriginalImageStore::DIRECTORY . '/uuid-' . $mid,
      'mime' => 'image/jpeg',
    ]);
    $store->method('canView')->willReturnCallback(fn (MediaInterface $item) => $item->id() === 1);

    $urls = $this->createMock(FileUrlGeneratorInterface::class);
    $urls->method('generateString')->willReturnCallback(fn (string $uri) => '/system/files/' . substr($uri, strlen('private://')));

    $controller = new OriginalImageController($store, $urls);
    $controller->setStringTranslation($this->getStringTranslationStub());
    foreach (['entityTypeManager' => $entityTypeManager, 'currentUser' => $this->createMock(AccountInterface::class)] as $property => $value) {
      $reflection = new \ReflectionProperty($controller, $property);
      $reflection->setValue($controller, $value);
    }
    return $controller;
  }

  /**
   * Only originals the account may open are listed, never cached.
   */
  public function testListsOnlyOriginalsTheAccountMayOpen(): void {
    $response = $this->controller($requested)->list(new Request(['media' => 'uuid-1, uuid-2,uuid-3']));

    $this->assertSame(
      ['originals' => ['uuid-1' => '/system/files/markaspot_vision/originals/uuid-1']],
      json_decode($response->getContent(), TRUE),
    );
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
  }

  /**
   * A site that keeps no originals answers with an empty list.
   */
  public function testSiteWithoutOriginalsListsNothing(): void {
    $response = $this->controller($requested, FALSE)->list(new Request(['media' => 'uuid-1']));

    $this->assertSame(['originals' => []], json_decode($response->getContent(), TRUE));
    $this->assertNull($requested);
  }

  /**
   * One request looks up ten media at most.
   */
  public function testLookupIsCappedAtTenMedia(): void {
    $uuids = implode(',', array_map(fn ($i) => 'uuid-' . $i, range(1, 25)));

    $this->controller($requested)->list(new Request(['media' => $uuids]));

    $this->assertCount(10, $requested);
  }

}
