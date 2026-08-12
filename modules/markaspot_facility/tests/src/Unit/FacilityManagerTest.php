<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_facility\Unit;

use CommerceGuys\Addressing\Country\CountryRepositoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_facility\Service\FacilityManager;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
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
   * Facility entity storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $facilityStorage;

  /**
   * Facility category entity storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $facilityCategoryStorage;

  /**
   * Country repository mock.
   *
   * @var \CommerceGuys\Addressing\Country\CountryRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected CountryRepositoryInterface $countryRepository;

  /**
   * Database connection mock.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Connection $database;

  /**
   * Jurisdiction hierarchy resolver mock.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * Feature entitlement resolver mock.
   *
   * @var \Drupal\markaspot_nuxt\Service\FeatureScopeResolver|\PHPUnit\Framework\MockObject\MockObject
   */
  protected FeatureScopeResolver $featureScopeResolver;

  /**
   * Facility entitlement returned by the resolver mock.
   */
  protected bool $facilityEntitled = TRUE;

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
    $this->facilityStorage = $this->createMock(EntityStorageInterface::class);
    $facility_query = $this->createMock(QueryInterface::class);
    $facility_query->method('accessCheck')->willReturnSelf();
    $facility_query->method('condition')->willReturnSelf();
    $facility_query->method('sort')->willReturnSelf();
    $facility_query->method('execute')->willReturn([]);
    $this->facilityStorage->method('getQuery')->willReturn($facility_query);
    $this->facilityStorage->method('loadMultiple')->willReturn([]);

    $this->facilityCategoryStorage = $this->createMock(EntityStorageInterface::class);
    $category_query = $this->createMock(QueryInterface::class);
    $category_query->method('accessCheck')->willReturnSelf();
    $category_query->method('condition')->willReturnSelf();
    $category_query->method('sort')->willReturnSelf();
    $category_query->method('execute')->willReturn([]);
    $this->facilityCategoryStorage->method('getQuery')->willReturn($category_query);
    $this->facilityCategoryStorage->method('loadMultiple')->willReturn([]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')
      ->willReturnCallback(static fn(string $type): bool => in_array($type, [
        'markaspot_facility',
        'markaspot_facility_category',
      ], TRUE));
    $entity_type_manager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
            'group' => $this->groupStorage,
            'markaspot_facility' => $this->facilityStorage,
            'markaspot_facility_category' => $this->facilityCategoryStorage,
            default => $this->createMock(EntityStorageInterface::class),
      });

    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerInterface::class));

    $this->countryRepository = $this->createMock(CountryRepositoryInterface::class);
    $this->countryRepository->method('getList')->willReturn([
      'DE' => 'Germany',
      'NL' => 'Netherlands',
      'GB' => 'United Kingdom',
    ]);

    $this->database = $this->createMock(Connection::class);
    $this->database->method('startTransaction')
      ->willReturn($this->transaction());

    $this->hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $this->featureScopeResolver = $this->createMock(FeatureScopeResolver::class);
    $this->featureScopeResolver->method('isEnabledEffective')
      ->willReturnCallback(fn(): bool => $this->facilityEntitled);

    $this->manager = new FacilityManager(
      $entity_type_manager,
      $logger_factory,
      $this->countryRepository,
      $this->database,
      $this->hierarchyResolver,
      $this->featureScopeResolver,
    );
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
      'categories' => [],
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
   * @covers ::getPublicSettings
   * @covers ::hasEntitlement
   */
  public function testFacilityEntitlementForcesSettingsOff(): void {
    $this->facilityEntitled = FALSE;
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => 'exclusive',
        'hideMapPicker' => TRUE,
        'items' => [
          [
            'id' => 'premium_site',
            'label' => 'Premium Site',
            'lat' => 52.1,
            'lng' => 9.1,
            'active' => TRUE,
          ],
        ],
      ]),
    ], 14);

    $dashboard = $this->manager->getDashboardSettings($group);
    $this->assertFalse($dashboard['enabled']);
    $this->assertSame('disabled', $dashboard['mode']);
    $this->assertSame(['premium_site'], array_column($dashboard['items'], 'id'));

    $public = $this->manager->getPublicSettings($group);
    $this->assertFalse($public['enabled']);
    $this->assertSame('disabled', $public['mode']);
    $this->assertSame([], $public['items']);
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
   * @covers ::normalizeSubmittedSettings
   */
  public function testNormalizeSubmittedSettingsCanonicalizesFacilityCategories(): void {
    $normalized = $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'categories' => [
        [
          'id' => 'playgrounds',
          'label' => 'Playgrounds',
          'icon' => 'lucide:ferris-wheel',
          'weight' => 17,
        ],
      ],
      'items' => [
        [
          'id' => 'park_north',
          'label' => 'Park North',
          'lat' => 51.3,
          'lng' => 6.6,
          'categoryId' => 'playgrounds',
        ],
      ],
    ]);

    $this->assertSame([
      'id' => 'playgrounds',
      'label' => 'Playgrounds',
      'icon' => 'i-lucide-ferris-wheel',
      'weight' => 17,
    ], $normalized['categories'][0]);
    $this->assertSame('playgrounds', $normalized['items'][0]['categoryId']);
  }

  /**
   * @covers ::normalizeSubmittedSettings
   */
  public function testNormalizeSubmittedSettingsRejectsInvalidCategoryWeight(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('categories[0].weight must be an integer between -10000 and 10000.');

    $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'categories' => [[
        'id' => 'playgrounds',
        'label' => 'Playgrounds',
        'icon' => 'i-lucide-ferris-wheel',
        'weight' => '1',
      ]],
      'items' => [],
    ]);
  }

  /**
   * @covers ::normalizeSubmittedSettings
   */
  public function testNormalizeSubmittedSettingsRejectsUnknownFacilityCategory(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('items[0].categoryId must reference a submitted facility category.');

    $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'categories' => [],
      'items' => [
        [
          'id' => 'park_north',
          'label' => 'Park North',
          'lat' => 51.3,
          'lng' => 6.6,
          'categoryId' => 'other-tenant',
        ],
      ],
    ]);
  }

  /**
   * @covers ::normalizeSubmittedSettings
   */
  public function testNormalizeSubmittedSettingsRejectsNonLucideCategoryIcon(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('categories[0].icon must be an i-lucide-* or lucide:* icon name.');

    $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'categories' => [
        [
          'id' => 'playgrounds',
          'label' => 'Playgrounds',
          'icon' => 'fa-ferris-wheel',
        ],
      ],
      'items' => [],
    ]);
  }

  /**
   * Facility category keys use the same hyphenated contract as the dashboard.
   *
   * @covers ::normalizeSubmittedSettings
   */
  public function testNormalizeSubmittedSettingsRejectsUnderscoreInCategoryKey(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('categories[0].id must contain only lowercase letters, numbers, and hyphens.');

    $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'categories' => [
        [
          'id' => 'school_types',
          'label' => 'School types',
          'icon' => 'i-lucide-school',
        ],
      ],
      'items' => [],
    ]);
  }

  /**
   * Existing facility icon overrides keep their legacy text-only contract.
   *
   * @covers ::normalizeSubmittedSettings
   */
  public function testNormalizeSubmittedSettingsPreservesLegacyFacilityIcon(): void {
    $normalized = $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'categories' => [],
      'items' => [
        [
          'id' => 'legacy-school',
          'label' => 'Legacy school',
          'lat' => 51.3,
          'lng' => 6.5,
          'icon' => 'fa-trash',
        ],
      ],
    ]);

    self::assertSame('fa-trash', $normalized['items'][0]['icon']);
  }

  /**
   * @covers ::normalizeSubmittedSettings
   */
  public function testNormalizeSubmittedSettingsAcceptsStructuredAddress(): void {
    $normalized = $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'items' => [
        [
          'id' => 'campus_north',
          'label' => 'Campus North',
          'lat' => 52.5,
          'lng' => 13.4,
          'address' => [
            'address_line1' => 'Hauptstrasse 12',
            'country_code' => 'de',
            'locality' => 'Berlin',
            'postal_code' => '10117',
          ],
        ],
      ],
    ]);

    $this->assertSame([
      'address_line1' => 'Hauptstrasse 12',
      'country_code' => 'DE',
      'locality' => 'Berlin',
      'postal_code' => '10117',
    ], $normalized['items'][0]['address']);
  }

  /**
   * @covers ::normalizeSubmittedSettings
   */
  public function testNormalizeSubmittedSettingsPreservesLegacyStringAddress(): void {
    $normalized = $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'items' => [
        [
          'id' => 'campus_north',
          'label' => 'Campus North',
          'lat' => 52.5,
          'lng' => 13.4,
          'address' => 'Main Street 1',
        ],
      ],
    ]);

    $this->assertSame('Main Street 1', $normalized['items'][0]['address']);
  }

  /**
   * @covers ::normalizeSubmittedSettings
   */
  public function testNormalizeSubmittedSettingsDropsEmptyOptionalAddressKeys(): void {
    $normalized = $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'items' => [
        [
          'id' => 'campus_north',
          'label' => 'Campus North',
          'lat' => 52.5,
          'lng' => 13.4,
          'address' => [
            'address_line1' => 'Hauptstrasse 12',
            'country_code' => '',
            'locality' => '   ',
            'postal_code' => NULL,
          ],
        ],
      ],
    ]);

    $this->assertSame([
      'address_line1' => 'Hauptstrasse 12',
    ], $normalized['items'][0]['address']);
  }

  /**
   * @covers ::normalizeSubmittedSettings
   * @dataProvider invalidStructuredAddressProvider
   */
  public function testNormalizeSubmittedSettingsRejectsInvalidStructuredAddress(
    mixed $address,
    string $expectedMessage,
  ): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($expectedMessage);

    $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'items' => [
        [
          'id' => 'campus_north',
          'label' => 'Campus North',
          'lat' => 52.5,
          'lng' => 13.4,
          'address' => $address,
        ],
      ],
    ]);
  }

  /**
   * Provides malformed address payloads plus the expected error message.
   */
  public static function invalidStructuredAddressProvider(): array {
    return [
      'missing address_line1' => [
        ['country_code' => 'DE'],
        'items[0].address.address_line1 is required.',
      ],
      'empty address_line1' => [
        ['address_line1' => '   '],
        'items[0].address.address_line1 must be a non-empty string.',
      ],
      'unknown sub-key' => [
        ['address_line1' => 'Main', 'street' => 'Foo'],
        'items[0].address contains unknown keys: street.',
      ],
      'invalid country_code shape (3 letters)' => [
        ['address_line1' => 'Main', 'country_code' => 'GER'],
        'items[0].address.country_code must be a 2-letter ISO 3166-1 alpha-2 code.',
      ],
      'non-iso 2-letter code (XX)' => [
        ['address_line1' => 'Main', 'country_code' => 'XX'],
        'items[0].address.country_code must be a valid ISO 3166-1 alpha-2 country code.',
      ],
      'non-iso 2-letter code (ZZ)' => [
        ['address_line1' => 'Main', 'country_code' => 'ZZ'],
        'items[0].address.country_code must be a valid ISO 3166-1 alpha-2 country code.',
      ],
      'non-iso 2-letter code (XK)' => [
        ['address_line1' => 'Main', 'country_code' => 'XK'],
        'items[0].address.country_code must be a valid ISO 3166-1 alpha-2 country code.',
      ],
      'wrong type (integer)' => [
        42,
        'items[0].address must be a string or an object.',
      ],
      'wrong type (boolean)' => [
        TRUE,
        'items[0].address must be a string or an object.',
      ],
    ];
  }

  /**
   * @covers ::normalizeSubmittedSettings
   *
   * The country list lookup accepts ISO 3166-1 alpha-2 codes that exist in the
   * `address.country_repository` mock (DE in setUp's known list).
   */
  public function testNormalizeSubmittedSettingsAcceptsValidIsoCountryCode(): void {
    $normalized = $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'items' => [
        [
          'id' => 'campus_north',
          'label' => 'Campus North',
          'lat' => 52.5,
          'lng' => 13.4,
          'address' => [
            'address_line1' => 'Hauptstrasse 12',
            'country_code' => 'DE',
          ],
        ],
      ],
    ]);

    $this->assertSame('DE', $normalized['items'][0]['address']['country_code']);
  }

  /**
   * @covers ::normalizeSubmittedSettings
   *
   * A whitespace-only legacy address string is rejected by the validator so
   * the write/read paths agree. `normalizeStoredAddress()` would trim such a
   * value back to NULL on read, silently losing the user's input.
   */
  public function testNormalizeSubmittedSettingsRejectsWhitespaceOnlyLegacyAddress(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('items[0].address must be a non-empty string.');

    $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'items' => [
        [
          'id' => 'campus_north',
          'label' => 'Campus North',
          'lat' => 52.5,
          'lng' => 13.4,
          'address' => '   ',
        ],
      ],
    ]);
  }

  /**
   * @covers ::normalizeSubmittedSettings
   *
   * #368 display metadata round-trips: valid icon/description/url are stored,
   * and empty values are dropped so the write/read paths agree.
   */
  public function testNormalizeSubmittedSettingsStoresDisplayMetadata(): void {
    $normalized = $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'items' => [
        [
          'id' => 'campus_north',
          'label' => 'Campus North',
          'lat' => 52.5,
          'lng' => 13.4,
          'icon' => 'i-lucide-building',
          'description' => 'Main administrative building.',
          'url' => 'https://example.org/campus-north',
        ],
        [
          'id' => 'campus_south',
          'label' => 'Campus South',
          'lat' => 52.4,
          'lng' => 13.3,
          'icon' => '',
          'description' => '   ',
          'url' => '',
        ],
      ],
    ]);

    $this->assertSame('i-lucide-building', $normalized['items'][0]['icon']);
    $this->assertSame('Main administrative building.', $normalized['items'][0]['description']);
    $this->assertSame('https://example.org/campus-north', $normalized['items'][0]['url']);

    // Empty display values are dropped, not stored as empty strings.
    $this->assertArrayNotHasKey('icon', $normalized['items'][1]);
    $this->assertArrayNotHasKey('description', $normalized['items'][1]);
    $this->assertArrayNotHasKey('url', $normalized['items'][1]);
  }

  /**
   * @covers ::normalizeSubmittedSettings
   * @covers ::validateUrlValue
   *
   * Embedded control characters in a url are stripped (not just trimmed at the
   * ends), so a mid-string newline cannot split the value for a consumer that
   * prints it raw.
   */
  public function testNormalizeSubmittedSettingsStripsControlCharsFromUrl(): void {
    $normalized = $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'items' => [
        [
          'id' => 'campus_north',
          'label' => 'Campus North',
          'lat' => 52.5,
          'lng' => 13.4,
          'url' => "https://example.org/a\r\nb\tc",
        ],
      ],
    ]);

    $this->assertSame('https://example.org/abc', $normalized['items'][0]['url']);
  }

  /**
   * @covers ::normalizeSubmittedSettings
   * @covers ::validateUrlValue
   * @dataProvider hostileDisplayMetadataProvider
   *
   * #367/#368: the server rejects HTML in icon/description and any non-http(s)
   * url scheme, so a hostile value cannot be persisted in config and served to
   * a consumer that does not re-sanitize on render.
   */
  public function testNormalizeSubmittedSettingsRejectsHostileDisplayMetadata(
    array $itemOverrides,
    string $expectedMessage,
  ): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($expectedMessage);

    $this->manager->normalizeSubmittedSettings([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'items' => [
        [
          'id' => 'campus_north',
          'label' => 'Campus North',
          'lat' => 52.5,
          'lng' => 13.4,
        ] + $itemOverrides,
      ],
    ]);
  }

  /**
   * Provides hostile display-metadata payloads plus the expected error.
   */
  public static function hostileDisplayMetadataProvider(): array {
    return [
      'javascript: url' => [
        ['url' => 'javascript:alert(1)'],
        'items[0].url must be an absolute http:// or https:// URL.',
      ],
      'data: url' => [
        ['url' => 'data:text/html,<script>alert(1)</script>'],
        'items[0].url must be an absolute http:// or https:// URL.',
      ],
      'scheme-relative url' => [
        ['url' => '//evil.example/x'],
        'items[0].url must be an absolute http:// or https:// URL.',
      ],
      'relative url' => [
        ['url' => '/campus'],
        'items[0].url must be an absolute http:// or https:// URL.',
      ],
      'empty-host url' => [
        ['url' => 'https://'],
        'items[0].url must be an absolute http:// or https:// URL.',
      ],
      'no-host triple-slash url' => [
        ['url' => 'https:///path'],
        'items[0].url must be an absolute http:// or https:// URL.',
      ],
      'html in icon' => [
        ['icon' => '<img src=x onerror=alert(1)>'],
        'items[0].icon must not contain HTML.',
      ],
      'html in description' => [
        ['description' => '<script>alert(1)</script>'],
        'items[0].description must not contain HTML.',
      ],
    ];
  }

  /**
   * @covers ::getDashboardSettings
   */
  public function testGetDashboardSettingsReturnsStructuredAddressRoundTrip(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('isDefaultTranslation')->willReturn(TRUE);
    $group->method('hasField')->with('field_facilities')->willReturn(TRUE);
    $json = json_encode([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'items' => [
        [
          'id' => 'campus_north',
          'label' => 'Campus North',
          'lat' => 52.5,
          'lng' => 13.4,
          'active' => TRUE,
          'address' => [
            'address_line1' => 'Hauptstrasse 12',
            'country_code' => 'DE',
            'locality' => 'Berlin',
            'postal_code' => '10117',
          ],
        ],
      ],
    ]);
      // phpcs:disable
      $group->method('get')->with('field_facilities')->willReturn(new class($json) {
      public string $value;

      public function __construct(string $json) { $this->value = $json; }

      public function isEmpty(): bool { return FALSE; }
      });
      // phpcs:enable

    $settings = $this->manager->getDashboardSettings($group);

    $this->assertSame([
      'address_line1' => 'Hauptstrasse 12',
      'country_code' => 'DE',
      'locality' => 'Berlin',
      'postal_code' => '10117',
    ], $settings['items'][0]['address']);
  }

  /**
   * @covers ::getDashboardSettings
   */
  public function testGetDashboardSettingsDropsCorruptStructuredAddress(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('isDefaultTranslation')->willReturn(TRUE);
    $group->method('hasField')->with('field_facilities')->willReturn(TRUE);
    $json = json_encode([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'items' => [
        [
          'id' => 'campus_north',
          'label' => 'Campus North',
          'lat' => 52.5,
          'lng' => 13.4,
          'active' => TRUE,
          'address' => ['country_code' => 'DE'],
        ],
      ],
    ]);
      // phpcs:disable
      $group->method('get')->with('field_facilities')->willReturn(new class($json) {
      public string $value;

      public function __construct(string $json) { $this->value = $json; }

      public function isEmpty(): bool { return FALSE; }
      });
      // phpcs:enable

    $settings = $this->manager->getDashboardSettings($group);

    $this->assertArrayNotHasKey('address', $settings['items'][0]);
  }

  /**
   * @covers ::getDashboardSettings
   *
   * #368: stored display metadata (icon/description/url) is re-emitted on read.
   */
  public function testGetDashboardSettingsReturnsDisplayMetadataRoundTrip(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('isDefaultTranslation')->willReturn(TRUE);
    $group->method('hasField')->with('field_facilities')->willReturn(TRUE);
    $json = json_encode([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'items' => [
        [
          'id' => 'campus_north',
          'label' => 'Campus North',
          'lat' => 52.5,
          'lng' => 13.4,
          'active' => TRUE,
          'icon' => 'i-lucide-building',
          'description' => 'Main administrative building.',
          'url' => 'https://example.org/campus-north',
        ],
      ],
    ]);
      // phpcs:disable
      $group->method('get')->with('field_facilities')->willReturn(new class($json) {
      public string $value;

      public function __construct(string $json) { $this->value = $json; }

      public function isEmpty(): bool { return FALSE; }
      });
      // phpcs:enable

    $settings = $this->manager->getDashboardSettings($group);

    $this->assertSame('i-lucide-building', $settings['items'][0]['icon']);
    $this->assertSame('Main administrative building.', $settings['items'][0]['description']);
    $this->assertSame('https://example.org/campus-north', $settings['items'][0]['url']);
  }

  /**
   * @covers ::getDashboardSettings
   * @covers ::getPublicSettings
   */
  public function testEntityCatalogueOverridesLegacyItems(): void {
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => 'exclusive',
        'hideMapPicker' => TRUE,
        'items' => [
          [
            'id' => 'legacy_school',
            'label' => 'Legacy School',
            'lat' => 51.0,
            'lng' => 7.0,
            'active' => TRUE,
          ],
        ],
      ]),
    ], 14);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([101, 102]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn([
      101 => $this->facilityEntity([
        'machine_name' => 'school_a',
        'label' => 'School A',
        'lat' => 50.1,
        'lng' => 8.1,
        'active' => TRUE,
        'address' => json_encode(['address_line1' => 'A Street 1']),
        'icon' => 'i-lucide-school',
      ]),
      102 => $this->facilityEntity([
        'machine_name' => 'school_b',
        'label' => 'School B',
        'lat' => 50.2,
        'lng' => 8.2,
        'active' => FALSE,
      ]),
    ]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')
      ->willReturnCallback(static fn(string $type): bool => $type === 'markaspot_facility');
    $entity_type_manager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
            'group' => $this->groupStorage,
            'markaspot_facility' => $storage,
            default => $this->createMock(EntityStorageInterface::class),
      });

    $manager = new FacilityManager(
      $entity_type_manager,
      $this->loggerFactory(),
      $this->countryRepository,
      $this->database,
      $this->hierarchyResolver,
      $this->featureScopeResolver,
    );

    $dashboard = $manager->getDashboardSettings($group);
    $this->assertSame(['school_a', 'school_b'], array_column($dashboard['items'], 'id'));
    $this->assertSame('A Street 1', $dashboard['items'][0]['address']['address_line1']);
    $this->assertSame('i-lucide-school', $dashboard['items'][0]['icon']);

    $public = $manager->getPublicSettings($group);
    $this->assertSame(['school_a'], array_column($public['items'], 'id'));
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
   * @covers ::isAddressLocked
   */
  public function testFacilityDerivationFailsClosedWithoutEntitlement(): void {
    $this->facilityEntitled = FALSE;
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => 'exclusive',
        'hideMapPicker' => FALSE,
        'items' => [
          [
            'id' => 'campus_north',
            'label' => 'Campus North',
            'lat' => 52.5,
            'lng' => 13.4,
            'address' => 'Main Street 1',
            'organisationId' => '90',
            'active' => TRUE,
          ],
        ],
      ]),
    ], 14);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $set_calls = [];
    $node = $this->facilityServiceRequestNode(
      'campus_north',
      14,
      [
        'field_facility',
        'field_jurisdiction',
        'field_geolocation',
        'field_address',
        'field_organisation',
      ],
      $set_calls,
    );

    $this->manager->applyToServiceRequest($node);

    $this->assertSame([['field_facility', NULL]], $set_calls);
    $this->assertFalse($this->manager->isAddressLocked($node));
  }

  /**
   * @covers ::applyToServiceRequest
   */
  public function testUnchangedHistoricalFacilityIsPreservedWithoutEntitlement(): void {
    $this->facilityEntitled = FALSE;
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => 'exclusive',
        'hideMapPicker' => FALSE,
        'items' => [],
      ]),
    ], 14);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $original_set_calls = [];
    $original = $this->facilityServiceRequestNode(
      'campus_north',
      14,
      ['field_facility', 'field_jurisdiction'],
      $original_set_calls,
    );
    $set_calls = [];
    $node = $this->facilityServiceRequestNode(
      'campus_north',
      14,
      ['field_facility', 'field_jurisdiction'],
      $set_calls,
    );
    $node->method('getOriginal')->willReturn($original);

    $this->manager->applyToServiceRequest($node);

    $this->assertSame([], $set_calls);
  }

  /**
   * @covers ::applyToServiceRequest
   */
  public function testHistoricalFacilityIsClearedWhenJurisdictionChanges(): void {
    $this->facilityEntitled = FALSE;
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => 'exclusive',
        'hideMapPicker' => FALSE,
        'items' => [],
      ]),
    ], 14);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $original_set_calls = [];
    $original = $this->facilityServiceRequestNode(
      'campus_north',
      15,
      ['field_facility', 'field_jurisdiction'],
      $original_set_calls,
    );
    $set_calls = [];
    $node = $this->facilityServiceRequestNode(
      'campus_north',
      14,
      ['field_facility', 'field_jurisdiction'],
      $set_calls,
    );
    $node->method('getOriginal')->willReturn($original);

    $this->manager->applyToServiceRequest($node);

    $this->assertSame([['field_facility', NULL]], $set_calls);
  }

  /**
   * @covers ::applyToServiceRequest
   */
  public function testApplyToServiceRequestSetsOrganisationForExclusiveFacility(): void {
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => 'exclusive',
        'hideMapPicker' => FALSE,
        'items' => [
          [
            'id' => 'campus_north',
            'label' => 'Campus North',
            'lat' => 52.5,
            'lng' => 13.4,
            'organisationId' => '2',
            'active' => TRUE,
          ],
        ],
      ]),
    ], 14);
    $organisation = $this->createMockGroup([
      'field_jurisdiction' => (object) ['target_id' => 14],
    ], 2, 'org');
    $this->groupStorage->method('load')
      ->willReturnCallback(static fn(int $id): ?GroupInterface => match ($id) {
        14 => $group,
        2 => $organisation,
        default => NULL,
      });

    $set_calls = [];
    $node = $this->facilityServiceRequestNode(
      'campus_north',
      14,
      [
        'field_facility',
        'field_jurisdiction',
        'field_geolocation',
        'field_organisation',
      ],
      $set_calls,
    );

    $this->manager->applyToServiceRequest($node);

    $this->assertSame([
      ['field_geolocation', ['lat' => 52.5, 'lng' => 13.4]],
      ['field_organisation', [['target_id' => 2]]],
    ], $set_calls);
  }

  /**
   * @covers ::applyToServiceRequest
   */
  public function testApplyToServiceRequestAllowsRootOrganisationForChildJurisdiction(): void {
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => 'exclusive',
        'hideMapPicker' => FALSE,
        'items' => [
          [
            'id' => 'campus_north',
            'label' => 'Campus North',
            'lat' => 52.5,
            'lng' => 13.4,
            'organisationId' => '2',
            'active' => TRUE,
          ],
        ],
      ]),
    ], 4);
    $organisation = $this->createMockGroup([
      'field_jurisdiction' => (object) ['target_id' => 1],
    ], 2, 'org');
    $this->groupStorage->method('load')
      ->willReturnCallback(static fn(int $id): ?GroupInterface => match ($id) {
        4 => $group,
        2 => $organisation,
        default => NULL,
      });
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(4)
      ->willReturn(1);

    $set_calls = [];
    $node = $this->facilityServiceRequestNode(
      'campus_north',
      4,
      [
        'field_facility',
        'field_jurisdiction',
        'field_geolocation',
        'field_organisation',
      ],
      $set_calls,
    );

    $this->manager->applyToServiceRequest($node);

    $this->assertSame([
      ['field_geolocation', ['lat' => 52.5, 'lng' => 13.4]],
      ['field_organisation', [['target_id' => 2]]],
    ], $set_calls);
  }

  /**
   * @covers ::applyToServiceRequest
   */
  public function testApplyToServiceRequestPreservesExistingOrganisationAssignment(): void {
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => 'exclusive',
        'hideMapPicker' => FALSE,
        'items' => [
          [
            'id' => 'campus_north',
            'label' => 'Campus North',
            'lat' => 52.5,
            'lng' => 13.4,
            'organisationId' => '2',
            'active' => TRUE,
          ],
        ],
      ]),
    ], 14);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $set_calls = [];
    $node = $this->facilityServiceRequestNode(
      'campus_north',
      14,
      [
        'field_facility',
        'field_jurisdiction',
        'field_geolocation',
        'field_organisation',
      ],
      $set_calls,
      [3],
    );

    $this->manager->applyToServiceRequest($node);

    $this->assertSame([
      ['field_geolocation', ['lat' => 52.5, 'lng' => 13.4]],
    ], $set_calls);
  }

  /**
   * @covers ::applyToServiceRequest
   */
  public function testApplyToServiceRequestSkipsEmptyFacilityOrganisationId(): void {
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => 'exclusive',
        'hideMapPicker' => FALSE,
        'items' => [
          [
            'id' => 'campus_north',
            'label' => 'Campus North',
            'lat' => 52.5,
            'lng' => 13.4,
            'organisationId' => '',
            'active' => TRUE,
          ],
        ],
      ]),
    ], 14);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $set_calls = [];
    $node = $this->facilityServiceRequestNode(
      'campus_north',
      14,
      [
        'field_facility',
        'field_jurisdiction',
        'field_geolocation',
        'field_organisation',
      ],
      $set_calls,
    );

    $this->manager->applyToServiceRequest($node);

    $this->assertSame([
      ['field_geolocation', ['lat' => 52.5, 'lng' => 13.4]],
    ], $set_calls);
  }

  /**
   * @covers ::applyToServiceRequest
   */
  public function testApplyToServiceRequestSkipsMissingFacilityOrganisationReference(): void {
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => 'exclusive',
        'hideMapPicker' => FALSE,
        'items' => [
          [
            'id' => 'campus_north',
            'label' => 'Campus North',
            'lat' => 52.5,
            'lng' => 13.4,
            'organisationId' => '99',
            'active' => TRUE,
          ],
        ],
      ]),
    ], 14);
    $this->groupStorage->method('load')
      ->willReturnCallback(static fn(int $id): ?GroupInterface => $id === 14 ? $group : NULL);

    $set_calls = [];
    $node = $this->facilityServiceRequestNode(
      'campus_north',
      14,
      [
        'field_facility',
        'field_jurisdiction',
        'field_geolocation',
        'field_organisation',
      ],
      $set_calls,
    );

    $this->manager->applyToServiceRequest($node);

    $this->assertSame([
      ['field_geolocation', ['lat' => 52.5, 'lng' => 13.4]],
    ], $set_calls);
  }

  /**
   * @covers ::applyToServiceRequest
   */
  public function testApplyToServiceRequestSkipsForeignFacilityOrganisationReference(): void {
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => 'exclusive',
        'hideMapPicker' => FALSE,
        'items' => [
          [
            'id' => 'campus_north',
            'label' => 'Campus North',
            'lat' => 52.5,
            'lng' => 13.4,
            'organisationId' => '2',
            'active' => TRUE,
          ],
        ],
      ]),
    ], 14);
    $organisation = $this->createMockGroup([
      'field_jurisdiction' => (object) ['target_id' => 15],
    ], 2, 'org');
    $this->groupStorage->method('load')
      ->willReturnCallback(static fn(int $id): ?GroupInterface => match ($id) {
        14 => $group,
        2 => $organisation,
        default => NULL,
      });

    $set_calls = [];
    $node = $this->facilityServiceRequestNode(
      'campus_north',
      14,
      [
        'field_facility',
        'field_jurisdiction',
        'field_geolocation',
        'field_organisation',
      ],
      $set_calls,
    );

    $this->manager->applyToServiceRequest($node);

    $this->assertSame([
      ['field_geolocation', ['lat' => 52.5, 'lng' => 13.4]],
    ], $set_calls);
  }

  /**
   * @covers ::applyToServiceRequest
   */
  public function testApplyToServiceRequestSkipsNonOrgFacilityOrganisationReference(): void {
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => 'exclusive',
        'hideMapPicker' => FALSE,
        'items' => [
          [
            'id' => 'campus_north',
            'label' => 'Campus North',
            'lat' => 52.5,
            'lng' => 13.4,
            'organisationId' => '15',
            'active' => TRUE,
          ],
        ],
      ]),
    ], 14);
    $jurisdiction = $this->createMockGroup([], 15, 'jur');
    $this->groupStorage->method('load')
      ->willReturnCallback(static fn(int $id): ?GroupInterface => match ($id) {
        14 => $group,
        15 => $jurisdiction,
        default => NULL,
      });

    $set_calls = [];
    $node = $this->facilityServiceRequestNode(
      'campus_north',
      14,
      [
        'field_facility',
        'field_jurisdiction',
        'field_geolocation',
        'field_organisation',
      ],
      $set_calls,
    );

    $this->manager->applyToServiceRequest($node);

    $this->assertSame([
      ['field_geolocation', ['lat' => 52.5, 'lng' => 13.4]],
    ], $set_calls);
  }

  /**
   * @covers ::applyToServiceRequest
   *
   * Structured-address path: the four sub-fields flow through verbatim and
   * the jurisdiction country fallback is suppressed (admin intent wins).
   */
  public function testApplyToServiceRequestPassesStructuredAddressVerbatim(): void {
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
            'active' => TRUE,
            'address' => [
              'address_line1' => 'Hauptstrasse 12',
              'country_code' => 'NL',
              'locality' => 'Berlin',
              'postal_code' => '10117',
            ],
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

            public function isEmpty(): bool { return FALSE; }
      };
      $jurisdiction_field = new class() {
            public function isEmpty(): bool { return FALSE; }

            public function first(): object { return (object) ['target_id' => 14]; }
      };
      $address_field = new class() {
            public function isEmpty(): bool { return TRUE; }

            public function first(): ?object { return NULL; }
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
      [
        'field_address',
        [
          'address_line1' => 'Hauptstrasse 12',
          'country_code' => 'NL',
          'locality' => 'Berlin',
          'postal_code' => '10117',
        ],
      ],
    ], $set_calls);
  }

  /**
   * @covers ::applyToServiceRequest
   *
   * Structured address WITHOUT country_code falls back to the jurisdiction
   * country, matching the legacy string path's behaviour.
   */
  public function testApplyToServiceRequestFallsBackToJurisdictionCountryForPartialStructuredAddress(): void {
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
            'active' => TRUE,
            'address' => [
              'address_line1' => 'Hauptstrasse 12',
            ],
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

            public function isEmpty(): bool { return FALSE; }
      };
      $jurisdiction_field = new class() {
            public function isEmpty(): bool { return FALSE; }

            public function first(): object { return (object) ['target_id' => 14]; }
      };
      $address_field = new class() {
            public function isEmpty(): bool { return TRUE; }

            public function first(): ?object { return NULL; }
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
    $node->method('set')
      ->willReturnCallback(function (string $field_name, array $value) use (&$set_calls): void {
            $set_calls[] = [$field_name, $value];
      });

    $this->manager->applyToServiceRequest($node);

    $this->assertSame([
      'address_line1' => 'Hauptstrasse 12',
      'country_code' => 'DE',
    ], $set_calls[1][1]);
  }

  /**
   * @covers ::applyToServiceRequest
   *
   * #367 defence in depth: a foreign facility id reaching this method via a
   * programmatic path (one that skipped validate(), e.g. ECA/import) is cleared
   * rather than persisted with no resolvable geodata.
   */
  public function testApplyToServiceRequestClearsForeignFacility(): void {
    $group = $this->createMockGroup([
      'field_facilities' => json_encode([
        'enabled' => TRUE,
        'mode' => 'exclusive',
        'hideMapPicker' => FALSE,
        'items' => [
          [
            'id' => 'campus_north',
            'label' => 'Campus North',
            'lat' => 52.5,
            'lng' => 13.4,
            'active' => TRUE,
          ],
        ],
      ]),
    ], 14);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

      // phpcs:disable
      $facility_field = new class('foreign_facility') {
            public function __construct(public string $value) {}

            public function isEmpty(): bool { return FALSE; }
      };
      $jurisdiction_field = new class() {
            public function isEmpty(): bool { return FALSE; }

            public function first(): object { return (object) ['target_id' => 14]; }
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

    $set_calls = [];
    $node->method('set')
      ->willReturnCallback(function (string $field_name, mixed $value) use (&$set_calls): void {
            $set_calls[] = [$field_name, $value];
      });

    $this->manager->applyToServiceRequest($node);

    $this->assertSame([['field_facility', NULL]], $set_calls);
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
  private function createMockGroup(array $fields, int $id, string $bundle = 'jur'): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('bundle')->willReturn($bundle);
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
              if ($name === 'value') {
                return $this->value;
              }
              if (is_object($this->value) && isset($this->value->{$name})) {
                return $this->value->{$name};
              }
              if (is_array($this->value) && array_key_exists($name, $this->value)) {
                return $this->value[$name];
              }
              return NULL;
                    }
          };
        // phpcs:enable
      });

    return $group;
  }

  /**
   * Creates a service request node double for facility derivation tests.
   *
   * @param string $facility_id
   *   Selected facility machine key.
   * @param int $jurisdiction_id
   *   Referenced jurisdiction id.
   * @param array<int, string> $available_fields
   *   Fields reported as present by hasField().
   * @param array<int, array{0: string, 1: mixed}> $set_calls
   *   Captured node::set() calls.
   * @param int[] $organisation_ids
   *   Existing organisation target ids on the request.
   */
  private function facilityServiceRequestNode(
    string $facility_id,
    int $jurisdiction_id,
    array $available_fields,
    array &$set_calls,
    array $organisation_ids = [],
  ): NodeInterface {
      // phpcs:disable
      $facility_field = new class($facility_id) {
            public function __construct(public string $value) {}

            public function isEmpty(): bool { return FALSE; }
      };
      $jurisdiction_field = new class($jurisdiction_id) {
            public function __construct(private int $targetId) {}

            public function isEmpty(): bool { return FALSE; }

            public function first(): object { return (object) ['target_id' => $this->targetId]; }
      };
      $organisation_field = new class($organisation_ids) {
            /**
             * @param int[] $targetIds
             *   Existing organisation target ids.
             */
            public function __construct(private array $targetIds) {}

            public function isEmpty(): bool { return $this->targetIds === []; }

            public function getValue(): array {
              return array_map(static fn(int $id): array => ['target_id' => $id], $this->targetIds);
            }
      };
      // phpcs:enable

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('hasField')
      ->willReturnCallback(static fn(string $field): bool => in_array($field, $available_fields, TRUE));
    $node->method('get')
      ->willReturnCallback(fn(string $field) => match ($field) {
        'field_facility' => $facility_field,
        'field_jurisdiction' => $jurisdiction_field,
        'field_organisation' => $organisation_field,
        default => $this->createMock(FieldItemListInterface::class),
      });
    $node->method('set')
      ->willReturnCallback(static function (string $field_name, mixed $value) use (&$set_calls): void {
        $set_calls[] = [$field_name, $value];
      });

    return $node;
  }

  /**
   * Creates a facility content entity double.
   */
  private function facilityEntity(array $fields): ContentEntityInterface {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => array_key_exists($name, $fields));
    $entity->method('get')
      ->willReturnCallback(fn(string $name): FieldItemListInterface => $this->fieldList($fields[$name] ?? NULL));
    return $entity;
  }

  /**
   * Creates a field list double exposing isEmpty() and value.
   */
  private function fieldList(mixed $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === NULL || $value === '');
    $field->method('getString')->willReturn($value === NULL ? '' : (string) $value);
    return $field;
  }

  /**
   * Creates a logger factory double for ad-hoc manager instances.
   */
  private function loggerFactory(): LoggerChannelFactoryInterface {
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerInterface::class));
    return $logger_factory;
  }

  /**
   * Creates a transaction double without invoking core transaction internals.
   */
  private function transaction(): Transaction {
    return new class() extends Transaction {

      /**
       * Constructs a no-op transaction double.
       */
      public function __construct() {
      }

      /**
       * Prevents core transaction cleanup in the test double.
       */
      public function __destruct() {
      }

      /**
       * Rolls back the no-op transaction double.
       */
      public function rollBack() {
      }

    };
  }

}
