<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Tests org jurisdiction field constraints.
 *
 * @group markaspot_group
 */
class OrphanOrgConstraintTest extends UnitTestCase {

  /**
   * Tests org groups require a jurisdiction.
   */
  public function testOrgJurisdictionFieldIsRequired(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $configPath = $moduleRoot . '/config/install/field.field.group.org.field_jurisdiction.yml';

    $this->assertFileExists($configPath);
    $config = Yaml::decode(file_get_contents($configPath));

    $this->assertSame('group.org.field_jurisdiction', $config['id']);
    $this->assertTrue($config['required']);
    $this->assertSame(['jur' => 'jur'], $config['settings']['handler_settings']['target_bundles']);
  }

  /**
   * Tests existing installs have an update path for the required flag.
   */
  public function testRequiredFlagUpdatePathExists(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $installFile = $moduleRoot . '/markaspot_group.install';
    $source = file_get_contents($installFile);

    $this->assertStringContainsString('function markaspot_group_update_11922', $source);
    $this->assertStringContainsString("getEditable('field.field.group.org.field_jurisdiction')", $source);
    $this->assertStringContainsString("set('required', TRUE)", $source);
    $this->assertStringContainsString('Existing orphan org groups still require manual jurisdiction assignment', $source);
  }

  /**
   * Tests the group entity receives the root-jurisdiction constraint.
   */
  public function testOrgRootJurisdictionConstraintIsRegistered(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $moduleFile = $moduleRoot . '/markaspot_group.module';
    $source = file_get_contents($moduleFile);

    $this->assertStringContainsString("addConstraint('OrgRootJurisdictionReference')", $source);
    $this->assertStringContainsString("addConstraint('JurisdictionParentReference')", $source);
  }

  /**
   * Tests protected group saves also enforce hierarchy constraints.
   */
  public function testProtectedGroupSaveGuardsHierarchyConstraints(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $protectedGroupFile = $moduleRoot . '/src/Entity/ProtectedGroup.php';
    $source = file_get_contents($protectedGroupFile);

    $this->assertStringContainsString('JurisdictionParentReferenceConstraint', $source);
    $this->assertStringContainsString('OrgRootJurisdictionReferenceConstraint', $source);
    $this->assertStringContainsString('validateProtectedTranslations', $source);
    $this->assertStringContainsString('self::isProtectedBundle($this->bundle())', $source);
    $this->assertStringContainsString("get('jurisdiction_group_type') ?: 'jur'", $source);
  }

}
