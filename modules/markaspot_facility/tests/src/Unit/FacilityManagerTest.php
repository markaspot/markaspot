<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_facility\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_facility\Service\FacilityManager;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests facility validation and service request derivation.
 *
 * @group markaspot_facility
 * @coversDefaultClass \Drupal\markaspot_facility\Service\FacilityManager
 */
class FacilityManagerTest extends UnitTestCase {
  /**
   * Group storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $groupStorage;

  /**
   * Facility manager under test.
   *
   * @var \Drupal\markaspot_facility\Service\FacilityManager
   */
  protected FacilityManager $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
            'group' => $this->groupStorage,
            default => $this->createMock(EntityStorageInterface::class),
      });

    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerInterface::class));

    $this->manager = new FacilityManager($entity_type_manager, $logger_factory);
  }

  /**
   * @covers ::getPublicSettings
   */
  public function testGetPublicSettingsReturnsDefaults(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('isDefaultTranslation')->willReturn(TRUE);
    $group->method('hasField')->with('field_facilities')->willReturn(TRUE);
      // phpcs:disable
      $group->method('get')->with('field_facilities')->willReturn(new class() {
      public function isEmpty(): bool {
        return TRUE;
        }
      });
      // phpcs:enable

    $settings = $this->manager->getPublicSettings($group);

    $this->assertSame([
      'enabled' => FALSE,
      'hideMapPicker' => FALSE,
      'items' => [],
      'mode' => 'disabled',
    ], $settings);
  }

  /**
   * @covers ::getPublicSettings
   */
  public function testGetPublicSettingsFiltersInactiveFacilities(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('isDefaultTranslation')->willReturn(TRUE);
    $group->method('hasField')->with('field_facilities')->willReturn(TRUE);
      // phpcs:disable
      $group->method('get')->with('field_facilities')->willReturn(new class() {
      public string $value;

      public function __construct() {
        $this->value = json_encode([
        'enabled' => TRUE,
        'hideMapPicker' => TRUE,
        'items' => [
        [
        'id' => 'active_office',
        'label' => 'Active Office',
        'lat' => 52.1,
        'lng' => 9.1,
        'active' => TRUE,
        ],
        [
        'id' => 'inactive_office',
        'label' => 'Inactive Office',
        'lat' => 52.2,
        'lng' => 9.2,
        'active' => FALSE,
        ],
        ],
        ]);
        }

      public function isEmpty(): bool {
        return FALSE;
        }
      });
      // phpcs:enable

    $settings = $this->manager->getPublicSettings($group);

    $this->assertCount(1, $settings['items']);
    $this->assertSame('active_office', $settings['items'][0]['id']);
  }

  /**
   * @covers ::getDashboardSettings
   * @dataProvider legacyModeProvider
   */
  public function testGetDashboardSettingsClampsLegacyMode(
    mixed $storedMode,
    bool $enabled,
    string $expected,
  ): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('isDefaultTranslation')->willReturn(TRUE);
    $group->method('hasField')->with('field_facilities')->willReturn(TRUE);
    $stored = ['enabled' => $enabled, 'hideMapPicker' => FALSE, 'items' => []];
    if ($storedMode !== '__absent__') {
      $stored['mode'] = $storedMode;
    }
    $json = json_encode($stored);
      // phpcs:disable
      $group->method('get')->with('field_facilities')->willReturn(new class($json) {
      public string $value;

      public function __construct(string $json) {
        $this->value = $json;
      }

      public function isEmpty(): bool {
        return FALSE;
      }
      });
      // phpcs:enable

    $settings = $this->manager->getDashboardSettings($group);

    $this->assertSame($expected, $settings['mode']);
  }

  /**
   * Provides stored mode values plus the expected clamped result.
   */
  public static function legacyModeProvider(): array {
    return [
      'absent mode + enabled=true → exclusive' => ['__absent__', TRUE, 'exclusive'],
      'absent mode + enabled=false → disabled' => ['__absent__', FALSE, 'disabled'],
      'legacy slug + enabled=true → exclusive' => ['facility_required', TRUE, 'exclusive'],
      'legacy slug + enabled=false → disabled' => ['facility_required', FALSE, 'disabled'],
      'typo + enabled=true → exclusive' => ['exlusive', TRUE, 'exclusive'],
      'empty string + enabled=true → exclusive' => ['', TRUE, 'exclusive'],
      'valid exclusive passes through' => ['exclusive', TRUE, 'exclusive'],
      'valid optional passes through' => ['optional', TRUE, 'optional'],
      'valid disabled passes through' => ['disabled', FALSE, 'disabled'],
      'whitespace around valid value trims' => ['  optional  ', TRUE, 'optional'],
      'non-string mode falls back to enabled' => [123, TRUE, 'exclusive'],
    ];
  }

  /**
   * @covers ::normalizeSubmittedSettings
   */
  public function testNormalizeSubmittedSettingsRejectsDuplicateIds(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('items[1].id must be unique.');

    $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'items' => [
        ['id' => 'school_1', 'label' => 'School 1', 'lat' => 52.1, 'lng' => 9.1],
        ['id' => 'school_1', 'label' => 'School 2', 'lat' => 52.2, 'lng' => 9.2],
      ],
    ]);
  }

  /**
   * @covers ::normalizeSubmittedSettings
   * @dataProvider invalidModeProvider
   */
  public function testNormalizeSubmittedSettingsRejectsInvalidMode(string $mode): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('mode must be one of exclusive, optional, disabled.');

    $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'mode' => $mode,
      'items' => [],
    ]);
  }

  /**
   * Provides mode strings that must be rejected by the normalizer.
   */
  public static function invalidModeProvider(): array {
    return [
      'empty string' => [''],
      'typo' => ['exlusive'],
      'legacy slug' => ['facility_required'],
      'uppercase' => ['Exclusive'],
      'whitespace-only trims to empty' => ['   '],
    ];
  }

  /**
   * @covers ::normalizeSubmittedSettings
   * @dataProvider validModeProvider
   */
  public function testNormalizeSubmittedSettingsAcceptsCanonicalModes(string $mode): void {
    $normalized = $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'mode' => $mode,
      'items' => [],
    ]);

    $this->assertSame($mode, $normalized['mode']);
  }

  /**
   * Provides the three canonical mode values accepted by the normalizer.
   */
  public static function validModeProvider(): array {
    return [
      'exclusive' => ['exclusive'],
      'optional' => ['optional'],
      'disabled' => ['disabled'],
    ];
  }

  /**
   * @covers ::normalizeSubmittedSettings
   */
  public function testNormalizeSubmittedSettingsCanonicalizesPayload(): void {
    $normalized = $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => TRUE,
      'mode' => 'exclusive',
      'label' => [
        'singular' => 'Facility',
        'plural' => 'Facilities',
      ],
      'items' => [
        [
          'id' => 'campus_north',
          'label' => 'Campus North',
          'lat' => '52.5',
          'lng' => '13.4',
          'address' => 'Main Street 1',
        ],
      ],
    ]);

    $this->assertSame(TRUE, $normalized['enabled']);
    $this->assertSame(TRUE, $normalized['hideMapPicker']);
    $this->assertSame('exclusive', $normalized['mode']);
    $this->assertSame('campus_north', $normalized['items'][0]['id']);
    $this->assertSame(52.5, $normalized['items'][0]['lat']);
    $this->assertSame(13.4, $normalized['items'][0]['lng']);
    $this->assertSame(TRUE, $normalized['items'][0]['active']);
  }

  /**
   * @covers ::applyToServiceRequest
   */
  public function testApplyToServiceRequestSetsFacilityLocationAndAddress(): void {
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'hideMapPicker' => FALSE,
        'items' => [
          [
            'id' => 'campus_north',
            'label' => 'Campus North',
            'lat' => 52.5,
            'lng' => 13.4,
            'address' => 'Main Street 1',
            'active' => TRUE,
          ],
        ],
      ]),
    // phpcs:disable
    'field_jurisdiction_address' => new class() {
          public string $country_code = 'DE';
      },
      // phpcs:enable
    ], 14);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

      // phpcs:disable
      $facility_field = new class('campus_north') {
            public function __construct(public string $value) {}

            public function isEmpty(): bool {
              return FALSE;
                }
      };
      $jurisdiction_field = new class() {
            public function isEmpty(): bool {
              return FALSE;
                }

            public function first(): object {
              return (object) ['target_id' => 14];
                }
      };
      $address_field = new class() {
            public function isEmpty(): bool {
              return TRUE;
                }

            public function first(): ?object {
              return NULL;
                }
      };
      // phpcs:enable

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('hasField')
      ->willReturnCallback(fn(string $field) => in_array($field, [
        'field_facility',
        'field_jurisdiction',
        'field_geolocation',
        'field_address',
      ], TRUE));
    $node->method('get')
      ->willReturnCallback(fn(string $field) => match ($field) {
            'field_facility' => $facility_field,
            'field_jurisdiction' => $jurisdiction_field,
            'field_address' => $address_field,
            default => $this->createMock(FieldItemListInterface::class),
      });

    $set_calls = [];
    $node->expects($this->exactly(2))
      ->method('set')
      ->willReturnCallback(function (string $field_name, array $value) use (&$set_calls): void {
            $set_calls[] = [$field_name, $value];
      });

    $this->manager->applyToServiceRequest($node);
    $this->assertSame([
      ['field_geolocation', ['lat' => 52.5, 'lng' => 13.4]],
      ['field_address', ['address_line1' => 'Main Street 1', 'country_code' => 'DE']],
    ], $set_calls);
    $this->assertTrue($this->manager->isAddressLocked($node));
  }

  /**
   * @covers ::applyToServiceRequest
   * @dataProvider nonExclusiveModeProvider
   */
  public function testApplyToServiceRequestSkipsOverwriteOutsideExclusiveMode(string $mode): void {
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => $mode,
        'hideMapPicker' => FALSE,
        'items' => [
          [
            'id' => 'campus_north',
            'label' => 'Campus North',
            'lat' => 52.5,
            'lng' => 13.4,
            'address' => 'Main Street 1',
            'active' => TRUE,
          ],
        ],
      ]),
    ], 14);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

      // phpcs:disable
      $facility_field = new class('campus_north') {
            public function __construct(public string $value) {}

            public function isEmpty(): bool {
              return FALSE;
                }
      };
      $jurisdiction_field = new class() {
            public function isEmpty(): bool {
              return FALSE;
                }

            public function first(): object {
              return (object) ['target_id' => 14];
                }
      };
      // phpcs:enable

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('hasField')
      ->willReturnCallback(fn(string $field) => in_array($field, [
        'field_facility',
        'field_jurisdiction',
        'field_geolocation',
        'field_address',
      ], TRUE));
    $node->method('get')
      ->willReturnCallback(fn(string $field) => match ($field) {
            'field_facility' => $facility_field,
            'field_jurisdiction' => $jurisdiction_field,
            default => $this->createMock(FieldItemListInterface::class),
      });

    $node->expects($this->never())->method('set');

    $this->manager->applyToServiceRequest($node);
  }

  /**
   * Provides non-exclusive modes that must preserve citizen-picked location.
   */
  public static function nonExclusiveModeProvider(): array {
    return [
      'optional mode preserves position' => ['optional'],
      'disabled mode preserves position' => ['disabled'],
    ];
  }

  /**
   * @covers ::isAddressLocked
   * @dataProvider addressLockModeProvider
   */
  public function testIsAddressLockedRespectsJurisdictionMode(
    string $mode,
    bool $expected,
  ): void {
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => $mode,
        'hideMapPicker' => FALSE,
        'items' => [
          [
            'id' => 'campus_north',
            'label' => 'Campus North',
            'lat' => 52.5,
            'lng' => 13.4,
            'address' => 'Main Street 1',
            'active' => TRUE,
          ],
        ],
      ]),
    ], 14);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

      // phpcs:disable
      $facility_field = new class('campus_north') {
            public function __construct(public string $value) {}

            public function isEmpty(): bool {
              return FALSE;
                }
      };
      $jurisdiction_field = new class() {
            public function isEmpty(): bool {
              return FALSE;
                }

            public function first(): object {
              return (object) ['target_id' => 14];
                }
      };
      $address_field = new class() {
            public function isEmpty(): bool {
              return FALSE;
                }

            public function first(): object {
              return (object) ['address_line1' => 'Main Street 1'];
                }
      };
      // phpcs:enable

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('hasField')
      ->willReturnCallback(fn(string $field) => in_array($field, [
        'field_facility',
        'field_jurisdiction',
        'field_address',
      ], TRUE));
    $node->method('get')
      ->willReturnCallback(fn(string $field) => match ($field) {
            'field_facility' => $facility_field,
            'field_jurisdiction' => $jurisdiction_field,
            'field_address' => $address_field,
            default => $this->createMock(FieldItemListInterface::class),
      });

    $this->assertSame($expected, $this->manager->isAddressLocked($node));
  }

  /**
   * Provides facility modes plus expected isAddressLocked result.
   */
  public static function addressLockModeProvider(): array {
    return [
      'exclusive mode locks address' => ['exclusive', TRUE],
      'optional mode leaves address free' => ['optional', FALSE],
      'disabled mode leaves address free' => ['disabled', FALSE],
    ];
  }

  /**
   * Creates a simple group stub with configurable field values.
   */
  private function createMockGroup(array $fields, int $id): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('isDefaultTranslation')->willReturn(TRUE);
    $group->method('hasField')
      ->willReturnCallback(fn(string $name) => array_key_exists($name, $fields));
    $group->method('get')
      ->willReturnCallback(function (string $name) use ($fields) {
            $value = $fields[$name] ?? NULL;
          // phpcs:disable
          return new class($value) {
                public function __construct(private mixed $value) {}

                public function isEmpty(): bool {
              return $this->value === NULL || $this->value === '';
                    }

                public function first(): mixed {
              return $this->value;
                    }

                public function __get(string $name): mixed {
              return $name === 'value' ? $this->value : NULL;
                    }
          };
        // phpcs:enable
      });

    return $group;
  }

}
