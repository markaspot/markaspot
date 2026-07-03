<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Utility\Token;
use Drupal\field\FieldConfigInterface;
use Drupal\markaspot_dashboard\Service\Exception\MailTextsConflictException;
use Drupal\markaspot_dashboard\Service\Exception\MailTextsForbiddenException;
use Drupal\markaspot_dashboard\Service\Exception\MailTextsNotFoundException;
use Drupal\markaspot_dashboard\Service\MailTextsService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests MailTextsService's key validation, catalog, delete guards, preview.
 *
 * @group markaspot_dashboard
 * @coversDefaultClass \Drupal\markaspot_dashboard\Service\MailTextsService
 */
class MailTextsServiceTest extends UnitTestCase {

  /**
   * Mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * Mocked entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityFieldManager;

  /**
   * Mocked token utility.
   *
   * @var \Drupal\Core\Utility\Token|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $token;

  /**
   * Mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Mocked acting user.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $account;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $this->entityFieldManager->method('getFieldDefinitions')->willReturn([]);
    $this->token = $this->createMock(Token::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->account = $this->createMock(AccountInterface::class);
    $this->account->method('id')->willReturn(7);

    // No service_request nodes by default: an empty entity query. Tests that
    // need a sample node call configureNodeStorage() again — a mock's
    // getStorage() must be stubbed exactly once, so this default is only
    // safe because every override below builds a *fresh* mock instead of
    // re-stubbing $this->entityTypeManager.
    $this->configureNodeStorage([]);
  }

  /**
   * Configures node storage on the entity type manager mock.
   *
   * PHPUnit's method()-based stubbing does not reliably support restubbing
   * the same method on the same mock instance, so tests needing a sample
   * node call this to build a fresh EntityTypeManagerInterface mock rather
   * than layering a second getStorage() stub onto the shared one.
   *
   * @param list<int> $ids
   *   Node IDs the query should resolve to.
   * @param \Drupal\node\NodeInterface|null $loadResult
   *   The node storage->load() should return, or NULL to leave it unstubbed.
   */
  protected function configureNodeStorage(array $ids, ?NodeInterface $loadResult = NULL): void {
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->with('node')->willReturn($this->buildNodeStorage($ids, $loadResult));
  }

  /**
   * Builds the service under test.
   */
  protected function buildService(): MailTextsService {
    return new MailTextsService(
      $this->configFactory,
      $this->entityTypeManager,
      $this->entityFieldManager,
      $this->token,
      $this->logger,
      $this->stubTranslation(),
    );
  }

  /**
   * A pass-through TranslationInterface stub that returns the raw string.
   *
   * Avoids bootstrapping the real translation service. StringTranslationTrait
   * ::t() always builds a real TranslatableMarkup itself (it does not call
   * translate() on the injected service), so the only method actually
   * exercised here is translateString() — TranslatableMarkup::render() calls
   * it back on itself to resolve the untranslated source. It must read
   * getUntranslatedString() rather than (string)-cast $translated_string:
   * that cast re-enters __toString() -> render() -> translateString() on the
   * same object, an infinite loop caught only by PHP's stack-size guard.
   */
  protected function stubTranslation(): TranslationInterface {
    return new class() implements TranslationInterface {

      /**
       * {@inheritdoc}
       */
      public function translate($string, array $args = [], array $options = []) {
        // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
        return new TranslatableMarkup($string, $args, $options, $this);
      }

      /**
       * {@inheritdoc}
       */
      public function translateString(TranslatableMarkup $translated_string) {
        return $translated_string->getUntranslatedString();
      }

      /**
       * {@inheritdoc}
       */
      public function formatPlural($count, $singular, $plural, array $args = [], array $options = []) {
        return $count === 1 ? $singular : $plural;
      }

    };
  }

  /**
   * Builds node storage backed by a query returning the given node IDs.
   *
   * @param list<int> $ids
   *   Node IDs the query should resolve to, newest (highest priority) first.
   * @param \Drupal\node\NodeInterface|null $loadResult
   *   The node storage->load() should return, or NULL to leave it unstubbed.
   */
  protected function buildNodeStorage(array $ids, ?NodeInterface $loadResult = NULL): EntityStorageInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->with('type', 'service_request')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->with(0, 1)->willReturnSelf();
    $query->method('execute')->willReturn($ids === [] ? [] : [$ids[0] => $ids[0]]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    if ($loadResult !== NULL) {
      $storage->method('load')->willReturn($loadResult);
    }
    return $storage;
  }

  /**
   * Builds a mocked service_request node with a given request_id.
   */
  protected function buildNode(int $nid, ?string $requestId): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nid);
    if ($requestId === NULL) {
      $node->method('hasField')->with('request_id')->willReturn(FALSE);
      return $node;
    }
    $node->method('hasField')->with('request_id')->willReturn(TRUE);
    $field = new class($requestId) {

      public function __construct(public readonly string $value) {}

      /**
       * Whether the field item is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };
    $node->method('get')->with('request_id')->willReturn($field);
    return $node;
  }

  /**
   * Builds a mocked configurable field definition.
   */
  protected function buildFieldDefinition(string $type, string $label, ?string $targetType = NULL): FieldConfigInterface {
    $definition = $this->createMock(FieldConfigInterface::class);
    $definition->method('getType')->willReturn($type);
    $definition->method('getLabel')->willReturn($label);
    $definition->method('getSetting')->willReturnCallback(
      fn(string $name) => $name === 'target_type' ? $targetType : NULL,
    );
    return $definition;
  }

  /**
   * Builds an editable config mock returning $data for get($key)/getRawData.
   */
  protected function buildEditableConfig(array $data): Config {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(
      fn(string $key) => $data[$key] ?? NULL,
    );
    $config->method('getRawData')->willReturn($data);
    return $config;
  }

  /**
   * @covers ::getCatalog
   */
  public function testGetCatalogFlagsStandardAndCustomKeys(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('getRawData')->willReturn([
      'langcode' => 'en',
      'report_confirmation' => [
        'subject' => 'Received',
        'headline' => '',
        'intro' => '',
        'body_blocks' => [],
        'cta_label' => '',
        'preheader' => '',
      ],
      'status_closed_ampel_sommer' => ['subject' => 'Custom subject'],
    ]);
    $this->configFactory->method('get')->with('markaspot_mail.texts')->willReturn($config);
    $this->configFactory->method('listAll')->willReturn([]);

    $catalog = $this->buildService()->getCatalog();

    self::assertSame(MailTextsService::STANDARD_KEYS, $catalog['standard_keys']);
    self::assertTrue($catalog['texts']['report_confirmation']['standard']);
    self::assertFalse($catalog['texts']['status_closed_ampel_sommer']['standard']);
    self::assertSame('Custom subject', $catalog['texts']['status_closed_ampel_sommer']['subject']);
    // Missing slots on the custom key normalize to '' / [].
    self::assertSame('', $catalog['texts']['status_closed_ampel_sommer']['intro']);
    self::assertSame([], $catalog['texts']['status_closed_ampel_sommer']['body_blocks']);
    // Four curated extras are always present even with no field definitions.
    $tokens = array_column($catalog['tokens'], 'token');
    self::assertContains('[node:request_id]', $tokens);
    self::assertContains('[node:field_status:entity:name]', $tokens);
  }

  /**
   * @covers ::saveText
   * @dataProvider provideInvalidKeys
   */
  public function testSaveTextRejectsInvalidKey(string $key): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->buildService()->saveText($key, [], $this->account);
  }

  /**
   * Invalid key fixtures: too short, uppercase, disallowed characters.
   *
   * @return array<string, array{0: string}>
   *   Test case name => argument list for testSaveTextRejectsInvalidKey().
   */
  public static function provideInvalidKeys(): array {
    return [
      'too short' => ['ab'],
      'uppercase' => ['Status_Open'],
      'space' => ['status open'],
      'dash' => ['status-open'],
      'too long' => [str_repeat('a', 65)],
    ];
  }

  /**
   * @covers ::saveText
   */
  public function testSaveTextRejectsUnknownSlot(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown slot "bogus"');
    $this->buildService()->saveText('custom_key', ['bogus' => 'x'], $this->account);
  }

  /**
   * @covers ::saveText
   */
  public function testSaveTextRejectsOversizedSubject(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('subject exceeds the maximum length');
    $this->buildService()->saveText('custom_key', ['subject' => str_repeat('x', 201)], $this->account);
  }

  /**
   * @covers ::saveText
   */
  public function testSaveTextRejectsOversizedIntro(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('intro exceeds the maximum length');
    $this->buildService()->saveText('custom_key', ['intro' => str_repeat('x', 5001)], $this->account);
  }

  /**
   * @covers ::saveText
   */
  public function testSaveTextRejectsTooManyBodyBlocks(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('body_blocks exceeds the maximum of 20 entries');
    $this->buildService()->saveText('custom_key', ['body_blocks' => array_fill(0, 21, 'x')], $this->account);
  }

  /**
   * @covers ::saveText
   */
  public function testSaveTextRejectsOversizedBodyBlockEntry(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('body_blocks entries exceed the maximum length');
    $this->buildService()->saveText('custom_key', ['body_blocks' => [str_repeat('x', 5001)]], $this->account);
  }

  /**
   * @covers ::saveText
   */
  public function testSaveTextCreatesNewCustomKeyWithEmptyPayload(): void {
    $config = $this->buildEditableConfig([]);
    $config->expects(self::once())->method('set')->with('custom_key', [
      'subject' => '',
      'headline' => '',
      'intro' => '',
      'body_blocks' => [],
      'cta_label' => '',
      'preheader' => '',
    ])->willReturnSelf();
    $config->expects(self::once())->method('save');
    $this->configFactory->method('getEditable')->with('markaspot_mail.texts')->willReturn($config);

    $result = $this->buildService()->saveText('custom_key', [], $this->account);

    self::assertTrue($result['created']);
    self::assertFalse($result['text']['standard']);
    self::assertSame('', $result['text']['subject']);
  }

  /**
   * @covers ::saveText
   */
  public function testSaveTextUpdatesExistingKeyPreservingUntouchedSlots(): void {
    $config = $this->buildEditableConfig([
      'status_open' => [
        'subject' => 'Old subject',
        'headline' => '',
        'intro' => 'Old intro',
        'body_blocks' => ['Old block'],
        'cta_label' => '',
        'preheader' => '',
      ],
    ]);
    $config->expects(self::once())->method('set')->with('status_open', self::callback(
      fn(array $slots): bool => $slots['subject'] === 'New subject' && $slots['intro'] === 'Old intro',
    ))->willReturnSelf();
    $config->expects(self::once())->method('save');
    $this->configFactory->method('getEditable')->with('markaspot_mail.texts')->willReturn($config);

    $result = $this->buildService()->saveText('status_open', ['subject' => 'New subject'], $this->account);

    self::assertFalse($result['created']);
    self::assertTrue($result['text']['standard']);
    self::assertSame('New subject', $result['text']['subject']);
    self::assertSame('Old intro', $result['text']['intro']);
  }

  /**
   * @covers ::deleteText
   */
  public function testDeleteTextThrowsNotFoundForMissingKey(): void {
    $config = $this->buildEditableConfig([]);
    $this->configFactory->method('getEditable')->with('markaspot_mail.texts')->willReturn($config);

    $this->expectException(MailTextsNotFoundException::class);
    $this->buildService()->deleteText('no_such_key', $this->account);
  }

  /**
   * @covers ::deleteText
   */
  public function testDeleteTextThrowsForbiddenForStandardKey(): void {
    $config = $this->buildEditableConfig(['status_open' => ['subject' => 'x']]);
    $this->configFactory->method('getEditable')->with('markaspot_mail.texts')->willReturn($config);

    try {
      $this->buildService()->deleteText('status_open', $this->account);
      self::fail('Expected MailTextsForbiddenException.');
    }
    catch (MailTextsForbiddenException $e) {
      self::assertSame('Standard keys cannot be deleted', $e->getMessage());
    }
  }

  /**
   * @covers ::deleteText
   */
  public function testDeleteTextThrowsConflictWhenReferencedByEca(): void {
    $config = $this->buildEditableConfig(['status_closed_ampel_sommer' => ['subject' => 'x']]);
    $config->expects(self::never())->method('clear');
    $this->configFactory->method('getEditable')->with('markaspot_mail.texts')->willReturn($config);

    $this->configFactory->method('listAll')->with('eca.eca.')->willReturn(['eca.eca.process_ampel']);
    $ecaConfig = $this->createMock(ImmutableConfig::class);
    $ecaConfig->method('getRawData')->willReturn([
      'id' => 'process_ampel',
      'actions' => [
        'Activity_send' => [
          'plugin' => 'markaspot_mail_send_notification',
          'configuration' => ['notification_key' => 'status_closed_ampel_sommer'],
        ],
      ],
    ]);
    $this->configFactory->method('get')->willReturnCallback(
      fn(string $name) => $name === 'eca.eca.process_ampel' ? $ecaConfig : $this->buildEditableConfig([]),
    );

    try {
      $this->buildService()->deleteText('status_closed_ampel_sommer', $this->account);
      self::fail('Expected MailTextsConflictException.');
    }
    catch (MailTextsConflictException $e) {
      self::assertSame(['process_ampel'], $e->getReferencedBy());
    }
  }

  /**
   * @covers ::deleteText
   */
  public function testDeleteTextSucceedsForUnreferencedCustomKey(): void {
    $config = $this->buildEditableConfig(['status_closed_ampel_sommer' => ['subject' => 'x']]);
    $config->expects(self::once())->method('clear')->with('status_closed_ampel_sommer')->willReturnSelf();
    $config->expects(self::once())->method('save');
    $this->configFactory->method('getEditable')->with('markaspot_mail.texts')->willReturn($config);
    $this->configFactory->method('listAll')->with('eca.eca.')->willReturn([]);

    $deleted = $this->buildService()->deleteText('status_closed_ampel_sommer', $this->account);

    self::assertSame('status_closed_ampel_sommer', $deleted);
  }

  /**
   * @covers ::preview
   */
  public function testPreviewReturnsNullSampleRequestIdWhenNoNodeExists(): void {
    $result = $this->buildService()->preview(['subject' => 'Hi [node:request_id]']);

    self::assertNull($result['sample_request_id']);
  }

  /**
   * @covers ::preview
   */
  public function testPreviewResolvesTokensAgainstNewestNode(): void {
    $node = $this->buildNode(42, '486-2026');
    $this->configureNodeStorage([42], $node);
    $this->token->method('replace')->willReturnCallback(
      fn($markup) => str_replace('[node:request_id]', '486-2026', (string) $markup),
    );

    $result = $this->buildService()->preview([
      'subject' => 'Report #[node:request_id]',
      'intro' => 'Intro text',
      'body_blocks' => ['Block one'],
    ]);

    self::assertSame('Report #486-2026', $result['subject']);
    self::assertSame('Intro text', $result['intro']);
    self::assertSame(['Block one'], $result['body_blocks']);
    self::assertSame('486-2026', $result['sample_request_id']);
  }

  /**
   * @covers ::preview
   */
  public function testPreviewOmitsUnprovidedSlots(): void {
    $result = $this->buildService()->preview(['subject' => 'Only subject']);

    self::assertSame('', $result['intro']);
    self::assertSame([], $result['body_blocks']);
  }

  /**
   * @covers ::getCatalog
   */
  public function testTokenCatalogAppliesFieldMappingAndBlocklist(): void {
    // Fresh mock: entityFieldManager already has a getFieldDefinitions()
    // stub from setUp(), and PHPUnit does not reliably support restubbing
    // the same method on the same mock instance (see configureNodeStorage()).
    $this->entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $this->entityFieldManager->method('getFieldDefinitions')->with('node', 'service_request')->willReturn([
      'field_category' => $this->buildFieldDefinition('entity_reference', 'Category', 'taxonomy_term'),
      'field_first_name' => $this->buildFieldDefinition('string', 'First name'),
      'field_notification' => $this->buildFieldDefinition('boolean', 'Notification'),
      'field_jurisdiction' => $this->buildFieldDefinition('entity_reference', 'Jurisdiction', 'group'),
      'field_geolocation' => $this->buildFieldDefinition('geolocation', 'Geolocation'),
      'field_service_provider' => $this->buildFieldDefinition('entity_reference', 'Service provider', 'taxonomy_term'),
    ]);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('getRawData')->willReturn([]);
    $this->configFactory->method('get')->with('markaspot_mail.texts')->willReturn($config);

    $catalog = $this->buildService()->getCatalog();
    $tokens = array_column($catalog['tokens'], 'token');

    self::assertContains('[node:field_category:entity:name]', $tokens);
    self::assertContains('[node:field_first_name]', $tokens);
    self::assertNotContains('[node:field_notification]', $tokens);
    self::assertNotContains('[node:field_jurisdiction:entity:name]', $tokens);
    self::assertNotContains('[node:field_geolocation]', $tokens);
    // Would otherwise slip through as a taxonomy-reference token; blocked
    // explicitly because it is service-provider-internal wording.
    self::assertNotContains('[node:field_service_provider:entity:name]', $tokens);
  }

}
