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
use Drupal\Core\Site\Settings;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests transactional mail branding resolution.
 */
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

  /**
   * Resets Drupal Settings so operating-mode flips do not leak between tests.
   *
   * MailBrandingService reads the mode via Settings::get(); the Settings
   * constructor pins itself as the singleton, so re-instantiating with an
   * empty array is the canonical reset (no putenv globalstate).
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings([]);
  }

  /**
   * Resets Drupal Settings on tear-down to mirror the setUp() reset.
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Tests platform defaults in platform mode.
   */
  public function testPlatformModeReturnsDefaults(): void {
    new Settings(['markaspot_operating_mode' => 'saas']);
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

  /**
   * Tests self-hosted mode as the default branding policy.
   *
   * In this mode the Kommune is the sole legal contact, so no Civic-Patches
   * branding may appear.
   */
  public function testSelfHostedModeIsDefaultAndDropsCivicPatchesFallbacks(): void {
    // setUp() resets Settings to empty -> default self_hosted.
    $service = $this->buildService(NULL);
    $branding = $service->getBranding(NULL, 'platform', 'en');

    // Configured platform_name still surfaces; brand attribution does not.
    $this->assertSame('Mark-a-Spot', $branding['platform_name']);
    // Configured legal/privacy URLs still surface (they're just URLs);
    // the civicpatches.de FALLBACK does not.
    $this->assertSame('https://civicpatches.de/impressum', $branding['legal_notice_url']);
    $this->assertSame('https://civicpatches.de/datenschutz', $branding['privacy_url']);
    // Platform footer (Zone 2: MaS-Logo + Civic-Patches Impressum) is
    // forced off regardless of the legacy show_platform_footer flag.
    $this->assertNull($branding['platform_footer']);
    $this->assertFalse($branding['show_platform_footer']);
  }

  /**
   * Tests jurisdiction branding values for an Amsterdam tenant.
   */
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

  /**
   * Jurisdiction footers must read the group translation for the mail language.
   */
  public function testJurisdictionFooterUsesRequestedTranslation(): void {
    $group = $this->buildGroup([
      'id' => 1,
      'label' => 'Amsterdam',
      'field_slug' => 'amsterdam',
      'field_platform_name' => 'Amsterdam',
      'field_email_footer' => "English footer\nContact",
    ], [
      'de' => [
        'label' => 'Amsterdam DE',
        'field_platform_name' => 'Amsterdam DE',
        'field_email_footer' => "Deutscher Footer\n<script>alert(1)</script>",
      ],
    ]);
    $service = $this->buildService($group);

    $branding = $service->getBranding(1, 'jurisdiction', 'de');

    $this->assertSame('Amsterdam DE', $branding['platform_name']);
    $footerHtml = (string) $branding['email_footer_html'];
    $this->assertStringContainsString('Deutscher Footer', $footerHtml);
    $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $footerHtml);
    $this->assertStringNotContainsString('English footer', $footerHtml);
    $this->assertStringNotContainsString('<script>', $footerHtml);
  }

  /**
   * Tests Tailwind emerald color mapping for jurisdiction branding.
   */
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

  /**
   * Tests slug-built jurisdiction legal URLs.
   */
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

  /**
   * Tests missing jurisdiction fallback and warning logging.
   */
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

  /**
   * Tests unknown color fallback.
   */
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

  /**
   * Tests raw hex color passthrough.
   */
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
   * Tests disabling the optional platform footer attribution.
   *
   * Self-hosted enterprise installations opt out so no civicpatches.de/impressum
   * or copyright line ships in their mails.
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
   * Tests the SaaS default for the optional platform footer attribution.
   *
   * In SaaS mode the legacy features.show_platform_footer flag defaults to TRUE
   * when markaspot_mail.settings has no entry.
   */
  public function testShowPlatformFooterDefaultsToTrueWhenMissingInSaasMode(): void {
    new Settings(['markaspot_operating_mode' => 'saas']);
    // Settings config without any features.show_platform_footer key.
    $service = $this->buildService(NULL);

    $branding = $service->getBranding(NULL, 'platform', 'en');

    $this->assertNotNull($branding['platform_footer']);
    $this->assertTrue($branding['show_platform_footer']);
  }

  /**
   * S3: javascript: in legal_notice_url config falls back to the safe URL.
   *
   * In SaaS mode the safe default is civicpatches.de (Civic Patches IS
   * the operator); in self_hosted mode the safe default is the empty
   * string so no Civic-Patches link appears in a Kommune-operated mail.
   * Both branches block the javascript: scheme — the security promise
   * holds either way.
   */
  public function testJavascriptUrlInPlatformConfigFallsBackToSafeDefault(): void {
    new Settings(['markaspot_operating_mode' => 'saas']);
    $overrides = self::PLATFORM_SETTINGS;
    $overrides['platform.legal_notice_url'] = 'javascript:alert(1)';
    $service = $this->buildService(NULL, NULL, NULL, $overrides);

    $branding = $service->getBranding(NULL, 'platform', 'en');

    $this->assertSame('https://civicpatches.de/impressum', $branding['legal_notice_url']);
  }

  /**
   * Tests self-hosted javascript URL fallback.
   *
   * The unsafe URL is still rejected, but the safe default is empty so Twig's
   * `{% if branding.legal_notice_url %}` suppresses the link entirely.
   */
  public function testJavascriptUrlInSelfHostedFallsBackToEmptyString(): void {
    // setUp() resets Settings to empty -> default self_hosted.
    $overrides = self::PLATFORM_SETTINGS;
    $overrides['platform.legal_notice_url'] = 'javascript:alert(1)';
    $service = $this->buildService(NULL, NULL, NULL, $overrides);

    $branding = $service->getBranding(NULL, 'platform', 'en');

    $this->assertSame('', $branding['legal_notice_url']);
  }

  /**
   * Tests SaaS fallback when platform.frontend_base_url is missing.
   *
   * The platform is the operator, so mark-a-spot.com is the legitimate default.
   */
  public function testFrontendBaseUrlSaasFallbackUsesMarkaSpotDefault(): void {
    new Settings(['markaspot_operating_mode' => 'saas']);
    $overrides = self::PLATFORM_SETTINGS;
    unset($overrides['platform.frontend_base_url']);
    $service = $this->buildService(NULL, NULL, NULL, $overrides);

    $branding = $service->getBranding(NULL, 'platform', 'en');

    $this->assertSame('https://mark-a-spot.com', $branding['frontend_base_url']);
  }

  /**
   * Tests self-hosted fallback when platform.frontend_base_url is missing.
   *
   * It falls back to '' rather than mark-a-spot.com so a Kommune-operated mail
   * does not silently link citizens to a foreign domain.
   */
  public function testFrontendBaseUrlSelfHostedFallsBackToEmptyString(): void {
    // setUp() resets Settings to empty -> default self_hosted.
    $overrides = self::PLATFORM_SETTINGS;
    unset($overrides['platform.frontend_base_url']);
    $service = $this->buildService(NULL, NULL, NULL, $overrides);

    $branding = $service->getBranding(NULL, 'platform', 'en');

    $this->assertSame('', $branding['frontend_base_url']);
  }

  /**
   * Tests legal URL suppression when self-hosted frontend bases are empty.
   *
   * A self-hosted jurisdiction with non-URL legal content, empty tenant template
   * and empty platform frontend_base_url must not produce a path-only URL.
   */
  public function testSelfHostedSlugLegalUrlSuppressedWhenNoFrontendBase(): void {
    // setUp() resets Settings to empty -> default self_hosted.
    $overrides = self::PLATFORM_SETTINGS;
    // No platform frontend base AND no tenant template -> nothing to
    // anchor a slug-built legal URL to.
    unset($overrides['platform.frontend_base_url']);
    unset($overrides['platform.tenant_frontend_base_template']);
    // Legal notice URL itself is also unset so the platform fallback ('')
    // becomes the assertion target.
    unset($overrides['platform.legal_notice_url']);

    $group = $this->buildGroup([
      'id' => 42,
      'label' => 'Self-Hosted Kommune',
      'field_slug' => 'kommune',
      'field_platform_name' => 'Kommune',
      'field_nuxt_config' => json_encode(['theme' => ['primary' => 'blue']]),
      // Non-URL content forces the slug-builder branch in resolveLegalUrl.
      'field_legal_notice' => 'Some prose, definitely not a URL.',
    ]);
    $service = $this->buildService($group, NULL, NULL, $overrides);

    $branding = $service->getBranding(42, 'jurisdiction', 'en');

    // Empty frontend base flows through to legal_notice_url. Without the
    // guard, resolveLegalUrl would build "" . "/" . slug . "/legal-notice"
    // and produce "/kommune/legal-notice" — a path-only string mail
    // clients cannot resolve. assertSame('', ...) fails loudly on that
    // exact regression with a meaningful diff.
    $this->assertSame('', $branding['frontend_base_url']);
    $this->assertSame('', $branding['legal_notice_url']);
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
    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')->willReturnCallback(
      static fn(string $key) => $key === 'jurisdiction_group_type' ? 'jur' : NULL,
    );
    $configFactory->method('get')->willReturnMap([
      ['markaspot_mail.settings', $settingsConfig],
      ['markaspot_open311.settings', $open311Config],
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
   * @param array $translations
   *   Optional translation data keyed by langcode.
   */
  private function buildGroup(array $data, array $translations = []): ContentEntityInterface {
    $group = $this->createMock(ContentEntityInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('id')->willReturn($data['id']);
    $group->method('label')->willReturn($data['label'] ?? '');
    $translatedGroups = [];
    foreach ($translations as $langcode => $translationData) {
      $translatedGroups[$langcode] = $this->buildGroup($translationData + $data);
    }
    $group->method('hasTranslation')->willReturnCallback(
      static fn(string $langcode): bool => isset($translatedGroups[$langcode]),
    );
    $group->method('getTranslation')->willReturnCallback(
      static fn(string $langcode): ContentEntityInterface => $translatedGroups[$langcode] ?? $group,
    );

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
