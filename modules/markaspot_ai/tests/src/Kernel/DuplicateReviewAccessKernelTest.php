<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\markaspot_ai\Controller\DuplicateController;
use Drupal\markaspot_ai\Form\DuplicateReviewForm;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tenant and report access for the markaspot_ai review form and API.
 */
#[TestGroup('markaspot_ai')]
#[RunTestsInSeparateProcesses]
final class DuplicateReviewAccessKernelTest extends DuplicateAccessKernelTestBase {

  /**
   * Same-tenant review renders both reports and stores the decision.
   */
  public function testSameTenantReviewIsAllowed(): void {
    $matchId = $this->createMatch($this->request($this->tenantA, 'Pothole A1'), $this->request($this->tenantA, 'Pothole A2'));
    $this->actAs($this->adminA);

    $this->assertTrue($this->policy()->accessReviewRoute((string) $matchId, $this->adminA)->isAllowed());
    $build = $this->form()->buildForm([], new FormState(), $matchId);
    $rendered = $this->renderTitles($build);
    $this->assertStringContainsString('Pothole A1', $rendered);
    $this->assertStringContainsString('Pothole A2', $rendered);
    $this->assertTrue($build['actions']['confirm']['#access']);

    $this->submit($matchId, 'confirm');
    $row = $this->matchRow($matchId);
    $this->assertSame('confirmed', $row['status']);
    $this->assertSame((int) $this->adminA->id(), (int) $row['reviewed_by']);
  }

  /**
   * Tenant A cannot read or review tenant B's match through form or API.
   */
  public function testForeignTenantMatchIsDeniedWithoutMetadataOrMutation(): void {
    $matchId = $this->createMatch($this->request($this->tenantB, 'Foreign B1'), $this->request($this->tenantB, 'Foreign B2'));
    $this->actAs($this->adminA);

    $this->assertFalse($this->policy()->accessReviewRoute((string) $matchId, $this->adminA)->isAllowed());
    $this->assertFormDenied($matchId);
    $this->assertSubmitDeniedWithoutMutation($matchId);

    $response = $this->controller()->reviewMatch(
      Request::create('/', 'POST', [], [], [], [], json_encode(['status' => 'confirmed'])),
      $matchId,
    );
    $this->assertSame(403, $response->getStatusCode());
    $this->assertStringNotContainsString('Foreign', (string) $response->getContent());
    $this->assertUnreviewed($matchId);
  }

  /**
   * A match that spans two tenants is denied even to one tenant's admin.
   */
  public function testMixedScopeMatchIsDenied(): void {
    $matchId = $this->createMatch($this->request($this->tenantA, 'Own A'), $this->request($this->tenantB, 'Foreign B'));
    $this->actAs($this->adminA);

    $this->assertFalse($this->policy()->matchIdAccess($matchId, 'view', $this->adminA)->isAllowed());
    $this->assertFalse($this->policy()->matchIdAccess($matchId, 'update', $this->adminA)->isAllowed());
    $this->assertFormDenied($matchId);
    $this->assertSubmitDeniedWithoutMutation($matchId);
  }

  /**
   * An unpublished report the reviewer cannot view denies the match.
   */
  public function testUnpublishedReportIsDenied(): void {
    $matchId = $this->createMatch($this->request($this->tenantA, 'Published'), $this->request($this->tenantA, 'Draft', FALSE));
    $this->actAs($this->adminA);

    $this->assertFormDenied($matchId);
    $this->assertSubmitDeniedWithoutMutation($matchId);
  }

  /**
   * Deleted reports and unknown match IDs are denied the same way.
   */
  public function testMissingReportAndUnknownMatchAreDenied(): void {
    $deleted = $this->request($this->tenantA, 'Gone');
    $matchId = $this->createMatch($this->request($this->tenantA, 'Kept'), $deleted);
    $deleted->delete();
    $this->actAs($this->adminA);

    $this->assertFormDenied($matchId);
    $this->assertSubmitDeniedWithoutMutation($matchId);
    $this->assertFalse($this->policy()->accessReviewRoute('999999', $this->adminA)->isAllowed());
    $this->assertFalse($this->policy()->accessReviewRoute('not-a-number', $this->adminA)->isAllowed());
    $this->assertFormDenied(999999);
  }

  /**
   * A tenant that has not opted into duplicate detection exposes nothing.
   */
  public function testTenantOptOutAndGlobalToggleDeny(): void {
    $matchId = $this->createMatch($this->request($this->tenantA, 'A1'), $this->request($this->tenantA, 'A2'));
    $this->actAs($this->adminA);

    $this->optedIn = [(int) $this->tenantB->id()];
    $this->assertFormDenied($matchId);
    $this->assertSubmitDeniedWithoutMutation($matchId);

    $this->optedIn = [(int) $this->tenantA->id()];
    $this->setDuplicateDetection(FALSE);
    $this->assertFormDenied($matchId);
    $this->assertSubmitDeniedWithoutMutation($matchId);
  }

  /**
   * View access renders the comparison but reviewing needs update access.
   */
  public function testViewOnlyReviewerCannotSubmit(): void {
    $matchId = $this->createMatch($this->request($this->tenantA, 'A1'), $this->request($this->tenantA, 'A2'));
    $this->actAs($this->viewerA);

    $build = $this->form()->buildForm([], new FormState(), $matchId);
    $this->assertFalse($build['actions']['confirm']['#access']);
    $this->assertFalse($build['actions']['reject']['#access']);
    $this->assertSubmitDeniedWithoutMutation($matchId);
  }

  /**
   * Administrators review any tenant; a revoked membership loses access.
   */
  public function testAdministratorAccessAndRevokedMembership(): void {
    $foreign = $this->createMatch($this->request($this->tenantB, 'B1'), $this->request($this->tenantB, 'B2'));
    $root = User::load(1);
    $this->actAs($root);
    $this->assertTrue($this->policy()->matchIdAccess($foreign, 'update', $root)->isAllowed());
    $this->form()->buildForm([], new FormState(), $foreign);
    $this->submit($foreign, 'reject');
    $this->assertSame('rejected', $this->matchRow($foreign)['status']);

    $own = $this->createMatch($this->request($this->tenantA, 'A1'), $this->request($this->tenantA, 'A2'));
    $this->actAs($this->adminA);
    $this->assertTrue($this->policy()->matchIdAccess($own, 'update', $this->adminA)->isAllowed());

    $this->tenantA->removeMember($this->adminA);
    $this->actAs($this->adminA);
    $this->assertFalse($this->policy()->matchIdAccess($own, 'view', $this->adminA)->isAllowed());
    $this->assertFormDenied($own);
    $this->assertSubmitDeniedWithoutMutation($own);
  }

  /**
   * Asserts that the form refuses to build for a match.
   */
  private function assertFormDenied(int $matchId): void {
    try {
      $this->form()->buildForm([], new FormState(), $matchId);
      $this->fail('Duplicate review form rendered an inaccessible match.');
    }
    catch (AccessDeniedHttpException) {
      $this->addToAssertionCount(1);
    }
  }

  /**
   * Asserts that validation and submission refuse and nothing is written.
   */
  private function assertSubmitDeniedWithoutMutation(int $matchId): void {
    $form = [];
    $state = (new FormState())->setValue('match_id', $matchId);
    $this->form()->validateForm($form, $state);
    $this->assertNotEmpty($state->getErrors());
    try {
      $this->submit($matchId, 'confirm');
      $this->fail('Duplicate review submission reached reviewMatch().');
    }
    catch (AccessDeniedHttpException) {
      $this->addToAssertionCount(1);
    }
    $this->assertUnreviewed($matchId);
  }

  /**
   * Submits the review form with the given action.
   */
  private function submit(int $matchId, string $action): void {
    $form = [];
    $state = (new FormState())
      ->setValue('match_id', $matchId)
      ->setTriggeringElement(['#name' => $action]);
    $this->form()->submitForm($form, $state);
  }

  /**
   * Collects the rendered report titles from the comparison tables.
   */
  private function renderTitles(array $build): string {
    $out = '';
    foreach (['source', 'match'] as $side) {
      foreach ($build['comparison'][$side]['table']['#rows'] ?? [] as $row) {
        $out .= ' ' . strip_tags((string) $row[1]);
      }
    }
    return $out;
  }

  /**
   * Builds the review form with the test policy.
   */
  private function form(): DuplicateReviewForm {
    $form = new DuplicateReviewForm(
      $this->detection(),
      $this->container->get('entity_type.manager'),
      $this->container->get('date.formatter'),
      $this->policy(),
    );
    $form->setStringTranslation($this->container->get('string_translation'));
    $form->setMessenger($this->container->get('messenger'));
    return $form;
  }

  /**
   * Builds the API controller with the same test policy.
   */
  private function controller(): DuplicateController {
    $controller = new DuplicateController(
      $this->detection(),
      $this->container->get('entity_type.manager'),
      $this->container->get('database'),
      NULL,
      $this->policy(),
    );
    $controller->setStringTranslation($this->container->get('string_translation'));
    return $controller;
  }

}
