<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_ai\Privacy\RetainedPiiOriginal;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\AttributeFillingService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Retained PII originals never reach a form-assistant provider prompt.
 *
 * Runs the real PII presave redaction on a synthetic submission and captures
 * the complete outbound chat payload of AttributeFillingService::assistForm()
 * through a mocked provider client.
 */
#[TestGroup('markaspot_ai')]
#[RunTestsInSeparateProcesses]
final class RetainedPiiPromptKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'file', 'options',
    'entity', 'flexible_permissions', 'group',
    'entity_reference_revisions', 'paragraphs',
  ];

  /**
   * Synthetic personal data, none of which may leave the site again.
   */
  private const EMAIL = 'private@example.test';
  private const PHONE = '+49 170 1234567';

  /**
   * Jurisdiction with PII redaction enabled.
   */
  private GroupInterface $jurisdiction;

  /**
   * Every message list sent to the provider, in call order.
   *
   * @var array<int, array<int, array<string, mixed>>>
   */
  private array $sentMessages = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper', 'paragraph'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'node', 'filter', 'group']);
    require_once dirname(__DIR__, 3) . '/markaspot_ai.module';

    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Body',
    ])->save();
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction', 'creator_membership' => FALSE])->save();
    ParagraphsType::create(['id' => 'internal_remark', 'label' => 'Internal remark'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_internal_remark_text',
      'entity_type' => 'paragraph',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_internal_remark_text',
      'entity_type' => 'paragraph',
      'bundle' => 'internal_remark',
      'label' => 'Remark',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_internal_remark',
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'cardinality' => -1,
      'settings' => ['target_type' => 'paragraph'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_internal_remark',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Internal remarks',
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => ['target_bundles' => ['internal_remark' => 'internal_remark']],
      ],
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Jurisdiction',
    ])->save();

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    $this->jurisdiction = Group::create(['type' => 'jur', 'label' => 'Tenant']);
    $this->jurisdiction->save();

    // Global switch on, provider name detection off: regex redaction only.
    $GLOBALS['config']['markaspot_ai.settings']['pii_redaction'] = [
      'enabled' => TRUE,
      'detect_names' => FALSE,
    ];
    // Tenant opt-in for piiRedaction (markaspot_nuxt is not installed here).
    $this->container->set('markaspot_nuxt.feature_scope_resolver', new class {

      /**
       * Every tenant feature is on.
       */
      public function isEnabledEffective(string $dotPath, GroupInterface $jur, ?bool $default = NULL): bool {
        return TRUE;
      }

    });
  }

  /**
   * A non-draft assist call never resends the retained original.
   */
  public function testNonDraftPromptExcludesRetainedOriginal(): void {
    $node = $this->submitWithPii();
    $this->addRemark($node, 'Crew scheduled for Tuesday.');

    $original = $this->retainedOriginal($node);
    $this->assertNotNull($original, 'Redaction retained the original as an internal remark.');
    $this->assertStringContainsString(self::EMAIL, (string) $original->get('field_internal_remark_text')->value);
    $this->assertTrue(RetainedPiiOriginal::isPiiOriginal($original));

    $this->createService()->assistForm($node, ['priority'], 'en', FALSE);

    $prompt = $this->lastPrompt();
    $this->assertNoRetainedPii($prompt);
    $this->assertStringContainsString('[REDACTED email]', $prompt);
    $this->assertStringContainsString('Crew scheduled for Tuesday.', $prompt);
  }

  /**
   * The provenance metadata survives storage.
   */
  public function testNewOriginalCarriesExplicitProvenance(): void {
    $node = $this->submitWithPii();
    $id = $this->retainedOriginal($node)?->id();
    $this->container->get('entity_type.manager')->getStorage('paragraph')->resetCache();
    $stored = Paragraph::load($id);

    $this->assertTrue((bool) $stored->getBehaviorSetting(RetainedPiiOriginal::SETTINGS_KEY, 'pii_original', FALSE));
    $this->assertTrue((bool) $stored->getBehaviorSetting(RetainedPiiOriginal::SETTINGS_KEY, 'exclude_from_ai', FALSE));
  }

  /**
   * Draft (reply) callers never see the original either.
   */
  public function testDraftPromptExcludesRetainedOriginal(): void {
    $node = $this->submitWithPii();

    $this->createService()->assistForm($node, ['status_note'], 'en', TRUE, 'Ask the reporter for the exact location.');

    $this->assertNoRetainedPii($this->lastPrompt());
  }

  /**
   * Originals stored before the metadata existed are recognised by marker.
   */
  public function testLegacyOriginalWithoutMetadataIsExcluded(): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Legacy report',
      'body' => ['value' => 'Broken lamp, contact [REDACTED email].', 'format' => 'plain_text'],
    ]);
    $node->save();
    $legacy = $this->addRemark($node, RetainedPiiOriginal::remarkText('Broken lamp, contact ' . self::EMAIL . ' or ' . self::PHONE . '.'));
    $this->addRemark($node, 'Reported to the lighting contractor.');
    $this->assertSame([], $legacy->getAllBehaviorSettings());

    $this->createService()->assistForm($node, ['priority'], 'en', FALSE);

    $prompt = $this->lastPrompt();
    $this->assertNoRetainedPii($prompt);
    $this->assertStringContainsString('Reported to the lighting contractor.', $prompt);
  }

  /**
   * Ordinary remarks keep feeding the non-draft prompt.
   */
  public function testOrdinaryRemarksStayInContext(): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Clean report',
      'body' => ['value' => 'Graffiti on the bridge.', 'format' => 'plain_text'],
    ]);
    $node->save();
    $this->addRemark($node, 'Cleaning crew notified.');

    $this->createService()->assistForm($node, ['priority'], 'en', FALSE);

    $this->assertStringContainsString('Cleaning crew notified.', $this->lastPrompt());
  }

  /**
   * Editing the heading of an unmarked legacy original keeps it excluded.
   */
  public function testEditedLegacyOriginalStaysExcluded(): void {
    $node = $this->reportWithLegacyOriginal($legacy);
    $this->assertFalse(RetainedPiiOriginal::isMarked($legacy));

    // What InternalRemarkController::update() does: replace the text only.
    $legacy->set('field_internal_remark_text', [
      'value' => 'Checked on site. Contact: ' . self::EMAIL . ', ' . self::PHONE,
      'format' => 'plain_text',
    ]);
    $this->saveThroughHooks($legacy);

    $this->assertTrue(RetainedPiiOriginal::isMarked($this->reloadParagraph($legacy)));
    $this->createService()->assistForm($this->reloadNode($node), ['priority'], 'en', FALSE);
    $this->assertNoRetainedPii($this->lastPrompt());
  }

  /**
   * Overwritten behavior settings cannot lift the exclusion.
   */
  public function testClearedProvenanceIsRestoredOnSave(): void {
    $node = $this->submitWithPii();
    $original = $this->retainedOriginal($node);
    $original->set('field_internal_remark_text', ['value' => 'Contact ' . self::EMAIL, 'format' => 'plain_text']);
    // A JSON:API PATCH can replace the serialized settings wholesale.
    $original->set('behavior_settings', serialize([]));
    $original->setAllBehaviorSettings([]);
    $this->saveThroughHooks($original);

    $this->assertTrue(RetainedPiiOriginal::isMarked($this->reloadParagraph($original)));
    $this->createService()->assistForm($this->reloadNode($node), ['priority'], 'en', FALSE);
    $this->assertNoRetainedPii($this->lastPrompt());
  }

  /**
   * The update marks legacy originals once, then changes nothing.
   */
  public function testUpdateMarksLegacyOriginalsIdempotently(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_ai.install';
    $this->reportWithLegacyOriginal($corroborated);
    // Marker kept only in an older revision: an edit removed it.
    $edited = $this->addRemark($this->reportWithLegacyOriginal($unused), RetainedPiiOriginal::remarkText('Call ' . self::PHONE));
    $edited->setNewRevision(TRUE);
    $edited->set('field_internal_remark_text', ['value' => 'Call the reporter back.', 'format' => 'plain_text']);
    $edited->save();
    // Marker without a host report created at the same time.
    $late = Paragraph::create([
      'type' => 'internal_remark',
      'field_internal_remark_text' => [
        'value' => RetainedPiiOriginal::remarkText(self::EMAIL),
        'format' => 'plain_text',
      ],
      'created' => 1000,
    ]);
    $late->save();
    $ordinary = Paragraph::create([
      'type' => 'internal_remark',
      'field_internal_remark_text' => ['value' => 'Cleaning crew notified.', 'format' => 'plain_text'],
    ]);
    $ordinary->save();

    $first = $this->runUpdate();
    $this->assertStringContainsString('4 marked now, 0 already marked; 1 of the newly marked', $first);
    foreach ([$corroborated, $unused, $edited, $late] as $paragraph) {
      $stored = $this->reloadParagraph($paragraph);
      $this->assertTrue(RetainedPiiOriginal::isMarked($stored));
    }
    $storedEdited = $this->reloadParagraph($edited);
    $this->assertSame('Call the reporter back.', $storedEdited->get('field_internal_remark_text')->value);
    $this->assertFalse(RetainedPiiOriginal::isMarked($this->reloadParagraph($ordinary)));

    $second = $this->runUpdate();
    $this->assertStringContainsString('0 marked now, 4 already marked', $second);
  }

  /**
   * A host pinned to an unmarked older revision of a marked original.
   *
   * The remark was edited with a new revision (marker gone, PII kept) and
   * marked afterwards, but the report still references the first revision.
   */
  public function testHostReferencingUnmarkedOlderRevisionIsExcluded(): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Pinned revision',
      'body' => ['value' => 'Fence down, contact [REDACTED email].', 'format' => 'plain_text'],
    ]);
    $node->save();
    $pinned = $this->addRemark($node, 'Contact ' . self::EMAIL . ' or ' . self::PHONE . '.');
    $pinnedRevision = (int) $pinned->getRevisionId();
    $this->addRemark($node, 'Fence contractor booked.');

    $pinned->setNewRevision(TRUE);
    RetainedPiiOriginal::mark($pinned);
    $pinned->save();
    $this->assertNotSame($pinnedRevision, (int) $pinned->getRevisionId());

    $stored = $this->reloadNode($node);
    $referenced = $stored->get('field_internal_remark')->referencedEntities();
    $this->assertSame($pinnedRevision, (int) $referenced[0]->getRevisionId(), 'The report still references the unmarked revision.');
    $this->assertFalse(RetainedPiiOriginal::isMarked($referenced[0]));

    $this->createService()->assistForm($stored, ['priority'], 'en', FALSE);

    $prompt = $this->lastPrompt();
    $this->assertNoRetainedPii($prompt);
    $this->assertStringContainsString('Fence contractor booked.', $prompt);
  }

  /**
   * Migrated legacy notes stay out of the outbound prompt.
   */
  public function testMigratedLegacyNotesStayOutOfPrompt(): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Migrated report',
      'body' => ['value' => 'Tree branch on the road.', 'format' => 'plain_text'],
    ]);
    $node->save();
    $note = Paragraph::create([
      'type' => 'internal_remark',
      'field_internal_remark_text' => [
        'value' => 'Legacy note: neighbour ' . self::EMAIL . ' called twice.',
        'format' => 'plain_text',
      ],
    ]);
    $note->setBehaviorSettings('markaspot_legacy_notes', ['exclude_from_ai' => TRUE]);
    $note->save();
    $node->get('field_internal_remark')->appendItem($note);
    $node->save();
    $this->addRemark($node, 'Removed by the parks team.');

    $this->createService()->assistForm($node, ['priority'], 'en', FALSE);

    $prompt = $this->lastPrompt();
    $this->assertStringNotContainsString(self::EMAIL, $prompt);
    $this->assertStringNotContainsString('Legacy note', $prompt);
    $this->assertStringContainsString('Removed by the parks team.', $prompt);
  }

  /**
   * Creates a report whose original predates the provenance mark.
   */
  private function reportWithLegacyOriginal(?Paragraph &$legacy = NULL): NodeInterface {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Legacy report',
      'body' => ['value' => 'Broken lamp, contact [REDACTED email].', 'format' => 'plain_text'],
    ]);
    $node->save();
    $legacy = $this->addRemark($node, RetainedPiiOriginal::remarkText('Broken lamp, contact ' . self::EMAIL . ' or ' . self::PHONE . '.'));
    return $node;
  }

  /**
   * Saves a paragraph and runs markaspot_ai's presave hook as storage would.
   *
   * The markaspot_ai module is not installed in this test (its dependency
   * chain is out of scope), so the hook is invoked explicitly with the stored
   * version set as original, after Paragraph::preSave() would have run.
   */
  private function saveThroughHooks(Paragraph $paragraph): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('paragraph');
    $paragraph->setOriginal($storage->loadUnchanged($paragraph->id()));
    $paragraph->preSave($storage);
    markaspot_ai_paragraph_presave($paragraph);
    // Storage writes the field as it is after the hook; Paragraph::preSave()
    // has already serialized, so the mark must be in the field itself.
    $this->assertStringContainsString('pii_original', (string) $paragraph->get('behavior_settings')->value);
    $paragraph->save();
  }

  /**
   * Runs markaspot_ai_update_11902() to completion.
   */
  private function runUpdate(): string {
    $sandbox = [];
    do {
      $message = markaspot_ai_update_11902($sandbox);
    } while (($sandbox['#finished'] ?? 1) < 1);
    return $message;
  }

  /**
   * Reloads a paragraph from storage.
   */
  private function reloadParagraph(Paragraph $paragraph): Paragraph {
    return $this->container->get('entity_type.manager')->getStorage('paragraph')->loadUnchanged($paragraph->id());
  }

  /**
   * Reloads a report from storage.
   */
  private function reloadNode(NodeInterface $node): NodeInterface {
    return $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
  }

  /**
   * Saves a synthetic submission through the real PII presave redaction.
   */
  private function submitWithPii(): NodeInterface {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Pothole',
      'body' => [
        'value' => 'Deep pothole outside number 4. Call me at ' . self::PHONE . ' or write to ' . self::EMAIL . '.',
        'format' => 'plain_text',
      ],
      'field_jurisdiction' => $this->jurisdiction->id(),
    ]);
    markaspot_ai_node_presave($node);
    $node->save();
    $this->assertStringNotContainsString(self::EMAIL, (string) $node->get('body')->value);
    return $node;
  }

  /**
   * Appends an ordinary internal remark.
   */
  private function addRemark(NodeInterface $node, string $text): Paragraph {
    $paragraph = Paragraph::create([
      'type' => 'internal_remark',
      'field_internal_remark_text' => ['value' => $text, 'format' => 'plain_text'],
    ]);
    $paragraph->save();
    $node->get('field_internal_remark')->appendItem($paragraph);
    $node->save();
    return $paragraph;
  }

  /**
   * Finds the remark that holds the retained original.
   */
  private function retainedOriginal(NodeInterface $node): ?Paragraph {
    foreach ($node->get('field_internal_remark')->referencedEntities() as $paragraph) {
      if (str_contains((string) $paragraph->get('field_internal_remark_text')->value, RetainedPiiOriginal::MARKER)) {
        return $paragraph;
      }
    }
    return NULL;
  }

  /**
   * Builds the service with a provider client that records every request.
   */
  private function createService(): AttributeFillingService {
    $client = $this->createMock(AiClientService::class);
    $client->method('resolveChatModel')->willReturn('test-model');
    $client->method('chat')->willReturnCallback(function (array $messages): array {
      $this->sentMessages[] = $messages;
      return ['choices' => [['message' => ['content' => '{}']]]];
    });
    return new AttributeFillingService(
      $client,
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('logger.factory'),
      $this->createMock(TokenTrackingService::class),
      $this->container->get('file_system'),
      $this->container->get('language_manager'),
      $this->container->get('database'),
      $this->container->get('state'),
      $this->container->get('datetime.time'),
    );
  }

  /**
   * Flattens the complete last outbound message list to one string.
   */
  private function lastPrompt(): string {
    $this->assertNotEmpty($this->sentMessages, 'The provider was called.');
    return json_encode(end($this->sentMessages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

  /**
   * Asserts that no part of the retained original is in the payload.
   */
  private function assertNoRetainedPii(string $prompt): void {
    $this->assertStringNotContainsString(self::EMAIL, $prompt);
    $this->assertStringNotContainsString('170 1234567', $prompt);
    $this->assertStringNotContainsString(RetainedPiiOriginal::MARKER, $prompt);
  }

}
