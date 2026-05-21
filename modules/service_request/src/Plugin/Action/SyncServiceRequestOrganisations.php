<?php

namespace Drupal\service_request\Plugin\Action;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Utility\Token;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Synchronizes service request organisation groups and notifies new assignees.
 *
 * @Action(
 *   id = "service_request_sync_organisations",
 *   label = @Translation("Sync service request organisations"),
 *   type = "node"
 * )
 */
class SyncServiceRequestOrganisations extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The mail manager.
   */
  protected MailManagerInterface $mailManager;

  /**
   * The language manager.
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The email validator.
   */
  protected EmailValidatorInterface $emailValidator;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * The token service.
   */
  protected Token $token;

  /**
   * Constructs the action plugin.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, MailManagerInterface $mail_manager, LanguageManagerInterface $language_manager, EmailValidatorInterface $email_validator, LoggerInterface $logger, Token $token) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
    $this->emailValidator = $email_validator;
    $this->logger = $logger;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('email.validator'),
      $container->get('logger.factory')->get('service_request'),
      $container->get('token')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'organisation_field' => 'field_organisation',
      'email_field' => 'field_head_organisation_e_mail',
      'content_plugin' => 'group_node:service_request',
      'organisation_group_type' => '',
      'subject' => '',
      'message' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['organisation_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organisation field'),
      '#default_value' => $this->configuration['organisation_field'],
      '#required' => TRUE,
    ];
    $form['email_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organisation email field'),
      '#default_value' => $this->configuration['email_field'],
      '#required' => TRUE,
    ];
    $form['content_plugin'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Group content plugin'),
      '#default_value' => $this->configuration['content_plugin'],
      '#required' => TRUE,
    ];
    $form['organisation_group_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organisation group type'),
      '#default_value' => $this->configuration['organisation_group_type'],
      '#description' => $this->t('Leave empty to use the target bundles from the organisation field.'),
    ];
    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $this->configuration['subject'],
      '#maxlength' => 254,
      '#required' => TRUE,
    ];
    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#default_value' => $this->configuration['message'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    foreach (['organisation_field', 'email_field', 'content_plugin', 'organisation_group_type', 'subject', 'message'] as $key) {
      $this->configuration[$key] = $form_state->getValue($key);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'service_request') {
      return;
    }

    $organisation_field = (string) $this->configuration['organisation_field'];
    if (!$entity->hasField($organisation_field)) {
      return;
    }

    $current_group_ids = $this->getReferencedGroupIds($entity, $organisation_field);
    $original_group_ids = [];
    if (isset($entity->original) && $entity->original instanceof NodeInterface && $entity->original->hasField($organisation_field)) {
      $original_group_ids = $this->getReferencedGroupIds($entity->original, $organisation_field);
    }

    $organisation_bundles = $this->getOrganisationBundles($entity, $organisation_field);
    $this->removeStaleRelationships($entity, $current_group_ids, $organisation_bundles);
    $this->addMissingRelationships($entity, $current_group_ids, $organisation_bundles);

    $new_group_ids = $original_group_ids
      ? array_values(array_diff($current_group_ids, $original_group_ids))
      : $current_group_ids;
    $this->notifyGroups($entity, $new_group_ids, $organisation_bundles);
  }

  /**
   * Gets referenced organisation group IDs from a node field.
   *
   * @return int[]
   *   The referenced group IDs.
   */
  protected function getReferencedGroupIds(NodeInterface $node, string $field_name): array {
    $group_ids = [];
    foreach ($node->get($field_name)->getValue() as $item) {
      if (!empty($item['target_id'])) {
        $group_ids[] = (int) $item['target_id'];
      }
    }
    return array_values(array_unique($group_ids));
  }

  /**
   * Gets the organisation group bundles accepted by this action.
   *
   * @return string[]
   *   The accepted group bundle IDs.
   */
  protected function getOrganisationBundles(NodeInterface $node, string $field_name): array {
    $configured = trim((string) ($this->configuration['organisation_group_type'] ?? ''));
    if ($configured !== '') {
      return [$configured];
    }

    $handler_settings = $node->getFieldDefinition($field_name)->getSetting('handler_settings') ?: [];
    $target_bundles = $handler_settings['target_bundles'] ?? [];
    if (is_array($target_bundles) && $target_bundles) {
      return array_values(array_unique(array_filter(array_map('strval', array_values($target_bundles)))));
    }

    return ['organisation', 'org'];
  }

  /**
   * Removes organisation relationships no longer present on the field.
   *
   * @param int[] $current_group_ids
   *   The assigned organisation group IDs.
   * @param string[] $organisation_bundles
   *   Accepted group bundle IDs.
   */
  protected function removeStaleRelationships(NodeInterface $node, array $current_group_ids, array $organisation_bundles): void {
    $relationship_storage = $this->getGroupRelationshipStorage();
    if (!$relationship_storage || !$node->id()) {
      return;
    }

    $relationships = $relationship_storage->loadByProperties([
      'entity_id' => $node->id(),
      'plugin_id' => $this->configuration['content_plugin'],
    ]);

    foreach ($relationships as $relationship) {
      if (!$relationship instanceof EntityInterface || !method_exists($relationship, 'getGroup')) {
        continue;
      }
      $group = $relationship->getGroup();
      if ($this->isOrganisationGroup($group, $organisation_bundles) && !in_array((int) $group->id(), $current_group_ids, TRUE)) {
        $relationship->delete();
      }
    }
  }

  /**
   * Adds missing organisation relationships for all assigned organisations.
   *
   * @param int[] $current_group_ids
   *   The assigned organisation group IDs.
   * @param string[] $organisation_bundles
   *   Accepted group bundle IDs.
   */
  protected function addMissingRelationships(NodeInterface $node, array $current_group_ids, array $organisation_bundles): void {
    $group_storage = $this->getGroupStorage();
    $relationship_storage = $this->getGroupRelationshipStorage();
    if (!$group_storage || !$relationship_storage || !$node->id()) {
      return;
    }

    foreach ($current_group_ids as $group_id) {
      $group = $group_storage->load($group_id);
      if (!$this->isOrganisationGroup($group, $organisation_bundles)) {
        continue;
      }

      $existing = $relationship_storage->loadByProperties([
        'entity_id' => $node->id(),
        'gid' => $group_id,
        'plugin_id' => $this->configuration['content_plugin'],
      ]);
      if ($existing) {
        continue;
      }

      if (!method_exists($group, 'addRelationship')) {
        continue;
      }

      try {
        $group->addRelationship($node, $this->configuration['content_plugin']);
      }
      catch (\Throwable $e) {
        $this->logger->error('Could not assign service request @nid to organisation group @gid: @message', [
          '@nid' => $node->id(),
          '@gid' => $group_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Sends notification mails to newly assigned organisation groups.
   *
   * @param int[] $group_ids
   *   The groups to notify.
   * @param string[] $organisation_bundles
   *   Accepted group bundle IDs.
   */
  protected function notifyGroups(NodeInterface $node, array $group_ids, array $organisation_bundles): void {
    $group_storage = $this->getGroupStorage();
    if (!$group_storage || !$group_ids) {
      return;
    }

    $langcode = $this->languageManager->getDefaultLanguage()->getId();

    foreach ($group_ids as $group_id) {
      $group = $group_storage->load($group_id);
      if (!$this->isOrganisationGroup($group, $organisation_bundles) || !$group instanceof FieldableEntityInterface) {
        continue;
      }

      $emails = $this->getGroupEmails($group);
      if (!$emails) {
        continue;
      }

      $context = [
        'node' => $node,
        'group' => $group,
        'entity' => $group,
        'subject' => $this->token->replace((string) $this->configuration['subject'], ['node' => $node, 'group' => $group, 'entity' => $group], ['clear' => TRUE]),
        'message' => $this->token->replace((string) $this->configuration['message'], ['node' => $node, 'group' => $group, 'entity' => $group], ['clear' => TRUE]),
      ];

      foreach ($emails as $email) {
        $message = $this->mailManager->mail('system', 'action_send_email', $email, $langcode, ['context' => $context]);
        if (!empty($message['result'])) {
          $this->logger->info('Sent service request organisation notification for node @nid to @mail.', [
            '@nid' => $node->id(),
            '@mail' => $email,
          ]);
        }
      }
    }
  }

  /**
   * Gets valid notification email addresses from a group.
   *
   * @return string[]
   *   Valid email addresses.
   */
  protected function getGroupEmails(FieldableEntityInterface $group): array {
    $email_field = (string) $this->configuration['email_field'];
    if (!$group->hasField($email_field)) {
      return [];
    }

    $emails = [];
    foreach ($group->get($email_field)->getValue() as $item) {
      $email = trim((string) ($item['value'] ?? $item['email'] ?? ''));
      if ($email !== '' && $this->emailValidator->isValid($email)) {
        $emails[] = $email;
      }
    }
    return array_values(array_unique($emails));
  }

  /**
   * Checks whether an entity is an accepted organisation group.
   *
   * @param mixed $group
   *   The loaded entity.
   * @param string[] $organisation_bundles
   *   Accepted group bundle IDs.
   */
  protected function isOrganisationGroup($group, array $organisation_bundles): bool {
    return $group instanceof EntityInterface
      && $group->getEntityTypeId() === 'group'
      && in_array($group->bundle(), $organisation_bundles, TRUE);
  }

  /**
   * Gets group entity storage when the Group module is available.
   */
  protected function getGroupStorage() {
    try {
      return $this->entityTypeManager->getStorage('group');
    }
    catch (\Throwable $e) {
      $this->logger->warning('Cannot sync service request organisations because the group entity type is unavailable.');
      return NULL;
    }
  }

  /**
   * Gets group relationship storage when the Group module is available.
   */
  protected function getGroupRelationshipStorage() {
    try {
      return $this->entityTypeManager->getStorage('group_relationship');
    }
    catch (\Throwable $e) {
      $this->logger->warning('Cannot sync service request organisations because the group relationship entity type is unavailable.');
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = AccessResult::allowedIf($object instanceof NodeInterface && $object->bundle() === 'service_request');
    return $return_as_object ? $result : $result->isAllowed();
  }

}
