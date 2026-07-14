<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\AssigneeNotificationBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the person assignment notification mail builder.
 */
#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\AssigneeNotificationBuilder::class)]
#[Group('markaspot_mail')]
final class AssigneeNotificationBuilderTest extends UnitTestCase {

  /**
   * Tests the builder mail type and supported mail key.
   */
  public function testTypeAndSupports(): void {
    $builder = $this->buildBuilder();
    $this->assertSame(MailType::ECA_ASSIGNEE_NOTIFICATION, $builder->getType());
    $this->assertTrue($builder->supports('markaspot_group', 'assignee_notification'));
    $this->assertFalse($builder->supports('markaspot_group', 'org_notification'));
    $this->assertFalse($builder->supports('other', 'assignee_notification'));
  }

  /**
   * Tests missing node context keeps the mail unbranded.
   */
  public function testBuildReturnsNullWithoutNode(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $this->assertNull($this->buildBuilder($logger)->build($this->buildContext([])));
  }

  /**
   * Tests request details and dashboard URL rendering.
   */
  public function testBuildProducesAssigneeNotification(): void {
    $branding = $this->createMock(MailBrandingService::class);
    $branding->expects($this->once())
      ->method('getBranding')
      ->with(18, 'jurisdiction', 'de')
      ->willReturn([
        'frontend_base_url' => 'https://bonn-mobility.example',
        'jurisdiction_slug' => 'bonn',
        'frontend_uses_jurisdiction_path' => TRUE,
      ]);
    $message = $this->buildBuilder(branding: $branding)->build(
      $this->buildContext(['node' => $this->buildNode()], 'de'),
    );

    $this->assertNotNull($message);
    $this->assertSame('Request #7-2026 assigned to you', $message->subject);
    $this->assertSame('card_transactional', $message->variant);
    $this->assertSame('jurisdiction', $message->mode);
    $this->assertSame(18, $message->jurisdictionId);
    $this->assertSame('A citizen request is ready for review.', (string) $message->content['intro']);
    $this->assertSame('Open request', (string) $message->content['cta_label']);
    $this->assertSame('https://bonn-mobility.example/bonn/dashboard/requests/7-2026', $message->content['cta_url']);
    $this->assertContains(['Request' => '#7-2026'], $message->content['features_block']);
    $this->assertContains(['Category' => 'Radbuegel'], $message->content['features_block']);
    $this->assertContains(['Location' => 'Euskirchener Strasse 49'], $message->content['features_block']);
    $this->assertContains('Description: Bitte pruefen.', $message->content['body_blocks']);
  }

  /**
   * Builds a mail context.
   */
  private function buildContext(array $params, string $langcode = 'en'): MailContext {
    return new MailContext(
      module: 'markaspot_group',
      key: 'assignee_notification',
      langcode: $langcode,
      params: $params,
      to: 'assignee@example.com',
    );
  }

  /**
   * Builds the builder with optional test doubles.
   */
  private function buildBuilder(?LoggerInterface $logger = NULL, ?MailBrandingService $branding = NULL): AssigneeNotificationBuilder {
    $builder = new AssigneeNotificationBuilder(
      $logger ?? $this->createMock(LoggerInterface::class),
      $branding ?? $this->createMock(MailBrandingService::class),
    );
    $builder->setStringTranslation($this->getStringTranslationStub());
    return $builder;
  }

  /**
   * Builds a service request node with representative fields.
   */
  private function buildNode(): NodeInterface {
    $jurisdiction = $this->createMock(GroupInterface::class);
    $jurisdiction->method('getEntityTypeId')->willReturn('group');
    $jurisdiction->method('bundle')->willReturn('jur');
    $jurisdiction->method('id')->willReturn(18);

    $category = new class() {

      /**
       * Returns the category label.
       */
      public function label(): string {
        return 'Radbuegel';
      }

    };

    $fields = [
      'field_jurisdiction' => $this->referenceField([$jurisdiction]),
      'request_id' => $this->field('7-2026'),
      'field_category' => $this->referenceField([$category]),
      'field_address' => $this->field('Euskirchener Strasse 49'),
      'body' => $this->field('<p>Bitte pruefen.</p>'),
    ];
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(123);
    $node->method('hasField')
      ->willReturnCallback(static fn(string $fieldName): bool => array_key_exists($fieldName, $fields));
    $node->method('get')
      ->willReturnCallback(static fn(string $fieldName): FieldItemListInterface => $fields[$fieldName]);
    return $node;
  }

  /**
   * Builds a scalar field item list.
   */
  private function field(string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === '');
    $field->method('getString')->willReturn($value);
    return $field;
  }

  /**
   * Builds an entity reference field item list.
   */
  private function referenceField(array $entities): EntityReferenceFieldItemListInterface {
    $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($entities === []);
    $field->method('referencedEntities')->willReturn($entities);
    return $field;
  }

}
