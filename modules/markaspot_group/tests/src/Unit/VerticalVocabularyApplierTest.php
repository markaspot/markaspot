<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\StatusTermScope;
use Drupal\markaspot_group\Service\VerticalVocabularyApplier;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests safe vertical vocabulary planning and application.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Service\VerticalVocabularyApplier
 */
class VerticalVocabularyApplierTest extends UnitTestCase {

  /**
   * Tests the municipal identity definition is byte-identical and write-free.
   *
   * @covers ::apply
   */
  public function testMunicipalLeavesConfigByteIdentical(): void {
    $original = "{\n  \"branding\": {\"logo\": \"/logo.svg\"}\n}";
    $saveCount = 0;
    $group = $this->createGroup(10, 'Root', $original, $saveCount);
    $applier = $this->createApplier([10 => $group], [10 => 10]);

    $rows = $applier->apply($this->definition('municipal'), 10);

    $this->assertSame("{\n  \"branding\": {\"logo\": \"/logo.svg\"}\n}", $original);
    $this->assertSame(0, $saveCount);
    $this->assertSame('no-changes', $rows[0]['result']);
  }

  /**
   * Tests HOA config is merged, preserves other sections, and is idempotent.
   *
   * @covers ::apply
   */
  public function testHoaMergesConfigAndSecondRunIsIdempotent(): void {
    $config = json_encode([
      'branding' => ['logo' => '/logo.svg'],
      'features' => ['map' => TRUE],
      'map' => ['zoom' => 12],
      'navigation' => ['footer' => ['privacy']],
      'setup' => new \stdClass(),
      'i18n' => [
        'wording' => 'report',
        'overrides' => [
          'en' => [
            'custom.keep' => 'Untouched',
            'dashboard.nav.organisations' => 'Organisations',
          ],
          'fr' => ['custom.french' => 'Conserver'],
        ],
      ],
    ], JSON_THROW_ON_ERROR);
    $saveCount = 0;
    $group = $this->createGroup(10, 'Root', $config, $saveCount);
    $applier = $this->createApplier([10 => $group], [10 => 10]);
    $definition = $this->definition('hoa', [
      'en' => [
        'jurisdiction' => [
          'singular' => 'Community',
          'plural' => 'Communities',
        ],
        'organisation' => [
          'singular' => 'Contractor',
          'plural' => 'Contractors',
        ],
      ],
    ], [
      'en' => [
        'dashboard.nav.organisations' => 'Contractors',
        'dashboard.detail.placeholders.no_organisation' => 'No contractor assigned',
      ],
    ]);

    $firstRows = $applier->apply($definition, 10);
    $decoded = json_decode($config, TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(['logo' => '/logo.svg'], $decoded['branding']);
    $this->assertSame(['map' => TRUE], $decoded['features']);
    $this->assertSame(['zoom' => 12], $decoded['map']);
    $this->assertSame(['footer' => ['privacy']], $decoded['navigation']);
    $this->assertStringContainsString('"setup":{}', $config);
    $this->assertSame('report', $decoded['i18n']['wording']);
    $this->assertSame('Untouched', $decoded['i18n']['overrides']['en']['custom.keep']);
    $this->assertSame('Conserver', $decoded['i18n']['overrides']['fr']['custom.french']);
    $this->assertSame(
      'Communities',
      $decoded['i18n']['entities']['en']['jurisdiction']['plural'],
    );
    $this->assertSame(
      'Contractors',
      $decoded['i18n']['overrides']['en']['dashboard.nav.organisations'],
    );
    $this->assertSame(1, $saveCount);
    $this->assertContains('set', array_column($firstRows, 'result'));
    $this->assertContains('updated', array_column($firstRows, 'result'));
    $this->assertContains('added', array_column($firstRows, 'result'));

    $secondRows = $applier->apply($definition, 10);

    $this->assertSame(1, $saveCount);
    $this->assertNotContains('set', array_column($secondRows, 'result'));
    $this->assertNotContains('updated', array_column($secondRows, 'result'));
    $this->assertNotContains('added', array_column($secondRows, 'result'));
    $lastRow = $secondRows[array_key_last($secondRows)];
    $this->assertSame('no-changes', $lastRow['result']);
  }

  /**
   * Tests dry-run reports config changes without writing.
   *
   * @covers ::apply
   */
  public function testDryRunWritesNothing(): void {
    $original = '{"branding":{"logo":"/logo.svg"}}';
    $config = $original;
    $saveCount = 0;
    $group = $this->createGroup(10, 'Root', $config, $saveCount);
    $applier = $this->createApplier([10 => $group], [10 => 10]);
    $definition = $this->definition('hoa', [
      'en' => [
        'jurisdiction' => [
          'singular' => 'Community',
          'plural' => 'Communities',
        ],
        'organisation' => [
          'singular' => 'Contractor',
          'plural' => 'Contractors',
        ],
      ],
    ]);

    $rows = $applier->apply($definition, 10, TRUE);

    $this->assertSame($original, $config);
    $this->assertSame(0, $saveCount);
    $this->assertSame('would-set', $rows[0]['result']);
  }

  /**
   * Tests malformed nested config fails cleanly before any write.
   *
   * @covers ::apply
   */
  public function testMalformedNestedConfigFailsCleanly(): void {
    $config = '{"i18n":{"entities":"legacy"}}';
    $saveCount = 0;
    $group = $this->createGroup(10, 'Root', $config, $saveCount);
    $applier = $this->createApplier([10 => $group], [10 => 10]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
      'field_nuxt_config.i18n.entities must be an object.',
    );
    $applier->apply($this->definition('hoa', [
      'en' => [
        'jurisdiction' => [
          'singular' => 'Community',
          'plural' => 'Communities',
        ],
        'organisation' => [
          'singular' => 'Contractor',
          'plural' => 'Contractors',
        ],
      ],
    ]), 10);
  }

  /**
   * Tests child jurisdictions are refused and the root is named.
   *
   * @covers ::apply
   */
  public function testChildJurisdictionIsRefusedWithRootDetails(): void {
    $childConfig = '{}';
    $childSaves = 0;
    $child = $this->createGroup(12, 'Child', $childConfig, $childSaves);
    $rootConfig = '{}';
    $rootSaves = 0;
    $root = $this->createGroup(10, 'Portfolio Root', $rootConfig, $rootSaves);
    $applier = $this->createApplier(
      [10 => $root, 12 => $child],
      [10 => 10, 12 => 10],
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
      'Jurisdiction 12 is not the portfolio root. The root is 10 "Portfolio Root". Run the command with --jurisdiction=10.',
    );
    $applier->apply($this->definition('hoa'), 12);
  }

  /**
   * Tests a fail-open hierarchy result cannot promote a malformed child.
   *
   * @covers ::apply
   */
  public function testParentReferencePreventsFailOpenRootPromotion(): void {
    $config = '{}';
    $saveCount = 0;
    $group = $this->createGroup(12, 'Orphaned Child', $config, $saveCount, FALSE);
    $applier = $this->createApplier([12 => $group], [12 => 12]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
      'Jurisdiction 12 has a parent reference and cannot be treated as a portfolio root.',
    );
    $applier->apply($this->definition('hoa'), 12);
  }

  /**
   * Tests status matching, stable IDs, skips, ambiguity, and foreign isolation.
   *
   * @covers ::apply
   */
  public function testStatusRenamesAreConservativeAndKeepTermIds(): void {
    $config = '{}';
    $groupSaves = 0;
    $group = $this->createGroup(10, 'Root', $config, $groupSaves);

    $matchedName = 'Assigned to Vendor';
    $matchedSaves = 0;
    $matched = $this->createTerm(21, 10, $matchedName, $matchedSaves);
    $foreignName = 'Assigned to Vendor';
    $foreignSaves = 0;
    $foreign = $this->createTerm(99, 90, $foreignName, $foreignSaves);
    $duplicateOneName = 'Duplicate old status';
    $duplicateOneSaves = 0;
    $duplicateOne = $this->createTerm(
      31,
      10,
      $duplicateOneName,
      $duplicateOneSaves,
    );
    $duplicateTwoName = 'duplicate OLD status';
    $duplicateTwoSaves = 0;
    $duplicateTwo = $this->createTerm(
      32,
      10,
      $duplicateTwoName,
      $duplicateTwoSaves,
    );

    $scope = $this->createMock(StatusTermScope::class);
    $scope->expects($this->exactly(2))
      ->method('canScope')
      ->with(10)
      ->willReturn(TRUE);
    $scope->expects($this->exactly(2))
      ->method('loadTreePoolByProperties')
      ->with(['vid' => 'service_status'], 10)
      ->willReturn([
        21 => $matched,
        99 => $foreign,
        31 => $duplicateOne,
        32 => $duplicateTwo,
      ]);
    $applier = $this->createApplier([10 => $group], [10 => 10], $scope);
    $definition = $this->definition('hoa', [], [], [
      'en' => [
        [
          'match' => 'assigned TO vendor',
          'name' => 'Assigned to Contractor',
        ],
        [
          'match' => 'Missing status',
          'name' => 'Replacement',
        ],
        [
          'match' => 'Duplicate old status',
          'name' => 'Must not be used',
        ],
      ],
    ]);

    $rows = $applier->apply($definition, 10);

    $this->assertSame(21, (int) $matched->id());
    $this->assertSame('Assigned to Contractor', $matchedName);
    $this->assertSame(1, $matchedSaves);
    $this->assertSame('Assigned to Vendor', $foreignName);
    $this->assertSame(0, $foreignSaves);
    $this->assertSame('Duplicate old status', $duplicateOneName);
    $this->assertSame('duplicate OLD status', $duplicateTwoName);
    $this->assertSame(0, $duplicateOneSaves);
    $this->assertSame(0, $duplicateTwoSaves);
    $this->assertSame(
      ['renamed', 'not-found', 'ambiguous'],
      array_column($rows, 'result'),
    );
    $this->assertStringContainsString(
      'term ID and existing references remain unchanged',
      $rows[0]['detail'],
    );
    $this->assertStringContainsString('31:Duplicate old status', $rows[2]['detail']);
    $this->assertStringContainsString('32:duplicate OLD status', $rows[2]['detail']);

    $secondRows = $applier->apply($definition, 10);

    $this->assertSame(21, (int) $matched->id());
    $this->assertSame(1, $matchedSaves);
    $this->assertSame('unchanged', $secondRows[0]['result']);
    $this->assertStringContainsString(
      'already has target name',
      $secondRows[0]['detail'],
    );
  }

  /**
   * Tests a status dry-run neither changes nor saves the matched term.
   *
   * @covers ::apply
   */
  public function testStatusDryRunWritesNothing(): void {
    $config = '{}';
    $groupSaves = 0;
    $group = $this->createGroup(10, 'Root', $config, $groupSaves);
    $name = 'Assigned to Vendor';
    $termSaves = 0;
    $term = $this->createTerm(21, 10, $name, $termSaves);
    $scope = $this->createMock(StatusTermScope::class);
    $scope->method('canScope')->willReturn(TRUE);
    $scope->method('loadTreePoolByProperties')->willReturn([21 => $term]);
    $applier = $this->createApplier([10 => $group], [10 => 10], $scope);

    $rows = $applier->apply($this->definition('hoa', [], [], [
      'en' => [[
        'match' => 'Assigned to Vendor',
        'name' => 'Assigned to Contractor',
      ]],
    ]), 10, TRUE);

    $this->assertSame('Assigned to Vendor', $name);
    $this->assertSame(0, $termSaves);
    $this->assertSame(0, $groupSaves);
    $this->assertSame('would-rename', $rows[0]['result']);
  }

  /**
   * Tests an existing target name blocks a duplicate-label rename.
   *
   * @covers ::apply
   */
  public function testStatusTargetNameCollisionIsSkipped(): void {
    $config = '{}';
    $groupSaves = 0;
    $group = $this->createGroup(10, 'Root', $config, $groupSaves);
    $sourceName = 'Assigned to Vendor';
    $sourceSaves = 0;
    $source = $this->createTerm(21, 10, $sourceName, $sourceSaves);
    $targetName = 'Assigned to Contractor';
    $targetSaves = 0;
    $target = $this->createTerm(22, 10, $targetName, $targetSaves);
    $scope = $this->createMock(StatusTermScope::class);
    $scope->method('canScope')->willReturn(TRUE);
    $scope->method('loadTreePoolByProperties')->willReturn([
      21 => $source,
      22 => $target,
    ]);
    $applier = $this->createApplier([10 => $group], [10 => 10], $scope);

    $rows = $applier->apply($this->definition('hoa', [], [], [
      'en' => [[
        'match' => 'Assigned to Vendor',
        'name' => 'Assigned to Contractor',
      ]],
    ]), 10);

    $this->assertSame('target-exists', $rows[0]['result']);
    $this->assertSame('no-writes', $rows[1]['result']);
    $this->assertSame('Assigned to Vendor', $sourceName);
    $this->assertSame(0, $sourceSaves);
    $this->assertSame(0, $targetSaves);
  }

  /**
   * Tests write failures roll back the cross-layer transaction.
   *
   * @covers ::apply
   */
  public function testWriteFailureRollsBackTransaction(): void {
    $config = '{}';
    $saveCount = 0;
    $group = $this->createGroup(
      10,
      'Root',
      $config,
      $saveCount,
      TRUE,
      new \RuntimeException('Group save failed.'),
    );
    $database = $this->createMock(Connection::class);
    $transactionManager = $this->createMock(TransactionManagerInterface::class);
    $transactionManager->expects($this->once())
      ->method('rollback')
      ->with('vertical_failure', 'vertical_failure');
    $database->method('transactionManager')->willReturn($transactionManager);
    $transaction = new Transaction(
      $database,
      'vertical_failure',
      'vertical_failure',
    );
    $database->expects($this->once())
      ->method('startTransaction')
      ->willReturn($transaction);
    $applier = $this->createApplier(
      [10 => $group],
      [10 => 10],
      NULL,
      $database,
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Group save failed.');
    $applier->apply($this->definition('hoa', [
      'en' => [
        'jurisdiction' => [
          'singular' => 'Community',
          'plural' => 'Communities',
        ],
        'organisation' => [
          'singular' => 'Contractor',
          'plural' => 'Contractors',
        ],
      ],
    ]), 10);
  }

  /**
   * Creates a vertical definition for one test.
   */
  protected function definition(
    string $id,
    array $entities = [],
    array $overrides = [],
    array $statuses = [],
  ): array {
    return [
      'id' => $id,
      'label' => ucfirst($id),
      'entities' => $entities,
      'overrides' => $overrides,
      'statuses' => $statuses,
    ];
  }

  /**
   * Creates an applier with controlled group storage and hierarchy.
   *
   * @param array<int, \Drupal\group\Entity\GroupInterface> $groups
   *   Groups keyed by ID.
   * @param array<int, int> $roots
   *   Root IDs keyed by jurisdiction ID.
   * @param \Drupal\markaspot_group\Service\StatusTermScope|null $scope
   *   Optional status scope mock.
   * @param \Drupal\Core\Database\Connection|null $database
   *   Optional database connection mock.
   */
  protected function createApplier(
    array $groups,
    array $roots,
    ?StatusTermScope $scope = NULL,
    ?Connection $database = NULL,
  ): VerticalVocabularyApplier {
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')
      ->willReturnCallback(
        static fn(int $id): ?GroupInterface => $groups[$id] ?? NULL,
      );
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    $hierarchyResolver = $this->createMock(
      JurisdictionHierarchyResolverInterface::class,
    );
    $hierarchyResolver->method('getRootJurisdictionId')
      ->willReturnCallback(static fn(int $id): ?int => $roots[$id] ?? NULL);

    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn('jur');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($open311Config);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getDefaultLanguage')->willReturn($language);
    if ($database === NULL) {
      $database = $this->createMock(Connection::class);
      $transactionManager = $this->createMock(TransactionManagerInterface::class);
      $database->method('transactionManager')->willReturn($transactionManager);
      $transaction = new Transaction($database, 'vertical_test', 'vertical_test');
      $database->method('startTransaction')->willReturn($transaction);
    }

    return new VerticalVocabularyApplier(
      $entityTypeManager,
      $hierarchyResolver,
      $scope ?? $this->createMock(StatusTermScope::class),
      $configFactory,
      $languageManager,
      $database,
    );
  }

  /**
   * Creates a mutable jurisdiction group double.
   */
  protected function createGroup(
    int $id,
    string $label,
    string &$config,
    int &$saveCount,
    bool $parentIsEmpty = TRUE,
    ?\Throwable $saveException = NULL,
  ): GroupInterface {
    $configField = $this->createMock(FieldItemListInterface::class);
    $configField->method('getString')
      ->willReturnCallback(
        static function () use (&$config): string {
          return $config;
        },
      );
    $parentField = $this->createMock(FieldItemListInterface::class);
    $parentField->method('isEmpty')->willReturn($parentIsEmpty);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('label')->willReturn($label);
    $group->method('bundle')->willReturn('jur');
    $group->method('hasField')
      ->willReturnCallback(
        static fn(string $fieldName): bool => in_array(
          $fieldName,
          ['field_nuxt_config', 'field_parent_jurisdiction'],
          TRUE,
        ),
      );
    $group->method('get')
      ->willReturnCallback(
        static fn(string $fieldName): FieldItemListInterface => match ($fieldName) {
          'field_nuxt_config' => $configField,
          'field_parent_jurisdiction' => $parentField,
        },
      );
    $group->method('set')
      ->willReturnCallback(
        static function (string $fieldName, mixed $value) use (
          &$config,
          $group,
        ): GroupInterface {
          if ($fieldName === 'field_nuxt_config') {
            $config = (string) $value;
          }
          return $group;
        },
      );
    $group->method('save')
      ->willReturnCallback(
        static function () use (&$saveCount, $saveException): int {
          $saveCount++;
          if ($saveException !== NULL) {
            throw $saveException;
          }
          return 1;
        },
      );
    return $group;
  }

  /**
   * Creates a mutable service status term double.
   */
  protected function createTerm(
    int $id,
    int $rootId,
    string &$name,
    int &$saveCount,
  ): TermInterface {
    $jurisdictionField = $this->createMock(FieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('getValue')->willReturn([
      ['target_id' => $rootId],
    ]);
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');

    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn($id);
    $term->method('bundle')->willReturn('service_status');
    $term->method('hasField')
      ->with('field_jurisdiction')
      ->willReturn(TRUE);
    $term->method('get')
      ->with('field_jurisdiction')
      ->willReturn($jurisdictionField);
    $term->method('hasTranslation')->willReturn(FALSE);
    $term->method('getUntranslated')->willReturnSelf();
    $term->method('language')->willReturn($language);
    $term->method('getName')
      ->willReturnCallback(
        static function () use (&$name): string {
          return $name;
        },
      );
    $term->method('setName')
      ->willReturnCallback(
        static function (string $newName) use (&$name, $term): TermInterface {
          $name = $newName;
          return $term;
        },
      );
    $term->method('save')
      ->willReturnCallback(
        static function () use (&$saveCount): int {
          $saveCount++;
          return 1;
        },
      );
    return $term;
  }

}
