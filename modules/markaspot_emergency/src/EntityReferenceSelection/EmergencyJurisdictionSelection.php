<?php

declare(strict_types=1);

namespace Drupal\markaspot_emergency\EntityReferenceSelection;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionWithAutocreateInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Decorates one prepared Lite jurisdiction reference validation.
 *
 * Listings, autocomplete, query alterations, and autocreate behavior always
 * remain owned by the configured core selection handler.
 */
final class EmergencyJurisdictionSelection implements SelectionInterface, SelectionWithAutocreateInterface {

  /**
   * Constructs the validation-only decorator.
   */
  public function __construct(
    private readonly SelectionInterface $inner,
    private readonly int $jurisdictionId,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getReferenceableEntities($match = NULL, $match_operator = 'CONTAINS', $limit = 0) {
    return $this->inner->getReferenceableEntities($match, $match_operator, $limit);
  }

  /**
   * {@inheritdoc}
   */
  public function countReferenceableEntities($match = NULL, $match_operator = 'CONTAINS') {
    return $this->inner->countReferenceableEntities($match, $match_operator);
  }

  /**
   * {@inheritdoc}
   */
  public function validateReferenceableEntities(array $ids) {
    $valid = $this->inner->validateReferenceableEntities($ids);
    $submitted = array_values(array_unique(array_map('intval', $ids)));
    if ($submitted === [$this->jurisdictionId]) {
      $valid[$this->jurisdictionId] = $this->jurisdictionId;
    }
    return $valid;
  }

  /**
   * {@inheritdoc}
   */
  public function entityQueryAlter(SelectInterface $query) {
    $this->inner->entityQueryAlter($query);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    return $this->inner->buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->inner->validateConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->inner->submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function createNewEntity($entity_type_id, $bundle, $label, $uid) {
    if (!$this->inner instanceof SelectionWithAutocreateInterface) {
      throw new \LogicException('The configured entity reference handler does not support autocreate.');
    }
    return $this->inner->createNewEntity($entity_type_id, $bundle, $label, $uid);
  }

  /**
   * {@inheritdoc}
   */
  public function validateReferenceableNewEntities(array $entities) {
    if (!$this->inner instanceof SelectionWithAutocreateInterface) {
      return [];
    }
    return $this->inner->validateReferenceableNewEntities($entities);
  }

}
