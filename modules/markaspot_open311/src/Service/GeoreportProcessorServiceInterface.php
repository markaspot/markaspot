<?php

namespace Drupal\markaspot_open311\Service;

use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Interface for the GeoReport processor service.
 */
interface GeoreportProcessorServiceInterface {

  /**
   * Returns the Open311 discovery configuration.
   *
   * @return array
   *   The discovery configuration array.
   */
  public function getDiscovery(): array;

  /**
   * Prepares node properties for a service request.
   *
   * @param array $requestData
   *   The request data as an associative array.
   * @param string $operation
   *   The operation (create, update, etc.).
   *
   * @return array
   *   An associative array containing node property values.
   *
   * @throws \Drupal\markaspot_open311\Exception\GeoreportException
   *   If there is an error in the request data.
   */
  public function prepareNodeProperties(array $requestData, string $operation): array;

  /**
   * Maps a service code to a taxonomy term ID.
   *
   * @param string $serviceCode
   *   A comma-separated list of service codes.
   * @param int|null $jurisdictionId
   *   Optional jurisdiction group ID to scope the lookup.
   *
   * @return int|null
   *   The taxonomy term ID for the service code.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If the service code is not found.
   */
  public function mapServiceCodeToTaxonomy(string $serviceCode, ?int $jurisdictionId = NULL): ?int;

  /**
   * Maps status values to taxonomy term IDs.
   *
   * @param string $statuses
   *   A comma-separated list of status values.
   *
   * @return array
   *   An array of taxonomy term IDs.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If a status value is not found.
   */
  public function mapStatusToTaxonomy(string $statuses): array;

  /**
   * Returns a taxonomy tree for a given vocabulary.
   *
   * @param string $vocabulary
   *   The machine name of the vocabulary.
   * @param string|null $langcode
   *   The language code. Defaults to site default language.
   * @param int $parent
   *   The parent term ID (default: 0).
   * @param int|null $maxDepth
   *   The maximum depth (default: null).
   * @param int|null $jurisdictionId
   *   Optional jurisdiction group ID to filter terms.
   *
   * @return array
   *   An array of service definitions.
   */
  public function getTaxonomyTree(string $vocabulary = 'tags', ?string $langcode = NULL, int $parent = 0, ?int $maxDepth = NULL, ?int $jurisdictionId = NULL): array;

  /**
   * Maps a taxonomy term ID to a service definition.
   *
   * @param int $tid
   *   The taxonomy term ID.
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   An associative array representing the service definition.
   */
  public function mapTaxonomyToService(int $tid, string $langcode): array;

  /**
   * Queries the database for service request nodes.
   *
   * @param object $query
   *   The database query object.
   * @param object $user
   *   The user object.
   * @param array $parameters
   *   An array of query parameters.
   *
   * @return array
   *   An array of service request definitions.
   */
  public function getResults(object $query, object $user, array $parameters): array;

  /**
   * Creates a node query for service requests.
   *
   * @param array $parameters
   *   The query parameters.
   * @param object $user
   *   The current user.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The configured query object.
   */
  public function createNodeQuery(array $parameters, $user): QueryInterface;

  /**
   * Maps a node to a service request definition.
   *
   * @param object $node
   *   The service request node.
   * @param string $extendedRole
   *   The extended role for rendering additional fields.
   * @param array $parameters
   *   An array of query parameters.
   *
   * @return array
   *   An associative array representing the service request.
   */
  public function mapNodeToServiceRequest(object $node, string $extendedRole, array $parameters): array;

  /**
   * Formats an address field into a string.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $address
   *   The address field value.
   *
   * @return string
   *   The formatted address string.
   */
  public function formatAddress(FieldItemListInterface $address): string;

  /**
   * Retrieves a field value from a taxonomy term.
   *
   * @param int|null $tid
   *   The taxonomy term ID.
   * @param string $fieldName
   *   The field name.
   *
   * @return mixed
   *   The field value, or null if not found.
   */
  public function getTaxonomyTermField(?int $tid, string $fieldName): mixed;

  /**
   * Retrieves a translated field value from a taxonomy term.
   *
   * @param int|null $tid
   *   The taxonomy term ID.
   * @param string $fieldName
   *   The field name.
   * @param string $langcode
   *   The language code for translation.
   *
   * @return mixed
   *   The translated field value, or null if not found.
   */
  public function getTranslatedTaxonomyTermField(?int $tid, string $fieldName, string $langcode): mixed;

  /**
   * Maps a taxonomy term ID to an "open" or "closed" status value.
   *
   * @param int|null $taxonomyId
   *   The taxonomy term ID.
   *
   * @return string
   *   The status value ("open" or "closed").
   */
  public function mapStatusToOpenClosedValue(?int $taxonomyId): string;

  /**
   * Maps a status value to an array of taxonomy term IDs.
   *
   * @param string $status
   *   The status value ("open" or "closed").
   * @param int|null $jurisdictionId
   *   Optional jurisdiction group ID.
   *
   * @return array
   *   An array of taxonomy term IDs.
   */
  public function mapStatusToTaxonomyIds(string $status, ?int $jurisdictionId = NULL): array;

  /**
   * Gets the initial status term ID for a jurisdiction.
   *
   * @param int|null $jurisdictionId
   *   The jurisdiction group ID, or NULL for config fallback.
   *
   * @return int|null
   *   The taxonomy term ID, or NULL if not found.
   */
  public function getInitialStatusTid(?int $jurisdictionId = NULL): ?int;

  /**
   * Gets the initial boilerplate node ID for a jurisdiction.
   *
   * Resolves to the root jurisdiction and checks for a configured
   * field_initial_boilerplate on the group entity.
   *
   * @param int|null $jurisdictionId
   *   The jurisdiction group ID, or NULL to skip lookup.
   *
   * @return int|null
   *   The boilerplate node ID, or NULL if not configured.
   */
  public function getInitialBoilerplateId(?int $jurisdictionId = NULL): ?int;

  /**
   * Validates that the authenticated user has access to a jurisdiction.
   *
   * @param int|null $jurisdictionId
   *   The jurisdiction group ID, or NULL to skip validation.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The user account. Defaults to current user.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the user is not a member of the jurisdiction group.
   */
  public function validateJurisdictionAccess(?int $jurisdictionId, $account = NULL): void;

  /**
   * Resolves a jurisdiction ID from request parameters.
   *
   * Accepts numeric group IDs, slugs, and deprecated aliases (jurisdiction,
   * gid). For slugs, performs a database lookup against the jurisdiction
   * group's field_slug.
   *
   * @param array $parameters
   *   Query parameters containing jurisdiction_id (or deprecated aliases).
   *
   * @return int|null
   *   The resolved group ID, or NULL if not specified or not found.
   */
  public function resolveJurisdictionId(array $parameters): ?int;

  /**
   * Gets all category term IDs that belong to a jurisdiction.
   *
   * @param int $jurisdictionId
   *   The jurisdiction group ID.
   *
   * @return array
   *   Array of taxonomy term IDs.
   */
  public function getCategoryTidsForJurisdiction(int $jurisdictionId): array;

  /**
   * Gets the jurisdiction ID from a service request node.
   *
   * @param object $node
   *   The service request node.
   *
   * @return int|null
   *   The jurisdiction group ID, or NULL if not found.
   */
  public function getJurisdictionIdFromNode(object $node): ?int;

  /**
   * Creates a status note paragraph entity.
   *
   * @param array $fields
   *   Associative array with keys: 'status_term_id', 'note', 'format',
   *   'boilerplate_id', 'author_id'. See implementation for details.
   * @param string $langcode
   *   The language code for the paragraph. Defaults to site default language.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph
   *   The created paragraph entity.
   */
  public function createStatusNoteParagraph(array $fields, string $langcode = ''): Paragraph;

  /**
   * Updates the published status of media entities.
   *
   * @param array $mediaUpdates
   *   An array of media update instructions.
   * @param object|null $node
   *   Optional node entity for delta-based lookup.
   */
  public function updateMediaPublishedStatus(array $mediaUpdates, $node = NULL): void;

}
