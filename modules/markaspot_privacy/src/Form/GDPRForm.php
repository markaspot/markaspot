<?php

namespace Drupal\markaspot_privacy\Form;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class GDPRForm provides a form for the user to delete/anonymize data.
 */
class GDPRForm extends FormBase {

  use StringTranslationTrait;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;


  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;


  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs form object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    MessengerInterface $messenger,
    EntityTypeManagerInterface $entity_type_manager,
    protected Connection $database,
  ) {
    $this->messenger = $messenger;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger'),
      $container->get('entity_type.manager'),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'default_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $uuid = NULL) {
    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Please confirm the deletion of this content.'),
      '#description' => $this->t('All content including the user-data will be deleted.'),
      '#default_value' => 1,
      "#required" => TRUE,
    ];

    $form['uuid'] = [
      '#type' => 'hidden',
      '#value' => $uuid,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Display result.
    $uuid = $form_state->getValue('uuid');
    if (!is_string($uuid) || !Uuid::isValid($uuid)) {
      $this->messenger->addError($this->t('The requested content identifier is invalid.'));
      return;
    }
    $node_storage = $this->entityTypeManager->getStorage('node');
    if (!$node_storage instanceof NodeStorageInterface) {
      throw new \LogicException('The node storage must support revision cleanup.');
    }
    $node = $node_storage->loadByProperties(['uuid' => $uuid]);

    if (empty($node)) {
      $this->messenger->addMessage($this->t("Sorry, we can't find the content requested. Maybe this has been deleted already."), 'error');
    }
    else {

      // We only have one node as loaded by UUID.
      $node = reset($node);
      if ($node->bundle() !== 'service_request' || !$node->access('update')) {
        $this->messenger->addError($this->t('You are not allowed to modify the requested content.'));
        return;
      }
      $citizen_entities = [];
      try {
        $this->deletePersonalData($node, $node_storage, $citizen_entities);
      }
      catch (\Throwable $exception) {
        $node_storage->resetCache([$node->id()]);
        if ($citizen_entities !== []) {
          $this->entityTypeManager->getStorage('citizen_entity')
            ->resetCache(array_keys($citizen_entities));
        }
        $this->logger('markaspot_privacy')->error('Privacy deletion failed: @message', [
          '@message' => $exception->getMessage(),
        ]);
        $this->messenger->addError($this->t('The privacy deletion could not be confirmed. Please verify the content before retrying.'));
        return;
      }

      $title = $node->label();

      $this->messenger->addMessage($this->t('The service request "@title" has been removed from the system.', ['@title' => $title]), 'info');

      $this->logger('markaspot_privacy')->notice('User deleted %title.',
        ['%title' => $title]);

      $form_state->setRedirect('<front>');

    }

  }

  /**
   * Deletes personal data in one database transaction.
   *
   * Drupal 10 and 11.2 commit when the transaction object leaves this method.
   * A commit exception therefore propagates to submitForm() without requiring
   * access to an already destroyed transaction object.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request to anonymize.
   * @param \Drupal\node\NodeStorageInterface $node_storage
   *   The node storage.
   * @param array $citizen_entities
   *   Referenced citizen entities, populated for failure-path cache resets.
   */
  private function deletePersonalData(NodeInterface $node, NodeStorageInterface $node_storage, array &$citizen_entities): void {
    $transaction = $this->database->startTransaction();
    try {
      foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
        $translation = $node->getTranslation($langcode);
        $translation->setUnpublished();
        foreach (['field_e_mail', 'field_first_name', 'field_last_name', 'field_phone'] as $field_name) {
          if ($translation->hasField($field_name)) {
            $translation->set($field_name, NULL);
          }
        }
        if ($translation->hasField('field_citizen')) {
          $citizen_field = $translation->get('field_citizen');
          if (!$citizen_field instanceof EntityReferenceFieldItemListInterface) {
            throw new \UnexpectedValueException('field_citizen must be an entity reference field.');
          }
          foreach ($citizen_field->referencedEntities() as $citizen_entity) {
            if ($citizen_entity->getEntityTypeId() !== 'citizen_entity' || $citizen_entity->id() === NULL) {
              throw new \UnexpectedValueException('field_citizen must reference a saved citizen_entity.');
            }
            $citizen_entities[(string) $citizen_entity->id()] = $citizen_entity;
          }
          $translation->set('field_citizen', NULL);
        }
      }
      $node->setNewRevision(FALSE);
      $node->save();

      // Historical revisions must not retain citizen contact data.
      $current_revision_id = (int) $node->getRevisionId();
      $revision_ids = array_keys($node_storage->getQuery()
        ->allRevisions()
        ->accessCheck(FALSE)
        ->condition('nid', $node->id())
        ->execute());
      foreach ($revision_ids as $revision_id) {
        if ((int) $revision_id !== $current_revision_id) {
          $node_storage->deleteRevision($revision_id);
        }
      }
      if ($citizen_entities !== []) {
        $this->entityTypeManager->getStorage('citizen_entity')
          ->delete(array_values($citizen_entities));
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

}
