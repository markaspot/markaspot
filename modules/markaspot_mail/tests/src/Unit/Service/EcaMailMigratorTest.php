<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\markaspot_mail\Service\EcaMailMigrator;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Covers the ECA-to-markaspot_mail migration heuristics and rewrite logic.
 *
 * Fixtures under tests/fixtures/eca/ are verbatim copies of the shipped
 * markaspot_group defaults and the real bleckede/dorsten tenant configs
 * (bleckede: German-localized copy; dorsten: tenant-specific status tids
 * 15/30/37/14 instead of the shipped 4/5/30, and the custom
 * [node:markaspot_frontend_url] token instead of [site:url][node:url]).
 * Testing against the real structures instead of hand-trimmed arrays
 * exercises the condition-chain walk the way the actual tenants would.
 */
#[CoversClass(EcaMailMigrator::class)]
#[Group('markaspot_mail')]
final class EcaMailMigratorTest extends UnitTestCase {

  /**
   *
   */
  public function testMatchKeywordsReturnsNullForEmptyString(): void {
    $this->assertNull(EcaMailMigrator::matchKeywords(''));
  }

  /**
   *
   */
  public function testMatchKeywordsReturnsNullWhenNoBucketMatches(): void {
    $this->assertNull(EcaMailMigrator::matchKeywords('Kein Befall'));
  }

  /**
   * Tests that closed-bucket keywords in either language are recognized.
   */
  #[DataProvider('closedKeywordProvider')]
  public function testMatchKeywordsMatchesClosedBucket(string $text): void {
    $this->assertSame('status_closed', EcaMailMigrator::matchKeywords($text));
  }

  /**
   * Data provider for testMatchKeywordsMatchesClosedBucket().
   */
  public static function closedKeywordProvider(): iterable {
    yield 'german erledigt' => ['Ihre Meldung wurde erledigt'];
    yield 'german abgeschlossen' => ['Prüfung abgeschlossen'];
    yield 'english closed' => ['Closed! Your Service Request'];
    yield 'english solved' => ['Solved! Your Service Request'];
  }

  /**
   *
   */
  public function testMatchKeywordsMatchesNotResponsibleBucket(): void {
    $this->assertSame('status_not_responsible', EcaMailMigrator::matchKeywords('Nicht zuständig'));
    $this->assertSame('status_not_responsible', EcaMailMigrator::matchKeywords('We are not responsible'));
  }

  /**
   *
   */
  public function testMatchKeywordsMatchesOpenBucket(): void {
    $this->assertSame('status_open', EcaMailMigrator::matchKeywords('Open'));
    $this->assertSame('status_open', EcaMailMigrator::matchKeywords('In Bearbeitung'));
  }

  /**
   * Tests that the closed bucket wins ties against the open bucket.
   *
   * "Abgeschlossen" (closed) must win over "Bearbeitung" (open) in the
   * same string, matching the bucket priority documented on the class
   * constants.
   */
  public function testMatchKeywordsPrioritizesClosedOverOpenOnAmbiguousText(): void {
    $this->assertSame('status_closed', EcaMailMigrator::matchKeywords('Bearbeitung abgeschlossen'));
  }

  /**
   * Tests a bleckede-style combined greeting/body/link/footer message.
   *
   * Greeting / body / "see status here:\n[token]" (one paragraph, single
   * newline) / footer, all in one legacy message.
   */
  public function testTransformMessageStripsFooterLinkAndSignatureCombinedIntoOneParagraph(): void {
    $message = <<<'TEXT'
    Guten Tag,

    Ihre Meldung mit der Nummer [node:request_id] wurde bearbeitet und als erledigt markiert.

    Weitere Informationen finden Sie unter:
    [site:url][node:url:path]

    [node:field_jurisdiction:entity:field_email_footer]
    TEXT;

    $migrator = $this->buildMigrator();
    $result = $migrator->transformMessage($message);

    $this->assertSame('Guten Tag,', $result['intro']);
    $this->assertSame(['Ihre Meldung mit der Nummer [node:request_id] wurde bearbeitet und als erledigt markiert.'], $result['body_blocks']);
    $this->assertSame('Meldung ansehen', $result['cta_label']);
  }

  /**
   * Tests recognition of the custom markaspot_token frontend URL token.
   *
   * Dorsten's real copy never uses [site:url]/[node:url], only the
   * markaspot_token-provided [node:markaspot_frontend_url].
   */
  public function testTransformMessageRecognizesCustomFrontendUrlToken(): void {
    $message = "Guten Tag!\n\nIhre Meldung wurde aktualisiert.\n\n[node:markaspot_frontend_url]\n\n--\nDies ist eine automatisch generierte E-Mail. Bitte antworten Sie nicht auf diese Nachricht.";

    $migrator = $this->buildMigrator();
    $result = $migrator->transformMessage($message);

    $this->assertSame(['Ihre Meldung wurde aktualisiert.'], $result['body_blocks']);
    $this->assertSame('Meldung ansehen', $result['cta_label']);
  }

  /**
   * Tests that a genuine external link paragraph is preserved.
   *
   * A bare https:// URL that is NOT a [site:url]/[node:url]/
   * [node:markaspot_frontend_url] token is real tenant content (e.g. a
   * business-hours page) and must survive the migration.
   */
  public function testTransformMessagePreservesGenuineExternalLinkAsBodyContent(): void {
    $message = "Good day!\n\nWe are not responsible.\n\nFor more information about our business hours, please visit: https://www.bonn.de/?sp:id=61983\n\n-- This is an automatically generated email, and unfortunately, you cannot reply to it.";

    $migrator = $this->buildMigrator();
    $result = $migrator->transformMessage($message);

    $this->assertContains('For more information about our business hours, please visit: https://www.bonn.de/?sp:id=61983', $result['body_blocks']);
    $this->assertNotContains('-- This is an automatically generated email, and unfortunately, you cannot reply to it.', $result['body_blocks']);
  }

  /**
   *
   */
  public function testTransformMessageDetectsEnglishWhenNoGermanNounsPresent(): void {
    $message = "Good day!\n\nYour request has been received.\n\n[site:url][node:url:path]";
    $migrator = $this->buildMigrator();
    $result = $migrator->transformMessage($message);
    $this->assertSame('View your report', $result['cta_label']);
  }

  /**
   * Documents a known, accepted limitation of the per-paragraph strip.
   *
   * The shipped EN default separates the lead-in sentence from the link
   * token with a blank line (its own paragraph), so only the token
   * paragraph is dropped. The lead-in sentence survives as a (now
   * dangling) body_block. Documented here as current behavior, not
   * silently "fixed" by a look-ahead heuristic nobody asked for.
   */
  public function testTransformMessageDoesNotDropUnrelatedColonEndedLeadIn(): void {
    $message = "Please click on the following link to access additional information about your request at any time:\n\n[site:url][node:url:path]";
    $migrator = $this->buildMigrator();
    $result = $migrator->transformMessage($message);
    $this->assertSame('Please click on the following link to access additional information about your request at any time:', $result['intro']);
    $this->assertSame([], $result['body_blocks']);
  }

  /**
   *
   */
  public function testAnalyzeSuggestsReportConfirmationForInsertModel(): void {
    $raw = $this->loadFixture('shipped_process_confirm_report');
    $migrator = $this->buildMigrator(['eca.eca.process_confirm_report' => $raw]);

    $findings = $migrator->analyze();

    $this->assertCount(1, $findings);
    $this->assertSame('insert', $findings[0]['trigger_event']);
    $this->assertSame('report_confirmation', $findings[0]['suggested_key']);
    $this->assertSame('RESOLVED', $findings[0]['status']);
  }

  /**
   * Tests the simplest real insert flow across tenants.
   *
   * Bleckede's process_ugsohtl is the simplest real insert flow: one
   * event, no gateway, one unconditional action.
   */
  public function testAnalyzeSuggestsReportConfirmationForUnconditionalInsertActionAcrossTenants(): void {
    $raw = $this->loadFixture('bleckede_process_ugsohtl');
    $migrator = $this->buildMigrator(['eca.eca.process_ugsohtl' => $raw]);

    $findings = $migrator->analyze();

    $this->assertCount(1, $findings);
    $this->assertSame('report_confirmation', $findings[0]['suggested_key']);
    $this->assertSame('(unconditional)', $findings[0]['branch_condition']);
  }

  /**
   *
   */
  public function testAnalyzeResolvesStatusKeysFromTaxonomyTermNames(): void {
    $raw = $this->loadFixture('shipped_process_tunr6d6');
    $migrator = $this->buildMigrator(
      ['eca.eca.process_tunr6d6' => $raw],
      termNamesByTid: [5 => 'Closed', 30 => 'Not Responsible', 4 => 'Open'],
    );

    $findings = $this->indexByActivity($migrator->analyze());

    $this->assertSame('status_closed', $findings['Activity_1u8e4au']['suggested_key']);
    $this->assertSame('status_not_responsible', $findings['Activity_1rxwbas']['suggested_key']);
    $this->assertSame('status_open', $findings['Activity_1w5ikso']['suggested_key']);
    foreach ($findings as $finding) {
      $this->assertSame('update', $finding['trigger_event']);
      $this->assertSame('RESOLVED', $finding['status']);
    }
  }

  /**
   * Tests the subject-line fallback when the term name is inconclusive.
   *
   * Dorsten tid=37 "Kein Befall" ("no infestation found") does not
   * contain any status keyword itself, but the subject line "... -
   * Prüfung abgeschlossen" does ("abgeschlossen" = closed).
   */
  public function testAnalyzeFallsBackToSubjectWhenTermNameIsInconclusive(): void {
    $raw = $this->loadFixture('dorsten_process_tunr6d6');
    $migrator = $this->buildMigrator(
      ['eca.eca.process_tunr6d6' => $raw],
      termNamesByTid: [15 => 'Erledigt', 30 => 'Nicht zuständig', 37 => 'Kein Befall'],
    );

    $findings = $this->indexByActivity($migrator->analyze());

    $this->assertSame('status_closed', $findings['Activity_1w5ikso']['suggested_key']);
    $this->assertStringContainsString('Kein Befall', $findings['Activity_1w5ikso']['branch_condition']);
  }

  /**
   * Tests that an unresolvable branch is reported, never guessed.
   *
   * Dorsten tid=14 ("In Bearbeitung" branch, Activity_inbearbeitung): term
   * not found in this test's fixture taxonomy, and neither the term name
   * nor the subject contain a recognized keyword substring
   * ("aktualisiert"/"bearbeitet" are not "update"/"bearbeitung"). This
   * must come back UNRESOLVED, never a guessed key.
   */
  public function testAnalyzeReturnsUnresolvedWhenNeitherTermNorSubjectMatch(): void {
    $raw = $this->loadFixture('dorsten_process_tunr6d6');
    $migrator = $this->buildMigrator(
      ['eca.eca.process_tunr6d6' => $raw],
      termNamesByTid: [15 => 'Erledigt', 30 => 'Nicht zuständig', 37 => 'Kein Befall'],
    );

    $findings = $this->indexByActivity($migrator->analyze());

    $this->assertNull($findings['Activity_inbearbeitung']['suggested_key']);
    $this->assertSame('UNRESOLVED', $findings['Activity_inbearbeitung']['status']);
    $this->assertStringContainsString('not found', $findings['Activity_inbearbeitung']['reason']);
  }

  /**
   *
   */
  public function testAnalyzeMarksAlreadyMigratedActionsIdempotently(): void {
    $raw = [
      'status' => TRUE,
      'id' => 'process_already_migrated',
      'label' => 'Already Migrated',
      'events' => [
        'Event_1' => [
          'plugin' => 'content_entity:update',
          'successors' => [['id' => 'Activity_1', 'condition' => '']],
        ],
      ],
      'conditions' => [],
      'gateways' => [],
      'actions' => [
        'Activity_1' => [
          'label' => 'Send notification',
          'plugin' => 'markaspot_mail_send_notification',
          'configuration' => ['notification_key' => 'status_open', 'recipient' => '[node:field_e_mail:value]'],
          'successors' => [],
        ],
      ],
    ];
    $migrator = $this->buildMigrator(['eca.eca.process_already_migrated' => $raw]);

    $findings = $migrator->analyze();

    $this->assertCount(1, $findings);
    $this->assertSame('ALREADY_MIGRATED', $findings[0]['status']);
    $this->assertSame('status_open', $findings[0]['suggested_key']);
  }

  /**
   * Tests that a shared-key conflict between insert branches is flagged.
   *
   * Shipped process_ugsohtl branches on field_category (not field_status)
   * while still being insert-triggered, so heuristic (a) suggests
   * report_confirmation for BOTH the "Welcome Open" and "Welcome Closed"
   * actions even though their wording genuinely differs. analyze() must
   * flag this so an operator reviews it before --apply silently drops one
   * variant's text.
   */
  public function testAnalyzeFlagsSharedKeyConflictBetweenDifferentlyWordedInsertBranches(): void {
    // The shipped default ships this workflow disabled (status: false) —
    // its category-branching STRUCTURE is what this test targets, and a
    // tenant enabling it hits the exact same shared-key scenario, so the
    // fixture's shipped on/off switch is overridden here rather than
    // skipping analyze()'s (correct) "only active configs" scope.
    $raw = $this->loadFixture('shipped_process_ugsohtl');
    $raw['status'] = TRUE;
    $migrator = $this->buildMigrator(['eca.eca.process_ugsohtl' => $raw]);

    $findings = $this->indexByActivity($migrator->analyze());

    $this->assertTrue($findings['Activity_14s87co']['shared_key_conflict']);
    $this->assertTrue($findings['Activity_1regpyc']['shared_key_conflict']);
    $this->assertStringContainsString('Shares notification_key', $findings['Activity_14s87co']['reason']);
  }

  /**
   *
   */
  public function testApplyRewritesActionAndWritesTextsAndBackup(): void {
    $raw = $this->loadFixture('shipped_process_confirm_report');
    $capturedEca = NULL;
    $capturedTexts = [];
    $migrator = $this->buildMigrator(
      ['eca.eca.process_confirm_report' => $raw],
      editableCapture: [
        'eca.eca.process_confirm_report' => static function (array $data) use (&$capturedEca): void {
          $capturedEca = $data;
        },
      ],
      textsCapture: static function (string $key, array $value) use (&$capturedTexts): void {
        $capturedTexts[$key] = $value;
      },
    );

    $findings = $migrator->analyze();
    $result = $migrator->apply($findings, []);

    $this->assertSame('migrated', $result[0]['status']);
    $this->assertNotNull($result[0]['backup_path']);

    $this->assertSame('markaspot_mail_send_notification', $capturedEca['actions']['Activity_send_confirmation']['plugin']);
    $this->assertSame(
      ['notification_key' => 'report_confirmation', 'recipient' => '[node:field_e_mail:value]'],
      $capturedEca['actions']['Activity_send_confirmation']['configuration'],
    );
    // Untouched model parts survive byte-identical.
    $this->assertSame($raw['conditions'], $capturedEca['conditions']);
    $this->assertSame($raw['events'], $capturedEca['events']);

    $this->assertSame('Your Report #[node:request_id] has been received', $capturedTexts['report_confirmation']['subject']);
    $this->assertSame('View your report', $capturedTexts['report_confirmation']['cta_label']);
    $this->assertSame('', $capturedTexts['report_confirmation']['headline']);
    $this->assertSame('', $capturedTexts['report_confirmation']['preheader']);
  }

  /**
   *
   */
  public function testApplySkipsUnresolvedActionsWithoutMapOverride(): void {
    $raw = $this->loadFixture('dorsten_process_tunr6d6');
    $capturedEca = NULL;
    $migrator = $this->buildMigrator(
      ['eca.eca.process_tunr6d6' => $raw],
      termNamesByTid: [15 => 'Erledigt', 30 => 'Nicht zuständig', 37 => 'Kein Befall'],
      editableCapture: [
        'eca.eca.process_tunr6d6' => static function (array $data) use (&$capturedEca): void {
          $capturedEca = $data;
        },
      ],
    );

    $findings = $migrator->analyze();
    $result = $this->indexByActivity($migrator->apply($findings, []));

    $this->assertSame('skipped_unresolved', $result['Activity_inbearbeitung']['status']);
    // The untouched action must still be action_send_email_action, never
    // silently migrated with a guessed key.
    $this->assertSame('action_send_email_action', $capturedEca['actions']['Activity_inbearbeitung']['plugin']);
  }

  /**
   *
   */
  public function testApplyHonorsExplicitMapOverrideForUnresolvedAction(): void {
    $raw = $this->loadFixture('dorsten_process_tunr6d6');
    $capturedEca = NULL;
    $migrator = $this->buildMigrator(
      ['eca.eca.process_tunr6d6' => $raw],
      termNamesByTid: [15 => 'Erledigt', 30 => 'Nicht zuständig', 37 => 'Kein Befall'],
      editableCapture: [
        'eca.eca.process_tunr6d6' => static function (array $data) use (&$capturedEca): void {
          $capturedEca = $data;
        },
      ],
    );

    $findings = $migrator->analyze();
    $result = $this->indexByActivity($migrator->apply($findings, ['Activity_inbearbeitung' => 'status_open']));

    $this->assertSame('migrated', $result['Activity_inbearbeitung']['status']);
    $this->assertSame('status_open', $result['Activity_inbearbeitung']['notification_key']);
    $this->assertSame('markaspot_mail_send_notification', $capturedEca['actions']['Activity_inbearbeitung']['plugin']);
  }

  /**
   *
   */
  public function testApplyKeepsFirstVariantTextWhenTwoActionsShareOneKey(): void {
    // See testAnalyzeFlagsSharedKeyConflictBetweenDifferentlyWordedInsertBranches()
    // for why status is force-enabled on this shipped-but-disabled fixture.
    $raw = $this->loadFixture('shipped_process_ugsohtl');
    $raw['status'] = TRUE;
    $capturedTexts = [];
    $noop = static function (): void {
    };
    $migrator = $this->buildMigrator(
      ['eca.eca.process_ugsohtl' => $raw],
      editableCapture: ['eca.eca.process_ugsohtl' => $noop],
      textsCapture: static function (string $key, array $value) use (&$capturedTexts): void {
        $capturedTexts[$key] = $value;
      },
    );

    $findings = $migrator->analyze();
    $result = $this->indexByActivity($migrator->apply($findings, []));

    // All three of this fixture's email actions (Welcome Open x2 variants
    // plus Welcome Closed) are rewired to the same key.
    $this->assertSame('report_confirmation', $result['Activity_14s87co']['notification_key']);
    $this->assertSame('report_confirmation', $result['Activity_1regpyc']['notification_key']);
    $this->assertSame('report_confirmation', $result['Activity_15z9pjj']['notification_key']);
    // Only one of them actually won the text.
    $winners = array_filter($result, static fn (array $r): bool => $r['text_applied'] === TRUE);
    $this->assertCount(1, $winners);
    $losers = array_filter($result, static fn (array $r): bool => $r['text_applied'] === FALSE && $r['status'] === 'migrated');
    $this->assertCount(2, $losers);
    foreach ($losers as $loser) {
      $this->assertNotNull($loser['discarded_variant_of']);
    }
    // The texts config was written exactly once for this key.
    $this->assertArrayHasKey('report_confirmation', $capturedTexts);
  }

  /**
   *
   */
  public function testApplyIsIdempotentForAlreadyMigratedActions(): void {
    $raw = [
      'status' => TRUE,
      'id' => 'process_already_migrated',
      'label' => 'Already Migrated',
      'events' => [
        'Event_1' => [
          'plugin' => 'content_entity:update',
          'successors' => [['id' => 'Activity_1', 'condition' => '']],
        ],
      ],
      'conditions' => [],
      'gateways' => [],
      'actions' => [
        'Activity_1' => [
          'label' => 'Send notification',
          'plugin' => 'markaspot_mail_send_notification',
          'configuration' => ['notification_key' => 'status_open', 'recipient' => '[node:field_e_mail:value]'],
          'successors' => [],
        ],
      ],
    ];
    $saveCalled = FALSE;
    $migrator = $this->buildMigrator(
      ['eca.eca.process_already_migrated' => $raw],
      editableCapture: [
        'eca.eca.process_already_migrated' => static function () use (&$saveCalled): void {
          $saveCalled = TRUE;
        },
      ],
    );

    $findings = $migrator->analyze();
    $result = $migrator->apply($findings, []);

    $this->assertSame('already_migrated', $result[0]['status']);
    $this->assertFalse($saveCalled, 'apply() must not rewrite a config that needed no changes.');
  }

  /**
   * Loads and decodes a fixture YAML file.
   *
   * @return array<string, mixed>
   *   The decoded fixture.
   */
  private function loadFixture(string $name): array {
    $path = __DIR__ . '/../../../fixtures/eca/' . $name . '.yml';
    $decoded = Yaml::decode((string) file_get_contents($path));
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Re-indexes a list of findings by activity_id for direct assertions.
   *
   * @param list<array<string, mixed>> $findings
   *   Findings as returned by EcaMailMigrator::analyze().
   *
   * @return array<string, array<string, mixed>>
   *   The same findings, keyed by their activity_id.
   */
  private function indexByActivity(array $findings): array {
    $indexed = [];
    foreach ($findings as $finding) {
      $indexed[$finding['activity_id']] = $finding;
    }
    return $indexed;
  }

  /**
   * Builds an EcaMailMigrator with mocked dependencies.
   *
   * @param array<string, array<string, mixed>> $rawByName
   *   Config name => decoded eca.eca.* raw data, backing both get() and
   *   getEditable().
   * @param array<int, string|null> $termNamesByTid
   *   Taxonomy_term tid => label, or absent/NULL for "term does not
   *   exist".
   * @param array<string, callable(array<string, mixed>): void> $editableCapture
   *   Config name => callback invoked with the array passed to
   *   Config::setData(), for apply() assertions.
   * @param null|callable(string, array<string, mixed>): void $textsCapture
   *   Invoked once per Config::set() call on the markaspot_mail.texts
   *   editable config, for apply() assertions.
   */
  private function buildMigrator(
    array $rawByName = [],
    array $termNamesByTid = [],
    array $editableCapture = [],
    ?callable $textsCapture = NULL,
  ): EcaMailMigrator {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('listAll')->willReturnCallback(
      static fn (string $prefix): array => array_values(array_filter(array_keys($rawByName), static fn (string $name): bool => str_starts_with($name, $prefix))),
    );
    $configFactory->method('get')->willReturnCallback(function (string $name) use ($rawByName): ImmutableConfig {
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('getRawData')->willReturn($rawByName[$name] ?? []);
      return $config;
    });
    $configFactory->method('getEditable')->willReturnCallback(function (string $name) use ($rawByName, $editableCapture, $textsCapture): Config {
      $config = $this->createMock(Config::class);
      $config->method('getRawData')->willReturn($rawByName[$name] ?? []);
      $config->method('setData')->willReturnCallback(function (array $data) use ($config, $name, $editableCapture): Config {
        if (isset($editableCapture[$name])) {
          $editableCapture[$name]($data);
        }
        return $config;
      });
      if ($textsCapture !== NULL) {
        $config->method('set')->willReturnCallback(function (string $key, $value) use ($config, $textsCapture): Config {
          $textsCapture($key, $value);
          return $config;
        });
      }
      $config->method('save')->willReturnSelf();
      return $config;
    });

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    if ($termNamesByTid !== []) {
      $storage = $this->createMock(EntityStorageInterface::class);
      $storage->method('load')->willReturnCallback(function ($tid) use ($termNamesByTid) {
        $name = $termNamesByTid[(int) $tid] ?? NULL;
        if ($name === NULL) {
          return NULL;
        }
        $term = $this->createMock(TermInterface::class);
        $term->method('label')->willReturn($name);
        return $term;
      });
      $entityTypeManager->method('getStorage')->with('taxonomy_term')->willReturn($storage);
    }

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('prepareDirectory')->willReturn(TRUE);
    $fileSystem->method('saveData')->willReturnCallback(
      static fn (string $data, string $uri): string => $uri,
    );

    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1700000000);

    $logger = $this->createMock(LoggerInterface::class);

    return new EcaMailMigrator($configFactory, $entityTypeManager, $fileSystem, $time, $logger);
  }

}
