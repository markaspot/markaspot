<?php

namespace Drupal\markaspot_service_provider\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Entity\EntityInterface;
use Drupal\eca\Event\EntityEventInterface;
use Drupal\node\NodeInterface;
use Drupal\markaspot_service_provider\ServiceProviderEvents;

/**
 * Event dispatched when a service provider submits a response.
 */
class ServiceProviderResponseEvent extends Event implements EntityEventInterface {

  /**
   * Event name.
   *
   * @deprecated Use ServiceProviderEvents::RESPONSE_SUBMITTED instead.
   */
  const EVENT_NAME = ServiceProviderEvents::RESPONSE_SUBMITTED;

  /**
   * The service request node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected NodeInterface $node;

  /**
   * The service provider email.
   *
   * @var string
   */
  protected string $email;

  /**
   * The completion notes.
   *
   * @var string
   */
  protected string $completionNotes;

  /**
   * Constructs a new ServiceProviderResponseEvent.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param string $email
   *   The service provider email.
   * @param string $completion_notes
   *   The completion notes.
   */
  public function __construct(NodeInterface $node, string $email, string $completion_notes) {
    $this->node = $node;
    $this->email = $email;
    $this->completionNotes = $completion_notes;
  }

  /**
   * Gets the service request node.
   *
   * @return \Drupal\node\NodeInterface
   *   The node.
   */
  public function getNode(): NodeInterface {
    return $this->node;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntity(): EntityInterface {
    return $this->node;
  }

  /**
   * Gets the service provider email.
   *
   * @return string
   *   The email.
   */
  public function getEmail(): string {
    return $this->email;
  }

  /**
   * Gets the completion notes.
   *
   * @return string
   *   The completion notes.
   */
  public function getCompletionNotes(): string {
    return $this->completionNotes;
  }

}
