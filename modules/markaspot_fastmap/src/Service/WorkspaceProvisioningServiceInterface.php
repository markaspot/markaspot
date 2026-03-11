<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

/**
 * Provisions and tears down FastMap workspaces.
 */
interface WorkspaceProvisioningServiceInterface {

  /**
   * Provision a complete workspace (group, terms, user, membership).
   *
   * @param array $data
   *   Workspace parameters: name, slug, email, categories, lat, lng, zoom,
   *   template, language, boundary.
   *
   * @return array
   *   Result with group_id, slug, name, url, categories count.
   *
   * @throws \RuntimeException
   *   When slug is already taken or entity creation fails.
   */
  public function provisionWorkspace(array $data): array;

  /**
   * Tear down a workspace and all associated data.
   *
   * @param int $groupId
   *   The jurisdiction group entity ID.
   *
   * @throws \RuntimeException
   *   When the group does not exist or deletion fails.
   */
  public function teardownWorkspace(int $groupId): void;

}
