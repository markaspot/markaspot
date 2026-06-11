<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the inbound-mail triage inbox list.
 *
 * Lists STAGED mails first (the inbox a moderator works through), with per-row
 * "Categorize & promote" and "Discard" actions. Promoted and discarded mails
 * are shown beneath for audit context. This is the functional Phase 1 list;
 * the dashboard-integrated triage UI is a later phase.
 */
class InboundMailListBuilder extends EntityListBuilder {

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    /** @var static $instance */
    $instance = parent::createInstance($container, $entity_type);
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    // Staged mails first (newest staged on top), then the rest for context.
    return $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->pager(50)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function load(): array {
    $entities = parent::load();
    // Jurisdiction scoping (#482): a content entity query's accessCheck(TRUE)
    // does not consult the entity access handler for inbound_mail, so rows
    // are filtered here. Global admins keep the full list; a jur-scoped
    // triage user only sees their own jurisdictions' mail (plus unscoped
    // mail). The pager may under-fill a page for scoped users; the scoped
    // surface of record is the dashboard API, which scopes its query.
    $entities = array_filter($entities, static fn(EntityInterface $mail): bool => $mail->access('view'));
    // Stable order: staged mails (the actionable inbox) before promoted /
    // discarded ones, then by recency.
    uasort($entities, static function (EntityInterface $a, EntityInterface $b): int {
      $weight = static fn(InboundMail $m): int => $m->getState() === InboundMail::STATE_STAGED ? 0 : 1;
      $stateCmp = $weight($a) <=> $weight($b);
      if ($stateCmp !== 0) {
        return $stateCmp;
      }
      return (int) $b->get('created')->value <=> (int) $a->get('created')->value;
    });
    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'from' => $this->t('From'),
      'subject' => $this->t('Subject'),
      'jurisdiction' => $this->t('Jurisdiction'),
      'received' => $this->t('Received'),
      'state' => $this->t('State'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof InboundMail);

    $fromName = trim((string) $entity->get('from_name')->value);
    $from = $fromName !== ''
      ? $fromName . ' <' . $entity->getFromAddress() . '>'
      : $entity->getFromAddress();

    $jurisdiction = $entity->get('jurisdiction_id')->entity;

    $created = (int) $entity->get('created')->value;

    // Attacker-controlled values (from_name, subject) are wrapped as
    // #plain_text render elements so escaping is explicit defense-in-depth
    // (NIT 11), not left to the table's implicit Twig autoescape.
    $row = [
      'from' => ['data' => ['#plain_text' => $from]],
      'subject' => $entity->getSubject() !== ''
        ? ['data' => ['#plain_text' => $entity->getSubject()]]
        : $this->t('(no subject)'),
      'jurisdiction' => $jurisdiction !== NULL ? $jurisdiction->label() : $this->t('Unassigned'),
      'received' => $created > 0 ? $this->dateFormatter->format($created, 'short') : '',
      'state' => $entity->getState(),
    ];

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getOperations(EntityInterface $entity): array {
    assert($entity instanceof InboundMail);
    $operations = [];

    // Promotion and discard only make sense while the mail is staged.
    if ($entity->getState() === InboundMail::STATE_STAGED) {
      $operations['promote'] = [
        'title' => $this->t('Categorize & promote'),
        'weight' => 0,
        'url' => Url::fromRoute('markaspot_mail_inbound.promote', ['inbound_mail' => $entity->id()]),
      ];
      $operations['discard'] = [
        'title' => $this->t('Discard'),
        'weight' => 10,
        'url' => Url::fromRoute('markaspot_mail_inbound.discard', ['inbound_mail' => $entity->id()]),
      ];
    }

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No inbound mails. Citizen emails appear here once a mailbox is configured and cron runs.');
    return $build;
  }

}
