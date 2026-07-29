<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\StatusTermScope;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Manages per-jurisdiction selections from a root-owned status pool.
 */
final class StatusSelectionController implements ContainerInjectionInterface {

  use JurisdictionIdResolverTrait;

  /**
   * Maximum accepted PATCH body size.
   */
  private const MAX_SELECTION_PAYLOAD_BYTES = 65536;

  /**
   * Constructs the status selection controller.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected GroupMembershipLoaderInterface $membershipLoader,
    protected JurisdictionHierarchyResolverInterface $hierarchyResolver,
    protected StatusTermScope $statusTermScope,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('group.membership_loader'),
      $container->get('markaspot_group.hierarchy_resolver'),
      $container->get('markaspot_group.status_term_scope'),
      $container->get('cache_tags.invalidator'),
      $container->get('config.factory'),
    );
  }

  /**
   * Checks that the caller administers the requested jurisdiction or its root.
   */
  public function accessCheck(AccountInterface $account, string $group_id): AccessResultInterface {
    $group = $this->loadJurisdictionGroup($group_id);
    if (!$group instanceof GroupInterface) {
      return AccessResult::forbidden('Jurisdiction not found.')
        ->addCacheContexts(['url.path']);
    }

    $group_id = (int) $group->id();
    $root_group_id = $this->hierarchyResolver->getRootJurisdictionId($group_id);
    if ($root_group_id === NULL || $root_group_id <= 0) {
      return AccessResult::forbidden('Jurisdiction root could not be resolved.')
        ->addCacheableDependency($group)
        ->addCacheContexts(['url.path'])
        ->addCacheTags(['group_list']);
    }

    $membership_cache_tags = [
      'group_relationship_list:plugin:group_membership:entity:' . $account->id(),
      'group_relationship_list:plugin:group_membership:group:' . $group_id,
      'group_relationship_list:plugin:group_membership:group:' . $root_group_id,
      'group:' . $group_id,
      'group:' . $root_group_id,
      'group_list',
    ];

    // Site administrators bypass the membership requirement, mirroring
    // DashboardController::access(). Without this the dev/site admin (role
    // administrator, no tenant_admin membership) gets 403 and the whole
    // statuses settings page degrades to an error card.
    if ($account->hasPermission('administer site configuration')) {
      return AccessResult::allowed()
        ->addCacheableDependency($group)
        ->addCacheContexts(['user.permissions']);
    }

    if (!in_array('tenant_admin', $account->getRoles(), TRUE)) {
      return AccessResult::forbidden('User is not a tenant admin for this jurisdiction.')
        ->addCacheableDependency($group)
        ->addCacheContexts(['user', 'user.roles'])
        ->addCacheTags($membership_cache_tags);
    }

    $allowed_group_ids = [$group_id, $root_group_id];
    foreach ($this->membershipLoader->loadByUser(
      $account,
      $this->jurisdictionRoleIds('tenant_admin'),
    ) as $membership) {
      $managed_group = $membership->getGroup();
      if (!$this->isJurisdictionGroup($managed_group)
        || !in_array((int) $managed_group->id(), $allowed_group_ids, TRUE)) {
        continue;
      }

      return AccessResult::allowed()
        ->addCacheableDependency($group)
        ->addCacheableDependency($membership)
        ->addCacheContexts(['user', 'user.roles'])
        ->addCacheTags($membership_cache_tags);
    }

    return AccessResult::forbidden('User is not a tenant admin for this jurisdiction.')
      ->addCacheableDependency($group)
      ->addCacheContexts(['user', 'user.roles'])
      ->addCacheTags($membership_cache_tags);
  }

  /**
   * Returns the root pool and the jurisdiction's explicit selection.
   */
  public function getSelection(string $group_id): JsonResponse {
    $context = $this->loadContext($group_id);
    if ($context === NULL) {
      return $this->errorResponse('Jurisdiction not found.', 404);
    }

    [$group, $root_group_id] = $context;
    $is_root = (int) $group->id() === $root_group_id;

    return new JsonResponse([
      'is_root' => $is_root,
      'root_group_id' => $root_group_id,
      'pool' => $this->buildPool((int) $group->id()),
      'selection' => $is_root ? NULL : $this->getSelectedTermIds($group),
    ]);
  }

  /**
   * Replaces the jurisdiction's explicit status selection.
   */
  public function patchSelection(Request $request, string $group_id): JsonResponse {
    $context = $this->loadContext($group_id);
    if ($context === NULL) {
      return $this->errorResponse('Jurisdiction not found.', 404);
    }

    [$group, $root_group_id] = $context;
    if ((int) $group->id() === $root_group_id) {
      return $this->errorResponse('Root jurisdictions own the status pool and cannot select it.', 400);
    }

    $selection = $this->decodeSelection($request);
    if ($selection === FALSE) {
      return $this->errorResponse('Body must be a JSON object containing only an integer selection array.', 400);
    }

    $pool_ids = array_fill_keys(
      array_column($this->buildPool((int) $group->id()), 'tid'),
      TRUE,
    );
    if (count($selection) > count($pool_ids)) {
      return $this->errorResponse('Selection contains more statuses than the root pool.', 400);
    }
    foreach ($selection as $term_id) {
      if (!isset($pool_ids[$term_id])) {
        return $this->errorResponse('Selection contains a status outside the root pool.', 400);
      }
    }

    if (!$group->hasField('field_service_statuses')) {
      return $this->errorResponse('Status selection storage is not installed.', 500);
    }

    $group->set('field_service_statuses', array_map(
      static fn(int $term_id): array => ['target_id' => $term_id],
      $selection,
    ));
    $group->save();

    // Group saves invalidate the entity and list tags. The explicit status
    // list invalidation also clears consumers that cache StatusTermScope
    // results only under their taxonomy dependencies.
    $this->cacheTagsInvalidator->invalidateTags([
      'group:' . $group->id(),
      'group_list',
      'taxonomy_term_list:service_status',
    ]);

    return new JsonResponse(['selection' => $selection]);
  }

  /**
   * Loads a published jurisdiction group and its root ID.
   *
   * @return array{0:\Drupal\group\Entity\GroupInterface,1:int}|null
   *   The group and root ID, or NULL when either cannot be resolved.
   */
  private function loadContext(string $group_id): ?array {
    $group = $this->loadJurisdictionGroup($group_id);
    if (!$group instanceof GroupInterface) {
      return NULL;
    }

    $root_group_id = $this->hierarchyResolver
      ->getRootJurisdictionId((int) $group->id());
    if ($root_group_id === NULL || $root_group_id <= 0) {
      return NULL;
    }

    return [$group, $root_group_id];
  }

  /**
   * Loads a published group of the configured jurisdiction bundle.
   */
  private function loadJurisdictionGroup(string $group_id): ?GroupInterface {
    $resolved_id = $this->resolveJurisdictionId($group_id);
    if ($resolved_id === NULL) {
      return NULL;
    }

    $group = $this->entityTypeManager
      ->getStorage('group')
      ->load($resolved_id);
    return $this->isJurisdictionGroup($group) ? $group : NULL;
  }

  /**
   * Builds the root-owned status pool in taxonomy weight order.
   *
   * @return array<int, array<string, int|string|null>>
   *   Normalized status objects.
   */
  private function buildPool(int $group_id): array {
    $terms = array_values(array_filter(
      $this->statusTermScope->loadTreePoolByProperties(
        // Published only: the live catalog (MarkASpotSettingsController)
        // filters on status 1, so unpublished terms would be selectable
        // here yet never appear anywhere. Selection validation uses the
        // same pool, which is safe while no pre-existing selections can
        // reference unpublished terms.
        ['vid' => 'service_status', 'status' => 1],
        $group_id,
      ),
      static fn(mixed $term): bool => $term instanceof TermInterface,
    ));

    usort(
      $terms,
      static fn(TermInterface $left, TermInterface $right): int =>
        [$left->getWeight(), (int) $left->id()]
        <=> [$right->getWeight(), (int) $right->id()],
    );

    return array_map(
      fn(TermInterface $term): array => [
        'tid' => (int) $term->id(),
        'uuid' => (string) $term->uuid(),
        'name' => (string) $term->getName(),
        'hex' => $this->getFieldString($term, 'field_status_hex', 'color'),
        'icon' => $this->getFieldString($term, 'field_status_icon'),
        'open311_mapping' => $this->getOpen311Mapping($term),
        'notification_key' => $this->getFieldString($term, 'field_notification_key'),
      ],
      $terms,
    );
  }

  /**
   * Returns a normalized optional string field value.
   */
  private function getFieldString(
    TermInterface $term,
    string $field_name,
    string $property = 'value',
  ): ?string {
    if (!$term->hasField($field_name) || $term->get($field_name)->isEmpty()) {
      return NULL;
    }

    $values = $term->get($field_name)->getValue();
    $value = $values[0][$property] ?? NULL;
    if (!is_scalar($value) || (string) $value === '') {
      return NULL;
    }

    return (string) $value;
  }

  /**
   * Returns only Open311 mappings included in the response contract.
   */
  private function getOpen311Mapping(TermInterface $term): ?string {
    $mapping = $this->getFieldString($term, 'field_open311_mapping');
    return in_array($mapping, ['initial', 'open', 'closed'], TRUE)
      ? $mapping
      : NULL;
  }

  /**
   * Returns the positive term IDs stored on a child jurisdiction.
   *
   * @return int[]
   *   Explicit selection IDs, or an empty array for inheritance.
   */
  private function getSelectedTermIds(GroupInterface $group): array {
    if (!$group->hasField('field_service_statuses')
      || $group->get('field_service_statuses')->isEmpty()) {
      return [];
    }

    $selection = [];
    foreach ($group->get('field_service_statuses')->getValue() as $item) {
      $term_id = (int) ($item['target_id'] ?? 0);
      if ($term_id > 0) {
        $selection[] = $term_id;
      }
    }
    return $selection;
  }

  /**
   * Decodes the pinned PATCH body shape.
   *
   * @return int[]|false
   *   Selection IDs, or FALSE for malformed input.
   */
  private function decodeSelection(Request $request): array|false {
    $raw = (string) $request->getContent();
    if (strlen($raw) > self::MAX_SELECTION_PAYLOAD_BYTES) {
      return FALSE;
    }

    try {
      $decoded = json_decode(
        $raw,
        FALSE,
        512,
        JSON_THROW_ON_ERROR,
      );
    }
    catch (\JsonException) {
      return FALSE;
    }

    if (!$decoded instanceof \stdClass) {
      return FALSE;
    }

    $payload = get_object_vars($decoded);
    if (count($payload) !== 1
      || !array_key_exists('selection', $payload)
      || !is_array($payload['selection'])) {
      return FALSE;
    }

    $seen = [];
    foreach ($payload['selection'] as $term_id) {
      if (!is_int($term_id) || isset($seen[$term_id])) {
        return FALSE;
      }
      $seen[$term_id] = TRUE;
    }

    return $payload['selection'];
  }

  /**
   * Builds a consistent error response.
   */
  private function errorResponse(string $message, int $status): JsonResponse {
    return new JsonResponse(['error' => $message], $status);
  }

}
