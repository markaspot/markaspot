<?php

declare(strict_types=1);

namespace Drupal\markaspot_passwordless\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_fastmap\Service\DemoStaffAccount;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Stores personal form layout preferences, never field configuration or grants.
 */
final class FormLayoutPreferencesController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * Stable identifiers, deliberately independent of translated labels.
   */
  public const SECTIONS = [
    'details', 'location', 'media', 'status_history', 'status_actions',
    'context', 'internal', 'service_provider', 'contact', 'related',
  ];

  /**
   * Constructs the personal preferences endpoint.
   */
  public function __construct(
    AccountInterface $account,
    EntityTypeManagerInterface $entity_type_manager,
    private readonly UserDataInterface $userData,
    private readonly WorkspaceVisibilityInterface $visibility,
    private readonly ?DemoStaffAccount $demoAccount = NULL,
  ) {
    $this->currentUser = $account;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('user.data'),
      $container->get('markaspot_group.workspace_visibility'),
      $container->has('markaspot_fastmap.demo_staff_account')
        ? $container->get('markaspot_fastmap.demo_staff_account') : NULL,
    );
  }

  /**
   * Reads or replaces the current account's layout for one jurisdiction.
   */
  public function preferences(Request $request, string $jurisdiction_id): JsonResponse {
    if (!$this->currentUser->isAuthenticated()) {
      return $this->respond(['error' => 'Authentication required.'], 403);
    }
    $jurisdiction = $this->resolveJurisdictionId($jurisdiction_id);
    if ($jurisdiction === NULL || !$this->visibility->allowsReadFor($this->currentUser, $jurisdiction)) {
      return $this->respond(['error' => 'Workspace unavailable.'], 403);
    }

    // Shared demo logins must never change another visitor's experience.
    if ($this->demoAccount?->isDemoAccount($this->currentUser)) {
      return $request->isMethodSafe()
        ? $this->respond(['layout' => NULL, 'writable' => FALSE])
        : $this->respond(['error' => 'Demo accounts keep no preferences.'], 403);
    }

    $uid = (int) $this->currentUser->id();
    $key = 'form_layout.' . $jurisdiction;
    if ($request->isMethodSafe()) {
      $stored = $this->userData->get('markaspot_passwordless', $uid, $key);
      return $this->respond([
        'layout' => self::validLayout($stored) ? $stored : NULL,
        'writable' => TRUE,
      ]);
    }

    if (strlen($request->getContent()) > 2048) {
      return $this->respond(['error' => 'Preference is too large.'], 400);
    }
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data) || array_keys($data) !== ['layout'] ||
      ($data['layout'] !== NULL && !self::validLayout($data['layout']))) {
      return $this->respond(['error' => 'Invalid form layout.'], 400);
    }

    // No caller-supplied uid, user UUID or namespace is accepted.
    if ($data['layout'] === NULL) {
      $this->userData->delete('markaspot_passwordless', $uid, $key);
    }
    else {
      $this->userData->set('markaspot_passwordless', $uid, $key, $data['layout']);
    }
    return $this->respond(['layout' => $data['layout'], 'writable' => TRUE]);
  }

  /**
   * Validates a complete, versioned partition of the known section IDs.
   */
  public static function validLayout(mixed $layout): bool {
    if (!is_array($layout) || count($layout) !== 3 ||
      ($layout['version'] ?? NULL) !== 1 ||
      !isset($layout['left'], $layout['right']) ||
      !is_array($layout['left']) || !is_array($layout['right']) ||
      !array_is_list($layout['left']) || !array_is_list($layout['right'])) {
      return FALSE;
    }
    $ids = array_merge($layout['left'], $layout['right']);
    if (count($ids) !== count(self::SECTIONS)) {
      return FALSE;
    }
    foreach ($ids as $id) {
      if (!is_string($id) || !in_array($id, self::SECTIONS, TRUE)) {
        return FALSE;
      }
    }
    return count(array_unique($ids)) === count(self::SECTIONS);
  }

  /**
   * Personal preferences must not enter any shared or browser HTTP cache.
   */
  private function respond(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
  }

}
