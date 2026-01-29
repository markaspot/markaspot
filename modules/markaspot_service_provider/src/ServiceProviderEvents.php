<?php

namespace Drupal\markaspot_service_provider;

/**
 * Defines events for the markaspot_service_provider module.
 */
final class ServiceProviderEvents {

  /**
   * Event fired when a service provider submits a response.
   *
   * @Event
   */
  const RESPONSE_SUBMITTED = 'markaspot_service_provider.response_submitted';

}
