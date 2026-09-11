<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Protects global report statistics and processing queues in form-only mode.
 */
final class FormOnlyGlobalReportAccessCheck implements AccessInterface {

  use JurisdictionIdResolverTrait;

  /**
   * Routes that contain their own no-filter tenant isolation.
   *
   * Attribute status returns an empty result and attribute queue returns 403
   * for users the controller does not treat as global. Processing status,
   * queue and run remain platform-wide without a filter.
   */
  private const SELF_SCOPING_ROUTES = [
    'markaspot_ai.attributes.status',
    'markaspot_ai.attributes.queue',
  ];

  /**
   * Constructs the global report access check.
   */
  public function __construct(
    private readonly WorkspaceVisibilityInterface $visibility,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Requires sufficient staff scope for the requested report operation.
   */
  public function access(AccountInterface $account, Request $request): AccessResult {
    $ids = array_filter($this->visibility->getRestrictedJurisdictionIds(),
      fn(int $id): bool => $this->visibility->getVisibility($id) === 'form_only');
    $result = AccessResult::allowed()
      ->cachePerUser()
      ->addCacheTags(['group_list']);
    if ((int) $account->id() > 0) {
      $result->addCacheTags([
        'group_relationship_list:plugin:group_membership:entity:' . $account->id(),
      ]);
    }
    if ($ids === []) {
      return $result;
    }

    // Reject malformed filters instead of allowing their global fallback.
    $body = $request->getContent();
    $content = $body === '' ? [] : json_decode($body, TRUE);
    if (!is_array($content)) {
      return AccessResult::forbidden()
        ->addCacheableDependency($result)
        ->setCacheMaxAge(0);
    }
    $requested_id = NULL;
    foreach ([$request->query->all(), $content] as $parameters) {
      if (array_key_exists('jurisdiction_id', $parameters)) {
        $value = $parameters['jurisdiction_id'];
        $resolved_id = is_string($value) || is_int($value)
          ? $this->resolveJurisdictionId($value)
          : NULL;
        if ($resolved_id === NULL
          || ($requested_id !== NULL && $requested_id !== $resolved_id)) {
          return AccessResult::forbidden()
            ->addCacheableDependency($result)
            ->setCacheMaxAge(0);
        }
        $requested_id = $resolved_id;
      }
    }

    if ($requested_id !== NULL) {
      if ($this->visibility->getVisibility($requested_id) === 'form_only'
        && $this->visibility->getFormOnlyOrganisationScope($account, $requested_id) !== NULL) {
        return AccessResult::forbidden('The requested form-only jurisdiction requires full staff access.')
          ->addCacheableDependency($result)
          ->setCacheMaxAge(0);
      }
      return $result->setCacheMaxAge(0);
    }

    if ($this->visibility->requestUsesPublicApiKey($request)) {
      return AccessResult::forbidden('Public API keys cannot access form-only processing data.')
        ->addCacheableDependency($result)
        ->setCacheMaxAge(0);
    }

    $self_scoping = (int) $account->id() !== 1
      && !$account->hasPermission('administer nodes')
      && in_array(
        $request->attributes->get('_route'),
        self::SELF_SCOPING_ROUTES,
        TRUE,
      );
    foreach ($ids as $id) {
      $scope = $this->visibility->getFormOnlyOrganisationScope($account, $id);
      $forbidden = $self_scoping
        ? $scope !== NULL && $scope !== []
        : $scope !== NULL;
      if ($forbidden) {
        return AccessResult::forbidden('Global processing requires full form-only staff access.')
          ->addCacheableDependency($result)
          ->setCacheMaxAge(0);
      }
    }

    // An otherwise allowed request becomes forbidden when it carries a public
    // API key, and no cache context can represent that credential state.
    return $result->setCacheMaxAge(0);
  }

}
