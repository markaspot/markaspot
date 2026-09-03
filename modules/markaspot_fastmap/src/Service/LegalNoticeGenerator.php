<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use CommerceGuys\Addressing\Country\CountryRepositoryInterface;
use CommerceGuys\Addressing\Exception\UnknownCountryException;
use Drupal\Component\Utility\Html;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\group\Entity\GroupInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates TMG §5 conforming Impressum content from operator data.
 *
 * Renders per-locale Twig templates under templates/legal-notice/.
 * Writes the result to field_legal_notice (text_long, translatable). This
 * service is the single owner of auto-generated field_legal_notice content;
 * manual overrides must be respected via the manual-override sentinel (see
 * hasManualOverride).
 *
 * Data source map (group.jur entity):
 *   - field_jurisdiction_address (operator name and postal address)
 *   - field_jurisdiction_e_mail (operator contact email)
 *   - field_billing_tax_id (optional Umsatzsteuer-ID)
 *
 * Security:
 *   - Twig auto-escape MUST stay enabled. This service escapes every scalar
 *     value defensively before handing it to Twig. NEVER pass any raw user
 *     input through |raw in the templates.
 */
final class LegalNoticeGenerator {

  /**
   * Sentinel marking a manually edited field_legal_notice value.
   *
   * Operators (or admin tooling) can drop this HTML comment anywhere in the
   * field value to opt out of automatic re-generation. The check is
   * case-insensitive so non-pedantic editors don't silently lose protection.
   */
  public const MANUAL_OVERRIDE_SENTINEL = '<!-- manual-override -->';

  /**
   * List of supported template langcodes.
   *
   * Detection is deferred to getSupportedLangcodes() so module install in
   * tests doesn't require disk-walking at construction time.
   *
   * @var array<int, string>|null
   */
  private ?array $supportedLangcodes = NULL;

  public function __construct(
    private readonly TwigEnvironment $twig,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
    private readonly ?CountryRepositoryInterface $countryRepository = NULL,
  ) {}

  /**
   * Renders the legal notice HTML for one langcode.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group whose operator fields seed the template.
   * @param string $langcode
   *   Target langcode (e.g. 'de', 'en'). Falls back to 'en' when no template
   *   exists for the requested langcode.
   *
   * @return string
   *   Rendered HTML. Always non-empty: when operator data is missing, the
   *   template renders explicit placeholder markers so operators can spot an
   *   incomplete Impressum visually.
   */
  public function generateForGroup(GroupInterface $group, string $langcode): string {
    $resolvedLangcode = $this->resolveTemplateLangcode($langcode);
    $context = $this->buildContext($group, $resolvedLangcode);
    $templateName = sprintf('@markaspot_fastmap/legal-notice/%s.html.twig', $resolvedLangcode);

    return (string) $this->twig->load($templateName)->render($context);
  }

  /**
   * Writes generated legal notice content to the group, per langcode.
   *
   * Creates translations as needed. Skips langcodes that carry the manual
   * override sentinel. The write is wrapped in setNewRevision() with an
   * explicit revision log message so GoBD Nachvollziehbarkeit holds for
   * automatically-generated Impressum content.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group to update.
   * @param array<int, string> $langcodes
   *   Target langcodes. Empty means "all templates we ship".
   *
   * @return array<int, string>
   *   Langcodes that were actually written (excludes skipped manual overrides
   *   and excludes langcodes for which no template exists).
   */
  public function syncGroup(GroupInterface $group, array $langcodes = []): array {
    if (!$group->hasField('field_legal_notice')) {
      return [];
    }

    $targets = $langcodes !== [] ? $langcodes : $this->getSupportedLangcodes();
    $written = [];
    $skipped = [];

    foreach ($targets as $langcode) {
      $langcode = $this->resolveTemplateLangcode($langcode);

      if ($this->hasManualOverride($group, $langcode)) {
        $skipped[] = $langcode;
        continue;
      }

      // Ensure a translation exists for this langcode. group entities are
      // translatable for the legal-notice field; missing translations are
      // created on the fly.
      $translation = $this->ensureTranslation($group, $langcode);
      $html = $this->generateForGroup($translation, $langcode);

      // basic_html is the safest universally-available text format for
      // auto-generated content. full_html re-renders arbitrary HTML at
      // display time, which would defeat the Html::escape + Twig autoescape
      // defenses applied during generation. basic_html whitelists exactly
      // the tags the legal-notice templates use: h2/h3/p/br/a/em.
      $translation->set('field_legal_notice', [
        'value' => $html,
        'format' => 'basic_html',
      ]);

      // Only mark a written langcode; the actual save happens once at the
      // end with a single new revision capturing all langcode changes.
      $written[] = $langcode;
    }

    if ($written !== []) {
      try {
        $group->setNewRevision(TRUE);
        $group->setRevisionLogMessage('Auto-generated legal notice from operator address');
        $group->setRevisionCreationTime(\time());
        $group->save();
        $this->logger->info(
          'legal_notice.synced group=@id langcodes=@l skipped=@s',
          [
            '@id' => (string) $group->id(),
            '@l' => implode(',', $written),
            '@s' => $skipped !== [] ? implode(',', $skipped) : 'none',
          ]
        );
      }
      catch (\Throwable $e) {
        $this->logger->error(
          'legal_notice.sync_failed group=@id error=@msg',
          [
            '@id' => (string) $group->id(),
            '@msg' => $e->getMessage(),
          ]
        );
        throw $e;
      }
    }

    return $written;
  }

  /**
   * Checks whether the existing field_legal_notice value is manually edited.
   *
   * Returns TRUE when the value contains MANUAL_OVERRIDE_SENTINEL
   * (case-insensitive). Operators add the comment to keep their hand-curated
   * Impressum from being overwritten by automatic syncs.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to check.
   * @param string $langcode
   *   The langcode of the translation to inspect.
   */
  public function hasManualOverride(GroupInterface $group, string $langcode): bool {
    if (!$group->hasField('field_legal_notice')) {
      return FALSE;
    }

    $translation = $group->hasTranslation($langcode)
      ? $group->getTranslation($langcode)
      : $group;

    if ($translation->get('field_legal_notice')->isEmpty()) {
      return FALSE;
    }

    $value = (string) $translation->get('field_legal_notice')->value;
    return stripos($value, self::MANUAL_OVERRIDE_SENTINEL) !== FALSE;
  }

  /**
   * Returns the langcodes for which a template exists in this module.
   *
   * @return array<int, string>
   *   Sorted list of langcodes ('de', 'en', ...).
   */
  public function getSupportedLangcodes(): array {
    if ($this->supportedLangcodes !== NULL) {
      return $this->supportedLangcodes;
    }

    // Extension::getPath() returns a path relative to the app root, so
    // prepend DRUPAL_ROOT to get a usable absolute filesystem path. The
    // app.root container parameter is not always defined in test harnesses
    // where the service is constructed manually, hence the DRUPAL_ROOT
    // constant fallback (always set in any Drupal bootstrap including
    // kernel tests).
    $modulePath = $this->moduleHandler->getModule('markaspot_fastmap')->getPath();
    $root = \defined('DRUPAL_ROOT') ? DRUPAL_ROOT : '';
    $dir = ($root !== '' ? \rtrim($root, '/') . '/' : '') . $modulePath . '/templates/legal-notice';
    $langcodes = [];
    if (\is_dir($dir)) {
      foreach (\scandir($dir) ?: [] as $file) {
        if (\preg_match('/^([a-z]{2})\.html\.twig$/', $file, $m) === 1) {
          $langcodes[] = $m[1];
        }
      }
    }
    \sort($langcodes);
    $this->supportedLangcodes = $langcodes;
    return $langcodes;
  }

  /**
   * Picks a renderable template langcode, falling back to en or de.
   *
   * Order: requested langcode -> default site language -> 'en' -> 'de'
   * -> first supported langcode -> requested as-is (Twig will then error
   * loudly, which is the intended signal that the module is mis-installed).
   */
  private function resolveTemplateLangcode(string $langcode): string {
    // Defense-in-depth: reject malformed langcodes before they reach the
    // Twig template path. Twig FilesystemLoader sandboxes by namespace, but
    // explicit validation here closes the theoretical path-traversal gap if
    // a future change broadens template resolution. Accepts BCP-47 short
    // forms (en, de) plus regional/script subtags (de-ls, en-us, sr-latn).
    if (!\preg_match('/^[a-z]{2,3}(-[a-z]{2,4})?$/', $langcode)) {
      $langcode = 'en';
    }

    $supported = $this->getSupportedLangcodes();
    if (\in_array($langcode, $supported, TRUE)) {
      return $langcode;
    }

    $default = $this->languageManager->getDefaultLanguage()->getId();
    if (\in_array($default, $supported, TRUE)) {
      return $default;
    }

    foreach (['en', 'de'] as $candidate) {
      if (\in_array($candidate, $supported, TRUE)) {
        return $candidate;
      }
    }

    return $supported[0] ?? $langcode;
  }

  /**
   * Ensures the group has a translation for the given langcode.
   *
   * Returns the translation entity (or the original when langcode matches
   * the default). New translations are created with the group's stored
   * values copied from the default translation.
   */
  private function ensureTranslation(GroupInterface $group, string $langcode): GroupInterface {
    if ($group->language()->getId() === $langcode) {
      return $group;
    }
    if ($group->hasTranslation($langcode)) {
      /** @var \Drupal\group\Entity\GroupInterface $translation */
      $translation = $group->getTranslation($langcode);
      return $translation;
    }
    /** @var \Drupal\group\Entity\GroupInterface $translation */
    $translation = $group->addTranslation($langcode, $group->toArray());
    return $translation;
  }

  /**
   * Builds the Twig context array from operator fields and the optional VAT ID.
   *
   * All scalar values are escaped defensively via Html::escape() before Twig
   * auto-escape handles the rendered template.
   */
  private function buildContext(GroupInterface $group, string $langcode): array {
    $address = NULL;
    if ($group->hasField('field_jurisdiction_address') && !$group->get('field_jurisdiction_address')->isEmpty()) {
      $address = $group->get('field_jurisdiction_address')->first();
    }

    $organization = $this->readAddressProperty($address, 'organization');
    $givenName = $this->readAddressProperty($address, 'given_name');
    $familyName = $this->readAddressProperty($address, 'family_name');
    $operatorName = $organization !== ''
      ? $organization
      : \trim($givenName . ' ' . $familyName);
    $addressLine1 = $this->readAddressProperty($address, 'address_line1');
    $addressLine2 = $this->readAddressProperty($address, 'address_line2');
    $postalCode = $this->readAddressProperty($address, 'postal_code');
    $city = $this->readAddressProperty($address, 'locality');
    $countryCode = $this->readAddressProperty($address, 'country_code');
    $country = $countryCode;
    if ($countryCode !== '' && $this->countryRepository !== NULL) {
      try {
        $country = $this->countryRepository->get($countryCode, $langcode)->getName();
      }
      catch (UnknownCountryException) {
        // Preserve an unknown ISO code instead of dropping the country.
      }
    }

    $email = $this->readField($group, 'field_jurisdiction_e_mail');
    $taxId = $this->readField($group, 'field_billing_tax_id');

    $hasCompleteData = $operatorName !== ''
      && $addressLine1 !== ''
      && $postalCode !== ''
      && $city !== ''
      && $email !== '';

    return [
      'operator_name' => Html::escape($operatorName),
      'address_line1' => Html::escape($addressLine1),
      'address_line2' => Html::escape($addressLine2),
      'postal_code' => Html::escape($postalCode),
      'city' => Html::escape($city),
      'country' => Html::escape($country),
      'email' => Html::escape($email),
      'tax_id' => Html::escape($taxId),
      'has_complete_data' => $hasCompleteData,
    ];
  }

  /**
   * Reads a string field, returning '' when absent or empty.
   */
  private function readField(GroupInterface $group, string $fieldName): string {
    if (!$group->hasField($fieldName) || $group->get($fieldName)->isEmpty()) {
      return '';
    }
    return \trim((string) $group->get($fieldName)->value);
  }

  /**
   * Reads a scalar address property, returning '' when no address exists.
   */
  private function readAddressProperty(?FieldItemInterface $address, string $propertyName): string {
    if ($address === NULL) {
      return '';
    }
    return \trim((string) $address->get($propertyName)->getValue());
  }

}
