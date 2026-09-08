<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared form-only report scope for aggregate SQL using the node alias "n".
 */
class FormOnlyReportQueryScope {

  public function __construct(
    protected readonly WorkspaceVisibilityInterface $workspaceVisibility,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly Connection $database,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Applies anonymous-equivalent access when an aggregate uses a public key.
   */
  public function getSqlRestrictionForRequest(Request $request, AccountInterface $account): string {
    $settings = $this->configFactory->get('services_api_key_auth.settings');
    $header = $settings->get('api_key_request_header_name');
    $query = $settings->get('api_key_get_parameter_name');
    $body = $settings->get('api_key_post_parameter_name');
    $uses_api_key = $request->query->has('api_key') || $request->request->has('api_key')
      || ($header && $request->headers->has($header))
      || ($query && $request->query->has($query))
      || ($body && $request->request->has($body));
    foreach (['api_key', 'api-key', 'apikey', 'x-api-key'] as $name) {
      $uses_api_key = $uses_api_key || $request->headers->has($name);
    }
    return $this->getSqlRestrictionFor($uses_api_key ? new AnonymousUserSession() : $account);
  }

  /**
   * Returns a correlated predicate without materializing report IDs in PHP.
   *
   * The caller appends this AND predicate to its node alias n join. Identifiers
   * come from Field API and interpolated values are server-derived integers.
   */
  public function getSqlRestrictionFor(AccountInterface $account): string {
    $visibility = $this->workspaceVisibility;
    $scopes = [];
    foreach ($visibility->getRestrictedJurisdictionIds() as $jurisdiction_id) {
      if ($visibility->getVisibility($jurisdiction_id) !== 'form_only') {
        continue;
      }
      $scope = $visibility->getFormOnlyOrganisationScope($account, $jurisdiction_id);
      if ($scope !== NULL) {
        $scopes[(int) $jurisdiction_id] = $scope;
      }
    }
    if ($scopes === []) {
      return '';
    }

    try {
      $storage = $this->entityTypeManager->getStorage('node');
      if (!$storage instanceof SqlEntityStorageInterface) {
        return ' AND 1 = 0';
      }
      $definitions = $this->entityFieldManager->getFieldStorageDefinitions('node');
      $mapping = $storage->getTableMapping();
      $jur_table = $mapping->getFieldTableName('field_jurisdiction');
      $jur_column = $mapping->getFieldColumnName($definitions['field_jurisdiction'], 'target_id');
      $schema = $this->database->schema();
      foreach (['entity_id', 'deleted', $jur_column] as $column) {
        if (!$schema->fieldExists($jur_table, $column)) {
          return ' AND 1 = 0';
        }
      }
      $sql = '';
      foreach ($scopes as $jurisdiction_id => $organisation_ids) {
        $org_sql = '';
        if ($organisation_ids !== []) {
          $org_table = $mapping->getFieldTableName('field_organisation');
          $org_column = $mapping->getFieldColumnName($definitions['field_organisation'], 'target_id');
          foreach (['entity_id', 'deleted', $org_column] as $column) {
            if (!$schema->fieldExists($org_table, $column)) {
              return ' AND 1 = 0';
            }
          }
          $org_ids = implode(',', array_map('intval', $organisation_ids));
          $org_sql = " AND NOT EXISTS (SELECT 1 FROM {{$org_table}} form_org WHERE form_org.entity_id = n.nid AND form_org.deleted = 0 AND form_org.$org_column IN ($org_ids))";
        }
        $sql .= " AND NOT EXISTS (SELECT 1 FROM {{$jur_table}} form_jur WHERE form_jur.entity_id = n.nid AND form_jur.deleted = 0 AND form_jur.$jur_column = $jurisdiction_id$org_sql)";
      }
      return $sql;
    }
    catch (\Throwable) {
      // An incomplete field schema cannot safely prove report visibility.
      return ' AND 1 = 0';
    }
  }

}
