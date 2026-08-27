<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers required email behavior through Drupal entity validation.
 *
 * @group service_request
 */
#[RunTestsInSeparateProcesses]
final class ServiceRequestRequiredEmailKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'service_request',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node']);

    NodeType::create([
      'type' => 'service_request',
      'name' => 'Service request',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_e_mail',
      'entity_type' => 'node',
      'type' => 'email',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_e_mail',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Email',
      'required' => TRUE,
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Status',
      'settings' => ['handler' => 'default:node'],
    ])->save();

    $this->container->get('config.storage')->write(
      'markaspot_open311.settings',
      ['status_note_auto_create' => FALSE],
    );
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Required email distinguishes new, historical empty, and cleared values.
   */
  public function testRequiredEmailLifecycleThroughEntityValidation(): void {
    $stored_definition = FieldConfig::load('node.service_request.field_e_mail');
    $this->assertNotNull($stored_definition);
    $this->assertTrue($stored_definition->isRequired());

    $runtime_definition = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('node', 'service_request')['field_e_mail'];
    $this->assertFalse($runtime_definition->isRequired());
    $this->assertArrayHasKey('ServiceRequestRequiredEmail', $runtime_definition->getConstraints());

    $new = Node::create([
      'type' => 'service_request',
      'title' => 'New without email',
    ]);
    $this->assertCount(1, $new->validate()->getByField('field_e_mail'));

    $historical = Node::create([
      'type' => 'service_request',
      'title' => 'Historical without email',
    ]);
    $historical->save();
    $historical = Node::load($historical->id());
    $this->assertNotNull($historical);
    $this->assertCount(0, $historical->validate()->getByField('field_e_mail'));

    $populated = Node::create([
      'type' => 'service_request',
      'title' => 'Existing with email',
      'field_e_mail' => 'citizen@example.test',
    ]);
    $populated->save();
    $populated = Node::load($populated->id());
    $this->assertNotNull($populated);
    $populated->set('field_e_mail', NULL);
    $this->assertCount(1, $populated->validate()->getByField('field_e_mail'));
  }

}
