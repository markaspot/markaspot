<?php

declare(strict_types=1);

namespace Drupal\markaspot_cap\Hook;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\markaspot_cap\Service\CapFeedMutationTrackerInterface;
use Drupal\markaspot_cap\Support\CapApprovalReadiness;

/**
 * Drupal 11 entity hooks for CAP approval controls and feed invalidation.
 */
final class CapEntityHooks {

  /**
   * Constructs the CAP entity hook service.
   */
  public function __construct(
    private readonly CapFeedMutationTrackerInterface $feedMutationTracker,
  ) {}

  /**
   * Adds the staff-only CAP approval control to service request forms.
   *
   * @param \Drupal\Core\Entity\Display\EntityFormDisplayInterface $formDisplay
   *   Form display being altered.
   * @param array<string, mixed> $context
   *   Form display alter context.
   */
  #[Hook('entity_form_display_alter')]
  public function entityFormDisplayAlter(
    EntityFormDisplayInterface $formDisplay,
    array $context = [],
  ): void {
    if ($formDisplay->getTargetEntityTypeId() !== 'node'
      || $formDisplay->getTargetBundle() !== 'service_request') {
      return;
    }

    $formDisplay->setComponent('field_cap_publish', [
      'type' => 'boolean_checkbox',
      'weight' => 95,
      'region' => 'content',
      'settings' => ['display_label' => TRUE],
      'third_party_settings' => [],
    ]);
  }

  /**
   * Invalidates CAP feeds after a content entity insert.
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    if ($entity instanceof ContentEntityInterface) {
      $this->feedMutationTracker->markEntityChanged($entity);
      return;
    }
    if (CapApprovalReadiness::isApprovalRelevantEntity($entity)) {
      CapApprovalReadiness::refresh();
    }
  }

  /**
   * Invalidates both old and new CAP feeds after a content entity update.
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    if (!$entity instanceof ContentEntityInterface) {
      if (CapApprovalReadiness::isApprovalRelevantEntity($entity)) {
        CapApprovalReadiness::refresh();
      }
      return;
    }

    // getOriginal() was added in Drupal 11.2. Drupal 10 exposes the unchanged
    // entity through the legacy public property during update hooks.
    $original = method_exists($entity, 'getOriginal')
      ? $entity->getOriginal()
      : ($entity->original ?? NULL);
    $this->feedMutationTracker->markEntityChanged(
      $entity,
      $original instanceof ContentEntityInterface ? $original : NULL,
    );
  }

  /**
   * Invalidates CAP feeds after a content entity deletion.
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    if ($entity instanceof ContentEntityInterface) {
      $this->feedMutationTracker->markEntityChanged($entity);
      return;
    }
    if (CapApprovalReadiness::isApprovalRelevantEntity($entity)) {
      CapApprovalReadiness::refresh();
    }
  }

  /**
   * Revalidates pending CAP approval state after configuration import.
   */
  #[Hook('cron')]
  public function cron(): void {
    CapApprovalReadiness::refresh();
  }

}
