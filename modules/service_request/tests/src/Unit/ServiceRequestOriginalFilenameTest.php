<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__, 3) . '/service_request.module';

/**
 * Tests preservation of UTF-8 upload display names.
 */
final class ServiceRequestOriginalFilenameTest extends UnitTestCase {

  /**
   * Valid UTF-8 names survive the ASCII upload transport fallback.
   */
  public function testDecodesUnicodeDisplayName(): void {
    $filename = 'файл крефельд.xlsx';

    $this->assertSame(
      $filename,
      _service_request_decode_original_upload_filename(rawurlencode($filename), 'attachment.xlsx'),
    );
  }

  /**
   * Path separators and control characters are rejected.
   */
  public function testRejectsUnsafeDisplayNames(): void {
    $this->assertNull(_service_request_decode_original_upload_filename(rawurlencode('../secret.txt'), 'secret.txt'));
    $this->assertNull(_service_request_decode_original_upload_filename(rawurlencode("report\r\nInjected.txt"), 'report.txt'));
  }

  /**
   * Oversized header and decoded values retain the safe fallback name.
   */
  public function testRejectsOversizedDisplayNames(): void {
    $this->assertNull(_service_request_decode_original_upload_filename(str_repeat('a', 4097), 'attachment.txt'));
    $this->assertNull(_service_request_decode_original_upload_filename(rawurlencode(str_repeat('ä', 241)), 'attachment'));
  }

  /**
   * The header limit accommodates every valid 240-codepoint UTF-8 filename.
   */
  public function testAcceptsMaximumLengthMultibyteDisplayName(): void {
    $filename = str_repeat('🙂', 236) . '.txt';

    $this->assertLessThanOrEqual(4096, strlen(rawurlencode($filename)));
    $this->assertSame(
      $filename,
      _service_request_decode_original_upload_filename(rawurlencode($filename), 'attachment.txt'),
    );
  }

  /**
   * The display name cannot bypass the transport extension policy.
   */
  public function testRejectsMismatchedExtension(): void {
    $this->assertNull(
      _service_request_decode_original_upload_filename(rawurlencode('payload.php'), 'attachment.jpg'),
    );
    $this->assertSame(
      'Sommerbild.JPG',
      _service_request_decode_original_upload_filename(rawurlencode('Sommerbild.JPG'), 'upload.jpg'),
    );
  }

  /**
   * Unicode format controls are removed before the name is displayed.
   */
  public function testRemovesUnicodeBidiControlsWithoutChangingJoiners(): void {
    $filename = "invoice\u{202E}gpj.exe.jpg";

    $this->assertSame(
      'invoicegpj.exe.jpg',
      _service_request_decode_original_upload_filename(rawurlencode($filename), 'attachment.jpg'),
    );

    $family = "family👨\u{200D}👩\u{200D}👧\u{200D}👦.jpg";
    $this->assertSame(
      $family,
      _service_request_decode_original_upload_filename(rawurlencode($family), 'attachment.jpg'),
    );

    $persian = "می\u{200C}روم.pdf";
    $this->assertSame(
      $persian,
      _service_request_decode_original_upload_filename(rawurlencode($persian), 'attachment.pdf'),
    );
  }

  /**
   * Sanitizer growth is truncated without removing its safety marker.
   */
  public function testTruncatesSanitizerGrowthAtDrupalLimit(): void {
    $sanitized = str_repeat('a', 230) . '.final_.pdf';

    $result = _service_request_truncate_sanitized_upload_filename($sanitized);

    $this->assertSame(str_repeat('a', 229) . '.final_.pdf', $result);
    $this->assertSame(240, mb_strlen((string) $result));
  }

  /**
   * Only the upload routes and fields used for service requests are trusted.
   */
  public function testScopesOriginalFilenameHeaderToSupportedUploads(): void {
    $request = Request::create('/jsonapi/media/request_image/field_media_image', 'POST');
    $request->attributes->set('_route', 'jsonapi.media--request_image.file_upload.new_resource');
    $request->attributes->set('file_field_name', 'field_media_image');
    $this->assertSame([
      'entity_type' => 'media',
      'bundle' => 'request_image',
      'field' => 'field_media_image',
    ], _service_request_original_filename_upload_context($request));

    $request->attributes->set('_route', 'jsonapi.node--page.file_upload.existing_resource');
    $this->assertNull(_service_request_original_filename_upload_context($request));

    $request->attributes->set('_route', 'jsonapi.node--service_request.file_upload.existing_resource');
    $request->attributes->set('file_field_name', 'field_request_image');
    $this->assertNull(_service_request_original_filename_upload_context($request));
  }

  /**
   * Drupal's upload sanitizer result is revalidated before persistence.
   */
  public function testAppliesDrupalUploadSanitization(): void {
    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->expects($this->once())
      ->method('dispatch')
      ->willReturnCallback(static function (object $event): object {
        $event->setFilename('payload.php_.jpg');
        return $event;
      });

    $this->assertSame(
      'payload.php_.jpg',
      _service_request_sanitize_original_upload_filename(
        'payload.php.jpg',
        'attachment.jpg',
        'jpg',
        $dispatcher,
      ),
    );
  }

}
