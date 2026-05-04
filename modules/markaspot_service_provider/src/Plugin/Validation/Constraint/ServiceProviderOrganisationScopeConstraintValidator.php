<?php

declare(strict_types=1);

namespace Drupal\markaspot_service_provider\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\markaspot_service_provider\ServiceProviderOrganisationScopeHelper;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates service provider organisation scope on service requests.
 */
class ServiceProviderOrganisationScopeConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ServiceProviderOrganisationScopeConstraint) {
      return;
    }

    if (!$value instanceof NodeInterface || $value->bundle() !== 'service_request') {
      return;
    }

    if ($value->hasField('field_service_provider') && !$value->get('field_service_provider')->isEmpty()) {
      $provider = $value->get('field_service_provider')->entity;
      if ($provider instanceof TermInterface
        && !ServiceProviderOrganisationScopeHelper::providerMatchesRequest($provider, $value)) {
        $this->addViolation($constraint, 'field_service_provider', $constraint->message);
        if ($value->hasField('field_organisation')) {
          $this->addViolation($constraint, 'field_organisation', $constraint->message);
        }
      }
    }

    if ($value->hasField('field_boilerplates_sp') && !$value->get('field_boilerplates_sp')->isEmpty()) {
      $boilerplate = $value->get('field_boilerplates_sp')->entity;
      if (!$boilerplate instanceof NodeInterface
        || !ServiceProviderOrganisationScopeHelper::boilerplateMatchesRequest($boilerplate, $value)) {
        $this->addViolation($constraint, 'field_boilerplates_sp', $constraint->boilerplateMessage);
        if ($value->hasField('field_organisation')) {
          $this->addViolation($constraint, 'field_organisation', $constraint->boilerplateMessage);
        }
      }
    }
  }

  /**
   * Adds a scoped-provider violation at a field path.
   */
  protected function addViolation(ServiceProviderOrganisationScopeConstraint $constraint, string $path, string|TranslatableMarkup $message): void {
    $this->context->buildViolation($this->violationMessage($message))
      ->atPath($path)
      ->addViolation();
  }

  /**
   * Gets the untranslated source string for the violation builder.
   */
  protected function violationMessage(string|TranslatableMarkup $message): string {
    if ($message instanceof TranslatableMarkup) {
      return $message->getUntranslatedString();
    }

    return $message;
  }

}
