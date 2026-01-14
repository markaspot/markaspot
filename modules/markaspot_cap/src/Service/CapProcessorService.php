<?php

namespace Drupal\markaspot_cap\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Service for processing service requests into CAP format.
 */
class CapProcessorService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
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
    $config = $this->configFactory->get('system.site');

    // Build CAP alert structure.
    $alert = [
      'identifier' => $node->get('request_id')->value,
      'sender' => $config->get('mail') ?? 'noreply@example.com',
      'sent' => $this->formatDateTime($node->get('created')->value),
      'status' => 'Actual',
      'msgType' => 'Alert',
      'scope' => 'Public',
      'info' => $this->buildInfoElement($node),
    ];

    return $alert;
  }

  /**
   * Build CAP info element from node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return array
   *   CAP info structure.
   */
  private function buildInfoElement(NodeInterface $node): array {
    $info = [
      'category' => 'Other',
      'event' => $node->get('field_category')->entity ? $node->get('field_category')->entity->label() : 'Unknown',
      'urgency' => 'Expected',
      'severity' => $this->mapPriorityToSeverity($node),
      'certainty' => 'Observed',
      'effective' => $this->formatDateTime($node->get('created')->value),
      'headline' => $node->getTitle(),
      'description' => $this->getDescription($node),
      'senderName' => $this->getSenderName($node),
    ];

    // Add area information if geolocation is available.
    if ($node->hasField('field_geolocation') && !$node->get('field_geolocation')->isEmpty()) {
      $info['area'] = $this->buildAreaElement($node);
    }

    // Add language.
    $info['language'] = $node->language()->getId();

    return $info;
  }

  /**
   * Build CAP area element from node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return array
   *   CAP area structure.
   */
  private function buildAreaElement(NodeInterface $node): array {
    $area = [];

    // Get address description.
    if ($node->hasField('field_address') && !$node->get('field_address')->isEmpty()) {
      $address = $node->get('field_address')->first();
      $area['areaDesc'] = $this->formatAddress($address);
    }
    else {
      $area['areaDesc'] = 'Unknown Location';
    }

    // Get geolocation.
    if ($node->hasField('field_geolocation') && !$node->get('field_geolocation')->isEmpty()) {
      $geolocation = $node->get('field_geolocation')->first();
      $lat = $geolocation->get('lat')->getValue();
      $lng = $geolocation->get('lng')->getValue();

      // CAP circle format: "lat,long radius"
      // Using 0 radius for exact point.
      $area['circle'] = sprintf('%s,%s 0', $lat, $lng);
    }

    return $area;
  }

  /**
   * Map Drupal priority to CAP severity.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return string
   *   CAP severity level.
   */
  private function mapPriorityToSeverity(NodeInterface $node): string {
    if (!$node->hasField('field_priority') || $node->get('field_priority')->isEmpty()) {
      return 'Minor';
    }

    $priority = (int) $node->get('field_priority')->value;

    // Map Mark-a-Spot priority (0-4) to CAP severity.
    $severityMap = [
      0 => 'Extreme',   // Critical.
      1 => 'Severe',    // High.
      2 => 'Moderate',  // Medium.
      3 => 'Minor',     // Low.
      4 => 'Minor',     // Very Low.
    ];

    return $severityMap[$priority] ?? 'Minor';
  }

  /**
   * Get description from node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return string
   *   Description text.
   */
  private function getDescription(NodeInterface $node): string {
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      return strip_tags($node->get('body')->value);
    }
    return '';
  }

  /**
   * Get sender name from node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return string
   *   Sender name.
   */
  private function getSenderName(NodeInterface $node): string {
    if ($node->hasField('field_name') && !$node->get('field_name')->isEmpty()) {
      return $node->get('field_name')->value;
    }
    return 'Anonymous';
  }

  /**
   * Format address field to string.
   *
   * @param mixed $address
   *   Address field item.
   *
   * @return string
   *   Formatted address.
   */
  private function formatAddress($address): string {
    $parts = [];

    if ($address->get('address_line1')->getValue()) {
      $parts[] = $address->get('address_line1')->getValue();
    }
    if ($address->get('address_line2')->getValue()) {
      $parts[] = $address->get('address_line2')->getValue();
    }
    if ($address->get('postal_code')->getValue()) {
      $parts[] = $address->get('postal_code')->getValue();
    }
    if ($address->get('locality')->getValue()) {
      $parts[] = $address->get('locality')->getValue();
    }

    return !empty($parts) ? implode(', ', $parts) : 'Unknown Location';
  }

  /**
   * Format timestamp to ISO 8601.
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
