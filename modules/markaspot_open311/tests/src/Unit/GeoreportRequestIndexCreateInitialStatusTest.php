<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_group\Service\JurisdictionScopeValidator;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestIndexResource;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 3) . '/src/Exception/GeoreportException.php';
require_once dirname(__DIR__, 3) . '/src/Plugin/rest/resource/GeoreportRequestIndexResource.php';

/**
 * Open311 create validates with the target jurisdiction's initial status.
 */
#[CoversClass(GeoreportRequestIndexResource::class)]
#[Group('markaspot_open311')]
final class GeoreportRequestIndexCreateInitialStatusTest extends UnitTestCase {

  /**
   * The field default (another jurisdiction's term) is replaced first.
   *
   * The API user of jurisdiction 5 may not reference the default term 3 of
   * jurisdiction 1; validation must already see jurisdiction 5's term 84.
   */
  public function testInitialStatusIsSetBeforeValidation(): void {
    $events = [];
    $status = 3;

    $node = $this->createMock(Node::class);
    $node->method('hasField')->willReturn(TRUE);
    $node->method('set')->willReturnCallback(function (string $field, $value) use (&$events, &$status, $node) {
      if ($field === 'field_status') {
        $status = (int) $value[0]['target_id'];
        $events[] = 'set_status:' . $status;
      }
      return $node;
    });
    $violations = $this->createMock(EntityConstraintViolationListInterface::class);
    $violations->method('count')->willReturn(0);
    $node->method('validate')->willReturnCallback(function () use (&$events, &$status, $violations) {
      $events[] = 'validate:' . $status;
      return $violations;
    });
    $node->method('save')->willReturnCallback(function () use (&$events) {
      $events[] = 'save';
      return 1;
    });
    $node->method('__get')->willReturn((object) ['value' => 'R-1']);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('create')->willReturn($node);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $statusNote = $this->createMock(FieldItemListInterface::class);
    $statusNote->method('isEmpty')->willReturn(FALSE);
    $paragraph = $this->createMock(Paragraph::class);
    $paragraph->method('get')->willReturn($statusNote);
    $paragraph->method('id')->willReturn(11);
    $paragraph->method('getRevisionId')->willReturn(12);

    $processor = $this->createMock(GeoreportProcessorServiceInterface::class);
    $processor->expects($this->once())->method('prepareNodeProperties')
      ->with([
        'jurisdiction_id' => 5, 'service_code' => 'pothole',
        'media_url' => 'https://images.example.com/fictional.png',
      ], 'create')
      ->willReturn(['type' => 'service_request']);
    $processor->method('resolveJurisdictionId')->willReturn(5);
    $processor->expects($this->once())->method('commitImportedMedia')->willReturnCallback(static function () use (&$events): void {
      $events[] = 'enqueue_imported_media';
    });
    $processor->expects($this->never())->method('discardImportedMedia');
    $processor->expects($this->once())
      ->method('getInitialStatusTid')
      ->with(5)
      ->willReturn(84);
    $processor->method('createStatusNoteParagraph')->willReturn($paragraph);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getDefaultLanguage')->willReturn($language);

    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAnonymous')->willReturn(FALSE);
    $account->method('getRoles')->willReturn(['administrator']);

    $scope = $this->createMock(JurisdictionScopeValidator::class);
    $scope->method('resolveSubmissionJurisdiction')->willReturn(5);
    $visibility = $this->createMock(WorkspaceVisibilityInterface::class);
    $visibility->method('isBlockedForSubmission')->willReturn(FALSE);
    $resource = (new \ReflectionClass(GeoreportRequestIndexResource::class))->newInstanceWithoutConstructor();
    foreach ([
      'entityTypeManager' => $entityTypeManager,
      'georeportProcessor' => $processor,
      'languageManager' => $languageManager,
      'currentUser' => $account,
      'logger' => new NullLogger(),
      'jurisdictionScopeValidator' => $scope,
      'workspaceVisibility' => $visibility,
    ] as $name => $value) {
      (new \ReflectionProperty($resource, $name))->setValue($resource, $value);
    }

    $result = $resource->post([
      'jurisdiction_id' => '5', 'service_code' => 'pothole',
      'media_url' => 'https://images.example.com/fictional.png',
    ]);

    $this->assertSame(['service_requests' => ['request' => ['service_request_id' => 'R-1']]], $result);
    $this->assertSame(['set_status:84', 'validate:84', 'save', 'enqueue_imported_media'], $events);
  }

}
