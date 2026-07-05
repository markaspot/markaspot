<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Entity;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Plugin\Validation\Constraint\JurisdictionParentReferenceConstraint;
use Drupal\markaspot_group\Plugin\Validation\Constraint\OrgParentReferenceConstraint;
use Drupal\markaspot_group\Plugin\Validation\Constraint\OrgRootJurisdictionReferenceConstraint;

/**
 * Adds Mark-a-Spot tenant delete guards before Group removes relationships.
 */
class ProtectedGroup extends Group {

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    if (self::isProtectedBundle($this->bundle())) {
      $violations = $this->validateProtectedTranslations();
      if (count($violations) > 0) {
        $messages = [];
        foreach ($violations as $violation) {
          $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
        }
        $messages = array_values(array_unique($messages));
        throw new EntityStorageException(implode(' ', $messages));
      }
    }

    parent::preSave($storage);
  }

  /**
   * Validates translations for constraints that must also guard direct saves.
   */
  protected function validateProtectedTranslations(): array {
    $all_violations = [];
    foreach (array_keys($this->getTranslationLanguages()) as $langcode) {
      $translation = $this->getTranslation($langcode);
      foreach ($translation->validate() as $violation) {
        if ($violation->getConstraint() instanceof OrgRootJurisdictionReferenceConstraint
          || $violation->getConstraint() instanceof JurisdictionParentReferenceConstraint
          || $violation->getConstraint() instanceof OrgParentReferenceConstraint) {
          $all_violations[] = $violation;
        }
      }
    }

    return $all_violations;
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    foreach ($entities as $group) {
      if (!$group instanceof GroupInterface || !self::isProtectedBundle($group->bundle())) {
        continue;
      }

      $blockers = \_markaspot_group_delete_blockers($group, TRUE);
      if ($blockers !== []) {
        throw new EntityStorageException(\_markaspot_group_delete_refusal_message($group, $blockers));
      }
    }

    parent::preDelete($storage, $entities);
  }

  /**
   * Checks whether a group bundle carries tenant-boundary protections.
   */
  protected static function isProtectedBundle(string $bundle): bool {
    $jurisdiction_group_type = \Drupal::config('markaspot_open311.settings')
      ->get('jurisdiction_group_type') ?: 'jur';
    return in_array($bundle, [$jurisdiction_group_type, 'org'], TRUE);
  }

}
