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
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
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
    $this->assertSame('Request #7-2026 assigned to you', (string) $message->content['headline']);
    $this->assertSame('Request #7-2026 was assigned to you', (string) $message->content['preheader']);
    $this->assertSame('card_transactional', $message->variant);
    $this->assertSame('jurisdiction', $message->mode);
    $this->assertSame(18, $message->jurisdictionId);
    $this->assertSame('A citizen request is ready for review.', (string) $message->content['intro']);
    $this->assertSame('Open request', (string) $message->content['cta_label']);
    $this->assertSame('https://bonn-mobility.example/bonn/dashboard/requests/7-2026', $message->content['cta_url']);
    $this->assertSame([
      ['Request' => '#7-2026'],
      ['Category' => 'Radbuegel'],
      ['Location' => 'Euskirchener Strasse 49, 53113 Bonn, NRW, DE'],
    ], $message->content['features_block']);
    $this->assertSame([
      'This request has been assigned to you for processing.',
      'Description: Bitte pruefen.',
    ], $message->content['body_blocks']);
    $this->assertStringNotContainsString('plain_text', implode(' ', $message->content['body_blocks']));
  }

  /**
   * Tests all editable slots, Drupal tokens, and assignment placeholders.
   */
  public function testBuildUsesResolvedOverridesAndKeepsGeneratedDetails(): void {
    $node = $this->buildNode();
    $resolver = $this->buildTextResolver([
      'subject' => 'Custom {{ assignee }} #{{ request_id }} [node:title]',
      'headline' => 'Handle {{ assignee }}',
      'intro' => 'Review [node:title] #{{ request_id }}.',
      'body_blocks' => ['Assigned to {{ assignee }} for [node:title].'],
      'cta_label' => 'Open #{{ request_id }}',
      'preheader' => '{{ assignee }} received [node:title].',
    ], $node);
    $branding = $this->createMock(MailBrandingService::class);
    $branding->method('getBranding')->willReturn([
      'frontend_base_url' => 'https://example.test',
      'frontend_uses_jurisdiction_path' => FALSE,
    ]);
    $assignee = $this->createMock(UserInterface::class);
    $assignee->method('getDisplayName')->willReturn('Alex & Kim');

    $message = $this->buildBuilder(branding: $branding, textResolver: $resolver)->build(
      $this->buildContext(['node' => $node, 'assignee' => $assignee]),
    );

    $this->assertNotNull($message);
    $this->assertSame('Custom Alex & Kim #7-2026 Broken lamp', $message->subject);
    $this->assertSame('Handle Alex & Kim', (string) $message->content['headline']);
    $this->assertSame('Review Broken lamp #7-2026.', (string) $message->content['intro']);
    $this->assertSame('Alex & Kim received Broken lamp.', (string) $message->content['preheader']);
    $this->assertSame('Open #7-2026', (string) $message->content['cta_label']);
    $this->assertSame([
      'Assigned to Alex &amp; Kim for Broken lamp.',
      'Description: Bitte pruefen.',
    ], $message->content['body_blocks']);
    $this->assertContains(['Request' => '#7-2026'], $message->content['features_block']);
  }

  /**
   * Tests final raw-slot filtering after token and context replacement.
   */
  public function testBuildFiltersDynamicValuesInRawHtmlSlots(): void {
    $node = $this->buildNode();
    $resolver = $this->buildTextResolver([
      'intro' => '<a href="{{ assignee }}">Assignee</a> <a href="[node:title]">Token</a>',
      'body_blocks' => ['<a href="{{ assignee }}">Assignee</a> <a href="[node:title]">Token</a>'],
    ], $node, 'javascript:alert(1)');
    $assignee = $this->createMock(UserInterface::class);
    $assignee->method('getDisplayName')->willReturn('javascript:alert(2)');

    $message = $this->buildBuilder(textResolver: $resolver)->build(
      $this->buildContext(['node' => $node, 'assignee' => $assignee]),
    );

    $this->assertNotNull($message);
    $this->assertStringNotContainsString('javascript:', (string) $message->content['intro']);
    $this->assertStringNotContainsString('javascript:', (string) $message->content['body_blocks'][0]);
  }

  /**
   * Tests a subject-only override leaves every other slot at its old default.
   */
  public function testBuildUsesPartialSubjectOverrideWithOtherSlotsUnchanged(): void {
    $node = $this->buildNode();
    $resolver = $this->buildTextResolver([
      'subject' => 'Queue #{{ request_id }} for {{ assignee }}',
    ], $node);
    $branding = $this->createMock(MailBrandingService::class);
    $branding->method('getBranding')->willReturn([
      'frontend_base_url' => 'https://example.test',
      'frontend_uses_jurisdiction_path' => FALSE,
    ]);
    $assignee = $this->createMock(UserInterface::class);
    $assignee->method('getDisplayName')->willReturn('Alex Example');

    $message = $this->buildBuilder(branding: $branding, textResolver: $resolver)->build(
      $this->buildContext(['node' => $node, 'assignee' => $assignee]),
    );

    $this->assertNotNull($message);
    $this->assertSame('Queue #7-2026 for Alex Example', $message->subject);
    $this->assertSame('Request #7-2026 assigned to you', (string) $message->content['headline']);
    $this->assertSame('Request #7-2026 was assigned to you', (string) $message->content['preheader']);
    $this->assertSame('A citizen request is ready for review.', (string) $message->content['intro']);
    $this->assertSame('Open request', (string) $message->content['cta_label']);
    $this->assertSame([
      'This request has been assigned to you for processing.',
      'Description: Bitte pruefen.',
    ], $message->content['body_blocks']);
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
  private function buildBuilder(
    ?LoggerInterface $logger = NULL,
    ?MailBrandingService $branding = NULL,
    ?MailTextResolver $textResolver = NULL,
  ): AssigneeNotificationBuilder {
    $textResolver ??= $this->buildTextResolver([]);
    $builder = new AssigneeNotificationBuilder(
      $logger ?? $this->createMock(LoggerInterface::class),
      $branding ?? $this->createMock(MailBrandingService::class),
      $textResolver,
    );
    $builder->setStringTranslation($this->getStringTranslationStub());
    return $builder;
  }

  /**
   * Builds a resolver double for one complete or partial slot set.
   */
  private function buildTextResolver(
    array $overrides,
    ?NodeInterface $expectedNode = NULL,
    string $tokenValue = 'Broken lamp',
  ): MailTextResolver {
    $slots = $overrides + [
      'subject' => '',
      'headline' => '',
      'intro' => '',
      'body_blocks' => [],
      'cta_label' => '',
      'preheader' => '',
    ];
    $resolver = $this->createMock(MailTextResolver::class);
    $resolver->method('resolve')
      ->with('markaspot_mail.texts', 'assignee_notification', $this->anything())
      ->willReturn($slots);
    $resolver->method('resolveField')
      ->willReturnCallback(
        static fn(string $configName, string $key, string $field): string => (string) $slots[$field],
      );
    $replaceTokens = $expectedNode === NULL
      ? $resolver->method('replaceTokens')
      : $resolver->expects($this->once())->method('replaceTokens');
    $replaceTokens
      ->with(
        $this->callback(static fn(array $resolved): bool => $resolved['subject'] === $slots['subject']),
        $this->callback(
          static fn(array $data): bool => $expectedNode === NULL || ($data['node'] ?? NULL) === $expectedNode,
        ),
        $this->anything(),
      )
      ->willReturnCallback(static function (array $resolved) use ($tokenValue): array {
        foreach (['subject', 'headline', 'intro', 'cta_label', 'preheader'] as $slot) {
          $resolved[$slot] = str_replace('[node:title]', $tokenValue, $resolved[$slot]);
        }
        $resolved['body_blocks'] = array_map(
          static fn(string $block): string => str_replace('[node:title]', $tokenValue, $block),
          $resolved['body_blocks'],
        );
        return $resolved;
      });
    return $resolver;
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
      'request_id' => $this->field([['value' => '7-2026']], ['value' => '7-2026']),
      'field_category' => $this->referenceField([$category]),
      'field_address' => $this->field(
        [[
          'address_line1' => 'Euskirchener Strasse 49',
          'address_line2' => '',
          'postal_code' => '53113',
          'locality' => 'Bonn',
          'administrative_area' => 'NRW',
          'country_code' => 'DE',
        ]],
        [],
        'address-property-leak',
      ),
      'body' => $this->field(
        [['value' => '<p>Bitte pruefen.</p>', 'format' => 'plain_text']],
        ['value' => '<p>Bitte pruefen.</p>'],
        '<p>Bitte pruefen.</p>, plain_text',
      ),
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
  private function field(
    array $values,
    array $properties = [],
    ?string $stringValue = NULL,
  ): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($values === []);
    $field->method('getValue')->willReturn($values);
    $field->method('getString')->willReturn($stringValue ?? (string) ($properties['value'] ?? ''));
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
