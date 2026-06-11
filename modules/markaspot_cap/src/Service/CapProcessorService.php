<?php

namespace Drupal\markaspot_cap\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;

/**
 * Service for processing service requests into CAP format.
 *
 * PII-safe: senderName comes from config, never from reporter fields.
 * areaDesc uses jurisdiction/site name, not the full street address.
 * GPS circle is coarsened to 2 decimal places (roughly 1 km resolution).
 */
class CapProcessorService {

  /**
   * Radius (km) appended to every GPS circle element.
   */
  const CIRCLE_RADIUS_KM = 1;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    StateInterface $state,
    EntityFieldManagerInterface $entity_field_manager,
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * Convert a service request node to CAP format.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return array
   *   CAP formatted data.
   */
  public function nodeToCapAlert(NodeInterface $node): array {
    $capConfig = $this->configFactory->get('markaspot_cap.settings');
    $siteConfig = $this->configFactory->get('system.site');

    // senderName from CAP config, falling back to site name -- never from PII.
    $senderName = (string) ($capConfig->get('sender.name') ?: $siteConfig->get('name') ?: 'City Services');

    // CAP alert-level defaults from config, with built-in fallbacks.
    $defaults = (array) ($capConfig->get('defaults') ?: []);
    $msgType = $defaults['msg_type'] ?? 'Alert';
    $scope = $defaults['scope'] ?? 'Public';

    $alert = [
      'identifier' => $node->get('request_id')->value,
      'sender' => $siteConfig->get('mail') ?? 'noreply@example.com',
      'sent' => $this->formatDateTime((int) $node->get('created')->value),
      'status' => 'Actual',
      'msgType' => $msgType,
      'scope' => $scope,
      'info' => $this->buildInfoElement($node, $senderName, $defaults),
    ];

    return $alert;
  }

  /**
   * Build CAP info element from node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param string $senderName
   *   The PII-safe sender name from config.
   * @param array $defaults
   *   The defaults array from markaspot_cap.settings.
   *
   * @return array
   *   CAP info structure.
   */
  private function buildInfoElement(NodeInterface $node, string $senderName, array $defaults = []): array {
    $info = [
      'category' => $defaults['category'] ?? 'Other',
      'event' => $node->get('field_category')->entity ? $node->get('field_category')->entity->label() : 'Unknown',
      'urgency' => $defaults['urgency'] ?? 'Expected',
      'severity' => $this->mapHazardLevelToSeverity($node, $defaults),
      'certainty' => $defaults['certainty'] ?? 'Observed',
      'effective' => $this->formatDateTime((int) $node->get('created')->value),
      'headline' => $node->getTitle(),
      'description' => $this->getDescription($node),
      // PII-safe: always comes from config, not from field_name.
      'senderName' => $senderName,
    ];

    if ($node->hasField('field_geolocation') && !$node->get('field_geolocation')->isEmpty()) {
      $info['area'] = $this->buildAreaElement($node);
    }

    $info['language'] = $node->language()->getId();

    return $info;
  }

  /**
   * Build CAP area element from node.
   *
   * AreaDesc uses jurisdiction name / site name -- NOT the street address.
   * GPS circle is coarsened to 2 decimal places (~1 km grid).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return array
   *   CAP area structure.
   */
  private function buildAreaElement(NodeInterface $node): array {
    $area = [];

    // areaDesc: jurisdiction name from config, not street address (B1 GDPR).
    $capConfig = $this->configFactory->get('markaspot_cap.settings');
    $siteConfig = $this->configFactory->get('system.site');
    $area['areaDesc'] = (string) ($capConfig->get('sender.name') ?: $siteConfig->get('name') ?: 'Service Area');

    // GPS circle coarsened to 2 decimal places (~1 km) (B1 GDPR).
    if ($node->hasField('field_geolocation') && !$node->get('field_geolocation')->isEmpty()) {
      $geolocation = $node->get('field_geolocation')->first();
      $lat = round((float) $geolocation->get('lat')->getValue(), 2);
      $lng = round((float) $geolocation->get('lng')->getValue(), 2);
      $area['circle'] = sprintf('%.2f,%.2f %d', $lat, $lng, self::CIRCLE_RADIUS_KM);
    }

    return $area;
  }

  /**
   * Map field_hazard_level to CAP severity (H4).
   *
   * Field_hazard_level: 0=Unknown, 1=Minor, 2=Moderate, 3=Severe, 4=Extreme.
   * Falls back to config severity_mapping if present, then built-in table.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param array $defaults
   *   The defaults array from markaspot_cap.settings (used for fallback).
   *
   * @return string
   *   CAP severity level string.
   */
  private function mapHazardLevelToSeverity(NodeInterface $node, array $defaults = []): string {
    // Only use field_hazard_level; field_priority is on a different scale.
    if (!$node->hasField('field_hazard_level') || $node->get('field_hazard_level')->isEmpty()) {
      // Use severity_mapping.default from config, then built-in fallback.
      return $this->getSeverityDefault($defaults);
    }

    $level = (int) $node->get('field_hazard_level')->value;

    // Check config-based override mapping first.
    $capConfig = $this->configFactory->get('markaspot_cap.settings');
    $configMapping = $capConfig->get('severity_mapping');
    if (is_array($configMapping) && isset($configMapping[$level])) {
      return (string) $configMapping[$level];
    }

    // Built-in table: field_hazard_level 0-4 maps ascending.
    $map = [
      0 => 'Minor',
      1 => 'Minor',
      2 => 'Moderate',
      3 => 'Severe',
      4 => 'Extreme',
    ];

    return $map[$level] ?? $this->getSeverityDefault($defaults);
  }

  /**
   * Returns the configured severity default or 'Minor' as last-resort fallback.
   *
   * @param array $defaults
   *   The defaults array from markaspot_cap.settings.
   *
   * @return string
   *   The default severity string.
   */
  private function getSeverityDefault(array $defaults): string {
    $capConfig = $this->configFactory->get('markaspot_cap.settings');
    $configMapping = $capConfig->get('severity_mapping');
    if (is_array($configMapping) && isset($configMapping['default'])) {
      return (string) $configMapping['default'];
    }
    return 'Minor';
  }

  /**
   * Get description from node body (HTML stripped).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return string
   *   Plain-text description.
   */
  private function getDescription(NodeInterface $node): string {
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      return strip_tags($node->get('body')->value);
    }
    return '';
  }

  /**
   * Format timestamp to ISO 8601 UTC.
   *
   * @param int $timestamp
   *   Unix timestamp.
   *
   * @return string
   *   ISO 8601 formatted datetime.
   */
  private function formatDateTime(int $timestamp): string {
    return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
  }

}
