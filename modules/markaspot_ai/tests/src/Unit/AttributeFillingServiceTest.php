<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\media\MediaInterface;
use Drupal\file\FileInterface;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\taxonomy\TermInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\AttributeFillingService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\markaspot_ai\Utility\BlurAdvisory;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the AI-powered attribute filling service.
 *
 * @group markaspot_ai
 * @coversDefaultClass \Drupal\markaspot_ai\Service\AttributeFillingService
 */
class AttributeFillingServiceTest extends UnitTestCase {

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->logger = $this->createMock(LoggerInterface::class);
  }

  /**
   * Creates a service instance with mocked dependencies.
   *
   * @return \Drupal\markaspot_ai\Service\AttributeFillingService
   *   The service instance.
   */
  protected function createService(): AttributeFillingService {
    $aiClient = $this->createMock(AiClientService::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $tokenTracking = $this->createMock(TokenTrackingService::class);
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $database = $this->createMock(Connection::class);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn($language);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_ai.settings')
      ->willReturn($config);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->logger);

    return new AttributeFillingService(
      $aiClient,
      $entityTypeManager,
      $configFactory,
      $loggerFactory,
      $tokenTracking,
      $fileSystem,
      $languageManager,
      $database,
      $this->createMock(StateInterface::class),
      $this->createMock(TimeInterface::class),
    );
  }

  /**
   * Invokes a protected method on an object.
   *
   * @param object $object
   *   The object to invoke the method on.
   * @param string $methodName
   *   The method name.
   * @param array $parameters
   *   The method parameters.
   *
   * @return mixed
   *   The return value of the method.
   */
  protected function invokeMethod(object $object, string $methodName, array $parameters = []): mixed {
    $reflection = new \ReflectionMethod($object, $methodName);
    $reflection->setAccessible(TRUE);
    return $reflection->invokeArgs($object, $parameters);
  }

  /**
   * Legacy notes never enter AI context or displace ten normal remarks.
   *
   * @covers ::buildInternalRemarks
   */
  public function testLegacyRemarksExcludedFromAiContext(): void {
    $remarks = [];
    for ($i = 0; $i < 12; $i++) {
      $created = $this->createMock(FieldItemListInterface::class);
      $created->method('__get')->willReturn('1700000000');
      $text = $this->createMock(FieldItemListInterface::class);
      $text->method('isEmpty')->willReturn(FALSE);
      $text->method('__get')->willReturn('Ordinary remark ' . $i);
      $remark = $this->createMock(ParagraphInterface::class);
      $remark->method('getBehaviorSetting')->willReturn(FALSE);
      $remark->method('hasField')->willReturn(TRUE);
      $remark->method('get')->willReturnMap([
        ['created', $created], ['field_internal_remark_text', $text],
      ]);
      $remarks[] = $remark;
    }
    // Most recent entry: its private text must never even be read.
    $legacy = $this->createMock(ParagraphInterface::class);
    $legacy->method('getBehaviorSetting')
      ->with('markaspot_legacy_notes', 'exclude_from_ai', FALSE)->willReturn(TRUE);
    $legacy->expects($this->never())->method('get');
    $remarks[] = $legacy;
    $items = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $items->method('isEmpty')->willReturn(FALSE);
    $items->method('referencedEntities')->willReturn($remarks);
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_internal_remark')->willReturn(TRUE);
    $node->method('get')->with('field_internal_remark')->willReturn($items);
    $result = $this->invokeMethod($this->createService(), 'buildInternalRemarks', [$node]);
    $this->assertCount(10, explode("\n", $result));
    $this->assertStringContainsString('Ordinary remark 11', $result);
    $this->assertStringContainsString('Ordinary remark 2', $result);
    $this->assertStringNotContainsString('Ordinary remark 0', $result);
  }

  /**
   * Tests valid singlevaluelist response.
   *
   * @covers ::validateResponse
   */
  public function testValidateSingleValueList(): void {
    $service = $this->createService();

    $attributes = [
      [
        'code' => 'size',
        'datatype' => 'singlevaluelist',
        'values' => [
          ['key' => 'small'],
          ['key' => 'medium'],
          ['key' => 'large'],
        ],
      ],
    ];

    $response = ['size' => 'medium'];
    $result = $this->invokeMethod($service, 'validateResponse', [$response, $attributes]);

    $this->assertEquals(['size' => 'medium'], $result);
  }

  /**
   * Tests invalid singlevaluelist value is rejected.
   *
   * @covers ::validateResponse
   */
  public function testValidateSingleValueListInvalid(): void {
    $service = $this->createService();

    $attributes = [
      [
        'code' => 'size',
        'datatype' => 'singlevaluelist',
        'values' => [
          ['key' => 'small'],
          ['key' => 'medium'],
          ['key' => 'large'],
        ],
      ],
    ];

    $response = ['size' => 'enormous'];
    $result = $this->invokeMethod($service, 'validateResponse', [$response, $attributes]);

    $this->assertEmpty($result);
  }

  /**
   * Tests valid multivaluelist response.
   *
   * @covers ::validateResponse
   */
  public function testValidateMultiValueList(): void {
    $service = $this->createService();

    $attributes = [
      [
        'code' => 'materials',
        'datatype' => 'multivaluelist',
        'values' => [
          ['key' => 'asphalt'],
          ['key' => 'concrete'],
          ['key' => 'gravel'],
        ],
      ],
    ];

    $response = ['materials' => ['asphalt', 'concrete']];
    $result = $this->invokeMethod($service, 'validateResponse', [$response, $attributes]);

    $this->assertEquals(['materials' => ['asphalt', 'concrete']], $result);
  }

  /**
   * Tests multivaluelist filters out invalid values.
   *
   * @covers ::validateResponse
   */
  public function testValidateMultiValueListFiltersInvalid(): void {
    $service = $this->createService();

    $attributes = [
      [
        'code' => 'materials',
        'datatype' => 'multivaluelist',
        'values' => [
          ['key' => 'asphalt'],
          ['key' => 'concrete'],
        ],
      ],
    ];

    $response = ['materials' => ['asphalt', 'wood', 'concrete', 'steel']];
    $result = $this->invokeMethod($service, 'validateResponse', [$response, $attributes]);

    $this->assertEquals(['materials' => ['asphalt', 'concrete']], $result);
  }

  /**
   * Tests multivaluelist with single string value (AI sometimes returns this).
   *
   * @covers ::validateResponse
   */
  public function testValidateMultiValueListSingleString(): void {
    $service = $this->createService();

    $attributes = [
      [
        'code' => 'materials',
        'datatype' => 'multivaluelist',
        'values' => [
          ['key' => 'asphalt'],
          ['key' => 'concrete'],
        ],
      ],
    ];

    $response = ['materials' => 'asphalt'];
    $result = $this->invokeMethod($service, 'validateResponse', [$response, $attributes]);

    $this->assertEquals(['materials' => ['asphalt']], $result);
  }

  /**
   * Tests number validation.
   *
   * @covers ::validateResponse
   */
  public function testValidateNumber(): void {
    $service = $this->createService();

    $attributes = [
      ['code' => 'depth', 'datatype' => 'number'],
    ];

    $response = ['depth' => '15.5'];
    $result = $this->invokeMethod($service, 'validateResponse', [$response, $attributes]);

    $this->assertEquals(15.5, $result['depth']);
  }

  /**
   * Tests invalid number is rejected.
   *
   * @covers ::validateResponse
   */
  public function testValidateNumberInvalid(): void {
    $service = $this->createService();

    $attributes = [
      ['code' => 'depth', 'datatype' => 'number'],
    ];

    $response = ['depth' => 'very deep'];
    $result = $this->invokeMethod($service, 'validateResponse', [$response, $attributes]);

    $this->assertEmpty($result);
  }

  /**
   * Tests string validation and sanitization.
   *
   * @covers ::validateResponse
   */
  public function testValidateString(): void {
    $service = $this->createService();

    $attributes = [
      ['code' => 'notes', 'datatype' => 'string'],
    ];

    $response = ['notes' => '  <script>alert("xss")</script>Pothole near corner  '];
    $result = $this->invokeMethod($service, 'validateResponse', [$response, $attributes]);

    $this->assertEquals('alert("xss")Pothole near corner', $result['notes']);
  }

  /**
   * Tests string truncation at 500 characters.
   *
   * @covers ::validateResponse
   */
  public function testValidateStringTruncation(): void {
    $service = $this->createService();

    $attributes = [
      ['code' => 'notes', 'datatype' => 'string'],
    ];

    $longText = str_repeat('a', 600);
    $response = ['notes' => $longText];
    $result = $this->invokeMethod($service, 'validateResponse', [$response, $attributes]);

    $this->assertEquals(500, mb_strlen($result['notes']));
  }

  /**
   * Tests datetime validation accepts ISO 8601.
   *
   * @covers ::validateResponse
   */
  public function testValidateDatetime(): void {
    $service = $this->createService();

    $attributes = [
      ['code' => 'observed_at', 'datatype' => 'datetime'],
    ];

    $response = ['observed_at' => '2024-01-15T10:30:00Z'];
    $result = $this->invokeMethod($service, 'validateResponse', [$response, $attributes]);

    $this->assertEquals('2024-01-15T10:30:00Z', $result['observed_at']);
  }

  /**
   * Tests datetime validation accepts date only.
   *
   * @covers ::validateResponse
   */
  public function testValidateDateOnly(): void {
    $service = $this->createService();

    $attributes = [
      ['code' => 'observed_at', 'datatype' => 'datetime'],
    ];

    $response = ['observed_at' => '2024-01-15'];
    $result = $this->invokeMethod($service, 'validateResponse', [$response, $attributes]);

    $this->assertEquals('2024-01-15', $result['observed_at']);
  }

  /**
   * Tests datetime validation rejects invalid format.
   *
   * @covers ::validateResponse
   */
  public function testValidateDatetimeInvalid(): void {
    $service = $this->createService();

    $attributes = [
      ['code' => 'observed_at', 'datatype' => 'datetime'],
    ];

    $response = ['observed_at' => 'last tuesday'];
    $result = $this->invokeMethod($service, 'validateResponse', [$response, $attributes]);

    $this->assertEmpty($result);
  }

  /**
   * Tests unknown attribute codes are skipped.
   *
   * @covers ::validateResponse
   */
  public function testValidateUnknownCodeSkipped(): void {
    $service = $this->createService();

    $attributes = [
      ['code' => 'size', 'datatype' => 'string'],
    ];

    $response = [
      'size' => 'Large',
      'unknown_field' => 'should be ignored',
    ];

    $result = $this->invokeMethod($service, 'validateResponse', [$response, $attributes]);

    $this->assertArrayHasKey('size', $result);
    $this->assertArrayNotHasKey('unknown_field', $result);
  }

  /**
   * Tests null and empty values are skipped.
   *
   * @covers ::validateResponse
   */
  public function testValidateNullAndEmptySkipped(): void {
    $service = $this->createService();

    $attributes = [
      ['code' => 'notes', 'datatype' => 'string'],
      ['code' => 'depth', 'datatype' => 'number'],
    ];

    $response = [
      'notes' => NULL,
      'depth' => '',
    ];

    $result = $this->invokeMethod($service, 'validateResponse', [$response, $attributes]);

    $this->assertEmpty($result);
  }

  /**
   * Tests attribute schema formatting.
   *
   * @covers ::buildAttributeSchema
   */
  public function testBuildAttributeSchema(): void {
    $service = $this->createService();

    $attributes = [
      [
        'code' => 'damage_type',
        'datatype' => 'singlevaluelist',
        'description' => 'Type of damage',
        'required' => TRUE,
        'values' => [
          ['key' => 'crack', 'name' => 'Crack in surface'],
          ['key' => 'hole', 'name' => 'Hole/pit'],
        ],
      ],
      [
        'code' => 'notes',
        'datatype' => 'string',
        'description' => 'Additional notes',
      ],
    ];

    $result = $this->invokeMethod($service, 'buildAttributeSchema', [$attributes]);

    $this->assertStringContainsString('"damage_type"', $result);
    $this->assertStringContainsString('(singlevaluelist', $result);
    $this->assertStringContainsString('(required)', $result);
    $this->assertStringContainsString('"crack" = Crack in surface', $result);
    $this->assertStringContainsString('"hole" = Hole/pit', $result);
    $this->assertStringContainsString('"notes"', $result);
    $this->assertStringContainsString('(string)', $result);
  }

  /**
   * Tests parsing a service definition with variable attributes.
   *
   * @covers ::parseServiceDefinition
   */
  public function testParseServiceDefinitionVariableOnly(): void {
    $service = $this->createService();

    $term = $this->createMockTerm([
      [
        'code' => 'size',
        'variable' => TRUE,
        'datatype' => 'singlevaluelist',
      ],
      [
        'code' => 'fixed_value',
        'variable' => FALSE,
        'datatype' => 'string',
      ],
      [
        'code' => 'notes',
        'variable' => TRUE,
        'datatype' => 'text',
      ],
    ]);

    $result = $this->invokeMethod($service, 'parseServiceDefinition', [$term]);

    // Only variable attributes should be included.
    $this->assertCount(2, $result);
    $this->assertEquals('size', $result[0]['code']);
    $this->assertEquals('notes', $result[1]['code']);
  }

  /**
   * Tests parsing empty service definition.
   *
   * @covers ::parseServiceDefinition
   */
  public function testParseServiceDefinitionEmpty(): void {
    $service = $this->createService();

    $fieldItem = $this->createMock(FieldItemListInterface::class);
    $fieldItem->method('isEmpty')->willReturn(TRUE);

    $term = $this->createMock(TermInterface::class);
    $term->method('hasField')
      ->with('field_service_definition')
      ->willReturn(TRUE);
    $term->method('get')
      ->with('field_service_definition')
      ->willReturn($fieldItem);

    $result = $this->invokeMethod($service, 'parseServiceDefinition', [$term]);
    $this->assertEmpty($result);
  }

  /**
   * Tests parsing invalid JSON in service definition.
   *
   * @covers ::parseServiceDefinition
   */
  public function testParseServiceDefinitionInvalidJson(): void {
    $service = $this->createService();

    $term = $this->createMockTermWithRawValue('not-json-at-all');

    $result = $this->invokeMethod($service, 'parseServiceDefinition', [$term]);
    $this->assertEmpty($result);
  }

  /**
   * Tests parsing wrapped service definition format.
   *
   * @covers ::parseServiceDefinition
   */
  public function testParseServiceDefinitionWrappedFormat(): void {
    $service = $this->createService();

    $definition = json_encode([
      'attributes' => [
        ['code' => 'size', 'variable' => TRUE],
        ['code' => 'color', 'variable' => TRUE],
      ],
    ]);

    $term = $this->createMockTermWithRawValue($definition);

    $result = $this->invokeMethod($service, 'parseServiceDefinition', [$term]);
    $this->assertCount(2, $result);
  }

  /**
   * Tests translated service definitions are used when requested.
   *
   * @covers ::getVariableAttributes
   */
  public function testGetVariableAttributesUsesRequestedTranslation(): void {
    $service = $this->createService();

    $translated = $this->createMockTermWithRawValue(json_encode([
      'attributes' => [
        ['code' => 'condition', 'variable' => TRUE],
      ],
    ]));

    $term = $this->createMock(TermInterface::class);
    $term->method('hasTranslation')
      ->with('de')
      ->willReturn(TRUE);
    $term->method('getTranslation')
      ->with('de')
      ->willReturn($translated);

    $result = $service->getVariableAttributes($term, 'de');

    $this->assertCount(1, $result);
    $this->assertSame('condition', $result[0]['code']);
  }

  /**
   * Tests empty stored request attribute values are treated as missing.
   *
   * @param string $rawValue
   *   Raw field_request_attributes value.
   * @param bool $expected
   *   Expected result.
   *
   * @dataProvider requestAttributesProvider
   * @covers ::nodeHasRequestAttributes
   */
  public function testNodeHasRequestAttributes(string $rawValue, bool $expected): void {
    $service = $this->createService();
    $node = $this->createMockNodeWithRequestAttributes($rawValue);

    $result = $this->invokeMethod($service, 'nodeHasRequestAttributes', [$node]);

    $this->assertSame($expected, $result);
  }

  /**
   * Data provider for stored request attributes.
   *
   * @return array<string, array{string, bool}>
   *   Raw field values and expected filled state.
   */
  public static function requestAttributesProvider(): array {
    return [
      'empty string' => ['', FALSE],
      'empty array' => ['[]', FALSE],
      'empty object' => ['{}', FALSE],
      'json null' => ['null', FALSE],
      'filled object' => ['{"condition":"broken"}', TRUE],
      'legacy non-json string' => ['condition=broken', TRUE],
    ];
  }

  /**
   * Tests extracting valid keys from attribute values.
   *
   * @covers ::getValidKeys
   */
  public function testGetValidKeys(): void {
    $service = $this->createService();

    $attr = [
      'values' => [
        ['key' => 'small', 'name' => 'Small size'],
        ['key' => 'medium', 'name' => 'Medium size'],
        ['key' => 'large', 'name' => 'Large size'],
      ],
    ];

    $keys = $this->invokeMethod($service, 'getValidKeys', [$attr]);
    $this->assertEquals(['small', 'medium', 'large'], $keys);
  }

  /**
   * Tests getValidKeys with empty values.
   *
   * @covers ::getValidKeys
   */
  public function testGetValidKeysEmpty(): void {
    $service = $this->createService();

    $keys = $this->invokeMethod($service, 'getValidKeys', [['values' => []]]);
    $this->assertEmpty($keys);

    $keys = $this->invokeMethod($service, 'getValidKeys', [[]]);
    $this->assertEmpty($keys);
  }

  /**
   * Creates a mock taxonomy term with service definition.
   *
   * @param array $attributes
   *   The attribute definitions.
   *
   * @return \Drupal\taxonomy\TermInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock term.
   */
  protected function createMockTerm(array $attributes) {
    return $this->createMockTermWithRawValue(json_encode($attributes));
  }

  /**
   * Creates a mock taxonomy term with raw service definition value.
   *
   * @param string $rawValue
   *   The raw JSON value.
   *
   * @return \Drupal\taxonomy\TermInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock term.
   */
  protected function createMockTermWithRawValue(string $rawValue) {
    $fieldItem = new class($rawValue) {

      /**
       * The field value.
       */
      public string $value;

      /**
       * Constructs the field item stub.
       */
      public function __construct(string $value) {
        $this->value = $value;
      }

      /**
       * Checks if the field is empty.
       */
      public function isEmpty(): bool {
        return empty($this->value);
      }

    };

    $term = $this->createMock(TermInterface::class);
    $term->method('hasField')
      ->with('field_service_definition')
      ->willReturn(TRUE);
    $term->method('get')
      ->with('field_service_definition')
      ->willReturn($fieldItem);
    $term->method('hasTranslation')->willReturn(FALSE);

    return $term;
  }

  /**
   * Creates a mock node with request attributes.
   *
   * @param string $rawValue
   *   The raw field_request_attributes value.
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock node.
   */
  protected function createMockNodeWithRequestAttributes(string $rawValue) {
    $fieldItem = new class($rawValue) {

      /**
       * The field value.
       */
      public string $value;

      /**
       * Constructs the field item stub.
       */
      public function __construct(string $value) {
        $this->value = $value;
      }

      /**
       * Checks if the field is empty.
       */
      public function isEmpty(): bool {
        return $this->value === '';
      }

    };

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')
      ->with('field_request_attributes')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('field_request_attributes')
      ->willReturn($fieldItem);

    return $node;
  }

  /**
   * Hosted attribute images are preprocessed and failed blur yields no image.
   */
  public function testBlurForAttributeImages(): void {
    foreach ([FALSE, TRUE] as $fails) {
      $service = $this->getMockBuilder(AttributeFillingService::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['getStyledImagePath', 'parseServiceDefinition', 'getJurisdictionPrompt'])
        ->getMock();
      $service->method('getStyledImagePath')->willReturn('data://text/plain;base64,' . base64_encode('synthetic image'));
      $vision = $this->createMock(ImageProcessingService::class);
      $vision->expects($this->never())->method('isBlurRequired');
      $blur = $vision->expects($this->exactly($fails ? 2 : 1))->method('blurSensitiveAreas')->with('synthetic image', $this->callback('is_string'));
      if ($fails) {
        $blur->willThrowException(new \RuntimeException('Blur unavailable.'));
      }
      else {
        $blur->willReturn(['contents' => 'processed image']);
      }
      (new \ReflectionProperty($service, 'imageProcessing'))->setValue($service, $vision);
      (new \ReflectionProperty($service, 'logger'))->setValue($service, $this->logger);
      (new \ReflectionProperty($service, 'state'))->setValue($service, $this->createMock(StateInterface::class));
      $time = $this->createMock(TimeInterface::class);
      $time->method('getCurrentTime')->willReturn(100000);
      (new \ReflectionProperty($service, 'time'))->setValue($service, $time);
      $config = $this->getConfigFactoryStub(['markaspot_vision.settings' => ['enable_blur_preprocessing' => TRUE]]);
      (new \ReflectionProperty($service, 'configFactory'))->setValue($service, $config);
      $file = $this->createMock(FileInterface::class);
      $file->method('getFileUri')->willReturn('synthetic.png');
      $image_field = $this->createMock(FieldItemListInterface::class);
      $image_field->method('isEmpty')->willReturn(FALSE);
      $image_field->method('__get')->with('entity')->willReturn($file);
      $media = $this->createMock(MediaInterface::class);
      $media->method('hasField')->willReturn(TRUE);
      $media->method('get')->willReturn($image_field);
      $items = $this->createMock(EntityReferenceFieldItemListInterface::class);
      $items->method('isEmpty')->willReturn(FALSE);
      $items->method('referencedEntities')->willReturn([$media]);
      $node = $this->createMock(NodeInterface::class);
      $category = $this->createMock(FieldItemListInterface::class);
      $category->method('__get')->with('entity')->willReturn($this->createMock(TermInterface::class));
      $fields = ['field_request_media', 'field_category'];
      $node->method('hasField')->willReturnCallback(static fn ($field) => in_array($field, $fields, TRUE));
      $node->method('get')->willReturnMap([['field_request_media', $items], ['field_category', $category]]);
      $node->method('getTitle')->willReturn('Synthetic report');
      $images = $service->getNodeImages($node);
      if ($fails) {
        self::assertSame([], $images);
        $service->method('parseServiceDefinition')->willReturn([
          'colour' => ['code' => 'colour', 'datatype' => 'string'],
        ]);
        $service->method('getJurisdictionPrompt')->willReturn('');
        $client = $this->createMock(AiClientService::class);
        $client->method('resolveChatModel')->willReturn('configured');
        $client->expects($this->once())->method('chat')->with($this->callback(static function ($messages): bool {
          self::assertCount(1, $messages[1]['content']);
          self::assertSame('text', $messages[1]['content'][0]['type']);
          return TRUE;
        }), $this->anything())->willReturn([]);
        (new \ReflectionProperty($service, 'aiClient'))->setValue($service, $client);
        $config = $this->getConfigFactoryStub(['markaspot_ai.settings' => []]);
        (new \ReflectionProperty($service, 'configFactory'))->setValue($service, $config);
        self::assertNull($service->fillAttributes($node, TRUE, 'en', FALSE));
      }
      else {
        self::assertSame('data:image/jpeg;base64,' . base64_encode('processed image'), $images[0]['image_url']['url']);
      }
    }
  }

  /**
   * Required blur fails closed before accessing media if Vision is unavailable.
   */
  #[DataProvider('requiredModes')]
  public function testMissingVisionWithRequiredBlur(bool $auto): void {
    $previous = getenv('MARKASPOT_BLUR_REQUIRED');
    $url = getenv('MARKASPOT_BLUR_URL');
    putenv($auto ? 'MARKASPOT_BLUR_REQUIRED' : 'MARKASPOT_BLUR_REQUIRED="true"');
    putenv('MARKASPOT_BLUR_URL=https://platform.example/blur');
    try {
      $service = $this->createService();
      $this->logger->expects($this->once())->method('error')->with($this->callback(static fn ($message) => str_contains($message, 'MARKASPOT_BLUR_REQUIRED') && str_contains($message, 'markaspot_vision')));
      $node = $this->createMock(NodeInterface::class);
      $node->expects($this->never())->method('get');
      self::assertSame([], $service->getNodeImages($node));
    }
    finally {
      putenv($previous === FALSE ? 'MARKASPOT_BLUR_REQUIRED' : 'MARKASPOT_BLUR_REQUIRED=' . $previous);
      putenv($url === FALSE ? 'MARKASPOT_BLUR_URL' : 'MARKASPOT_BLUR_URL=' . $url);
    }
  }

  /**
   * Required modes fail closed even without Vision installed.
   */
  public static function requiredModes(): iterable {
    yield [FALSE];
    yield [TRUE];
  }

  /**
   * Chat image preparation shares the persisted advisory throttle.
   */
  public function testUnprotectedChatAdvisory(): void {
    $env = ['MARKASPOT_BLUR_REQUIRED', 'MARKASPOT_BLUR_URL'];
    $saved = [];
    foreach ($env as $name) {
      $saved[$name] = getenv($name);
      putenv($name);
    }
    try {
      $service = $this->getMockBuilder(AttributeFillingService::class)->disableOriginalConstructor()->onlyMethods(['getStyledImagePath'])->getMock();
      $service->method('getStyledImagePath')->willReturn('data://text/plain;base64,' . base64_encode('synthetic original'));
      (new \ReflectionProperty($service, 'imageProcessing'))->setValue($service, NULL);
      (new \ReflectionProperty($service, 'logger'))->setValue($service, $this->logger);
      $this->logger->expects($this->once())->method('warning')->with(BlurAdvisory::message()->getUntranslatedString());
      $config = $this->getConfigFactoryStub(['markaspot_vision.settings' => ['enable_blur_preprocessing' => FALSE]]);
      (new \ReflectionProperty($service, 'configFactory'))->setValue($service, $config);
      $time = $this->createMock(TimeInterface::class);
      $time->method('getCurrentTime')->willReturn(100000);
      (new \ReflectionProperty($service, 'time'))->setValue($service, $time);
      $last = NULL;
      $state = $this->createMock(StateInterface::class);
      $state->method('get')->with(BlurAdvisory::STATE_KEY)->willReturnCallback(static function () use (&$last) {
        return $last;
      });
      $state->expects($this->once())->method('set')->with(BlurAdvisory::STATE_KEY, 100000)->willReturnCallback(static function ($key, $value) use (&$last) {
        $last = $value;
      });
      (new \ReflectionProperty($service, 'state'))->setValue($service, $state);
      $file = $this->createMock(FileInterface::class);
      $file->method('getFileUri')->willReturn('synthetic.png');
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('__get')->with('entity')->willReturn($file);
      $media = $this->createMock(MediaInterface::class);
      $media->method('hasField')->willReturn(TRUE);
      $media->method('get')->willReturn($field);
      $items = $this->createMock(EntityReferenceFieldItemListInterface::class);
      $items->method('referencedEntities')->willReturn([$media]);
      $node = $this->createMock(NodeInterface::class);
      $node->method('hasField')->willReturn(TRUE);
      $node->method('get')->willReturn($items);
      for ($i = 0; $i < 2; $i++) {
        $images = $service->getNodeImages($node);
        self::assertCount(1, $images);
        self::assertSame('data:image/jpeg;base64,' . base64_encode('synthetic original'), $images[0]['image_url']['url']);
      }
    }
    finally {
      foreach ($saved as $name => $value) {
        putenv($value === FALSE ? $name : "$name=$value");
      }
    }
  }

}
