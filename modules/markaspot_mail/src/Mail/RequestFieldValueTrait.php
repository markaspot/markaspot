<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Resolves structured request fields for human-readable mail content.
 */
trait RequestFieldValueTrait {

  /**
   * Builds a readable address from the Address field's named properties.
   */
  protected function resolveAddressText(
    ContentEntityInterface $entity,
    string $fieldName = 'field_address',
  ): string {
    if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return '';
    }

    $values = $entity->get($fieldName)->getValue();
    $address = is_array($values[0] ?? NULL) ? $values[0] : [];
    $value = static fn(string $property): string => trim((string) ($address[$property] ?? ''));

    $postalLocality = implode(' ', array_filter([
      $value('postal_code'),
      $value('locality'),
    ], static fn(string $part): bool => $part !== ''));

    return implode(', ', array_filter([
      $value('address_line1'),
      $value('address_line2'),
      $postalLocality,
      $value('administrative_area'),
      $value('country_code'),
    ], static fn(string $part): bool => $part !== ''));
  }

  /**
   * Resolves and strips markup from a formatted body field's raw value.
   */
  protected function resolveBodyText(
    ContentEntityInterface $entity,
    string $fieldName = 'body',
  ): string {
    if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return '';
    }

    return trim(strip_tags((string) $entity->get($fieldName)->value));
  }

}
