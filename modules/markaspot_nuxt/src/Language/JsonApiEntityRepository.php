<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Language;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;

/**
 * Adapts translation selection only for JSON:API's converter/access checker.
 *
 * The global entity.repository is unchanged. Core still chooses fallback
 * translations, then JSON:API checks access on the selected translation for
 * collections, individual resources, and included/related resources.
 */
final class JsonApiEntityRepository implements EntityRepositoryInterface {

  /**
   * Constructs the JSON:API repository adapter.
   */
  public function __construct(
    private readonly EntityRepositoryInterface $inner,
    private readonly JsonApiReadLanguage $readLanguage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getTranslationFromContext(EntityInterface $entity, $langcode = NULL, $context = []) {
    $is_header_context = empty($langcode) && $this->readLanguage->isRead();
    if ($is_header_context) {
      $langcode = $this->readLanguage->getLangcode();
    }
    $translation = $this->inner->getTranslationFromContext($entity, $langcode, $context);
    if ($is_header_context && $translation !== NULL) {
      $translation->addCacheContexts([JsonApiReadLanguage::CACHE_CONTEXT]);
      $translation->addCacheTags(JsonApiReadLanguage::CACHE_TAGS);
    }
    return $translation;
  }

  /**
   * {@inheritdoc}
   */
  public function loadEntityByUuid($entity_type_id, $uuid) {
    return $this->inner->loadEntityByUuid($entity_type_id, $uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function loadEntityByConfigTarget($entity_type_id, $target) {
    return $this->inner->loadEntityByConfigTarget($entity_type_id, $target);
  }

  /**
   * {@inheritdoc}
   */
  public function getActive($entity_type_id, $entity_id, ?array $contexts = NULL) {
    return $this->inner->getActive($entity_type_id, $entity_id, $contexts);
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveMultiple($entity_type_id, array $entity_ids, ?array $contexts = NULL) {
    return $this->inner->getActiveMultiple($entity_type_id, $entity_ids, $contexts);
  }

  /**
   * {@inheritdoc}
   */
  public function getCanonical($entity_type_id, $entity_id, ?array $contexts = NULL) {
    return $this->inner->getCanonical($entity_type_id, $entity_id, $contexts);
  }

  /**
   * {@inheritdoc}
   */
  public function getCanonicalMultiple($entity_type_id, array $entity_ids, ?array $contexts = NULL) {
    return $this->inner->getCanonicalMultiple($entity_type_id, $entity_ids, $contexts);
  }

}
