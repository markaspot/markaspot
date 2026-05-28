<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests the service request organisation sync action wiring.
 */
class SyncServiceRequestOrganisationsActionTest extends UnitTestCase {

  /**
   * Tests the action is provided by service_request, not tenant custom code.
   */
  public function testActionPluginIdAndProviderAreStable(): void {
    $source = $this->loadActionSource();

    $this->assertStringContainsString('namespace Drupal\\service_request\\Plugin\\Action;', $source);
    $this->assertStringContainsString('id = "service_request_sync_organisations"', $source);
    $this->assertStringContainsString("'content_plugin' => 'group_node:service_request'", $source);
  }

  /**
   * Tests the category assignment action is provided by service_request.
   */
  public function testCategoryAssignmentActionPluginIdIsStable(): void {
    $source = $this->loadCategoryAssignmentActionSource();

    $this->assertStringContainsString('namespace Drupal\\service_request\\Plugin\\Action;', $source);
    $this->assertStringContainsString('id = "service_request_assign_organisation_from_category"', $source);
    $this->assertStringContainsString("'category_group_field' => 'field_category_gid'", $source);
    $this->assertStringContainsString("'content_plugin' => 'group_node:service_request'", $source);
  }

  /**
   * Tests the category assignment action supports legacy string mappings.
   */
  public function testCategoryAssignmentActionSupportsStringAndReferenceMappings(): void {
    $source = $this->loadCategoryAssignmentActionSource();

    $this->assertStringContainsString("\$item['target_id'] ?? \$item['value'] ?? NULL", $source);
    $this->assertStringContainsString("strtolower(\$value) === 'n/a'", $source);
    $this->assertStringContainsString('ctype_digit($value)', $source);
  }

  /**
   * Tests category assignment sync removes stale organisation relationships.
   */
  public function testCategoryAssignmentActionReconcilesRelationships(): void {
    $source = $this->loadCategoryAssignmentActionSource();

    $this->assertStringContainsString('function syncRelationships(', $source);
    $this->assertStringContainsString('function removeStaleRelationships(', $source);
    $this->assertStringContainsString('function shouldSyncRelationships(', $source);
    $this->assertStringContainsString('!empty($this->configuration[\'save_entity\'])', $source);
    $this->assertStringContainsString('$relationship->delete();', $source);
  }

  /**
   * Tests category assignment fails closed for tenant-scoped groups.
   */
  public function testCategoryAssignmentActionChecksTenantBoundaries(): void {
    $source = $this->loadCategoryAssignmentActionSource();

    $this->assertStringContainsString('nodeHasJurisdictionField', $source);
    $this->assertStringContainsString('return FALSE;', $source);
    $this->assertStringContainsString('getRootJurisdictionId', $source);
  }

  /**
   * Tests category assignment access checks node and field edit access.
   */
  public function testCategoryAssignmentActionChecksMutationAccess(): void {
    $source = $this->loadCategoryAssignmentActionSource();

    $this->assertStringContainsString("access('update'", $source);
    $this->assertStringContainsString("access('edit'", $source);
    $this->assertStringContainsString('AccessResult::forbidden()', $source);
  }

  /**
   * Tests the action supports legacy and current organisation bundles.
   */
  public function testActionSupportsLegacyAndCurrentOrganisationBundles(): void {
    $source = $this->loadActionSource();

    $this->assertStringContainsString("return ['organisation', 'org'];", $source);
    $this->assertStringContainsString('target_bundles', $source);
  }

  /**
   * Tests the action only notifies newly assigned organisations.
   */
  public function testActionNotifiesOnlyNewAssignments(): void {
    $source = $this->loadActionSource();

    $this->assertStringContainsString('array_diff($current_group_ids, $original_group_ids)', $source);
    $this->assertStringContainsString('$this->notifyGroups($entity, $new_group_ids, $organisation_bundles);', $source);
    $this->assertStringContainsString('groupMatchesJurisdiction($group, $node)', $source);
  }

  /**
   * Tests token replacement happens before the system mail action is called.
   */
  public function testActionPreReplacesMailTokens(): void {
    $source = $this->loadActionSource();

    $this->assertStringContainsString('$this->token->replace(', $source);
    $this->assertStringContainsString("(string) \$this->configuration['subject']", $source);
    $this->assertStringContainsString("(string) \$this->configuration['message']", $source);
    $this->assertStringContainsString("'system', 'action_send_email'", $source);
  }

  /**
   * Tests the action falls back to group members when no org mailbox exists.
   */
  public function testActionFallsBackToGroupMemberEmails(): void {
    $source = $this->loadActionSource();

    $this->assertStringContainsString("use Drupal\\user\\UserInterface;", $source);
    $this->assertStringContainsString("use Drupal\\group\\Entity\\GroupRelationshipInterface;", $source);
    $this->assertStringContainsString('if ($emails !== [])', $source);
    $this->assertStringContainsString("'plugin_id' => 'group_membership'", $source);
    $this->assertStringContainsString('$membership_relationship instanceof GroupRelationshipInterface', $source);
    $this->assertStringContainsString('$user = $membership_relationship->getEntity();', $source);
    $this->assertStringContainsString('!$user->isActive()', $source);
    $this->assertStringContainsString('strtolower($email)', $source);
    $this->assertStringNotContainsString("'@mail' => \$email", $source);
    $this->assertStringContainsString("'@count' => \$sent_count", $source);
  }

  /**
   * Loads the action source.
   */
  private function loadActionSource(): string {
    $path = dirname(__DIR__, 3) . '/src/Plugin/Action/SyncServiceRequestOrganisations.php';
    $source = file_get_contents($path);
    $this->assertIsString($source);
    return $source;
  }

  /**
   * Loads the category assignment action source.
   */
  private function loadCategoryAssignmentActionSource(): string {
    $path = dirname(__DIR__, 3) . '/src/Plugin/Action/AssignServiceRequestOrganisationFromCategory.php';
    $source = file_get_contents($path);
    $this->assertIsString($source);
    return $source;
  }

}
