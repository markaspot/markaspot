<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates new or changed image-analysis project contexts.
 */
final class ProjectPromptConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   */
  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof FieldItemListInterface || !$constraint instanceof ProjectPromptConstraint || $value->isEmpty()) {
      return;
    }
    $prompt = $value->first()?->get('value')->getValue();
    if (!is_string($prompt) || $prompt === '' || !$this->isNewOrChanged($value, $prompt)) {
      return;
    }
    $length = mb_strlen(trim(ProjectPromptConstraint::lineFeeds($prompt)));
    if ($length > ProjectPromptConstraint::MAX_LENGTH) {
      $this->context->addViolation($constraint->tooLongMessage, [
        '@max' => ProjectPromptConstraint::MAX_LENGTH,
        '@length' => $length,
      ]);
    }
    // FALSE means invalid UTF-8, which is not plain text either.
    if (preg_match(ProjectPromptConstraint::CONTROL_CHARACTERS, $prompt) !== 0) {
      $this->context->addViolation($constraint->controlCharacterMessage);
    }
  }

  /**
   * Whether the value differs from the stored one of the same translation.
   *
   * A form re-submits an untouched stored prompt with CRLF line endings, so
   * line endings are compared as line feeds.
   */
  private function isNewOrChanged(FieldItemListInterface $items, string $prompt): bool {
    $entity = $items->getEntity();
    if ($entity->isNew() || $entity->id() === NULL) {
      return TRUE;
    }
    $stored = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    if (!$stored instanceof FieldableEntityInterface) {
      return TRUE;
    }
    if ($stored instanceof TranslatableInterface && $entity instanceof TranslatableInterface) {
      $langcode = $entity->language()->getId();
      if (!$stored->hasTranslation($langcode)) {
        return TRUE;
      }
      $stored = $stored->getTranslation($langcode);
    }
    $name = $items->getFieldDefinition()->getName();
    if (!$stored->hasField($name) || $stored->get($name)->isEmpty()) {
      return TRUE;
    }
    return ProjectPromptConstraint::lineFeeds((string) $stored->get($name)->value) !== ProjectPromptConstraint::lineFeeds($prompt);
  }

}
