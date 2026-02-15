<?php

namespace Drupal\markaspot_contact;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\contact\MailHandlerInterface;

/**
 * Service for handling contact form submissions via REST API.
 */
class ContactService implements ContactServiceInterface {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The contact mail handler.
   *
   * @var \Drupal\contact\MailHandlerInterface
   */
  protected $mailHandler;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new ContactService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\contact\MailHandlerInterface $mail_handler
   *   The contact mail handler.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    FloodInterface $flood,
    AccountProxyInterface $current_user,
    MailHandlerInterface $mail_handler,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->flood = $flood;
    $this->currentUser = $current_user;
    $this->mailHandler = $mail_handler;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function validateInput(array $data): array {
    $errors = [];

    if (empty($data['name'])) {
      $errors[] = 'Name is required.';
    }

    if (empty($data['mail'])) {
      $errors[] = 'Email address is required.';
    }
    elseif (!filter_var($data['mail'], FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'A valid email address is required.';
    }

    if (empty($data['message'])) {
      $errors[] = 'Message is required.';
    }

    // Check GDPR consent if required.
    $config = $this->configFactory->get('markaspot_contact.settings');
    if ($config->get('gdpr_required') && empty($data['gdpr'])) {
      $errors[] = 'GDPR consent is required.';
    }

    // Honeypot: if the hidden field is filled, it's a bot.
    if (!empty($data['website'])) {
      $errors[] = 'Spam detected.';
    }

    // Time check: form must be open for at least 3 seconds.
    if (!empty($data['form_token'])) {
      $submitted_time = (int) base64_decode($data['form_token']);
      $elapsed = time() - $submitted_time;
      if ($elapsed < 3) {
        $errors[] = 'Spam detected.';
      }
    }

    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function submitContactForm(array $data): array {
    $logger = $this->loggerFactory->get('markaspot_contact');
    $config = $this->configFactory->get('markaspot_contact.settings');

    // Validate input.
    $errors = $this->validateInput($data);
    if (!empty($errors)) {
      return [
        'success' => FALSE,
        'message' => 'Validation failed.',
        'errors' => $errors,
        'code' => 400,
      ];
    }

    // Check flood control.
    $limit = $config->get('flood_limit') ?: 5;
    $interval = $config->get('flood_interval') ?: 3600;

    if (!$this->flood->isAllowed('markaspot_contact', $limit, $interval)) {
      $logger->warning('Flood control triggered for contact form submission from @mail.', [
        '@mail' => $data['mail'],
      ]);
      return [
        'success' => FALSE,
        'message' => 'Too many submissions. Please try again later.',
        'code' => 429,
      ];
    }

    try {
      // Determine which contact form to use.
      $form_id = $config->get('contact_form');
      if (empty($form_id)) {
        $form_id = $this->configFactory->get('contact.settings')->get('default_form');
      }

      if (empty($form_id)) {
        $logger->error('No contact form configured and no default form found.');
        return [
          'success' => FALSE,
          'message' => 'Contact form is not configured.',
          'code' => 500,
        ];
      }

      // Verify the contact form exists.
      $contact_form = $this->entityTypeManager->getStorage('contact_form')->load($form_id);
      if (!$contact_form) {
        $logger->error('Contact form @form_id does not exist.', ['@form_id' => $form_id]);
        return [
          'success' => FALSE,
          'message' => 'Contact form is not configured.',
          'code' => 500,
        ];
      }

      // Create the contact_message entity.
      $storage = $this->entityTypeManager->getStorage('contact_message');
      $message = $storage->create([
        'contact_form' => $form_id,
        'name' => $data['name'],
        'mail' => $data['mail'],
        'subject' => 'Contact form: ' . $data['name'],
        'message' => $data['message'],
        'copy' => !empty($data['copy']),
      ]);

      // Set GDPR consent field if available.
      if ($message->hasField('field_feedback_gdpr') && !empty($data['gdpr'])) {
        $message->set('field_feedback_gdpr', (bool) $data['gdpr']);
      }

      // contact_message uses ContentEntityNullStorage, so save() is a no-op.
      // We call it for best practice, but mail is ONLY sent via the handler.
      $message->save();

      // Send the mail. This is the only way to actually dispatch the email.
      $this->mailHandler->sendMailMessages($message, $this->currentUser);

      // Register flood event.
      $this->flood->register('markaspot_contact', $interval);

      $logger->notice('Contact form submitted by @name (@mail).', [
        '@name' => $data['name'],
        '@mail' => $data['mail'],
      ]);

      return [
        'success' => TRUE,
        'message' => 'Your message has been sent.',
      ];
    }
    catch (\Exception $e) {
      $logger->error('Error submitting contact form: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [
        'success' => FALSE,
        'message' => 'An error occurred while sending your message.',
        'code' => 500,
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFormInfo(): array {
    $config = $this->configFactory->get('markaspot_contact.settings');

    // Determine the form ID.
    $form_id = $config->get('contact_form');
    if (empty($form_id)) {
      $form_id = $this->configFactory->get('contact.settings')->get('default_form');
    }

    $form_label = '';
    if (!empty($form_id)) {
      $contact_form = $this->entityTypeManager->getStorage('contact_form')->load($form_id);
      if ($contact_form) {
        $form_label = $contact_form->label();
      }
    }

    return [
      'active' => !empty($form_id),
      'form_label' => $form_label,
      'gdpr_required' => (bool) $config->get('gdpr_required'),
    ];
  }

}
