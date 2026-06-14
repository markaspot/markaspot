<?php

declare(strict_types=1);

namespace Drupal\markaspot_facility\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\markaspot_facility\Service\FacilityManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for tenant facilities settings.
 */
final class FacilitySettingsController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * Facility manager service.
   *
   * @var \Drupal\markaspot_facility\Service\FacilityManager
   */
  private FacilityManager $facilityManager;

  /**
   * Constructs the controller.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FacilityManager $facility_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->facilityManager = $facility_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('markaspot_facility.manager'),
    );
  }

  /**
   * Returns current facilities settings for a jurisdiction.
   */
  public function getFacilitiesSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    return new JsonResponse([
      'jurisdiction_id' => (int) $group->id(),
      'facilities' => $this->facilityManager->getDashboardSettings($group),
    ]);
  }

  /**
   * Updates facilities settings for a jurisdiction.
   */
  public function updateFacilitiesSettings(Request $request, string $jurisdiction_id): JsonResponse {
    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return new JsonResponse(['error' => 'Jurisdiction not found.'], 404);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
    }

    if (array_key_exists('facilities', $data)) {
      if (!is_array($data['facilities'])) {
        return new JsonResponse(['error' => 'facilities must be an object when provided.'], 422);
      }
      $data = $data['facilities'];
    }

    try {
      $this->facilityManager->saveDashboardSettings($group, $data);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 422);
    }
    catch (\Throwable $e) {
      $this->getLogger('markaspot_facility')->error(
        'Failed to save facilities settings for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to save facilities settings.'], 500);
    }

    $this->getLogger('markaspot_facility')->notice(
      'User @user updated facilities settings for jurisdiction @id.',
      ['@user' => $this->currentUser()->getDisplayName(), '@id' => $group->id()]
    );

    return $this->getFacilitiesSettings($request, $jurisdiction_id);
  }

  /**
   * Loads a jurisdiction group entity from a slug or numeric ID.
   */
  private function loadJurisdictionGroup(string $jurisdiction_id): ?GroupInterface {
    $resolved_id = $this->resolveJurisdictionId($jurisdiction_id);
    if ($resolved_id === NULL) {
      return NULL;
    }

    $group = $this->entityTypeManager()->getStorage('group')->load($resolved_id);
    if (!$this->isJurisdictionGroup($group)) {
      return NULL;
    }

    return $group;
  }

}
