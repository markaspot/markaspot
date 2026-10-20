<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the server-side bounds of field_ai_system_prompt.
 */
#[TestGroup('markaspot_nuxt')]
#[RunTestsInSeparateProcesses]
final class ProjectPromptConstraintKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'file',
    'file_mdm',
    'file_mdm_exif',
    'file_mdm_font',
    'image',
    'image_effects',
    'node',
    'taxonomy',
    'media',
    'markaspot_media',
    'entity_reference_revisions',
    'paragraphs',
    'field_permissions',
    'entity',
    'flexible_permissions',
    'group',
    'gnode',
    'markaspot_validation',
    'markaspot_group',
    'serialization',
    'jsonapi',
    'markaspot_nuxt',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'group', 'group_relationship', 'group_config_wrapper'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user', 'field', 'filter', 'group']);
    // markaspot_group adds all-groups members when a group is created.
    FieldStorageConfig::create([
      'field_name' => 'field_all_groups_member',
      'entity_type' => 'user',
      'type' => 'boolean',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_all_groups_member',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'All groups member',
    ])->save();
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction', 'creator_membership' => FALSE])->save();
    // Saving the group type installs the storage markaspot_nuxt ships in
    // config/optional; markaspot_vision ships the same one.
    $this->assertNotNull(FieldStorageConfig::loadByName('group', 'field_ai_system_prompt'));
    FieldConfig::create([
      'field_name' => 'field_ai_system_prompt',
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'AI system prompt',
    ])->save();
  }

  /**
   * Returns the prompt violations of a group.
   *
   * @return string[]
   *   The violation messages on field_ai_system_prompt.
   */
  private function promptViolations(GroupInterface $group): array {
    $messages = [];
    foreach ($group->validate() as $violation) {
      if (str_starts_with($violation->getPropertyPath(), 'field_ai_system_prompt')) {
        $messages[] = (string) $violation->getMessage();
      }
    }
    return $messages;
  }

  /**
   * Creates an unsaved jurisdiction with a prompt.
   */
  private function jurisdiction(?string $prompt): GroupInterface {
    return Group::create(['type' => 'jur', 'label' => 'Musterstadt', 'field_ai_system_prompt' => $prompt]);
  }

  /**
   * The constraint is attached by field name.
   */
  public function testConstraintIsAttached(): void {
    $definitions = $this->container->get('entity_field.manager')->getFieldDefinitions('group', 'jur');
    $this->assertArrayHasKey('MarkaspotProjectPrompt', $definitions['field_ai_system_prompt']->getConstraints());
  }

  /**
   * New values follow the onboarding rules.
   */
  public function testNewValues(): void {
    $this->assertSame([], $this->promptViolations($this->jurisdiction(NULL)));
    $this->assertSame([], $this->promptViolations($this->jurisdiction('')));
    $this->assertSame([], $this->promptViolations($this->jurisdiction("Katzen in Innenhöfen.\n\tGültig:\r\n- Sichtungen")));
    $this->assertSame([], $this->promptViolations($this->jurisdiction('  ' . str_repeat('ä', 2000) . "\n ")));

    $tooLong = $this->promptViolations($this->jurisdiction(str_repeat('ä', 2001)));
    $this->assertSame(['The image-analysis context can have at most 2000 characters (2001 given).'], $tooLong);

    foreach (["\x00", "\x07", "\x0B", "\x0C", "\x1B", "\x7F"] as $character) {
      $this->assertSame(
        ['The image-analysis context must be plain text without control characters.'],
        $this->promptViolations($this->jurisdiction('Purpose' . $character . 'text')),
        bin2hex($character),
      );
    }
  }

  /**
   * A stored value is never blocked; only a new or changed one is checked.
   */
  public function testExistingValuesAreNeverBlocked(): void {
    $legacy = str_repeat('Alte Beschreibung. ', 200) . "\x07";
    $group = $this->jurisdiction($legacy);
    // Saved before the rule existed: save() does not validate.
    $group->save();

    $group = Group::load($group->id());
    $group->set('label', 'Musterstadt Nord');
    $this->assertSame([], $this->promptViolations($group));

    $group->set('field_ai_system_prompt', $legacy);
    $this->assertSame([], $this->promptViolations($group));

    $group->set('field_ai_system_prompt', $legacy . ' Neu.');
    $this->assertCount(2, $this->promptViolations($group));

    $group->set('field_ai_system_prompt', 'Kurzer neuer Zweck.');
    $this->assertSame([], $this->promptViolations($group));
  }

}
