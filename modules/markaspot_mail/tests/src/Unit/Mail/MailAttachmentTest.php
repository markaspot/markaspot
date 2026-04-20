<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Mail;

use Drupal\markaspot_mail\Mail\MailAttachment;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the readonly DTO that carries a single mail attachment.
 *
 * Focus: constructor validation invariants (MailAttachment is a boundary
 * DTO — third-party builders and future PDF/CSV generators may construct
 * it directly, so fail-fast behaviour matters more than for the
 * intra-module MailMessage DTO) and the phpmailer_smtp-compatible
 * toParam() shape.
 */
#[CoversClass(MailAttachment::class)]
#[Group('markaspot_mail')]
final class MailAttachmentTest extends UnitTestCase {

  /**
   *
   */
  public function testConstructWithFilepathAcceptsMinimalArgs(): void {
    $attachment = new MailAttachment(
      filename: 'photo.jpg',
      filemime: 'image/jpeg',
      filepath: 'public://reports/photo.jpg',
    );
    $this->assertSame('photo.jpg', $attachment->filename);
    $this->assertSame('image/jpeg', $attachment->filemime);
    $this->assertSame('public://reports/photo.jpg', $attachment->filepath);
    $this->assertNull($attachment->filecontent);
    $this->assertNull($attachment->filesize);
  }

  /**
   *
   */
  public function testConstructWithFilecontentAcceptsMinimalArgs(): void {
    $attachment = new MailAttachment(
      filename: 'receipt.pdf',
      filemime: 'application/pdf',
      filecontent: '%PDF-1.4 raw bytes',
    );
    $this->assertSame('%PDF-1.4 raw bytes', $attachment->filecontent);
    $this->assertNull($attachment->filepath);
  }

  /**
   *
   */
  public function testConstructThrowsWhenBothPayloadsMissing(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('requires either $filepath or $filecontent');
    new MailAttachment(
      filename: 'empty.txt',
      filemime: 'text/plain',
    );
  }

  /**
   *
   */
  public function testConstructThrowsOnEmptyFilename(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('requires a non-empty, path-traversal-safe $filename');
    new MailAttachment(
      filename: '',
      filemime: 'image/jpeg',
      filepath: 'public://photo.jpg',
    );
  }

  /**
   *
   */
  public function testConstructThrowsOnDotFilename(): void {
    $this->expectException(\InvalidArgumentException::class);
    new MailAttachment(
      filename: '.',
      filemime: 'image/jpeg',
      filepath: 'public://photo.jpg',
    );
  }

  /**
   *
   */
  public function testConstructThrowsOnDotDotFilename(): void {
    $this->expectException(\InvalidArgumentException::class);
    new MailAttachment(
      filename: '..',
      filemime: 'image/jpeg',
      filepath: 'public://photo.jpg',
    );
  }

  /**
   * Filename sanitation covers CWE-93 (mail-header injection). A filename
   * with embedded CR/LF can close the Content-Disposition header and open
   * a forged one downstream. basename() additionally neutralizes path
   * segments that might confuse visual rendering in mail clients.
   */
  public function testFilenameStripsCarriageReturnLineFeedNul(): void {
    $attachment = new MailAttachment(
      filename: "photo\r\n.jpg\0",
      filemime: 'image/jpeg',
      filepath: 'public://photo.jpg',
    );
    $this->assertSame('photo.jpg', $attachment->filename);
  }

  /**
   *
   */
  public function testFilenameBasenameStripsPathTraversal(): void {
    $attachment = new MailAttachment(
      filename: '../../etc/passwd.jpg',
      filemime: 'image/jpeg',
      filepath: 'public://photo.jpg',
    );
    $this->assertSame('passwd.jpg', $attachment->filename);
  }

  /**
   *
   */
  public function testFilenameBasenameStripsDirectorySeparator(): void {
    $attachment = new MailAttachment(
      filename: 'subdir/photo.jpg',
      filemime: 'image/jpeg',
      filepath: 'public://photo.jpg',
    );
    $this->assertSame('photo.jpg', $attachment->filename);
  }

  /**
   *
   */
  public function testToParamShapeMatchesPhpmailerSmtpExpectation(): void {
    $attachment = new MailAttachment(
      filename: 'photo.jpg',
      filemime: 'image/jpeg',
      filepath: 'public://reports/photo.jpg',
    );
    $this->assertSame(
      [
        'filename' => 'photo.jpg',
        'filemime' => 'image/jpeg',
        'filepath' => 'public://reports/photo.jpg',
      ],
      $attachment->toParam(),
    );
  }

  /**
   *
   */
  public function testToParamIncludesFilecontentWhenSet(): void {
    $attachment = new MailAttachment(
      filename: 'dyn.csv',
      filemime: 'text/csv',
      filecontent: "a,b\n1,2\n",
    );
    $param = $attachment->toParam();
    $this->assertSame("a,b\n1,2\n", $param['filecontent']);
    $this->assertArrayNotHasKey('filepath', $param);
  }

  /**
   *
   */
  public function testToParamIncludesBothWhenBothSet(): void {
    $attachment = new MailAttachment(
      filename: 'both.bin',
      filemime: 'application/octet-stream',
      filepath: 'public://both.bin',
      filecontent: 'raw',
    );
    $param = $attachment->toParam();
    $this->assertArrayHasKey('filepath', $param);
    $this->assertArrayHasKey('filecontent', $param);
  }

  /**
   *
   */
  public function testFilesizePassedThroughWhenSet(): void {
    $attachment = new MailAttachment(
      filename: 'photo.jpg',
      filemime: 'image/jpeg',
      filepath: 'public://photo.jpg',
      filesize: 12345,
    );
    $this->assertSame(12345, $attachment->filesize);
  }

}
