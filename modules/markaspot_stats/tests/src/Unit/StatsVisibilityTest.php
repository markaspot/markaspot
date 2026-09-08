<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_stats\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\markaspot_group\Service\FormOnlyReportQueryScope;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_stats\Controller\StatsController;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests every statistics controller path applies the shared report SQL scope.
 *
 * @group markaspot_stats
 */
class StatsVisibilityTest extends UnitTestCase {

  /**
   * Both flat endpoints constrain the node JOIN, retaining empty term counts.
   */
  public function testFlatStatsApplyReportRestriction(): void {
    foreach (['getStatusStats', 'getCategoryStats'] as $method) {
      $database = $this->createMock(Connection::class);
      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchAll')->willReturn([]);
      $database->expects($this->once())->method('query')
        ->willReturnCallback(function (string $sql) use ($statement): StatementInterface {
          $this->assertStringContainsString("n.type = 'service_request' AND n.nid = 0", preg_replace('/\s+/', ' ', $sql));
          return $statement;
        });
      $response = $this->controller($database)->$method(new Request());
      $this->assertSame('[]', $response->getContent());
      $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }
  }

  /**
   * The hierarchical endpoint also constrains its independent count query.
   */
  public function testHierarchicalStatsApplyReportRestriction(): void {
    $database = $this->createMock(Connection::class);
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn([]);
    $statement->method('fetchAllAssoc')->willReturn([]);
    $query = $this->createMock(SelectInterface::class);
    foreach (['fields', 'condition', 'groupBy'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('execute')->willReturn($statement);
    $node_join_seen = FALSE;
    $query->method('leftJoin')->willReturnCallback(function (string $table, string $alias, string $condition) use (&$node_join_seen): string {
      if ($table === 'node_field_data') {
        $node_join_seen = TRUE;
        $this->assertSame('fc.entity_id = n.nid AND n.type = :type AND n.nid = 0', $condition);
      }
      return $alias;
    });
    $database->method('select')->willReturn($query);
    $response = $this->controller($database)->getHierarchicalCategoryStats(new Request());
    $this->assertTrue($node_join_seen);
    $this->assertSame('[]', $response->getContent());
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
  }

  /**
   * Builds a controller with a restrictive query policy and anonymous viewer.
   */
  private function controller(Connection $database): StatsController {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->method('getCurrentLanguage')->willReturn($language);
    $scope = $this->createMock(FormOnlyReportQueryScope::class);
    $scope->expects($this->once())->method('getSqlRestrictionForRequest')->willReturn(' AND n.nid = 0');
    $container = new ContainerBuilder();
    $container->set('current_user', new AnonymousUserSession());
    \Drupal::setContainer($container);
    return new StatsController($database, $language_manager, $this->createMock(JurisdictionHierarchyResolverInterface::class), $scope);
  }

}
