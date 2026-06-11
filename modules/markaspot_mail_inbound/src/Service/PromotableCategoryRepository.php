<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves the categories a staged mail may be promoted with.
 *
 * Extracted from InboundMailPromoteForm so the admin form and the dashboard
 * API (#482) share ONE definition of "promotable": any enabled service_category
 * term scoped to the mail's jurisdiction. Whether the term carries a
 * field_service_code (the Open311 API field) is irrelevant here: coded terms
 * go through the processor's service_code mapping; codeless terms have
 * field_category set directly by InboundMailPromoter. Both produce a valid
 * service request node with field_category populated.
 */
class PromotableCategoryRepository {

  /**
   * Constructs the repository.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Builds the promotable category options scoped to a jurisdiction.
   *
   * All service_category terms are offered. When the jurisdiction scopes at
   * least one term via field_jurisdiction, only those are returned; otherwise
   * all service categories are offered (single-tenant installs, unscoped
   * vocabularies). Root-jurisdiction inheritance is left to the processor on
   * promotion.
   *
   * @param int $jurisdictionGid
   *   The jurisdiction group id, or 0 when unassigned.
   *
   * @return array<int, string>
   *   Term-id keyed option labels (include the service code in parentheses when
   *   the term has one, so staff can distinguish categories at a glance).
   */
  public function getOptions(int $jurisdictionGid): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    if ($jurisdictionGid > 0) {
      try {
        $scoped = $storage->getQuery()
          ->accessCheck(TRUE)
          ->condition('vid', 'service_category')
          ->condition('field_jurisdiction', $jurisdictionGid)
          ->sort('name', 'ASC')
          ->execute();
        if ($scoped !== []) {
          return $this->labelOptions($storage->loadMultiple($scoped));
        }
      }
      catch (\Exception) {
        // Terms carry no field_jurisdiction on this install (single-tenant
        // vocabularies): the entity query throws for the unknown field.
        // Fall through to the full service-category list.
      }
    }
    $tids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', 'service_category')
      ->sort('name', 'ASC')
      ->execute();
    return $tids === [] ? [] : $this->labelOptions($storage->loadMultiple($tids));
  }

  /**
   * Whether a term is a valid promotion category for a jurisdiction.
   *
   * "Valid" means the term appears in the jurisdiction-scoped service_category
   * list returned by getOptions(). Whether it carries a field_service_code is
   * irrelevant: both coded and codeless terms are promotable.
   *
   * @param int $categoryTid
   *   The candidate term id.
   * @param int $jurisdictionGid
   *   The mail's jurisdiction group id, or 0.
   *
   * @return bool
   *   TRUE when the term is among the jurisdiction's promotable options.
   */
  public function isPromotable(int $categoryTid, int $jurisdictionGid): bool {
    return $categoryTid > 0 && array_key_exists($categoryTid, $this->getOptions($jurisdictionGid));
  }

  /**
   * Maps loaded terms to id => "label (code)" options.
   *
   * @param array<int|string, \Drupal\Core\Entity\EntityInterface> $terms
   *   The loaded terms.
   *
   * @return array<int, string>
   *   Options.
   */
  protected function labelOptions(array $terms): array {
    $options = [];
    foreach ($terms as $term) {
      $code = $term->hasField('field_service_code') && !$term->get('field_service_code')->isEmpty()
        ? (string) $term->get('field_service_code')->value
        : '';
      $label = (string) $term->label();
      $options[(int) $term->id()] = $code !== '' ? $label . ' (' . $code . ')' : $label;
    }
    return $options;
  }

}
