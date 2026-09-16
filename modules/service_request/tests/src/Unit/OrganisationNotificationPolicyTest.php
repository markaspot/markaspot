<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Unit;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\service_request\OrganisationNotificationPolicy;
use Drupal\Tests\UnitTestCase;

/**
 * Tests explicit per-organisation opt-outs and backwards-compatible defaults.
 */
final class OrganisationNotificationPolicyTest extends UnitTestCase {

  /**
   * Tests missing, empty, and explicitly configured boolean values.
   */
  public function testLegacyDefaultsAndExplicitValues(): void {
    $missing = $this->createMock(FieldableEntityInterface::class);
    $missing->method('hasField')->willReturn(FALSE);
    $this->assertTrue(OrganisationNotificationPolicy::isEnabled($missing));
    foreach ([NULL, TRUE, FALSE, 1, 0, '1', '0'] as $value) {
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('isEmpty')->willReturn($value === NULL);
      $field->method('__get')->with('value')->willReturn($value);
      $group = $this->createMock(FieldableEntityInterface::class);
      $group->method('hasField')->willReturn(TRUE);
      $group->method('get')->willReturn($field);
      $this->assertSame(!in_array($value, [FALSE, 0, '0'], TRUE), OrganisationNotificationPolicy::isEnabled($group));
    }
  }

}
