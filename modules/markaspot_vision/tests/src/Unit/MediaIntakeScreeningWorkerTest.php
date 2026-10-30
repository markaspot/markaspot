<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\markaspot_vision\Plugin\QueueWorker\MediaIntakeScreeningWorker;
use Drupal\markaspot_vision\Service\MediaIntakeScreening;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins queue dispatch, cron discovery and retry propagation.
 */
#[Group('markaspot_vision')]
final class MediaIntakeScreeningWorkerTest extends UnitTestCase {

  /**
   * The worker is discoverable by both cron and queue:run.
   */
  public function testCronRegistration(): void {
    $attributes = (new \ReflectionClass(MediaIntakeScreeningWorker::class))->getAttributes(QueueWorker::class);
    $this->assertCount(1, $attributes);
    $definition = $attributes[0]->newInstance();
    $this->assertSame(MediaIntakeScreening::QUEUE, $definition->id);
    $this->assertSame(['time' => 60], $definition->cron);
  }

  /**
   * Malformed jobs are dropped without invoking image processing.
   */
  public function testMalformedItemsAreDropped(): void {
    $service = $this->createMock(MediaIntakeScreening::class);
    $service->expects($this->never())->method('process');
    $worker = new MediaIntakeScreeningWorker([], MediaIntakeScreening::QUEUE, [], $service);
    foreach ([NULL, [], ['media_id' => 0], ['media_id' => -1]] as $data) {
      $worker->processItem($data);
    }
  }

  /**
   * The queue backend receives the requested backoff unchanged.
   */
  public function testRetryDelayIsPropagated(): void {
    $service = $this->createMock(MediaIntakeScreening::class);
    $service->expects($this->once())->method('process')->with(42)
      ->willThrowException(new DelayedRequeueException(120));
    $worker = new MediaIntakeScreeningWorker([], MediaIntakeScreening::QUEUE, [], $service);
    $this->expectException(DelayedRequeueException::class);
    $worker->processItem(['media_id' => 42]);
  }

}
