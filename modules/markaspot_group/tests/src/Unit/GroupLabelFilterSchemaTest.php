<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the configuration schema for the labelled Group filter.
 *
 * @group markaspot_group
 */
final class GroupLabelFilterSchemaTest extends UnitTestCase {

  /**
   * Tests the custom filter value inherits the numeric value structure.
   */
  public function testNumericValueSchemaIsDeclared(): void {
    $schemaPath = dirname(__DIR__, 3) . '/config/schema/markaspot_group.views.schema.yml';
    $schema = Yaml::parseFile($schemaPath);

    $this->assertSame(
      'views.filter_value.numeric',
      $schema['views.filter_value.markaspot_group_label']['type'] ?? NULL,
    );
  }

}
