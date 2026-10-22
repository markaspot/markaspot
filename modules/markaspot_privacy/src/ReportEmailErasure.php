<?php

declare(strict_types=1);

namespace Drupal\markaspot_privacy;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\node\NodeInterface;
use Drupal\service_request\RevisionLog;

/**
 * Erases email values without discarding the report's revision history.
 */
final class ReportEmailErasure implements ReportEmailErasureInterface {

  public const ERASED_EMAIL = 'anasasaonymous@example.off';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function erase(NodeInterface $node): void {
    $storage = $this->entityTypeManager->getStorage('node');
    if (!$storage instanceof SqlEntityStorageInterface || !$node->hasField('field_e_mail')) {
      throw new EntityStorageException('Report email erasure requires revision-aware SQL storage.');
    }
    $definition = $node->getFieldDefinition('field_e_mail')->getFieldStorageDefinition();
    $mapping = $storage->getTableMapping();
    if (!$mapping->requiresDedicatedTableStorage($definition)) {
      throw new EntityStorageException('Report email erasure requires a dedicated email field table.');
    }
    $table = $mapping->getDedicatedRevisionTableName($definition);
    $column = $mapping->getFieldColumnName($definition, 'value');
    $transaction = $this->database->startTransaction();
    try {
      $node->setUnpublished();
      foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
        $node->getTranslation($langcode)->set('field_e_mail', self::ERASED_EMAIL);
      }
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId(0);
      $node->setRevisionCreationTime($this->time->getRequestTime());
      if (class_exists(RevisionLog::class)) {
        RevisionLog::setEvent($node, 'anonymized');
      }
      $node->save();
      // Do not save old revisions through entity hooks: that would create
      // further revisions. Keep their audit state, removing only the email.
      $this->database->update($table)
        ->fields([$column => self::ERASED_EMAIL])
        ->condition('entity_id', $node->id())
        ->execute();
    }
    catch (\Throwable $error) {
      $transaction->rollBack();
      throw $error;
    }
    unset($transaction);
    $storage->resetCache([$node->id()]);
    Cache::invalidateTags($node->getCacheTags());
  }

}
