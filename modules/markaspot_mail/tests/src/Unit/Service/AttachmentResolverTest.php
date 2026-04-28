<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\file\FileInterface;
use Drupal\markaspot_mail\Service\AttachmentResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Covers the five policy gates that turn field references into attachments.
 *
 * The resolver is the single decision point for "is this file a valid
 * attachment candidate"; each test pins one gate in isolation so a
 * future refactor can't silently merge or bypass one.
 */
#[CoversClass(AttachmentResolver::class)]
#[Group('markaspot_mail')]
final class AttachmentResolverTest extends UnitTestCase {

  /**
   *
   */
  public function testMasterSwitchDisabledReturnsEmpty(): void {
    $resolver = $this->buildResolver(['enabled' => FALSE]);
    $entity = $this->buildEntity(['field_request_image' => [$this->buildFile()]]);
    $this->assertSame([], $resolver->resolve($entity, ['field_request_image']));
  }

  /**
   *
   */
  public function testMissingFieldIsSilentlySkipped(): void {
    $resolver = $this->buildResolver();
    $entity = $this->buildEntity([]);
    $this->assertSame([], $resolver->resolve($entity, ['nonexistent_field']));
  }

  /**
   *
   */
  public function testEmptyFieldIsSilentlySkipped(): void {
    $resolver = $this->buildResolver();
    $entity = $this->buildEntity(['field_request_image' => []]);
    $this->assertSame([], $resolver->resolve($entity, ['field_request_image']));
  }

  /**
   *
   */
  public function testTemporaryFileIsSkipped(): void {
    $resolver = $this->buildResolver();
    $temp = $this->buildFile(permanent: FALSE);
    $entity = $this->buildEntity(['field_request_image' => [$temp]]);
    $this->assertSame([], $resolver->resolve($entity, ['field_request_image']));
  }

  /**
   *
   */
  public function testNonFileReferencedEntityIsSkipped(): void {
    $resolver = $this->buildResolver();
    $notAFile = $this->createMock(ContentEntityInterface::class);
    $entity = $this->buildEntity(['field_request_image' => [$notAFile]]);
    $this->assertSame([], $resolver->resolve($entity, ['field_request_image']));
  }

  /**
   *
   */
  public function testPermanentPublicFileIsResolved(): void {
    $resolver = $this->buildResolver();
    $file = $this->buildFile(uri: 'public://photo.jpg', filename: 'photo.jpg', size: 1024);
    $entity = $this->buildEntity(['field_request_image' => [$file]]);
    $result = $resolver->resolve($entity, ['field_request_image']);
    $this->assertCount(1, $result);
    $this->assertSame('photo.jpg', $result[0]->filename);
    $this->assertSame('image/jpeg', $result[0]->filemime);
    $this->assertSame('public://photo.jpg', $result[0]->filepath);
    $this->assertSame(1024, $result[0]->filesize);
  }

  /**
   *
   */
  public function testPrivateSchemeBlockedByDefault(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('private:// scheme not allowed'));
    $resolver = $this->buildResolver(logger: $logger);
    $file = $this->buildFile(uri: 'private://secret.jpg');
    $entity = $this->buildEntity(['field_request_image' => [$file]]);
    $this->assertSame([], $resolver->resolve($entity, ['field_request_image']));
  }

  /**
   *
   */
  public function testPrivateSchemeAllowedWhenOptedIn(): void {
    $resolver = $this->buildResolver();
    $file = $this->buildFile(uri: 'private://citizen.jpg', filename: 'citizen.jpg');
    $entity = $this->buildEntity(['field_request_image' => [$file]]);
    $result = $resolver->resolve(
      $entity,
      ['field_request_image'],
      includePrivate: TRUE,
    );
    $this->assertCount(1, $result);
    $this->assertSame('private://citizen.jpg', $result[0]->filepath);
  }

  /**
   *
   */
  public function testMimeWhitelistRejectsUnlistedMime(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('MIME @mime not in whitelist'));
    $resolver = $this->buildResolver(
      ['allowed_mime' => ['image/jpeg', 'image/png']],
      logger: $logger,
    );
    $file = $this->buildFile(mime: 'application/x-shellscript');
    $entity = $this->buildEntity(['field_request_image' => [$file]]);
    $this->assertSame([], $resolver->resolve($entity, ['field_request_image']));
  }

  /**
   *
   */
  public function testEmptyAllowedMimeListDisablesMimeGate(): void {
    // Empty list is policy: MIME gate off. Operators must flip 'enabled'
    // to block all attachments, not prune the whitelist to [].
    $resolver = $this->buildResolver(['allowed_mime' => []]);
    $file = $this->buildFile(mime: 'application/x-custom-type');
    $entity = $this->buildEntity(['field_request_image' => [$file]]);
    $this->assertCount(1, $resolver->resolve($entity, ['field_request_image']));
  }

  /**
   *
   */
  public function testPerFileCapExceededIsRejected(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('per-file cap exceeded'));
    $resolver = $this->buildResolver(
      ['max_per_file_bytes' => 1000],
      logger: $logger,
    );
    $file = $this->buildFile(size: 2000);
    $entity = $this->buildEntity(['field_request_image' => [$file]]);
    $this->assertSame([], $resolver->resolve($entity, ['field_request_image']));
  }

  /**
   *
   */
  public function testBudgetExhaustedStopsAndLogsAggregate(): void {
    $logger = $this->createMock(LoggerInterface::class);
    // Budget-exhaust logs as info, not notice (operator-routine on large
    // reports).
    $logger->expects($this->once())
      ->method('info')
      ->with($this->stringContains('budget exhausted'));
    $resolver = $this->buildResolver([
      'max_per_file_bytes' => 1000,
      'max_total_bytes' => 1500,
    ], logger: $logger);
    $first = $this->buildFile(id: 1, filename: 'a.jpg', size: 800);
    $second = $this->buildFile(id: 2, filename: 'b.jpg', size: 800);
    $third = $this->buildFile(id: 3, filename: 'c.jpg', size: 800);
    $entity = $this->buildEntity(['field_request_image' => [$first, $second, $third]]);
    $result = $resolver->resolve($entity, ['field_request_image']);
    // First fits (800 &lt;= 1500), second would bring us to 1600 &gt; 1500 — stop.
    $this->assertCount(1, $result);
    $this->assertSame('a.jpg', $result[0]->filename);
  }

  /**
   *
   */
  public function testInclusiveBudgetCheckAllowsExactFit(): void {
    $resolver = $this->buildResolver([
      'max_per_file_bytes' => 1000,
      'max_total_bytes' => 1500,
    ]);
    $first = $this->buildFile(id: 1, filename: 'a.jpg', size: 750);
    $second = $this->buildFile(id: 2, filename: 'b.jpg', size: 750);
    $entity = $this->buildEntity(['field_request_image' => [$first, $second]]);
    $result = $resolver->resolve($entity, ['field_request_image']);
    $this->assertCount(2, $result);
  }

  /**
   *
   */
  public function testUnknownSizeTreatedAsWorstCase(): void {
    // NULL size books perFileCap against the budget, so one unknown-size
    // file consumes a large chunk even if the actual payload is tiny.
    $resolver = $this->buildResolver([
      'max_per_file_bytes' => 1000,
      'max_total_bytes' => 1500,
    ]);
    $nullSize = $this->buildFile(id: 1, filename: 'unknown.jpg', size: NULL);
    $small = $this->buildFile(id: 2, filename: 'tiny.jpg', size: 100);
    $entity = $this->buildEntity(['field_request_image' => [$nullSize, $small]]);
    $result = $resolver->resolve($entity, ['field_request_image']);
    // First books 1000 (cap). Second needs 100, 1000+100=1100 &lt;= 1500 — fits.
    $this->assertCount(2, $result);
    $this->assertNull($result[0]->filesize);
    $this->assertSame(100, $result[1]->filesize);
  }

  /**
   *
   */
  public function testFieldOrderPreservedAcrossMultipleFields(): void {
    $resolver = $this->buildResolver();
    $img = $this->buildFile(id: 1, filename: 'photo.jpg');
    $doc = $this->buildFile(id: 2, filename: 'report.pdf', mime: 'application/pdf');
    $entity = $this->buildEntity([
      'field_request_image' => [$img],
      'field_attachment' => [$doc],
    ]);
    $result = $resolver->resolve(
      $entity,
      ['field_request_image', 'field_attachment'],
    );
    $this->assertCount(2, $result);
    // First-come-first-serve: field order determines attachment order.
    $this->assertSame('photo.jpg', $result[0]->filename);
    $this->assertSame('report.pdf', $result[1]->filename);
  }

  /**
   * Helper: builds a resolver with a config mock and optional logger.
   *
   * $settingsOverride merges into the default attachment settings so each
   * test can tune one gate in isolation without re-declaring the whole
   * config tree.
   */
  private function buildResolver(
    array $settingsOverride = [],
    ?LoggerInterface $logger = NULL,
  ): AttachmentResolver {
    $defaults = [
      'enabled' => TRUE,
      'max_per_file_bytes' => 5242880,
      'max_total_bytes' => 20971520,
      'allowed_mime' => [
        'image/jpeg',
        'image/png',
        'application/pdf',
      ],
    ];
    $settings = $settingsOverride + $defaults;

    $immutable = $this->createMock(ImmutableConfig::class);
    $immutable->method('get')
      ->with('attachments')
      ->willReturn($settings);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_mail.settings')
      ->willReturn($immutable);

    return new AttachmentResolver(
      $configFactory,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Helper: builds a FileInterface mock with sane defaults.
   *
   * Default values mirror a permanent public JPG so most gate tests can
   * override just the single property they care about.
   */
  private function buildFile(
    int|string $id = 1,
    string $filename = 'photo.jpg',
    string $mime = 'image/jpeg',
    string $uri = 'public://photo.jpg',
    ?int $size = 1024,
    bool $permanent = TRUE,
  ): FileInterface {
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn($id);
    $file->method('getFilename')->willReturn($filename);
    $file->method('getMimeType')->willReturn($mime);
    $file->method('getFileUri')->willReturn($uri);
    $file->method('getSize')->willReturn($size);
    $file->method('isPermanent')->willReturn($permanent);
    return $file;
  }

  /**
   * Helper: builds a ContentEntityInterface mock whose hasField/get
   * responds according to the provided field → referenced-entities map.
   *
   * Passing an empty array for a field produces an empty-list field
   * mock (isEmpty true). Omitting a field makes hasField return FALSE.
   */
  private function buildEntity(array $fields): ContentEntityInterface&MockObject {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('hasField')
      ->willReturnCallback(
        static fn(string $name): bool => array_key_exists($name, $fields),
      );
    $entity->method('get')
      ->willReturnCallback(function (string $name) use ($fields) {
        $items = $fields[$name] ?? [];
        $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
        $field->method('isEmpty')->willReturn($items === []);
        $field->method('referencedEntities')->willReturn($items);
        return $field;
      });
    return $entity;
  }

}
