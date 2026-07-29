<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\markaspot_open311\Service\StatusClassifier;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests service-status Open311 classification.
 */
#[CoversClass(\Drupal\markaspot_open311\Service\StatusClassifier::class)]
#[Group('markaspot_open311')]
final class StatusClassifierTest extends UnitTestCase {

  /**
   * Term mappings override conflicting legacy configuration.
   */
  #[DataProvider('termMappingProvider')]
  public function testTermFieldWinsOverLegacyMap(
    string $mapping,
    array $statusOpen,
    bool $expectedClosed,
  ): void {
    $term = $this->buildTerm(7, $mapping);
    $classifier = $this->buildClassifier(
      statusOpen: $statusOpen,
      loadedTerms: [7 => $term],
    );

    $this->assertSame($expectedClosed, $classifier->isClosed($term));
  }

  /**
   * Provides all supported term mappings.
   */
  public static function termMappingProvider(): array {
    return [
      'closed overrides legacy open' => ['closed', [7 => '7'], TRUE],
      'open overrides legacy default closed' => ['open', [], FALSE],
      'initial overrides legacy default closed' => ['initial', [], FALSE],
    ];
  }

  /**
   * Empty mappings retain the processor's legacy status_open fallback.
   */
  public function testEmptyFieldFallsBackToLegacyOpenMap(): void {
    $legacy_open = $this->buildTerm(7, NULL);
    $legacy_default = $this->buildTerm(8, NULL);
    $classifier = $this->buildClassifier(
      statusOpen: [7 => '7'],
      loadedTerms: [
        7 => $legacy_open,
        8 => $legacy_default,
      ],
    );

    $this->assertFalse($classifier->isClosed($legacy_open));
    $this->assertTrue($classifier->isClosed($legacy_default));
  }

  /**
   * Missing terms retain the historical default-closed behavior.
   */
  public function testUnknownTermDefaultsToClosed(): void {
    $classifier = $this->buildClassifier(statusOpen: []);

    $this->assertTrue($classifier->isClosed(99));
  }

  /**
   * Closed IDs include term semantics and the legacy safety net.
   */
  public function testClosedTidsUnionsTermMappingsAndLegacyMap(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->expects($this->exactly(2))
      ->method('condition')
      ->willReturnCallback(function (string $field, mixed $value) use ($query): QueryInterface {
        $this->assertContains([$field, $value], [
          ['vid', 'service_status'],
          ['field_open311_mapping', 'closed'],
        ]);
        return $query;
      });
    $query->method('execute')->willReturn([4 => '4', 6 => '6']);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->once())
      ->method('set')
      ->with(
        'markaspot_open311:closed_status_tids',
        [4, 6, 30],
        Cache::PERMANENT,
        [
          'taxonomy_term_list:service_status',
          'config:markaspot_open311.settings',
        ],
      );

    $classifier = $this->buildClassifier(
      statusClosed: [6 => '6', 30 => '30'],
      query: $query,
      cache: $cache,
    );

    $this->assertSame([4, 6, 30], $classifier->closedTids());
  }

  /**
   * Builds the classifier with isolated entity, config, and cache doubles.
   *
   * @param array<int|string, int|string> $statusOpen
   *   Legacy open map.
   * @param array<int|string, int|string> $statusClosed
   *   Legacy closed map.
   * @param array<int, \Drupal\taxonomy\TermInterface> $loadedTerms
   *   Terms returned for integer lookups.
   * @param \Drupal\Core\Entity\Query\QueryInterface|null $query
   *   Entity query double for the closed-mapping lookup, or NULL for none.
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   Cache backend double, or NULL for a pass-through stub.
   */
  private function buildClassifier(
    array $statusOpen = [],
    array $statusClosed = [],
    array $loadedTerms = [],
    ?QueryInterface $query = NULL,
    ?CacheBackendInterface $cache = NULL,
  ): StatusClassifier {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn (string $key): mixed => match ($key) {
        'status_open' => $statusOpen,
        'status_closed' => $statusClosed,
        default => NULL,
      },
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($config);

    $term_storage = $this->createMock(EntityStorageInterface::class);
    $term_storage->method('load')
      ->willReturnCallback(static fn (int $tid): ?TermInterface => $loadedTerms[$tid] ?? NULL);
    if ($query !== NULL) {
      $term_storage->method('getQuery')->willReturn($query);
    }

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->with('taxonomy_term')
      ->willReturn($term_storage);

    if ($cache === NULL) {
      $cache = $this->createMock(CacheBackendInterface::class);
      $cache->method('get')->willReturn(FALSE);
    }

    return new StatusClassifier($config_factory, $entity_type_manager, $cache);
  }

  /**
   * Builds a service_status term with an optional mapping value.
   */
  private function buildTerm(int $tid, ?string $mapping): TermInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($mapping === NULL);
    $field->method('getValue')->willReturn(
      $mapping === NULL ? [] : [['value' => $mapping]],
    );

    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn($tid);
    $term->method('hasField')
      ->with('field_open311_mapping')
      ->willReturn(TRUE);
    $term->method('get')
      ->with('field_open311_mapping')
      ->willReturn($field);
    return $term;
  }

}
