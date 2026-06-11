<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;

/**
 * Appends internal_remark paragraphs to service request nodes.
 *
 * ONE guarded mechanism shared by the two writers of mail-borne remarks:
 * - MailIngestOrchestrator::appendReplyRemark(): a citizen reply arriving
 *   AFTER promotion,
 * - InboundMailPromoter: the staged conversation log preserved AT promotion.
 *
 * Degrades gracefully on tenants without the paragraph stack: a missing
 * field_internal_remark or internal_remark bundle is a logged no-op, never
 * an exception. The created paragraph mirrors the dashboard's structure
 * (field_internal_remark_text, plain_text) and is attributed to the
 * anonymous user: the content originates from the citizen, not from staff.
 */
class InternalRemarkWriter {

  /**
   * Constructs an InternalRemarkWriter.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelInterface $logger,
  ) {
  }

  /**
   * Appends one internal_remark paragraph to a node and saves the node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The (saved) service request node.
   * @param string $text
   *   The remark text (already normalized plain text).
   *
   * @return bool
   *   TRUE when the remark was stored, FALSE on a (logged) degradation.
   */
  public function append(NodeInterface $node, string $text): bool {
    if (!$node->hasField('field_internal_remark')) {
      $this->logger->notice('Node @nid has no field_internal_remark; mail remark not stored.', ['@nid' => $node->id()]);
      return FALSE;
    }
    try {
      if ($this->entityTypeManager->getStorage('paragraphs_type')->load('internal_remark') === NULL) {
        $this->logger->notice('Paragraph bundle internal_remark missing; mail remark for node @nid not stored.', ['@nid' => $node->id()]);
        return FALSE;
      }

      /** @var \Drupal\paragraphs\ParagraphInterface $paragraph */
      $paragraph = $this->entityTypeManager->getStorage('paragraph')->create([
        'type' => 'internal_remark',
        'langcode' => $node->language()->getId(),
      ]);
      $paragraph->set('field_internal_remark_text', [
        'value' => $text,
        'format' => 'plain_text',
      ]);
      if ($paragraph->hasField('field_author')) {
        // Anonymous: the remark originates from the citizen, not from staff.
        $paragraph->set('field_author', 0);
      }
      $paragraph->save();

      $current = $node->get('field_internal_remark')->getValue();
      $current[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
      $node->set('field_internal_remark', $current);
      $node->save();
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to append mail remark to node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
