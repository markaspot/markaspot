<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_privacy\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_privacy\Form\GDPRForm;
use Drupal\markaspot_privacy\ReportEmailErasure;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves public erasure scrubs all retained revisions and split siblings.
 *
 * @group markaspot_privacy
 */
#[RunTestsInSeparateProcesses]
final class ReportEmailErasureKernelTest extends KernelTestBase {

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
    'language',
    'service_request',
    'markaspot_privacy',
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
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);
    NodeType::create(['type' => 'service_request', 'name' => 'Report'])->save();
    foreach (['field_e_mail' => 'email', 'field_feedback' => 'text_long', 'field_status' => 'entity_reference'] as $field => $type) {
      FieldStorageConfig::create([
        'field_name' => $field, 'entity_type' => 'node', 'type' => $type,
        'settings' => $type === 'entity_reference' ? ['target_type' => 'node'] : [],
        'translatable' => TRUE,
      ])->save();
      FieldConfig::create([
        'field_name' => $field, 'entity_type' => 'node', 'bundle' => 'service_request',
        'translatable' => $field === 'field_feedback',
      ])->save();
    }
    User::create(['uid' => 0, 'name' => 'Anonymous', 'status' => 0])->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
  }

  /**
   * Erasure removes email and feedback from split reports and all translations.
   */
  public function testErasureCoversUntouchedEditedAndSplitReports(): void {
    $original = Node::create([
      'type' => 'service_request',
      'title' => 'Original',
      'field_e_mail' => 'original@example.invalid',
      'field_feedback' => 'Fictional original feedback',
    ]);
    $original->addTranslation('de', [
      'title' => 'Fiktive Meldung', 'field_feedback' => 'Fiktive private Antwort',
    ]);
    $original->save();
    $sibling = Node::create([
      'type' => 'service_request', 'title' => 'Split', 'field_e_mail' => 'old@example.invalid',
      'field_feedback' => 'Fictional historic feedback',
    ]);
    $sibling->save();
    $sibling->setNewRevision(TRUE);
    $sibling->set('field_e_mail', 'new@example.invalid')->set('field_feedback', NULL)->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $before = [];
    foreach ([$original, $sibling] as $node) {
      $before[$node->id()] = $storage->revisionIds($node);
    }
    $this->container->set('markaspot_dashboard.request_link', new class((int) $original->id(), (int) $sibling->id()) {

      /**
       * Constructs synthetic split links.
       */
      public function __construct(private int $source, private int $target) {}

      /**
       * Returns the linked synthetic sibling.
       */
      public function getLinksForNode(int $nid): array {
        return [['source_nid' => $this->source, 'target_nid' => $this->target]];
      }

    });
    $form_state = (new FormState())->setValue('uuid', $original->uuid());
    $form = [];
    GDPRForm::create($this->container)->submitForm($form, $form_state);
    foreach ([$original, $sibling] as $node) {
      $current = $storage->loadUnchanged($node->id());
      $this->assertFalse($current->isPublished());
      $this->assertTrue($current->get('field_feedback')->isEmpty());
      $revisions = $storage->revisionIds($current);
      $this->assertCount(count($before[$node->id()]) + 1, $revisions);
      foreach ($revisions as $vid) {
        $revision = $storage->loadRevision($vid);
        $this->assertSame(ReportEmailErasure::ERASED_EMAIL, $revision->get('field_e_mail')->value);
        $this->assertSame($node->label(), $revision->label());
        foreach ($revision->getTranslationLanguages() as $langcode => $language) {
          $this->assertTrue($revision->getTranslation($langcode)->get('field_feedback')->isEmpty());
        }
      }
    }
    $remaining = $this->container->get('database')->select('node_revision__field_e_mail', 'email')
      ->condition('field_e_mail_value', ReportEmailErasure::ERASED_EMAIL, '<>')->countQuery()->execute()->fetchField();
    $this->assertSame(0, (int) $remaining);
    foreach (['node__field_feedback', 'node_revision__field_feedback'] as $table) {
      $this->assertSame(0, (int) $this->container->get('database')->select($table)->countQuery()->execute()->fetchField());
    }
  }

  /**
   * Erasure still works on installations without the optional feedback field.
   */
  public function testErasureWithoutFeedbackField(): void {
    FieldStorageConfig::loadByName('node', 'field_feedback')->delete();
    $node = Node::create([
      'type' => 'service_request', 'title' => 'Fictional report', 'field_e_mail' => 'fictional@example.invalid',
    ]);
    $node->save();
    $this->container->get('markaspot_privacy.report_email_erasure')->erase($node);
    $this->assertSame(ReportEmailErasure::ERASED_EMAIL, Node::load($node->id())->get('field_e_mail')->value);
  }

}
