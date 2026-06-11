<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\IngestResult;
use Drupal\markaspot_mail_inbound\Service\InboundMailPromoter;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;

/**
 * Kernel test for the inbound mail staging spine + manual promotion (#467).
 *
 * Proves the rebuild contract:
 * - a parsed mail is STAGED as an inbound_mail (state=staged) with NO node,
 * - the spam heuristic records a non-report mail as state=discarded,
 * - a threaded reply from the same sender appends to the staged mail,
 * - promotion creates a service_request node via the processor contract, sets
 *   field_source=email, field_jurisdiction and an initial status, and flips
 *   the mail to state=promoted,
 * - discard flips the mail to state=discarded.
 *
 * The Open311 processor is exercised through a lightweight double wired into
 * the promoter: it mirrors prepareNodeProperties / getInitialStatusTid /
 * createStatusNoteParagraph so the test does not need the full open311 module
 * stack. The exact processor input contract is verified separately in
 * InboundMailPromoterRequestDataTest (unit).
 *
 * @group markaspot_mail_inbound
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class InboundMailStagingKernelTest extends KernelTestBase {

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
    'options',
    'taxonomy',
    'field_permissions',
    'entity_reference_revisions',
    'paragraphs',
    'file',
    'entity',
    'flexible_permissions',
    'group',
    'gnode',
    // The module ships a jsonapi_extras.jsonapi_resource_config in
    // config/install (the disabled inbound_mail JSON:API resource), so
    // installConfig(['markaspot_mail_inbound']) needs the stack present.
    'serialization',
    'jsonapi',
    'jsonapi_extras',
    'markaspot_mail_inbound',
  ];

  /**
   * The category term id used in promotion (carries a service code).
   */
  protected int $categoryTid;

  /**
   * The jurisdiction group id.
   */
  protected int $gid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installEntitySchema('inbound_mail');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node', 'group']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();

    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();

    $this->createNodeField('body', 'text_with_summary');
    $this->createNodeField('field_e_mail', 'email');
    $this->createNodeField('field_category', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createNodeField('field_gdpr', 'boolean');
    $this->createNodeField('field_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    // field_source and field_email_message_id are installed by the module's
    // shipped config (installConfig below); do not create them manually or the
    // type/permission would conflict.
    // Jurisdiction group type + relationship + node field_jurisdiction.
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $this->container->get('entity_type.manager')
      ->getStorage('group_relationship_type')
      ->createFromPlugin(GroupType::load('jur'), 'group_node:service_request')
      ->save();
    $this->createNodeField('field_jurisdiction', 'entity_reference', ['target_type' => 'group'], -1);
    // The media module is not installed here, so the promoter's raw-file
    // fallback branch attaches file targets directly; an entity_reference to
    // file stands in for the production media reference.
    $this->createNodeField('field_request_media', 'entity_reference', ['target_type' => 'file'], -1);

    // Status note paragraph stack.
    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $this->createParagraphField('field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term'], 'status');
    $this->createParagraphField('field_status_note', 'text_long', [], 'status');
    $this->createNodeField('field_status_notes', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);

    $this->installConfig(['markaspot_mail_inbound']);

    // A coded service_category term (the promotion gate).
    Vocabulary::create(['vid' => 'service_category', 'name' => 'Service Category'])->save();
    $this->createTermField('field_service_code', 'string');
    $term = Term::create(['vid' => 'service_category', 'name' => 'Streetlight', 'field_service_code' => '1.3']);
    $term->save();
    $this->categoryTid = (int) $term->id();

    $group = Group::create(['type' => 'jur', 'label' => 'City']);
    $group->save();
    $this->gid = (int) $group->id();

    $this->config('markaspot_mail_inbound.settings')
      ->set('mailboxes', [
        [
          'id' => 'city_main',
          'label' => 'City Main',
          'enabled' => TRUE,
          'recipient_addresses' => ['report@city.example'],
          'jurisdiction_gid' => $this->gid,
          'default_category_tid' => 0,
          'sender_allowlist' => [],
          'sender_blocklist' => ['blocked@example.org'],
        ],
      ])
      ->set('flood_limit', 10)
      ->set('flood_window', 3600)
      ->save();
  }

  /**
   * Creates a node base/config field.
   */
  protected function createNodeField(string $name, string $type, array $settings = [], int $cardinality = 1): void {
    $this->createField('node', 'service_request', $name, $type, $settings, $cardinality);
  }

  /**
   * Creates a paragraph field.
   */
  protected function createParagraphField(string $name, string $type, array $settings, string $bundle): void {
    $this->createField('paragraph', $bundle, $name, $type, $settings, 1);
  }

  /**
   * Creates a taxonomy_term field on service_category.
   */
  protected function createTermField(string $name, string $type, array $settings = []): void {
    $this->createField('taxonomy_term', 'service_category', $name, $type, $settings, 1);
  }

  /**
   * Generic field creation helper.
   */
  protected function createField(string $entityType, string $bundle, string $name, string $type, array $settings, int $cardinality): void {
    if (!FieldStorageConfig::loadByName($entityType, $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'type' => $type,
        'cardinality' => $cardinality,
        'settings' => $settings,
      ])->save();
    }
    if (!FieldConfig::loadByName($entityType, $bundle, $name)) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'bundle' => $bundle,
        'label' => $name,
        'required' => FALSE,
      ])->save();
    }
  }

  /**
   * Ingests a raw message through parser + orchestrator.
   */
  protected function ingest(string $raw): IngestResult {
    $message = $this->container->get('markaspot_mail_inbound.parser')->parse($raw);
    $mailbox = $this->container->get('markaspot_mail_inbound.mailbox_resolver')->getMailbox('city_main');
    $this->assertNotNull($mailbox);
    return $this->container->get('markaspot_mail_inbound.orchestrator')->ingest($message, $mailbox);
  }

  /**
   * Counts service_request nodes.
   */
  protected function countNodes(): int {
    return (int) \Drupal::entityQuery('node')
      ->condition('type', 'service_request')
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Loads an inbound_mail by id.
   */
  protected function loadMail(int $id): InboundMail {
    /** @var \Drupal\markaspot_mail_inbound\Entity\InboundMail $mail */
    $mail = $this->container->get('entity_type.manager')->getStorage('inbound_mail')->load($id);
    return $mail;
  }

  /**
   * Builds a minimal raw message.
   */
  protected function makeRaw(string $messageId, string $sender = 'citizen@example.org', string $subject = 'Broken light', string $body = 'A streetlight is broken.', array $extraHeaders = []): string {
    $headers = '';
    foreach ($extraHeaders as $name => $value) {
      $headers .= "$name: $value\r\n";
    }
    return "From: Citizen <$sender>\r\n"
      . "To: report@city.example\r\n"
      . "Subject: $subject\r\n"
      . "Message-ID: <$messageId>\r\n"
      . "Date: Tue, 09 Jun 2026 10:00:00 +0200\r\n"
      . $headers
      . "Content-Type: text/plain; charset=utf-8\r\n"
      . "\r\n"
      . "$body\r\n";
  }

  /**
   * Builds a multipart raw message carrying a tiny real PNG attachment.
   */
  protected function makeRawWithImage(string $messageId, string $sender = 'citizen@example.org'): string {
    // A minimal 1x1 PNG; finfo sniffs it as image/png, the allowed type.
    $png = base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );
    $boundary = 'b0undary' . substr($messageId, 0, 6);
    return "From: Citizen <$sender>\r\n"
      . "To: report@city.example\r\n"
      . "Subject: Broken light with photo\r\n"
      . "Message-ID: <$messageId>\r\n"
      . "Date: Tue, 09 Jun 2026 10:00:00 +0200\r\n"
      . "MIME-Version: 1.0\r\n"
      . "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n"
      . "\r\n"
      . "--$boundary\r\n"
      . "Content-Type: text/plain; charset=utf-8\r\n"
      . "\r\n"
      . "A streetlight is broken, photo attached.\r\n"
      . "--$boundary\r\n"
      . "Content-Type: image/png; name=\"photo.png\"\r\n"
      . "Content-Transfer-Encoding: base64\r\n"
      . "Content-Disposition: attachment; filename=\"photo.png\"\r\n"
      . "\r\n"
      . chunk_split(base64_encode($png), 76, "\r\n")
      . "--$boundary--\r\n";
  }

  /**
   * A genuine mail is staged, no node is created.
   */
  public function testGenuineMailIsStagedWithoutNode(): void {
    $result = $this->ingest($this->makeRaw('plain-001@example.org'));

    $this->assertSame(IngestResult::STAGED, $result->status);
    $this->assertSame(0, $this->countNodes());

    $mail = $this->loadMail((int) $result->entityId);
    $this->assertSame(InboundMail::STATE_STAGED, $mail->getState());
    $this->assertSame('citizen@example.org', $mail->getFromAddress());
    $this->assertSame('Broken light', $mail->getSubject());
    $this->assertStringContainsString('streetlight is broken', $mail->getBody());
    $this->assertSame($this->gid, $mail->getJurisdictionId());
    $this->assertContains('plain-001@example.org', $mail->getThreadMessageIds());
  }

  /**
   * An auto-reply (Auto-Submitted) is recorded as discarded, not staged.
   */
  public function testAutoReplyIsDiscarded(): void {
    $result = $this->ingest($this->makeRaw('auto-001@example.org', 'noreply@example.org', 'Out of office', 'I am away.', [
      'Auto-Submitted' => 'auto-replied',
    ]));

    $this->assertSame(IngestResult::DISCARDED, $result->status);
    $this->assertSame(0, $this->countNodes());
    $mail = $this->loadMail((int) $result->entityId);
    $this->assertSame(InboundMail::STATE_DISCARDED, $mail->getState());
  }

  /**
   * A bulk mail (Precedence: bulk) is discarded.
   */
  public function testBulkMailIsDiscarded(): void {
    $result = $this->ingest($this->makeRaw('bulk-001@example.org', 'news@example.org', 'Newsletter', 'Buy now.', [
      'Precedence' => 'bulk',
    ]));
    $this->assertSame(IngestResult::DISCARDED, $result->status);
    $this->assertSame(InboundMail::STATE_DISCARDED, $this->loadMail((int) $result->entityId)->getState());
  }

  /**
   * A reply from the same sender appends onto the staged mail.
   */
  public function testReplyAppendsToStagedMail(): void {
    $first = $this->ingest($this->makeRaw('orig-001@example.org'));
    $this->assertSame(IngestResult::STAGED, $first->status);

    $reply = "From: Citizen <citizen@example.org>\r\n"
      . "To: report@city.example\r\n"
      . "Subject: Re: Broken light\r\n"
      . "Message-ID: <reply-001@example.org>\r\n"
      . "In-Reply-To: <orig-001@example.org>\r\n"
      . "References: <orig-001@example.org>\r\n"
      . "Date: Wed, 10 Jun 2026 09:00:00 +0200\r\n"
      . "Content-Type: text/plain; charset=utf-8\r\n"
      . "\r\n"
      . "It is the one near the church.\r\n";

    $result = $this->ingest($reply);
    $this->assertSame(IngestResult::REPLY, $result->status);
    $this->assertSame($first->entityId, $result->entityId);

    $mail = $this->loadMail((int) $first->entityId);
    $this->assertStringContainsString('near the church', $mail->getBody());
    $this->assertContains('reply-001@example.org', $mail->getThreadMessageIds());
    $this->assertSame(0, $this->countNodes());
  }

  /**
   * Promotion creates a node via the processor contract; mail flips state.
   */
  public function testPromotionCreatesServiceRequest(): void {
    $staged = $this->ingest($this->makeRaw('promote-001@example.org'));
    $this->assertSame(IngestResult::STAGED, $staged->status);
    $mail = $this->loadMail((int) $staged->entityId);

    $node = $this->makePromoter()->promoteToServiceRequest($mail, $this->categoryTid);

    $this->assertInstanceOf(NodeInterface::class, $node);
    $this->assertSame(1, $this->countNodes());
    $this->assertFalse($node->isPublished());
    $this->assertSame('email', $node->get('field_source')->value);
    $this->assertSame('promote-001@example.org', $node->get('field_email_message_id')->value);
    $this->assertSame($this->categoryTid, (int) $node->get('field_category')->target_id);
    $this->assertSame($this->gid, (int) $node->get('field_jurisdiction')->target_id);
    $this->assertTrue((bool) $node->get('field_gdpr')->value);
    // The subject went into the description (body), not the title.
    $this->assertStringContainsString('Broken light', (string) $node->get('body')->value);
    // Initial status applied via the processor double.
    $this->assertFalse($node->get('field_status')->isEmpty());

    // The mail is now promoted and points at the node.
    $reloaded = $this->loadMail((int) $staged->entityId);
    $this->assertSame(InboundMail::STATE_PROMOTED, $reloaded->getState());
    $this->assertSame((int) $node->id(), (int) $reloaded->get('nid')->target_id);

    // The processor double seeds a status term description, so the note is not
    // empty and the MEDIUM 4 fallback does not fire here.
    $this->assertFalse($node->get('field_status_notes')->isEmpty());

    // Review MEDIUM 3: the promoter no longer creates the group relationship
    // itself. In production markaspot_group_node_insert relates the node to its
    // ROOT jurisdiction (one relationship, matching the web/Open311 path); its
    // behavior is covered by markaspot_group's own tests. That module is not
    // installed in this kernel test, so removing the safety net means the
    // promoter creates NO relationship here. The assertion proves the promoter
    // does not double-relate (the original double-relationship bug).
    $relationships = $this->container->get('entity_type.manager')
      ->getStorage('group_relationship')
      ->loadByProperties([
        'entity_id' => $node->id(),
        'gid' => $this->gid,
        'plugin_id' => 'group_node:service_request',
      ]);
    $this->assertCount(0, $relationships);
  }

  /**
   * Promoting into a codeless category sets field_category directly.
   *
   * When the chosen service_category term has no field_service_code, the
   * promoter cannot pass a service_code to the Open311 processor (which
   * would then resolve it back to a term). Instead it sets field_category
   * directly on the created node after the processor call. The resulting node
   * must carry the correct field_category tid and a generated title (via the
   * presave hook, absent here but mirrored by the processor double).
   */
  public function testPromotionIntoCodelessCategorySetsFieldCategoryDirectly(): void {
    // Create a codeless term — no field_service_code assigned.
    $codelessTerm = Term::create(['vid' => 'service_category', 'name' => 'Graffiti']);
    $codelessTerm->save();
    $codelessTid = (int) $codelessTerm->id();

    $staged = $this->ingest($this->makeRaw('codeless-001@example.org'));
    $this->assertSame(IngestResult::STAGED, $staged->status);
    $mail = $this->loadMail((int) $staged->entityId);

    $node = $this->makePromoter()->promoteToServiceRequest($mail, $codelessTid);

    $this->assertInstanceOf(NodeInterface::class, $node);
    $this->assertSame(1, $this->countNodes());
    $this->assertFalse($node->isPublished());
    // field_category must be the codeless term's tid, set directly by the
    // promoter (not via the processor's service_code mapping).
    $this->assertSame($codelessTid, (int) $node->get('field_category')->target_id, 'field_category holds the codeless term set directly by the promoter.');
    // Channel and jurisdiction tracking still apply on the codeless path.
    $this->assertSame('email', $node->get('field_source')->value);
    $this->assertSame($this->gid, (int) $node->get('field_jurisdiction')->target_id);
    $this->assertTrue((bool) $node->get('field_gdpr')->value);
    // Description still quotes the original mail.
    $this->assertStringContainsString('Broken light', (string) $node->get('body')->value);

    // The mail is promoted and references the node.
    $reloaded = $this->loadMail((int) $staged->entityId);
    $this->assertSame(InboundMail::STATE_PROMOTED, $reloaded->getState());
    $this->assertSame((int) $node->id(), (int) $reloaded->get('nid')->target_id);
  }

  /**
   * The MEDIUM 4 fallback fills an empty status note with the default string.
   */
  public function testPromotionFallsBackToDefaultStatusNote(): void {
    $staged = $this->ingest($this->makeRaw('note-001@example.org'));
    $mail = $this->loadMail((int) $staged->entityId);

    // A promoter wired to a double whose createStatusNoteParagraph leaves
    // field_status_note empty (no term description / boilerplate).
    $node = $this->makePromoter(emptyStatusNote: TRUE)->promoteToServiceRequest($mail, $this->categoryTid);

    $paragraphId = (int) $node->get('field_status_notes')->target_id;
    $this->assertGreaterThan(0, $paragraphId);
    /** @var \Drupal\paragraphs\ParagraphInterface $paragraph */
    $paragraph = $this->container->get('entity_type.manager')
      ->getStorage('paragraph')->load($paragraphId);
    $this->assertSame(
      'The service request has been created.',
      (string) $paragraph->get('field_status_note')->value
    );
  }

  /**
   * Staged attachments are NOT stored in the public media scheme (HIGH 1).
   */
  public function testStagedAttachmentIsNotPubliclyStored(): void {
    $result = $this->ingest($this->makeRawWithImage('att-001@example.org'));
    $this->assertSame(IngestResult::STAGED, $result->status);

    $mail = $this->loadMail((int) $result->entityId);
    $fileIds = $mail->getAttachmentFileIds();
    $this->assertNotEmpty($fileIds, 'The image attachment was persisted.');

    $file = $this->container->get('entity_type.manager')->getStorage('file')->load(reset($fileIds));
    $this->assertNotNull($file);
    $uri = (string) $file->getFileUri();
    // No private filesystem in the kernel test, so it falls back to the denied
    // public staging subdir; it must NOT be a plain public media file and the
    // filename must not be a guessable timestamp.
    $this->assertStringContainsString('mail-inbound-staged/', $uri);
    $this->assertMatchesRegularExpression('#/mail-[0-9a-f]{32}\.#', $uri, 'Filename uses a non-predictable random component.');
    // PERMANENT while staged: attachment_files registers no file usage, so a
    // temporary file would be garbage-collected by file_cron after ~6h while
    // the mail waits in triage.
    $this->assertTrue($file->isPermanent(), 'A staged attachment is permanent so file GC cannot destroy it mid-triage.');
  }

  /**
   * Promotion relocates a staged attachment and releases the file reference.
   *
   * No private filesystem and no media field config exist in this kernel
   * test, so the resolved media directory is the public ROOT — the exact
   * production constellation in which the earlier str_starts_with() prefix
   * check treated public://mail-inbound-staged/ as already inside the target
   * and left the promoted file in the denied subdir (403 on Apache once the
   * report is published).
   */
  public function testPromotionRelocatesAttachmentAndReleasesReference(): void {
    $staged = $this->ingest($this->makeRawWithImage('att-promote-001@example.org'));
    $this->assertSame(IngestResult::STAGED, $staged->status);
    $mail = $this->loadMail((int) $staged->entityId);
    $fileIds = $mail->getAttachmentFileIds();
    $this->assertNotEmpty($fileIds);

    $node = $this->makePromoter()->promoteToServiceRequest($mail, $this->categoryTid);

    $fid = (int) reset($fileIds);
    $file = $this->container->get('entity_type.manager')->getStorage('file')->load($fid);
    $this->assertNotNull($file);
    $uri = (string) $file->getFileUri();
    $this->assertStringStartsWith('public://', $uri);
    $this->assertStringNotContainsString('mail-inbound-staged/', $uri, 'The promoted file left the denied staging subdir (within-scheme move).');
    $this->assertFileExists($uri);
    $this->assertTrue($file->isPermanent());

    // The node actually carries the file (raw-file fallback branch, no media
    // module here) — the precondition for releasing the mail's reference.
    $this->assertSame($fid, (int) $node->get('field_request_media')->target_id);

    // The mail no longer references the file: it belongs to the node now, and
    // a stale reference would re-delete it at discard or uninstall.
    $reloaded = $this->loadMail((int) $staged->entityId);
    $this->assertSame([], $reloaded->getAttachmentFileIds());
  }

  /**
   * Creates the internal_remark paragraph stack (mirrors the distribution).
   */
  protected function setUpInternalRemarkStack(): void {
    ParagraphsType::create(['id' => 'internal_remark', 'label' => 'Internal remark'])->save();
    $this->createParagraphField('field_internal_remark_text', 'text_long', [], 'internal_remark');
    $this->createNodeField('field_internal_remark', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
  }

  /**
   * Promotion with a staged-phase reply: original text description + remark.
   *
   * No AI suggestion stored: falls back to the original mail text. The FULL
   * conversation log (including the citizen reply) is preserved as ONE
   * internal_remark paragraph (unconditional, product decision 2026-06-11).
   */
  public function testPromotionWithStagedReplyQuotesOriginalAndStoresLogAsRemark(): void {
    $this->setUpInternalRemarkStack();

    $staged = $this->ingest($this->makeRaw('convo-001@example.org'));
    $this->assertSame(IngestResult::STAGED, $staged->status);

    // A citizen reply to the staged mail appends a conversation entry.
    $reply = "From: Citizen <citizen@example.org>\r\n"
      . "To: report@city.example\r\n"
      . "Subject: Re: Broken light\r\n"
      . "Message-ID: <convo-reply-001@example.org>\r\n"
      . "In-Reply-To: <convo-001@example.org>\r\n"
      . "References: <convo-001@example.org>\r\n"
      . "Date: Wed, 10 Jun 2026 09:00:00 +0200\r\n"
      . "Content-Type: text/plain; charset=utf-8\r\n"
      . "\r\n"
      . "It is the one near the church.\r\n";
    $this->assertSame(IngestResult::REPLY, $this->ingest($reply)->status);

    $mail = $this->loadMail((int) $staged->entityId);
    $node = $this->makePromoter()->promoteToServiceRequest($mail, $this->categoryTid);

    // No suggested_description set: description = subject + ORIGINAL text
    // only (no reply content, no conversation marker, no staff dialogue).
    $this->assertSame(
      "Broken light\n\nA streetlight is broken.",
      (string) $node->get('body')->value
    );

    // ONE internal remark carries the FULL conversation log with the label.
    $items = $node->get('field_internal_remark')->getValue();
    $this->assertCount(1, $items);
    /** @var \Drupal\paragraphs\ParagraphInterface $paragraph */
    $paragraph = $this->container->get('entity_type.manager')
      ->getStorage('paragraph')->load($items[0]['target_id']);
    $text = (string) $paragraph->get('field_internal_remark_text')->value;
    $this->assertStringContainsString('E-Mail-Konversation aus dem Posteingang (vor Übernahme)', $text);
    $this->assertStringContainsString('A streetlight is broken.', $text);
    $this->assertStringContainsString('Reply from citizen@example.org', $text);
    $this->assertStringContainsString('It is the one near the church.', $text);
  }

  /**
   * Promoting a clean one-message mail ALWAYS creates a remark (unconditional).
   *
   * Product decision (2026-06-11): even a single-message mail gets a remark
   * so staff can always read what the citizen actually wrote, regardless of
   * whether the description came from an AI paraphrase or the original text.
   */
  public function testPromotionOfCleanMailAlwaysCreatesRemark(): void {
    $this->setUpInternalRemarkStack();

    $staged = $this->ingest($this->makeRaw('clean-001@example.org'));
    $mail = $this->loadMail((int) $staged->entityId);
    $node = $this->makePromoter()->promoteToServiceRequest($mail, $this->categoryTid);

    // Fallback description (no suggested_description stored).
    $this->assertSame(
      "Broken light\n\nA streetlight is broken.",
      (string) $node->get('body')->value
    );
    // Unconditional: even a clean mail produces one remark.
    $items = $node->get('field_internal_remark')->getValue();
    $this->assertCount(1, $items);
    /** @var \Drupal\paragraphs\ParagraphInterface $paragraph */
    $paragraph = $this->container->get('entity_type.manager')
      ->getStorage('paragraph')->load($items[0]['target_id']);
    $text = (string) $paragraph->get('field_internal_remark_text')->value;
    $this->assertStringContainsString('E-Mail-Konversation aus dem Posteingang (vor Übernahme)', $text);
    $this->assertStringContainsString('A streetlight is broken.', $text);
  }

  /**
   * Promotion WITH a stored suggested_description uses AI text for the body.
   *
   * Product decision (2026-06-11): when the AI stored a suggested_description,
   * that becomes the public node body (subject + AI text). The citizen's
   * original wording is ALWAYS preserved in the internal remark.
   */
  public function testPromotionWithSuggestedDescriptionUsesAiText(): void {
    $this->setUpInternalRemarkStack();

    $staged = $this->ingest($this->makeRaw('ai-desc-001@example.org'));
    $this->assertSame(IngestResult::STAGED, $staged->status);

    // Simulate the AI suggestion service having stored a suggested_description.
    $mail = $this->loadMail((int) $staged->entityId);
    $mail->setSuggestedDescription('A streetlight on Hauptstrasse is out of order.');
    $mail->save();

    $node = $this->makePromoter()->promoteToServiceRequest($mail, $this->categoryTid);

    // Description = subject + AI-generated text (NOT the raw mail body).
    $this->assertSame(
      "Broken light\n\nA streetlight on Hauptstrasse is out of order.",
      (string) $node->get('body')->value
    );

    // The internal remark ALWAYS contains the citizen's original wording.
    $items = $node->get('field_internal_remark')->getValue();
    $this->assertCount(1, $items);
    /** @var \Drupal\paragraphs\ParagraphInterface $paragraph */
    $paragraph = $this->container->get('entity_type.manager')
      ->getStorage('paragraph')->load($items[0]['target_id']);
    $text = (string) $paragraph->get('field_internal_remark_text')->value;
    $this->assertStringContainsString('E-Mail-Konversation aus dem Posteingang (vor Übernahme)', $text);
    // Original citizen text preserved in the remark, not the AI paraphrase.
    $this->assertStringContainsString('A streetlight is broken.', $text);
    // AI text is in the node body, not the remark.
    $this->assertStringNotContainsString('Hauptstrasse is out of order', $text);
  }

  /**
   * Discard flips the mail to discarded and creates no node.
   */
  public function testDiscardMarksMailDiscarded(): void {
    $staged = $this->ingest($this->makeRaw('discard-001@example.org'));
    $mail = $this->loadMail((int) $staged->entityId);

    $this->makePromoter()->discard($mail);

    $reloaded = $this->loadMail((int) $staged->entityId);
    $this->assertSame(InboundMail::STATE_DISCARDED, $reloaded->getState());
    $this->assertSame(0, $this->countNodes());
  }

  /**
   * Builds a promoter wired to a lightweight processor double.
   *
   * The double mirrors the three processor methods the promoter calls; the
   * full input contract is verified in the unit test.
   *
   * @param bool $emptyStatusNote
   *   When TRUE the double leaves field_status_note empty so the promoter's
   *   MEDIUM 4 default-note fallback is exercised.
   */
  protected function makePromoter(bool $emptyStatusNote = FALSE): InboundMailPromoter {
    $statusTerm = Term::create(['vid' => 'service_category', 'name' => 'Open']);
    $statusTerm->save();
    $statusTid = (int) $statusTerm->id();

    $entityTypeManager = $this->container->get('entity_type.manager');

    $processor = new class($entityTypeManager, $statusTid, $emptyStatusNote) {

      public function __construct(
        protected $entityTypeManager,
        protected int $statusTid,
        protected bool $emptyStatusNote = FALSE,
      ) {}

      /**
       * Mirrors the real processor's create-path field mapping.
       */
      public function prepareNodeProperties(array $requestData, string $operation): array {
        // Mirror the real processor's field mapping for the keys the promoter
        // passes: service_code -> field_category, description -> body,
        // email -> field_e_mail, field_gdpr passthrough.
        $term = NULL;
        if (isset($requestData['service_code'])) {
          $terms = $this->entityTypeManager->getStorage('taxonomy_term')
            ->loadByProperties([
              'vid' => 'service_category',
              'field_service_code' => $requestData['service_code'],
            ]);
          $term = $terms ? reset($terms) : NULL;
        }
        $values = [
          'type' => 'service_request',
          'langcode' => 'und',
          // The real processor sets a temporary title (the service code) that
          // markaspot_request_id's presave hook later overwrites with
          // "#<id> <category>". That module is not installed here, so the tmp
          // title also satisfies the NOT NULL title column. (Integration note:
          // promotion depends on markaspot_request_id for the final title.)
          'title' => $requestData['service_code'] ?? 'service_request',
        ];
        if ($term) {
          $values['field_category'] = (int) $term->id();
        }
        if (isset($requestData['email'])) {
          $values['field_e_mail'] = ['value' => $requestData['email']];
        }
        if (isset($requestData['description'])) {
          $values['body'] = ['value' => $requestData['description'], 'format' => 'plain_text'];
        }
        if (array_key_exists('field_gdpr', $requestData)) {
          $values['field_gdpr'] = (bool) $requestData['field_gdpr'];
        }
        return $values;
      }

      /**
       * Returns the seeded initial status term id.
       */
      public function getInitialStatusTid(?int $jurisdictionId = NULL): ?int {
        return $this->statusTid;
      }

      /**
       * Creates a minimal status note paragraph.
       */
      public function createStatusNoteParagraph(array $fields, string $langcode = ''): Paragraph {
        $paragraph = Paragraph::create(['type' => 'status', 'langcode' => $langcode ?: 'en']);
        if (!empty($fields['status_term_id'])) {
          $paragraph->set('field_status_term', $fields['status_term_id']);
        }
        // Mirror the real processor: only set a note when there is one. When
        // emptyStatusNote is requested, leave it empty so the promoter's
        // default-note fallback (MEDIUM 4) is exercised.
        if (!$this->emptyStatusNote) {
          $paragraph->set('field_status_note', ['value' => 'Created.', 'format' => 'plain_text']);
        }
        $paragraph->save();
        return $paragraph;
      }

    };

    return new InboundMailPromoter(
      $entityTypeManager,
      $this->container->get('module_handler'),
      $this->container->get('event_dispatcher'),
      $this->container->get('logger.factory')->get('markaspot_mail_inbound'),
      $this->container->get('entity_field.manager'),
      $this->container->get('config.factory'),
      $this->container->get('file_system'),
      $this->container->get('token'),
      $this->container->get('markaspot_mail_inbound.internal_remark_writer'),
      $processor,
      NULL,
    );
  }

}
