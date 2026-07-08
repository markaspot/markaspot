<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\OrganisationMetadataBuilder;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for service request assignee candidates.
 */
final class RequestAssigneesController extends ControllerBase {

  /**
   * Constructs a RequestAssigneesController.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly OrganisationMetadataBuilder $organisationMetadataBuilder,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('markaspot_group.organisation_metadata_builder'),
    );
  }

  /**
   * Returns assignable users for a service request.
   */
  public function assignees(NodeInterface $node): JsonResponse {
    if ($node->bundle() !== 'service_request') {
      return new JsonResponse(['error' => 'Service request not found.'], 404);
    }

    if (!\_markaspot_group_case_assignment_enabled_for_node($node)) {
      return new JsonResponse(['error' => 'Case assignment is not available for this jurisdiction.'], 403);
    }
    if (!\_markaspot_group_can_assign_service_request($node, $this->currentUser())) {
      return new JsonResponse(['error' => 'You may not assign this service request.'], 403);
    }

    $candidates = [];
    $organisation_groups = \_markaspot_group_service_request_organisation_groups($node);
    if ($organisation_groups !== []) {
      $jurisdiction_ids = [];
      foreach ($organisation_groups as $organisation) {
        $this->addGroupMembers($candidates, $organisation, 'org');
        $jurisdiction = \_markaspot_group_organisation_jurisdiction_group($organisation);
        if ($jurisdiction instanceof GroupInterface) {
          $jurisdiction_ids[(int) $jurisdiction->id()] = $jurisdiction;
        }
      }
      foreach ($jurisdiction_ids as $jurisdiction) {
        $this->addGroupMembers($candidates, $jurisdiction, 'jur');
      }
    }
    else {
      foreach ($this->assignmentJurisdictionGroups($node) as $jurisdiction) {
        $this->addGroupMembers($candidates, $jurisdiction, 'jur');
      }
    }

    $this->addOrganisationMemberships($node, $candidates);

    usort($candidates, static function (array $a, array $b): int {
      $label_compare = strcasecmp((string) $a['label'], (string) $b['label']);
      return $label_compare !== 0
        ? $label_compare
        : ((int) $a['uid'] <=> (int) $b['uid']);
    });

    return new JsonResponse([
      'current' => $this->currentAssignee($node),
      'current_team' => $this->currentTeam($node),
      'candidates' => array_values($candidates),
      'units' => $this->assignableUnits($node),
    ]);
  }

  /**
   * Adds active group members to the candidate map.
   *
   * @param array<int, array<string, mixed>> $candidates
   *   Candidate map keyed by UID.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group whose members should be added.
   * @param string $scope
   *   Candidate scope, either org or jur.
   */
  private function addGroupMembers(array &$candidates, GroupInterface $group, string $scope): void {
    $uids = $this->database->select('group_relationship_field_data', 'gr')
      ->fields('gr', ['entity_id'])
      ->condition('gr.gid', (int) $group->id())
      ->condition('gr.plugin_id', 'group_membership')
      ->distinct()
      ->execute()
      ->fetchCol();

    // Uid 1 is the Drupal superuser: auto-joined to every group at creation
    // and a technical account, never a real case worker. Excluding it keeps
    // the assignee list clean (it would otherwise appear in every org).
    $uids = array_values(array_unique(array_filter(
      array_map('intval', $uids),
      static fn(int $uid): bool => $uid > 1,
    )));
    if ($uids === []) {
      return;
    }

    $user_storage = $this->entityTypeManager()->getStorage('user');
    $active_uids = $user_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uids, 'IN')
      ->condition('status', 1)
      ->execute();
    if ($active_uids === []) {
      return;
    }

    foreach ($user_storage->loadMultiple($active_uids) as $user) {
      if (!$user instanceof UserInterface) {
        continue;
      }
      $uid = (int) $user->id();
      if (isset($candidates[$uid]) && $candidates[$uid]['scope'] === 'org') {
        continue;
      }
      $candidates[$uid] = [
        'id' => $user->uuid(),
        'uid' => $uid,
        'label' => $user->getDisplayName(),
        'scope' => $scope,
        'organisations' => [],
      ];
    }
  }

  /**
   * Adds request-jurisdiction-scoped organisation memberships to candidates.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param array<int, array<string, mixed>> $candidates
   *   Candidate map keyed by UID.
   */
  private function addOrganisationMemberships(NodeInterface $node, array &$candidates): void {
    if ($candidates === []) {
      return;
    }

    $organisations_by_uid = \_markaspot_group_organisation_groups_by_user_in_request_jurisdiction(
      $node,
      array_keys($candidates),
    );

    foreach ($candidates as $uid => &$candidate) {
      $candidate['organisations'] = [];
      foreach ($organisations_by_uid[(int) $uid] ?? [] as $organisation) {
        $candidate['organisations'][] = [
          'id' => $organisation->uuid(),
          'gid' => (int) $organisation->id(),
          'label' => $organisation->label(),
        ] + $this->organisationMetadataBuilder->build($organisation);
      }
    }
    unset($candidate);
  }

  /**
   * Builds the current assignee response value.
   */
  private function currentAssignee(NodeInterface $node): ?array {
    if (!$node->hasField('field_assignee') || $node->get('field_assignee')->isEmpty()) {
      return NULL;
    }

    $assignee = $node->get('field_assignee')->entity;
    if (!$assignee instanceof UserInterface) {
      return NULL;
    }
    if (!\_markaspot_group_service_request_assignee_is_valid($node, $assignee)) {
      return NULL;
    }

    return [
      'id' => $assignee->uuid(),
      'uid' => (int) $assignee->id(),
      'label' => $assignee->getDisplayName(),
    ];
  }

  /**
   * Builds the current assigned team response value.
   */
  private function currentTeam(NodeInterface $node): ?array {
    $team = \_markaspot_group_service_request_assigned_team_group($node);
    if (!$team instanceof GroupInterface || !\_markaspot_group_service_request_team_is_valid($node, $team)) {
      return NULL;
    }

    return [
      'id' => $team->uuid(),
      'gid' => (int) $team->id(),
      'label' => (string) $team->label(),
      'code' => $this->organisationMetadataBuilder->build($team)['code'],
    ];
  }

  /**
   * Builds assignable organisation unit rows for the request jurisdiction.
   *
   * @return array<int, array<string, mixed>>
   *   Assignable unit rows.
   */
  private function assignableUnits(NodeInterface $node): array {
    $units = [];
    foreach (\_markaspot_group_request_assignable_organisation_groups($node) as $organisation) {
      $units[] = [
        'id' => $organisation->uuid(),
        'gid' => (int) $organisation->id(),
        'label' => (string) $organisation->label(),
      ] + $this->organisationMetadataBuilder->build($organisation);
    }

    return $units;
  }

  /**
   * Loads jurisdiction groups whose members may be candidate assignees.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Jurisdiction groups keyed by group ID.
   */
  private function assignmentJurisdictionGroups(NodeInterface $node): array {
    $group_ids = \_markaspot_group_case_assignment_jurisdiction_group_ids($node);
    if ($group_ids === []) {
      return [];
    }

    $groups = [];
    foreach ($this->entityTypeManager()->getStorage('group')->loadMultiple($group_ids) as $group) {
      if ($group instanceof GroupInterface) {
        $groups[(int) $group->id()] = $group;
      }
    }

    return $groups;
  }

}
