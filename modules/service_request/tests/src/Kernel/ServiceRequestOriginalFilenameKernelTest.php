<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers original upload filenames through Drupal's file presave lifecycle.
 *
 * @group service_request
 */
#[RunTestsInSeparateProcesses]
final class ServiceRequestOriginalFilenameKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'file',
    'service_request',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'file', 'node']);

    NodeType::create([
      'type' => 'service_request',
      'name' => 'Service request',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_attachment',
      'entity_type' => 'node',
      'type' => 'file',
      'settings' => ['target_type' => 'file'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_attachment',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Attachment',
      'settings' => [
        'file_extensions' => 'txt png jpg pdf doc docx eml',
      ],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Supported uploads apply core sanitizers without changing the safe URI.
   */
  public function testSupportedUploadSanitizesDisplayNameOnly(): void {
    $request = $this->uploadRequest(
      'jsonapi.node--service_request.file_upload.existing_resource',
      'field_attachment',
      'payload.php.jpg',
    );
    $this->container->get('request_stack')->push($request);

    try {
      $file = File::create([
        'filename' => 'attachment.jpg',
        'uri' => 'temporary://attachment.jpg',
        'filemime' => 'image/jpeg',
        'filesize' => 1,
        'status' => 0,
      ]);
      $file->save();
    }
    finally {
      $this->container->get('request_stack')->pop();
    }

    $this->assertSame('payload.php_.jpg', $file->getFilename());
    $this->assertSame('temporary://attachment.jpg', $file->getFileUri());

    $request = $this->uploadRequest(
      'jsonapi.node--service_request.file_upload.existing_resource',
      'field_attachment',
      'report.doc.pdf',
    );
    $this->container->get('request_stack')->push($request);

    try {
      $allowed = File::create([
        'filename' => 'attachment.pdf',
        'uri' => 'temporary://attachment-allowed.pdf',
        'filemime' => 'application/pdf',
        'filesize' => 1,
        'status' => 0,
      ]);
      $allowed->save();
    }
    finally {
      $this->container->get('request_stack')->pop();
    }

    $this->assertSame('report.doc.pdf', $allowed->getFilename());
  }

  /**
   * Sanitizer growth stays within Drupal's filename length constraint.
   */
  public function testExactLimitMultiDotFilenameRemainsSafe(): void {
    $request = $this->uploadRequest(
      'jsonapi.node--service_request.file_upload.existing_resource',
      'field_attachment',
      str_repeat('a', 230) . '.final.pdf',
    );
    $this->container->get('request_stack')->push($request);

    try {
      $file = File::create([
        'filename' => 'attachment-limit.pdf',
        'uri' => 'temporary://attachment-limit.pdf',
        'filemime' => 'application/pdf',
        'filesize' => 1,
        'status' => 0,
      ]);
      $file->save();
    }
    finally {
      $this->container->get('request_stack')->pop();
    }

    $this->assertSame(str_repeat('a', 229) . '.final_.pdf', $file->getFilename());
    $this->assertSame(240, mb_strlen($file->getFilename()));
    $this->assertSame('temporary://attachment-limit.pdf', $file->getFileUri());
  }

  /**
   * An unrelated file save cannot opt into the display-name header.
   */
  public function testUnsupportedUploadRouteRetainsTransportName(): void {
    $request = $this->uploadRequest(
      'jsonapi.node--page.file_upload.existing_resource',
      'field_attachment',
      'fremde-datei.jpg',
    );
    $this->container->get('request_stack')->push($request);

    try {
      $file = File::create([
        'filename' => 'attachment-other.jpg',
        'uri' => 'temporary://attachment-other.jpg',
        'filemime' => 'image/jpeg',
        'filesize' => 1,
        'status' => 0,
      ]);
      $file->save();
    }
    finally {
      $this->container->get('request_stack')->pop();
    }

    $this->assertSame('attachment-other.jpg', $file->getFilename());
  }

  /**
   * Creates a JSON:API upload request fixture.
   */
  private function uploadRequest(string $route, string $field, string $filename): Request {
    $request = Request::create('/jsonapi/upload', 'POST');
    $request->attributes->set('_route', $route);
    $request->attributes->set('file_field_name', $field);
    $request->headers->set('X-Markaspot-Original-Filename', rawurlencode($filename));

    return $request;
  }

}
