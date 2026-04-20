<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(\Drupal\markaspot_mail\Service\MailBrandingService::class)]
#[Group('markaspot_mail')]
final class MailBrandingServiceTest extends UnitTestCase {

  /**
   * Platform defaults returned by the mocked markaspot_mail.settings config.
   */
  private const PLATFORM_SETTINGS = [
    'platform.name' => 'Mark-a-Spot',
    'platform.support_email' => 'support@civic-patches.com',
    'platform.reply_to' => 'support@civic-patches.com',
    'platform.logo_path' => 'images/mark-a-spot-logo@2x.png',
    'platform.primary_color' => '#004ced',
    'platform.background_color' => '#EEF3FF',
    'platform.legal_notice_url' => 'https://civicpatches.de/impressum',
    'platform.privacy_url' => 'https://civicpatches.de/datenschutz',
    'platform.frontend_base_url' => 'https://mark-a-spot.com',
    'platform.tenant_frontend_base_template' => 'https://{slug}.civicspot.io',
  ];
  public function testPlatformModeReturnsDefaults(): void {
    $service = $this->buildService(NULL);
    $branding = $service->getBranding(NULL, 'platform', 'en');

    $this->assertSame('platform', $branding['mode']);
    $this->assertSame('Mark-a-Spot', $branding['platform_name']);
    $this->assertSame('#004ced', $branding['primary_color']);
    // normalizeColor() lowercases the accepted hex value for consistency.
    $this->assertSame('#eef3ff', $branding['background_color']);
    $this->assertSame('support@civic-patches.com', $branding['support_email']);
    $this->assertSame('https://civicpatches.de/impressum', $branding['legal_notice_url']);
    $this->assertSame('https://civicpatches.de/datenschutz', $branding['privacy_url']);
    $this->assertSame('https://mark-a-spot.com', $branding['platform_footer']['mas_link']);
    $this->assertSame('https://civicspot.io', $branding['platform_footer']['civicspot_link']);
    $this->assertStringContainsString('Civic Patches GmbH', $branding['platform_footer']['copyright']);
  }

  public function testJurisdictionAmsterdamMapsBlueToHex(): void {
    $group = $this->buildGroup([
      'id' => 1,
      'label' => 'Amsterdam',
      'field_slug' => 'amsterdam',
      'field_platform_name' => 'Amsterdam',
      'field_nuxt_config' => json_encode(['theme' => ['primary' => 'blue']]),
      'field_jurisdiction_e_mail' => 'contact@amsterdam.nl',
      'field_legal_notice' => 'https://amsterdam.nl/impressum',
      'field_privacy_policy' => 'https://amsterdam.nl/privacy',
      'field_email_footer' => "Gemeente Amsterdam\nMeld en Herstel",
    ]);
    $service = $this->buildService($group);

    $branding = $service->getBranding(1, 'jurisdiction', 'en');

    $this->assertSame('jurisdiction', $branding['mode']);
    $this->assertSame('Amsterdam', $branding['platform_name']);
    $this->assertSame('amsterdam', $branding['jurisdiction_slug']);
    $this->assertSame('Amsterdam', $branding['jurisdiction_label']);
    $this->assertSame('#3b82f6', $branding['primary_color']);
    $this->assertSame('contact@amsterdam.nl', $branding['support_email']);
    $this->assertSame('contact@amsterdam.nl', $branding['reply_to']);
    $this->assertSame('https://amsterdam.nl/impressum', $branding['legal_notice_url']);
    $this->assertSame('https://amsterdam.nl/privacy', $branding['privacy_url']);
    // S1: email_footer_html is a MarkupInterface (safe for auto-escape).
    $this->assertInstanceOf(MarkupInterface::class, $branding['email_footer_html']);
    $footerHtml = (string) $branding['email_footer_html'];
    $this->assertStringContainsString('Gemeente Amsterdam', $footerHtml);
    $this->assertStringContainsString('<br', $footerHtml);
  }

  public function testJurisdictionBcpMapsEmeraldToHex(): void {
    $group = $this->buildGroup([
      'id' => 8,
      'label' => 'BCP Council',
      'field_slug' => 'bcp-council',
      'field_platform_name' => 'BCP Council',
      'field_nuxt_config' => json_encode(['theme' => ['primary' => 'emerald']]),
    ]);
    $service = $this->buildService($group);

    $branding = $service->getBranding(8, 'jurisdiction', 'en');

    $this->assertSame('BCP Council', $branding['platform_name']);
    $this->assertSame('#10b981', $branding['primary_color']);
    $this->assertSame('bcp-council', $branding['jurisdiction_slug']);
  }

  public function testJurisdictionSlugBuildsLegalUrlsWhenFieldsContainContent(): void {
    $group = $this->buildGroup([
      'id' => 5,
      'label' => 'Rotterdam',
      'field_slug' => 'rotterdam',
      'field_platform_name' => 'Rotterdam',
      'field_nuxt_config' => json_encode(['theme' => ['primary' => 'teal']]),
      // Non-URL content in legal fields -> slug-based URLs are built.
      'field_legal_notice' => 'Some prose content, not a URL.',
      'field_privacy_policy' => 'Also just text.',
    ]);
    $service = $this->buildService($group);

    $branding = $service->getBranding(5, 'jurisdiction', 'en');

    $this->assertSame('#14b8a6', $branding['primary_color']);
    // U2: slug-built URLs now come from the tenant frontend template, NOT
    // from the platform base. Amsterdam's privacy page lives on the tenant
    // domain, not on mark-a-spot.com.
    $this->assertSame('https://rotterdam.civicspot.io/rotterdam/legal-notice', $branding['legal_notice_url']);
    $this->assertSame('https://rotterdam.civicspot.io/rotterdam/privacy', $branding['privacy_url']);
    $this->assertSame('https://rotterdam.civicspot.io', $branding['frontend_base_url']);
  }

  public function testMissingJurisdictionFallsBackAndLogsWarning(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->atLeastOnce())
      ->method('warning');

    $service = $this->buildService(NULL, $storage, $logger);
    $branding = $service->getBranding(9999, 'jurisdiction', 'en');

    $this->assertSame('jurisdiction', $branding['mode']);
    $this->assertSame('Mark-a-Spot', $branding['platform_name']);
    $this->assertSame('#004ced', $branding['primary_color']);
    $this->assertSame('https://civicpatches.de/impressum', $branding['legal_notice_url']);
  }

  public function testUnknownColorFallsBackToDefault(): void {
    $group = $this->buildGroup([
      'id' => 9,
      'label' => 'Bournemouth',
      'field_slug' => 'bournemouth',
      'field_platform_name' => 'Bournemouth Town Council',
      'field_nuxt_config' => json_encode(['theme' => ['primary' => 'electric-orange-pop']]),
    ]);
    $service = $this->buildService($group);

    $branding = $service->getBranding(9, 'jurisdiction', 'en');
    $this->assertSame('#004ced', $branding['primary_color']);
  }

  public function testHexColorPassesThrough(): void {
    $group = $this->buildGroup([
      'id' => 7,
      'label' => 'Utrecht',
      'field_slug' => 'utrecht',
      'field_platform_name' => 'Utrecht',
      'field_nuxt_config' => json_encode(['theme' => ['primary' => '#ff3366']]),
    ]);
    $service = $this->buildService($group);

    $branding = $service->getBranding(7, 'jurisdiction', 'en');
    $this->assertSame('#ff3366', $branding['primary_color']);
  }

  /**
   * Strips CRLF-contaminated e-mail addresses before header assignment.
   *
   * S2 defense-in-depth: anything that flows into Reply-To must never
   * contain raw \r / \n.
   */
  public function testJurisdictionEmailStripsCrlfBeforeHeaderAssignment(): void {
    $group = $this->buildGroup([
      'id' => 1,
      'label' => 'Amsterdam',
      'field_slug' => 'amsterdam',
      'field_platform_name' => 'Amsterdam',
      'field_nuxt_config' => json_encode(['theme' => ['primary' => 'blue']]),
      'field_jurisdiction_e_mail' => "contact@amsterdam.nl\r\nBcc: attacker@example.com",
    ]);
    $service = $this->buildService($group);

    $branding = $service->getBranding(1, 'jurisdiction', 'en');

    $this->assertStringNotContainsString("\r", $branding['support_email']);
    $this->assertStringNotContainsString("\n", $branding['support_email']);
    $this->assertStringNotContainsString("\r", $branding['reply_to']);
    $this->assertStringNotContainsString("\n", $branding['reply_to']);
    // The email prefix survives; the injected header is concatenated but
    // rendered inert. Downstream the hook additionally sanitizes, so this
    // defense-in-depth coverage is enough.
    $this->assertStringStartsWith('contact@amsterdam.nl', $branding['support_email']);
    $this->assertStringStartsWith('contact@amsterdam.nl', $branding['reply_to']);
  }

  /**
   * features.show_platform_footer = false suppresses the Civic Patches
   * attribution bundle. Self-hosted enterprise installations opt out so
   * no civicpatches.de/impressum or copyright line ships in their mails.
   */
  public function testShowPlatformFooterFalseSuppressesPlatformFooter(): void {
    $overrides = self::PLATFORM_SETTINGS;
    $overrides['features.show_platform_footer'] = FALSE;
    $service = $this->buildService(NULL, NULL, NULL, $overrides);

    $branding = $service->getBranding(NULL, 'platform', 'en');

    $this->assertNull($branding['platform_footer']);
    $this->assertFalse($branding['show_platform_footer']);
    // Platform name + color stay — the branding package is still usable,
    // just without the Civic Patches attribution slot.
    $this->assertSame('Mark-a-Spot', $branding['platform_name']);
    $this->assertSame('#004ced', $branding['primary_color']);
  }

  /**
   * Flag defaults to TRUE when markaspot_mail.settings has no entry,
   * so shipping config behavior stays backwards-compatible.
   */
  public function testShowPlatformFooterDefaultsToTrueWhenMissing(): void {
    // Settings config without any features.show_platform_footer key.
    $service = $this->buildService(NULL);

    $branding = $service->getBranding(NULL, 'platform', 'en');

    $this->assertNotNull($branding['platform_footer']);
    $this->assertTrue($branding['show_platform_footer']);
  }

  /**
   * S3: javascript: in legal_notice_url config falls back to the safe URL.
   */
  public function testJavascriptUrlInPlatformConfigFallsBackToSafeDefault(): void {
    $overrides = self::PLATFORM_SETTINGS;
    $overrides['platform.legal_notice_url'] = 'javascript:alert(1)';
    $service = $this->buildService(NULL, NULL, NULL, $overrides);

    $branding = $service->getBranding(NULL, 'platform', 'en');

    $this->assertSame('https://civicpatches.de/impressum', $branding['legal_notice_url']);
  }

  /**
   * Builds a MailBrandingService with mocked dependencies.
   */
  private function buildService(
    ?ContentEntityInterface $group,
    ?EntityStorageInterface $storage = NULL,
    ?LoggerInterface $logger = NULL,
    ?array $settingsOverride = NULL,
  ): MailBrandingService {
    $storage = $storage ?? $this->createMock(EntityStorageInterface::class);
    if ($group !== NULL) {
      $storage->method('load')->willReturn($group);
    }
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('group')->willReturn($storage);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $settingsConfig = $this->createMock(ImmutableConfig::class);
    $settings = $settingsOverride ?? self::PLATFORM_SETTINGS;
    $settingsConfig->method('get')->willReturnCallback(
      static fn(string $key) => $settings[$key] ?? NULL,
    );
    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')->willReturn(NULL);
    $configFactory->method('get')->willReturnMap([
      ['markaspot_mail.settings', $settingsConfig],
      ['system.site', $siteConfig],
    ]);

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $logger = $logger ?? $this->createMock(LoggerInterface::class);

    return new MailBrandingService(
      $etm,
      $configFactory,
      $languageManager,
      $fileUrlGenerator,
      $logger,
    );
  }

  /**
   * Builds a jurisdiction group mock.
   *
   * @param array $data
   *   Keyed by field machine name. "id" and "label" are meta keys.
   */
  private function buildGroup(array $data): ContentEntityInterface {
    $group = $this->createMock(ContentEntityInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('id')->willReturn($data['id']);
    $group->method('label')->willReturn($data['label'] ?? '');
    $group->method('hasTranslation')->willReturn(FALSE);
    $group->method('getTranslation')->willReturnSelf();

    $group->method('hasField')->willReturnCallback(
      static fn(string $name) => array_key_exists($name, $data),
    );
    $group->method('get')->willReturnCallback(
      function (string $name) use ($data) {
        $value = $data[$name] ?? NULL;
        return $this->buildFieldItemList($value);
      },
    );

    return $group;
  }

  /**
   * Builds a field item list stub supporting ->value, ->isEmpty(), ->entity.
   */
  private function buildFieldItemList($value): object {
    // @phpcs:disable Drupal.Commenting.DocComment
    return new class ($value) {

      /**
       * Raw field value.
       *
       * @var mixed
       */
      public $value;

      /**
       * Referenced entity (unused in these tests).
       *
       * @var mixed
       */
      public $entity = NULL;

      public function __construct($value) {
        $this->value = $value;
      }

      /**
       *
       */
      public function isEmpty(): bool {
        return $this->value === NULL || $this->value === '';
      }

    };
    // @phpcs:enable
  }

}
