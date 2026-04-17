<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail;

use Drupal\markaspot_mail\Enum\MailType;

/**
 * Collects all tagged MailBuilderInterface services and dispatches lookups.
 *
 * The container wires every service tagged "markaspot_mail.builder" into
 * the $builders iterator at construction time. Subsequent findForMessage()
 * calls scan linearly: first match wins, builder order follows tag priority.
 *
 * Linear scan is fine for the expected count of builders (<20). If this
 * ever grows materially, swap to a supports-map built at construction.
 */
final class MailBuilderRegistry {

  /**
   * @var list<MailBuilderInterface>
   */
  private readonly array $builders;

  /**
   * @param iterable<MailBuilderInterface> $builders
   *   All tagged builders. Accepts iterable so the service container can
   *   pass a !tagged_iterator without forcing eager materialization at
   *   wiring time.
   */
  public function __construct(iterable $builders) {
    $list = [];
    foreach ($builders as $builder) {
      if ($builder instanceof MailBuilderInterface) {
        $list[] = $builder;
      }
    }
    $this->builders = $list;
  }

  /**
   * Returns the first builder that claims the given ($module, $key) pair.
   */
  public function findForMessage(string $module, string $key): ?MailBuilderInterface {
    foreach ($this->builders as $builder) {
      if ($builder->supports($module, $key)) {
        return $builder;
      }
    }
    return NULL;
  }

  /**
   * Returns the first builder with the given MailType classification.
   *
   * Useful for tests and for future introspection endpoints. Not used on
   * the hot mail-alter path (there we route by module:key).
   */
  public function findByType(MailType $type): ?MailBuilderInterface {
    foreach ($this->builders as $builder) {
      if ($builder->getType() === $type) {
        return $builder;
      }
    }
    return NULL;
  }

  /**
   * @return list<MailBuilderInterface>
   *   All registered builders, in resolution order.
   */
  public function all(): array {
    return $this->builders;
  }

}
