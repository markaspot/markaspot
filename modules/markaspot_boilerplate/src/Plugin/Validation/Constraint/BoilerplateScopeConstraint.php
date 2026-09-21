<?php

declare(strict_types=1);

namespace Drupal\markaspot_boilerplate\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates template writes before API persistence.
 */
#[Constraint(id: 'BoilerplateScope', label: new TranslatableMarkup('Boilerplate tenant scope'), type: 'entity')]
final class BoilerplateScopeConstraint extends SymfonyConstraint {
}
