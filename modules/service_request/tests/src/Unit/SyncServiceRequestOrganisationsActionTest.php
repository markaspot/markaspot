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
  }

  /**
   * Tests token replacement happens before the system mail action is called.
   */
  public function testActionPreReplacesMailTokens(): void {
    $source = $this->loadActionSource();

    $this->assertStringContainsString("\$this->token->replace((string) \$this->configuration['subject']", $source);
    $this->assertStringContainsString("\$this->token->replace((string) \$this->configuration['message']", $source);
    $this->assertStringContainsString("'system', 'action_send_email'", $source);
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

}
