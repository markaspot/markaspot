<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Demo\DemoWorkspace;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the demo block contract helpers.
 */
#[CoversClass(DemoWorkspace::class)]
#[Group('markaspot_fastmap')]
final class DemoWorkspaceTest extends UnitTestCase {

  /**
   * Seed UUIDs are stable, scoped and valid UUID strings.
   */
  public function testUuidIsDeterministicAndScoped(): void {
    $uuid = DemoWorkspace::uuid('site-a', 'demo-x', 'report', 'one');

    $this->assertSame($uuid, DemoWorkspace::uuid('site-a', 'demo-x', 'report', 'one'));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-a[0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
    $this->assertNotSame($uuid, DemoWorkspace::uuid('site-b', 'demo-x', 'report', 'one'));
    $this->assertNotSame($uuid, DemoWorkspace::uuid('site-a', 'demo-y', 'report', 'one'));
    $this->assertNotSame($uuid, DemoWorkspace::uuid('site-a', 'demo-x', 'media', 'one'));
  }

  /**
   * Only groups with a demo block are demo workspaces.
   */
  public function testBlockDetection(): void {
    $this->assertNull(DemoWorkspace::block($this->group(NULL)));
    $this->assertNull(DemoWorkspace::block($this->group('not json')));
    $this->assertNull(DemoWorkspace::block($this->group('{"theme":{}}')));
    $this->assertNull(DemoWorkspace::block($this->group('{"demo":{"seedVersion":1}}')));
    $this->assertSame(
      ['template' => 'safe-routes'],
      DemoWorkspace::block($this->group('{"demo":{"template":"safe-routes"}}')),
    );
  }

  /**
   * Delivery keeps the contract shape and drops everything else.
   */
  public function testSanitizeForDelivery(): void {
    $this->assertNull(DemoWorkspace::sanitizeForDelivery(NULL));
    $this->assertNull(DemoWorkspace::sanitizeForDelivery(['template' => '<b>x</b>']));

    $result = DemoWorkspace::sanitizeForDelivery([
      'template' => 'safe-routes',
      'seedVersion' => 3,
      'secret' => 'not delivered',
      'translations' => [
        '12-2026' => [
          'es' => [
            'title' => 'Cruce',
            'description' => 'Texto',
            'statusNotes' => ['uno', ['nested'], 'tres'],
            'extra' => 'dropped',
          ],
          'not a langcode!' => ['title' => 'x'],
        ],
        'bad id with spaces' => ['es' => ['title' => 'x']],
      ],
      'task' => ['es' => 'Abre', 'de' => ['nested']],
      'showcase_request_id' => '12-2026',
    ]);

    $this->assertSame([
      'template' => 'safe-routes',
      'seedVersion' => 3,
      'translations' => [
        '12-2026' => [
          'es' => [
            'title' => 'Cruce',
            'description' => 'Texto',
            // Position matters: notes are index-aligned with status_notes.
            'statusNotes' => ['uno', '', 'tres'],
          ],
        ],
      ],
      'task' => ['es' => 'Abre'],
      'showcase_request_id' => '12-2026',
    ], $result);

    // Anything but a request ID is delivered as NULL.
    foreach ([NULL, 12, '<b>1</b>', ['1-2026']] as $invalid) {
      $this->assertNull(DemoWorkspace::sanitizeForDelivery([
        'template' => 'safe-routes',
        'showcase_request_id' => $invalid,
      ])['showcase_request_id']);
    }
  }

  /**
   * Delivered texts are plain text, even from a hand-edited block.
   */
  public function testSanitizeStripsMarkup(): void {
    $result = DemoWorkspace::sanitizeForDelivery([
      'template' => 'safe-routes',
      'translations' => [
        '1-2026' => [
          'en' => [
            'title' => '<img src=x onerror=alert(1)>Crossing',
            'description' => '<b>Bold</b> text',
            'statusNotes' => ['<a href="javascript:x">Note</a>'],
          ],
        ],
      ],
      'task' => ['en' => '<script>alert(1)</script>Open it'],
    ]);

    $this->assertSame('Crossing', $result['translations']['1-2026']['en']['title']);
    $this->assertSame('Bold text', $result['translations']['1-2026']['en']['description']);
    $this->assertSame(['Note'], $result['translations']['1-2026']['en']['statusNotes']);
    $this->assertStringNotContainsString('<', $result['task']['en']);
  }

  /**
   * Empty maps stay JSON objects for the frontend.
   */
  public function testEmptyMapsEncodeAsObjects(): void {
    $result = DemoWorkspace::sanitizeForDelivery(['template' => 'crisis-map']);

    $this->assertSame(
      '{"template":"crisis-map","seedVersion":0,"translations":{},"task":{},"showcase_request_id":null}',
      json_encode($result),
    );
  }

  /**
   * Delivery needs the demo block AND the slug on the allowlist.
   */
  public function testDeliverableNeedsBlockAndAllowlist(): void {
    $block = '{"demo":{"template":"safe-routes","seedVersion":2}}';

    $this->assertNull(DemoWorkspace::deliverable($this->group($block, 'demo-x'), []));
    $this->assertNull(DemoWorkspace::deliverable($this->group($block, 'demo-x'), ['demo-y']));
    $this->assertNull(DemoWorkspace::deliverable($this->group('{"theme":{}}', 'demo-x'), ['demo-x']));
    $this->assertNull(DemoWorkspace::deliverable($this->group($block, NULL), ['demo-x']));
    $this->assertSame(
      ['template' => 'safe-routes', 'seedVersion' => 2],
      array_slice(DemoWorkspace::deliverable($this->group($block, 'demo-x'), ['demo-x']), 0, 2),
    );
  }

  /**
   * Builds a group double with field_nuxt_config and field_slug values.
   */
  private function group(?string $config, ?string $slug = NULL): GroupInterface {
    $fields = ['field_nuxt_config' => $config, 'field_slug' => $slug];
    $items = [];
    foreach ($fields as $name => $value) {
      $item = $this->createMock(FieldItemListInterface::class);
      $item->method('isEmpty')->willReturn($value === NULL);
      $item->method('__get')->with('value')->willReturn($value);
      $items[$name] = $item;
    }
    $group = $this->createMock(GroupInterface::class);
    $group->method('hasField')->willReturnCallback(static fn(string $name): bool => isset($items[$name]));
    $group->method('get')->willReturnCallback(static fn(string $name): FieldItemListInterface => $items[$name]);
    return $group;
  }

}
