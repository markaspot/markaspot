<?php

declare(strict_types=1);

namespace Drupal\markaspot_facility\Service;

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
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('markaspot_facility');
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
    if ($node->bundle() !== 'service_request'
      || !$node->hasField('field_facility')
      || $node->get('field_facility')->isEmpty()
      || !$node->hasField('field_jurisdiction')
      || $node->get('field_jurisdiction')->isEmpty()) {
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
        $country_code = $this->resolveCountryCode($node, $group);
        $address = [
          'address_line1' => $facility['address'],
        ];
        if ($country_code !== NULL) {
          $address['country_code'] = $country_code;
        }
        $node->set('field_address', $address);
        $this->addressLocks[$node] = TRUE;
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

    if ($node->bundle() !== 'service_request'
      || !$node->hasField('field_facility')
      || $node->get('field_facility')->isEmpty()
      || !$node->hasField('field_address')
      || $node->get('field_address')->isEmpty()) {
      return FALSE;
    }

    $address_line1 = $node->get('field_address')->first()?->address_line1 ?? NULL;
    return is_string($address_line1) && trim($address_line1) !== '';
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

    if (!empty($settings['mode']) && is_string($settings['mode'])) {
      $normalized['mode'] = trim($settings['mode']);
    }

    if (!empty($settings['items']) && is_array($settings['items'])) {
      foreach ($settings['items'] as $item) {
        if (!is_array($item) || empty($item['id']) || empty($item['label'])
          || !isset($item['lat'], $item['lng'])) {
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

        if (!empty($item['address']) && is_string($item['address'])) {
          $normalized_item['address'] = $item['address'];
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
      if ($mode === '' || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $mode)) {
        throw new \InvalidArgumentException('mode must match /^[a-z][a-z0-9_-]{0,63}$/.');
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
        $normalized_item['address'] = $this->validateTextValue(
          $item['address'],
          "items[$index].address",
          512,
          TRUE
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
