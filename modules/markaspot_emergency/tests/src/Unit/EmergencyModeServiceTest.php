<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_emergency\Unit;

use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests jurisdiction state and category-mode compatibility behavior.
 *
 * @group markaspot_emergency
 * @coversDefaultClass \Drupal\markaspot_emergency\Service\EmergencyModeService
 */
class EmergencyModeServiceTest extends UnitTestCase {

  /**
   * @covers ::getModeState
   * @covers ::getStatus
   * @covers ::isActive
   */
  public function testModeStateDefaultsToOffForOnlyRoot(): void {
    $harness = $this->buildHarness();

    $this->assertSame([
      'jurisdiction_id' => 7,
      'status' => 'off',
      'activated_at' => NULL,
      'changed_at' => 0,
      'activated_by' => NULL,
      'mode_type' => 'disaster',
      'force_redirect' => TRUE,
      'lite_ui' => TRUE,
      'revision' => 0,
      'snapshot' => [],
      'translation_snapshot' => [],
      'translation_snapshot_captured' => FALSE,
    ], $harness->service->getModeState());
    $this->assertFalse($harness->service->isActive());
  }

  /**
   * @covers ::getModeState
   * @covers ::getActivatedAt
   * @covers ::getActivatedBy
   * @covers ::getModeType
   * @covers ::getRevision
   */
  public function testReadsCompletePerRootState(): void {
    $record = [
      'status' => 'active',
      'activated_at' => 1_700_000_000,
      'activated_by' => 42,
      'mode_type' => 'crisis',
      'force_redirect' => FALSE,
      'lite_ui' => TRUE,
      'revision' => 9,
      'snapshot' => [3, '4', 4],
    ];
    $harness = $this->buildHarness(stateValues: [
      EmergencyModeService::STATE_PREFIX . '7' => $record,
    ]);

    $this->assertTrue($harness->service->isActive(7));
    $this->assertSame(1_700_000_000, $harness->service->getActivatedAt(7));
    $this->assertSame(42, $harness->service->getActivatedBy(7));
    $this->assertSame('crisis', $harness->service->getModeType(7));
    $this->assertSame(9, $harness->service->getRevision(7));
    $this->assertSame([3, 4], $harness->service->getModeState(7)['snapshot']);
  }

  /**
   * Active legacy State remains visible during the deploy-before-updb window.
   *
   * @covers ::getModeState
   */
  public function testLegacyGlobalStateMapsToTheOnlyRealRoot(): void {
    $harness = $this->buildHarness(stateValues: [
      EmergencyModeService::STATE_STATUS => 'active',
      EmergencyModeService::STATE_ACTIVATED_AT => 1_700_000_000,
      'markaspot_emergency.original_published_tids' => [5, '4', 5],
    ]);

    $state = $harness->service->getModeState(7);

    $this->assertSame('active', $state['status']);
    $this->assertSame([4, 5], $state['snapshot']);
    $this->assertFalse($state['translation_snapshot_captured']);
  }

  /**
   * Conflicting legacy snapshot keys never get guessed during deployment.
   *
   * @covers ::getModeState
   */
  public function testLegacyRuntimeRejectsConflictingSnapshots(): void {
    $harness = $this->buildHarness(stateValues: [
      EmergencyModeService::STATE_STATUS => 'active',
      'markaspot_emergency.original_published_tids' => [4],
      'markaspot_emergency.original_published_tids.7' => [5],
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('snapshots disagree');
    $harness->service->getModeState(7);
  }

  /**
   * Tests a restrictive incident profile through its full lifecycle.
   *
   * @covers ::activate
   * @covers ::deactivate
   * @covers ::getActiveJurisdictionIds
   * @covers ::getAvailableCategoryTerms
   */
  public function testDisasterToCrisisSwitchRestoresExactInitialSnapshot(): void {
    $harness = $this->buildHarness(terms: $this->transitionTerms());

    $disaster = $harness->service->activate(
      'disaster',
      FALSE,
      TRUE,
      TRUE,
      FALSE,
      7,
    );

    $this->assertSame('active', $disaster['status']);
    $this->assertSame('disaster', $disaster['mode_type']);
    $this->assertFalse($disaster['force_redirect']);
    $this->assertTrue($disaster['lite_ui']);
    $this->assertSame(1, $disaster['revision']);
    $this->assertSame([10], $disaster['snapshot']);
    $this->assertSame([11], $this->publishedTermIds($harness));
    $this->assertSame([7], $harness->stateValues[EmergencyModeService::STATE_ACTIVE_INDEX]);
    $this->assertSame([11], array_map(
      static fn(TermInterface $term): int => (int) $term->id(),
      $harness->service->getAvailableCategoryTerms(7),
    ));

    $crisis = $harness->service->activate(
      'crisis',
      FALSE,
      FALSE,
      TRUE,
      FALSE,
      7,
    );

    $this->assertSame($disaster['activated_at'], $crisis['activated_at']);
    $this->assertGreaterThan($disaster['changed_at'], $crisis['changed_at']);
    $this->assertSame('crisis', $crisis['mode_type']);
    $this->assertFalse($crisis['force_redirect']);
    $this->assertFalse($crisis['lite_ui']);
    $this->assertSame(2, $crisis['revision']);
    $this->assertSame([10], $crisis['snapshot']);
    $this->assertSame([12], $this->publishedTermIds($harness));
    $this->assertSame([7], $harness->service->getActiveJurisdictionIds());

    $off = $harness->service->deactivate(7);
    $this->assertGreaterThan($crisis['changed_at'], $off['changed_at']);

    $this->assertSame('off', $off['status']);
    $this->assertSame('crisis', $off['mode_type']);
    $this->assertSame(3, $off['revision']);
    $this->assertSame([], $off['snapshot']);
    $this->assertSame([10], $this->publishedTermIds($harness));
    $this->assertSame([], $harness->stateValues[EmergencyModeService::STATE_ACTIVE_INDEX]);
    $this->assertSame([], $harness->service->getActiveJurisdictionIds());
  }

  /**
   * Emergency publication covers every translation and restores each exactly.
   *
   * @covers ::activate
   * @covers ::deactivate
   */
  public function testTranslatedPublicationStateIsRestoredExactly(): void {
    $harness = $this->buildHarness(terms: [
      10 => [
        'name' => 'Ordinary category',
        'status' => TRUE,
        'translations' => ['en' => TRUE, 'de' => FALSE],
      ],
      11 => [
        'name' => 'Disaster category',
        'status' => FALSE,
        'modes' => ['disaster'],
        'translations' => ['en' => FALSE, 'de' => FALSE],
      ],
    ]);

    $active = $harness->service->activate(
      'disaster',
      TRUE,
      TRUE,
      TRUE,
      FALSE,
      7,
    );

    $this->assertSame([
      10 => ['en' => TRUE, 'de' => FALSE],
      11 => ['en' => FALSE, 'de' => FALSE],
    ], $active['translation_snapshot']);
    $this->assertTrue($active['translation_snapshot_captured']);
    $this->assertSame(['en' => FALSE, 'de' => FALSE], $harness->terms[10]['translations']);
    $this->assertSame(['en' => TRUE, 'de' => TRUE], $harness->terms[11]['translations']);

    $off = $harness->service->deactivate(7);

    $this->assertSame(['en' => TRUE, 'de' => FALSE], $harness->terms[10]['translations']);
    $this->assertSame(['en' => FALSE, 'de' => FALSE], $harness->terms[11]['translations']);
    $this->assertSame([], $off['translation_snapshot']);
    $this->assertFalse($off['translation_snapshot_captured']);
  }

  /**
   * Legacy snapshots restore only the default translation they had changed.
   *
   * @covers ::deactivate
   */
  public function testLegacyDeactivationPreservesNonDefaultTranslations(): void {
    $harness = $this->buildHarness(
      terms: [
        10 => [
          'status' => FALSE,
          'translations' => ['en' => FALSE, 'de' => TRUE],
        ],
        11 => [
          'status' => TRUE,
          'translations' => ['en' => TRUE, 'de' => FALSE],
        ],
      ],
      stateValues: [
        EmergencyModeService::STATE_PREFIX . '7' => [
          'status' => 'active',
          'activated_at' => 1_700_000_000,
          'activated_by' => 99,
          'mode_type' => 'disaster',
          'force_redirect' => TRUE,
          'lite_ui' => TRUE,
          'revision' => 1,
          'snapshot' => [10],
        ],
        EmergencyModeService::STATE_ACTIVE_INDEX => [7],
      ],
    );

    $harness->service->deactivate(7);

    $this->assertSame(['en' => TRUE, 'de' => TRUE], $harness->terms[10]['translations']);
    $this->assertSame(['en' => FALSE, 'de' => FALSE], $harness->terms[11]['translations']);
  }

  /**
   * A post-update profile switch keeps legacy non-default translations intact.
   *
   * @covers ::activate
   * @covers ::deactivate
   */
  public function testLegacyProfileSwitchStaysDefaultTranslationOnly(): void {
    $stateKey = EmergencyModeService::STATE_PREFIX . '7';
    $harness = $this->buildHarness(
      terms: [
        10 => [
          'status' => FALSE,
          'translations' => ['en' => FALSE, 'de' => TRUE],
        ],
        11 => [
          'status' => TRUE,
          'modes' => ['disaster'],
          'translations' => ['en' => TRUE, 'de' => FALSE],
        ],
        12 => [
          'status' => FALSE,
          'modes' => ['crisis'],
          'translations' => ['en' => FALSE, 'de' => TRUE],
        ],
      ],
      stateValues: [
        $stateKey => [
          'status' => 'active',
          'activated_at' => 1_700_000_000,
          'activated_by' => 99,
          'mode_type' => 'disaster',
          'force_redirect' => TRUE,
          'lite_ui' => TRUE,
          'revision' => 1,
          'snapshot' => [10],
        ],
        EmergencyModeService::STATE_ACTIVE_INDEX => [7],
      ],
    );

    $switched = $harness->service->activate(
      'crisis',
      TRUE,
      TRUE,
      TRUE,
      FALSE,
      7,
    );
    $this->assertFalse($switched['translation_snapshot_captured']);
    $this->assertSame(['en' => FALSE, 'de' => TRUE], $harness->terms[10]['translations']);
    $this->assertSame(['en' => FALSE, 'de' => FALSE], $harness->terms[11]['translations']);
    $this->assertSame(['en' => TRUE, 'de' => TRUE], $harness->terms[12]['translations']);

    $harness->service->deactivate(7);

    $this->assertSame(['en' => TRUE, 'de' => TRUE], $harness->terms[10]['translations']);
    $this->assertSame(['en' => FALSE, 'de' => FALSE], $harness->terms[11]['translations']);
    $this->assertSame(['en' => FALSE, 'de' => TRUE], $harness->terms[12]['translations']);
  }

  /**
   * Tests that non-restrictive switching does not leak the previous profile.
   *
   * @covers ::activate
   * @covers ::deactivate
   */
  public function testNonRestrictiveSwitchKeepsSnapshotAndRemovesOldMode(): void {
    $harness = $this->buildHarness(terms: $this->transitionTerms());

    $first = $harness->service->activate(
      'disaster',
      TRUE,
      TRUE,
      FALSE,
      FALSE,
      7,
    );
    $this->assertSame(1, $first['revision']);
    $this->assertSame([10], $first['snapshot']);
    $this->assertSame([10, 11], $this->publishedTermIds($harness));

    $switched = $harness->service->activate(
      'crisis',
      TRUE,
      TRUE,
      FALSE,
      FALSE,
      7,
    );
    $this->assertSame(2, $switched['revision']);
    $this->assertSame([10], $switched['snapshot']);
    $this->assertSame([10, 12], $this->publishedTermIds($harness));

    $off = $harness->service->deactivate(7);
    $this->assertSame(3, $off['revision']);
    $this->assertSame([10], $this->publishedTermIds($harness));
  }

  /**
   * @covers ::activate
   */
  public function testFailedTransitionRollsBackAndReleasesBothLocks(): void {
    $harness = $this->buildHarness(terms: $this->transitionTerms());
    $harness->throwOnSaveTermId = 10;

    try {
      $harness->service->activate(
        'disaster',
        TRUE,
        TRUE,
        TRUE,
        FALSE,
        7,
      );
      $this->fail('The simulated term-save failure must escape activate().');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Simulated taxonomy save failure.', $exception->getMessage());
    }

    $this->assertCount(1, $harness->transactions);
    $this->assertSame(1, $harness->transactions[0]->rollbacks);
    $this->assertSame([
      EmergencyModeService::STATE_ACTIVE_INDEX . '.transition',
      EmergencyModeService::STATE_PREFIX . '7',
    ], $harness->locksAcquired);
    $this->assertSame([
      EmergencyModeService::STATE_PREFIX . '7',
      EmergencyModeService::STATE_ACTIVE_INDEX . '.transition',
    ], $harness->locksReleased);
    $this->assertArrayNotHasKey(
      EmergencyModeService::STATE_PREFIX . '7',
      $harness->stateValues,
    );
    $this->assertSame([], $harness->stateValues[EmergencyModeService::STATE_ACTIVE_INDEX]);
    $this->assertSame(
      [EmergencyModeService::STATE_PREFIX . '7'],
      $harness->stateDeletes,
    );
  }

  /**
   * @covers ::activate
   */
  public function testGlobalIndexLockFailureStopsBeforeRootTransition(): void {
    $indexLock = EmergencyModeService::STATE_ACTIVE_INDEX . '.transition';
    $harness = $this->buildHarness(
      terms: $this->transitionTerms(),
      failedLocks: [$indexLock],
    );

    try {
      $harness->service->activate(
        'disaster',
        TRUE,
        TRUE,
        TRUE,
        FALSE,
        7,
      );
      $this->fail('A refused global index lock must abort the transition.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('already in progress', $exception->getMessage());
    }

    $this->assertSame([$indexLock], $harness->locksAcquired);
    $this->assertSame([], $harness->locksReleased);
    $this->assertSame([], $harness->transactions);
    $this->assertSame([10], $this->publishedTermIds($harness));
  }

  /**
   * @covers ::activate
   */
  public function testRootLockFailureReleasesGlobalIndexLock(): void {
    $rootLock = EmergencyModeService::STATE_PREFIX . '7';
    $indexLock = EmergencyModeService::STATE_ACTIVE_INDEX . '.transition';
    $harness = $this->buildHarness(
      terms: $this->transitionTerms(),
      failedLocks: [$rootLock],
    );

    try {
      $harness->service->activate(
        'disaster',
        TRUE,
        TRUE,
        TRUE,
        FALSE,
        7,
      );
      $this->fail('A refused root lock must abort the transition.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('already in progress', $exception->getMessage());
    }

    $this->assertSame([$indexLock, $rootLock], $harness->locksAcquired);
    $this->assertSame([$indexLock], $harness->locksReleased);
    $this->assertSame([], $harness->transactions);
  }

  /**
   * @covers ::resolveJurisdictionContext
   * @covers ::resolveRootJurisdictionId
   * @covers ::activate
   */
  public function testLeafResolvesToRootAndUsesTheWholeRootTermTree(): void {
    $terms = [
      21 => [
        'name' => 'Root category',
        'status' => FALSE,
        'jurisdiction' => 7,
        'modes' => ['disaster'],
      ],
      22 => [
        'name' => 'Leaf category',
        'status' => FALSE,
        'jurisdiction' => 8,
        'modes' => ['disaster'],
      ],
      23 => [
        'name' => 'Other tenant category',
        'status' => TRUE,
        'jurisdiction' => 9,
        'modes' => ['disaster'],
      ],
    ];
    $harness = $this->buildHarness(
      terms: $terms,
      rootTrees: [7 => [7, 8], 9 => [9]],
    );

    $this->assertSame([
      'requested_id' => 8,
      'root_id' => 7,
    ], $harness->service->resolveJurisdictionContext(8, FALSE));
    $this->assertSame(7, $harness->service->resolveRootJurisdictionId('leaf-8'));

    $state = $harness->service->activate(
      'disaster',
      TRUE,
      TRUE,
      TRUE,
      FALSE,
      8,
    );

    $this->assertSame(7, $state['jurisdiction_id']);
    $this->assertSame([21, 22, 23], $this->publishedTermIds($harness));
    $this->assertArrayHasKey(
      EmergencyModeService::STATE_PREFIX . '7',
      $harness->stateValues,
    );
    $this->assertArrayNotHasKey(
      EmergencyModeService::STATE_PREFIX . '8',
      $harness->stateValues,
    );
  }

  /**
   * @covers ::resolveRootJurisdictionId
   * @covers ::activate
   * @covers ::deactivate
   */
  public function testScopeZeroWorksOnlyWithoutJurisdictionGroups(): void {
    $harness = $this->buildHarness(
      terms: [
        30 => [
          'name' => 'Ordinary',
          'status' => TRUE,
          'jurisdiction' => 0,
          'modes' => [],
        ],
        31 => [
          'name' => 'Disaster',
          'status' => FALSE,
          'jurisdiction' => 0,
          'modes' => ['disaster'],
        ],
      ],
      rootTrees: [],
      jurisdictionField: FALSE,
    );

    $this->assertSame(0, $harness->service->resolveRootJurisdictionId(NULL));
    $this->assertSame(0, $harness->service->resolveRootJurisdictionId(0, FALSE));

    $active = $harness->service->activate(
      'disaster',
      TRUE,
      TRUE,
      TRUE,
      FALSE,
      0,
    );
    $this->assertSame(0, $active['jurisdiction_id']);
    $this->assertSame([31], $this->publishedTermIds($harness));
    $this->assertSame([0], $harness->service->getActiveJurisdictionIds());

    $harness->service->deactivate(0);
    $this->assertSame([30], $this->publishedTermIds($harness));
  }

  /**
   * @covers ::getAvailableCategoryTerms
   * @covers ::hasJurisdictionField
   */
  public function testMissingJurisdictionFieldFailsClosedForTenantTerms(): void {
    $harness = $this->buildHarness(
      terms: $this->transitionTerms(),
      jurisdictionField: FALSE,
    );

    $this->assertFalse($harness->service->hasJurisdictionField());
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('field_jurisdiction is missing');
    $harness->service->getAvailableCategoryTerms(7);
  }

  /**
   * @covers ::resolveRootJurisdictionId
   */
  public function testUnscopedMultiTenantResolutionFailsClosed(): void {
    $harness = $this->buildHarness(rootTrees: [1 => [1], 5 => [5]]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('required for multi-tenant');
    $harness->service->resolveRootJurisdictionId(NULL);
  }

  /**
   * @covers ::resolveRootJurisdictionId
   */
  public function testRejectsScopeZeroWithRealRoot(): void {
    $harness = $this->buildHarness();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('only valid on installations without jurisdiction groups');
    $harness->service->resolveRootJurisdictionId(0, FALSE);
  }

  /**
   * @covers ::resolveRootJurisdictionId
   */
  public function testResolvesSlugThroughCanonicalJurisdictionLookup(): void {
    $harness = $this->buildHarness();

    $this->assertSame(7, $harness->service->resolveRootJurisdictionId('root-7'));
  }

  /**
   * A hidden root remains addressable when a published child represents it.
   *
   * @covers ::resolveRootJurisdictionId
   * @covers ::prepareSubmissionGuard
   * @covers ::acquireSubmissionGuard
   */
  public function testPublishedChildMakesUnpublishedRootAddressable(): void {
    $harness = $this->buildHarness(
      terms: [
        40 => [
          'status' => TRUE,
          'jurisdiction' => 7,
        ],
      ],
      rootTrees: [7 => [7, 8]],
      groupStatuses: [7 => FALSE, 8 => TRUE],
    );

    $this->assertSame(7, $harness->service->resolveRootJurisdictionId(7, FALSE));
    $context = $harness->service->prepareSubmissionGuard(
      7,
      0,
      'term-40',
      'group-8',
      'off',
    );
    $harness->service->acquireSubmissionGuard(
      $context['root_id'],
      $context['expected_revision'],
      $context['category_id'],
      $context['category_jurisdiction_ids'],
      $context['jurisdiction_id'],
      $context['expected_status'],
      $context['require_lite_ui'],
      $context['require_published_category'],
    );
    $harness->finishTransactions();

    $this->assertSame([EmergencyModeService::STATE_PREFIX . '7'], $harness->locksReleased);
  }

  /**
   * An unpublished root without a published descendant is not public scope.
   *
   * @covers ::resolveRootJurisdictionId
   */
  public function testRejectsUnpublishedRootWithoutPublishedDescendant(): void {
    $harness = $this->buildHarness(
      rootTrees: [7 => [7], 9 => [9]],
      groupStatuses: [7 => FALSE, 9 => TRUE],
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown or invalid jurisdiction');
    $harness->service->resolveRootJurisdictionId(7, FALSE);
  }

  /**
   * A hidden child cannot masquerade as an addressable hidden root.
   *
   * @covers ::resolveRootJurisdictionId
   */
  public function testRejectsUnpublishedChildIdentifier(): void {
    $harness = $this->buildHarness(
      rootTrees: [7 => [7, 8]],
      groupStatuses: [7 => TRUE, 8 => FALSE],
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown or invalid jurisdiction');
    $harness->service->resolveRootJurisdictionId(8, FALSE);
  }

  /**
   * @covers ::activate
   */
  public function testLegacyPresetIsAdoptedAndEnrichedWithoutDuplicate(): void {
    $terms = [
      40 => [
        'name' => 'Injured or Trapped Persons',
        'status' => FALSE,
        'jurisdiction' => 7,
        'modes' => [],
        'legacy' => TRUE,
        'service_code' => '',
      ],
    ];
    $harness = $this->buildHarness(
      terms: $terms,
      configValues: [
        'categories.emergency_presets' => [[
          'name' => 'Injured or Trapped Persons',
          'service_code' => 'EMG-INJURED',
          'weight' => -100,
          'modes' => ['disaster', 'crisis'],
        ]],
      ],
    );

    $state = $harness->service->activate(
      'disaster',
      TRUE,
      TRUE,
      TRUE,
      TRUE,
      7,
    );

    $this->assertSame(0, $harness->createdTerms);
    $this->assertCount(1, $harness->terms);
    $this->assertSame('EMG-INJURED', $harness->terms[40]['service_code']);
    $this->assertSame(['disaster', 'crisis'], $harness->terms[40]['modes']);
    $this->assertSame(0, $harness->terms[40]['weight']);
    $this->assertTrue($harness->terms[40]['status']);
    $this->assertSame('disaster', $state['mode_type']);
    $this->assertGreaterThanOrEqual(2, $harness->terms[40]['saves']);
  }

  /**
   * Stable-code presets keep taxonomy edits made after their initial seed.
   *
   * @covers ::activate
   */
  public function testExistingPresetKeepsOperatorManagedTaxonomyValues(): void {
    $harness = $this->buildHarness(
      terms: [
        40 => [
          'name' => 'Local emergency intake',
          'status' => FALSE,
          'jurisdiction' => 7,
          'modes' => ['crisis'],
          'legacy' => TRUE,
          'service_code' => 'EMG-INJURED',
          'weight' => 42,
        ],
      ],
      configValues: [
        'categories.emergency_presets' => [[
          'name' => 'Injured Persons',
          'service_code' => 'EMG-INJURED',
          'weight' => -100,
          'modes' => ['disaster'],
        ]],
      ],
    );

    $harness->service->activate('crisis', TRUE, TRUE, TRUE, TRUE, 7);

    $this->assertSame('Local emergency intake', $harness->terms[40]['name']);
    $this->assertSame(42, $harness->terms[40]['weight']);
    $this->assertSame(['crisis'], $harness->terms[40]['modes']);
    $this->assertTrue($harness->terms[40]['status']);
  }

  /**
   * Duplicate stable codes in one root fail before taxonomy is changed.
   *
   * @covers ::activate
   */
  public function testDuplicateScopedPresetCodeIsRejected(): void {
    $harness = $this->buildHarness(
      terms: [
        40 => [
          'name' => 'First intake',
          'jurisdiction' => 7,
          'service_code' => 'EMG-INJURED',
        ],
        41 => [
          'name' => 'Leaf intake',
          'jurisdiction' => 8,
          'service_code' => 'EMG-INJURED',
        ],
      ],
      rootTrees: [7 => [7, 8]],
      configValues: [
        'categories.emergency_presets' => [[
          'name' => 'Injured Persons',
          'service_code' => 'EMG-INJURED',
        ]],
      ],
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('assigned more than once');
    try {
      $harness->service->activate('disaster', TRUE, TRUE, TRUE, TRUE, 7);
    }
    finally {
      $this->assertSame(0, $harness->createdTerms);
      $this->assertSame(0, $harness->terms[40]['saves']);
      $this->assertSame(0, $harness->terms[41]['saves']);
    }
  }

  /**
   * Ambiguous name-based legacy presets are never adopted by reset() order.
   *
   * @covers ::activate
   */
  public function testAmbiguousLegacyPresetIsRejected(): void {
    $terms = [];
    foreach ([40, 41] as $id) {
      $terms[$id] = [
        'name' => 'Injured Persons',
        'jurisdiction' => 7,
        'legacy' => TRUE,
        'service_code' => '',
      ];
    }
    $harness = $this->buildHarness(
      terms: $terms,
      configValues: [
        'categories.emergency_presets' => [[
          'name' => 'Injured Persons',
          'service_code' => 'EMG-INJURED',
        ]],
      ],
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('is ambiguous');
    $harness->service->activate('disaster', TRUE, TRUE, TRUE, TRUE, 7);
  }

  /**
   * @covers ::prepareSubmissionGuard
   * @covers ::acquireSubmissionGuard
   */
  public function testSubmissionGuardReleasesOnlyAfterRootTransaction(): void {
    $stateKey = EmergencyModeService::STATE_PREFIX . '7';
    $harness = $this->buildHarness(
      terms: [
        40 => [
          'status' => TRUE,
          'jurisdiction' => 7,
          'modes' => ['crisis'],
        ],
      ],
      stateValues: [
        $stateKey => [
          'status' => 'active',
          'activated_at' => 1_700_000_000,
          'activated_by' => 99,
          'mode_type' => 'crisis',
          'force_redirect' => TRUE,
          'lite_ui' => TRUE,
          'revision' => 4,
          'snapshot' => [],
        ],
      ],
      rootTrees: [7 => [7, 9]],
      serviceDefinitionField: TRUE,
    );

    $context = $harness->service->prepareSubmissionGuard(7, 4, 'term-40', 'group-9');
    $this->assertSame([
      'root_id' => 7,
      'expected_revision' => 4,
      'expected_status' => 'active',
      'require_lite_ui' => TRUE,
      'require_published_category' => TRUE,
      'require_lite_compatible_category' => TRUE,
      'category_id' => 40,
      'category_jurisdiction_ids' => [7],
      'jurisdiction_id' => 9,
    ], $context);

    $harness->service->acquireSubmissionGuard(
      $context['root_id'],
      $context['expected_revision'],
      $context['category_id'],
      $context['category_jurisdiction_ids'],
      $context['jurisdiction_id'],
      $context['expected_status'],
      $context['require_lite_ui'],
      $context['require_published_category'],
      $context['require_lite_compatible_category'],
    );

    $this->assertContains($stateKey, $harness->locksAcquired);
    $this->assertNotContains($stateKey, $harness->locksReleased);
    $this->assertContains('key_value', $harness->lockingReads);
    $this->assertContains('taxonomy_term_field_data', $harness->lockingReads);
    $this->assertContains('taxonomy_term__field_service_definition', $harness->lockingReads);
    $this->assertContains('taxonomy_term__field_jurisdiction', $harness->lockingReads);
    $this->assertContains('groups_field_data', $harness->lockingReads);
    $this->assertContains('group__field_parent_jurisdiction', $harness->lockingReads);

    $harness->finishTransactions();

    $this->assertContains($stateKey, $harness->locksReleased);
  }

  /**
   * A revision-pinned Lite request must not select a dynamic required form.
   *
   * @covers ::prepareSubmissionGuard
   */
  public function testPinnedLiteSubmissionRejectsIncompatibleCategoryAtPreflight(): void {
    $stateKey = EmergencyModeService::STATE_PREFIX . '7';
    $harness = $this->buildHarness(
      terms: [
        40 => [
          'status' => TRUE,
          'jurisdiction' => 7,
          'service_definition' => json_encode([
            'attributes' => [[
              'code' => 'lamp_id',
              'variable' => TRUE,
              'required' => TRUE,
            ]],
          ], JSON_THROW_ON_ERROR),
        ],
      ],
      stateValues: [
        $stateKey => [
          'status' => 'active',
          'activated_at' => 1_700_000_000,
          'activated_by' => 99,
          'mode_type' => 'crisis',
          'force_redirect' => TRUE,
          'lite_ui' => TRUE,
          'revision' => 4,
          'snapshot' => [],
        ],
      ],
      serviceDefinitionField: TRUE,
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('requires fields unavailable in the Lite emergency form');
    $harness->service->prepareSubmissionGuard(7, 4, 'term-40', 'group-7');
  }

  /**
   * A category definition cannot change after Lite request preparation.
   *
   * @covers ::prepareSubmissionGuard
   * @covers ::acquireSubmissionGuard
   */
  public function testPinnedLiteSubmissionRechecksCategoryCompatibilityUnderLock(): void {
    $stateKey = EmergencyModeService::STATE_PREFIX . '7';
    $harness = $this->buildHarness(
      terms: [
        40 => [
          'status' => TRUE,
          'jurisdiction' => 7,
          'service_definition' => '{"attributes":[]}',
        ],
      ],
      stateValues: [
        $stateKey => [
          'status' => 'active',
          'activated_at' => 1_700_000_000,
          'activated_by' => 99,
          'mode_type' => 'crisis',
          'force_redirect' => TRUE,
          'lite_ui' => TRUE,
          'revision' => 4,
          'snapshot' => [],
        ],
      ],
      serviceDefinitionField: TRUE,
    );
    $context = $harness->service->prepareSubmissionGuard(7, 4, 'term-40', 'group-7');
    $harness->terms[40]['service_definition'] = json_encode([
      'attributes' => [[
        'code' => 'lamp_id',
        'variable' => TRUE,
        'required' => TRUE,
      ]],
    ], JSON_THROW_ON_ERROR);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('requires fields unavailable in the Lite emergency form');
    try {
      $harness->service->acquireSubmissionGuard(
        $context['root_id'],
        $context['expected_revision'],
        $context['category_id'],
        $context['category_jurisdiction_ids'],
        $context['jurisdiction_id'],
        $context['expected_status'],
        $context['require_lite_ui'],
        $context['require_published_category'],
        $context['require_lite_compatible_category'],
      );
    }
    finally {
      $this->assertContains('taxonomy_term__field_service_definition', $harness->lockingReads);
    }
  }

  /**
   * Normal offline replay is accepted only at its exact off-state revision.
   *
   * @covers ::prepareSubmissionGuard
   * @covers ::acquireSubmissionGuard
   */
  public function testSubmissionGuardPinsNormalOfflineReplayRevision(): void {
    $harness = $this->buildHarness(terms: [
      40 => [
        'status' => TRUE,
        'jurisdiction' => 7,
      ],
    ]);

    $context = $harness->service->prepareSubmissionGuard(
      7,
      0,
      'term-40',
      NULL,
      'off',
    );
    $harness->service->acquireSubmissionGuard(
      $context['root_id'],
      $context['expected_revision'],
      $context['category_id'],
      $context['category_jurisdiction_ids'],
      $context['jurisdiction_id'],
      $context['expected_status'],
      $context['require_lite_ui'],
      $context['require_published_category'],
    );
    $harness->finishTransactions();

    $harness->stateValues[EmergencyModeService::STATE_PREFIX . '7'] = [
      'status' => 'active',
      'activated_at' => 1_700_000_000,
      'changed_at' => 1_700_000_000,
      'mode_type' => 'disaster',
      'force_redirect' => FALSE,
      'lite_ui' => TRUE,
      'revision' => 1,
      'snapshot' => [40],
    ];

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('profile changed');
    $harness->service->acquireSubmissionGuard(7, 0, 40, [7], NULL, 'off');
  }

  /**
   * Headerless full-frontend creates derive their root from the category.
   *
   * @covers ::prepareSubmissionGuard
   * @covers ::acquireSubmissionGuard
   */
  public function testUnmarkedSubmissionGuardDerivesRootFromCategory(): void {
    $stateKey = EmergencyModeService::STATE_PREFIX . '7';
    $harness = $this->buildHarness(
      terms: [
        40 => [
          'status' => TRUE,
          'jurisdiction' => 7,
        ],
      ],
      rootTrees: [7 => [7, 9], 8 => [8]],
    );

    $context = $harness->service->prepareSubmissionGuard(
      NULL,
      NULL,
      'term-40',
      NULL,
    );
    $this->assertSame(7, $context['root_id']);
    $harness->service->acquireSubmissionGuard(
      $context['root_id'],
      $context['expected_revision'],
      $context['category_id'],
      $context['category_jurisdiction_ids'],
      $context['jurisdiction_id'],
      $context['expected_status'],
      $context['require_lite_ui'],
      $context['require_published_category'],
    );
    $this->assertContains($stateKey, $harness->locksAcquired);
    $this->assertNotContains($stateKey, $harness->locksReleased);
    $harness->finishTransactions();
    $this->assertContains($stateKey, $harness->locksReleased);
  }

  /**
   * Removing client headers cannot bypass an off-to-active transition.
   *
   * @covers ::prepareSubmissionGuard
   * @covers ::acquireSubmissionGuard
   */
  public function testHeaderlessSubmissionPinsServerSnapshotAcrossTransition(): void {
    $harness = $this->buildHarness(terms: [
      40 => [
        'status' => TRUE,
        'jurisdiction' => 7,
      ],
    ]);
    $context = $harness->service->prepareSubmissionGuard(
      NULL,
      NULL,
      'term-40',
      NULL,
    );
    $this->assertSame('off', $context['expected_status']);
    $this->assertSame(0, $context['expected_revision']);

    // The category intentionally remains published, modelling a transition
    // with unpublish_regular disabled.
    $harness->stateValues[EmergencyModeService::STATE_PREFIX . '7'] = [
      'status' => 'active',
      'lite_ui' => FALSE,
      'revision' => 1,
    ];

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('profile changed');
    $harness->service->acquireSubmissionGuard(
      $context['root_id'],
      $context['expected_revision'],
      $context['category_id'],
      $context['category_jurisdiction_ids'],
      $context['jurisdiction_id'],
      $context['expected_status'],
      $context['require_lite_ui'],
      $context['require_published_category'],
    );
  }

  /**
   * Brief request contention waits once instead of rejecting normal traffic.
   *
   * @covers ::prepareSubmissionGuard
   * @covers ::acquireSubmissionGuard
   */
  public function testSubmissionPersistenceRetriesTheShortRootGate(): void {
    $stateKey = EmergencyModeService::STATE_PREFIX . '7';
    $harness = $this->buildHarness(
      terms: [
        40 => [
          'status' => TRUE,
          'jurisdiction' => 7,
        ],
      ],
      lockAcquireFailures: [$stateKey => 1],
    );

    $context = $harness->service->prepareSubmissionGuard(
      NULL,
      NULL,
      'term-40',
      NULL,
    );
    $harness->service->acquireSubmissionGuard(
      $context['root_id'],
      $context['expected_revision'],
      $context['category_id'],
      $context['category_jurisdiction_ids'],
      $context['jurisdiction_id'],
      $context['expected_status'],
      $context['require_lite_ui'],
      $context['require_published_category'],
    );
    $this->assertSame([$stateKey, $stateKey], $harness->locksAcquired);
    $this->assertSame([$stateKey], $harness->locksWaited);
    $this->assertSame([], $harness->locksReleased);
    $harness->finishTransactions();
    $this->assertSame([$stateKey], $harness->locksReleased);
  }

  /**
   * Stable headerless management writes retain off-mode draft semantics.
   *
   * @covers ::prepareSubmissionGuard
   * @covers ::acquireSubmissionGuard
   */
  public function testHeaderlessManagementCreateMayUseDraftCategoryWhileModeIsOff(): void {
    $harness = $this->buildHarness(terms: [
      40 => [
        'status' => FALSE,
        'jurisdiction' => 7,
      ],
    ]);

    $context = $harness->service->prepareSubmissionGuard(
      NULL,
      NULL,
      'term-40',
      'group-7',
    );
    $harness->service->acquireSubmissionGuard(
      $context['root_id'],
      $context['expected_revision'],
      $context['category_id'],
      $context['category_jurisdiction_ids'],
      $context['jurisdiction_id'],
      $context['expected_status'],
      $context['require_lite_ui'],
      $context['require_published_category'],
    );
    $harness->finishTransactions();
    $this->assertSame([EmergencyModeService::STATE_PREFIX . '7'], $harness->locksReleased);
  }

  /**
   * Category and submitted jurisdiction must share a root even while off.
   *
   * @covers ::prepareSubmissionGuard
   */
  public function testSubmissionGuardRejectsCrossRootCategoryWhileModeIsOff(): void {
    $harness = $this->buildHarness(
      terms: [
        40 => [
          'status' => FALSE,
          'jurisdiction' => 7,
        ],
      ],
      rootTrees: [7 => [7, 9], 8 => [8, 10]],
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('different root jurisdictions');
    $harness->service->prepareSubmissionGuard(
      NULL,
      NULL,
      'term-40',
      'group-10',
    );
  }

  /**
   * Client headers cannot claim a child or foreign root.
   *
   * @covers ::prepareSubmissionGuard
   */
  public function testSubmissionGuardRejectsMismatchedClaimedRoot(): void {
    $harness = $this->buildHarness(
      terms: [
        40 => [
          'status' => TRUE,
          'jurisdiction' => 7,
        ],
      ],
      rootTrees: [7 => [7, 9]],
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('does not match');
    $harness->service->prepareSubmissionGuard(9, 4, 'term-40', 'group-9');
  }

  /**
   * @covers ::savePolicy
   * @covers ::getPolicy
   */
  public function testOperationalPolicyIsIsolatedPerRootJurisdiction(): void {
    $harness = $this->buildHarness(rootTrees: [7 => [7], 8 => [8]]);

    $saved = $harness->service->savePolicy(7, [
      'mode_type' => 'crisis',
      'force_redirect' => FALSE,
      'lite_ui' => TRUE,
      'unpublish_regular' => TRUE,
      'auto_deactivate' => ['enabled' => TRUE, 'duration' => 1],
      'allowed_urls' => ['/tenant-seven', '/'],
      'maintenance' => ['show_only_categories' => []],
      'banner' => [
        'enabled' => TRUE,
        'message' => 'Tenant seven alert',
      ],
    ]);

    $other = $harness->service->getPolicy(8);

    $this->assertSame(1, $saved['auto_deactivate']['duration']);
    $this->assertSame(['/tenant-seven'], $saved['allowed_urls']);
    $this->assertSame('Tenant seven alert', $saved['banner']['message']);
    $this->assertSame(72, $other['auto_deactivate']['duration']);
    $this->assertSame('', $other['banner']['message']);
    $this->assertArrayHasKey(EmergencyModeService::STATE_POLICY_PREFIX . '7', $harness->stateValues);
    $this->assertArrayNotHasKey(EmergencyModeService::STATE_POLICY_PREFIX . '8', $harness->stateValues);
  }

  /**
   * Presentation strings retain one value per administrative UI language.
   *
   * @covers ::savePolicy
   * @covers ::getPolicy
   */
  public function testOperationalPolicyPreservesTranslatedPresentationStrings(): void {
    $harness = $this->buildHarness();

    $harness->service->savePolicy(7, [
      'maintenance' => ['banner_text' => 'Maintenance in progress'],
      'banner' => [
        'message' => 'Emergency information',
        'title' => 'Important notice',
      ],
    ]);

    $harness->currentLangcode = 'de';
    $this->assertSame(
      'Emergency information',
      $harness->service->getPolicy(7)['banner']['message'],
    );

    $german = $harness->service->savePolicy(7, [
      'maintenance' => ['banner_text' => 'Wartungsarbeiten laufen'],
      'banner' => [
        'message' => 'Information zur Notlage',
        'title' => 'Wichtiger Hinweis',
      ],
    ]);

    $this->assertSame('Wartungsarbeiten laufen', $german['maintenance']['banner_text']);
    $this->assertSame('Information zur Notlage', $german['banner']['message']);
    $this->assertSame('Wichtiger Hinweis', $german['banner']['title']);
    $this->assertArrayNotHasKey('banner_text_translations', $german['maintenance']);
    $this->assertArrayNotHasKey('message_translations', $german['banner']);
    $this->assertArrayNotHasKey('title_translations', $german['banner']);

    $stored = $harness->stateValues[EmergencyModeService::STATE_POLICY_PREFIX . '7'];
    $this->assertSame([
      'de' => 'Wartungsarbeiten laufen',
      'en' => 'Maintenance in progress',
    ], $stored['maintenance']['banner_text_translations']);
    $this->assertSame([
      'de' => 'Information zur Notlage',
      'en' => 'Emergency information',
    ], $stored['banner']['message_translations']);
    $this->assertSame([
      'de' => 'Wichtiger Hinweis',
      'en' => 'Important notice',
    ], $stored['banner']['title_translations']);

    $harness->currentLangcode = 'en';
    $english = $harness->service->getPolicy(7);
    $this->assertSame('Maintenance in progress', $english['maintenance']['banner_text']);
    $this->assertSame('Emergency information', $english['banner']['message']);
    $this->assertSame('Important notice', $english['banner']['title']);

    $harness->currentLangcode = 'fr';
    $this->assertSame(
      'Emergency information',
      $harness->service->getPolicy(7)['banner']['message'],
    );
  }

  /**
   * Legacy single-string policy State remains visible in every locale.
   *
   * @covers ::getPolicy
   */
  public function testLegacyPolicyPresentationStringsUseLanguageNeutralFallback(): void {
    $harness = $this->buildHarness(stateValues: [
      EmergencyModeService::STATE_POLICY_PREFIX . '7' => [
        'maintenance' => ['banner_text' => 'Legacy maintenance'],
        'banner' => [
          'message' => 'Legacy message',
          'title' => 'Legacy title',
        ],
      ],
    ]);
    $harness->currentLangcode = 'nl';

    $policy = $harness->service->getPolicy(7);

    $this->assertSame('Legacy maintenance', $policy['maintenance']['banner_text']);
    $this->assertSame('Legacy message', $policy['banner']['message']);
    $this->assertSame('Legacy title', $policy['banner']['title']);
  }

  /**
   * @covers ::getTermModes
   */
  public function testPopulatedModeFieldIsAuthoritative(): void {
    $modes = $this->createMock(FieldItemListInterface::class);
    $modes->method('isEmpty')->willReturn(FALSE);
    $modes->method('getValue')->willReturn([['value' => 'maintenance']]);
    $legacy = $this->createMock(FieldItemListInterface::class);
    $legacy->method('getString')->willReturn('1');
    $term = $this->createMock(TermInterface::class);
    $term->method('hasField')->willReturn(TRUE);
    $term->method('get')->willReturnMap([
      ['field_emergency_modes', $modes],
      ['field_emergency_category', $legacy],
    ]);

    $this->assertSame(
      ['maintenance'],
      $this->buildHarness()->service->getTermModes($term),
    );
  }

  /**
   * @covers ::getTermModes
   */
  public function testEmptyModeFieldCanDisableLegacyBoolean(): void {
    $modes = $this->createMock(FieldItemListInterface::class);
    $modes->method('isEmpty')->willReturn(TRUE);
    $term = $this->createMock(TermInterface::class);
    $term->method('hasField')->willReturn(TRUE);
    $term->method('get')->with('field_emergency_modes')->willReturn($modes);

    $this->assertSame([], $this->buildHarness()->service->getTermModes($term));
  }

  /**
   * @covers ::getTermModes
   */
  public function testLegacyBooleanFallsBackWhenModeFieldIsAbsent(): void {
    $legacy = $this->createMock(FieldItemListInterface::class);
    $legacy->method('getString')->willReturn('1');
    $term = $this->createMock(TermInterface::class);
    $term->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_emergency_category',
    );
    $term->method('get')->with('field_emergency_category')->willReturn($legacy);

    $this->assertSame(
      ['disaster', 'crisis'],
      $this->buildHarness()->service->getTermModes($term),
    );
  }

  /**
   * @covers ::cacheTag
   */
  public function testCacheTagIncludesRootJurisdiction(): void {
    $this->assertSame(
      'markaspot_emergency:status:7',
      EmergencyModeService::cacheTag(7),
    );
  }

  /**
   * Builds a stateful service harness.
   *
   * @param array<int, array<string, mixed>> $terms
   *   Taxonomy term data keyed by term ID.
   * @param array<int, int[]> $rootTrees
   *   Jurisdiction trees keyed by root group ID.
   * @param array<int, bool> $groupStatuses
   *   Optional publication overrides keyed by jurisdiction group ID.
   * @param bool $jurisdictionField
   *   Whether service categories expose field_jurisdiction.
   * @param array<string, mixed> $stateValues
   *   Initial State values.
   * @param array<string, mixed> $configValues
   *   Emergency config overrides keyed by dotted config path.
   * @param string[] $failedLocks
   *   Lock names whose acquisition should fail.
   * @param array<string, int> $lockAcquireFailures
   *   Lock names with a finite number of failed acquisition attempts.
   */
  private function buildHarness(
    array $terms = [],
    array $rootTrees = [7 => [7]],
    array $groupStatuses = [],
   bool $jurisdictionField = TRUE,
    bool $serviceDefinitionField = FALSE,
    array $stateValues = [],
    array $configValues = [],
    array $failedLocks = [],
    array $lockAcquireFailures = [],
  ): EmergencyServiceTestHarness {
    $harness = new EmergencyServiceTestHarness();
    $harness->stateValues = $stateValues;
    $harness->rootTrees = $rootTrees;
    $harness->groupStatuses = $groupStatuses;
    $harness->jurisdictionField = $jurisdictionField;
    $harness->serviceDefinitionField = $serviceDefinitionField;
    $harness->failedLocks = array_fill_keys($failedLocks, TRUE);
    $harness->lockAcquireFailures = $lockAcquireFailures;
    foreach ($terms as $id => $term) {
      $harness->terms[(int) $id] = $this->normalizeTerm((int) $id, $term);
    }

    $settingsValues = array_replace([
      'emergency_mode.force_redirect' => TRUE,
      'emergency_mode.lite_ui' => TRUE,
      'emergency_mode.mode_type' => 'disaster',
      'categories.emergency_presets' => [],
      'maintenance.unpublish_non_selected' => TRUE,
      'maintenance.jurisdictions' => [],
      'maintenance.show_only_categories' => [],
    ], $configValues);
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn(string $key): mixed => $settingsValues[$key] ?? NULL,
    );
    $open311 = $this->createMock(ImmutableConfig::class);
    $open311->method('get')->willReturnCallback(
      static fn(string $key): mixed => $key === 'jurisdiction_group_type'
        ? 'jur'
        : NULL,
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(
      static fn(string $name): ImmutableConfig => $name === 'markaspot_open311.settings'
        ? $open311
        : $settings,
    );

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(
      static fn(string $key, mixed $default = NULL): mixed => array_key_exists(
        $key,
        $harness->stateValues,
      ) ? $harness->stateValues[$key] : $default,
    );
    $state->method('set')->willReturnCallback(
      static function (string $key, mixed $value) use ($harness): void {
        $harness->stateValues[$key] = $value;
        $harness->stateWrites[] = [$key, $value];
      },
    );
    $state->method('delete')->willReturnCallback(
      static function (string $key) use ($harness): void {
        unset($harness->stateValues[$key]);
        $harness->stateDeletes[] = $key;
      },
    );

    $hierarchy = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy->method('getAllRootJurisdictionIds')->willReturnCallback(
      static fn(): array => array_map('intval', array_keys($harness->rootTrees)),
    );
    $hierarchy->method('getRootJurisdictionId')->willReturnCallback(
      fn(int $id): ?int => $this->rootForGroup($harness, $id),
    );
    $hierarchy->method('getTermJurisdictionIds')->willReturnCallback(
      function (int $id) use ($harness): array {
        $root = $this->rootForGroup($harness, $id);
        return $root === NULL ? [] : $harness->rootTrees[$root];
      },
    );
    $hierarchy->method('getDescendantIds')->willReturnCallback(
      function (int $id) use ($harness): array {
        $root = $this->rootForGroup($harness, $id);
        return $root === NULL ? [] : $harness->rootTrees[$root];
      },
    );

    $taxonomyStorage = $this->createMock(EntityStorageInterface::class);
    $taxonomyStorage->method('getQuery')->willReturnCallback(
      fn(): QueryInterface => $this->buildTermQuery($harness),
    );
    $taxonomyStorage->method('loadMultiple')->willReturnCallback(
      function (?array $ids = NULL) use ($harness): array {
        $ids ??= array_keys($harness->terms);
        $loaded = [];
        foreach ($ids as $id) {
          $id = (int) $id;
          if (isset($harness->terms[$id])) {
            $loaded[$id] = $this->termMock($harness, $id);
          }
        }
        return $loaded;
      },
    );
    $taxonomyStorage->method('loadByProperties')->willReturnCallback(
      function (array $properties) use ($harness): array {
        $matches = [];
        foreach ($harness->terms as $id => $term) {
          if ($this->termMatchesProperties($term, $properties)) {
            $matches[$id] = $this->termMock($harness, $id);
          }
        }
        return $matches;
      },
    );
    $taxonomyStorage->method('create')->willReturnCallback(
      function (array $values) use ($harness): TermInterface {
        $id = $harness->terms === [] ? 1 : max(array_keys($harness->terms)) + 1;
        $harness->terms[$id] = $this->normalizeTerm($id, [
          'name' => (string) ($values['name'] ?? ''),
          'status' => (bool) ($values['status'] ?? FALSE),
          'jurisdiction' => (int) ($values['field_jurisdiction'] ?? 0),
          'weight' => (int) ($values['weight'] ?? 0),
        ]);
        $harness->createdTerms++;
        return $this->termMock($harness, $id);
      },
    );

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('getQuery')->willReturnCallback(
      fn(): QueryInterface => $this->buildGroupQuery($harness),
    );
    $groupStorage->method('load')->willReturnCallback(
      fn(int $id): ?GroupInterface => $this->groupMock($harness, $id),
    );
    $groupStorage->method('loadMultiple')->willReturnCallback(
      function (?array $ids = NULL) use ($harness): array {
        $ids ??= $this->allGroupIds($harness);
        $groups = [];
        foreach ($ids as $id) {
          $group = $this->groupMock($harness, (int) $id);
          if ($group !== NULL) {
            $groups[(int) $id] = $group;
          }
        }
        return $groups;
      },
    );
    $groupStorage->method('loadByProperties')->willReturnCallback(
      function (array $properties) use ($harness): array {
        $identifier = (string) ($properties['field_slug'] ?? $properties['uuid'] ?? '');
        if (!preg_match('/^(?:root|leaf|group)-(\d+)$/', $identifier, $matches)) {
          return [];
        }
        $id = (int) $matches[1];
        $group = $this->groupMock($harness, $id);
        if ($group !== NULL
          && isset($properties['status'])
          && (bool) $properties['status'] !== $group->isPublished()) {
          return [];
        }
        return $group === NULL ? [] : [$id => $group];
      },
    );

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnCallback(
      static fn(string $entityType): EntityStorageInterface => $entityType === 'group'
        ? $groupStorage
        : $taxonomyStorage,
    );

    $fieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $fieldManager->method('getFieldDefinitions')->willReturnCallback(
      static function (string $entityType, string $bundle) use ($harness): array {
        if ($entityType !== 'taxonomy_term' || $bundle !== 'service_category') {
          return [];
        }
        $definitions = ['field_service_code' => new \stdClass()];
        if ($harness->jurisdictionField) {
          $definitions['field_jurisdiction'] = new \stdClass();
        }
        if ($harness->serviceDefinitionField) {
          $definitions['field_service_definition'] = new \stdClass();
        }
        return $definitions;
      },
    );

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(99);
    $account->method('getDisplayName')->willReturn('Emergency Operator');
    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(
      static function (string $name) use ($harness): bool {
        $harness->locksAcquired[] = $name;
        if (($harness->lockAcquireFailures[$name] ?? 0) > 0) {
          $harness->lockAcquireFailures[$name]--;
          return FALSE;
        }
        return !isset($harness->failedLocks[$name]);
      },
    );
    $lock->method('wait')->willReturnCallback(
      static function (string $name) use ($harness): bool {
        $harness->locksWaited[] = $name;
        return FALSE;
      },
    );
    $lock->method('release')->willReturnCallback(
      static function (string $name) use ($harness): void {
        $harness->locksReleased[] = $name;
      },
    );

    $database = $this->createMock(Connection::class);
    $database->method('startTransaction')->willReturnCallback(
      static function () use ($harness): EmergencyServiceTestTransaction {
        $transaction = new EmergencyServiceTestTransaction();
        $harness->transactions[] = $transaction;
        return $transaction;
      },
    );
    $database->method('select')->willReturnCallback(
      fn(string $table): SelectInterface => $this->buildDatabaseSelect($harness, $table),
    );
    $transactionManager = $this->createMock(TransactionManagerInterface::class);
    $transactionManager->method('addPostTransactionCallback')->willReturnCallback(
      static function (callable $callback) use ($harness): void {
        $harness->postTransactionCallbacks[] = $callback;
      },
    );
    $database->method('transactionManager')->willReturn($transactionManager);

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturnCallback(
      static fn(string $type = LanguageInterface::TYPE_INTERFACE): LanguageInterface => new Language([
        'id' => $harness->currentLangcode,
      ]),
    );
    $languageManager->method('getDefaultLanguage')->willReturn(new Language([
      'id' => 'en',
    ]));

    $cacheInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $cacheInvalidator->method('invalidateTags')->willReturnCallback(
      static function (array $tags) use ($harness): void {
        $harness->invalidatedTags[] = $tags;
      },
    );
    $container = new ContainerBuilder();
    $container->set('cache_tags.invalidator', $cacheInvalidator);
    \Drupal::setContainer($container);

    $harness->service = new EmergencyModeService(
      $configFactory,
      $state,
      $entityTypeManager,
      $fieldManager,
      $account,
      $loggerFactory,
      $hierarchy,
      $lock,
      $database,
      $languageManager,
      new PhpSerialize(),
    );
    return $harness;
  }

  /**
   * Builds an entity query over the mutable term model.
   */
  private function buildTermQuery(EmergencyServiceTestHarness $harness): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $conditions = [];
    $query->method('condition')->willReturnCallback(
      static function (
        mixed $field,
        mixed $value = NULL,
        mixed $operator = NULL,
      ) use (&$conditions, $query): QueryInterface {
        if (is_string($field)) {
          $conditions[$field] = [$value, $operator];
        }
        return $query;
      },
    );
    $query->method('accessCheck')->willReturn($query);
    $query->method('execute')->willReturnCallback(
      static function () use (&$conditions, $harness): array {
        $ids = [];
        foreach ($harness->terms as $id => $term) {
          if (isset($conditions['vid']) && $conditions['vid'][0] !== 'service_category') {
            continue;
          }
          if (isset($conditions['status'])
            && (int) $term['status'] !== (int) $conditions['status'][0]) {
            continue;
          }
          if (isset($conditions['field_jurisdiction'])) {
            $allowed = array_map('intval', (array) $conditions['field_jurisdiction'][0]);
            if (!in_array((int) $term['jurisdiction'], $allowed, TRUE)) {
              continue;
            }
          }
          $ids[$id] = $id;
        }
        ksort($ids);
        return $ids;
      },
    );
    return $query;
  }

  /**
   * Builds current-read query doubles for State and category guard rows.
   */
  private function buildDatabaseSelect(
    EmergencyServiceTestHarness $harness,
    string $table,
  ): SelectInterface {
    $query = $this->createMock(SelectInterface::class);
    $conditions = [];
    $query->method('fields')->willReturn($query);
    $query->method('condition')->willReturnCallback(
      static function (
        mixed $field,
        mixed $value = NULL,
        mixed $operator = NULL,
      ) use (&$conditions, $query): SelectInterface {
        if (is_string($field)) {
          $conditions[$field] = [$value, $operator];
        }
        return $query;
      },
    );
    $query->method('range')->willReturn($query);
    $query->method('orderBy')->willReturn($query);
    $query->method('forUpdate')->willReturnCallback(
      static function (bool $set = TRUE) use ($harness, $table, $query): SelectInterface {
        if ($set) {
          $harness->lockingReads[] = $table;
        }
        return $query;
      },
    );
    $query->method('execute')->willReturnCallback(
      function () use (&$conditions, $harness, $table): StatementInterface {
        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchField')->willReturnCallback(
          static function () use (&$conditions, $harness, $table): string|int|false {
            if ($table === 'key_value') {
              $key = (string) ($conditions['name'][0] ?? '');
              return array_key_exists($key, $harness->stateValues)
                ? PhpSerialize::encode($harness->stateValues[$key])
                : FALSE;
            }
            if ($table === 'taxonomy_term_field_data') {
              $tid = (int) ($conditions['tid'][0] ?? 0);
              return isset($harness->terms[$tid]) && $harness->terms[$tid]['status']
                ? $tid
                : FALSE;
            }
            if ($table === 'groups_field_data') {
              $id = (int) ($conditions['id'][0] ?? 0);
              $group = in_array($id, array_merge(...array_values($harness->rootTrees ?: [[]])), TRUE);
              $published = $harness->groupStatuses[$id] ?? TRUE;
              if (isset($conditions['status']) && (bool) $conditions['status'][0] !== $published) {
                return FALSE;
              }
              return $group ? $id : FALSE;
            }
            return FALSE;
          },
        );
        $statement->method('fetchCol')->willReturnCallback(
          function () use (&$conditions, $harness, $table): array {
            if ($table === 'taxonomy_term__field_jurisdiction') {
              $tid = (int) ($conditions['entity_id'][0] ?? 0);
              $jurisdictionId = (int) ($harness->terms[$tid]['jurisdiction'] ?? 0);
              return $jurisdictionId > 0 ? [$jurisdictionId] : [];
            }
            if ($table === 'taxonomy_term__field_service_definition') {
              $tid = (int) ($conditions['entity_id'][0] ?? 0);
              $definition = $harness->terms[$tid]['service_definition'] ?? '';
              return $definition === '' ? [] : [$definition];
            }
            if ($table === 'group__field_parent_jurisdiction') {
              $id = (int) ($conditions['entity_id'][0] ?? 0);
              $root = $this->rootForGroup($harness, $id);
              return $root !== NULL && $root !== $id ? [$root] : [];
            }
            return [];
          },
        );
        return $statement;
      },
    );
    return $query;
  }

  /**
   * Builds a group query used by the scope-0 safety check.
   */
  private function buildGroupQuery(EmergencyServiceTestHarness $harness): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturn($query);
    $query->method('accessCheck')->willReturn($query);
    $query->method('range')->willReturn($query);
    $query->method('execute')->willReturnCallback(
      fn(): array => array_combine(
        $this->allGroupIds($harness),
        $this->allGroupIds($harness),
      ) ?: [],
    );
    return $query;
  }

  /**
   * Returns one stateful taxonomy term test double.
   */
  private function termMock(
    EmergencyServiceTestHarness $harness,
    int $id,
  ): TermInterface {
    if (isset($harness->termMocks[$id])) {
      return $harness->termMocks[$id];
    }

    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn($id);
    $term->method('uuid')->willReturn('term-' . $id);
    $term->method('isTranslatable')->willReturnCallback(
      static fn(): bool => $harness->terms[$id]['translations'] !== [],
    );
    $term->method('getTranslationLanguages')->willReturnCallback(
      static fn(): array => array_fill_keys(
        array_keys($harness->terms[$id]['translations']),
        new \stdClass(),
      ),
    );
    $term->method('hasTranslation')->willReturnCallback(
      static fn(string $langcode): bool => array_key_exists(
        $langcode,
        $harness->terms[$id]['translations'],
      ),
    );
    $term->method('getTranslation')->willReturnCallback(
      fn(string $langcode): TermInterface => $this->termTranslationMock(
        $harness,
        $id,
        $langcode,
      ),
    );
    $term->method('label')->willReturnCallback(
      static fn(): string => $harness->terms[$id]['name'],
    );
    $term->method('getName')->willReturnCallback(
      static fn(): string => $harness->terms[$id]['name'],
    );
    $term->method('isPublished')->willReturnCallback(
      static fn(): bool => $harness->terms[$id]['status'],
    );
    $term->method('setPublished')->willReturnCallback(
      static function () use ($harness, $id, $term): TermInterface {
        $harness->terms[$id]['status'] = TRUE;
        $defaultLangcode = array_key_first($harness->terms[$id]['translations']);
        if ($defaultLangcode !== NULL) {
          $harness->terms[$id]['translations'][$defaultLangcode] = TRUE;
        }
        return $term;
      },
    );
    $term->method('setUnpublished')->willReturnCallback(
      static function () use ($harness, $id, $term): TermInterface {
        $harness->terms[$id]['status'] = FALSE;
        $defaultLangcode = array_key_first($harness->terms[$id]['translations']);
        if ($defaultLangcode !== NULL) {
          $harness->terms[$id]['translations'][$defaultLangcode] = FALSE;
        }
        return $term;
      },
    );
    $term->method('setName')->willReturnCallback(
      static function (string $name) use ($harness, $id, $term): TermInterface {
        $harness->terms[$id]['name'] = $name;
        return $term;
      },
    );
    $term->method('setWeight')->willReturnCallback(
      static function (int $weight) use ($harness, $id, $term): TermInterface {
        $harness->terms[$id]['weight'] = $weight;
        return $term;
      },
    );
    $term->method('hasField')->willReturnCallback(
      static fn(string $field): bool => match ($field) {
        'field_emergency_modes' => $harness->terms[$id]['has_mode_field'],
        'field_emergency_category' => TRUE,
        'field_service_code' => TRUE,
        'field_jurisdiction' => $harness->jurisdictionField,
        'field_service_definition' => $harness->serviceDefinitionField,
        default => FALSE,
      },
    );
    $term->method('get')->willReturnCallback(
      function (string $field) use ($harness, $id): FieldItemListInterface {
        $list = $this->createMock(FieldItemListInterface::class);
        $list->method('isEmpty')->willReturnCallback(
          static fn(): bool => match ($field) {
            'field_emergency_modes' => $harness->terms[$id]['modes'] === [],
            'field_service_code' => $harness->terms[$id]['service_code'] === '',
            'field_jurisdiction' => $harness->terms[$id]['jurisdiction'] <= 0,
            'field_service_definition' => $harness->terms[$id]['service_definition'] === '',
            default => !$harness->terms[$id]['legacy'],
          },
        );
        $list->method('getValue')->willReturnCallback(
          static fn(): array => match ($field) {
            'field_emergency_modes' => array_map(
              static fn(string $mode): array => ['value' => $mode],
              $harness->terms[$id]['modes'],
            ),
            'field_jurisdiction' => $harness->terms[$id]['jurisdiction'] > 0
              ? [['target_id' => $harness->terms[$id]['jurisdiction']]]
              : [],
            default => [],
          },
        );
        $list->method('getString')->willReturnCallback(
          static fn(): string => match ($field) {
            'field_emergency_category' => $harness->terms[$id]['legacy'] ? '1' : '0',
            'field_service_code' => $harness->terms[$id]['service_code'],
            default => '',
          },
        );
        $list->method('__get')->willReturnCallback(
          static fn(string $property): mixed => $field === 'field_service_definition'
            && $property === 'value'
            ? $harness->terms[$id]['service_definition']
            : NULL,
        );
        return $list;
      },
    );
    $term->method('set')->willReturnCallback(
      static function (string $field, mixed $value) use ($harness, $id, $term): TermInterface {
        if ($field === 'field_emergency_modes') {
          $harness->terms[$id]['modes'] = array_values(array_map(
            static fn(mixed $mode): string => is_array($mode)
              ? (string) ($mode['value'] ?? '')
              : (string) $mode,
            (array) $value,
          ));
        }
        elseif ($field === 'field_emergency_category') {
          $harness->terms[$id]['legacy'] = (bool) $value;
        }
        elseif ($field === 'field_service_code') {
          $harness->terms[$id]['service_code'] = (string) $value;
        }
        return $term;
      },
    );
    $term->method('save')->willReturnCallback(
      static function () use ($harness, $id): int {
        $harness->terms[$id]['saves']++;
        if ($harness->throwOnSaveTermId === $id) {
          throw new \RuntimeException('Simulated taxonomy save failure.');
        }
        return 1;
      },
    );

    $harness->termMocks[$id] = $term;
    return $term;
  }

  /**
   * Returns a mutable publication-only term translation double.
   */
  private function termTranslationMock(
    EmergencyServiceTestHarness $harness,
    int $id,
    string $langcode,
  ): TermInterface {
    if (isset($harness->translationMocks[$id][$langcode])) {
      return $harness->translationMocks[$id][$langcode];
    }

    $translation = $this->createMock(TermInterface::class);
    $translation->method('id')->willReturn($id);
    $translation->method('isPublished')->willReturnCallback(
      static fn(): bool => $harness->terms[$id]['translations'][$langcode],
    );
    $translation->method('setPublished')->willReturnCallback(
      static function () use ($harness, $id, $langcode, $translation): TermInterface {
        $harness->terms[$id]['translations'][$langcode] = TRUE;
        if ($langcode === array_key_first($harness->terms[$id]['translations'])) {
          $harness->terms[$id]['status'] = TRUE;
        }
        return $translation;
      },
    );
    $translation->method('setUnpublished')->willReturnCallback(
      static function () use ($harness, $id, $langcode, $translation): TermInterface {
        $harness->terms[$id]['translations'][$langcode] = FALSE;
        if ($langcode === array_key_first($harness->terms[$id]['translations'])) {
          $harness->terms[$id]['status'] = FALSE;
        }
        return $translation;
      },
    );

    $harness->translationMocks[$id][$langcode] = $translation;
    return $translation;
  }

  /**
   * Returns a jurisdiction group test double, or NULL for an unknown group.
   */
  private function groupMock(
    EmergencyServiceTestHarness $harness,
    int $id,
  ): ?GroupInterface {
    if (!in_array($id, $this->allGroupIds($harness), TRUE)) {
      return NULL;
    }
    if (isset($harness->groupMocks[$id])) {
      return $harness->groupMocks[$id];
    }

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn('jur');
    $group->method('isPublished')->willReturnCallback(
      static fn(): bool => $harness->groupStatuses[$id] ?? TRUE,
    );
    $group->method('label')->willReturn('Jurisdiction ' . $id);
    $harness->groupMocks[$id] = $group;
    return $group;
  }

  /**
   * Normalizes one mutable term record.
   *
   * @param int $id
   *   Term ID.
   * @param array<string, mixed> $term
   *   Partial term values.
   *
   * @return array<string, mixed>
   *   Complete mutable term values.
   */
  private function normalizeTerm(int $id, array $term): array {
    return array_replace([
      'id' => $id,
      'name' => 'Category ' . $id,
      'status' => FALSE,
      'jurisdiction' => 7,
      'modes' => [],
      'legacy' => FALSE,
      'service_code' => '',
      'service_definition' => '',
      'weight' => 0,
      'has_mode_field' => TRUE,
      'translations' => [],
      'saves' => 0,
    ], $term);
  }

  /**
   * Checks a term against loadByProperties() criteria used by the service.
   */
  private function termMatchesProperties(array $term, array $properties): bool {
    foreach ($properties as $field => $expected) {
      $actual = match ($field) {
        'vid' => 'service_category',
        'uuid' => 'term-' . $term['id'],
        'name' => $term['name'],
        'field_emergency_category' => $term['legacy'],
        'field_service_code' => $term['service_code'],
        'field_jurisdiction' => $term['jurisdiction'],
        default => NULL,
      };
      if ($actual !== $expected) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Returns the root for a group in the mutable hierarchy.
   */
  private function rootForGroup(
    EmergencyServiceTestHarness $harness,
    int $groupId,
  ): ?int {
    foreach ($harness->rootTrees as $rootId => $ids) {
      if (in_array($groupId, array_map('intval', $ids), TRUE)) {
        return (int) $rootId;
      }
    }
    return NULL;
  }

  /**
   * Returns all configured group IDs.
   *
   * @return int[]
   *   Root and leaf jurisdiction IDs.
   */
  private function allGroupIds(EmergencyServiceTestHarness $harness): array {
    return array_values(array_unique(array_map(
      'intval',
      array_merge(...array_values($harness->rootTrees ?: [[]])),
    )));
  }

  /**
   * Returns IDs currently marked published in the test model.
   *
   * @return int[]
   *   Published term IDs.
   */
  private function publishedTermIds(EmergencyServiceTestHarness $harness): array {
    $ids = array_keys(array_filter(
      $harness->terms,
      static fn(array $term): bool => $term['status'],
    ));
    sort($ids);
    return array_map('intval', $ids);
  }

  /**
   * Returns the standard terms for mode transition tests.
   *
   * @return array<int, array<string, mixed>>
   *   Term data keyed by term ID.
   */
  private function transitionTerms(): array {
    return [
      10 => [
        'name' => 'Ordinary category',
        'status' => TRUE,
        'jurisdiction' => 7,
        'modes' => [],
      ],
      11 => [
        'name' => 'Disaster category',
        'status' => FALSE,
        'jurisdiction' => 7,
        'modes' => ['disaster'],
      ],
      12 => [
        'name' => 'Crisis category',
        'status' => FALSE,
        'jurisdiction' => 7,
        'modes' => ['crisis'],
      ],
    ];
  }

}

/**
 * Mutable data shared by unit-test doubles.
 */
final class EmergencyServiceTestHarness {

  /**
   * Service under test.
   */
  public EmergencyModeService $service;

  /**
   * Current administrative interface language.
   */
  public string $currentLangcode = 'en';

  /**
   * Mutable State API values.
   *
   * @var array<string, mixed>
   */
  public array $stateValues = [];

  /**
   * Mutable taxonomy term records.
   *
   * @var array<int, array<string, mixed>>
   */
  public array $terms = [];

  /**
   * Jurisdiction trees keyed by root ID.
   *
   * @var array<int, int[]>
   */
  public array $rootTrees = [];

  /**
   * Jurisdiction publication overrides keyed by group ID.
   *
   * @var array<int, bool>
   */
  public array $groupStatuses = [];

  /**
   * Stateful term test doubles.
   *
   * @var array<int, \Drupal\taxonomy\TermInterface>
   */
  public array $termMocks = [];

  /**
   * Stateful term translation test doubles.
   *
   * @var array<int, array<string, \Drupal\taxonomy\TermInterface>>
   */
  public array $translationMocks = [];

  /**
   * Jurisdiction group test doubles.
   *
   * @var array<int, \Drupal\group\Entity\GroupInterface>
   */
  public array $groupMocks = [];

  /**
   * Lock names configured to reject acquisition.
   *
   * @var array<string, bool>
   */
  public array $failedLocks = [];

  /**
   * Remaining transient acquisition failures by lock name.
   *
   * @var array<string, int>
   */
  public array $lockAcquireFailures = [];

  /**
   * Recorded State writes.
   *
   * @var array<int, array{0: string, 1: mixed}>
   */
  public array $stateWrites = [];

  /**
   * Recorded State deletions.
   *
   * @var string[]
   */
  public array $stateDeletes = [];

  /**
   * Recorded lock acquisition attempts.
   *
   * @var string[]
   */
  public array $locksAcquired = [];

  /**
   * Recorded lock waits.
   *
   * @var string[]
   */
  public array $locksWaited = [];

  /**
   * Recorded lock releases.
   *
   * @var string[]
   */
  public array $locksReleased = [];

  /**
   * Tables read with current FOR UPDATE semantics.
   *
   * @var string[]
   */
  public array $lockingReads = [];

  /**
   * Root-transaction callbacks awaiting commit or rollback.
   *
   * @var callable[]
   */
  public array $postTransactionCallbacks = [];

  /**
   * Transactions started by the service.
   *
   * @var \Drupal\Tests\markaspot_emergency\Unit\EmergencyServiceTestTransaction[]
   */
  public array $transactions = [];

  /**
   * Cache-tag invalidations.
   *
   * @var array<int, string[]>
   */
  public array $invalidatedTags = [];

  /**
   * Whether service categories expose field_jurisdiction.
   */
  public bool $jurisdictionField = TRUE;

  /**
   * Whether service categories expose field_service_definition.
   */
  public bool $serviceDefinitionField = FALSE;

  /**
   * Number of taxonomy terms created by the service.
   */
  public int $createdTerms = 0;

  /**
   * Term ID whose next save should fail.
   */
  public ?int $throwOnSaveTermId = NULL;

  /**
   * Completes the simulated database root transaction.
   */
  public function finishTransactions(bool $success = TRUE): void {
    $callbacks = $this->postTransactionCallbacks;
    $this->postTransactionCallbacks = [];
    foreach ($callbacks as $callback) {
      $callback($success);
    }
  }

}

/**
 * Records rollback calls made by the service.
 */
final class EmergencyServiceTestTransaction {

  /**
   * Number of rollback calls.
   */
  public int $rollbacks = 0;

  /**
   * Records one rollback.
   */
  public function rollBack(): void {
    $this->rollbacks++;
  }

}
