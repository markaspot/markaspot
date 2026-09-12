<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Service;

use Drupal\Component\Utility\EmailValidatorInterface;

/**
 * Validates the v1 document without loading or modifying Drupal entities.
 */
final class TenantConfigValidator {

  public const ATTRIBUTE_KEYS = [
    'code', 'datatype', 'description', 'required',
    'variable', 'order', 'values', 'media_type',
  ];

  public const ROLES = [
    'tenant_admin' => 'jur-tenant_admin',
    'moderator' => 'jur-moderator',
    'editorial' => 'jur-editorial',
    'org_member' => 'jur-org_member',
  ];

  private const STRINGS = [
    'tenant' => ['slug', 'label', 'platform_name', 'email', 'address'],
    'organisations' => ['code', 'name', 'email', 'type', 'parent_code'],
    'categories' => ['code', 'name', 'description', 'hex', 'icon', 'parent_code', 'organisation_code'],
    'statuses' => ['name', 'kind', 'open311', 'hex', 'icon', 'description'],
    'users' => ['email', 'first_name', 'last_name', 'role', 'organisation_code'],
  ];

  private const REQUIRED = [
    'tenant' => ['slug', 'label'],
    'organisations' => ['code', 'name'],
    'categories' => ['code', 'name', 'description', 'hex', 'icon'],
    'statuses' => ['name', 'kind', 'open311', 'hex', 'icon', 'description'],
    'users' => ['email', 'first_name', 'last_name', 'role'],
  ];

  /**
   * Constructs the document validator.
   */
  public function __construct(private readonly EmailValidatorInterface $emailValidator) {}

  /**
   * Returns all structural and reference errors before any entity writes.
   *
   * @param array<string, mixed> $configuration
   *   Source document.
   * @param string[] $skip
   *   Sections whose writes are skipped, not their validation.
   *
   * @return string[]
   *   Validation errors.
   */
  public function validate(array $configuration, array $skip = []): array {
    $errors = [];
    if (($configuration['version'] ?? NULL) !== 1) {
      $errors[] = 'version must be the integer 1.';
    }
    foreach ($skip as $section) {
      if (!in_array($section, TenantImporter::SECTIONS, TRUE)) {
        $errors[] = sprintf('Unknown --skip section "%s".', $section);
      }
    }
    // One typed pass replaces both preflight casts and per-section type checks.
    foreach (self::STRINGS as $section => $fields) {
      $items = $configuration[$section] ?? NULL;
      if (!is_array($items) || ($section === 'tenant' ? array_is_list($items) : !array_is_list($items))) {
        $errors[] = $section . ($section === 'tenant' ? ' must be an object.' : ' must be an array.');
        $configuration[$section] = [];
        continue;
      }
      foreach ($section === 'tenant' ? [$items] : $items as $index => $row) {
        $path = $section === 'tenant' ? $section : sprintf('%s[%d]', $section, $index);
        if (!is_array($row) || array_is_list($row)) {
          $errors[] = $path . ' must be an object.';
          unset($configuration[$section][$index]);
          continue;
        }
        foreach ($fields as $field) {
          $required = in_array($field, self::REQUIRED[$section], TRUE);
          $value = $row[$field] ?? NULL;
          if ($value === NULL && !$required) {
            continue;
          }
          $limit = str_contains($field, 'code') ? 32 : ($field === 'email' ? 254 : 255);
          if (!is_string($value)) {
            $errors[] = "$path.$field must be a string.";
            // Safe placeholders allow unrelated errors to be collected too.
            if ($section !== 'tenant') {
              $configuration[$section][$index][$field] = '';
            }
            continue;
          }
          if (mb_strlen($value) > $limit) {
            $errors[] = "$path.$field must not exceed $limit characters.";
          }
          if ($required && !in_array($field, ['description', 'first_name', 'last_name'], TRUE) && trim($value) === '') {
            $errors[] = "$path.$field must be a non-empty string.";
          }
          if (preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', $value)
            || (in_array($field, ['code', 'email', 'name', 'parent_code', 'organisation_code'], TRUE) && trim($value) !== $value)) {
            $errors[] = "$path.$field contains control characters or surrounding whitespace.";
          }
          if ($field === 'email' && $value !== '' && !$this->emailValidator->isValid($value)) {
            $errors[] = "$path.email is not a valid email address.";
          }
          if ($field === 'hex' && !preg_match('/^#[0-9a-f]{6}$/i', $value)) {
            $errors[] = "$path.hex must be a six-digit hexadecimal color.";
          }
        }
      }
    }
    // Invalid rows were excluded and invalid scalar fields replaced locally.
    // This copy is never returned or applied; the original input is unchanged.
    $categories = $configuration['categories'];
    $statuses = $configuration['statuses'];
    $users = $configuration['users'];
    $keys = [];
    foreach (['organisations' => 'code', 'categories' => 'code', 'statuses' => 'name', 'users' => 'email'] as $section => $field) {
      $keys[$section] = [];
      foreach ($configuration[$section] as $index => $row) {
        $key = mb_strtolower($row[$field]);
        if ($key === '') {
          continue;
        }
        if (isset($keys[$section][$key])) {
          $errors[] = "{$section}[$index].$field duplicates another key case-insensitively.";
        }
        $keys[$section][$key] = $row;
      }
    }
    foreach (['organisations', 'categories'] as $section) {
      foreach ($configuration[$section] as $index => $row) {
        $parent = mb_strtolower($row['parent_code'] ?? '');
        if ($parent !== '' && !isset($keys[$section][$parent])) {
          $errors[] = sprintf('%s[%d].parent_code references unknown %s "%s".', $section, $index, $section === 'categories' ? 'category' : 'organisation', $row['parent_code']);
        }
        elseif ($section === 'categories' && $parent !== '' && ($row['active'] ?? FALSE) && !($keys[$section][$parent]['active'] ?? FALSE)) {
          $errors[] = "categories[$index].parent_code references an inactive category.";
        }
      }
      $errors = array_merge($errors, $this->parentCycles($keys[$section], $section));
    }
    foreach (['categories' => $categories, 'users' => $users] as $section => $items) {
      foreach ($items as $index => $row) {
        $org = mb_strtolower($row['organisation_code'] ?? '');
        if ($org !== '' && !isset($keys['organisations'][$org])) {
          $errors[] = sprintf('%s[%d].organisation_code references unknown organisation "%s".', $section, $index, $row['organisation_code']);
        }
      }
    }
    foreach ($categories as $index => $row) {
      if (!is_bool($row['active'] ?? NULL)) {
        $errors[] = "categories[$index].active must be a boolean.";
      }
      if (array_key_exists('attributes', $row)) {
        $errors = array_merge($errors, $this->attributes($row['attributes'], "categories[$index].attributes"));
      }
    }
    $initial = 0;
    foreach ($statuses as $index => $row) {
      $initial += $row['kind'] === 'initial' ? 1 : 0;
      if (!in_array($row['kind'], ['initial', 'open', 'closed', 'archived'], TRUE)) {
        $errors[] = "statuses[$index].kind must be initial, open, closed, or archived.";
      }
      if (!in_array($row['open311'], ['open', 'closed'], TRUE)) {
        $errors[] = "statuses[$index].open311 must be open or closed.";
      }
      if (!is_bool($row['notify_citizen'] ?? NULL)) {
        $errors[] = "statuses[$index].notify_citizen must be a boolean.";
      }
      if (!is_int($row['weight'] ?? NULL)) {
        $errors[] = "statuses[$index].weight must be an integer.";
      }
    }
    if ($initial !== 1) {
      $errors[] = sprintf('statuses must contain exactly one item with kind "initial"; found %d.', $initial);
    }
    foreach ($users as $index => $row) {
      if (!isset(self::ROLES[$row['role']])) {
        $errors[] = "users[$index].role is not supported.";
      }
      if ($row['role'] === 'org_member' && empty($row['organisation_code'])) {
        $errors[] = "users[$index].organisation_code is required for role org_member.";
      }
    }
    return array_values(array_unique($errors));
  }

  /**
   * Reports tenant properties that are valid source data but are not imported.
   *
   * @return string[]
   *   One operator-facing notice per unsupported tenant property.
   */
  public function notices(array $configuration): array {
    $tenant = $configuration['tenant'] ?? [];
    if (!is_array($tenant) || array_is_list($tenant)) {
      return [];
    }

    $notices = [];
    foreach (array_diff(array_keys($tenant), self::STRINGS['tenant']) as $key) {
      $notices[] = sprintf(
        'tenant.%s: not imported by this command',
        (string) $key,
      );
    }
    return $notices;
  }

  /**
   * Validates only supported v1 attributes; unknown properties are discarded.
   *
   * @return string[]
   *   Errors.
   */
  private function attributes(mixed $attributes, string $path): array {
    if (!is_array($attributes) || !array_is_list($attributes)) {
      return [$path . ' must be a list.'];
    }
    $errors = [];
    $codes = [];
    foreach ($attributes as $index => $attribute) {
      $item = $path . '[' . $index . ']';
      if (!is_array($attribute)) {
        $errors[] = $item . ' must be an object.';
        continue;
      }
      $code = $attribute['code'] ?? NULL;
      if (!is_string($code) || mb_strlen($code) > 32 || !preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
        $errors[] = $item . '.code must be a machine name of at most 32 characters.';
      }
      elseif (isset($codes[$code])) {
        $errors[] = $item . '.code duplicates another attribute.';
      }
      else {
        $codes[$code] = TRUE;
      }
      $datatypes = ['string', 'number', 'text', 'datetime', 'singlevaluelist', 'multivaluelist', 'imagelist'];
      if (!in_array($attribute['datatype'] ?? NULL, $datatypes, TRUE)) {
        $errors[] = $item . '.datatype is not supported.';
      }
      $description = $attribute['description'] ?? NULL;
      if (!is_string($description) || trim($description) === '' || mb_strlen($description) > 255) {
        $errors[] = $item . '.description must contain 1 to 255 characters.';
      }
      foreach (['required', 'variable'] as $field) {
        if (array_key_exists($field, $attribute) && !is_bool($attribute[$field])) {
          $errors[] = $item . '.' . $field . ' must be a boolean.';
        }
      }
      if (array_key_exists('order', $attribute) && !is_int($attribute['order'])) {
        $errors[] = $item . '.order must be an integer.';
      }
      if (($attribute['datatype'] ?? NULL) === 'imagelist' || array_key_exists('media_type', $attribute)) {
        $media = $attribute['media_type'] ?? NULL;
        if (!is_string($media) || mb_strlen($media) > 32 || !preg_match('/^[a-z][a-z0-9_]*$/', $media)) {
          $errors[] = $item . '.media_type must be a machine name of at most 32 characters.';
        }
      }
      if (array_key_exists('values', $attribute)) {
        $values = $attribute['values'];
        if (!is_array($values) || !array_is_list($values)) {
          $errors[] = $item . '.values must be a list.';
          continue;
        }
        foreach ($values as $value) {
          foreach (['key', 'name'] as $field) {
            if (!is_array($value) || !is_string($value[$field] ?? NULL) || trim($value[$field]) === '' || mb_strlen($value[$field]) > 255) {
              $errors[] = $item . '.values must contain string key/name of 1 to 255 characters.';
            }
          }
        }
      }
    }
    return $errors;
  }

  /**
   * Detects cycles after parent keys and scalar types have been checked.
   *
   * @param array<string, array<string, mixed>> $rows
   *   Rows indexed by normalized code.
   * @param string $section
   *   Section label for errors.
   *
   * @return string[]
   *   Cycle errors.
   */
  private function parentCycles(array $rows, string $section): array {
    $errors = [];
    foreach (array_keys($rows) as $start) {
      $seen = [];
      $current = $start;
      while ($current !== '' && isset($rows[$current])) {
        if (isset($seen[$current])) {
          $errors[] = "$section contains a parent_code cycle.";
          break;
        }
        $seen[$current] = TRUE;
        $current = mb_strtolower($rows[$current]['parent_code'] ?? '');
      }
    }
    return $errors;
  }

}
