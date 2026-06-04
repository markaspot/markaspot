<?php

declare(strict_types=1);

namespace Drupal\markaspot_facility\Service;

use CommerceGuys\Addressing\Country\CountryRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages jurisdiction facility settings and service request derivation.
 */
class FacilityManager {
  /**
   * Maximum number of facilities allowed in the MVP blob.
   */
  private const MAX_ITEMS = 500;

  /**
   * Canonical facility modes accepted by the client runtime.
   *
   * Kept in sync with FacilityMode in types/clientConfig.ts. Anything outside
   * this set resolves to `exclusive` via the legacy fallback when
   * `enabled: true`, so storing arbitrary strings here creates a silent
   * drift between admin intent and citizen-facing behaviour.
   */
  public const ALLOWED_MODES = ['exclusive', 'optional', 'disabled'];

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * Country repository for ISO 3166-1 alpha-2 validation.
   *
   * @var \CommerceGuys\Addressing\Country\CountryRepositoryInterface
   */
  private CountryRepositoryInterface $countryRepository;

  /**
   * Tracks nodes whose address was locked from a selected facility.
   *
   * @var \SplObjectStorage<\Drupal\node\NodeInterface, bool>
   */
  private \SplObjectStorage $addressLocks;

  /**
   * Constructs the facility manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    CountryRepositoryInterface $country_repository,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('markaspot_facility');
    $this->countryRepository = $country_repository;
    $this->addressLocks = new \SplObjectStorage();
  }

  /**
   * Returns the normalized facilities object for dashboard responses.
   */
  public function getDashboardSettings(?GroupInterface $group): array {
    return $this->normalizeStoredSettings($this->decodeFacilitiesField($group), FALSE);
  }

  /**
   * Returns the normalized facilities object for public settings.
   */
  public function getPublicSettings(?GroupInterface $group): array {
    return $this->normalizeStoredSettings($this->decodeFacilitiesField($group), TRUE);
  }

  /**
   * Validates and stores the facilities blob on a jurisdiction group.
   */
  public function saveDashboardSettings(GroupInterface $group, array $payload): array {
    $normalized = $this->normalizeSubmittedSettings($payload);
    $source = $this->getSourceGroup($group);

    if (!$source->hasField('field_facilities')) {
      throw new \RuntimeException('field_facilities is missing on the jurisdiction group.');
    }

    $source->set(
          'field_facilities',
          json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
      );
    $source->save();

    return $normalized;
  }

  /**
   * Applies facility-derived geodata and address to a service request node.
   */
  public function applyToServiceRequest(NodeInterface $node): void {
    if (
          $node->bundle() !== 'service_request'
          || !$node->hasField('field_facility')
          || $node->get('field_facility')->isEmpty()
          || !$node->hasField('field_jurisdiction')
          || $node->get('field_jurisdiction')->isEmpty()
      ) {
      return;
    }

    $facility_id = (string) $node->get('field_facility')->value;
    $jurisdiction_item = $node->get('field_jurisdiction')->first();
    $jurisdiction_id = (int) ($jurisdiction_item->target_id ?? 0);
    if ($jurisdiction_id <= 0) {
      return;
    }

    $group = $this->entityTypeManager->getStorage('group')->load($jurisdiction_id);
    if (!$group instanceof GroupInterface) {
      return;
    }

    $settings = $this->getDashboardSettings($group);

    // In optional mode the citizen chose the position; the facility tag is
    // auto-derived from it and must not override the picked coordinates or
    // address. Preserves the `tag = f(position)` invariant documented for
    // the feature. Disabled mode should not reach this code with a facility
    // set, but we guard defensively.
    if (($settings['mode'] ?? 'disabled') !== 'exclusive') {
      return;
    }

    foreach ($settings['items'] as $facility) {
      if (($facility['id'] ?? '') !== $facility_id) {
        continue;
      }

      if ($node->hasField('field_geolocation')) {
        $node->set('field_geolocation', [
          'lat' => $facility['lat'],
          'lng' => $facility['lng'],
        ]);
      }

      if (!empty($facility['address']) && $node->hasField('field_address')) {
        $address = $this->buildFieldAddressFromFacility($facility['address'], $node, $group);
        if ($address !== NULL) {
          $node->set('field_address', $address);
          $this->addressLocks[$node] = TRUE;
        }
      }

      return;
    }

    $this->logger->warning(
          'Facility "@facility" was not found for jurisdiction @jurisdiction while saving service request @node.',
          [
            '@facility' => $facility_id,
            '@jurisdiction' => $jurisdiction_id,
            '@node' => $node->id() ?? 'new',
          ]
      );
  }

  /**
   * Returns whether the facility flow locked the node address for this save.
   */
  public function isAddressLocked(NodeInterface $node): bool {
    if ($this->addressLocks->contains($node)) {
      return TRUE;
    }

    if (
          $node->bundle() !== 'service_request'
          || !$node->hasField('field_facility')
          || $node->get('field_facility')->isEmpty()
          || !$node->hasField('field_address')
          || $node->get('field_address')->isEmpty()
      ) {
      return FALSE;
    }

    // Only treat a pre-existing address as locked in exclusive mode. In
    // optional mode the citizen authored the address, so the geocoder must
    // stay free to re-derive it from moved coordinates. Without this gate,
    // markaspot_geocoder would freeze the address on every subsequent save
    // of an optional-mode report that happens to carry a facility tag.
    if ($this->resolveEffectiveMode($node) !== 'exclusive') {
      return FALSE;
    }

    $address_line1 = $node->get('field_address')->first()?->address_line1 ?? NULL;
    return is_string($address_line1) && trim($address_line1) !== '';
  }

  /**
   * Resolves the effective facility mode for a node's jurisdiction.
   *
   * Returns NULL when the node is not a service_request, is missing a
   * jurisdiction reference, or points at a jurisdiction whose group cannot
   * be loaded. Callers should treat NULL as "mode-agnostic" and make their
   * own decision (typically: fail secure).
   */
  private function resolveEffectiveMode(NodeInterface $node): ?string {
    if (
          $node->bundle() !== 'service_request'
          || !$node->hasField('field_jurisdiction')
          || $node->get('field_jurisdiction')->isEmpty()
      ) {
      return NULL;
    }

    $jurisdiction_item = $node->get('field_jurisdiction')->first();
    $jurisdiction_id = (int) ($jurisdiction_item->target_id ?? 0);
    if ($jurisdiction_id <= 0) {
      return NULL;
    }

    $group = $this->entityTypeManager->getStorage('group')->load($jurisdiction_id);
    if (!$group instanceof GroupInterface) {
      return NULL;
    }

    $settings = $this->getDashboardSettings($group);
    return $settings['mode'] ?? 'disabled';
  }

  /**
   * Decodes the raw JSON blob from the jurisdiction field.
   */
  private function decodeFacilitiesField(?GroupInterface $group): array {
    if (!$group instanceof GroupInterface) {
      return [];
    }

    $source = $this->getSourceGroup($group);
    if (!$source->hasField('field_facilities') || $source->get('field_facilities')->isEmpty()) {
      return [];
    }

    $decoded = json_decode((string) $source->get('field_facilities')->value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Normalizes raw stored settings into the public/dashboard response shape.
   */
  private function normalizeStoredSettings(array $settings, bool $public): array {
    $normalized = [
      'enabled' => !empty($settings['enabled']),
      'hideMapPicker' => !empty($settings['hideMapPicker']),
      'items' => [],
    ];

    if (!empty($settings['label']) && is_array($settings['label'])) {
      $label = [];
      if (!empty($settings['label']['singular']) && is_string($settings['label']['singular'])) {
        $label['singular'] = trim($settings['label']['singular']);
      }
      if (!empty($settings['label']['plural']) && is_string($settings['label']['plural'])) {
        $label['plural'] = trim($settings['label']['plural']);
      }
      if ($label !== []) {
        $normalized['label'] = $label;
      }
    }

    $normalized['mode'] = $this->resolveStoredMode($settings, $normalized['enabled']);

    if (!empty($settings['items']) && is_array($settings['items'])) {
      foreach ($settings['items'] as $item) {
        if (
              !is_array($item) || empty($item['id']) || empty($item['label'])
              || !isset($item['lat'], $item['lng'])
          ) {
          continue;
        }

        $active = array_key_exists('active', $item) ? (bool) $item['active'] : TRUE;
        if ($public && !$active) {
          continue;
        }

        $normalized_item = [
          'id' => (string) $item['id'],
          'label' => (string) $item['label'],
          'lat' => (float) $item['lat'],
          'lng' => (float) $item['lng'],
          'active' => $active,
        ];

        $stored_address = $this->normalizeStoredAddress($item['address'] ?? NULL);
        if ($stored_address !== NULL) {
          $normalized_item['address'] = $stored_address;
        }
        if (!empty($item['organisationId']) && is_string($item['organisationId'])) {
          $normalized_item['organisationId'] = $item['organisationId'];
        }

        $normalized['items'][] = $normalized_item;
      }
    }

    return $normalized;
  }

  /**
   * Resolves a stored mode value to one of the canonical modes.
   *
   * Tolerates legacy rows where `mode` is absent, empty, or an obsolete slug
   * like `facility_required`. Unknown values collapse to `exclusive` when
   * the feature is enabled (matches the client runtime legacy fallback in
   * `useFacilityReporting.ts`) and to `disabled` otherwise. This keeps the
   * dashboard form from hitting a 400 on the first Save after the allowlist
   * was tightened.
   */
  private function resolveStoredMode(array $settings, bool $enabled): string {
    $raw = isset($settings['mode']) && is_string($settings['mode'])
      ? trim($settings['mode'])
      : '';
    if (in_array($raw, self::ALLOWED_MODES, TRUE)) {
      return $raw;
    }
    return $enabled ? 'exclusive' : 'disabled';
  }

  /**
   * Validates dashboard payloads and returns canonical storage data.
   */
  public function normalizeSubmittedSettings(array $payload): array {
    $allowed_keys = ['enabled', 'label', 'mode', 'hideMapPicker', 'items'];
    $unknown = array_diff(array_keys($payload), $allowed_keys);
    if ($unknown !== []) {
      throw new \InvalidArgumentException('Unknown facilities settings keys: ' . implode(', ', $unknown) . '.');
    }

    if (!array_key_exists('enabled', $payload) || !is_bool($payload['enabled'])) {
      throw new \InvalidArgumentException('enabled is required and must be a boolean.');
    }
    if (!array_key_exists('hideMapPicker', $payload) || !is_bool($payload['hideMapPicker'])) {
      throw new \InvalidArgumentException('hideMapPicker is required and must be a boolean.');
    }
    if (!array_key_exists('items', $payload) || !is_array($payload['items'])) {
      throw new \InvalidArgumentException('items is required and must be an array.');
    }
    if (count($payload['items']) > self::MAX_ITEMS) {
      throw new \InvalidArgumentException('items exceeds the maximum allowed number of facilities.');
    }

    $normalized = [
      'enabled' => $payload['enabled'],
      'hideMapPicker' => $payload['hideMapPicker'],
      'items' => [],
    ];

    if (array_key_exists('label', $payload)) {
      if (!is_array($payload['label'])) {
        throw new \InvalidArgumentException('label must be an object when provided.');
      }
      $label_unknown = array_diff(array_keys($payload['label']), ['singular', 'plural']);
      if ($label_unknown !== []) {
        throw new \InvalidArgumentException('Unknown label keys: ' . implode(', ', $label_unknown) . '.');
      }
      $label = [];
      foreach (['singular', 'plural'] as $key) {
        if (array_key_exists($key, $payload['label'])) {
          $label[$key] = $this->validateTextValue(
                $payload['label'][$key],
                "label.$key",
                120,
                TRUE
            );
        }
      }
      if ($label !== []) {
        $normalized['label'] = $label;
      }
    }

    if (array_key_exists('mode', $payload)) {
      if (!is_string($payload['mode'])) {
        throw new \InvalidArgumentException('mode must be a string when provided.');
      }
      $mode = trim($payload['mode']);
      if (!in_array($mode, self::ALLOWED_MODES, TRUE)) {
        throw new \InvalidArgumentException(sprintf(
              'mode must be one of %s.',
              implode(', ', self::ALLOWED_MODES)
          ));
      }
      $normalized['mode'] = $mode;
    }

    $seen_ids = [];
    foreach (array_values($payload['items']) as $index => $item) {
      if (!is_array($item)) {
        throw new \InvalidArgumentException("items[$index] must be an object.");
      }

      $item_unknown = array_diff(array_keys($item), [
        'id',
        'label',
        'lat',
        'lng',
        'address',
        'organisationId',
        'active',
        // Display metadata added for #381 (FacilityRow icon/description/url).
        // The Vue admin (facilities.vue) sends these, so accept them here to
        // avoid a 422 on save (#368). Stored as-is in config; the frontend is
        // responsible for safe rendering of url/icon/description.
        'icon',
        'description',
        'url',
      ]);
      if ($item_unknown !== []) {
        throw new \InvalidArgumentException("items[$index] contains unknown keys: " . implode(', ', $item_unknown) . '.');
      }

      $id = $this->validateMachineKey($item['id'] ?? NULL, "items[$index].id");
      if (isset($seen_ids[$id])) {
        throw new \InvalidArgumentException("items[$index].id must be unique.");
      }
      $seen_ids[$id] = TRUE;

      $normalized_item = [
        'id' => $id,
        'label' => $this->validateTextValue($item['label'] ?? NULL, "items[$index].label", 255),
        'lat' => $this->validateCoordinate($item['lat'] ?? NULL, "items[$index].lat", -90.0, 90.0),
        'lng' => $this->validateCoordinate($item['lng'] ?? NULL, "items[$index].lng", -180.0, 180.0),
        'active' => array_key_exists('active', $item) ? $this->validateBoolean($item['active'], "items[$index].active") : TRUE,
      ];

      if (array_key_exists('address', $item)) {
        $normalized_item['address'] = $this->validateFacilityAddress(
          $item['address'],
          "items[$index].address"
          );
      }

      if (array_key_exists('organisationId', $item)) {
        $normalized_item['organisationId'] = $this->validateTextValue(
          $item['organisationId'],
          "items[$index].organisationId",
          255
          );
      }

      $normalized['items'][] = $normalized_item;
    }

    return $normalized;
  }

  /**
   * Resolves the untranslated jurisdiction entity for non-translatable fields.
   */
  private function getSourceGroup(GroupInterface $group): GroupInterface {
    return $group->isDefaultTranslation() ? $group : $group->getUntranslated();
  }

  /**
   * Validates a machine key string.
   */
  private function validateMachineKey(mixed $value, string $path): string {
    if (!is_string($value)) {
      throw new \InvalidArgumentException("$path must be a string.");
    }
    $value = trim($value);
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/', $value)) {
      throw new \InvalidArgumentException("$path must match /^[a-z0-9][a-z0-9_-]{0,127}$/.");
    }
    return $value;
  }

  /**
   * Validates a general text value.
   */
  private function validateTextValue(mixed $value, string $path, int $max_length, bool $allow_empty = FALSE): string {
    if (!is_string($value)) {
      throw new \InvalidArgumentException("$path must be a string.");
    }
    $trimmed = trim($value);
    if ($trimmed === '' && !$allow_empty) {
      throw new \InvalidArgumentException("$path must be a non-empty string.");
    }
    if (mb_strlen($trimmed) > $max_length) {
      throw new \InvalidArgumentException("$path exceeds the maximum length of $max_length characters.");
    }
    if (strip_tags($trimmed) !== $trimmed) {
      throw new \InvalidArgumentException("$path must not contain HTML.");
    }
    return $trimmed;
  }

  /**
   * Validates a facility address payload (legacy string or structured object).
   *
   * Accepts either:
   * - a non-empty string up to 512 chars (legacy form, mirrors what tenants
   *   stored before the admin UI gained reverse geocoding), or
   * - an associative array `{address_line1, country_code?, locality?,
   *   postal_code?}` written by the new admin UI after reverse geocoding.
   *
   * Anything else is rejected. The structured form requires `address_line1`
   * and silently drops empty optional sub-keys so a `country_code: ''` from
   * a JSON null-coercion does not pollute storage. The legacy string branch
   * rejects empty/whitespace-only input so the write/read paths agree:
   * `normalizeStoredAddress()` would trim a whitespace string back to NULL,
   * which would silently lose the value on the next GET.
   *
   * @return array<string, string>|string
   *   The canonical legacy string or the canonical structured array.
   */
  private function validateFacilityAddress(mixed $value, string $path): array|string {
    if (is_string($value)) {
      return $this->validateTextValue($value, $path, 512, FALSE);
    }
    if (is_array($value)) {
      return $this->validateStructuredAddress($value, $path);
    }
    throw new \InvalidArgumentException("$path must be a string or an object.");
  }

  /**
   * Validates a structured FacilityAddress object.
   *
   * Mirrors the four sub-fields required by Drupal's Address module so the
   * admin UI's reverse-geocoded payload can be persisted as-is. Optional
   * sub-keys are skipped when not a non-empty string after trimming.
   *
   * @param array<int|string, mixed> $value
   *   The structured address candidate.
   * @param string $path
   *   The dotted path for error messages (e.g. `items[0].address`).
   *
   * @return array<string, string>
   *   The validated structured address with only present, non-empty keys.
   */
  private function validateStructuredAddress(array $value, string $path): array {
    $allowed = ['address_line1', 'country_code', 'locality', 'postal_code'];
    $unknown = array_diff(array_keys($value), $allowed);
    if ($unknown !== []) {
      throw new \InvalidArgumentException("$path contains unknown keys: " . implode(', ', $unknown) . '.');
    }

    if (!array_key_exists('address_line1', $value)) {
      throw new \InvalidArgumentException("$path.address_line1 is required.");
    }

    $structured = [
      'address_line1' => $this->validateTextValue($value['address_line1'], "$path.address_line1", 255),
    ];

    if (array_key_exists('country_code', $value) && $this->isNonEmptyString($value['country_code'])) {
      $country = strtoupper(trim((string) $value['country_code']));
      // The regex is a cheap pre-check for the shape; the actual ISO 3166-1
      // alpha-2 list lookup catches non-existent codes (XX, ZZ, EU, XK) that
      // would otherwise pass here and crash Drupal's downstream Address
      // module (commerceguys/addressing) with a field-constraint violation
      // on every subsequent service request submission.
      if (!preg_match('/^[A-Z]{2}$/', $country)) {
        throw new \InvalidArgumentException("$path.country_code must be a 2-letter ISO 3166-1 alpha-2 code.");
      }
      if (!array_key_exists($country, $this->countryRepository->getList())) {
        throw new \InvalidArgumentException("$path.country_code must be a valid ISO 3166-1 alpha-2 country code.");
      }
      $structured['country_code'] = $country;
    }

    if (array_key_exists('locality', $value) && $this->isNonEmptyString($value['locality'])) {
      $structured['locality'] = $this->validateTextValue($value['locality'], "$path.locality", 255);
    }

    if (array_key_exists('postal_code', $value) && $this->isNonEmptyString($value['postal_code'])) {
      $structured['postal_code'] = $this->validateTextValue($value['postal_code'], "$path.postal_code", 32);
    }

    return $structured;
  }

  /**
   * Returns TRUE for a string with at least one non-whitespace character.
   *
   * Used to skip empty optional address sub-keys (e.g. an undefined that
   * coerced to JSON null and surfaced as a missing/empty value).
   */
  private function isNonEmptyString(mixed $value): bool {
    return is_string($value) && trim($value) !== '';
  }

  /**
   * Normalizes a stored address value for the dashboard/public response.
   *
   * Accepts the legacy string form or a structured array previously written
   * by the admin UI. Unknown keys in the structured form are dropped; an
   * incomplete structured form (missing `address_line1`) is treated as
   * absent so a corrupted blob doesn't poison every dashboard load.
   *
   * @return array<string, string>|string|null
   *   The normalized stored address, or NULL when nothing usable is present.
   */
  private function normalizeStoredAddress(mixed $value): array|string|null {
    if (is_string($value)) {
      $trimmed = trim($value);
      return $trimmed === '' ? NULL : $trimmed;
    }
    if (!is_array($value) || !$this->isNonEmptyString($value['address_line1'] ?? NULL)) {
      return NULL;
    }
    $normalized = [
      'address_line1' => trim((string) $value['address_line1']),
    ];
    foreach (['country_code', 'locality', 'postal_code'] as $key) {
      if ($this->isNonEmptyString($value[$key] ?? NULL)) {
        $normalized[$key] = $key === 'country_code'
          ? strtoupper(trim((string) $value[$key]))
          : trim((string) $value[$key]);
      }
    }
    return $normalized;
  }

  /**
   * Builds the field_address payload for a service request from a facility.
   *
   * Returns the structured array directly when the stored facility carries
   * one; falls back to `{address_line1, country_code?}` derived from a legacy
   * string + jurisdiction country code otherwise. Returns NULL when the
   * source value yields no usable address line.
   *
   * @return array<string, string>|null
   *   The address payload for `$node->set('field_address', ...)`.
   */
  private function buildFieldAddressFromFacility(
    mixed $stored_address,
    NodeInterface $node,
    GroupInterface $group,
  ): ?array {
    if (is_array($stored_address) && $this->isNonEmptyString($stored_address['address_line1'] ?? NULL)) {
      $address = ['address_line1' => trim((string) $stored_address['address_line1'])];
      foreach (['country_code', 'locality', 'postal_code'] as $key) {
        if ($this->isNonEmptyString($stored_address[$key] ?? NULL)) {
          $address[$key] = $key === 'country_code'
            ? strtoupper(trim((string) $stored_address[$key]))
            : trim((string) $stored_address[$key]);
        }
      }
      // Only consult the jurisdiction fallback when the structured payload
      // didn't already supply a country code. Preserves the admin's intent
      // when reverse geocoding produced one.
      if (!isset($address['country_code'])) {
        $country_code = $this->resolveCountryCode($node, $group);
        if ($country_code !== NULL) {
          $address['country_code'] = $country_code;
        }
      }
      return $address;
    }

    if (is_string($stored_address) && trim($stored_address) !== '') {
      $address = ['address_line1' => trim($stored_address)];
      $country_code = $this->resolveCountryCode($node, $group);
      if ($country_code !== NULL) {
        $address['country_code'] = $country_code;
      }
      return $address;
    }

    return NULL;
  }

  /**
   * Validates a boolean value.
   */
  private function validateBoolean(mixed $value, string $path): bool {
    if (!is_bool($value)) {
      throw new \InvalidArgumentException("$path must be a boolean.");
    }
    return $value;
  }

  /**
   * Validates a latitude or longitude.
   */
  private function validateCoordinate(mixed $value, string $path, float $min, float $max): float {
    if (!is_numeric($value)) {
      throw new \InvalidArgumentException("$path must be numeric.");
    }
    $float = (float) $value;
    if ($float < $min || $float > $max) {
      throw new \InvalidArgumentException("$path must be between $min and $max.");
    }
    return $float;
  }

  /**
   * Resolves a country code from node or jurisdiction data.
   */
  private function resolveCountryCode(NodeInterface $node, GroupInterface $group): ?string {
    if ($node->hasField('field_address') && !$node->get('field_address')->isEmpty()) {
      $country = $node->get('field_address')->first()?->country_code;
      if (is_string($country) && $country !== '') {
        return $country;
      }
    }

    if ($group->hasField('field_jurisdiction_address') && !$group->get('field_jurisdiction_address')->isEmpty()) {
      $country = $group->get('field_jurisdiction_address')->first()?->country_code;
      if (is_string($country) && $country !== '') {
        return $country;
      }
    }

    return NULL;
  }

}
