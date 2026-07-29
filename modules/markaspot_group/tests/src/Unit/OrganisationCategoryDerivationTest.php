<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolver;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require_once dirname(__DIR__, 3) . '/src/Service/JurisdictionHierarchyResolver.php';
require_once dirname(__DIR__, 3) . '/markaspot_group.module';

/**
 * Tests organisation category derivation ambiguity semantics.
 */
#[Group('markaspot_group')]
final class OrganisationCategoryDerivationTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Tests own-jurisdiction ambiguity keeps legacy first-match routing.
   */
  public function testOwnJurisdictionAmbiguityUsesFirstMatch(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Using first match'),
        $this->callback(
          static fn(array $context): bool => $context['@org_id'] === 11,
        ),
      );
    $this->setContainer([[11 => 11, 12 => 12]], [], $logger);

    $this->assertSame(11, _markaspot_group_derive_org_from_category(
      $this->requestWithCategory(55),
      9,
    ));
  }

  /**
   * Tests ambiguity remains strict only at newly searched ancestor levels.
   */
  public function testAncestorAmbiguityDoesNotGuess(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $warnings = [];
    $logger->expects($this->exactly(2))
      ->method('warning')
      ->willReturnCallback(
        static function (string $message, array $context) use (&$warnings): void {
          $warnings[] = [$message, $context];
        },
      );
    $this->setContainer(
      [[], [21 => 21, 22 => 22]],
      [6],
      $logger,
    );

    $this->assertNull(_markaspot_group_derive_org_from_category(
      $this->requestWithCategory(55),
      9,
    ));
    $this->assertStringContainsString(
      'Skipping automatic assignment',
      $warnings[0][0],
    );
    $this->assertSame(6, $warnings[0][1]['@jurisdiction']);
  }

  /**
   * Sets the reduced Drupal container used by the derivation helper.
   *
   * @param array<int, array<int, int>> $queryResults
   *   Entity query results in execution order.
   * @param int[] $ancestorIds
   *   Ancestor jurisdiction IDs.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger test double.
   */
  private function setContainer(
    array $queryResults,
    array $ancestorIds,
    LoggerInterface $logger,
  ): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturnOnConsecutiveCalls(...$queryResults);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($storage);

    $resolver = $this->createMock(JurisdictionHierarchyResolver::class);
    $resolver->method('getAncestorIds')->willReturn($ancestorIds);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_group')
      ->willReturn($logger);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entityTypeManager);
    $container->set('markaspot_group.hierarchy_resolver', $resolver);
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);
  }

  /**
   * Creates a service request with a category term.
   */
  private function requestWithCategory(int $termId): NodeInterface {
    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn($termId);
    $term->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => $name !== 'field_category_gid');

    $category = $this->createMock(FieldItemListInterface::class);
    $category->method('isEmpty')->willReturn(FALSE);
    $category->method('__get')->with('entity')->willReturn($term);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_category')->willReturn(TRUE);
    $node->method('get')->with('field_category')->willReturn($category);
    return $node;
  }

}
