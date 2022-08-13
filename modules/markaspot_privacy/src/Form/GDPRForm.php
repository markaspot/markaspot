<?php

namespace Drupal\markaspot_privacy\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(MessengerInterface $messenger, EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory) {
    $this->messenger = $messenger;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory->getEditable('markaspot_privacy.settings');
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger'),
      $container->get('entity_type.manager'),
      $container->get('config.factory')
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
    $node = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $uuid]);

    if (empty($node)) {
      $this->messenger->addMessage($this->t("Sorry, we can't find the content requested. Maybe this has been deleted already."), 'error');
    }
    else {

      // We only have one node as loaded by uuid.
      $node = reset($node);
      $node->setUnpublished();
      $node->set('field_e_mail', "anasasaonymous@example.off");
      $node->save();

      $title = $node->title->value;

      $this->messenger->addMessage($this->t('The service request "@title" has been removed from the system.', ['@title' => $title]), 'info');

      $this->logger('markaspot_privacy')->notice('User deleted %title.',
        ['%title' => $title]);

      $form_state->setRedirect('<front>');

    }

  }

}
