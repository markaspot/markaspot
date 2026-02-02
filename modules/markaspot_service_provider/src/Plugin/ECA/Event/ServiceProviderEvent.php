<?php

namespace Drupal\markaspot_service_provider\Plugin\ECA\Event;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaEvent;
use Drupal\eca\Attribute\Token;
use Drupal\eca\Event\Tag;
use Drupal\eca\Plugin\ECA\Event\EventBase;
use Drupal\markaspot_service_provider\Event\ServiceProviderResponseEvent;
use Drupal\markaspot_service_provider\ServiceProviderEvents;

/**
 * Plugin implementation for service provider ECA events.
 *
 * The [node] and [entity] tokens are automatically provided by ECA core
 * because ServiceProviderResponseEvent implements EntityEventInterface.
 */
#[EcaEvent(
  id: 'service_provider_response',
  event_name: ServiceProviderEvents::RESPONSE_SUBMITTED,
  event_class: ServiceProviderResponseEvent::class,
  subscriber_priority: 0,
  tags: Tag::RUNTIME,
  label: new TranslatableMarkup('Service provider response submitted'),
)]
#[Token(
  name: 'sp_email',
  description: 'The service provider email address.',
  classes: [ServiceProviderResponseEvent::class],
)]
#[Token(
  name: 'completion_notes',
  description: 'The completion notes submitted.',
  classes: [ServiceProviderResponseEvent::class],
)]
class ServiceProviderEvent extends EventBase {

  /**
   * {@inheritdoc}
   */
  public static function definitions(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getData(string $key): mixed {
    if ($this->event instanceof ServiceProviderResponseEvent) {
      if ($key === 'sp_email') {
        return $this->event->getEmail();
      }
      if ($key === 'completion_notes') {
        return $this->event->getCompletionNotes();
      }
    }
    return parent::getData($key);
  }

  /**
   * {@inheritdoc}
   */
  public function hasData(string $key): bool {
    if ($key === 'sp_email' || $key === 'completion_notes') {
      return $this->event instanceof ServiceProviderResponseEvent;
    }
    return parent::hasData($key);
  }

}
