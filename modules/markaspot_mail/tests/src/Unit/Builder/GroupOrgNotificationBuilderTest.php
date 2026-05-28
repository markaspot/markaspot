<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\GroupOrgNotificationBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the organisation assignment notification mail builder.
 */
#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\GroupOrgNotificationBuilder::class)]
#[Group('markaspot_mail')]
final class GroupOrgNotificationBuilderTest extends UnitTestCase {

  /**
   * Tests the builder mail type.
   */
  public function testGetTypeReturnsEcaGroupOrgNotification(): void {
    $this->assertSame(MailType::ECA_GROUP_ORG_NOTIFICATION, $this->buildBuilder()->getType());
  }

  /**
   * Tests the builder claims only organisation assignment notifications.
   */
  public function testSupportsOnlyOrgNotification(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_group', 'org_notification'));
    $this->assertFalse($builder->supports('markaspot_group', 'member_invitation'));
    $this->assertFalse($builder->supports('other', 'org_notification'));
  }

  /**
   * Tests missing node or organisation context keeps the mail unbranded.
   */
  public function testBuildReturnsNullOnMissingParams(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder($logger);
    $this->assertNull($builder->build($this->buildContext(['subject' => 'x'])));
  }

  /**
   * Tests the builder resolves jurisdiction mode and dashboard links.
   */
  public function testBuildProducesJurisdictionCardFromNodeAndOrganisation(): void {
    $branding = $this->createMock(MailBrandingService::class);
    $branding->expects($this->once())
      ->method('getBranding')
      ->with(18, 'jurisdiction', 'de')
      ->willReturn([
        'frontend_base_url' => 'https://bonn-mobility.example',
        'jurisdiction_slug' => 'bonn',
        'frontend_uses_jurisdiction_path' => TRUE,
      ]);
    $builder = $this->buildBuilder(branding: $branding);
    $ctx = $this->buildContext([
      'node' => $this->buildNode(),
      'organisation' => $this->buildOrganisation(),
    ], 'de');
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertSame('jurisdiction', $msg->mode);
    $this->assertSame(18, $msg->jurisdictionId);
    $this->assertSame('Request #7-2026 assigned to Tiefbauamt', $msg->subject);
    $this->assertSame('A citizen request is ready for review.', (string) $msg->content['intro']);
    $this->assertSame('Open request', (string) $msg->content['cta_label']);
    $this->assertSame('https://bonn-mobility.example/bonn/dashboard/requests/7-2026', $msg->content['cta_url']);
    $this->assertContains(['Category' => 'Radbuegel'], $msg->content['features_block']);
    $this->assertContains(['Location' => 'Euskirchener Strasse 49'], $msg->content['features_block']);
    $this->assertContains(['Organisation' => 'Tiefbauamt'], $msg->content['features_block']);
  }

  /**
   * Tests tenant frontend bases are not prefixed with the jurisdiction slug.
   */
  public function testBuildOmitsSlugPrefixWhenFrontendBaseIsTenantScoped(): void {
    $branding = $this->createMock(MailBrandingService::class);
    $branding->expects($this->once())
      ->method('getBranding')
      ->with(18, 'jurisdiction', 'de')
      ->willReturn([
        'frontend_base_url' => 'https://bonn-mobility.example',
        'jurisdiction_slug' => 'bonn',
        'frontend_uses_jurisdiction_path' => FALSE,
      ]);
    $builder = $this->buildBuilder(branding: $branding);
    $ctx = $this->buildContext([
      'node' => $this->buildNode(),
      'organisation' => $this->buildOrganisation(),
    ], 'de');
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('https://bonn-mobility.example/dashboard/requests/7-2026', $msg->content['cta_url']);
  }

  /**
   * Builds a MailContext with sensible test defaults.
   */
  private function buildContext(array $params, string $langcode = 'en'): MailContext {
    return new MailContext(
      module: 'markaspot_group',
      key: 'org_notification',
      langcode: $langcode,
      params: $params,
      to: 'head@org.example',
    );
  }

  /**
   * Builds the builder with optional test doubles.
   */
  private function buildBuilder(?LoggerInterface $logger = NULL, ?MailBrandingService $branding = NULL): GroupOrgNotificationBuilder {
    $builder = new GroupOrgNotificationBuilder(
      $logger ?? $this->createMock(LoggerInterface::class),
      $branding ?? $this->createMock(MailBrandingService::class),
    );
    $builder->setStringTranslation($this->getStringTranslationStub());
    return $builder;
  }

  /**
   * Builds a service request node with jurisdiction and display fields.
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
      'request_id' => $this->field([['value' => '7-2026']], ['value' => '7-2026']),
      'field_category' => $this->referenceField([$category]),
      'field_address' => $this->field([['value' => 'Euskirchener Strasse 49']], ['value' => 'Euskirchener Strasse 49']),
      'body' => $this->field([['value' => 'Bitte pruefen.']], ['value' => 'Bitte pruefen.']),
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
   * Builds an organisation group.
   */
  private function buildOrganisation(): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('label')->willReturn('Tiefbauamt');
    return $group;
  }

  /**
   * Builds a scalar field item list.
   */
  private function field(array $values, array $properties = []): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($values === []);
    $field->method('getString')
      ->willReturn((string) ($properties['value'] ?? ''));
    $field->method('__get')
      ->willReturnCallback(static fn(string $property): mixed => $properties[$property] ?? NULL);
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
