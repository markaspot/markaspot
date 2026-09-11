<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Service;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Maps validated v1 values to the installed Drupal field representations.
 */
final class TenantImportFieldMapper {

  /**
   * Builds category field values that do not depend on imported entity IDs.
   */
  public function categoryFieldValues(TermInterface $term, array $category, int $index, int $rootId): array {
    return [
      'description' => trim((string) $category['description']) === ''
        ? []
        : $this->textValue((string) $category['description']),
      'weight' => $term->isNew() ? $index : (int) $term->getWeight(),
      'field_service_code' => (string) $category['code'],
      'field_category_hex' => $this->colourValue($term, 'field_category_hex', (string) $category['hex']),
      'field_category_icon' => (string) $category['icon'],
      'field_service_definition' => $this->textValue($this->serviceDefinitionJson($category)),
      'field_jurisdiction' => ['target_id' => $rootId],
    ];
  }

  /**
   * Builds status field values.
   */
  public function statusFieldValues(TermInterface $term, array $status, int $rootId): array {
    $open311 = $status['kind'] === 'initial' ? 'initial' : (string) $status['open311'];
    $notificationKey = NULL;
    if ($status['notify_citizen'] === TRUE) {
      $notificationKey = $status['open311'] === 'closed' ? 'status_closed' : 'status_open';
    }
    return [
      'description' => trim((string) $status['description']) === ''
        ? []
        : $this->textValue((string) $status['description']),
      'weight' => (int) $status['weight'],
      'field_status_hex' => $this->colourValue($term, 'field_status_hex', (string) $status['hex']),
      'field_status_icon' => (string) $status['icon'],
      'field_open311_mapping' => $open311,
      'field_notification_key' => $notificationKey,
      'field_status_definition' => $term->get('field_status_definition')->isEmpty()
        ? $this->textValue('{"attributes":[]}')
        : $term->get('field_status_definition')->getValue(),
      'field_jurisdiction' => ['target_id' => $rootId],
    ];
  }

  /**
   * Builds Jurisdiction fields from filled tenant values only.
   */
  public function jurisdictionFieldValues(array $tenant): array {
    $values = [];
    $mapping = [
      'platform_name' => 'field_platform_name',
      'email' => 'field_jurisdiction_e_mail',
    ];
    foreach ($mapping as $source => $field) {
      $value = trim((string) ($tenant[$source] ?? ''));
      if ($value === '') {
        continue;
      }
      $values[$field] = $value;
    }
    $address = trim((string) ($tenant['address'] ?? ''));
    if ($address !== '') {
      $values['field_jurisdiction_address'] = $this->structuredGermanAddress(
        $address,
        trim((string) ($tenant['label'] ?? '')),
      );
    }
    return $values;
  }

  /**
   * Converts the v1 free-form German municipal address to AddressItem data.
   */
  public function structuredGermanAddress(string $address, string $organisation): array {
    $value = [
      'country_code' => 'DE',
      'organization' => $organisation,
      'address_line1' => $address,
      'postal_code' => '',
      'locality' => '',
    ];
    if (preg_match('/^(.+?),\s*(\d{5})\s+(.+)$/u', $address, $matches) === 1) {
      $value['address_line1'] = trim($matches[1]);
      $value['postal_code'] = $matches[2];
      $value['locality'] = trim($matches[3]);
    }
    return $value;
  }

  /**
   * Builds normalized Open311 service definition JSON.
   */
  public function serviceDefinitionJson(array $category): string {
    $attributes = [];
    /** @var list<array<string, mixed>> $sourceAttributes */
    $sourceAttributes = isset($category['attributes']) && is_array($category['attributes'])
      ? $category['attributes']
      : [];
    foreach ($sourceAttributes as $index => $attribute) {
      $normalized = array_intersect_key($attribute, array_flip(TenantConfigValidator::ATTRIBUTE_KEYS));
      $normalized['variable'] = isset($attribute['variable']) ? (bool) $attribute['variable'] : TRUE;
      $normalized['required'] = (bool) ($attribute['required'] ?? FALSE);
      $normalized['order'] = isset($attribute['order']) ? (int) $attribute['order'] : $index;
      $normalized['values'] = isset($attribute['values']) && is_array($attribute['values'])
        ? array_map(static fn(array $value): array => array_intersect_key($value, array_flip(['key', 'name'])), $attribute['values'])
        : [];
      $attributes[] = $normalized;
    }
    return (string) json_encode(
      ['attributes' => $attributes],
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
  }

  /**
   * Formats a text or text_long field value.
   */
  public function textValue(string $value): array {
    return ['value' => $value, 'format' => 'plain_text'];
  }

  /**
   * Formats a color for the installed field type.
   */
  public function colourValue(
    FieldableEntityInterface $entity,
    string $field,
    string $hex,
  ): mixed {
    return $entity->get($field)->getFieldDefinition()->getType() === 'color_field_type'
      ? ['color' => strtoupper($hex), 'opacity' => '1']
      : $hex;
  }

}
