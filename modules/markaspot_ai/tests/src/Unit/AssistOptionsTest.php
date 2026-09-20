<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_ai\Service\AttributeFillingService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../../markaspot_ai.module';

/**
 * Proves AI options and model responses cannot escape the resolved scope.
 */
#[Group('markaspot_ai')]
final class AssistOptionsTest extends UnitTestCase {

  /**
   * The real resolver rejects missing, unpublished and non-jurisdiction roots.
   */
  public function testCanonicalRootValidation(): void {
    $cases = [
      ['jur', TRUE, 9],
      ['jur', FALSE, NULL],
      ['org', TRUE, NULL],
      [NULL, FALSE, NULL],
    ];
    foreach ($cases as [$bundle, $published, $expected]) {
      $service = $this->getMockBuilder(AttributeFillingService::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('isEmpty')->willReturn(FALSE);
      $field->method('getValue')->willReturn([['target_id' => 42]]);
      $node = $this->createMock(NodeInterface::class);
      $node->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_jurisdiction');
      $node->method('get')->with('field_jurisdiction')->willReturn($field);
      $direct = $this->createMock(GroupInterface::class);
      $direct->method('bundle')->willReturn('jur');
      $direct->method('isPublished')->willReturn(TRUE);
      $root = NULL;
      if ($bundle !== NULL) {
        $root = $this->createMock(GroupInterface::class);
        $root->method('bundle')->willReturn($bundle);
        $root->method('isPublished')->willReturn($published);
      }
      $storage = $this->createMock(EntityStorageInterface::class);
      $storage->method('load')->willReturnMap([[42, $direct], [9, $root]]);
      $manager = $this->createMock(EntityTypeManagerInterface::class);
      $manager->method('getStorage')->with('group')->willReturn($storage);
      $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
      $resolver->method('getRootJurisdictionId')->with(42)->willReturn(9);
      (new \ReflectionProperty($service, 'entityTypeManager'))->setValue($service, $manager);
      (new \ReflectionProperty($service, 'hierarchyResolver'))->setValue($service, $resolver);
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('get')->with('jurisdiction_group_type')->willReturn('jur');
      $factory = $this->createMock(ConfigFactoryInterface::class);
      $factory->method('get')->with('markaspot_open311.settings')->willReturn($config);
      (new \ReflectionProperty($service, 'configFactory'))->setValue($service, $factory);
      $this->assertSame($expected, (new \ReflectionMethod($service, 'resolveAssistRootId'))->invoke($service, $node));
    }
  }

  /**
   * Missing jurisdiction must not trigger any global entity discovery.
   */
  public function testMissingScopeDoesNotLoadOptions(): void {
    $service = $this->getMockBuilder(AttributeFillingService::class)
      ->disableOriginalConstructor()->onlyMethods(['resolveAssistRootId'])->getMock();
    $service->method('resolveAssistRootId')->willReturn(NULL);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->never())->method('getStorage');
    (new \ReflectionProperty($service, 'entityTypeManager'))->setValue($service, $manager);
    $node = $this->createMock(NodeInterface::class);
    foreach (['loadOrganisationOptions', 'loadStatusOptions'] as $method) {
      $this->assertSame([], (new \ReflectionMethod($service, $method))->invoke($service, $node));
    }
  }

  /**
   * Empty scoped catalogues must not be replaced by other tenants' entries.
   */
  public function testEmptyCataloguesStayScoped(): void {
    $service = $this->getMockBuilder(AttributeFillingService::class)
      ->disableOriginalConstructor()->onlyMethods(['resolveAssistRootId'])->getMock();
    $service->method('resolveAssistRootId')->willReturn(42);
    $orgs = $this->createMock(EntityStorageInterface::class);
    $orgs->expects($this->once())->method('loadByProperties')
      ->with(['type' => 'org', 'status' => 1, 'field_jurisdiction' => 42])->willReturn([]);
    $statuses = $this->createMock(EntityStorageInterface::class);
    $statuses->expects($this->once())->method('loadByProperties')
      ->with(['vid' => 'service_status', 'status' => 1, 'field_jurisdiction' => 42])->willReturn([]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([['group', $orgs], ['taxonomy_term', $statuses]]);
    (new \ReflectionProperty($service, 'entityTypeManager'))->setValue($service, $manager);
    $node = $this->createMock(NodeInterface::class);
    foreach (['buildOrganisationOptions', 'buildStatusOptions'] as $method) {
      $this->assertSame('', (new \ReflectionMethod($service, $method))->invoke($service, $node, 'de'));
    }
  }

  /**
   * A model response cannot inject valid UUIDs from another jurisdiction.
   */
  public function testResponseRejectsForeignIdsAndAcceptsLocalIds(): void {
    $service = $this->getMockBuilder(AttributeFillingService::class)
      ->disableOriginalConstructor()->onlyMethods(['loadOrganisationOptions', 'loadStatusOptions'])->getMock();
    $org = $this->createMock(GroupInterface::class);
    $org->method('uuid')->willReturn('local-org');
    $status = $this->createMock(TermInterface::class);
    $status->method('uuid')->willReturn('local-status');
    $service->method('loadOrganisationOptions')->willReturn([$org]);
    $service->method('loadStatusOptions')->willReturn([$status]);
    (new \ReflectionProperty($service, 'logger'))->setValue($service, $this->createMock(LoggerInterface::class));
    $node = $this->createMock(NodeInterface::class);
    $method = new \ReflectionMethod($service, 'validateAssistResponse');
    $foreign = $method->invoke($service, [
      'organisation' => 'foreign-org',
      'status_term_id' => 'foreign-status',
      'status_note' => 'Checked',
    ], ['organisation', 'status_note'], [], $node);
    $this->assertSame(['status_note' => 'Checked'], $foreign);
    $local = $method->invoke($service, [
      'organisation' => 'local-org',
      'status_term_id' => 'local-status',
      'status_note' => 'Checked',
    ], ['organisation', 'status_note'], [], $node);
    $this->assertSame(['organisation' => 'local-org', 'status_note' => 'Checked', 'status_term_id' => 'local-status'], $local);
  }

}
