<?php

namespace Drupal\Tests\markaspot_tenant_import\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_tenant_import\Service\TenantBoilerplates;
use Drupal\node\NodeStorageInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Rejects malformed ownership before touching content.
 */
#[Group('markaspot_tenant_import')]
final class TenantBoilerplatesTest extends UnitTestCase {

  /**
   * Invalid state must never silently create or adopt nodes.
   */
  #[DataProvider('invalidOwnership')]
  public function testInvalidOwnership(mixed $record): void {
    $storage = $this->createMock(NodeStorageInterface::class);
    $storage->expects($this->never())->method('loadByProperties');
    $storage->expects($this->never())->method('create');
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('node')->willReturn($storage);
    $jurisdiction = $this->createMock(GroupInterface::class);
    $jurisdiction->method('uuid')->willReturn('12345678-1234-1234-1234-123456789abc');
    $ownership = $this->createMock(KeyValueStoreInterface::class);
    $ownership->method('get')->willReturn($record);
    $ownership->expects($this->never())->method('set');
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Invalid boilerplate ownership record.');
    TenantBoilerplates::apply([], $jurisdiction, $entities, $ownership, 'de');
  }

  /**
   * Malformed records, including formerly accepted null and array values.
   */
  public static function invalidOwnership(): array {
    return [
      'scalar' => ['invalid'],
      'null UUID' => [['received' => NULL]],
      'array UUID' => [['received' => ['12345678-1234-1234-1234-123456789abc']]],
      'invalid UUID' => [['received' => 'not-a-uuid']],
      'invalid key' => [['INVALID' => '12345678-1234-1234-1234-123456789abc']],
      'numeric key' => [[0 => '12345678-1234-1234-1234-123456789abc']],
    ];
  }

}
