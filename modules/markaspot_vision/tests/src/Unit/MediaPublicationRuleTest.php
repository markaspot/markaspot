<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\file\FileInterface;
use Drupal\markaspot_vision\Service\MediaIntakeScreening;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins each conjunct of the automatic media publication rule.
 */
#[Group('markaspot_vision')]
final class MediaPublicationRuleTest extends UnitTestCase {

  /**
   * Only screened, clean images of published reports are released.
   */
  #[DataProvider('publicationCases')]
  public function testPublication(bool $nodePublished, string $metadata, bool $flag, string $issues, ?array $intake, bool $strict, bool $manual, bool $publish, bool $busy = FALSE): void {
    require_once dirname(__DIR__, 3) . '/markaspot_vision.module';
    $container = new ContainerBuilder();
    $container->set('config.factory', $this->getConfigFactoryStub(['markaspot_vision.settings' => ['require_ai_screening' => $strict]]));
    $keyValue = new KeyValueMemoryFactory();
    $container->set('keyvalue', $keyValue);
    $lock = new NullLockBackend();
    if ($busy) {
      $lock = $this->createMock(LockBackendInterface::class);
      $lock->method('acquire')->willReturn(FALSE);
      $lock->expects($this->once())->method('wait')->with('markaspot_open311.media_publication.42', 1);
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('Media publication is being edited.');
    }
    $container->set('lock', $lock);
    $loggers = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggers->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container->set('logger.factory', $loggers);
    \Drupal::setContainer($container);
    $source = tempnam(sys_get_temp_dir(), 'fictional-publication-');
    file_put_contents($source, 'Fictional screened bytes');
    if (!empty($intake['privacy_screened'])) {
      $intake['status'] ??= 'done';
      $intake['processed'] ??= TRUE;
      $intake['prepared_hash'] ??= hash('sha256', 'Fictional screened bytes');
    }
    if ($intake !== NULL) {
      $keyValue->get(MediaIntakeScreening::STORE)->set(42, $intake);
    }
    if ($manual) {
      $keyValue->get('markaspot_open311.media_publication_manual')->set(42, TRUE);
    }
    $media = $this->createMock(MediaInterface::class);
    $media->method('id')->willReturn(42);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadUnchanged')->with(42)->willReturn($media);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('media')->willReturn($storage);
    $container->set('entity_type.manager', $manager);
    $media->method('isPublished')->willReturn(FALSE);
    $media->method('hasField')->willReturn(TRUE);
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($source);
    $media->method('get')->willReturnCallback(function (string $name) use ($metadata, $flag, $issues, $file) {
      $item = $this->createMock(FieldItemListInterface::class);
      $item->method('__isset')->with('value')->willReturn(TRUE);
      $item->method('__get')->willReturn(match ($name) {
        'field_media_image' => $file,
        'field_ai_metadata' => $metadata,
        'field_ai_privacy_flag' => $flag,
        'field_ai_privacy_issues' => $issues,
      });
      return $item;
    });
    $media->expects($publish ? $this->once() : $this->never())->method('setPublished');
    $media->expects($publish ? $this->once() : $this->never())->method('save');
    $references = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $references->method('isEmpty')->willReturn(FALSE);
    $references->method('referencedEntities')->willReturn([$media]);
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('isPublished')->willReturn($nodePublished);
    $node->method('get')->with('field_request_media')->willReturn($references);
    try {
      _markaspot_vision_publish_safe_media($node);
      if (($intake['prepared_hash'] ?? '') === 'wrong') {
        $this->assertSame('failed', $keyValue->get(MediaIntakeScreening::STORE)->get(42)['status']);
      }
    }
    finally {
      unlink($source);
    }
  }

  /**
   * Independent publication guards, including explicit editorial authority.
   */
  public static function publicationCases(): array {
    return [
      'clean screened published' => [TRUE, '{}', FALSE, '', ['privacy_screened' => TRUE], TRUE, FALSE, TRUE],
      'draft report' => [FALSE, '{}', FALSE, '', ['privacy_screened' => TRUE], TRUE, FALSE, FALSE],
      'source replaced' => [
        TRUE, '{}', FALSE, '',
        ['privacy_screened' => TRUE, 'prepared_hash' => 'wrong'],
        TRUE, FALSE, FALSE,
      ],
      'strict missing marker' => [TRUE, '', FALSE, '', NULL, TRUE, FALSE, FALSE],
      'privacy flag' => [TRUE, '{}', TRUE, '', NULL, TRUE, FALSE, FALSE],
      'privacy issues' => [TRUE, '{}', FALSE, 'Fictional personal information', NULL, TRUE, FALSE, FALSE],
      'pending import legacy' => [TRUE, '', FALSE, '', ['status' => 'pending'], FALSE, FALSE, FALSE],
      'failed import legacy' => [TRUE, '{}', FALSE, '', ['status' => 'failed'], FALSE, FALSE, FALSE],
      'manual unpublished' => [TRUE, '{}', FALSE, '', NULL, TRUE, TRUE, FALSE],
      'legacy ordinary media' => [TRUE, '', FALSE, '', NULL, FALSE, FALSE, TRUE],
      'busy publication lock' => [TRUE, '{}', FALSE, '', NULL, TRUE, FALSE, FALSE, TRUE],
    ];
  }

}
