<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression net for the user--user JSON:API resource lockdown.
 *
 * Tightening landed in 896e17c6: of every resource field on the User entity
 * only `uuid` and `name` may stay enabled (everything else — mail, roles,
 * field_internal_phone, field_internal_orga, access timestamps, ...) MUST
 * be disabled. The resource itself stays enabled (`disabled: false`) so
 * relationship sideloads on dashboard responses can still resolve the user.
 *
 * This test reads the optional install config from disk so a future
 * `cset jsonapi_extras.jsonapi_resource_config.user--user ...` slip-up
 * surfaces in CI rather than silently exposing PII on prod.
 *
 * @group markaspot_dashboard
 */
final class JsonApiUserResourceConfigTest extends UnitTestCase {

  /**
   * Fields that MUST stay enabled (whitelist).
   *
   * Everything else in the resource is expected to be `disabled: true`.
   */
  private const ENABLED_FIELDS = ['uuid', 'name'];

  /**
   * The parsed resource config.
   *
   * @var array<string, mixed>
   */
  private array $config;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The config lives in markaspot_nuxt (markaspot_dashboard depends on it).
    // dirname(__DIR__, 3) -> .../markaspot_dashboard.
    $path = dirname(__DIR__, 4) . '/markaspot_nuxt/config/optional/jsonapi_extras.jsonapi_resource_config.user--user.yml';
    $this->assertFileExists($path, 'user--user resource lockdown config must ship with the profile.');
    $this->config = Yaml::parseFile($path);
  }

  /**
   * Resource-level `disabled` must stay false (otherwise sideloads break).
   */
  public function testResourceIsNotDisabled(): void {
    $this->assertArrayHasKey('disabled', $this->config);
    $this->assertFalse(
      $this->config['disabled'],
      'user--user resource must NOT be disabled: dashboard sideloads need to resolve the user relationship.'
    );
  }

  /**
   * Resource id and type must remain user--user.
   */
  public function testResourceTargetsUserUser(): void {
    $this->assertSame('user--user', $this->config['id'] ?? NULL);
    $this->assertSame('user--user', $this->config['resourceType'] ?? NULL);
  }

  /**
   * Only the whitelisted fields stay enabled; everything else is disabled.
   */
  public function testOnlyUuidAndNameAreEnabled(): void {
    $this->assertArrayHasKey('resourceFields', $this->config);
    $fields = $this->config['resourceFields'];
    $this->assertIsArray($fields);
    $this->assertNotEmpty($fields, 'resourceFields must enumerate the user entity fields explicitly.');

    $enabled = [];
    $missingDisabledFlag = [];
    foreach ($fields as $name => $definition) {
      $this->assertIsArray($definition, sprintf('Field "%s" must be an associative array.', $name));
      if (!array_key_exists('disabled', $definition)) {
        $missingDisabledFlag[] = $name;
        continue;
      }
      if ($definition['disabled'] === FALSE) {
        $enabled[] = $name;
      }
    }

    $this->assertSame(
      [],
      $missingDisabledFlag,
      'Every resource field must carry an explicit `disabled` flag.'
    );

    sort($enabled);
    $expected = self::ENABLED_FIELDS;
    sort($expected);
    $this->assertSame(
      $expected,
      $enabled,
      'Only uuid and name may be exposed on the user--user resource; everything else leaks PII.'
    );
  }

  /**
   * High-sensitivity fields are explicitly checked for `disabled: true`.
   *
   * Belt-and-braces — even if testOnlyUuidAndNameAreEnabled() were ever
   * weakened, these specific fields must remain off because they were the
   * concrete leak the lockdown closed.
   */
  public function testHighSensitivityFieldsRemainDisabled(): void {
    $mustBeDisabled = [
      'mail',
      'roles',
      'access',
      'login',
      'init',
      'pass',
      'status',
      'field_internal_orga',
      'field_internal_phone',
      'field_all_groups_member',
    ];

    foreach ($mustBeDisabled as $fieldName) {
      $this->assertArrayHasKey(
        $fieldName,
        $this->config['resourceFields'],
        sprintf('Sensitive field "%s" must be present in the resource config so its disabled state is pinned.', $fieldName)
      );
      $this->assertTrue(
        $this->config['resourceFields'][$fieldName]['disabled'] ?? FALSE,
        sprintf('Sensitive field "%s" must be disabled on the user--user resource.', $fieldName)
      );
    }
  }

  /**
   * Internal status terms must expose the staff dashboard definition contract.
   */
  public function testInternalStatusResourceExposesDashboardContract(): void {
    $path = dirname(__DIR__, 4) . '/markaspot_nuxt/config/optional/jsonapi_extras.jsonapi_resource_config.taxonomy_term--internal_status.yml';
    $this->assertFileExists($path, 'taxonomy_term--internal_status resource config must ship with the profile.');
    $config = Yaml::parseFile($path);

    $this->assertFalse($config['disabled'] ?? TRUE);
    $this->assertSame('taxonomy_term--internal_status', $config['id'] ?? NULL);
    $this->assertSame('taxonomy_term/internal_status', $config['path'] ?? NULL);

    foreach ([
      'tid',
      'name',
      'weight',
      'field_internal_status_code',
      'field_jurisdiction',
      'field_status_definition',
    ] as $fieldName) {
      $this->assertArrayHasKey(
        $fieldName,
        $config['resourceFields'],
        sprintf('Field "%s" must be pinned on taxonomy_term--internal_status.', $fieldName)
      );
      $this->assertFalse(
        $config['resourceFields'][$fieldName]['disabled'] ?? TRUE,
        sprintf('Field "%s" must be available to authenticated dashboard JSON:API calls.', $fieldName)
      );
    }
  }

}
