<?php

declare(strict_types=1);

namespace Drupal\markaspot_facility\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;

/**
 * Ensures a service request's facility belongs to its own jurisdiction.
 *
 * The field_facility property is public and anonymous-writable. Without this
 * guard a submitter can write an arbitrary facility machine key, including one
 * owned by a DIFFERENT tenant. FacilityManager::applyToServiceRequest() only
 * logs a warning for an unknown id and still persists the node, so the
 * cross-tenant value survives. This entity-level constraint rejects the save
 * instead.
 *
 * @Constraint(
 *   id = "FacilityOwnership",
 *   label = @Translation("Facility ownership scope", context = "Validation"),
 *   type = "entity:node"
 * )
 */
class FacilityOwnershipConstraint extends Constraint {

  /**
   * Message shown when the facility is not owned by the node's jurisdiction.
   *
   * @var string|\Drupal\Core\StringTranslation\TranslatableMarkup
   */
  public string|TranslatableMarkup $message = 'The selected facility is not available for this jurisdiction.';

  /**
   * Constructs a facility ownership constraint.
   *
   * Re-wraps the default string in a TranslatableMarkup so the message is
   * translatable when the constraint is instantiated without options, matching
   * the pattern used by ServiceProviderOrganisationScopeConstraint.
   */
  public function __construct(mixed $options = NULL, ?array $groups = NULL, mixed $payload = NULL) {
    parent::__construct($options, $groups, $payload);
    if ($this->message === 'The selected facility is not available for this jurisdiction.') {
      $this->message = new TranslatableMarkup('The selected facility is not available for this jurisdiction.');
    }
  }

}
