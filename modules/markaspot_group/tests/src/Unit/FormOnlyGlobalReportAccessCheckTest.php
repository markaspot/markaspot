<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

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
   * Verifies full scope, mode preservation and fail-closed filter handling.
   *
   * @dataProvider accessCases
   */
  public function testAccess(array $modes, array $scopes, array $query, string $body, bool $expected): void {
    $container = new ContainerBuilder();
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
    $account = $this->createMock(AccountInterface::class);
    $visibility = $this->createMock(WorkspaceVisibilityInterface::class);
    $visibility->method('getRestrictedJurisdictionIds')->willReturn(array_keys($modes));
    $visibility->method('getVisibility')->willReturnCallback(static fn(int $id): string => $modes[$id]);
    $visibility->method('getFormOnlyOrganisationScope')->willReturnCallback(static fn(AccountInterface $account, int $id): ?array => $scopes[$id]);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(1);
    $group->method('bundle')->willReturn('jur');
    $group->method('isPublished')->willReturn(TRUE);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(static fn(int $id) => $id === 1 ? $group : NULL);
    $storage->method('loadByProperties')->willReturn([]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('group')->willReturn($storage);
    $checker = new FormOnlyGlobalReportAccessCheck($visibility, $manager, $this->getConfigFactoryStub([
      'markaspot_open311.settings' => ['jurisdiction_group_type' => 'jur'],
    ]));
    $request = Request::create('/api/ai/processing/status', 'GET', $query, [], [], [], $body);
    $result = $checker->access($account, $request);
    $this->assertSame($expected, $result->isAllowed());
    $this->assertContains('group_list', $result->getCacheTags());
    if (in_array('form_only', $modes, TRUE)) {
      $this->assertSame(0, $result->getCacheMaxAge());
    }
  }

  /**
   * Covers global queues despite filters and all-staff versus partial scope.
   */
  public static function accessCases(): array {
    return [
      'normal modes unchanged' => [[1 => 'submission_only', 2 => 'blocked'], [], [], '', TRUE],
      'normal malformed unchanged' => [[1 => 'authenticated'], [], [], '{bad', TRUE],
      'citizen denied' => [[1 => 'form_only'], [1 => []], [], '', FALSE],
      'org staff denied' => [[1 => 'form_only'], [1 => [9]], [], '', FALSE],
      'full staff allowed' => [[1 => 'form_only'], [1 => NULL], [], '', TRUE],
      'foreign staff denied' => [[1 => 'form_only', 2 => 'form_only'], [1 => NULL, 2 => []], [], '', FALSE],
      'all jurisdictions required' => [[1 => 'form_only', 2 => 'form_only'], [1 => NULL, 2 => NULL], [], '', TRUE],
      'filter cannot narrow shared queues' => [
        [1 => 'form_only', 2 => 'form_only'], [1 => NULL, 2 => []], ['jurisdiction_id' => '1'], '', FALSE,
      ],
      'valid filter allowed for full staff' => [[1 => 'form_only'], [1 => NULL], ['jurisdiction_id' => '1'], '', TRUE],
      'unknown filter denied' => [[1 => 'form_only'], [1 => NULL], ['jurisdiction_id' => 'missing'], '', FALSE],
      'array filter denied' => [[1 => 'form_only'], [1 => NULL], ['jurisdiction_id' => [1]], '', FALSE],
      'empty filter denied' => [[1 => 'form_only'], [1 => NULL], ['jurisdiction_id' => ''], '', FALSE],
      'invalid JSON denied' => [[1 => 'form_only'], [1 => NULL], [], '{bad', FALSE],
      'invalid body filter denied' => [[1 => 'form_only'], [1 => NULL], [], '{"jurisdiction_id":0}', FALSE],
      'conflicting invalid body denied' => [
        [1 => 'form_only'], [1 => NULL], ['jurisdiction_id' => '1'], '{"jurisdiction_id":null}', FALSE,
      ],
    ];
  }

}
