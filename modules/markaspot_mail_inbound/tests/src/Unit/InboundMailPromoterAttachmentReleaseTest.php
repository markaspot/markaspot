<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Token;
use Drupal\file\FileInterface;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Service\InboundMailPromoter;
use Drupal\markaspot_mail_inbound\Service\InternalRemarkWriter;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Unit tests for the promoter's partial attachment-release branch (#482).
 *
 * Phase 1 test gap: when media creation throws for ONE of several staged
 * files, the sibling file must still be attached (and its reference released
 * on the mail later), while the failed file stays OUT of the released set so
 * its reference keeps it reachable for discard / uninstall cleanup. Also
 * covers the LOW carry-over: without field_request_media on the node, NO
 * media entity is created at all.
 *
 * @group markaspot_mail_inbound
 * @coversDefaultClass \Drupal\markaspot_mail_inbound\Service\InboundMailPromoter
 */
class InboundMailPromoterAttachmentReleaseTest extends UnitTestCase {

  /**
   * Builds a promoter whose media storage throws on selected file ids.
   *
   * @param array<int, \Drupal\file\FileInterface|null> $files
   *   File ids mapped to the file mock the storage returns (NULL = missing).
   * @param int[] $throwOnFids
   *   File ids whose media save() throws.
   * @param int $mediaCreations
   *   Receives the number of media create() calls (by reference).
   */
  protected function promoter(array $files, array $throwOnFids, int &$mediaCreations): TestablePromoterForAttachments {
    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')->willReturnCallback(
      static fn(int|string $fid) => $files[(int) $fid] ?? NULL
    );

    $mediaStorage = $this->createMock(EntityStorageInterface::class);
    $mediaStorage->method('create')->willReturnCallback(
      function (array $values) use ($throwOnFids, &$mediaCreations): MediaInterface {
        $mediaCreations++;
        $fid = (int) ($values['field_media_image']['target_id'] ?? 0);
        $media = $this->createMock(MediaInterface::class);
        $media->method('uuid')->willReturn('uuid-' . $fid);
        $media->method('setName')->willReturnSelf();
        $media->method('setPublished')->willReturnSelf();
        $media->method('id')->willReturn(1000 + $fid);
        if (in_array($fid, $throwOnFids, TRUE)) {
          $media->method('save')->willThrowException(new \RuntimeException('media save failed'));
        }
        else {
          $media->method('save')->willReturn(1);
        }
        return $media;
      }
    );

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturnCallback(
      static fn(string $type): EntityStorageInterface => match ($type) {
        'file' => $fileStorage,
        'media' => $mediaStorage,
        default => throw new \LogicException('Unexpected storage ' . $type),
      }
    );

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('media')->willReturn(TRUE);

    // resolveMediaDirectory(): the entity field manager throws (media field
    // storage not relevant here) and prepareDirectory() FAILS, so the
    // directory resolves to NULL and moveFileToMediaScheme() is a no-op —
    // the test isolates the media-creation branch.
    $entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $entityFieldManager->method('getFieldStorageDefinitions')->willThrowException(new \RuntimeException('no media definitions'));
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('prepareDirectory')->willReturn(FALSE);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn([]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);
    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnArgument(0);

    return new TestablePromoterForAttachments(
      $etm,
      $moduleHandler,
      $this->createMock(EventDispatcherInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $entityFieldManager,
      $configFactory,
      $fileSystem,
      $token,
      $this->createMock(InternalRemarkWriter::class),
      NULL,
      NULL,
      $this->createMock(LanguageManagerInterface::class),
    );
  }

  /**
   * Builds a file mock with an id and uri.
   */
  protected function file(int $fid): FileInterface {
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn($fid);
    $file->method('getFileUri')->willReturn('private://mail-inbound-staged/mail-' . $fid . '.jpg');
    return $file;
  }

  /**
   * Builds a mail mock exposing the given attachment file ids.
   */
  protected function mail(array $fids): InboundMail {
    $mail = $this->createMock(InboundMail::class);
    $mail->method('getAttachmentFileIds')->willReturn($fids);
    return $mail;
  }

  /**
   * Builds a node mock with/without field_request_media.
   */
  protected function node(bool $hasMediaField): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_request_media')->willReturn($hasMediaField);
    return $node;
  }

  /**
   * One failing media save releases ONLY the sibling file's reference.
   *
   * @covers ::buildAttachmentMedia
   */
  public function testPartialMediaFailureKeepsFailedFileReferenced(): void {
    $mediaCreations = 0;
    $promoter = $this->promoter(
      [7 => $this->file(7), 8 => $this->file(8)],
      throwOnFids: [7],
      mediaCreations: $mediaCreations,
    );

    $result = $promoter->exposeBuildAttachmentMedia($this->mail([7, 8]), $this->node(TRUE));

    // The sibling (fid 8) made it onto the node and is releasable; the
    // failed file (fid 7) is NOT in the released set, so its reference on
    // the mail keeps it reachable for discard / uninstall cleanup.
    $this->assertSame([8], $result['fids']);
    $this->assertCount(1, $result['items']);
    $this->assertSame(1008, $result['items'][0]['target_id']);
    $this->assertSame(2, $mediaCreations, 'Both files were attempted.');
  }

  /**
   * A missing file id is skipped without aborting the siblings.
   *
   * @covers ::buildAttachmentMedia
   */
  public function testMissingFileIsSkipped(): void {
    $mediaCreations = 0;
    $promoter = $this->promoter(
      [9 => $this->file(9)],
      throwOnFids: [],
      mediaCreations: $mediaCreations,
    );

    $result = $promoter->exposeBuildAttachmentMedia($this->mail([5, 9]), $this->node(TRUE));

    $this->assertSame([9], $result['fids']);
    $this->assertSame(1, $mediaCreations, 'Only the existing file minted a media entity.');
  }

  /**
   * Without field_request_media NO media entity is created (LOW carry-over).
   *
   * @covers ::buildAttachmentMedia
   */
  public function testNoMediaCreatedWhenFieldMissing(): void {
    $mediaCreations = 0;
    $promoter = $this->promoter(
      [7 => $this->file(7)],
      throwOnFids: [],
      mediaCreations: $mediaCreations,
    );

    $result = $promoter->exposeBuildAttachmentMedia($this->mail([7]), $this->node(FALSE));

    $this->assertSame(['items' => [], 'fids' => []], $result);
    $this->assertSame(0, $mediaCreations, 'The field check runs BEFORE any media creation.');
  }

}

/**
 * Test-only subclass exposing buildAttachmentMedia().
 */
class TestablePromoterForAttachments extends InboundMailPromoter {

  /**
   * Exposes buildAttachmentMedia().
   *
   * @return array{items: array<int, array<string, mixed>>, fids: int[]}
   *   The builder result.
   */
  public function exposeBuildAttachmentMedia(InboundMail $mail, NodeInterface $node): array {
    return $this->buildAttachmentMedia($mail, $node);
  }

}
