<?php

namespace Drupal\Tests\markaspot_cap\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\markaspot_cap\Service\CapProcessorService;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the CapProcessorService.
 *
 * Covers nodeToCapAlert() and its private helpers: buildInfoElement(),
 * buildAreaElement(), mapPriorityToSeverity(), getDescription(),
 * getSenderName(), formatAddress(), and formatDateTime().
 *
 * @group markaspot_cap
 * @coversDefaultClass \Drupal\markaspot_cap\Service\CapProcessorService
 */
class CapProcessorServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_cap\Service\CapProcessorService
   */
  protected $service;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    // System.site config.
    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')
      ->willReturnMap([
        ['mail', 'admin@example.com'],
        ['name', 'Test Site'],
      ]);
    $this->configFactory->method('get')
      ->with('system.site')
      ->willReturn($siteConfig);

    $this->service = new CapProcessorService(
      $this->configFactory,
      $this->entityTypeManager,
    );
  }

  /**
   * Tests a basic CAP alert conversion with minimal node data.
   *
   * @covers ::nodeToCapAlert
   */
  public function testNodeToCapAlertBasicStructure(): void {
    $node = $this->createCapNode([
      'request_id' => 'SR-001',
      'title' => 'Pothole on Main Street',
      'created' => 1700000000,
      'category_label' => 'Roads',
      'body' => 'Large pothole near intersection.',
      'name' => 'John Doe',
      'langcode' => 'en',
    ]);

    $alert = $this->service->nodeToCapAlert($node);

    $this->assertEquals('SR-001', $alert['identifier']);
    $this->assertEquals('admin@example.com', $alert['sender']);
    $this->assertEquals('Actual', $alert['status']);
    $this->assertEquals('Alert', $alert['msgType']);
    $this->assertEquals('Public', $alert['scope']);

    // Verify sent timestamp is ISO 8601.
    $this->assertEquals('2023-11-14T22:13:20Z', $alert['sent']);

    // Verify info element.
    $info = $alert['info'];
    $this->assertEquals('Roads', $info['event']);
    $this->assertEquals('Pothole on Main Street', $info['headline']);
    $this->assertEquals('Large pothole near intersection.', $info['description']);
    $this->assertEquals('John Doe', $info['senderName']);
    $this->assertEquals('en', $info['language']);
    $this->assertEquals('Other', $info['category']);
    $this->assertEquals('Expected', $info['urgency']);
    $this->assertEquals('Observed', $info['certainty']);
  }

  /**
   * Tests priority to severity mapping.
   *
   * @covers ::nodeToCapAlert
   *
   * @dataProvider prioritySeverityProvider
   */
  public function testPriorityToSeverityMapping(?int $priority, string $expectedSeverity): void {
    $node = $this->createCapNode([
      'request_id' => 'SR-002',
      'title' => 'Test',
      'created' => 1700000000,
      'category_label' => 'Test',
      'priority' => $priority,
      'langcode' => 'en',
    ]);

    $alert = $this->service->nodeToCapAlert($node);
    $this->assertEquals($expectedSeverity, $alert['info']['severity']);
  }

  /**
   * Data provider for priority to severity mapping.
   *
   * @return array
   *   Test cases: [priority, expectedSeverity].
   */
  public static function prioritySeverityProvider(): array {
    return [
      'critical (0)' => [0, 'Extreme'],
      'high (1)' => [1, 'Severe'],
      'medium (2)' => [2, 'Moderate'],
      'low (3)' => [3, 'Minor'],
      'very low (4)' => [4, 'Minor'],
      'no priority' => [NULL, 'Minor'],
    ];
  }

  /**
   * Tests that geolocation data produces a CAP area element.
   *
   * @covers ::nodeToCapAlert
   */
  public function testNodeToCapAlertWithGeolocation(): void {
    $node = $this->createCapNode([
      'request_id' => 'SR-003',
      'title' => 'Test with location',
      'created' => 1700000000,
      'category_label' => 'Test',
      'langcode' => 'en',
      'lat' => '50.7374',
      'lng' => '7.0982',
      'address_line1' => 'Hauptstrasse 1',
      'address_line2' => '',
      'postal_code' => '53111',
      'locality' => 'Bonn',
    ]);

    $alert = $this->service->nodeToCapAlert($node);
    $this->assertArrayHasKey('area', $alert['info']);
    $this->assertEquals('50.7374,7.0982 0', $alert['info']['area']['circle']);
    $this->assertStringContainsString('Hauptstrasse 1', $alert['info']['area']['areaDesc']);
    $this->assertStringContainsString('Bonn', $alert['info']['area']['areaDesc']);
  }

  /**
   * Tests that missing geolocation does not produce area element.
   *
   * @covers ::nodeToCapAlert
   */
  public function testNodeToCapAlertWithoutGeolocation(): void {
    $node = $this->createCapNode([
      'request_id' => 'SR-004',
      'title' => 'No location',
      'created' => 1700000000,
      'category_label' => 'Test',
      'langcode' => 'en',
    ]);

    $alert = $this->service->nodeToCapAlert($node);
    $this->assertArrayNotHasKey('area', $alert['info']);
  }

  /**
   * Tests fallback description when body is empty.
   *
   * @covers ::nodeToCapAlert
   */
  public function testNodeToCapAlertEmptyDescription(): void {
    $node = $this->createCapNode([
      'request_id' => 'SR-005',
      'title' => 'No body',
      'created' => 1700000000,
      'category_label' => 'Test',
      'langcode' => 'en',
    ]);

    $alert = $this->service->nodeToCapAlert($node);
    $this->assertEquals('', $alert['info']['description']);
  }

  /**
   * Tests fallback sender name when field_name is empty.
   *
   * @covers ::nodeToCapAlert
   */
  public function testNodeToCapAlertAnonymousSenderName(): void {
    $node = $this->createCapNode([
      'request_id' => 'SR-006',
      'title' => 'Anonymous report',
      'created' => 1700000000,
      'category_label' => 'Test',
      'langcode' => 'en',
    ]);

    $alert = $this->service->nodeToCapAlert($node);
    $this->assertEquals('Anonymous', $alert['info']['senderName']);
  }

  /**
   * Tests that unknown category falls back to 'Unknown'.
   *
   * @covers ::nodeToCapAlert
   */
  public function testNodeToCapAlertNoCategoryEntity(): void {
    $node = $this->createCapNode([
      'request_id' => 'SR-007',
      'title' => 'No category entity',
      'created' => 1700000000,
      'category_entity' => NULL,
      'langcode' => 'en',
    ]);

    $alert = $this->service->nodeToCapAlert($node);
    $this->assertEquals('Unknown', $alert['info']['event']);
  }

  /**
   * Tests that HTML is stripped from body text.
   *
   * @covers ::nodeToCapAlert
   */
  public function testNodeToCapAlertStripsHtmlFromBody(): void {
    $node = $this->createCapNode([
      'request_id' => 'SR-008',
      'title' => 'HTML body',
      'created' => 1700000000,
      'category_label' => 'Test',
      'body' => '<p>A <strong>pothole</strong> issue.</p>',
      'langcode' => 'en',
    ]);

    $alert = $this->service->nodeToCapAlert($node);
    $this->assertEquals('A pothole issue.', $alert['info']['description']);
  }

  // ===========================================================================
  // Helper methods.
  // ===========================================================================

  /**
   * Creates a mock node for CAP processing.
   *
   * @param array $config
   *   Configuration with keys:
   *   - request_id: The service request ID.
   *   - title: The node title.
   *   - created: Unix timestamp.
   *   - category_label: Category term label (or NULL).
   *   - category_entity: Explicit NULL to simulate missing entity.
   *   - body: Body text (optional).
   *   - name: Reporter name (optional).
   *   - priority: Priority value 0-4 (optional).
   *   - langcode: Language code.
   *   - lat, lng: Geolocation coordinates (optional).
   *   - address_line1, address_line2, postal_code, locality (optional).
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked node.
   */
  protected function createCapNode(array $config): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('getTitle')->willReturn($config['title']);

    // Language.
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn($config['langcode'] ?? 'en');
    $node->method('language')->willReturn($language);

    // Build fields map.
    $fields = [];

    // request_id field.
    $fields['request_id'] = $this->createFieldStub($config['request_id']);

    // Created field.
    $fields['created'] = $this->createFieldStub($config['created']);

    // field_category with entity.
    if (array_key_exists('category_entity', $config) && $config['category_entity'] === NULL) {
      $fields['field_category'] = new class() {

        /**
         * The referenced entity.
         *
         * @var object|null
         */
        public ?object $entity = NULL;

        /**
         * Checks whether the field item is empty.
         */
        public function isEmpty(): bool {
          return TRUE;
        }

      };
    }
    elseif (isset($config['category_label'])) {
      $categoryTerm = $this->createMock(TermInterface::class);
      $categoryTerm->method('label')->willReturn($config['category_label']);
      $fields['field_category'] = new class($categoryTerm) {

        /**
         * The referenced entity.
         *
         * @var object|null
         */
        public ?object $entity;

        /**
         * Constructs a category field stub.
         */
        public function __construct(object $entity) {
          $this->entity = $entity;
        }

        /**
         * Checks whether the field item is empty.
         */
        public function isEmpty(): bool {
          return FALSE;
        }

      };
    }

    // Body field.
    if (isset($config['body'])) {
      $fields['body'] = $this->createFieldStub($config['body']);
    }

    // field_name.
    if (isset($config['name'])) {
      $fields['field_name'] = $this->createFieldStub($config['name']);
    }

    // field_priority.
    if (isset($config['priority'])) {
      $fields['field_priority'] = $this->createFieldStub($config['priority']);
    }

    // field_geolocation.
    if (isset($config['lat']) && isset($config['lng'])) {
      $geoItem = new class($config['lat'], $config['lng']) {

        /**
         * Latitude value.
         *
         * @var string
         */
        private string $lat;

        /**
         * Longitude value.
         *
         * @var string
         */
        private string $lng;

        /**
         * Constructs a geolocation item stub.
         */
        public function __construct(string $lat, string $lng) {
          $this->lat = $lat;
          $this->lng = $lng;
        }

        /**
         * Gets a sub-value.
         */
        public function get(string $name): object {
          $val = $name === 'lat' ? $this->lat : $this->lng;
          return new class($val) {

            /**
             * The value.
             *
             * @var string
             */
            private string $value;

            /**
             * Constructs a value stub.
             */
            public function __construct(string $value) {
              $this->value = $value;
            }

            /**
             * Returns the value.
             */
            public function getValue(): string {
              return $this->value;
            }

          };
        }

      };

      $geoField = $this->createMock(FieldItemListInterface::class);
      $geoField->method('isEmpty')->willReturn(FALSE);
      $geoField->method('first')->willReturn($geoItem);
      $fields['field_geolocation'] = $geoField;
    }

    // field_address.
    if (isset($config['address_line1'])) {
      $addressParts = [
        'address_line1' => $config['address_line1'] ?? '',
        'address_line2' => $config['address_line2'] ?? '',
        'postal_code' => $config['postal_code'] ?? '',
        'locality' => $config['locality'] ?? '',
      ];

      $addressItem = new class($addressParts) {

        /**
         * Address parts.
         *
         * @var array
         */
        private array $parts;

        /**
         * Constructs an address item stub.
         */
        public function __construct(array $parts) {
          $this->parts = $parts;
        }

        /**
         * Gets a sub-value.
         */
        public function get(string $name): object {
          $val = $this->parts[$name] ?? '';
          return new class($val) {

            /**
             * The value.
             *
             * @var string
             */
            private string $value;

            /**
             * Constructs a value stub.
             */
            public function __construct(string $value) {
              $this->value = $value;
            }

            /**
             * Returns the value.
             */
            public function getValue(): string {
              return $this->value;
            }

          };
        }

      };

      $addressField = $this->createMock(FieldItemListInterface::class);
      $addressField->method('isEmpty')->willReturn(FALSE);
      $addressField->method('first')->willReturn($addressItem);
      $fields['field_address'] = $addressField;
    }

    // Configure hasField and get.
    $node->method('hasField')
      ->willReturnCallback(fn($name) => isset($fields[$name]));
    $node->method('get')
      ->willReturnCallback(function ($name) use ($fields) {
        if (isset($fields[$name])) {
          return $fields[$name];
        }
        $empty = $this->createMock(FieldItemListInterface::class);
        $empty->method('isEmpty')->willReturn(TRUE);
        return $empty;
      });

    return $node;
  }

  /**
   * Creates a simple field stub with a value property.
   *
   * @param mixed $val
   *   The field value.
   *
   * @return object
   *   A field stub object.
   */
  protected function createFieldStub(mixed $val): object {
    return new class($val) {

      /**
       * The field value.
       *
       * @var mixed
       */
      public mixed $value;

      /**
       * Constructs a field stub.
       */
      public function __construct(mixed $value) {
        $this->value = $value;
      }

      /**
       * Checks whether the field item is empty.
       */
      public function isEmpty(): bool {
        return $this->value === NULL || $this->value === '';
      }

    };
  }

}
