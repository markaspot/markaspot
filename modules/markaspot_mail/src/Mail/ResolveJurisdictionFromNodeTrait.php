<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
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
    $jurisdiction = $this->resolveJurisdictionGroupFromNode($node);
    return $jurisdiction === NULL
      ? ['platform', NULL]
      : ['jurisdiction', (int) $jurisdiction->id()];
  }

  /**
   * Resolves the jurisdiction group itself from a service_request node.
   *
   * Builders that need a tenant-owned value in addition to the rendering
   * mode, such as citizen wording, can use this helper instead of re-reading
   * the entity reference with subtly different validity checks.
   */
  protected function resolveJurisdictionGroupFromNode(?NodeInterface $node): ?GroupInterface {
    if ($node === NULL || !$node->hasField('field_jurisdiction')) {
      return NULL;
    }
    $field = $node->get('field_jurisdiction');
    if (!$field instanceof EntityReferenceFieldItemListInterface || $field->isEmpty()) {
      return NULL;
    }
    $target = $field->referencedEntities()[0] ?? NULL;
    if (!$target instanceof GroupInterface || !$this->isResolvedJurisdictionGroup($target)) {
      return NULL;
    }
    return $target;
  }

  /**
   * Checks whether an entity is the configured jurisdiction group bundle.
   */
  protected function isResolvedJurisdictionGroup(mixed $target): bool {
    return $target instanceof ContentEntityInterface
      && $target->getEntityTypeId() === 'group'
      && $target->bundle() === $this->resolvedJurisdictionGroupType();
  }

  /**
   * Returns the configured jurisdiction group bundle.
   */
  protected function resolvedJurisdictionGroupType(): string {
    if (
      property_exists($this, 'configFactory')
      && $this->configFactory instanceof ConfigFactoryInterface
    ) {
      $configured = $this->configFactory
        ->get('markaspot_open311.settings')
        ->get('jurisdiction_group_type');
      return is_string($configured) && $configured !== '' ? $configured : 'jur';
    }

    try {
      if (\Drupal::hasService('config.factory')) {
        $configured = \Drupal::config('markaspot_open311.settings')
          ->get('jurisdiction_group_type');
        return is_string($configured) && $configured !== '' ? $configured : 'jur';
      }
    }
    catch (\Throwable) {
      // Unit tests may instantiate builders without a Drupal container.
    }

    return 'jur';
  }

}
