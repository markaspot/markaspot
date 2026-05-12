<?php

declare(strict_types=1);

namespace Drupal\markaspot_notification\Mail;

use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\markaspot_notification\NotificationCollector;
use Drupal\node\NodeInterface;

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly MailManagerInterface $inner,
    private readonly NotificationCollector $collector,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Node resolved for the current dashboard PATCH UUID.
   *
   * @var \Drupal\node\NodeInterface|null
   */
  private ?NodeInterface $resolvedNode = NULL;

  /**
   * UUID used to populate the resolved node cache.
   *
   * @var string|null
   */
  private ?string $resolvedNodeUuid = NULL;

  /**
   * {@inheritdoc}
   */
  public function mail($module, $key, $to, $langcode, $params = [], $reply = NULL, $send = TRUE): array {
    $result = $this->inner->mail($module, $key, $to, $langcode, $params, $reply, $send);
    if ($this->shouldRecordMail($module, $key) && ($result['result'] ?? FALSE) === TRUE) {
      $node = $this->resolveNode($params);
      $uuid = $node?->uuid() ?? $this->collector->getCurrentNodeUuid();
      if ($uuid !== NULL) {
        $this->collector->record($uuid, 'mail_sent', $this->buildNotificationPayload($to, $params, $node));
      }
    }
    return $result;
  }

  /**
   * Returns whether a mail invocation should surface in the dashboard.
   *
   * Core's action_send_email action covers ECA-driven reporter, organisation,
   * and service-provider notifications. markaspot_group:org_notification is
   * the PHP replacement for the older process_apply_group ECA mail action.
   */
  private function shouldRecordMail($module, $key): bool {
    return ($module === 'system' && $key === 'action_send_email')
      || ($module === 'markaspot_group' && $key === 'org_notification')
      || ($module === 'markaspot_escalation' && $key === 'delegation_notification');
  }

  /**
   * Builds the side-effect payload for a sent mail without exposing addresses.
   *
   * @param string|array $to
   *   Recipient address or addresses passed to MailManagerInterface::mail().
   * @param array $params
   *   Mail params. Non-ECA callers may include node/organisation context.
   * @param \Drupal\node\NodeInterface|null $node
   *   The service_request node when available.
   *
   * @return array
   *   Notification payload with recipient_type and optional display label.
   */
  private function buildNotificationPayload($to, array $params, ?NodeInterface $node): array {
    $emails = $this->normalizeEmails($to);

    if ($node instanceof NodeInterface) {
      $reporterEmails = $this->fieldEmailValues($node, 'field_e_mail');
      if ($this->emailsIntersect($emails, $reporterEmails)) {
        return ['recipient_type' => 'reporter'];
      }

      foreach ($this->referencedEntities($node, 'field_service_provider') as $provider) {
        $providerEmails = $this->fieldEmailValues($provider, 'field_sp_email');
        if ($this->emailsIntersect($emails, $providerEmails)) {
          return [
            'recipient_type' => 'service_provider',
            'label' => $provider->label(),
          ];
        }
      }

      foreach (['field_organisation', 'field_jurisdiction'] as $groupField) {
        foreach ($this->referencedEntities($node, $groupField) as $group) {
          $groupEmails = array_merge(
            $this->fieldEmailValues($group, 'field_head_organisation_e_mail'),
            $this->fieldEmailValues($group, 'field_jurisdiction_e_mail'),
          );
          if ($this->emailsIntersect($emails, $groupEmails)) {
            return [
              'recipient_type' => 'group',
              'label' => $group->label(),
            ];
          }
        }
      }
    }

    if (($params['organisation'] ?? NULL) instanceof EntityInterface) {
      return [
        'recipient_type' => 'group',
        'label' => $params['organisation']->label(),
      ];
    }

    return ['recipient_type' => 'unknown'];
  }

  /**
   * Resolves the service_request node for the current dashboard PATCH.
   *
   * @param array $params
   *   Mail params that may include a node instance.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The resolved service_request node, or NULL if no current node is known.
   */
  private function resolveNode(array $params): ?NodeInterface {
    if (($params['node'] ?? NULL) instanceof NodeInterface) {
      return $params['node'];
    }

    $uuid = $this->collector->getCurrentNodeUuid();
    if ($uuid === NULL) {
      return NULL;
    }
    if ($uuid === $this->resolvedNodeUuid) {
      return $this->resolvedNode;
    }

    $nodes = $this->entityTypeManager
      ->getStorage('node')
      ->loadByProperties(['uuid' => $uuid]);
    $node = reset($nodes);
    $this->resolvedNodeUuid = $uuid;
    $this->resolvedNode = $node instanceof NodeInterface ? $node : NULL;
    return $this->resolvedNode;
  }

  /**
   * Returns normalized email values from an entity field.
   */
  private function fieldEmailValues(EntityInterface $entity, string $fieldName): array {
    if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return [];
    }

    $emails = [];
    foreach ($entity->get($fieldName) as $item) {
      $value = (string) ($item->getValue()['value'] ?? '');
      $normalized = $this->normalizeEmail($value);
      if ($normalized !== '') {
        $emails[] = $normalized;
      }
    }
    return array_values(array_unique($emails));
  }

  /**
   * Returns referenced entities from an entity reference field.
   */
  private function referencedEntities(EntityInterface $entity, string $fieldName): array {
    if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return [];
    }
    return $entity->get($fieldName)->referencedEntities();
  }

  /**
   * Normalizes a MailManager recipient value into comparable addresses.
   *
   * @param string|array $to
   *   Mail recipient value.
   *
   * @return string[]
   *   Lowercase email addresses.
   */
  private function normalizeEmails($to): array {
    $rawRecipients = is_array($to) ? $to : preg_split('/[,;]/', (string) $to);
    $emails = [];
    foreach ($rawRecipients ?: [] as $recipient) {
      $normalized = $this->normalizeEmail((string) $recipient);
      if ($normalized !== '') {
        $emails[] = $normalized;
      }
    }
    return array_values(array_unique($emails));
  }

  /**
   * Normalizes one recipient string.
   */
  private function normalizeEmail(string $value): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    if (preg_match('/<([^>]+)>/', $value, $matches) === 1) {
      $value = $matches[1];
    }
    return strtolower(trim($value));
  }

  /**
   * Returns whether two normalized email lists overlap.
   */
  private function emailsIntersect(array $left, array $right): bool {
    return array_intersect($left, $right) !== [];
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
