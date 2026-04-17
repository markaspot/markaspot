<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Service;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves brand metadata for transactional mails.
 *
 * Supports two modes, mapped onto DSGVO responsibility:
 *   - "platform"     Civic Patches GmbH is legally responsible (OTP fallback,
 *                    FastMap workspace mails). Footer points at
 *                    civicpatches.de/impressum + civicpatches.de/datenschutz.
 *   - "jurisdiction" The Kommune (group entity) is legally responsible.
 *                    Footer uses the group's field_legal_notice +
 *                    field_privacy_policy, contact data from
 *                    field_jurisdiction_e_mail + field_email_footer.
 *
 * The "Platform Zone 2" footer is always returned so callers can render a
 * persistent Mark-a-Spot attribution block. Tier-based removal is a later
 * stage and intentionally not implemented here.
 */
class MailBrandingService {

  /**
   * Static map of Tailwind color names to HEX values.
   *
   * Anything not in this map (incl. raw "#RRGGBB") passes through unchanged
   * as long as it looks like a 6-digit hex color.
   */
  private const TAILWIND_COLORS = [
    'blue' => '#3b82f6',
    'indigo' => '#6366f1',
    'sky' => '#0ea5e9',
    'cyan' => '#06b6d4',
    'teal' => '#14b8a6',
    'emerald' => '#10b981',
    'green' => '#22c55e',
    'red' => '#ef4444',
    'rose' => '#f43f5e',
    'orange' => '#f97316',
    'amber' => '#f59e0b',
    'yellow' => '#eab308',
    'violet' => '#8b5cf6',
    'purple' => '#a855f7',
    'fuchsia' => '#d946ef',
    'pink' => '#ec4899',
    'slate' => '#64748b',
    'gray' => '#6b7280',
    'zinc' => '#71717a',
  ];

  /**
   * Default primary color when nothing else resolves (Electric Azure).
   */
  private const DEFAULT_PRIMARY = '#004ced';

  /**
   * Default background color (Azure-50).
   */
  private const DEFAULT_BACKGROUND = '#EEF3FF';

  /**
   * Request-scoped memoization of resolved branding packages.
   *
   * Keyed by "<jurisdictionId|''>:<mode>:<langcode>". The entity type
   * manager caches the group load, but the full branding assembly
   * (logo file URL lookup, nuxt_config JSON decode, footer construction)
   * is non-trivial. Builders that inject this service call getBranding()
   * to resolve frontend URLs BEFORE MailAlterHook calls it again for
   * rendering — memoization keeps the second call free.
   *
   * @var array<string, array>
   */
  private array $brandingCache = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LanguageManagerInterface $languageManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly LoggerInterface $logger,
    private readonly ?ModuleExtensionList $moduleExtensionList = NULL,
    private readonly ?RequestStack $requestStack = NULL,
  ) {}

  /**
   * Returns the branding package for a given mail context.
   *
   * @param int|null $jurisdictionId
   *   Group entity ID for mode "jurisdiction", or NULL for platform mode.
   * @param string $mode
   *   Either "platform" or "jurisdiction".
   * @param string $langcode
   *   Langcode used for field translations + frontend URL generation.
   *
   * @return array
   *   Associative array with the following keys:
   *   - mode (string)
   *   - platform_name (string)
   *   - logo_url (string)
   *   - primary_color (string)
   *   - background_color (string)
   *   - support_email (string, header-sanitized)
   *   - legal_notice_url (string)
   *   - privacy_url (string)
   *   - email_footer_html (MarkupInterface, already safe to print in Twig)
   *   - reply_to (string, header-sanitized)
   *   - frontend_base_url (string, platform-safe https URL)
   *   - jurisdiction_slug (string|null)
   *   - jurisdiction_label (string|null)
   *   - platform_footer (array)
   */
  public function getBranding(?int $jurisdictionId, string $mode, string $langcode): array {
    $mode = $mode === 'jurisdiction' ? 'jurisdiction' : 'platform';
    $cacheKey = ($jurisdictionId ?? '') . ':' . $mode . ':' . $langcode;
    if (isset($this->brandingCache[$cacheKey])) {
      return $this->brandingCache[$cacheKey];
    }
    $branding = $this->computeBranding($jurisdictionId, $mode, $langcode);
    $this->brandingCache[$cacheKey] = $branding;
    return $branding;
  }

  /**
   * Builds the branding package without the memoization shell.
   */
  private function computeBranding(?int $jurisdictionId, string $mode, string $langcode): array {
    $platformDefaults = $this->getPlatformDefaults();

    // features.show_platform_footer is the opt-out switch for self-hosted
    // enterprise installations and paid CivicSpot tiers that don't want
    // Civic Patches GmbH attribution in every mail. When false:
    //   - Zone 2 (MaS logo + Docs/civicspot.io + civicpatches.de Impressum
    //     + © Civic Patches GmbH) disappears entirely.
    //   - The top Mark-a-Spot inline SVG in platform mode disappears too,
    //     because it's the same brand mark the footer is attributing.
    //   - Zone 1 (jurisdiction contact + legal links) is unaffected. The
    //     Kommune remains the DSGVO-Verantwortliche and their links are
    //     still rendered.
    $showPlatformFooter = (bool) (
      $this->configFactory->get('markaspot_mail.settings')->get('features.show_platform_footer') ?? TRUE
    );

    $branding = [
      'mode' => $mode,
      'platform_name' => $platformDefaults['name'],
      'logo_url' => $platformDefaults['logo_url'],
      'primary_color' => $platformDefaults['primary_color'],
      'background_color' => $platformDefaults['background_color'],
      'support_email' => $this->sanitizeHeaderValue($platformDefaults['support_email']),
      'legal_notice_url' => $platformDefaults['legal_notice_url'],
      'privacy_url' => $platformDefaults['privacy_url'],
      'email_footer_html' => Markup::create(''),
      'reply_to' => $this->sanitizeHeaderValue($platformDefaults['reply_to']),
      'frontend_base_url' => $platformDefaults['frontend_base_url'],
      'jurisdiction_slug' => NULL,
      'jurisdiction_label' => NULL,
      'platform_footer' => $showPlatformFooter ? $this->getPlatformFooter() : NULL,
      'show_platform_footer' => $showPlatformFooter,
    ];

    if ($mode === 'platform' || $jurisdictionId === NULL) {
      return $branding;
    }

    // Jurisdiction mode: load group + layer its values on top, soft-failing.
    $group = NULL;
    try {
      $storage = $this->entityTypeManager->getStorage('group');
      $group = $storage->load($jurisdictionId);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Unable to load jurisdiction @id for mail branding: @msg', [
        '@id' => $jurisdictionId,
        '@msg' => $e->getMessage(),
      ]);
    }

    if (!$group instanceof ContentEntityInterface || $group->bundle() !== 'jur') {
      $this->logger->warning('Mail branding requested for jurisdiction @id in mode "jurisdiction", but no jur group could be resolved. Falling back to platform branding.', [
        '@id' => $jurisdictionId,
      ]);
      // Stay in jurisdiction mode semantically, but footer-wise we have to
      // fall back to platform data. Caller still sees mode=jurisdiction so
      // they can, e.g., still pick a jurisdiction-tinted intro copy.
      return $branding;
    }

    // Apply translation if available.
    if ($group->hasTranslation($langcode)) {
      $group = $group->getTranslation($langcode);
    }

    $branding['jurisdiction_label'] = (string) $group->label();

    if ($group->hasField('field_slug') && !$group->get('field_slug')->isEmpty()) {
      $branding['jurisdiction_slug'] = (string) $group->get('field_slug')->value;
    }

    if ($group->hasField('field_platform_name') && !$group->get('field_platform_name')->isEmpty()) {
      $platformName = trim((string) $group->get('field_platform_name')->value);
      if ($platformName !== '') {
        $branding['platform_name'] = $platformName;
      }
    }

    // Logo: prefer field_logo_light; absolute URL via FileUrlGenerator.
    if ($group->hasField('field_logo_light') && !$group->get('field_logo_light')->isEmpty()) {
      $logoUrl = $this->resolveFileAbsoluteUrl($group->get('field_logo_light'));
      if ($logoUrl !== NULL) {
        $branding['logo_url'] = $logoUrl;
      }
    }

    // Primary color from field_nuxt_config JSON (theme.primary).
    if ($group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $color = $this->resolvePrimaryFromNuxtConfig((string) $group->get('field_nuxt_config')->value);
      if ($color !== NULL) {
        $branding['primary_color'] = $color;
      }
    }

    // Support email from field_jurisdiction_e_mail. Sanitize CR/LF/NUL before
    // it flows into Reply-To.
    if ($group->hasField('field_jurisdiction_e_mail') && !$group->get('field_jurisdiction_e_mail')->isEmpty()) {
      $supportEmail = $this->sanitizeHeaderValue(trim((string) $group->get('field_jurisdiction_e_mail')->value));
      if ($supportEmail !== '') {
        $branding['support_email'] = $supportEmail;
        $branding['reply_to'] = $supportEmail;
      }
    }

    // field_email_footer: string_long. Treat as plain text; wrap in <p>.
    if ($group->hasField('field_email_footer') && !$group->get('field_email_footer')->isEmpty()) {
      $raw = (string) $group->get('field_email_footer')->value;
      $branding['email_footer_html'] = $this->toSafeFooterHtml($raw);
    }

    // In jurisdiction mode, the tenant's privacy / legal pages live on the
    // tenant frontend, not on mark-a-spot.com. Resolve the tenant base from
    // the configurable template, falling back to the platform base when the
    // template isn't usable (e.g. no slug).
    $slug = $branding['jurisdiction_slug'];
    $tenantBase = $this->resolveTenantFrontendBase($slug, $platformDefaults);
    $branding['frontend_base_url'] = $tenantBase;
    $frontendBase = rtrim($tenantBase, '/');

    $legalNoticeUrl = $this->resolveLegalUrl(
      $group->hasField('field_legal_notice') ? $group->get('field_legal_notice') : NULL,
      $slug,
      'legal-notice',
      $frontendBase,
    );
    if ($legalNoticeUrl !== NULL) {
      $branding['legal_notice_url'] = $legalNoticeUrl;
    }

    $privacyUrl = $this->resolveLegalUrl(
      $group->hasField('field_privacy_policy') ? $group->get('field_privacy_policy') : NULL,
      $slug,
      'privacy',
      $frontendBase,
    );
    if ($privacyUrl !== NULL) {
      $branding['privacy_url'] = $privacyUrl;
    }

    // Soft-fail: jurisdiction mode requires at least *some* impressum.
    // legal_notice_url already has a platform fallback, but warn if the
    // jurisdiction didn't contribute one.
    if ($legalNoticeUrl === NULL) {
      $this->logger->warning('Jurisdiction @id has no resolvable field_legal_notice; using platform impressum.', [
        '@id' => $jurisdictionId,
      ]);
    }

    return $branding;
  }

  /**
   * Returns the platform-level defaults, layering settings + system.site.
   */
  private function getPlatformDefaults(): array {
    $settings = $this->configFactory->get('markaspot_mail.settings');
    $site = $this->configFactory->get('system.site');

    $name = (string) ($settings->get('platform.name') ?? '');
    if ($name === '') {
      $name = (string) ($site->get('name') ?? 'Mark-a-Spot');
    }

    $supportEmail = (string) ($settings->get('platform.support_email') ?? '');
    if ($supportEmail === '') {
      $supportEmail = (string) ($site->get('mail') ?? 'support@civic-patches.com');
    }

    $replyTo = (string) ($settings->get('platform.reply_to') ?? '') ?: $supportEmail;

    $primary = $this->normalizeColor((string) ($settings->get('platform.primary_color') ?? ''), self::DEFAULT_PRIMARY);
    $background = $this->normalizeColor((string) ($settings->get('platform.background_color') ?? ''), self::DEFAULT_BACKGROUND);

    // Validate all URL-ish config against an http(s) allowlist so a bad
    // setting can never inject javascript: / data: / file: into a mail.
    $legalNotice = $this->validateHttpUrl(
      (string) ($settings->get('platform.legal_notice_url') ?? ''),
      'https://civicpatches.de/impressum',
    );
    $privacy = $this->validateHttpUrl(
      (string) ($settings->get('platform.privacy_url') ?? ''),
      'https://civicpatches.de/datenschutz',
    );

    // Logo: platform logo ships inside the module as a PNG asset. If the
    // file doesn't exist on disk we still hand back the URL; downstream the
    // template will render an alt-text fallback. We don't want branding
    // resolution to hard-fail on a missing static asset.
    $logoPath = (string) ($settings->get('platform.logo_path') ?? 'images/mark-a-spot-logo@2x.png');
    $logoUrl = $this->buildModuleAssetUrl($logoPath);

    // Frontend base: prefer an explicit setting, otherwise fall back to
    // mark-a-spot.com. Still validate to keep the allowlist promise.
    $frontendBase = $this->validateHttpUrl(
      (string) ($settings->get('platform.frontend_base_url') ?? ''),
      'https://mark-a-spot.com',
    );

    // Tenant template: used in jurisdiction mode to build
    // <template with {slug}> -> e.g. https://{slug}.civicspot.io. Only the
    // resolved (slug-substituted) URL is validated; the template itself
    // must contain the {slug} placeholder.
    $tenantTemplate = (string) ($settings->get('platform.tenant_frontend_base_template') ?? '');

    return [
      'name' => $name,
      'support_email' => $supportEmail,
      'reply_to' => $replyTo,
      'primary_color' => $primary,
      'background_color' => $background,
      'legal_notice_url' => $legalNotice,
      'privacy_url' => $privacy,
      'logo_url' => $logoUrl,
      'frontend_base_url' => $frontendBase,
      'tenant_frontend_base_template' => $tenantTemplate,
    ];
  }

  /**
   * Returns the Platform Zone-2 footer link bundle.
   */
  private function getPlatformFooter(): array {
    return [
      'mas_link' => 'https://mark-a-spot.com',
      'docs_link' => 'https://mark-a-spot.com/docs',
      'civicspot_link' => 'https://civicspot.io',
      'impressum_link' => 'https://civicpatches.de/impressum',
      'datenschutz_link' => 'https://civicpatches.de/datenschutz',
      'copyright' => '© ' . date('Y') . ' Civic Patches GmbH',
    ];
  }

  /**
   * Resolves the tenant frontend base URL (jurisdiction mode).
   *
   * Prefers the configurable template (e.g. "https://{slug}.civicspot.io")
   * with {slug} substituted from the group. Falls back to the platform
   * frontend base when no slug is available OR the substituted URL fails
   * http(s) validation.
   */
  private function resolveTenantFrontendBase(?string $slug, array $platformDefaults): string {
    $template = (string) ($platformDefaults['tenant_frontend_base_template'] ?? '');
    $platformBase = (string) $platformDefaults['frontend_base_url'];
    if ($slug === NULL || $slug === '' || $template === '' || !str_contains($template, '{slug}')) {
      return $platformBase;
    }
    $candidate = str_replace('{slug}', $slug, $template);
    return $this->validateHttpUrl($candidate, $platformBase);
  }

  /**
   * Absolute URL for a file-reference item (field_logo_light etc.).
   */
  private function resolveFileAbsoluteUrl($fieldItemList): ?string {
    try {
      $entity = $fieldItemList->entity;
      if ($entity === NULL) {
        return NULL;
      }
      $uri = (string) $entity->getFileUri();
      if ($uri === '') {
        return NULL;
      }
      return $this->fileUrlGenerator->generateAbsoluteString($uri);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not resolve absolute URL for jurisdiction logo: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Extracts the primary color from field_nuxt_config JSON.
   *
   * Returns a normalized HEX color or NULL if nothing usable was found.
   */
  private function resolvePrimaryFromNuxtConfig(string $json): ?string {
    $decoded = json_decode($json, TRUE);
    if (!is_array($decoded)) {
      return NULL;
    }
    $raw = $decoded['theme']['primary'] ?? NULL;
    if (!is_string($raw) || $raw === '') {
      return NULL;
    }
    return $this->normalizeColor($raw, self::DEFAULT_PRIMARY);
  }

  /**
   * Normalizes a color string (Tailwind name OR #RRGGBB) to a HEX value.
   *
   * Unknown tokens fall back to $fallback.
   */
  private function normalizeColor(string $raw, string $fallback): string {
    $raw = trim($raw);
    if ($raw === '') {
      return $fallback;
    }
    $lower = strtolower($raw);
    if (isset(self::TAILWIND_COLORS[$lower])) {
      return self::TAILWIND_COLORS[$lower];
    }
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $raw) === 1) {
      return strtolower($raw);
    }
    return $fallback;
  }

  /**
   * Resolves a legal/privacy URL from a text_long field.
   *
   * If the field contains something that looks like an absolute URL we use
   * it as-is (after http(s) validation); otherwise we build
   * <frontend_base>/<slug>/<path> when slug is available. Returns NULL when
   * nothing usable exists.
   */
  private function resolveLegalUrl($fieldItemList, ?string $slug, string $path, string $frontendBase): ?string {
    if ($fieldItemList === NULL || $fieldItemList->isEmpty()) {
      return NULL;
    }
    $raw = trim((string) $fieldItemList->value);
    if ($raw === '') {
      return NULL;
    }
    // If it looks like an absolute URL, validate + return; otherwise fall
    // through to the slug path builder.
    if (preg_match('#^https?://#i', $raw) === 1) {
      $validated = $this->validateHttpUrl($raw, '');
      if ($validated !== '') {
        return $validated;
      }
    }
    if ($slug === NULL || $slug === '' || $frontendBase === '') {
      return NULL;
    }
    return $frontendBase . '/' . $slug . '/' . ltrim($path, '/');
  }

  /**
   * Validates an http(s) URL, returning a safe fallback on any failure.
   *
   * Exposed logic: regex gate against ^https?:// (case-insensitive) + a
   * try/catch run through Url::fromUri() to exclude malformed or
   * scheme-smuggling inputs like "javascript:alert(1)" or "http:\x0a".
   */
  private function validateHttpUrl(string $url, string $fallback): string {
    $url = trim($url);
    if ($url === '') {
      return $fallback;
    }
    if (preg_match('#^https?://#i', $url) !== 1) {
      return $fallback;
    }
    try {
      Url::fromUri($url);
      return $url;
    }
    catch (\Throwable) {
      return $fallback;
    }
  }

  /**
   * Strips CR / LF / NUL bytes from a value heading for a mail header.
   *
   * Email addresses from field_jurisdiction_e_mail flow into Reply-To, so
   * stripping control bytes here is a defense-in-depth mirror of the
   * sanitization performed in MailAlterHook.
   */
  private function sanitizeHeaderValue(string $value): string {
    return str_replace(["\r", "\n", "\0"], '', $value);
  }

  /**
   * Escapes an email-footer string and wraps it into <p>-per-line HTML.
   *
   * Field_email_footer is string_long (plain text), so we don't go through
   * check_markup here. Newlines become <br>, blank lines split paragraphs.
   * Returned as a MarkupInterface so Twig's auto-escape treats it as
   * already-safe (no |raw filter needed at the call site).
   */
  private function toSafeFooterHtml(string $raw): MarkupInterface {
    $raw = trim($raw);
    if ($raw === '') {
      return Markup::create('');
    }
    $paragraphs = preg_split("/\n\s*\n/", $raw) ?: [$raw];
    $out = [];
    foreach ($paragraphs as $paragraph) {
      $escaped = htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $escaped = nl2br($escaped, FALSE);
      $out[] = '<p style="margin:0 0 12px 0;">' . $escaped . '</p>';
    }
    return Markup::create(implode('', $out));
  }

  /**
   * Builds an absolute URL to a module-local asset path.
   *
   * Falls back to a relative URL when RequestStack / ModuleExtensionList
   * are not available (e.g., under unit tests). Mail clients treat
   * relative URLs poorly, so production code always runs with both
   * services wired via the service container.
   */
  private function buildModuleAssetUrl(string $relative): string {
    $relative = ltrim($relative, '/');
    $modulePath = 'modules/contrib/markaspot/modules/markaspot_mail';
    if ($this->moduleExtensionList !== NULL) {
      try {
        $modulePath = ltrim($this->moduleExtensionList->getPath('markaspot_mail'), '/');
      }
      catch (\Throwable $e) {
        $this->logger->warning('Unable to resolve markaspot_mail module path: @msg', [
          '@msg' => $e->getMessage(),
        ]);
      }
    }
    $base = '';
    if ($this->requestStack !== NULL) {
      $request = $this->requestStack->getCurrentRequest();
      if ($request !== NULL) {
        $base = $request->getSchemeAndHttpHost();
      }
    }
    return ($base !== '' ? $base . '/' : '/') . $modulePath . '/' . $relative;
  }

}
