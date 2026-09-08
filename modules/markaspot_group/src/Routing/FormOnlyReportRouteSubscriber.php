<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds form-only protection to report follow-up and auxiliary endpoints.
 */
final class FormOnlyReportRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ([
      'markaspot_service_provider.response_form',
      'markaspot_service_provider.rest_update',
      'markaspot_service_provider.rest_get',
      'markaspot_service_provider.rest_auth',
      'markaspot_feedback.form',
      'markaspot_feedback.rest',
      'markaspot_feedback.get',
      'markaspot_nuxt.vote_sum',
      'markaspot_ai.sentiment_analyze',
    ] as $name) {
      // Keep every existing permission, feature and authentication requirement.
      $collection->get($name)?->setRequirement('_form_only_report_access', 'TRUE');
    }
    foreach ([
      'markaspot_ai.processing_status',
      'markaspot_ai.processing_queue',
      'markaspot_ai.processing_run',
      'markaspot_ai.attributes.status',
      'markaspot_ai.attributes.queue',
    ] as $name) {
      $collection->get($name)?->setRequirement('_form_only_global_report_access', 'TRUE');
    }
  }

}
