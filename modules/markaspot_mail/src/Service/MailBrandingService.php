<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Service;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use enshrined\svgSanitize\Sanitizer;
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

  /**
   * Memoized operating mode value.
   *
   * Resolved on first read from $settings['markaspot_operating_mode'] and
   * reused for all subsequent calls within the same request. Avoids the
   * duplicated Settings::get + strtolower roundtrip across getPlatformDefaults
   * and computeBranding, and removes the drift risk of patching one call
   * site without the other.
   */
  private ?string $operatingMode = NULL;

  /**
   * Memoized result for the single-jurisdiction install check.
   */
  private ?bool $singleJurisdictionInstall = NULL;

  /**
   * Memoized default jurisdiction id for single-jurisdiction installs.
   */
  private bool $singleJurisdictionIdResolved = FALSE;

  /**
   * The only jurisdiction group id, or NULL for multi-tenant/unknown installs.
   */
  private ?int $singleJurisdictionId = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LanguageManagerInterface $languageManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly LoggerInterface $logger,
    private readonly ?ModuleExtensionList $moduleExtensionList = NULL,
    private readonly ?RequestStack $requestStack = NULL,
    private readonly ?FileSystemInterface $fileSystem = NULL,
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
   *   - tenant_display_name (string|null)
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

    // Operating mode is the master switch for whether Civic-Patches /
    // Mark-a-Spot branding is allowed to surface in mail at all. SaaS
    // tenants (running under civicspot.io) are entitled to the platform
    // promo; self-hosted Kommunen are NOT — they are the sole legal
    // contact and any Civic-Patches link in their citizen mail would
    // misrepresent the service provider. The mode is read from the
    // markaspot_operating_mode setting (mapped from MARKASPOT_OPERATING_MODE
    // in markaspot-cloud/docker/settings.php) and defaults to self_hosted
    // when unset, so a fresh install ships clean.
    $isSaas = $this->getOperatingMode() === 'saas';
    // The legacy features.show_platform_footer flag still wins when
    // explicitly set; in SaaS mode it defaults true (current behaviour),
    // in self_hosted mode it is forced false regardless of config so a
    // Civic-Patches footer cannot bleed through accidentally.
    $configFlag = $this->configFactory->get('markaspot_mail.settings')->get('features.show_platform_footer');
    $showPlatformFooter = $isSaas
      ? ($configFlag === NULL ? TRUE : (bool) $configFlag)
      : FALSE;

    $branding = [
      'mode' => $mode,
      'platform_name' => $platformDefaults['name'],
      'logo_url' => $platformDefaults['logo_url'],
      'logo_svg_inline' => NULL,
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
      'tenant_display_name' => NULL,
      'platform_footer' => $showPlatformFooter ? $this->getPlatformFooter() : NULL,
      'show_platform_footer' => $showPlatformFooter,
    ];

    // In self-hosted single-jurisdiction installs the tenant is the platform.
    // Some mail flows, especially generic ECA action mails, can legitimately
    // arrive without a resolved entity context. Falling back to Mark-a-Spot in
    // that case would leak the product logo into municipal citizen mail, so
    // layer the only jurisdiction's branding for platform-mode fallbacks.
    if ($mode === 'platform' && $jurisdictionId === NULL && !$isSaas) {
      $singleJurisdictionId = $this->resolveSingleJurisdictionId();
      if ($singleJurisdictionId !== NULL) {
        return $this->computeBranding($singleJurisdictionId, 'jurisdiction', $langcode);
      }
    }

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

    if (!$group instanceof ContentEntityInterface || $group->bundle() !== $this->jurisdictionGroupType()) {
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
      // The slug feeds straight into URL construction (resolveLegalUrl,
      // resolveTenantFrontendBase). field_slug has no pattern constraint at
      // the field level, so a malformed value like "../evil" or one with
      // path/query metacharacters could synthesize a phishing-shaped URL
      // into citizen mail. Restrict to URL-safe path segments here; reject
      // by leaving jurisdiction_slug NULL, which falls back to the platform
      // legal/privacy URLs.
      $rawSlug = (string) $group->get('field_slug')->value;
      if (preg_match('/^[a-z0-9](?:[a-z0-9\-]{0,126}[a-z0-9])?$/', $rawSlug) === 1) {
        $branding['jurisdiction_slug'] = $rawSlug;
      }
      else {
        $this->logger->warning('Mail branding rejected unsafe jurisdiction slug @slug for group @id; falling back to platform legal/privacy URLs.', [
          '@slug' => $rawSlug,
          '@id' => $jurisdictionId,
        ]);
      }
    }

    // field_platform_name overrides the display name; fall back to group label.
    $platformName = '';
    if ($group->hasField('field_platform_name') && !$group->get('field_platform_name')->isEmpty()) {
      $platformName = trim((string) $group->get('field_platform_name')->value);
    }
    $branding['platform_name'] = $platformName !== '' ? $platformName : (string) $group->label();

    // Logo: prefer field_logo_light; absolute URL via FileUrlGenerator.
    // SVG files are additionally inlined for email client compatibility —
    // Gmail, Outlook and iOS Mail all block SVG in <img src="...svg">.
    $resolvedJurisdictionLogo = FALSE;
    if ($group->hasField('field_logo_light') && !$group->get('field_logo_light')->isEmpty()) {
      $logoField = $group->get('field_logo_light');
      $logo = $this->resolveLogoField($logoField);
      if ($logo !== NULL) {
        $branding['logo_url'] = $logo['url'];
        $branding['logo_svg_inline'] = $logo['svg_inline'];
        $resolvedJurisdictionLogo = TRUE;
      }
    }

    // Some tenants keep their logo source in field_nuxt_config only because
    // the shared frontend uses theme.logos.light/dark as its brand contract.
    // Reuse that source for transactional mails when the image field is not
    // populated, so self-hosted tenants do not fall back to the platform logo.
    if (!$resolvedJurisdictionLogo && $group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $logo = $this->resolveLogoFromNuxtConfig((string) $group->get('field_nuxt_config')->value);
      if ($logo !== NULL) {
        $branding['logo_url'] = $logo['url'];
        $branding['logo_svg_inline'] = $logo['svg_inline'];
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

    // Tenant display name for the synthesized footer fallback the layout
    // template renders when field_email_footer is empty. Resolution chain:
    // field_platform_name (already in $branding['platform_name']) wins;
    // otherwise field_nuxt_config.client.name, then .shortName, then the
    // group label. Stays NULL only if the group has no usable label at all,
    // which would already trip the soft-fail guard above.
    $displayName = $platformName;
    if ($displayName === '' && $group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $clientName = $this->resolveClientNameFromNuxtConfig((string) $group->get('field_nuxt_config')->value);
      if ($clientName !== NULL) {
        $displayName = $clientName;
      }
    }
    if ($displayName === '') {
      $displayName = trim((string) $group->label());
    }
    if ($displayName !== '') {
      $branding['tenant_display_name'] = $displayName;
    }

    // In jurisdiction mode, the tenant's privacy / legal pages live on the
    // tenant frontend, not on mark-a-spot.com. Resolve the tenant base from
    // the configurable template, falling back to the platform base when the
    // template isn't usable (e.g. no slug).
    $slug = $branding['jurisdiction_slug'];
    $tenantBase = $this->resolveTenantFrontendBase($slug, $platformDefaults);
    $branding['frontend_base_url'] = $tenantBase;
    $frontendBase = rtrim($tenantBase, '/');

    // Path is 'impressum' to match the Nuxt route at
    // frontend/app/pages/[[jurisdiction]]/impressum.vue. The frontend has no
    // /<slug>/legal-notice page, so any URL synthesized from the slug must
    // land on /<slug>/impressum to avoid a 404 from the mail link.
    $allowRootLegalPath = $this->isSingleJurisdictionInstall();
    $legalNoticeUrl = $this->resolveLegalUrl(
      $group->hasField('field_legal_notice') ? $group->get('field_legal_notice') : NULL,
      $slug,
      'impressum',
      $frontendBase,
      $allowRootLegalPath,
    );
    if ($legalNoticeUrl !== NULL) {
      $branding['legal_notice_url'] = $legalNoticeUrl;
    }

    $privacyUrl = $this->resolveLegalUrl(
      $group->hasField('field_privacy_policy') ? $group->get('field_privacy_policy') : NULL,
      $slug,
      'privacy',
      $frontendBase,
      $allowRootLegalPath,
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
   * Returns the configured jurisdiction group bundle.
   */
  private function jurisdictionGroupType(): string {
    $config = $this->configFactory->get('markaspot_open311.settings');
    $configured = $config ? $config->get('jurisdiction_group_type') : NULL;

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
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
    // The civicpatches.de fallback is only legitimate when the platform
    // runs in SaaS mode (Civic Patches IS the operator); a self-hosted
    // Kommune must surface its own URLs or none at all so it doesn't
    // misrepresent its legal contact.
    $isSaas = $this->getOperatingMode() === 'saas';
    $legalNotice = $this->validateHttpUrl(
      (string) ($settings->get('platform.legal_notice_url') ?? ''),
      $isSaas ? 'https://civicpatches.de/impressum' : '',
    );
    $privacy = $this->validateHttpUrl(
      (string) ($settings->get('platform.privacy_url') ?? ''),
      $isSaas ? 'https://civicpatches.de/datenschutz' : '',
    );

    // Logo: platform logo ships inside the module as a PNG asset. Only hand
    // back module-local paths when the file exists, because PHPMailer embeds
    // relative images and can fail formatting on unreadable files.
    $logoPath = (string) ($settings->get('platform.logo_path') ?? 'images/mark-a-spot-logo@2x.png');
    $logoUrl = $this->buildModuleAssetUrl($logoPath);

    // Frontend base: prefer an explicit setting. The mark-a-spot.com
    // fallback is only legitimate in SaaS mode (Civic Patches IS the
    // operator); a self-hosted Kommune must surface its own URL or none
    // at all so a missing setting doesn't silently link citizens to a
    // foreign domain. Mirrors the SaaS-gated legal/privacy fallbacks
    // above. Still validate to keep the allowlist promise.
    $frontendBase = $this->validateHttpUrl(
      (string) ($settings->get('platform.frontend_base_url') ?? ''),
      $isSaas ? 'https://mark-a-spot.com' : '',
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
   * Resolves a file-reference logo to a mail-safe logo package.
   *
   * @return array{url: string, svg_inline: MarkupInterface|null}|null
   *   A resolved logo package, or NULL when the file reference is unusable.
   */
  private function resolveLogoField($fieldItemList): ?array {
    try {
      $entity = $fieldItemList->entity;
      if ($entity === NULL) {
        return NULL;
      }
      $uri = (string) $entity->getFileUri();
      if ($uri === '') {
        return NULL;
      }
      $mailUri = $this->preferRasterLogoUri($uri);
      $url = $this->ensureAbsolute((string) $this->fileUrlGenerator->generateAbsoluteString($mailUri));
      if ($url === NULL) {
        return NULL;
      }

      return [
        'url' => $url,
        'svg_inline' => $mailUri === $uri ? $this->readSvgInline($uri) : NULL,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not resolve absolute URL for jurisdiction logo: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Ensures a URL is absolute AND served from a publicly reachable host.
   *
   * Three-way handling:
   *   1. Protocol-relative ("//host/path"): passed through unchanged so
   *      downstream callers can decide. Avoids the classic
   *      "https://base//host/path" double-slash bug.
   *   2. Absolute ("https?://host/path"): if the host looks like a
   *      container-internal service name (no dot, localhost, docker
   *      service hostname), the host is swapped for the configured
   *      platform.backend_base_url. Otherwise the URL is returned as-is.
   *      This defends against the common misconfiguration where
   *      Drupal trusts the Host header forwarded by an in-cluster
   *      reverse proxy (e.g. nginx sends Host: demo-nginx-1) and bakes
   *      it into file URLs via FileUrlGenerator::generateAbsoluteString.
   *   3. Path-only ("/path"): prepended with the resolved base.
   *
   * When no base URL is configured and the URL still needs help, the
   * method logs a warning once (via the injected channel) and returns
   * the best-effort value so mail delivery is not blocked.
   */
  private function ensureAbsolute(string $url): ?string {
    if ($url === '') {
      return NULL;
    }
    if (str_starts_with($url, '//')) {
      return $url;
    }
    $base = $this->resolveAbsoluteBaseUrl();
    if (preg_match('~^(https?)://([^/?#]+)(.*)$~i', $url, $match) === 1) {
      $host = $match[2];
      if ($this->looksLikePublicHost($host)) {
        return $url;
      }
      if ($base !== '') {
        return rtrim($base, '/') . ($match[3] !== '' ? $match[3] : '/');
      }
      $this->logger->warning('Mail asset URL resolved with a non-public host (@url). Set markaspot_mail.settings.platform.backend_base_url so mail clients can fetch it.', [
        '@url' => $url,
      ]);
      return $url;
    }
    if ($base === '') {
      $this->logger->warning('Mail asset URL resolved to a relative path (@url). Set markaspot_mail.settings.platform.backend_base_url to an absolute URL so mail clients can fetch it.', [
        '@url' => $url,
      ]);
      return $url;
    }
    return rtrim($base, '/') . '/' . ltrim($url, '/');
  }

  /**
   * Resolves an absolute base URL for mail-embedded assets.
   *
   * Priority:
   *   1. markaspot_mail.settings.platform.backend_base_url — explicit
   *      per-tenant override, typically wired via settings.php from an
   *      environment variable in multi-tenant deployments. Authoritative
   *      because operators know their public host; a request-driven
   *      fallback can leak a container-internal hostname when the
   *      reverse-proxy trust setup isn't tight.
   *   2. Current HTTP request via RequestStack, but only when the host
   *      looks like a real public FQDN (contains a dot, isn't localhost).
   *      Useful on single-instance installs without reverse proxies.
   *   3. Empty string — caller preserves the relative URL. Mail clients
   *      will not load it, but delivery still succeeds; a warning is
   *      logged so operators can diagnose.
   */
  private function resolveAbsoluteBaseUrl(): string {
    $configured = (string) ($this->configFactory
      ->get('markaspot_mail.settings')
      ->get('platform.backend_base_url') ?? '');
    if ($configured !== '') {
      $validated = $this->validateHttpUrl($configured, '');
      if ($validated !== '') {
        return rtrim($validated, '/');
      }
      $this->logger->warning('Ignored invalid mail backend base URL from markaspot_mail.settings.platform.backend_base_url.');
    }
    if ($this->requestStack !== NULL) {
      $request = $this->requestStack->getCurrentRequest();
      if ($request !== NULL) {
        $host = (string) $request->getHost();
        if ($host !== '' && $this->looksLikePublicHost($host)) {
          return (string) $request->getSchemeAndHttpHost();
        }
      }
    }
    return '';
  }

  /**
   * Heuristic gate to keep container-internal hostnames out of mail bodies.
   *
   * Accepts anything with a dot in the hostname and rejects bare
   * single-label hosts that point at service-discovery names (drupal,
   * nginx, web, mailpit, localhost). Not a security boundary — operators
   * still pin the safe value via backend_base_url config.
   */
  private function looksLikePublicHost(string $host): bool {
    if (!str_contains($host, '.')) {
      return FALSE;
    }
    $lower = strtolower($host);
    if ($lower === 'localhost' || str_ends_with($lower, '.localhost')) {
      return FALSE;
    }
    return TRUE;
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
   * Extracts the tenant display name from field_nuxt_config JSON.
   *
   * Prefers client.name (the full marketing name) over client.shortName,
   * which is reserved for compact UI surfaces. Returns NULL when neither
   * is a usable non-empty string.
   */
  private function resolveClientNameFromNuxtConfig(string $json): ?string {
    $decoded = json_decode($json, TRUE);
    if (!is_array($decoded)) {
      return NULL;
    }
    foreach (['name', 'shortName'] as $key) {
      $candidate = $decoded['client'][$key] ?? NULL;
      if (is_string($candidate)) {
        $trimmed = trim($candidate);
        if ($trimmed !== '') {
          return $trimmed;
        }
      }
    }
    return NULL;
  }

  /**
   * Resolves the light logo from field_nuxt_config JSON.
   *
   * Tenants often already publish their frontend brand assets through
   * theme.logos.light. Accept the same admin-managed contract for mail, but
   * keep the resolver narrow: public files only. External http(s) URLs are
   * intentionally ignored here so citizen mail does not embed arbitrary
   * remote tracking pixels as tenant logos.
   *
   * @return array{url: string, svg_inline: MarkupInterface|null}|null
   *   A resolved logo package, or NULL when no safe logo reference exists.
   */
  private function resolveLogoFromNuxtConfig(string $json): ?array {
    $decoded = json_decode($json, TRUE);
    if (!is_array($decoded)) {
      return NULL;
    }
    $raw = $decoded['theme']['logos']['light'] ?? NULL;
    if (!is_string($raw) || trim($raw) === '') {
      return NULL;
    }
    return $this->resolveLogoReference(trim($raw));
  }

  /**
   * Resolves an admin-managed logo reference to a mail-safe URL.
   *
   * @return array{url: string, svg_inline: MarkupInterface|null}|null
   *   A resolved logo package, or NULL when the reference is unusable.
   */
  private function resolveLogoReference(string $raw): ?array {
    if (preg_match('/[\r\n\0]/', $raw) === 1) {
      $this->logger->warning('Rejected unsafe jurisdiction mail logo reference containing control bytes.');
      return NULL;
    }

    if (preg_match('#^https?://#i', $raw) === 1) {
      $this->logger->warning('Rejected external jurisdiction mail logo reference from frontend config.');
      return NULL;
    }

    $uri = $this->publicFileUriFromLogoReference($raw);
    if ($uri === NULL) {
      $this->logger->warning('Rejected unsupported jurisdiction mail logo reference: @path', [
        '@path' => $raw,
      ]);
      return NULL;
    }

    $mailUri = $this->preferRasterLogoUri($uri);

    try {
      $url = $this->ensureAbsolute((string) $this->fileUrlGenerator->generateAbsoluteString($mailUri));
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not resolve jurisdiction mail logo from config: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
    if ($url === NULL) {
      return NULL;
    }

    return [
      'url' => $url,
      'svg_inline' => $mailUri === $uri ? $this->readSvgInline($uri) : NULL,
    ];
  }

  /**
   * Prefers a sibling PNG for SVG public-file logos.
   */
  private function preferRasterLogoUri(string $uri): string {
    if (preg_match('/\.svg$/i', $uri) !== 1) {
      return $uri;
    }
    $candidate = (string) preg_replace('/\.svg$/i', '.png', $uri);
    return $this->fileExists($candidate) ? $candidate : $uri;
  }

  /**
   * Checks file existence without triggering warnings for unresolved wrappers.
   */
  private function fileExists(string $uri): bool {
    if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $uri) === 1) {
      $realpath = $this->fileSystem?->realpath($uri);
      return is_string($realpath) && $realpath !== '' && is_readable($realpath);
    }

    return is_readable($uri);
  }

  /**
   * Maps a frontend public-files logo path to a Drupal stream wrapper URI.
   */
  private function publicFileUriFromLogoReference(string $raw): ?string {
    $raw = str_replace('\\', '/', trim($raw));
    if ($raw === '') {
      return NULL;
    }

    if (str_starts_with($raw, 'public://')) {
      $relative = substr($raw, strlen('public://'));
    }
    else {
      $path = parse_url($raw, PHP_URL_PATH);
      $path = is_string($path) && $path !== '' ? $path : $raw;
      if (str_starts_with($path, '/sites/default/files/')) {
        $relative = substr($path, strlen('/sites/default/files/'));
      }
      elseif (str_starts_with($path, 'sites/default/files/')) {
        $relative = substr($path, strlen('sites/default/files/'));
      }
      else {
        return NULL;
      }
    }

    $relative = ltrim($relative, '/');
    if ($relative === '' || str_contains($relative, '..')) {
      return NULL;
    }

    return 'public://' . $relative;
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
   * Priority: an absolute http(s) URL in the field always wins (after
   * validation). A path-only value such as "/impressum" is anchored at the
   * tenant frontend base without adding the slug only for proven
   * single-jurisdiction installs. Multi-tenant frontends keep the path below
   * the tenant slug. Otherwise, including when the field is empty or
   * prose-only, we synthesize <frontend_base>/<slug>/<path>, which the Nuxt
   * frontend already serves for every tenant. Returns NULL only when no
   * slug/frontend base is available, which means the platform default has to
   * stand in.
   */
  private function resolveLegalUrl($fieldItemList, ?string $slug, string $path, string $frontendBase, bool $allowRootPath = FALSE): ?string {
    $raw = '';
    if ($fieldItemList !== NULL && !$fieldItemList->isEmpty()) {
      $raw = trim((string) $fieldItemList->value);
    }
    // An absolute URL in the field short-circuits the slug builder so
    // tenants can point to a hand-rolled legal page on their own domain.
    if ($raw !== '' && preg_match('#^https?://#i', $raw) === 1) {
      $validated = $this->validateHttpUrl($raw, '');
      if ($validated !== '') {
        return $validated;
      }
    }
    if ($raw !== '' && str_starts_with($raw, '/')) {
      $fieldPath = $this->normalizePathOnlyLegalUrl($raw);
      if ($fieldPath === NULL || $frontendBase === '') {
        return NULL;
      }
      if ($allowRootPath) {
        return $frontendBase . $fieldPath;
      }
      if ($slug === NULL || $slug === '') {
        return NULL;
      }
      return $frontendBase . '/' . $slug . $fieldPath;
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
   * Normalizes path-only legal URLs and rejects malformed control input.
   */
  private function normalizePathOnlyLegalUrl(string $url): ?string {
    if ($url === '' || !str_starts_with($url, '/')) {
      return NULL;
    }
    if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
      return NULL;
    }
    $normalized = '/' . ltrim($url, '/');
    return $normalized === '/' ? NULL : $normalized;
  }

  /**
   * Detects whether the install has exactly one jurisdiction group.
   */
  private function isSingleJurisdictionInstall(): bool {
    if ($this->singleJurisdictionInstall !== NULL) {
      return $this->singleJurisdictionInstall;
    }
    try {
      $storage = $this->entityTypeManager->getStorage('group');
      $count = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $this->jurisdictionGroupType())
        ->count()
        ->execute();
      $this->singleJurisdictionInstall = (int) $count === 1;
    }
    catch (\Throwable) {
      $this->singleJurisdictionInstall = FALSE;
    }
    return $this->singleJurisdictionInstall;
  }

  /**
   * Returns the only jurisdiction group id for single-jurisdiction installs.
   */
  private function resolveSingleJurisdictionId(): ?int {
    if ($this->singleJurisdictionIdResolved) {
      return $this->singleJurisdictionId;
    }
    $this->singleJurisdictionIdResolved = TRUE;

    try {
      $storage = $this->entityTypeManager->getStorage('group');
      $count = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $this->jurisdictionGroupType())
        ->count()
        ->execute();
      if ((int) $count !== 1) {
        $this->singleJurisdictionInstall = FALSE;
        return NULL;
      }

      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $this->jurisdictionGroupType())
        ->sort('id')
        ->range(0, 1)
        ->execute();
      if (!is_array($ids) || $ids === []) {
        $this->singleJurisdictionInstall = FALSE;
        return NULL;
      }

      $id = reset($ids);
      $this->singleJurisdictionId = is_numeric($id) ? (int) $id : NULL;
      $this->singleJurisdictionInstall = $this->singleJurisdictionId !== NULL;
    }
    catch (\Throwable) {
      $this->singleJurisdictionInstall = FALSE;
      $this->singleJurisdictionId = NULL;
    }

    return $this->singleJurisdictionId;
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
   * Reads an SVG file and returns sanitized inline markup for email embedding.
   *
   * SVG in <img src="...svg"> is blocked by Gmail, Outlook, and iOS Mail.
   * Inlining works in Apple Mail and Thunderbird; Gmail/Outlook strip <svg>
   * silently, leaving the alt text visible.
   *
   * Security: the SVG is passed through enshrined/svg-sanitize before being
   * wrapped in Markup::create(). This removes <script>, event handlers
   * (onload, onerror, …), <foreignObject>, and external resource references
   * (xlink:href, href to external URLs) that would otherwise enable stored XSS
   * or tracking pixel leaks via Apple Mail / Thunderbird rendering.
   *
   * Returns NULL for non-SVG URIs, unreadable files, or files over 100 KB.
   */
  private function readSvgInline(string $uri): ?MarkupInterface {
    if (preg_match('/\.svg$/i', $uri) !== 1) {
      return NULL;
    }
    $maxBytes = 100 * 1024;
    $content = @file_get_contents($uri, FALSE, NULL, 0, $maxBytes + 1);
    if ($content === FALSE || strlen($content) > $maxBytes) {
      return NULL;
    }
    // Sanitize: remove <script>, event handlers, <foreignObject>, and external
    // resource references before the content is trusted as safe HTML.
    // removeRemoteReferences(TRUE) also closes privacy-leak vectors via
    // <image xlink:href="https://..."> tracking pixels.
    $sanitizer = new Sanitizer();
    $sanitizer->removeRemoteReferences(TRUE);
    $clean = $sanitizer->sanitize($content);
    if ($clean === FALSE || $clean === '') {
      return NULL;
    }
    // Strip XML declaration and DOCTYPE — invalid inside HTML5 <body>.
    $clean = (string) preg_replace('/^<\?xml[^?]*\?>\s*/i', '', $clean);
    $clean = (string) preg_replace('/<!DOCTYPE[^>]*>\s*/i', '', $clean);
    // Inject email-safe sizing on the root <svg> element. Prepending our
    // width/height means they win over any existing presentational attributes
    // (first attribute wins in HTML parsing). The inline style takes CSS
    // precedence on supporting clients.
    $clean = (string) preg_replace(
      '/<svg\b/i',
      '<svg width="160" style="display:block; max-width:160px; height:auto; border:0; outline:none;"',
      $clean,
      1,
    );
    $trimmed = trim($clean);
    if ($trimmed === '') {
      return NULL;
    }
    // Sanitizer::sanitize() already removed scripts, event handlers, and
    // external references. Safe to wrap in Markup::create().
    return Markup::create($trimmed);
  }

  /**
   * Resolves a logo asset reference for mail rendering.
   *
   * Accepts two input shapes:
   * - Absolute http(s) URL (e.g. a tenant-uploaded wappen served from the
   *   public file system). Passed through after the standard allowlist
   *   check; mail-client-incompatible schemes (data:, javascript:, ...)
   *   are not detected here and fall into the module-asset branch where
   *   they render as a broken URL — caller validates source.
   * - Module-relative asset path (e.g. images/mark-a-spot-logo@2x.png).
   *   Resolved to a Drupal-root-relative URL. phpmailer_smtp passes the
   *   rendered HTML through PHPMailer::msgHTML($html, DRUPAL_ROOT), and
   *   PHPMailer only embeds relative local image paths. Keeping module
   *   assets relative here lets the mailer convert them to cid: images
   *   instead of making inbox clients fetch a public HTTP URL. Missing
   *   module-local assets return an empty string so Twig renders the text
   *   fallback and mail delivery still succeeds.
   */
  private function buildModuleAssetUrl(string $relative): string {
    // Allow tenants to point platform.logo_path at an absolute URL (e.g. a
    // jurisdiction wappen served from the file system). Pass through after
    // the same allowlist validation used elsewhere; reject schemes we cannot
    // safely embed in mail (data:, javascript:, etc.).
    if (preg_match('#^https?://#i', $relative)) {
      return $this->ensureAbsolute($relative) ?? $relative;
    }
    $relative = ltrim($relative, '/');
    if ($relative === '' || str_contains($relative, '..')) {
      $this->logger->warning('Rejected unsafe mail module logo path: @path', [
        '@path' => $relative,
      ]);
      return '';
    }
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
    $rootRelative = '/' . $modulePath . '/' . $relative;
    if (defined('DRUPAL_ROOT') && !is_readable(DRUPAL_ROOT . $rootRelative)) {
      $this->logger->warning('Mail module logo path is not readable: @path', [
        '@path' => $rootRelative,
      ]);
      return '';
    }
    return $rootRelative;
  }

  /**
   * Returns the platform operating mode.
   *
   * Read from $settings['markaspot_operating_mode'] so the value is
   * pinned at bootstrap time and cannot be flipped via cim. The cloud
   * image maps the MARKASPOT_OPERATING_MODE env var to this setting in
   * markaspot-cloud/docker/settings.php; reading via Settings::get()
   * keeps the service free of putenv/getenv globalstate that bleeds
   * across paratest workers and lets Kernel tests override the value
   * via new Settings([...]).
   *
   * Two values:
   *
   * - "saas": platform runs under civicspot.io / mark-a-spot.com,
   *   Civic Patches GmbH is the legal operator. The CivicSpot promo
   *   footer + Civic-Patches Impressum/Datenschutz fallbacks are
   *   appropriate.
   * - "self_hosted": a Kommune (or any other independent operator)
   *   runs the platform on their own infrastructure. Civic Patches is
   *   neither operator nor data processor for this deployment, so any
   *   Civic-Patches link in citizen-facing mail would misrepresent the
   *   service provider. Default when the setting is unset, so a fresh
   *   install ships clean.
   *
   * Anything other than the literal "saas" (case-insensitive) falls
   * back to self_hosted on the safer-default principle.
   */
  private function getOperatingMode(): string {
    if ($this->operatingMode !== NULL) {
      return $this->operatingMode;
    }
    $raw = strtolower(trim((string) Settings::get('markaspot_operating_mode', '')));
    return $this->operatingMode = $raw === 'saas' ? 'saas' : 'self_hosted';
  }

}
