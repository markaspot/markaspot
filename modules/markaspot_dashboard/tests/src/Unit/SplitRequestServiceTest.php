<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\markaspot_dashboard\Service\RequestLinkServiceInterface;
use Drupal\markaspot_dashboard\Service\SplitRequestService;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests SplitRequestService's pure and jurisdiction-resolution logic.
 *
 * @group markaspot_dashboard
 * @coversDefaultClass \Drupal\markaspot_dashboard\Service\SplitRequestService
 */
class SplitRequestServiceTest extends UnitTestCase {

  /**
   * Mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * Mocked GeoReport processor.
   *
   * @var \Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $processor;

  /**
   * Mocked request link service.
   *
   * @var \Drupal\markaspot_dashboard\Service\RequestLinkServiceInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $requestLinkService;

  /**
   * Mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Mocked config factory (jurisdiction_group_type lookup).
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_dashboard\Service\SplitRequestService
   */
  protected $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->processor = $this->createMock(GeoreportProcessorServiceInterface::class);
    $this->requestLinkService = $this->createMock(RequestLinkServiceInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('jurisdiction_group_type')->willReturn('jur');
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')->with('markaspot_open311.settings')->willReturn($config);

    $this->service = new SplitRequestService(
      $this->entityTypeManager,
      $this->processor,
      $this->requestLinkService,
      $this->logger,
      $this->configFactory,
    );
  }

  // ===========================================================================
  // Provenance note text (pure, DE/EN selection).
  // ===========================================================================

  /**
   * @covers ::childProvenanceNote
   */
  public function testChildProvenanceNoteGerman(): void {
    $this->assertSame(
      'Aus Meldung #88-2026 abgetrennt.',
      SplitRequestService::childProvenanceNote('de', '88-2026')
    );
  }

  /**
   * @covers ::childProvenanceNote
   */
  public function testChildProvenanceNoteEnglish(): void {
    $this->assertSame(
      'Split from report #88-2026.',
      SplitRequestService::childProvenanceNote('en', '88-2026')
    );
  }

  /**
   * @covers ::originalProvenanceNote
   */
  public function testOriginalProvenanceNoteGerman(): void {
    $this->assertSame(
      'Anliegen als Meldung #89-2026 abgetrennt.',
      SplitRequestService::originalProvenanceNote('de', '89-2026')
    );
  }

  /**
   * @covers ::originalProvenanceNote
   */
  public function testOriginalProvenanceNoteEnglish(): void {
    $this->assertSame(
      'Concern split off as report #89-2026.',
      SplitRequestService::originalProvenanceNote('en', '89-2026')
    );
  }

  /**
   * An unknown langcode falls back to English.
   *
   * Mirrors sibling status-note text selection (e.g.
   * DuplicateController::confirmDuplicate).
   *
   * @covers ::childProvenanceNote
   */
  public function testChildProvenanceNoteFallsBackToEnglishForUnknownLangcode(): void {
    $this->assertSame(
      'Split from report #1-2026.',
      SplitRequestService::childProvenanceNote('fr', '1-2026')
    );
  }

  // ===========================================================================
  // isMediaSubset (pure).
  // ===========================================================================

  /**
   * @covers ::isMediaSubset
   */
  public function testIsMediaSubsetTrueForSubset(): void {
    $this->assertTrue(SplitRequestService::isMediaSubset([45], [45, 67]));
  }

  /**
   * @covers ::isMediaSubset
   */
  public function testIsMediaSubsetTrueForEmptySelection(): void {
    $this->assertTrue(SplitRequestService::isMediaSubset([], [45, 67]));
  }

  /**
   * @covers ::isMediaSubset
   */
  public function testIsMediaSubsetTrueForExactMatch(): void {
    $this->assertTrue(SplitRequestService::isMediaSubset([45, 67], [45, 67]));
  }

  /**
   * @covers ::isMediaSubset
   */
  public function testIsMediaSubsetFalseForForeignId(): void {
    $this->assertFalse(SplitRequestService::isMediaSubset([45, 99], [45, 67]));
  }

  /**
   * @covers ::isMediaSubset
   */
  public function testIsMediaSubsetFalseWhenSourceHasNoMedia(): void {
    $this->assertFalse(SplitRequestService::isMediaSubset([45], []));
  }

  // ===========================================================================
  // isCategoryInJurisdiction.
  // ===========================================================================

  /**
   * @covers ::isCategoryInJurisdiction
   */
  public function testIsCategoryInJurisdictionMatches(): void {
    $term = $this->createMockCategoryTerm('service_category', 10);
    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termStorage->method('load')->with(123)->willReturn($term);
    $this->entityTypeManager->method('getStorage')->with('taxonomy_term')->willReturn($termStorage);

    $this->assertTrue($this->service->isCategoryInJurisdiction(123, 10));
  }

  /**
   * @covers ::isCategoryInJurisdiction
   */
  public function testIsCategoryInJurisdictionForeignJurisdictionRejected(): void {
    $term = $this->createMockCategoryTerm('service_category', 10);
    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termStorage->method('load')->with(123)->willReturn($term);
    $this->entityTypeManager->method('getStorage')->with('taxonomy_term')->willReturn($termStorage);

    // Category belongs to jurisdiction 10, but the source is in 99.
    $this->assertFalse($this->service->isCategoryInJurisdiction(123, 99));
  }

  /**
   * @covers ::isCategoryInJurisdiction
   */
  public function testIsCategoryInJurisdictionWrongBundleRejected(): void {
    $term = $this->createMockCategoryTerm('service_status', 10);
    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termStorage->method('load')->with(123)->willReturn($term);
    $this->entityTypeManager->method('getStorage')->with('taxonomy_term')->willReturn($termStorage);

    $this->assertFalse($this->service->isCategoryInJurisdiction(123, 10));
  }

  /**
   * @covers ::isCategoryInJurisdiction
   */
  public function testIsCategoryInJurisdictionMissingTermRejected(): void {
    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termStorage->method('load')->with(999)->willReturn(NULL);
    $this->entityTypeManager->method('getStorage')->with('taxonomy_term')->willReturn($termStorage);

    $this->assertFalse($this->service->isCategoryInJurisdiction(999, 10));
  }

  // ===========================================================================
  // resolveJurisdictionForNode.
  // ===========================================================================

  /**
   * @covers ::resolveJurisdictionForNode
   */
  public function testResolveJurisdictionForNodeViaRelationship(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('id')->willReturn(10);

    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->method('getGroup')->willReturn($group);

    $relationshipStorage = $this->createMock(EntityStorageInterface::class);
    $relationshipStorage->method('loadByProperties')
      ->with([
        'entity_id' => 88,
        'plugin_id' => 'group_node:service_request',
      ])
      ->willReturn([$relationship]);

    $this->entityTypeManager->method('getStorage')
      ->with('group_relationship')
      ->willReturn($relationshipStorage);

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(88);

    $this->assertSame(10, $this->service->resolveJurisdictionForNode($node));
  }

  /**
   * @covers ::resolveJurisdictionForNode
   */
  public function testResolveJurisdictionForNodeFallsBackToFieldJurisdiction(): void {
    $relationshipStorage = $this->createMock(EntityStorageInterface::class);
    $relationshipStorage->method('loadByProperties')->willReturn([]);
    $this->entityTypeManager->method('getStorage')
      ->with('group_relationship')
      ->willReturn($relationshipStorage);

    $jurField = new class() {

      /**
       * The referenced jurisdiction group ID.
       *
       * @var int
       */
      // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
      public int $target_id = 10;

      /**
       * Checks whether the field item is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(88);
    $node->method('hasField')->with('field_jurisdiction')->willReturn(TRUE);
    $node->method('get')->with('field_jurisdiction')->willReturn($jurField);

    $this->assertSame(10, $this->service->resolveJurisdictionForNode($node));
  }

  /**
   * @covers ::resolveJurisdictionForNode
   */
  public function testResolveJurisdictionForNodeReturnsNullWhenUnresolvable(): void {
    $relationshipStorage = $this->createMock(EntityStorageInterface::class);
    $relationshipStorage->method('loadByProperties')->willReturn([]);
    $this->entityTypeManager->method('getStorage')
      ->with('group_relationship')
      ->willReturn($relationshipStorage);

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(88);
    $node->method('hasField')->with('field_jurisdiction')->willReturn(FALSE);

    $this->assertNull($this->service->resolveJurisdictionForNode($node));
  }

  /**
   * Builds a mock service_category taxonomy term.
   */
  private function createMockCategoryTerm(string $bundle, int $jurisdictionId): TermInterface {
    $term = $this->createMock(TermInterface::class);
    $term->method('bundle')->willReturn($bundle);

    $jurField = new class($jurisdictionId) {

      /**
       * The referenced jurisdiction group ID.
       *
       * @var int
       */
      // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
      public int $target_id;

      /**
       * Constructs a field item stub.
       */
      public function __construct(int $targetId) {
        $this->target_id = $targetId;
      }

      /**
       * Checks whether the field item is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $term->method('hasField')->with('field_jurisdiction')->willReturn(TRUE);
    $term->method('get')->with('field_jurisdiction')->willReturn($jurField);

    return $term;
  }

}
