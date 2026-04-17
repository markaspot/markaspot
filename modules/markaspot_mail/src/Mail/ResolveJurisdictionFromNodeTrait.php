<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves (mode, jurisdictionId) from a service_request node.
 *
 * Five builders (Feedback, EcaAction, Escalation, Moderation,
 * Resubmission) share an identical path: take an optional NodeInterface,
 * inspect its field_jurisdiction, coerce the target into a jur group
 * and return [mode, jurisdictionId]. The typed referencedEntities() +
 * bundle check pattern is the same everywhere, so it lives here.
 *
 * Callers that also have a direct jur group in params (EcaAction's
 * $context['entity'], Escalation's $params['jurisdiction']) handle that
 * case first and fall through to this helper only when no direct group
 * was supplied.
 */
trait ResolveJurisdictionFromNodeTrait {

  /**
   * Resolves the mode + jurisdiction id from a node's field_jurisdiction.
   *
   * Returns ['platform', NULL] whenever the node is NULL, missing the
   * field, has an empty field, or the referenced entity isn't a jur
   * group. Only a fully-valid jur group returns
   * ['jurisdiction', (int) $groupId].
   *
   * @return array{0: string, 1: int|null}
   *   Two-element array: [mode, jurisdictionId].
   */
  protected function resolveJurisdictionFromNode(?NodeInterface $node): array {
    if ($node === NULL || !$node->hasField('field_jurisdiction')) {
      return ['platform', NULL];
    }
    $field = $node->get('field_jurisdiction');
    if (!$field instanceof EntityReferenceFieldItemListInterface || $field->isEmpty()) {
      return ['platform', NULL];
    }
    $target = $field->referencedEntities()[0] ?? NULL;
    if (!$target instanceof ContentEntityInterface
      || $target->getEntityTypeId() !== 'group'
      || $target->bundle() !== 'jur') {
      return ['platform', NULL];
    }
    return ['jurisdiction', (int) $target->id()];
  }

}
