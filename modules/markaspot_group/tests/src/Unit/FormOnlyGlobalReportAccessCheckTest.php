<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Access\FormOnlyGlobalReportAccessCheck;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests global AI report operations with form-only jurisdictions.
 *
 * @group markaspot_group
 */
class FormOnlyGlobalReportAccessCheckTest extends UnitTestCase {

  /**
   * Verifies route scope, mode preservation and fail-closed filter handling.
   *
   * @dataProvider accessCases
   */
  public function testAccess(array $modes, array $scopes, array $query, string $body, string $route, bool $api_key, bool $expected, int $max_age, bool $administer_nodes = FALSE): void {
    $container = new ContainerBuilder();
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->with('administer nodes')
      ->willReturn($administer_nodes);
    $visibility = $this->createMock(WorkspaceVisibilityInterface::class);
    $visibility->method('getRestrictedJurisdictionIds')->willReturn(array_keys($modes));
    $visibility->method('getVisibility')->willReturnCallback(static fn(int $id): string => $modes[$id]);
    $visibility->method('getFormOnlyOrganisationScope')->willReturnCallback(static fn(AccountInterface $account, int $id): ?array => $scopes[$id]);
    $visibility->method('requestUsesPublicApiKey')->willReturnCallback(
      static fn(?Request $request = NULL): bool => $request?->headers->has('apikey') ?? FALSE,
    );
    $groups = [];
    foreach (array_keys($modes) as $id) {
      $group = $this->createMock(GroupInterface::class);
      $group->method('id')->willReturn($id);
      $group->method('bundle')->willReturn('jur');
      $group->method('isPublished')->willReturn(TRUE);
      $groups[$id] = $group;
    }
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(static fn(int $id) => $groups[$id] ?? NULL);
    $storage->method('loadByProperties')->willReturn([]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('group')->willReturn($storage);
    $checker = new FormOnlyGlobalReportAccessCheck($visibility, $manager, $this->getConfigFactoryStub([
      'markaspot_open311.settings' => ['jurisdiction_group_type' => 'jur'],
    ]));
    $request = Request::create('/api/ai/processing/status', 'GET', $query, [], [], [], $body);
    $request->attributes->set('_route', $route);
    if ($api_key) {
      $request->headers->set('apikey', 'public-key');
    }
    $result = $checker->access($account, $request);
    $this->assertSame($expected, $result->isAllowed());
    $this->assertContains('group_list', $result->getCacheTags());
    $this->assertContains('user', $result->getCacheContexts());
    $this->assertSame($max_age, $result->getCacheMaxAge());
  }

  /**
   * Covers filter isolation and route-specific no-filter controller behavior.
   */
  public static function accessCases(): array {
    $processing = 'markaspot_ai.processing_status';
    $attributes = 'markaspot_ai.attributes.status';
    return [
      'normal modes unchanged' => [
        [1 => 'submission_only', 2 => 'blocked'], [], [], '',
        $processing, FALSE, TRUE, Cache::PERMANENT,
      ],
      'normal malformed unchanged' => [
        [1 => 'authenticated'], [], [], '{bad',
        $processing, FALSE, TRUE, Cache::PERMANENT,
      ],
      'citizen denied on global route' => [
        [1 => 'form_only'], [1 => []], [], '',
        $processing, FALSE, FALSE, 0,
      ],
      'org staff denied on global route' => [
        [1 => 'form_only'], [1 => [9]], [], '',
        $processing, FALSE, FALSE, 0,
      ],
      'full staff allowed on global route' => [[1 => 'form_only'], [1 => NULL], [], '', $processing, FALSE, TRUE, 0],
      'foreign non-member denied on global route' => [
        [1 => 'form_only', 2 => 'form_only'], [1 => NULL, 2 => []],
        [], '', $processing, FALSE, FALSE, 0,
      ],
      'processing queue remains global without filter' => [
        [1 => 'form_only'], [1 => []], [], '',
        'markaspot_ai.processing_queue', FALSE, FALSE, 0,
      ],
      'processing run remains global without filter' => [
        [1 => 'form_only'], [1 => []], [], '',
        'markaspot_ai.processing_run', FALSE, FALSE, 0,
      ],
      'all form-only jurisdictions full scope' => [
        [1 => 'form_only', 2 => 'form_only'], [1 => NULL, 2 => NULL],
        [], '', $processing, FALSE, TRUE, 0,
      ],
      'workspace A filter ignores form-only B' => [
        [1 => 'public', 2 => 'form_only'], [2 => []], ['jurisdiction_id' => '1'], '', $processing, FALSE, TRUE, 0,
      ],
      'form-only workspace B filter denies non-member' => [
        [1 => 'public', 2 => 'form_only'], [2 => []], ['jurisdiction_id' => '2'], '', $processing, FALSE, FALSE, 0,
      ],
      'self-scoping route allows foreign non-member' => [
        [1 => 'public', 2 => 'form_only'], [2 => []], [], '', $attributes, FALSE, TRUE, 0,
      ],
      'attribute queue scopes itself without filter' => [
        [1 => 'form_only'], [1 => []], [], '',
        'markaspot_ai.attributes.queue', FALSE, TRUE, 0,
      ],
      'attribute controller global permission stays strict' => [
        [1 => 'form_only'], [1 => []], [], '',
        $attributes, FALSE, FALSE, 0, TRUE,
      ],
      'self-scoping route denies org-scoped staff' => [
        [1 => 'public', 2 => 'form_only'], [2 => [9]], [], '',
        $attributes, FALSE, FALSE, 0,
      ],
      'public API key denied on self-scoping route' => [
        [1 => 'form_only'], [1 => []], [], '', $attributes, TRUE, FALSE, 0,
      ],
      'valid filter allowed for full staff' => [
        [1 => 'form_only'], [1 => NULL], ['jurisdiction_id' => '1'],
        '', $processing, FALSE, TRUE, 0,
      ],
      'valid body filter allowed for full staff' => [
        [1 => 'form_only'], [1 => NULL], [], '{"jurisdiction_id":1}',
        $processing, FALSE, TRUE, 0,
      ],
      'unknown filter denied' => [
        [1 => 'form_only'], [1 => NULL], ['jurisdiction_id' => 'missing'],
        '', $processing, FALSE, FALSE, 0,
      ],
      'array filter denied' => [
        [1 => 'form_only'], [1 => NULL], ['jurisdiction_id' => [1]],
        '', $processing, FALSE, FALSE, 0,
      ],
      'empty filter denied' => [
        [1 => 'form_only'], [1 => NULL], ['jurisdiction_id' => ''],
        '', $processing, FALSE, FALSE, 0,
      ],
      'invalid JSON denied' => [[1 => 'form_only'], [1 => NULL], [], '{bad', $processing, FALSE, FALSE, 0],
      'invalid body filter denied' => [
        [1 => 'form_only'], [1 => NULL], [], '{"jurisdiction_id":0}',
        $processing, FALSE, FALSE, 0,
      ],
      'conflicting invalid body denied' => [
        [1 => 'form_only'], [1 => NULL], ['jurisdiction_id' => '1'], '{"jurisdiction_id":null}', $processing, FALSE, FALSE, 0,
      ],
      'conflicting valid filters denied' => [
        [1 => 'form_only', 2 => 'form_only'], [1 => NULL, 2 => NULL], ['jurisdiction_id' => '1'], '{"jurisdiction_id":2}', $processing, FALSE, FALSE, 0,
      ],
    ];
  }

}
