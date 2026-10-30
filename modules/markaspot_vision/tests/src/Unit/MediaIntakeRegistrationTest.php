<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface as DrupalQueueInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\markaspot_vision\Service\MediaIntakeScreening;
use Drupal\markaspot_vision\Service\MediaTenantScopeResolver;
use Drupal\markaspot_vision\Service\OriginalImageStore;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins association ordering and recoverability of queue backend failures.
 */
#[Group('markaspot_vision')]
final class MediaIntakeRegistrationTest extends UnitTestCase {

  /**
   * Registration holds media immediately; enqueueing waits for association.
   */
  #[DataProvider('associationCases')]
  public function testRegistration(bool $associated): void {
    $media = $this->createMock(MediaInterface::class);
    $media->method('id')->willReturn(42);
    $nodes = $associated ? [1 => $this->createMock(NodeInterface::class)] : [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn(array_keys($nodes));
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($nodes);
    $storage->method('load')->with(42)->willReturn($media);
    $storage->method('loadUnchanged')->with(42)->willReturn($media);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturn($storage);
    $keyValue = new KeyValueMemoryFactory();
    $queue = $this->createMock(DrupalQueueInterface::class);
    $queue->expects($associated ? $this->exactly(2) : $this->never())->method('createItem')
      ->with(['media_id' => 42])->willReturnOnConsecutiveCalls(FALSE, 17);
    $queues = $this->createMock(QueueFactory::class);
    $queues->method('get')->with(MediaIntakeScreening::QUEUE)->willReturn($queue);
    $images = $this->createMock(ImageProcessingService::class);
    $images->expects($this->never())->method('blurSensitiveAreas');
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($associated ? $this->once() : $this->never())->method('error');
    $loggers = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggers->method('get')->willReturn($logger);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(12345);
    $service = new MediaIntakeScreening(
      $queues, $keyValue, new NullLockBackend(), $entities, $this->getConfigFactoryStub([]), $time,
      $images, $this->createMock(OriginalImageStore::class), $this->createMock(MediaTenantScopeResolver::class),
      $this->createMock(FeatureScopeResolver::class), $loggers,
    );
    $service->register($media);
    $this->assertFalse($keyValue->get(MediaIntakeScreening::STORE)->get(42)['queued']);
    $service->enqueue($media);
    if (!$associated) {
      $service->process(42);
    }
    $this->assertSame(0, $keyValue->get(MediaIntakeScreening::STORE)->get(42)['attempts']);
    $service->recoverPending();
    $service->enqueue($media);
    $this->assertSame($associated, $keyValue->get(MediaIntakeScreening::STORE)->get(42)['queued']);
  }

  /**
   * A report not saved yet and a queue backend initially refusing work.
   */
  public static function associationCases(): array {
    return ['before association' => [FALSE], 'recover failed insertion' => [TRUE]];
  }

}
