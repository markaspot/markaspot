<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestIndexResource;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the field-filter allowlist on the GeoReport requests index.
 *
 * The endpoint is reachable anonymously. The pre-existing handler used a
 * substring match (str_contains($key, 'field_')) that accepted arbitrary
 * field names, enabling schema probing via the entity query layer. This
 * test pins the strict allowlist behaviour so it cannot regress.
 *
 * @group markaspot_open311
 *
 * @coversDefaultClass \Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestIndexResource
 */
class GeoreportFieldFilterAllowlistTest extends UnitTestCase {

  /**
   * Keys explicitly intended as filterable.
   *
   * @covers ::filterAllowedFieldParameters
   */
  public function testAllowlistedKeysPassThrough(): void {
    $input = [
      'field_status' => '12',
      'field_category' => '7',
      'field_district' => '3',
      'field_sublocality' => '42',
      'field_hazard_level' => '2',
      'field_facility' => 'fac-7',
    ];
    $this->assertSame(
      $input,
      GeoreportRequestIndexResource::filterAllowedFieldParameters($input)
    );
  }

  /**
   * Array-shaped values (e.g. `?field_status[]=1&field_status[]=2`) pass
   * through unmodified — the helper only filters by key, not by value.
   *
   * @covers ::filterAllowedFieldParameters
   */
  public function testArrayValuesPassThrough(): void {
    $input = [
      'field_status' => ['1', '2'],
      'field_internal_remark' => ['x'],
    ];
    $this->assertSame(
      ['field_status' => ['1', '2']],
      GeoreportRequestIndexResource::filterAllowedFieldParameters($input)
    );
  }

  /**
   * Probe-style keys must NOT pass even though they match field_*.
   *
   * @covers ::filterAllowedFieldParameters
   */
  public function testNonAllowlistedFieldKeysAreDropped(): void {
    $input = [
      'field_status' => '12',
      'field_author' => '1',
      'field_internal_remark' => 'x',
      'field_internal_phone' => '0228',
      'field_internal_orga' => 'amt',
      'field_email' => 'a@b.de',
      'field_anything' => 'probe',
    ];
    $this->assertSame(
      ['field_status' => '12'],
      GeoreportRequestIndexResource::filterAllowedFieldParameters($input)
    );
  }

  /**
   * Non-field keys are passed through untouched (handled elsewhere).
   *
   * The filter only governs the field_* surface; other Open311 params
   * (service_code, status, start_date, …) and the bbox/q filters live
   * in their own code paths. The allowlist should not interfere with
   * how those keys are seen by the caller.
   *
   * @covers ::filterAllowedFieldParameters
   */
  public function testNonFieldKeysAreDropped(): void {
    // The allowlist filters down to field_* keys it knows. Non-field keys
    // are also dropped here; their handling is the caller's responsibility.
    $input = [
      'service_code' => 'rad',
      'status' => 'open',
      'start_date' => '2025-01-01',
      'q' => 'foo',
      'bbox' => '1,2,3,4',
    ];
    $this->assertSame(
      [],
      GeoreportRequestIndexResource::filterAllowedFieldParameters($input)
    );
  }

  /**
   * Empty input yields empty output (no error, no warning).
   *
   * @covers ::filterAllowedFieldParameters
   */
  public function testEmptyInput(): void {
    $this->assertSame(
      [],
      GeoreportRequestIndexResource::filterAllowedFieldParameters([])
    );
  }

  /**
   * Comma-separated values from the multi-select UI are split into an array
   * so the caller can apply IN semantics.
   *
   * @covers ::filterAllowedFieldParameters
   */
  public function testCommaSeparatedValuesAreSplit(): void {
    $input = [
      'field_district' => '148,149,150',
      'field_status' => '12',
      'field_sublocality' => '42, 43, 44',
    ];
    $result = GeoreportRequestIndexResource::filterAllowedFieldParameters($input);
    $this->assertSame(['148', '149', '150'], $result['field_district']);
    $this->assertSame('12', $result['field_status']);
    $this->assertSame(['42', '43', '44'], $result['field_sublocality'], 'Whitespace around comma should be trimmed.');
  }

  /**
   * Multi-value arrays are deduplicated and stripped of empty/whitespace.
   *
   * @covers ::filterAllowedFieldParameters
   */
  public function testMultiValueNormalisation(): void {
    $input = [
      'field_district' => '148,148,148,,  ,149',
      'field_status' => ['1', '2', '2', '', '   ', '3'],
    ];
    $result = GeoreportRequestIndexResource::filterAllowedFieldParameters($input);
    $this->assertSame(['148', '149'], $result['field_district']);
    $this->assertSame(['1', '2', '3'], $result['field_status']);
  }

  /**
   * Hard cap protects the entity query from oversized IN clauses.
   *
   * @covers ::filterAllowedFieldParameters
   */
  public function testMultiValueCap(): void {
    $values = array_map('strval', range(1, 100));
    $input = ['field_district' => implode(',', $values)];
    $result = GeoreportRequestIndexResource::filterAllowedFieldParameters($input);
    $this->assertCount(50, $result['field_district']);
    // Order is preserved up to the cap.
    $this->assertSame('1', $result['field_district'][0]);
    $this->assertSame('50', $result['field_district'][49]);
  }

  /**
   * Pure comma / whitespace yields nothing (no spurious empty-string IN).
   *
   * @covers ::filterAllowedFieldParameters
   */
  public function testEmptyAfterSplitIsDropped(): void {
    $input = [
      'field_district' => ',,  , ,',
      'field_status' => '12',
    ];
    $this->assertSame(
      ['field_status' => '12'],
      GeoreportRequestIndexResource::filterAllowedFieldParameters($input)
    );
  }

  /**
   * Non-string scalars (ints, bools) and objects are dropped — the public
   * GET surface only carries strings, and unexpected shapes should not
   * silently round-trip into the entity query.
   *
   * @covers ::filterAllowedFieldParameters
   */
  public function testNonStringScalarsAreDropped(): void {
    $input = [
      'field_status' => 12,
      'field_category' => true,
      'field_district' => new \stdClass(),
      'field_sublocality' => '42',
    ];
    $this->assertSame(
      ['field_sublocality' => '42'],
      GeoreportRequestIndexResource::filterAllowedFieldParameters($input)
    );
  }

}
