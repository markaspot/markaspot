<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_request_index\Unit;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_request_index\RequestIndexProjector;
use Drupal\markaspot_request_index\RequestIndexVerifier;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the request index mapping from collected facts to a record.
 *
 * @group markaspot_request_index
 */
#[CoversClass(RequestIndexProjector::class)]
#[CoversClass(RequestIndexVerifier::class)]
final class RequestIndexProjectorTest extends UnitTestCase {

  /**
   * Maps facts to row columns, child IDs and flags.
   */
  public function testComposeMapsFacts(): void {
    $record = RequestIndexProjector::compose($this->facts());

    $this->assertSame(42, $record->nid);
    $this->assertSame([12, 11], $record->organisations, 'Delta order and distinct IDs.');
    $this->assertSame([3, 7], $record->jurisdictions);
    $row = $record->row;
    $this->assertSame(12, $row['org_gid'], 'The first delta, not the lowest ID.');
    $this->assertSame(1, $row['org_multi']);
    $this->assertSame(7, $row['jur_gid']);
    $this->assertSame(3, $row['root_gid']);
    $this->assertSame(1, $row['jur_multi']);
    $this->assertSame(1, $row['published']);
    $this->assertSame(5, $row['uid']);
    $this->assertSame(21, $row['status_tid']);
    $this->assertSame(22, $row['category_tid']);
    $this->assertSame('LIGHT-3', $row['service_code']);
    $this->assertSame('open', $row['open311_mapping']);
    $this->assertSame('2026-000042', $row['request_id']);
    $this->assertSame('Beispielweg 12', $row['address_line1']);
    $this->assertSame(31, $row['district_tid']);
    $this->assertSame('playground-7', $row['facility_id']);
    $this->assertNull($row['sublocality_tid'], 'A missing optional field stays NULL.');
    $this->assertNull($row['assignee_uid']);
    $this->assertNull($row['sentiment'], 'An empty string optional value stays NULL.');
    $this->assertSame(2, $row['hazard_level']);
    $this->assertArrayNotHasKey('projected', $row);
    $this->assertArrayNotHasKey('nid', $row);
  }

  /**
   * Builds search_text from title, request ID and address only.
   */
  public function testSearchTextExcludesContactData(): void {
    $facts = $this->facts() + [
      'field_e_mail' => 'fictional.reporter@example.test',
      'field_phone' => '+00 555 0100',
      'field_first_name' => 'Fictiona',
      'field_last_name' => 'Placeholderova',
      'e_mail' => 'fictional.reporter@example.test',
    ];
    $searchText = RequestIndexProjector::compose($facts)->row['search_text'];

    $this->assertSame('Street light flickers 2026-000042 Beispielweg 12 00099 Musterstadt', $searchText);
    foreach (['example.test', '555', 'Fictiona', 'Placeholderova'] as $contact) {
      $this->assertStringNotContainsString($contact, $searchText);
    }
  }

  /**
   * Normalises whitespace, drops empty and repeated parts and truncates.
   */
  public function testComposeNormalisesValues(): void {
    $record = RequestIndexProjector::compose([
      'nid' => 1,
      'title' => "  Pothole \n near   the   school ",
      'request_id' => str_repeat('9', 80),
      'address_line1' => 'Musterstadt',
      'postal_code' => '',
      'locality' => 'Musterstadt',
      'organisations' => [0, '4', 4],
      'jurisdictions' => [],
      'published' => FALSE,
    ]);

    $this->assertSame(64, mb_strlen($record->row['request_id']));
    $this->assertSame('Pothole near the school ' . str_repeat('9', 64) . ' Musterstadt', $record->row['search_text']);
    $this->assertSame([4], $record->organisations);
    $this->assertSame(4, $record->row['org_gid']);
    $this->assertSame(0, $record->row['org_multi']);
    $this->assertSame(0, $record->row['jur_gid']);
    $this->assertSame(0, $record->row['root_gid']);
    $this->assertSame(0, $record->row['published']);
    $this->assertSame('', $record->row['service_code']);
  }

  /**
   * Picks the most specific jurisdiction through the hierarchy resolver.
   */
  public function testMostSpecificJurisdiction(): void {
    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    // Root 1 with children 2 and 3; 4 is a child of 2.
    $resolver->method('getDescendantIds')->willReturnMap([
      [1, [1, 2, 3, 4]],
      [2, [2, 4]],
      [3, [3]],
      [4, [4]],
    ]);
    $projector = new RequestIndexProjector(
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $resolver,
    );

    $this->assertSame(0, $projector->mostSpecificJurisdiction([]));
    $this->assertSame(1, $projector->mostSpecificJurisdiction([1]));
    $this->assertSame(2, $projector->mostSpecificJurisdiction([1, 2]));
    $this->assertSame(4, $projector->mostSpecificJurisdiction([2, 1, 4]));
    $this->assertSame(2, $projector->mostSpecificJurisdiction([2, 3]), 'Siblings keep delta order.');
  }

  /**
   * Names the shapes of organisation drift.
   */
  public function testDriftPattern(): void {
    $this->assertSame('relation only, field empty', RequestIndexVerifier::driftPattern([], [5]));
    $this->assertSame('field only, no relation', RequestIndexVerifier::driftPattern([5], []));
    $this->assertSame('field and relation disjoint', RequestIndexVerifier::driftPattern([5], [6]));
    $this->assertSame('relation has extra organisations', RequestIndexVerifier::driftPattern([5], [5, 6]));
    $this->assertSame('field has extra organisations', RequestIndexVerifier::driftPattern([5, 6], [5]));
    $this->assertSame('field and relation overlap partially', RequestIndexVerifier::driftPattern([5, 6], [6, 7]));
  }

  /**
   * Returns fictional facts as RequestIndexProjector::build() collects them.
   *
   * @return array<string, mixed>
   *   Facts.
   */
  private function facts(): array {
    return [
      'nid' => 42,
      'uid' => 5,
      'published' => TRUE,
      'jurisdictions' => [3, 7, 3],
      'organisations' => [12, 11, 12],
      'jur_gid' => 7,
      'root_gid' => 3,
      'status_tid' => 21,
      'category_tid' => 22,
      'service_code' => 'LIGHT-3',
      'open311_mapping' => 'open',
      'created' => 1767225600,
      'changed' => 1767312000,
      'title' => 'Street light flickers',
      'request_id' => '2026-000042',
      'address_line1' => 'Beispielweg 12',
      'postal_code' => '00099',
      'locality' => 'Musterstadt',
      'district_tid' => 31,
      'sublocality_tid' => NULL,
      'facility_id' => 'playground-7',
      'source' => NULL,
      'assignee_uid' => NULL,
      'hazard_level' => '2',
      'sentiment' => '',
    ];
  }

}
