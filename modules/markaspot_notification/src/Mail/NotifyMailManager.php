<?php

declare(strict_types=1);

namespace Drupal\markaspot_notification\Mail;

use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\markaspot_notification\NotificationCollector;

/**
 * Decorates plugin.manager.mail to intercept ECA action e-mails.
 *
 * When an ECA action sends a mail via the system module (module='system',
 * key='action_send_email') and the send succeeds, a 'mail_sent' notification
 * is recorded in the NotificationCollector so the frontend can surface it.
 *
 * All MailManagerInterface / PluginManagerInterface methods, as well as
 * CachedDiscoveryInterface and CacheableDependencyInterface methods, delegate
 * to the inner service. Only mail() adds the interception logic.
 *
 * CachedDiscoveryInterface and CacheableDependencyInterface must be
 * implemented because DefaultPluginManager (which MailManager extends)
 * declares them, and CachedDiscoveryClearer calls clearCachedDefinitions() on
 * all services tagged plugin_manager including the decorated one.
 */
class NotifyMailManager implements MailManagerInterface, CachedDiscoveryInterface, CacheableDependencyInterface {

  /**
   * Constructs a NotifyMailManager decorator.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $inner
   *   The decorated mail manager.
   * @param \Drupal\markaspot_notification\NotificationCollector $collector
   *   The notification collector service.
   */
  public function __construct(
    private readonly MailManagerInterface $inner,
    private readonly NotificationCollector $collector,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function mail($module, $key, $to, $langcode, $params = [], $reply = NULL, $send = TRUE): array {
    $result = $this->inner->mail($module, $key, $to, $langcode, $params, $reply, $send);
    if ($module === 'system' && $key === 'action_send_email' && ($result['result'] ?? FALSE) === TRUE) {
      $uuid = $this->collector->getCurrentNodeUuid();
      if ($uuid !== NULL) {
        $this->collector->record($uuid, 'mail_sent', []);
      }
    }
    return $result;
  }

  // DiscoveryInterface methods.

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE): mixed {
    return $this->inner->getDefinition($plugin_id, $exception_on_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions(): array {
    return $this->inner->getDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id): bool {
    return $this->inner->hasDefinition($plugin_id);
  }

  // FactoryInterface methods.

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []): object {
    return $this->inner->createInstance($plugin_id, $configuration);
  }

  // MapperInterface methods.

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options): object|false {
    return $this->inner->getInstance($options);
  }

  // CachedDiscoveryInterface methods.

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions(): void {
    if ($this->inner instanceof CachedDiscoveryInterface) {
      $this->inner->clearCachedDefinitions();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function useCaches($use_caches = FALSE): void {
    if ($this->inner instanceof CachedDiscoveryInterface) {
      $this->inner->useCaches($use_caches);
    }
  }

  // CacheableDependencyInterface methods.

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    if ($this->inner instanceof CacheableDependencyInterface) {
      return $this->inner->getCacheContexts();
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    if ($this->inner instanceof CacheableDependencyInterface) {
      return $this->inner->getCacheTags();
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    if ($this->inner instanceof CacheableDependencyInterface) {
      return $this->inner->getCacheMaxAge();
    }
    return 0;
  }

}
