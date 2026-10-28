<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_request_index\Kernel;

use Drupal\markaspot_request_index_test\Hook\RequestIndexTestHooks;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the index holds what was stored, not what is in memory.
 *
 * Another module's insert or update hook may change the saved entity object
 * after the storage write without saving it again. That value never reaches
 * the database, so it must never reach the index either.
 *
 * @group markaspot_request_index
 */
#[RunTestsInSeparateProcesses]
final class RequestIndexStoredStateKernelTest extends RequestIndexKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['markaspot_request_index_test'];

  /**
   * An insert changed in memory after the write projects the stored values.
   */
  public function testInsertProjectsStoredValues(): void {
    $this->container->get('state')->set(RequestIndexTestHooks::STATE_KEY, 'insert');
    $node = $this->createRequest(['field_facility' => 'stored-facility']);
    $nid = (int) $node->id();

    $this->assertSame(RequestIndexTestHooks::FACILITY, $node->get('field_facility')->value, 'The test hook changed the saved object.');
    $stored = $this->storedRequest($nid);
    $this->assertSame('stored-facility', $stored->get('field_facility')->value, 'Nothing saved the in-memory change.');
    $this->assertSame('Bench with a loose slat', $stored->getTitle());

    $row = $this->row($nid);
    $this->assertSame('stored-facility', $row['facility_id']);
    $this->assertStringContainsString('Bench with a loose slat', $row['search_text']);
    $this->assertStringNotContainsString(RequestIndexTestHooks::TITLE, $row['search_text']);
    $this->assertSame([], $this->container->get('markaspot_request_index.verifier')->diff(0)['differences']);
  }

  /**
   * An update changed in memory after the write projects the stored values.
   */
  public function testUpdateProjectsStoredValues(): void {
    $nid = (int) $this->createRequest(['field_facility' => 'stored-facility'])->id();
    $this->container->get('state')->set(RequestIndexTestHooks::STATE_KEY, 'update');

    $node = $this->storedRequest($nid);
    $node->set('field_facility', 'updated-facility');
    $node->set('field_address', [
      'country_code' => 'DE',
      'address_line1' => 'Probeallee 3',
      'postal_code' => '00098',
      'locality' => 'Musterstadt',
    ]);
    $node->save();

    $this->assertSame(RequestIndexTestHooks::FACILITY, $node->get('field_facility')->value, 'The test hook changed the saved object.');
    $stored = $this->storedRequest($nid);
    $this->assertSame('updated-facility', $stored->get('field_facility')->value, 'Nothing saved the in-memory change.');

    $row = $this->row($nid);
    $this->assertSame('updated-facility', $row['facility_id']);
    $this->assertSame('Probeallee 3', $row['address_line1']);
    $this->assertStringNotContainsString(RequestIndexTestHooks::TITLE, $row['search_text']);
    $this->assertSame([], $this->container->get('markaspot_request_index.verifier')->diff(0)['differences']);
  }

  /**
   * Loads a request from storage, bypassing every entity cache.
   */
  private function storedRequest(int $nid): NodeInterface {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($nid);
    $this->assertInstanceOf(NodeInterface::class, $node);
    return $node;
  }

}
