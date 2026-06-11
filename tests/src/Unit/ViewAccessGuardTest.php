<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot\Unit;

use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

// The Drupal\markaspot\* namespace is registered dynamically by the Drupal
// kernel from the INSTALLED profile path, and the profile tree contains several
// nested .claude/worktrees/* copies that each carry a full markaspot profile —
// so a kernel-test class resolver can bind this class to a stale worktree copy
// that lacks the new methods. A plain unit test does not bootstrap extension
// discovery; we require the exact file under test so we always exercise THIS
// worktree's edited class. (Reported as a deviation in the deliverable.)
require_once dirname(__DIR__, 3) . '/src/EventSubscriber/ProfileConfigGuardSubscriber.php';

/**
 * Tests the "no anonymous Drupal HTML Views" headless hardening.
 *
 * Mark-a-Spot is headless: Drupal must never serve an anonymous,
 * server-rendered Views page. ProfileConfigGuardSubscriber::protectViewAccess()
 * rewrites anonymous display access to `role: authenticated` on every config
 * import, and the shared ::tightenAnonymousDisplays() helper backs both the
 * subscriber and markaspot_update_11929(). This test drives the real
 * import-transform tightening path against a core MemoryStorage (the same
 * StorageInterface core hands the subscriber during STORAGE_TRANSFORM_IMPORT)
 * and verifies the pure helper.
 *
 * @group markaspot
 *
 * @coversDefaultClass \Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber
 */
class ViewAccessGuardTest extends TestCase {

  /**
   * Builds the subscriber wired to a fresh in-memory import storage.
   *
   * The import storage is the only collaborator protectViewAccess() touches;
   * the other constructor arguments are mocked only to satisfy the signature.
   *
   * @return array{0: \Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber, 1: \Drupal\Core\Config\MemoryStorage}
   *   The subscriber and the import storage it will operate on.
   */
  private function buildSubscriber(): array {
    $importStorage = new MemoryStorage();
    $subscriber = new ProfileConfigGuardSubscriber(
      $this->createMock(ModuleExtensionList::class),
      $this->createMock(StorageInterface::class),
      'markaspot',
      new NullLogger(),
    );

    return [$subscriber, $importStorage];
  }

  /**
   * Invokes the private protectViewAccess() against the given storage.
   *
   * @param \Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber $subscriber
   *   The subscriber under test.
   * @param \Drupal\Core\Config\StorageInterface $importStorage
   *   The import storage to transform.
   */
  private function invokeProtectViewAccess(ProfileConfigGuardSubscriber $subscriber, StorageInterface $importStorage): void {
    $method = new \ReflectionMethod($subscriber, 'protectViewAccess');
    $method->setAccessible(TRUE);
    $method->invoke($subscriber, $importStorage);
  }

  /**
   * Returns a view config array with the given per-display access definitions.
   *
   * @param string $id
   *   The view machine id.
   * @param array<string, array<string, mixed>> $accessByDisplay
   *   Map of display id => access definition.
   *
   * @return array<string, mixed>
   *   A minimal but structurally valid views.view.* config array.
   */
  private function viewConfig(string $id, array $accessByDisplay): array {
    $displays = [];
    foreach ($accessByDisplay as $display_id => $access) {
      $displays[$display_id] = [
        'id' => $display_id,
        'display_plugin' => $display_id === 'default' ? 'default' : 'page',
        'display_options' => [
          'access' => $access,
        ],
      ];
    }

    return [
      'uuid' => '00000000-0000-0000-0000-0000000000' . substr(md5($id), 0, 2),
      'id' => $id,
      'label' => $id,
      'base_table' => 'node_field_data',
      'display' => $displays,
    ];
  }

  /**
   * Anonymous displays (none + access-content perm) become authenticated.
   *
   * @covers ::protectViewAccess
   * @covers ::tightenAnonymousDisplays
   * @covers ::accessIsAnonymous
   */
  public function testAnonymousDisplaysAreLockedToAuthenticated(): void {
    [$subscriber, $importStorage] = $this->buildSubscriber();

    $importStorage->write('views.view.public_none', $this->viewConfig('public_none', [
      // type: none => anonymous.
      'default' => ['type' => 'none', 'options' => []],
      // perm: access content => anonymous (held by anonymous by default).
      'page_1' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
    ]));

    $this->invokeProtectViewAccess($subscriber, $importStorage);

    $result = $importStorage->read('views.view.public_none');
    $expected = [
      'type' => 'role',
      'options' => ['role' => ['authenticated' => 'authenticated']],
    ];
    $this->assertSame($expected, $result['display']['default']['display_options']['access']);
    $this->assertSame($expected, $result['display']['page_1']['display_options']['access']);
  }

  /**
   * A display already locked to a staff role is left untouched (no loosening).
   *
   * @covers ::protectViewAccess
   * @covers ::tightenAnonymousDisplays
   * @covers ::accessIsAnonymous
   */
  public function testStaffRoleLockedDisplayIsPreserved(): void {
    [$subscriber, $importStorage] = $this->buildSubscriber();

    $staffAccess = [
      'type' => 'role',
      'options' => ['role' => ['administrator' => 'administrator']],
    ];
    $original = $this->viewConfig('staff_only', ['default' => $staffAccess]);
    $importStorage->write('views.view.staff_only', $original);

    $this->invokeProtectViewAccess($subscriber, $importStorage);

    $result = $importStorage->read('views.view.staff_only');
    // The stricter staff-role lock must survive byte-for-byte: only ANONYMOUS
    // displays are rewritten, so the guard never loosens or re-targets a role.
    $this->assertSame($staffAccess, $result['display']['default']['display_options']['access']);
    $this->assertSame($original, $result, 'A non-anonymous view config is left byte-for-byte unchanged.');
  }

  /**
   * A display gated by another anonymous-held perm is left untouched.
   *
   * The narrow definition of "anonymous" is `none` or `perm: access content`
   * only. A view gated by e.g. `access open311 extension` is a deliberate named
   * public surface and must be left for the operator to manage.
   *
   * @covers ::tightenAnonymousDisplays
   * @covers ::accessIsAnonymous
   */
  public function testOtherPermGatedDisplayIsNotTreatedAsAnonymous(): void {
    [$subscriber, $importStorage] = $this->buildSubscriber();

    $permAccess = [
      'type' => 'perm',
      'options' => ['perm' => 'access open311 extension'],
    ];
    $original = $this->viewConfig('open311', ['default' => $permAccess]);
    $importStorage->write('views.view.open311', $original);

    $this->invokeProtectViewAccess($subscriber, $importStorage);

    $result = $importStorage->read('views.view.open311');
    $this->assertSame($permAccess, $result['display']['default']['display_options']['access']);
    $this->assertSame($original, $result, 'A non-"access content" perm gate is not anonymous and is left unchanged.');
  }

  /**
   * Re-running the guard on already-tightened views is a no-op (idempotent).
   *
   * @covers ::protectViewAccess
   * @covers ::tightenAnonymousDisplays
   */
  public function testIdempotentOnAlreadyTightenedViews(): void {
    [$subscriber, $importStorage] = $this->buildSubscriber();

    $importStorage->write('views.view.public_none', $this->viewConfig('public_none', [
      'default' => ['type' => 'none', 'options' => []],
    ]));

    $this->invokeProtectViewAccess($subscriber, $importStorage);
    $firstPass = $importStorage->read('views.view.public_none');

    $this->invokeProtectViewAccess($subscriber, $importStorage);
    $secondPass = $importStorage->read('views.view.public_none');

    $this->assertSame($firstPass, $secondPass, 'Second pass over a locked view changes nothing.');
  }

  /**
   * The shared helper reports a change and rewrites a mixed view.
   *
   * Mirrors the markaspot_update_11929() entity path: one anonymous display
   * gets locked while a sibling staff-role display is preserved, and the helper
   * reports changed=TRUE so the hook records the view id.
   *
   * @covers ::tightenAnonymousDisplays
   * @covers ::accessIsAnonymous
   */
  public function testHelperTightensMixedViewAndReportsChange(): void {
    $displays = [
      'default' => [
        'display_options' => [
          'access' => ['type' => 'none', 'options' => []],
        ],
      ],
      'page_staff' => [
        'display_options' => [
          'access' => ['type' => 'role', 'options' => ['role' => ['editor' => 'editor']]],
        ],
      ],
    ];

    [$result, $changed] = ProfileConfigGuardSubscriber::tightenAnonymousDisplays($displays);

    $this->assertTrue($changed);
    $this->assertSame(
      ['type' => 'role', 'options' => ['role' => ['authenticated' => 'authenticated']]],
      $result['default']['display_options']['access'],
    );
    // The sibling staff display is preserved.
    $this->assertSame(
      ['type' => 'role', 'options' => ['role' => ['editor' => 'editor']]],
      $result['page_staff']['display_options']['access'],
    );
  }

  /**
   * The shared helper reports no change when there is nothing anonymous.
   *
   * Guards the markaspot_update_11929() "No anonymous Views found." path.
   *
   * @covers ::tightenAnonymousDisplays
   */
  public function testHelperReportsNoChangeForNonAnonymousViews(): void {
    $displays = [
      'default' => [
        'display_options' => [
          'access' => ['type' => 'role', 'options' => ['role' => ['editor' => 'editor']]],
        ],
      ],
    ];
    [$result, $changed] = ProfileConfigGuardSubscriber::tightenAnonymousDisplays($displays);
    $this->assertFalse($changed);
    $this->assertSame($displays, $result);
  }

  /**
   * A view exposing a `rest_export` display is left anonymous (public API).
   *
   * Such a view is a public REST API surface the headless frontend consumes
   * anonymously (e.g. markaspot_stats's stats/* endpoints, whose rest_export
   * displays carry no own access and inherit the anonymous `default`). Locking
   * it would 403 those endpoints and break the public stats widget, so the
   * guard skips the whole view — even though its `default` is anonymous.
   *
   * @covers ::tightenAnonymousDisplays
   */
  public function testRestExportViewIsLeftAnonymous(): void {
    $displays = [
      'default' => [
        'display_plugin' => 'default',
        'display_options' => [
          'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
        ],
      ],
      'rest_export_1' => [
        'display_plugin' => 'rest_export',
        // No own access: inherits the anonymous default at runtime.
        'display_options' => [],
      ],
    ];

    [$result, $changed] = ProfileConfigGuardSubscriber::tightenAnonymousDisplays($displays);

    $this->assertFalse($changed, 'A view exposing a rest_export display is not tightened.');
    $this->assertSame($displays, $result, 'The public REST API view is left byte-for-byte unchanged.');
  }

}
