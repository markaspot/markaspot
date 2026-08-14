<?php

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\media\MediaInterface;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\Tests\UnitTestCase;

/**
 * Tests GeoReport media URL exposure access.
 *
 * The service is instantiated without its constructor because only the current
 * user is relevant to the private access decision under test.
 *
 * @coversDefaultClass \Drupal\markaspot_open311\Service\GeoreportProcessorService
 * @group markaspot_open311
 */
class GeoreportProcessorServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_open311\Service\GeoreportProcessorService
   */
  protected $processor;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $reflection = new \ReflectionClass(GeoreportProcessorService::class);
    $this->processor = $reflection->newInstanceWithoutConstructor();
    $this->currentUser = $this->createMock(AccountProxyInterface::class);

    $property = $reflection->getProperty('currentUser');
    $property->setAccessible(TRUE);
    $property->setValue($this->processor, $this->currentUser);
  }

  /**
   * Published media can always expose its URL.
   *
   * @covers ::canExposeMediaUrl
   */
  public function testPublishedMediaIsExposed(): void {
    $media = $this->createMock(MediaInterface::class);
    $media->method('isPublished')->willReturn(TRUE);
    $media->expects($this->never())->method('access');
    $this->currentUser->expects($this->never())->method('hasPermission');

    $this->assertTrue($this->canExposeMediaUrl($media, FALSE));
  }

  /**
   * Unpublished media is hidden when unpublished access is not requested.
   *
   * @covers ::canExposeMediaUrl
   */
  public function testUnpublishedMediaIsHiddenByDefault(): void {
    $media = $this->createMock(MediaInterface::class);
    $media->method('isPublished')->willReturn(FALSE);
    $media->expects($this->never())->method('access');
    $this->currentUser->expects($this->never())->method('hasPermission');

    $this->assertFalse($this->canExposeMediaUrl($media, FALSE));
  }

  /**
   * Unpublished media is hidden without the advanced properties permission.
   *
   * @covers ::canExposeMediaUrl
   */
  public function testUnpublishedMediaIsHiddenWithoutPermission(): void {
    $media = $this->createMock(MediaInterface::class);
    $media->method('isPublished')->willReturn(FALSE);
    $media->expects($this->never())->method('access');
    $this->currentUser->expects($this->once())
      ->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(FALSE);

    $this->assertFalse($this->canExposeMediaUrl($media, TRUE));
  }

  /**
   * Authorized users can expose viewable unpublished media.
   *
   * @covers ::canExposeMediaUrl
   */
  public function testUnpublishedMediaIsExposedWithPermissionAndViewAccess(): void {
    $media = $this->createMock(MediaInterface::class);
    $media->method('isPublished')->willReturn(FALSE);
    $this->currentUser->expects($this->once())
      ->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(TRUE);
    $media->expects($this->once())
      ->method('access')
      ->with('view', $this->currentUser)
      ->willReturn(TRUE);

    $this->assertTrue($this->canExposeMediaUrl($media, TRUE));
  }

  /**
   * Unpublished media is hidden when media view access is denied.
   *
   * @covers ::canExposeMediaUrl
   */
  public function testUnpublishedMediaIsHiddenWithoutViewAccess(): void {
    $media = $this->createMock(MediaInterface::class);
    $media->method('isPublished')->willReturn(FALSE);
    $this->currentUser->expects($this->once())
      ->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(TRUE);
    $media->expects($this->once())
      ->method('access')
      ->with('view', $this->currentUser)
      ->willReturn(FALSE);

    $this->assertFalse($this->canExposeMediaUrl($media, TRUE));
  }

  /**
   * Invokes the private media URL access decision.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   * @param bool $allowUnpublished
   *   Whether unpublished media may be considered for exposure.
   *
   * @return bool
   *   TRUE when the media URL may be exposed, FALSE otherwise.
   */
  private function canExposeMediaUrl(MediaInterface $media, bool $allowUnpublished): bool {
    $method = new \ReflectionMethod($this->processor, 'canExposeMediaUrl');
    $method->setAccessible(TRUE);

    return $method->invoke($this->processor, $media, $allowUnpublished);
  }

}
