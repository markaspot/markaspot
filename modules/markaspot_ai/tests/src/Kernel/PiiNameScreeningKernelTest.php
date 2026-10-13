<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\KeyValueStore\KeyValueDatabaseFactory;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_ai\Privacy\NameScreeningResult;
use Drupal\markaspot_ai\Privacy\PiiScreeningHold;
use Drupal\markaspot_ai\Privacy\RetainedPiiOriginal;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\NlpClientService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * A failed personal-name screening is never treated as a clean result.
 *
 * Enables markaspot_ai and saves reports the ordinary way, so the real hook
 * lifecycle runs (field preSave before hook_node_presave, then insert/update
 * hooks); only the NLP container's HTTP transport (a Guzzle mock) and the
 * external provider client are replaced.
 */
#[TestGroup('markaspot_ai')]
#[RunTestsInSeparateProcesses]
final class PiiNameScreeningKernelTest extends KernelTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'file', 'options',
    'entity', 'flexible_permissions', 'group',
    'entity_reference_revisions', 'paragraphs',
    'workflows', 'content_moderation',
    'markaspot_ai', 'markaspot_ai_pii_test',
  ];

  /**
   * Synthetic personal data.
   */
  private const NAME = 'Erika Mustermann';
  private const EMAIL = 'private@example.test';
  private const PROBE = 'Streetlight 17 flickers since Monday';

  /**
   * Jurisdiction with PII redaction enabled.
   */
  private GroupInterface $jurisdiction;

  /**
   * Queued HTTP answers of the NLP container.
   */
  private MockHandler $nlpHandler;

  /**
   * Number of external provider calls.
   */
  private int $providerCalls = 0;

  /**
   * Answer of the external provider, or an exception to throw.
   */
  private string|\Exception $providerAnswer = '{"names_found": []}';

  /**
   * Every log line written during the test, placeholders resolved.
   *
   * @var string[]
   */
  private array $logLines = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $types = [
      'user', 'node', 'group', 'group_relationship', 'group_config_wrapper',
      'paragraph', 'content_moderation_state',
    ];
    foreach ($types as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'node', 'filter', 'group']);

    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    $this->addField('node', 'service_request', 'body', 'text_with_summary');
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction', 'creator_membership' => FALSE])->save();
    ParagraphsType::create(['id' => 'internal_remark', 'label' => 'Internal remark'])->save();
    $this->addField('paragraph', 'internal_remark', 'field_internal_remark_text', 'text_long');
    $this->addField('node', 'service_request', 'field_internal_remark', 'entity_reference_revisions', [
      'cardinality' => -1,
      'settings' => ['target_type' => 'paragraph'],
    ], [
      'handler' => 'default:paragraph',
      'handler_settings' => ['target_bundles' => ['internal_remark' => 'internal_remark']],
    ]);
    $this->addField('node', 'service_request', 'field_jurisdiction', 'entity_reference', [
      'settings' => ['target_type' => 'group'],
    ]);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    $this->jurisdiction = Group::create(['type' => 'jur', 'label' => 'Tenant']);
    $this->jurisdiction->save();

    // Tenant features come from markaspot_ai_pii_test's resolver stub.
    $this->useProvider('local_nlp');

    $this->nlpHandler = new MockHandler();
    $this->container->set('markaspot_ai.nlp_client', new NlpClientService(
      new Client(['handler' => HandlerStack::create($this->nlpHandler)]),
      $this->container->get('config.factory'),
      $this->container->get('logger.factory'),
    ));

    $provider = $this->createMock(AiClientService::class);
    $provider->method('chat')->willReturnCallback(function (): array {
      $this->providerCalls++;
      if ($this->providerAnswer instanceof \Exception) {
        throw $this->providerAnswer;
      }
      return ['choices' => [['message' => ['content' => $this->providerAnswer]]]];
    });
    $this->container->set('markaspot_ai.client', $provider);

    $lines = &$this->logLines;
    $this->container->get('logger.factory')->addLogger(new class($lines) implements LoggerInterface {

      use RfcLoggerTrait;

      /**
       * Collected lines.
       *
       * @var string[]
       */
      private array $lines;

      public function __construct(array &$lines) {
        $this->lines = &$lines;
      }

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $replace = [];
        foreach ($context as $key => $value) {
          if (is_scalar($value) || $value instanceof \Stringable) {
            $replace[$key] = (string) $value;
          }
        }
        $this->lines[] = strtr((string) $message, $replace);
      }

    });
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('MARKASPOT_AI_IONOS_KEY');
    parent::tearDown();
  }

  /**
   * A failed health check holds the report and never calls an external API.
   */
  public function testHealthCheckFailureHoldsReport(): void {
    $this->nlpHandler->append(new Response(503));

    $node = $this->submit(self::PROBE . ', reported by ' . self::NAME . '.');

    $this->assertHeld($node, NameScreeningResult::REASON_UNAVAILABLE);
    $this->assertStringContainsString(self::NAME, (string) $node->get('body')->value, 'Nothing was redacted: the text was not screened.');
    $this->assertNull($this->retainedOriginal($node), 'No original is retained when nothing was redacted.');
    $this->assertSame(0, $this->providerCalls, 'An explicitly selected local provider never fails over to an external one.');
    $this->assertLogsFreeOfCitizenText();
  }

  /**
   * A timed-out request holds the report; regex redaction still applies.
   */
  public function testRequestTimeoutHoldsReport(): void {
    $this->nlpHandler->append(
      $this->healthy(),
      new ConnectException('cURL error 28: Operation timed out', new Request('POST', '/redact')),
    );

    $node = $this->submit(self::PROBE . '. Mail me at ' . self::EMAIL . ', ' . self::NAME . '.');

    $this->assertHeld($node, NameScreeningResult::REASON_REQUEST_FAILED);
    $this->assertStringNotContainsString(self::EMAIL, (string) $node->get('body')->value);
    $this->assertStringContainsString('[REDACTED email]', (string) $node->get('body')->value);
    $original = $this->retainedOriginal($node);
    $this->assertNotNull($original);
    $this->assertTrue(RetainedPiiOriginal::isMarked($original));
    $this->assertLogsFreeOfCitizenText();
  }

  /**
   * Unusable payloads are failures, not "no names".
   */
  public function testMalformedOutputHoldsReport(): void {
    $this->nlpHandler->append($this->healthy(), new Response(200, [], '{"status": "ok"}'));
    $first = $this->submit(self::PROBE . ' (1)');
    $this->assertHeld($first, NameScreeningResult::REASON_MALFORMED);

    $this->nlpHandler->append($this->healthy(), new Response(200, [], '{"redacted": "x", "entities_found": "name: Erika"}'));
    $second = $this->submit(self::PROBE . ' (2)');
    $this->assertHeld($second, NameScreeningResult::REASON_MALFORMED);

    $this->nlpHandler->append($this->healthy(), new Response(200, [], 'not json'));
    $third = $this->submit(self::PROBE . ' (3)');
    $this->assertHeld($third, NameScreeningResult::REASON_MALFORMED);

    // A redacted text without an entity list is not "nothing found".
    foreach ([
      '{"redacted": "[REDACTED name]"}',
      '{"redacted": "x", "entities_found": null}',
      '{"redacted": "x", "entities_found": [42]}',
      '{"redacted": "x", "entities_found": [{"type": "name"}]}',
      '{"redacted": "x", "entities_found": ["name: "]}',
      '{"redacted": "x", "entities_found": {"a": "name: Erika"}}',
    ] as $i => $payload) {
      $this->nlpHandler->append($this->healthy(), new Response(200, [], $payload));
      $node = $this->submit(self::PROBE . ' (payload ' . $i . ')');
      $this->assertHeld($node, NameScreeningResult::REASON_MALFORMED);
    }
  }

  /**
   * A successful empty result keeps the ordinary path.
   */
  public function testSuccessfulEmptyResultPublishes(): void {
    $this->nlpHandler->append($this->healthy(), $this->redacted([]));

    $node = $this->submit(self::PROBE . '.');

    $this->assertNotHeld($node);
    $this->assertSame(self::PROBE . '.', $node->get('body')->value);
    $this->assertSame([], $node->get('field_internal_remark')->referencedEntities());
  }

  /**
   * Detected names are redacted and the report is published.
   */
  public function testDetectedNamesAreRedacted(): void {
    $this->nlpHandler->append($this->healthy(), $this->redacted(['name: ' . self::NAME]));

    $node = $this->submit(self::PROBE . ', reported by ' . self::NAME . '.');

    $this->assertNotHeld($node);
    $this->assertStringNotContainsString(self::NAME, (string) $node->get('body')->value);
    $this->assertStringContainsString('[REDACTED name]', (string) $node->get('body')->value);
    $original = $this->retainedOriginal($node);
    $this->assertNotNull($original);
    $this->assertStringContainsString(self::NAME, (string) $original->get('field_internal_remark_text')->value);
  }

  /**
   * After an outage new reports pass again; publishing releases a hold.
   */
  public function testRecoveryAfterOutage(): void {
    $this->nlpHandler->append(new ConnectException('Connection refused', new Request('GET', '/health')));
    $held = $this->submit(self::PROBE . ' during outage.');
    $this->assertHeld($held, NameScreeningResult::REASON_UNAVAILABLE);

    $this->nlpHandler->append($this->healthy(), $this->redacted([]));
    $later = $this->submit(self::PROBE . ' after recovery.');
    $this->assertNotHeld($later);

    // Staff reviewed the text and publish it (hook_node_update releases).
    $held->setPublished();
    $held->save();
    $this->assertTrue(Node::load($held->id())->isPublished());
    $this->assertFalse(PiiScreeningHold::isHeld($held));
    $this->assertSame([], PiiScreeningHold::heldIds());
  }

  /**
   * A failing external provider falls back to the local service only.
   */
  public function testExternalFailureFallsBackToLocalServiceOnly(): void {
    putenv('MARKASPOT_AI_IONOS_KEY=test-key');
    $this->useProvider('ionos');

    $this->providerAnswer = new \RuntimeException('Provider said: ' . self::NAME);
    $this->nlpHandler->append(new Response(503));
    $node = $this->submit(self::PROBE . ', reported by ' . self::NAME . '.');
    $this->assertSame(1, $this->providerCalls);
    $this->assertHeld($node, NameScreeningResult::REASON_UNAVAILABLE);
    $this->assertLogsFreeOfCitizenText();

    // An answer without a names_found list is not clean either.
    $this->providerAnswer = 'I cannot help with that.';
    $this->nlpHandler->append($this->healthy(), $this->redacted([]));
    $clean = $this->submit(self::PROBE . ' again.');
    $this->assertSame(2, $this->providerCalls);
    $this->assertNotHeld($clean, 'The local service screened it after the unusable answer.');

    // A names_found list with a non-name entry is not clean either.
    $this->providerAnswer = '{"names_found": [42]}';
    $this->nlpHandler->append(new Response(503));
    $odd = $this->submit(self::PROBE . ' odd.');
    $this->assertHeld($odd, NameScreeningResult::REASON_UNAVAILABLE);

    // A parseable empty answer is clean without touching the local service.
    $this->providerAnswer = '{"names_found": []}';
    $direct = $this->submit(self::PROBE . ' once more.');
    $this->assertNotHeld($direct);
    $this->assertSame(0, $this->nlpHandler->count());
  }

  /**
   * Remarks are stored with an ordinary save and point at their report.
   */
  public function testRemarksAreStoredWithTheReport(): void {
    $this->nlpHandler->append($this->healthy(), $this->redacted(['name: ' . self::NAME]));
    $node = $this->submit(self::PROBE . ', ' . self::NAME . ', ' . self::EMAIL . '.');

    $this->assertSame(1, $this->paragraphCount());
    $original = $this->retainedOriginal($node);
    $this->assertNotNull($original);
    $this->assertSame((string) $node->id(), (string) $original->get('parent_id')->value);
    $this->assertSame('node', $original->get('parent_type')->value);
    $this->assertSame('field_internal_remark', $original->get('parent_field_name')->value);
    $this->assertTrue(RetainedPiiOriginal::isMarked($original));

    // The failed-screening notice is stored the same way.
    $this->nlpHandler->append(new Response(503));
    $held = $this->submit(self::PROBE . ' held.');
    $this->assertHeld($held, NameScreeningResult::REASON_UNAVAILABLE);
    foreach ($held->get('field_internal_remark')->referencedEntities() as $remark) {
      $this->assertSame((string) $held->id(), (string) $remark->get('parent_id')->value);
    }
  }

  /**
   * A failed report save rolls back the remarks and the hold.
   */
  public function testFailedReportSaveLeavesNothingBehind(): void {
    // KernelTestBase keeps key/value in memory; a real site stores the holds
    // in the database, inside the report's transaction.
    $this->container->set('keyvalue', new KeyValueDatabaseFactory(
      $this->container->get('serialization.phpserialize'),
      $this->container->get('database'),
    ));
    // Successful submissions first, so every table a save touches exists:
    // creating one lazily is DDL, which implicitly commits an open MySQL
    // transaction and never happens on a real site.
    $this->nlpHandler->append($this->healthy(), $this->redacted(['name: ' . self::NAME]));
    $this->submit(self::PROBE . ', ' . self::NAME . ', warm-up.');
    $this->nlpHandler->append(new Response(503));
    $this->submit(self::PROBE . ' warm-up held.');
    $paragraphs = $this->paragraphCount();
    $nodes = $this->nodeCount();
    $holds = count(PiiScreeningHold::heldIds());

    $this->container->get('state')->set('markaspot_ai_pii_test.fail_report_save', TRUE);

    $this->nlpHandler->append($this->healthy(), $this->redacted(['name: ' . self::NAME]));
    $redacted = $this->newReport(self::PROBE . ', ' . self::NAME . '.');
    $this->assertSaveFails($redacted);

    $this->nlpHandler->append(new Response(503));
    $held = $this->newReport(self::PROBE . ' held.');
    $this->assertSaveFails($held);

    $this->assertSame($paragraphs, $this->paragraphCount(), 'No orphaned paragraph holds the original text.');
    $this->assertSame($nodes, $this->nodeCount());
    $this->assertFalse(PiiScreeningHold::isHeld($held), 'The hold rolled back with the report.');
    $this->assertCount($holds, PiiScreeningHold::heldIds());
  }

  /**
   * Content Moderation cannot re-publish a held report.
   */
  public function testModeratedReportStaysHeld(): void {
    $workflow = $this->createEditorialWorkflow();
    $workflow->getTypePlugin()->addEntityTypeAndBundle('node', 'service_request');
    $workflow->save();

    $this->nlpHandler->append(new Response(503));
    $node = $this->newReport(self::PROBE . ' moderated.');
    $node->set('moderation_state', 'published');
    $node->save();
    $node = Node::load($node->id());

    $this->assertHeld($node, NameScreeningResult::REASON_UNAVAILABLE);
    $this->assertSame('draft', $node->get('moderation_state')->value);

    // A screened moderated report keeps the requested state.
    $this->nlpHandler->append($this->healthy(), $this->redacted([]));
    $clean = $this->newReport(self::PROBE . ' moderated clean.');
    $clean->set('moderation_state', 'published');
    $clean->save();
    $this->assertNotHeld(Node::load($clean->id()));
  }

  /**
   * Automatic publication skips held reports.
   */
  public function testPublisherSkipsHeldReports(): void {
    $this->nlpHandler->append(new Response(503));
    $held = $this->submit(self::PROBE . ' held.');

    // An unpublished report that was screened normally.
    $this->nlpHandler->append($this->healthy(), $this->redacted([]));
    $waiting = $this->newReport(self::PROBE . ' waiting.');
    $waiting->setUnpublished();
    $waiting->save();

    // The same tagged entity query markaspot_publisher builds.
    $nids = array_map('intval', $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('status', 0)
      ->addTag('markaspot_publisher_candidates')
      ->execute());

    $this->assertNotContains((int) $held->id(), $nids);
    $this->assertContains((int) $waiting->id(), $nids);
  }

  /**
   * Submits a report through the real presave path.
   */
  private function submit(string $body): NodeInterface {
    $node = $this->newReport($body);
    $node->save();
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    return Node::load($node->id());
  }

  /**
   * Asserts a report save fails.
   */
  private function assertSaveFails(NodeInterface $node): void {
    try {
      $node->save();
      $this->fail('The report save was expected to fail.');
    }
    catch (EntityStorageException $e) {
      $this->assertSame('Forced report save failure.', $e->getMessage());
    }
  }

  /**
   * Creates an unsaved, published report.
   */
  private function newReport(string $body): NodeInterface {
    return Node::create([
      'type' => 'service_request',
      'title' => 'Report',
      'status' => 1,
      'body' => ['value' => $body, 'format' => 'plain_text'],
      'field_jurisdiction' => $this->jurisdiction->id(),
    ]);
  }

  /**
   * Asserts the held state of a stored report.
   */
  private function assertHeld(NodeInterface $node, string $reason): void {
    $this->assertFalse($node->isPublished(), 'An unscreened report is not published.');
    $hold = PiiScreeningHold::get($node);
    $this->assertNotNull($hold, 'The hold is recorded.');
    $this->assertSame($reason, $hold['reason']);
    $this->assertSame((int) $node->id(), $hold['nid']);
    $this->assertContains((int) $node->id(), PiiScreeningHold::heldIds());
    $notices = array_filter(
      $node->get('field_internal_remark')->referencedEntities(),
      fn($p) => str_contains((string) $p->get('field_internal_remark_text')->value, 'screening failed'),
    );
    $this->assertCount(1, $notices, 'Staff see why the report was held.');
  }

  /**
   * Asserts a stored report took the ordinary path.
   */
  private function assertNotHeld(NodeInterface $node, string $message = ''): void {
    $this->assertTrue($node->isPublished(), $message);
    $this->assertFalse(PiiScreeningHold::isHeld($node), $message);
  }

  /**
   * Asserts no log line repeats submitted text.
   */
  private function assertLogsFreeOfCitizenText(): void {
    $this->assertNotEmpty($this->logLines);
    foreach ($this->logLines as $line) {
      $this->assertStringNotContainsString(self::NAME, $line);
      $this->assertStringNotContainsString(self::EMAIL, $line);
      $this->assertStringNotContainsString('Streetlight', $line);
    }
  }

  /**
   * Finds the remark holding the retained original.
   */
  private function retainedOriginal(NodeInterface $node): ?object {
    foreach ($node->get('field_internal_remark')->referencedEntities() as $paragraph) {
      if (RetainedPiiOriginal::isPiiOriginal($paragraph)) {
        return $paragraph;
      }
    }
    return NULL;
  }

  /**
   * Number of stored paragraphs.
   */
  private function paragraphCount(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('paragraph')
      ->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Number of stored reports.
   */
  private function nodeCount(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('node')
      ->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * A healthy NLP health-check answer.
   */
  private function healthy(): Response {
    return new Response(200, [], '{"status": "ok", "ollama_available": true}');
  }

  /**
   * A successful NLP redaction answer.
   *
   * @param string[] $entities
   *   Entities in "type: value" form.
   */
  private function redacted(array $entities): Response {
    return new Response(200, [], json_encode(['redacted' => 'irrelevant', 'entities_found' => $entities]));
  }

  /**
   * Enables PII redaction with name detection through a provider.
   */
  private function useProvider(string $provider): void {
    $GLOBALS['config']['markaspot_ai.settings']['pii_redaction'] = [
      'enabled' => TRUE,
      'detect_names' => TRUE,
      'provider' => $provider,
    ];
  }

  /**
   * Adds a field to a bundle.
   */
  private function addField(string $entityType, string $bundle, string $name, string $type, array $storage = [], array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'type' => $type,
    ] + $storage)->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $name,
      'settings' => $settings,
    ])->save();
  }

}
