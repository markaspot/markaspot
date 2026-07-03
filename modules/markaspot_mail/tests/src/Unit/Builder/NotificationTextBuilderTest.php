<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\NotificationTextBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 *
 */
#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\NotificationTextBuilder::class)]
#[Group('markaspot_mail')]
final class NotificationTextBuilderTest extends UnitTestCase {

  /**
   *
   */
  public function testGetTypeReturnsNotificationConfig(): void {
    $this->assertSame(MailType::NOTIFICATION_CONFIG, $this->buildBuilder()->getType());
  }

  /**
   *
   */
  public function testSupportsOnlyNotificationPrefixedKeys(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_mail', 'notification_report_confirmation'));
    $this->assertTrue($builder->supports('markaspot_mail', 'notification_status_open'));
    $this->assertFalse($builder->supports('markaspot_mail', 'other'));
    $this->assertFalse($builder->supports('other', 'notification_report_confirmation'));
  }

  /**
   *
   */
  public function testBuildReturnsNullWhenNodeMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(logger: $logger);

    $ctx = new MailContext(
      module: 'markaspot_mail',
      key: 'notification_report_confirmation',
      langcode: 'en',
      params: [],
      to: 'citizen@example.com',
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   *
   */
  public function testBuildReturnsNullWhenResolvedSubjectIsEmpty(): void {
    $node = $this->buildPlainNode();

    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolve')->willReturn([
      'subject' => '',
      'headline' => '',
      'intro' => '',
      'body_blocks' => [],
      'cta_label' => '',
      'preheader' => '',
    ]);
    $textResolver->method('replaceTokens')->willReturnArgument(0);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $builder = $this->buildBuilder(textResolver: $textResolver, logger: $logger);
    $ctx = new MailContext(
      module: 'markaspot_mail',
      key: 'notification_report_confirmation',
      langcode: 'en',
      params: ['node' => $node, 'notification_key' => 'report_confirmation'],
      to: 'citizen@example.com',
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   *
   */
  public function testBuildProducesPlatformMailWithoutJurisdiction(): void {
    $node = $this->buildPlainNode();

    $slots = [
      'subject' => 'Your report #42 has been received',
      'headline' => 'We received your report',
      'intro' => 'Your report #42 has been received.',
      'body_blocks' => ['Category: Pothole'],
      'cta_label' => 'View your report',
      'preheader' => 'We received your report.',
    ];
    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolve')->willReturn($slots);
    $textResolver->method('replaceTokens')->willReturn($slots);

    $builder = $this->buildBuilder(textResolver: $textResolver);
    $ctx = new MailContext(
      module: 'markaspot_mail',
      key: 'notification_report_confirmation',
      langcode: 'en',
      params: ['node' => $node, 'notification_key' => 'report_confirmation'],
      to: 'citizen@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('platform', $msg->mode);
    $this->assertNull($msg->jurisdictionId);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertSame('Your report #42 has been received', $msg->subject);
    $this->assertSame('We received your report', $msg->content['headline']);
    $this->assertSame(['Category: Pothole'], $msg->content['body_blocks']);
    $this->assertSame('View your report', $msg->content['cta_label']);
    $this->assertSame('https://example.com/en/requests/42', $msg->content['cta_url']);
  }

  /**
   *
   */
  public function testBuildResolvesJurisdictionModeFromNode(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(7);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('bundle')->willReturn('jur');

    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', TRUE],
    ]);
    $node->method('get')->willReturnMap([
      ['field_jurisdiction', $jurisdictionField],
    ]);

    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('https://example.com/en/requests/42');
    $node->method('toUrl')->willReturn($url);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $node->method('language')->willReturn($language);

    $slots = [
      'subject' => 'Update on your report #42',
      'headline' => '',
      'intro' => '',
      'body_blocks' => [],
      'cta_label' => '',
      'preheader' => '',
    ];
    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolve')->willReturn($slots);
    $textResolver->method('replaceTokens')->willReturn($slots);

    $builder = $this->buildBuilder(textResolver: $textResolver);
    $ctx = new MailContext(
      module: 'markaspot_mail',
      key: 'notification_status_open',
      langcode: 'en',
      params: ['node' => $node, 'notification_key' => 'status_open'],
      to: 'citizen@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('jurisdiction', $msg->mode);
    $this->assertSame(7, $msg->jurisdictionId);
    $this->assertArrayNotHasKey('cta_label', $msg->content);
    $this->assertArrayNotHasKey('cta_url', $msg->content);
  }

  /**
   * Builds a mock node with hasField() = FALSE and a fixed canonical URL.
   */
  private function buildPlainNode(): NodeInterface&MockObject {
    $emptyField = $this->createMock(FieldItemListInterface::class);
    $emptyField->method('isEmpty')->willReturn(TRUE);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(FALSE);

    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('https://example.com/en/requests/42');
    $node->method('toUrl')->willReturn($url);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $node->method('language')->willReturn($language);

    return $node;
  }

  /**
   * Builds the builder with default or injected mocks.
   */
  private function buildBuilder(
    ?MailTextResolver $textResolver = NULL,
    ?LoggerInterface $logger = NULL,
  ): NotificationTextBuilder {
    if ($textResolver === NULL) {
      $textResolver = $this->createMock(MailTextResolver::class);
      $textResolver->method('resolve')->willReturn([
        'subject' => '',
        'headline' => '',
        'intro' => '',
        'body_blocks' => [],
        'cta_label' => '',
        'preheader' => '',
      ]);
      $textResolver->method('replaceTokens')->willReturnArgument(0);
    }
    return new NotificationTextBuilder(
      $textResolver,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

}
