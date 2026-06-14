<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\taxonomy\TermInterface;
use Drupal\Core\Field\FieldItemListInterface;
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
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
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

}
