<?php

declare(strict_types=1);

namespace Drupal\markaspot_ui\Service;

use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Applies the service request management display to the node edit form.
 *
 * The display is swapped via hook_entity_prepare_form() so the form actually
 * runs in the management form mode. Copying components inside
 * hook_entity_form_display_alter() would not be enough: field_group loads its
 * group definitions freshly from configuration by mode name
 * (field_group_info_groups()), so runtime-altered third party settings never
 * reach the rendered form.
 */
final class ManagementFormDisplaySwitcher {

  /**
   * Permission required to use the management form display.
   */
  public const PERMISSION = 'use service request management form';

  /**
   * Management form display config entity ID.
   */
  private const MANAGEMENT_DISPLAY_ID = 'node.service_request.management';

  /**
   * Route of the node edit form the switcher is limited to.
   *
   * The management display hides the title widget, so applying it on the
   * node add form would make the required title field unfillable. The
   * prepare-form hook cannot distinguish add from edit by operation (both
   * run the default operation), hence the route check.
   */
  private const EDIT_ROUTE = 'entity.node.edit_form';

  /**
   * Constructs the management form display switcher.
   */
  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteMatchInterface $routeMatch,
    private readonly ManagementAccessGate $accessGate,
  ) {}

  /**
   * Swaps the form display on an eligible node edit form.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the form is built for.
   * @param string $operation
   *   The form operation.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the entity form being prepared.
   */
  public function prepareForm(EntityInterface $entity, string $operation, FormStateInterface $form_state): void {
    if ($entity->getEntityTypeId() !== 'node'
      || $entity->bundle() !== 'service_request'
      || !in_array($operation, ['default', 'edit'], TRUE)
      || $this->routeMatch->getRouteName() !== self::EDIT_ROUTE
      || !$this->currentUser->hasPermission(self::PERMISSION)
      || !$this->accessGate->allowFullManagement()) {
      return;
    }

    $formObject = $form_state->getFormObject();
    if (!$formObject instanceof ContentEntityFormInterface) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('entity_form_display');
    $managementDisplay = $storage->load(self::MANAGEMENT_DISPLAY_ID);
    if (!$managementDisplay instanceof EntityFormDisplayInterface || !$managementDisplay->status()) {
      return;
    }

    if ($formObject->getFormDisplay($form_state)?->id() === $managementDisplay->id()) {
      return;
    }
    $formObject->setFormDisplay($managementDisplay, $form_state);
  }

}
