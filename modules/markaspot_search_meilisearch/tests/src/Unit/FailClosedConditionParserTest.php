<?php

namespace Drupal\Tests\markaspot_search_meilisearch\Unit;

use Drupal\markaspot_search_meilisearch\Parser\FailClosedConditionParser;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\Condition;
use Drupal\search_api\Query\ConditionGroup;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_meilisearch\Parser\BetweenOperatorParser;
use Drupal\search_api_meilisearch\Parser\InOperatorParser;
use Drupal\search_api_meilisearch\Parser\NotBetweenOperatorParser;
use Drupal\search_api_meilisearch\Parser\NotInOperatorParser;
use Drupal\search_api_meilisearch\Parser\NullValueParser;
use Drupal\search_api_meilisearch\Parser\ScalarValueParser;
use Drupal\search_api_meilisearch\Parser\StringExpressionFilterParser;
use Drupal\Tests\UnitTestCase;

/**
 * Tests that untranslatable conditions abort the search instead of vanishing.
 *
 * @group markaspot_search_meilisearch
 * @coversDefaultClass \Drupal\markaspot_search_meilisearch\Parser\FailClosedConditionParser
 */
class FailClosedConditionParserTest extends UnitTestCase {

  /**
   * Builds the contributed filter parser with the fail-closed parser added.
   */
  protected function buildFilterParser(): StringExpressionFilterParser {
    $parser = new StringExpressionFilterParser();
    // Same priorities as search_api_meilisearch.services.yml and this module.
    $parser->addConditionParser(new ScalarValueParser(), 10);
    $parser->addConditionParser(new NullValueParser(), 10);
    $parser->addConditionParser(new BetweenOperatorParser(), 10);
    $parser->addConditionParser(new NotBetweenOperatorParser(), 10);
    $parser->addConditionParser(new InOperatorParser(), 10);
    $parser->addConditionParser(new NotInOperatorParser(), 10);
    $parser->addConditionParser(new FailClosedConditionParser(), -1000);
    return $parser;
  }

  /**
   * Translatable conditions keep the contributed expression unchanged.
   */
  public function testTranslatableConditionsPassThrough(): void {
    $group = new ConditionGroup('AND');
    $group->addCondition('status', 1);
    $grants = new ConditionGroup('OR');
    $grants->addCondition('node_grants', 'node_access__all');
    $grants->addCondition('node_grants', 'node_access_markaspot_jurisdiction:1');
    $group->addConditionGroup($grants);
    $group->addCondition('nid', [3, 5], 'IN');

    $expression = $this->buildFilterParser()->parseExpression($group, $this->createMock(IndexInterface::class));

    $this->assertStringContainsString('status = 1', $expression);
    $this->assertStringContainsString('node_grants = "node_access__all"', $expression);
    $this->assertStringContainsString('nid = 3', $expression);
  }

  /**
   * An empty IN list used to vanish and widen an AND group to everything.
   */
  public function testEmptyInListAbortsTheSearch(): void {
    $group = new ConditionGroup('AND');
    $group->addCondition('status', 1);
    $group->addCondition('nid', [], 'IN');

    $this->expectException(SearchApiException::class);
    $this->expectExceptionMessage('"nid"');
    $this->buildFilterParser()->parseExpression($group, $this->createMock(IndexInterface::class));
  }

  /**
   * A dropped OR branch would narrow or widen access groups unpredictably.
   */
  public function testUntranslatableConditionInOrGroupAbortsTheSearch(): void {
    $group = new ConditionGroup('OR');
    $group->addCondition('node_grants', 'node_access__all');
    $group->addCondition('node_grants', ['a', 'b'], '=');

    $this->expectException(SearchApiException::class);
    $this->buildFilterParser()->parseExpression($group, $this->createMock(IndexInterface::class));
  }

  /**
   * The message names field and operator, never the value.
   *
   * @covers ::parse
   */
  public function testMessageCarriesNoValue(): void {
    try {
      (new FailClosedConditionParser())->parse(new Condition('field_e_mail', ['someone@example.org'], 'IN'), $this->createMock(IndexInterface::class));
      $this->fail('No exception thrown.');
    }
    catch (SearchApiException $e) {
      $this->assertStringContainsString('field_e_mail', $e->getMessage());
      $this->assertStringNotContainsString('someone@example.org', $e->getMessage());
    }
  }

}
