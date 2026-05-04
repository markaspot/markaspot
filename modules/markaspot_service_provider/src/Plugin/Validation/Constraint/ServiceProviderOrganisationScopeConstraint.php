<?php

declare(strict_types=1);

namespace Drupal\markaspot_service_provider\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;

/**
 * Ensures scoped service providers match the request organisations.
 *
 * @Constraint(
 *   id = "ServiceProviderOrganisationScope",
 *   label = @Translation("Service provider organisation scope", context = "Validation"),
 *   type = "entity:node"
 * )
 */
class ServiceProviderOrganisationScopeConstraint extends Constraint {

  /**
   * Message shown when a scoped provider does not match request organisations.
   *
   * @var string|\Drupal\Core\StringTranslation\TranslatableMarkup
   */
  public string|TranslatableMarkup $message = 'The selected service provider is not available for the selected organisation.';

  /**
   * Message shown when a scoped boilerplate does not match request scope.
   *
   * @var string|\Drupal\Core\StringTranslation\TranslatableMarkup
   */
  public string|TranslatableMarkup $boilerplateMessage = 'The selected service provider boilerplate is not available for the selected organisation.';

  /**
   * Constructs a service provider organisation scope constraint.
   */
  public function __construct(mixed $options = NULL, ?array $groups = NULL, mixed $payload = NULL) {
    parent::__construct($options, $groups, $payload);
    if ($this->message === 'The selected service provider is not available for the selected organisation.') {
      $this->message = new TranslatableMarkup('The selected service provider is not available for the selected organisation.');
    }
    if ($this->boilerplateMessage === 'The selected service provider boilerplate is not available for the selected organisation.') {
      $this->boilerplateMessage = new TranslatableMarkup('The selected service provider boilerplate is not available for the selected organisation.');
    }
  }

}
