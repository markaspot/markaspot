<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_mail_inbound\Dto\InboundMessage;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Event\InboundRequestCreatedEvent;
use Drupal\markaspot_mail_inbound\IngestResult;
use Drupal\markaspot_mail_inbound\Service\InboundMailReplyService;
use Drupal\markaspot_mail_inbound\Service\MailIntakeFidelity;
use Drupal\markaspot_mail_inbound\Util\MailTextUtils;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;

/**
 * Kernel test for the Phase 2 return channel (#482).
 *
 * Proves:
 * - a staff reply goes out with our own Message-ID plus In-Reply-To /
 *   References from the thread chain, From/Reply-To = the jurisdiction
 *   address, and is recorded on the conversation log,
 * - the outbound Message-ID is recorded so a CITIZEN REPLY TO OUR REPLY
 *   threads back onto the same staged mail through the orchestrator,
 * - outbound-unavailable degrades to record-only (sent=false, "(not sent"
 *   marker, no thread id recorded),
 * - markaspot_mail_inbound_mail_alter() threads OTHER modules' node mails
 *   (status notifications) into the citizen's conversation — and ONLY for
 *   email-origin nodes with the verified reporter as recipient,
 * - the missing-location auto-reply fires on the request-created event,
 *   gated by the auto_reply_missing_location flag and an empty
 *   field_geolocation.
 *
 * @group markaspot_mail_inbound
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class InboundMailReturnChannelKernelTest extends KernelTestBase {

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
    'file',
    'entity',
    'flexible_permissions',
    'group',
    // The module ships a jsonapi_extras.jsonapi_resource_config in
    // config/install (the disabled inbound_mail JSON:API resource), so
    // installConfig(['markaspot_mail_inbound']) needs the stack present.
    'serialization',
    'jsonapi',
    'jsonapi_extras',
    'markaspot_mail_inbound',
  ];

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
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installEntitySchema('inbound_mail');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node', 'group']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();

    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    $this->createNodeField('field_e_mail', 'email');
    $this->createNodeField('field_geolocation', 'string');
    // field_source / field_email_message_id + settings ship as module config.
    $this->installConfig(['markaspot_mail_inbound']);

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $this->createField('group', 'jur', 'field_jurisdiction_e_mail', 'email', [], 1);
    $group = Group::create([
      'type' => 'jur',
      'label' => 'City',
      'field_jurisdiction_e_mail' => 'report@city.example',
    ]);
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
          'sender_blocklist' => [],
        ],
      ])
      ->save();
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
   * Creates a node field on service_request.
   */
  protected function createNodeField(string $name, string $type, array $settings = [], int $cardinality = 1): void {
    $this->createField('node', 'service_request', $name, $type, $settings, $cardinality);
  }

  /**
   * Creates a saved staged inbound mail with one original thread id.
   */
  protected function stagedMail(string $messageId = 'orig-001@example.org'): InboundMail {
    /** @var \Drupal\markaspot_mail_inbound\Entity\InboundMail $mail */
    $mail = $this->container->get('entity_type.manager')->getStorage('inbound_mail')->create([
      'from_address' => 'citizen@example.org',
      'from_name' => 'Citizen',
      'subject' => 'Broken light',
      'body' => ['value' => 'A streetlight is broken.', 'format' => 'plain_text'],
      'message_id' => $messageId,
      'thread_message_ids' => [$messageId],
      'state' => InboundMail::STATE_STAGED,
      'jurisdiction_id' => ['target_id' => $this->gid],
    ]);
    $mail->save();
    return $mail;
  }

  /**
   * Creates a promoted mail + email-origin node pair.
   *
   * @return array{0: InboundMail, 1: NodeInterface}
   *   The promoted mail and its service request node.
   */
  protected function promotedPair(?string $geolocation = NULL): array {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Report',
      'field_e_mail' => 'citizen@example.org',
      'field_source' => 'email',
    ]);
    if ($geolocation !== NULL) {
      $node->set('field_geolocation', $geolocation);
    }
    $node->save();

    $mail = $this->stagedMail('promoted-' . random_int(100000, 999999) . '@example.org');
    $mail->set('nid', ['target_id' => $node->id()]);
    $mail->setState(InboundMail::STATE_PROMOTED);
    $mail->save();
    return [$mail, $node];
  }

  /**
   * Returns the collected test mails.
   */
  protected function collectedMails(): array {
    return $this->container->get('state')->get('system.test_mail_collector') ?? [];
  }

  /**
   * A staff reply threads, brands the sender and records the conversation.
   */
  public function testReplyThreadsRecordsAndUsesJurisdictionAddress(): void {
    $mail = $this->stagedMail();
    /** @var \Drupal\markaspot_mail_inbound\Service\InboundMailReplyService $service */
    $service = $this->container->get('markaspot_mail_inbound.reply');

    $sent = $service->sendReply($mail, 'Where exactly is the light?');
    $this->assertTrue($sent);

    $mails = $this->collectedMails();
    $this->assertCount(1, $mails);
    $message = $mails[0];
    $this->assertSame('citizen@example.org', $message['to']);
    $this->assertSame('Re: Broken light', $message['subject']);

    // Threading headers: our own Message-ID + the citizen's chain.
    $this->assertMatchesRegularExpression('/^<[0-9a-f-]{36}@[A-Za-z0-9.\-]+>$/', $message['headers']['Message-ID']);
    $this->assertSame('<orig-001@example.org>', $message['headers']['In-Reply-To']);
    $this->assertSame('<orig-001@example.org>', $message['headers']['References']);

    // The jurisdiction address wins for From and Reply-To.
    $this->assertSame('report@city.example', $message['headers']['From']);
    $this->assertSame('report@city.example', $message['headers']['Reply-To']);

    // RFC 3834 loop protection on the staff reply key: auto-generated +
    // responder suppression, but NO Precedence: bulk (it is an individual
    // human answer, not bulk mail).
    $this->assertSame('auto-generated', $message['headers']['Auto-Submitted']);
    $this->assertSame('All', $message['headers']['X-Auto-Response-Suppress']);
    $this->assertArrayNotHasKey('Precedence', $message['headers']);

    // The outbound id is recorded on the thread chain; the log carries the
    // staff entry (shared conversation format) and changed was touched.
    $reloaded = $this->reload($mail);
    $outboundId = MailTextUtils::normalizeMessageId($message['headers']['Message-ID']);
    $this->assertContains($outboundId, $reloaded->getThreadMessageIds());
    $this->assertStringContainsString("---\nStaff reply (", $reloaded->getBody());
    $this->assertStringContainsString('Where exactly is the light?', $reloaded->getBody());
  }

  /**
   * A citizen reply to OUR reply threads back onto the same staged mail.
   */
  public function testCitizenReplyToOurReplyThreadsBack(): void {
    $mail = $this->stagedMail();
    $this->container->get('markaspot_mail_inbound.reply')->sendReply($mail, 'Where exactly?');

    $outboundId = MailTextUtils::normalizeMessageId($this->collectedMails()[0]['headers']['Message-ID']);
    $this->assertNotSame('', $outboundId);

    // The citizen's mail client replies to OUR message id only.
    $raw = "From: Citizen <citizen@example.org>\r\n"
      . "To: report@city.example\r\n"
      . "Subject: Re: Broken light\r\n"
      . "Message-ID: <citizen-reply-001@example.org>\r\n"
      . "In-Reply-To: <$outboundId>\r\n"
      . "References: <orig-001@example.org> <$outboundId>\r\n"
      . "Date: Wed, 10 Jun 2026 09:00:00 +0200\r\n"
      . "Content-Type: text/plain; charset=utf-8\r\n"
      . "\r\n"
      . "It is the one near the church.\r\n";

    $message = $this->container->get('markaspot_mail_inbound.parser')->parse($raw);
    $mailbox = $this->container->get('markaspot_mail_inbound.mailbox_resolver')->getMailbox('city_main');
    $result = $this->container->get('markaspot_mail_inbound.orchestrator')->ingest($message, $mailbox);

    $this->assertSame(IngestResult::REPLY, $result->status);
    $this->assertSame((int) $mail->id(), $result->entityId);
    $reloaded = $this->reload($mail);
    $this->assertStringContainsString('near the church', $reloaded->getBody());
    $this->assertContains('citizen-reply-001@example.org', $reloaded->getThreadMessageIds());
  }

  /**
   * Outbound-unavailable degrades to record-only (sent=false).
   */
  public function testReplyDegradesToRecordOnly(): void {
    $mail = $this->stagedMail();

    // A fidelity stub reporting "no outbound" stands in for an install
    // without a mail plugin (the kernel environment force-configures the
    // test collector via $GLOBALS, so the real config cannot be unset here).
    $fidelity = new class(
      $this->container->get('markaspot_mail_inbound.mailbox_resolver'),
      $this->container->get('config.factory'),
      $this->container->get('stream_wrapper_manager'),
      $this->container->get('entity_type.manager'),
    ) extends MailIntakeFidelity {

      /**
       * {@inheritdoc}
       */
      public function outboundAvailable(): bool {
        return FALSE;
      }

    };

    $service = new InboundMailReplyService(
      $this->container->get('plugin.manager.mail'),
      $this->container->get('entity_type.manager'),
      $this->container->get('language_manager'),
      $this->container->get('config.factory'),
      $this->container->get('uuid'),
      $this->container->get('request_stack'),
      $this->container->get('markaspot_mail_inbound.mailbox_resolver'),
      $fidelity,
      $this->container->get('logger.factory')->get('markaspot_mail_inbound'),
    );

    $threadIdsBefore = $mail->getThreadMessageIds();
    $sent = $service->sendReply($mail, 'This will not be sent.');

    $this->assertFalse($sent);
    $this->assertSame([], $this->collectedMails(), 'No mail left the system.');

    $reloaded = $this->reload($mail);
    $this->assertSame($threadIdsBefore, $reloaded->getThreadMessageIds(), 'No phantom outbound id was recorded.');
    $this->assertStringContainsString('(not sent: outbound mail unavailable)', $reloaded->getBody());
    $this->assertStringContainsString('This will not be sent.', $reloaded->getBody());
  }

  /**
   * Hook_mail_alter threads node mails to the reporter; nothing else.
   */
  public function testMailAlterThreadsOnlyReporterMailsOfEmailNodes(): void {
    [$mail, $node] = $this->promotedPair();
    $originalThreadIds = $mail->getThreadMessageIds();

    // 1. A status notification to the REPORTER gets the full thread chain
    // (markaspot_notification params convention: params['node']).
    $message = $this->mailArray('markaspot_notification', 'status_change', 'citizen@example.org', ['node' => $node]);
    markaspot_mail_inbound_mail_alter($message);
    $this->assertArrayHasKey('Message-ID', $message['headers']);
    $this->assertSame('<' . end($originalThreadIds) . '>', $message['headers']['In-Reply-To']);
    $this->assertStringContainsString('<' . $originalThreadIds[0] . '>', $message['headers']['References']);
    $outboundId = MailTextUtils::normalizeMessageId($message['headers']['Message-ID']);
    $this->assertContains($outboundId, $this->reload($mail)->getThreadMessageIds(), 'The outbound id was recorded for reply-threading.');

    // 2. The ECA EmailAction convention (params['context']['node']) works.
    $ecaMessage = $this->mailArray('system', 'action_send_email', 'citizen@example.org', ['context' => ['node' => $node]]);
    markaspot_mail_inbound_mail_alter($ecaMessage);
    $this->assertArrayHasKey('In-Reply-To', $ecaMessage['headers']);

    // 2b. The second ECA key (params['context']['entity'], which
    // EcaActionEmailBuilder supports alongside 'node') resolves too.
    $ecaEntityMessage = $this->mailArray('system', 'action_send_email', 'citizen@example.org', ['context' => ['entity' => $node]]);
    markaspot_mail_inbound_mail_alter($ecaEntityMessage);
    $this->assertArrayHasKey('In-Reply-To', $ecaEntityMessage['headers']);

    // 3. A staff recipient is never threaded into the citizen conversation.
    $staffMessage = $this->mailArray('markaspot_notification', 'status_change', 'staff@city.example', ['node' => $node]);
    markaspot_mail_inbound_mail_alter($staffMessage);
    $this->assertArrayNotHasKey('In-Reply-To', $staffMessage['headers']);

    // 3b. An address merely CONTAINING the reporter's address is NOT the
    // reporter (substring matching would leak the thread's Message-IDs).
    $lookalike = $this->mailArray('markaspot_notification', 'status_change', 'fakecitizen@example.org', ['node' => $node]);
    markaspot_mail_inbound_mail_alter($lookalike);
    $this->assertArrayNotHasKey('In-Reply-To', $lookalike['headers']);

    // 3c. The "Display Name <addr>" form resolves to the reporter.
    $named = $this->mailArray('markaspot_notification', 'status_change', 'Citizen <citizen@example.org>', ['node' => $node]);
    markaspot_mail_inbound_mail_alter($named);
    $this->assertArrayHasKey('In-Reply-To', $named['headers']);

    // 4. A web-origin node is never touched.
    $webNode = Node::create([
      'type' => 'service_request',
      'title' => 'Web report',
      'field_e_mail' => 'citizen@example.org',
      'field_source' => 'web',
    ]);
    $webNode->save();
    $webMessage = $this->mailArray('markaspot_notification', 'status_change', 'citizen@example.org', ['node' => $webNode]);
    markaspot_mail_inbound_mail_alter($webMessage);
    $this->assertArrayNotHasKey('In-Reply-To', $webMessage['headers']);

    // 5. Our own module's mails are skipped (they thread via hook_mail).
    $ownMessage = $this->mailArray('markaspot_mail_inbound', 'triage_reply', 'citizen@example.org', ['node' => $node]);
    markaspot_mail_inbound_mail_alter($ownMessage);
    $this->assertArrayNotHasKey('In-Reply-To', $ownMessage['headers']);

    // 6. A mail without any node params is left alone.
    $plainMessage = $this->mailArray('user_module', 'whatever', 'citizen@example.org', []);
    markaspot_mail_inbound_mail_alter($plainMessage);
    $this->assertSame([], $plainMessage['headers']);
  }

  /**
   * A reply to OUR outbound id on a PROMOTED mail threads to the node.
   *
   * The outbound Message-ID of a status mail lives only on the promoted
   * inbound_mail's thread chain (the node stores just the original citizen
   * id). A citizen reply carrying ONLY that outbound id must hop from the
   * matched promoted mail to its service request — never stage a new mail —
   * and the reply id must be recorded for the next round.
   */
  public function testReplyToOutboundIdOnPromotedMailThreadsToNode(): void {
    [$mail, $node] = $this->promotedPair();

    // A status mail to the citizen records an outbound id on the chain.
    $statusMessage = $this->mailArray('markaspot_notification', 'status_change', 'citizen@example.org', ['node' => $node]);
    markaspot_mail_inbound_mail_alter($statusMessage);
    $outboundId = MailTextUtils::normalizeMessageId($statusMessage['headers']['Message-ID']);
    $this->assertNotSame('', $outboundId);

    // The citizen's client replies to the status mail ONLY (no References
    // back to the original id — some clients truncate the chain).
    $raw = "From: Citizen <citizen@example.org>\r\n"
      . "To: report@city.example\r\n"
      . "Subject: Re: Status update\r\n"
      . "Message-ID: <round3-001@example.org>\r\n"
      . "In-Reply-To: <$outboundId>\r\n"
      . "Date: Wed, 10 Jun 2026 12:00:00 +0200\r\n"
      . "Content-Type: text/plain; charset=utf-8\r\n"
      . "\r\n"
      . "Here is the location: near the church.\r\n";

    $message = $this->container->get('markaspot_mail_inbound.parser')->parse($raw);
    $mailbox = $this->container->get('markaspot_mail_inbound.mailbox_resolver')->getMailbox('city_main');
    $result = $this->container->get('markaspot_mail_inbound.orchestrator')->ingest($message, $mailbox);

    $this->assertSame(IngestResult::REPLY, $result->status);
    $this->assertSame(IngestResult::KIND_NODE, $result->kind, 'The reply resolved to the service request, not a new staged mail.');
    $this->assertSame((int) $node->id(), $result->entityId);

    // The reply id was recorded so a follow-up reply still resolves.
    $this->assertContains('round3-001@example.org', $this->reload($mail)->getThreadMessageIds());
  }

  /**
   * The auto-reply fires for an ungeolocated promotion — gated correctly.
   */
  public function testAutoReplyMissingLocationGating(): void {
    $dispatcher = $this->container->get('event_dispatcher');

    // 1. Ungeolocated + flag enabled (install default): the auto-reply goes
    // out, threaded into the conversation.
    [, $node] = $this->promotedPair();
    $dispatcher->dispatch($this->event($node), InboundRequestCreatedEvent::EVENT_NAME);
    $mails = $this->collectedMails();
    $this->assertCount(1, $mails);
    $this->assertSame('auto_reply_missing_location', $mails[0]['key']);
    $this->assertSame('citizen@example.org', $mails[0]['to']);
    $this->assertArrayHasKey('In-Reply-To', $mails[0]['headers']);
    $this->assertStringContainsString('location', strtolower(implode("\n", (array) $mails[0]['body'])));

    // RFC 3834 loop protection on the auto-reply key: a fully automatic
    // response declares auto-replied + suppression + Precedence: bulk.
    $this->assertSame('auto-replied', $mails[0]['headers']['Auto-Submitted']);
    $this->assertSame('All', $mails[0]['headers']['X-Auto-Response-Suppress']);
    $this->assertSame('bulk', $mails[0]['headers']['Precedence']);

    // 2. A geolocated promotion never triggers the auto-reply.
    [, $locatedNode] = $this->promotedPair('51.5,7.4');
    $dispatcher->dispatch($this->event($locatedNode), InboundRequestCreatedEvent::EVENT_NAME);
    $this->assertCount(1, $this->collectedMails(), 'No additional mail for a located report.');

    // 3. The settings flag turns the auto-reply off entirely.
    $this->config('markaspot_mail_inbound.settings')->set('auto_reply_missing_location', FALSE)->save();
    [, $gatedNode] = $this->promotedPair();
    $dispatcher->dispatch($this->event($gatedNode), InboundRequestCreatedEvent::EVENT_NAME);
    $this->assertCount(1, $this->collectedMails(), 'The flag gates the auto-reply.');
  }

  /**
   * Builds a hook_mail_alter $message array.
   */
  protected function mailArray(string $module, string $key, string $to, array $params): array {
    return [
      'module' => $module,
      'key' => $key,
      'to' => $to,
      'subject' => 'Status update',
      'body' => ['Your report was updated.'],
      'params' => $params,
      'headers' => [],
    ];
  }

  /**
   * Builds the request-created event for a node.
   */
  protected function event(NodeInterface $node): InboundRequestCreatedEvent {
    $message = new InboundMessage(
      fromAddress: 'citizen@example.org',
      fromName: 'Citizen',
      toAddresses: ['report@city.example'],
      subject: 'Broken light',
      textBody: 'A streetlight is broken.',
      htmlBody: '',
      messageId: 'orig-001@example.org',
      inReplyTo: '',
      references: [],
      date: NULL,
      attachments: [],
    );
    return new InboundRequestCreatedEvent($node, $message);
  }

  /**
   * Reloads a mail bypassing the static cache.
   */
  protected function reload(InboundMail $mail): InboundMail {
    $storage = $this->container->get('entity_type.manager')->getStorage('inbound_mail');
    $storage->resetCache([$mail->id()]);
    /** @var \Drupal\markaspot_mail_inbound\Entity\InboundMail $reloaded */
    $reloaded = $storage->load($mail->id());
    return $reloaded;
  }

}
