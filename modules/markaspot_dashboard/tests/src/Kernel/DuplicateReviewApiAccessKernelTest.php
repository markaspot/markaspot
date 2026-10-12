<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Kernel;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_dashboard\Controller\DuplicateController;
use Drupal\Tests\markaspot_ai\Kernel\DuplicateAccessKernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Duplicate match access on the browser-facing dashboard API.
 *
 * /api/ai/duplicates/{match_id}/review, /ai/duplicates/{nid} and
 * /ai/duplicates/pending are served by the dashboard controller, which must
 * apply the same DuplicateMatchAccessPolicy as the markaspot_ai form.
 */
#[TestGroup('markaspot_dashboard')]
#[RunTestsInSeparateProcesses]
final class DuplicateReviewApiAccessKernelTest extends DuplicateAccessKernelTestBase {

  /**
   * A same-tenant reviewer may reject a match through the API.
   */
  public function testSameTenantReviewIsStored(): void {
    $matchId = $this->createMatch($this->request($this->tenantA, 'A1'), $this->request($this->tenantA, 'A2'));
    $this->actAs($this->adminA);

    $response = $this->controller()->reviewMatch($matchId, $this->reviewRequest('rejected'));
    $this->assertSame(200, $response->getStatusCode());
    $row = $this->matchRow($matchId);
    $this->assertSame('rejected', $row['status']);
    $this->assertSame((int) $this->adminA->id(), (int) $row['reviewed_by']);
  }

  /**
   * A foreign tenant's match is refused without metadata or mutation.
   */
  public function testForeignTenantMatchIsRefused(): void {
    $matchId = $this->createMatch($this->request($this->tenantB, 'Foreign B1'), $this->request($this->tenantB, 'Foreign B2'));
    $this->actAs($this->adminA);

    $this->assertRefusedWithoutMutation($matchId);
  }

  /**
   * Update access on a draft the reviewer cannot view is not enough.
   *
   * Group grants "update any" on the unpublished duplicate while the reviewer
   * lacks "view unpublished"; the previous check (update on the duplicate,
   * view on the source) let this POST change the match.
   */
  public function testViewlessDraftIsRefused(): void {
    $draft = $this->request($this->tenantA, 'Draft duplicate', FALSE);
    $this->assertTrue($draft->access('update', $this->adminA));
    $this->assertFalse($draft->access('view', $this->adminA));
    $matchId = $this->createMatch($this->request($this->tenantA, 'Published source'), $draft);
    $this->actAs($this->adminA);

    $this->assertRefusedWithoutMutation($matchId);
  }

  /**
   * Unknown match IDs and deleted reports answer like foreign matches.
   */
  public function testMissingMatchAndDeletedReportAreRefused(): void {
    $deleted = $this->request($this->tenantA, 'Gone');
    $matchId = $this->createMatch($this->request($this->tenantA, 'Kept'), $deleted);
    $deleted->delete();
    $this->actAs($this->adminA);

    $this->assertRefusedWithoutMutation($matchId);
    $response = $this->controller()->reviewMatch(999999, $this->reviewRequest('rejected'));
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * A view-only reviewer cannot write; a revoked member loses access.
   */
  public function testViewOnlyAndRevokedMembershipAreRefused(): void {
    $matchId = $this->createMatch($this->request($this->tenantA, 'A1'), $this->request($this->tenantA, 'A2'));
    $this->actAs($this->viewerA);
    $this->assertRefusedWithoutMutation($matchId);

    $this->tenantA->removeMember($this->adminA);
    $this->actAs($this->adminA);
    $this->assertRefusedWithoutMutation($matchId);
  }

  /**
   * Administrators review across tenants.
   */
  public function testAdministratorMayReviewAnyTenant(): void {
    $matchId = $this->createMatch($this->request($this->tenantB, 'B1'), $this->request($this->tenantB, 'B2'));
    $this->actAs(User::load(1));

    $response = $this->controller()->reviewMatch($matchId, $this->reviewRequest('rejected'));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('rejected', $this->matchRow($matchId)['status']);
  }

  /**
   * Read endpoints list only matches whose both reports are accessible.
   */
  public function testReadEndpointsHideForeignMatches(): void {
    $source = $this->request($this->tenantA, 'Own source');
    $this->createMatch($source, $this->request($this->tenantA, 'Own duplicate'));
    $this->createMatch($source, $this->request($this->tenantB, 'Foreign duplicate'));
    $foreignSource = $this->request($this->tenantB, 'Foreign source');
    $this->createMatch($foreignSource, $this->request($this->tenantB, 'Foreign twin'));
    $this->actAs($this->adminA);

    $own = json_decode((string) $this->controller()->getDuplicates((int) $source->id())->getContent(), TRUE);
    $this->assertSame(['Own duplicate'], array_column($own['duplicates'], 'title'));

    $foreign = $this->controller()->getDuplicates((int) $foreignSource->id());
    $this->assertSame(403, $foreign->getStatusCode());
    $this->assertStringNotContainsString('Foreign', (string) $foreign->getContent());

    $pending = json_decode((string) $this->controller()->getPendingDuplicates(
      Request::create('/', 'GET', ['jurisdiction_id' => (string) $this->tenantA->id()]),
    )->getContent(), TRUE);
    $this->assertSame(['Own duplicate'], array_column($pending['matches'], 'match_title'));
    $this->assertStringNotContainsString('Foreign', json_encode($pending));

    $denied = json_decode((string) $this->controller()->getPendingDuplicates(
      Request::create('/', 'GET', ['jurisdiction_id' => (string) $this->tenantB->id()]),
    )->getContent(), TRUE);
    $this->assertSame([], $denied['matches']);
  }

  /**
   * Asserts a 403 for both review actions and an unchanged match row.
   */
  private function assertRefusedWithoutMutation(int $matchId): void {
    foreach (['rejected', 'confirmed'] as $status) {
      $response = $this->controller()->reviewMatch($matchId, $this->reviewRequest($status));
      $this->assertSame(403, $response->getStatusCode());
      $this->assertStringNotContainsString('Foreign', (string) $response->getContent());
    }
    $this->assertUnreviewed($matchId);
  }

  /**
   * Builds a JSON review request.
   */
  private function reviewRequest(string $status): Request {
    return Request::create('/', 'POST', [], [], [], [], json_encode(['status' => $status]));
  }

  /**
   * Builds the dashboard controller with the shared test policy.
   *
   * The markaspot_ai module is not installed in this kernel, so the module
   * handler only reports it as present; everything else is the container.
   */
  private function controller(): DuplicateController {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(fn(string $module): bool => $module === 'markaspot_ai');
    $controller = new DuplicateController(
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get('language_manager'),
      $this->container->get('datetime.time'),
      $this->container->get('config.factory'),
      $moduleHandler,
      $this->container->get('markaspot_group.status_term_scope'),
      NULL,
      $this->policy(),
    );
    $controller->setStringTranslation($this->container->get('string_translation'));
    return $controller;
  }

}
