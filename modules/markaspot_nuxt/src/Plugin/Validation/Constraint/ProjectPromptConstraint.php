<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Bounds the image-analysis project context of a jurisdiction.
 *
 * Same rules as the onboarding (validateProjectPrompt() in the FastMap
 * layer): at most 2000 characters after trimming, no control characters
 * other than tab, line feed and carriage return. Only new or changed values
 * are checked, so a longer prompt saved before this rule keeps working.
 */
#[Constraint(id: 'MarkaspotProjectPrompt', label: new TranslatableMarkup('Image-analysis project context'))]
final class ProjectPromptConstraint extends SymfonyConstraint {

  /**
   * Longest accepted context, in characters after trimming.
   */
  public const MAX_LENGTH = 2000;

  /**
   * Violation message for a context that is too long.
   */
  public string $tooLongMessage = 'The image-analysis context can have at most @max characters (@length given).';

  /**
   * Violation message for control characters.
   */
  public string $controlCharacterMessage = 'The image-analysis context must be plain text without control characters.';

}
