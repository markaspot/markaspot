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
   * Constructs the global report access check.
   */
  public function __construct(
    private readonly WorkspaceVisibilityInterface $visibility,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Requires full staff scope wherever a global report operation may reach.
   */
  public function access(AccountInterface $account, Request $request): AccessResult {
    $ids = array_filter($this->visibility->getRestrictedJurisdictionIds(),
      fn(int $id): bool => $this->visibility->getVisibility($id) === 'form_only');
    $result = AccessResult::allowed()->addCacheTags(['group_list']);
    if ($ids === []) {
      return $result;
    }
    $result->cachePerUser()->setCacheMaxAge(0);
    // These controllers expose global queue totals or process shared queues.
    // Their optional filter cannot establish isolation of the whole response.
    // Reject malformed filters instead of allowing their global fallback.
    $body = $request->getContent();
    $content = $body === '' ? [] : json_decode($body, TRUE);
    if (!is_array($content)) {
      return AccessResult::forbidden()->addCacheableDependency($result);
    }
    foreach ([$request->query->all(), $content] as $parameters) {
      if (array_key_exists('jurisdiction_id', $parameters)) {
        $value = $parameters['jurisdiction_id'];
        if ((!is_string($value) && !is_int($value)) || $this->resolveJurisdictionId($value) === NULL) {
          return AccessResult::forbidden()->addCacheableDependency($result);
        }
      }
    }
    foreach ($ids as $id) {
      // NULL means full jurisdiction scope. [] and organisation IDs cannot
      // authorize an unscoped aggregate. The policy also denies API-key owners.
      if ($this->visibility->getFormOnlyOrganisationScope($account, $id) !== NULL) {
        return AccessResult::forbidden('Global processing requires full form-only staff access.')
          ->addCacheableDependency($result);
      }
    }
    return $result;
  }

}
