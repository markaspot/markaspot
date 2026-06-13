<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Kernel;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Language\LanguageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Covers localized fallback text for initial status notes.
 *
 * @group service_request
 */
final class InitialStatusNoteTranslationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'node',
    'taxonomy',
    'language',
    'locale',
    'paragraphs',
    'entity_reference_revisions',
  ];

  /**
   * The configured initial status term id.
   */
  private int $statusTid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('locale', [
      'locales_location',
      'locales_source',
      'locales_target',
    ]);
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('paragraph');
    $this->installConfig(['system', 'user', 'field', 'filter', 'node']);

    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->config('system.site')->set('default_langcode', 'de')->save();
    $this->addGermanCreatedTranslation();

    NodeType::create([
      'type' => 'service_request',
      'name' => 'Service request',
    ])->save();
    Vocabulary::create([
      'vid' => 'service_status',
      'name' => 'Service status',
    ])->save();
    $term = Term::create([
      'vid' => 'service_status',
      'name' => 'Reported',
    ]);
    $term->save();
    $this->statusTid = (int) $term->id();

    ParagraphsType::create([
      'id' => 'status',
      'label' => 'Status',
    ])->save();
    $this->createStatusFields();

    $this->container->set('markaspot_open311.processor', new class {

      /**
       * Creates an empty status note paragraph.
       */
      public function createStatusNoteParagraph(array $fields, string $langcode = ''): Paragraph {
        $paragraph = Paragraph::create([
          'type' => 'status',
          'langcode' => $langcode,
        ]);
        if (!empty($fields['status_term_id'])) {
          $paragraph->set('field_status_term', $fields['status_term_id']);
        }
        return $paragraph;
      }

    });

    require_once dirname(__DIR__, 3) . '/service_request.module';
  }

  /**
   * Non-English site defaults must not use the language-neutral node langcode.
   */
  public function testCreationFallbackUsesSiteDefaultLanguage(): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Broken streetlight',
      'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
    ]);

    $this->assertSame('und', $node->language()->getId());

    _service_request_handle_creation($node, $this->statusConfig(), TRUE);

    /** @var \Drupal\paragraphs\Entity\Paragraph $paragraph */
    $paragraph = $node->get('field_status_notes')->referencedEntities()[0];
    $this->assertSame('de', $paragraph->language()->getId());
    $this->assertSame('Die Meldung wurde erstellt.', $paragraph->get('field_status_note')->value);
    $this->assertSame('plain_text', $paragraph->get('field_status_note')->format);
  }

  /**
   * Adds the German locale string used by the fallback.
   */
  private function addGermanCreatedTranslation(): void {
    $storage = $this->container->get('locale.storage');
    $source = $storage->createString([
      'source' => 'The service request has been created.',
      'context' => '',
    ])->save();
    $storage->createTranslation([
      'lid' => $source->lid,
      'language' => 'de',
      'translation' => 'Die Meldung wurde erstellt.',
    ])->save();

    if (function_exists('_locale_refresh_translations')) {
      _locale_refresh_translations(['de'], [$source->lid]);
    }
  }

  /**
   * Creates the minimal entity fields needed by the creation hook.
   */
  private function createStatusFields(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Status',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => ['service_status' => 'service_status'],
        ],
      ],
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_status_notes',
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'settings' => ['target_type' => 'paragraph'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status_notes',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Status notes',
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => [
          'target_bundles' => ['status' => 'status'],
        ],
      ],
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_status_term',
      'entity_type' => 'paragraph',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status_term',
      'entity_type' => 'paragraph',
      'bundle' => 'status',
      'label' => 'Status',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => ['service_status' => 'service_status'],
        ],
      ],
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_status_note',
      'entity_type' => 'paragraph',
      'type' => 'text_long',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status_note',
      'entity_type' => 'paragraph',
      'bundle' => 'status',
      'label' => 'Step note',
    ])->save();
  }

  /**
   * Returns a config stub with the initial status term id.
   */
  private function statusConfig(): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['status_open_start', $this->statusTid],
    ]);
    return $config;
  }

}
