<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Exception;

use Drupal\Core\Entity\EntityStorageException;

/**
 * Identifies an organisation delete blocked by remaining references.
 */
final class OrganisationDeleteBlockedException extends EntityStorageException {

}
