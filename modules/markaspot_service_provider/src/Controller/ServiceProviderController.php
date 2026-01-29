<?php

namespace Drupal\markaspot_service_provider\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\markaspot_service_provider\Event\ServiceProviderResponseEvent;

/**
 * Controller for service provider response requests.
 */
class ServiceProviderController extends ControllerBase
{

    /**
     * The entity type manager.
     *
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * The logger factory.
     *
     * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
     */
    protected $loggerFactory;

    /**
     * The state service.
     *
     * @var \Drupal\Core\State\StateInterface
     */
    protected $state;

    /**
     * The config factory.
     *
     * @var \Drupal\Core\Config\ConfigFactoryInterface
     */
    protected $configFactory;

    /**
     * The date formatter service.
     *
     * @var \Drupal\Core\Datetime\DateFormatterInterface
     */
    protected $dateFormatter;

    /**
     * The event dispatcher.
     *
     * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * The mail manager.
     *
     * @var \Drupal\Core\Mail\MailManagerInterface
     */
    protected $mailManager;

    /**
     * Constructs a ServiceProviderController object.
     *
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface    $entity_type_manager
     *   The entity type manager.
     * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
     *   The logger factory.
     * @param \Drupal\Core\State\StateInterface                 $state
     *   The state service.
     * @param \Drupal\Core\Config\ConfigFactoryInterface        $config_factory
     *   The config factory.
     * @param \Drupal\Core\Datetime\DateFormatterInterface      $date_formatter
     *   The date formatter service.
     * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
     *   The event dispatcher.
     * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
     *   The mail manager.
     */
    public function __construct(
        EntityTypeManagerInterface $entity_type_manager,
        LoggerChannelFactoryInterface $logger_factory,
        StateInterface $state,
        ConfigFactoryInterface $config_factory,
        DateFormatterInterface $date_formatter,
        EventDispatcherInterface $event_dispatcher,
        MailManagerInterface $mail_manager,
    ) {
        $this->entityTypeManager = $entity_type_manager;
        $this->loggerFactory = $logger_factory;
        $this->state = $state;
        $this->configFactory = $config_factory;
        $this->dateFormatter = $date_formatter;
        $this->eventDispatcher = $event_dispatcher;
        $this->mailManager = $mail_manager;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('entity_type.manager'),
            $container->get('logger.factory'),
            $container->get('state'),
            $container->get('config.factory'),
            $container->get('date.formatter'),
            $container->get('event_dispatcher'),
            $container->get('plugin.manager.mail')
        );
    }

    /**
     * Updates service provider response for a service request.
     *
     * @param string                                    $uuid
     *   The UUID of the service request.
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The request object.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   A JSON response.
     */
    public function updateResponse($uuid, Request $request): JsonResponse
    {
        $logger = $this->loggerFactory->get('markaspot_service_provider');

        try {
            // Get the JSON data from the request body.
            $content = $request->getContent();
            $data = json_decode($content, true);

            if (empty($data)) {
                $logger->warning('Empty request data for service provider response on UUID: @uuid', ['@uuid' => $uuid]);
                return new JsonResponse(['message' => $this->t('No data provided')], 400);
            }

            // Load the node by UUID.
            $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $uuid]);

            if (empty($nodes)) {
                $logger->warning('No node found with UUID: @uuid', ['@uuid' => $uuid]);
                return new JsonResponse(['message' => $this->t('Service request not found')], 404);
            }

            $node = reset($nodes);

            // Check if the node is a service request.
            if ($node->getType() != 'service_request') {
                $logger->warning('Node with UUID @uuid is not a service request', ['@uuid' => $uuid]);
                return new JsonResponse(['message' => $this->t('Not a service request')], 400);
            }

            // Normalize email verification from either JSON body or query param.
            $email_verification = $data['email_verification'] ?? $request->query->get('email_verification');

            // Check if multiple completions are allowed.
            $existing_completions = $node->get('field_service_provider_notes')->getValue();

            if (!empty($existing_completions)) {
                $reassignment_allowed = false;
                if ($node->hasField('field_reassign_sp') && !$node->get('field_reassign_sp')->isEmpty()) {
                    $reassignment_allowed = $node->get('field_reassign_sp')->value;
                }

                // Check if last note is from service provider.
                // If last note is from moderator, allow submission even without reassignment flag.
                $last_note_from_sp = false;
                $last_completion = end($existing_completions);
                if (!empty($last_completion['value'])) {
                    $last_note_text = $last_completion['value'];
                    if (strpos($last_note_text, '<!-- source:sp -->') !== false) {
                        $last_note_from_sp = true;
                    } elseif (strpos($last_note_text, '<!-- source:moderator -->') !== false) {
                        $last_note_from_sp = false;
                    }
                }

                if (!$reassignment_allowed && $last_note_from_sp) {
                    $logger->warning(
                        'Service provider @email attempted completion without reassignment flag for node @nid', [
                        '@email' => $email_verification,
                        '@nid' => $node->id(),
                        ]
                    );
                    return new JsonResponse(
                        [
                        'message' => $this->t('Service request already completed. Contact administrator for reassignment.'),
                        'error_code' => 'ALREADY_COMPLETED',
                        ], 403
                    );
                }
            }

            // Validate email against service provider field.
            if (!empty($email_verification)) {
                $validation_result = $this->validateServiceProviderEmail($node, $email_verification);

                if ($validation_result !== true) {
                    $logger->warning(
                        'Service provider validation failed for @email on node @nid: @reason', [
                        '@email' => $email_verification,
                        '@nid' => $node->id(),
                        '@reason' => $validation_result,
                        ]
                    );
                    return new JsonResponse(
                        [
                        'message' => $validation_result,
                        'error_code' => 'EMAIL_NOT_AUTHORIZED',
                        ], 403
                    );
                }
            }

            // Update the completion notes.
            if (isset($data['completion_notes'])) {
                if ($node->hasField('field_service_provider_notes')) {
                    $this->addServiceProviderCompletion($node, $email_verification, $data['completion_notes']);
                }
                elseif ($node->hasField('field_sp_feedback')) {
                    $node->set('field_sp_feedback', $data['completion_notes']);
                }
            }

            // Update the status if set_status flag is set.
            if (!empty($data['set_status'])) {
                $config = $this->configFactory->get('markaspot_service_provider.settings');
                $completion_status = $config->get('completion_status_tid');

                if (!empty($completion_status)) {
                    $status_tid = is_array($completion_status) ? reset($completion_status) : $completion_status;
                    $node->set('field_status', $status_tid);

                    // Add service provider status note if configured.
                    $status_note = $config->get('status_note');
                    if (!empty($status_note)) {
                        $this->addStatusNote($node, $status_note);
                    }
                }
            }

            // Save the node.
            $node->save();

            // Dispatch event for ECA to handle notifications.
            $event = new ServiceProviderResponseEvent($node, $email_verification ?? '', $data['completion_notes'] ?? '');
            $this->eventDispatcher->dispatch($event, ServiceProviderResponseEvent::EVENT_NAME);

            $logger->notice(
                'Updated service provider response for node @nid (UUID: @uuid)', [
                '@nid' => $node->id(),
                '@uuid' => $uuid,
                ]
            );

            return new JsonResponse(
                [
                'message' => $this->t('Danke für die Rückmeldung'),
                'nid' => $node->id(),
                'success' => true,
                ]
            );
        }
        catch (\Exception $e) {
            $logger->error(
                'Error updating service provider response for UUID @uuid: @error', [
                '@uuid' => $uuid,
                '@error' => $e->getMessage(),
                ]
            );

            return new JsonResponse(['message' => $this->t('Error processing response')], 500);
        }
    }

    /**
     * Gets service request data by UUID.
     *
     * Returns basic info requiring authentication via POST /auth endpoint.
     *
     * @param string                                    $uuid
     *   The UUID of the service request.
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The request object.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   A JSON response with service request data.
     */
    public function getServiceRequest($uuid, Request $request): JsonResponse
    {
        $logger = $this->loggerFactory->get('markaspot_service_provider');

        try {
            // Load the node by UUID.
            $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $uuid]);

            if (empty($nodes)) {
                $logger->warning('No node found with UUID: @uuid', ['@uuid' => $uuid]);
                return new JsonResponse(['message' => $this->t('Service request not found')], 404);
            }

            $node = reset($nodes);

            // Check if the node is a service request.
            if ($node->getType() != 'service_request') {
                $logger->warning('Node with UUID @uuid is not a service request', ['@uuid' => $uuid]);
                return new JsonResponse(['message' => $this->t('Not a service request')], 400);
            }

            // GET endpoint no longer accepts email in query string for security.
            // Return 401 requiring authentication via POST /auth endpoint.
            return new JsonResponse(
                [
                'message' => $this->t('Authentication required'),
                'error_code' => 'AUTH_REQUIRED',
                'uuid' => $uuid,
                'title' => $node->getTitle(),
                ], 401
            );
        }
        catch (\Exception $e) {
            $logger->error(
                'Error getting service request for UUID @uuid: @error', [
                '@uuid' => $uuid,
                '@error' => $e->getMessage(),
                ]
            );

            return new JsonResponse(['message' => $this->t('Error retrieving service request')], 500);
        }
    }

    /**
     * Authenticates service provider and returns full service request data.
     *
     * Uses POST with email in request body for better security (not in URL/logs).
     *
     * @param string                                    $uuid
     *   The UUID of the service request.
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The request object containing email in JSON body.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   A JSON response with full service request data on success.
     */
    public function authenticateServiceProvider($uuid, Request $request): JsonResponse
    {
        $logger = $this->loggerFactory->get('markaspot_service_provider');

        try {
            // Get the JSON data from the request body.
            $content = $request->getContent();
            $data = json_decode($content, true);

            // Get email from request body.
            $email = $data['email'] ?? null;

            if (empty($email)) {
                return new JsonResponse(
                    [
                    'message' => $this->t('Email is required'),
                    'error_code' => 'EMAIL_REQUIRED',
                    ], 400
                );
            }

            // Load the node by UUID.
            $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $uuid]);

            if (empty($nodes)) {
                $logger->warning('No node found with UUID: @uuid', ['@uuid' => $uuid]);
                return new JsonResponse(['message' => $this->t('Service request not found')], 404);
            }

            $node = reset($nodes);

            // Check if the node is a service request.
            if ($node->getType() != 'service_request') {
                $logger->warning('Node with UUID @uuid is not a service request', ['@uuid' => $uuid]);
                return new JsonResponse(['message' => $this->t('Not a service request')], 400);
            }

            // Validate email against service provider.
            $validation_result = $this->validateServiceProviderEmail($node, $email);

            if ($validation_result !== true) {
                $logger->warning(
                    'Service provider email validation failed on node @nid', [
                    '@nid' => $node->id(),
                    ]
                );
                return new JsonResponse(
                    [
                    'message' => $this->t('Email not authorized'),
                    'error_code' => 'EMAIL_NOT_AUTHORIZED',
                    ], 401
                );
            }

            // Email validated. Build full response with all node data.
            $response_data = $this->buildFullNodeResponse($node);

            $logger->notice(
                'Authenticated service provider retrieved full data for node @nid (UUID: @uuid)', [
                '@nid' => $node->id(),
                '@uuid' => $uuid,
                ]
            );

            return new JsonResponse($response_data);
        }
        catch (\Exception $e) {
            $logger->error(
                'Error authenticating service provider for UUID @uuid: @error', [
                '@uuid' => $uuid,
                '@error' => $e->getMessage(),
                ]
            );

            return new JsonResponse(['message' => $this->t('Error processing authentication')], 500);
        }
    }

    /**
     * Builds the full node response with all fields for authenticated requests.
     *
     * @param \Drupal\node\NodeInterface $node
     *   The service request node.
     *
     * @return array
     *   Full response data array.
     */
    protected function buildFullNodeResponse($node): array
    {
        // Get status term.
        $status_term = null;
        $status_id = null;
        if ($node->hasField('field_status') && !$node->get('field_status')->isEmpty()) {
            $status_id = $node->get('field_status')->target_id;
            $status_term = $node->get('field_status')->entity;
        }

        // Get category term.
        $category_term = null;
        $category_id = null;
        if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
            $category_id = $node->get('field_category')->target_id;
            $category_term = $node->get('field_category')->entity;
        }

        // Get service provider term.
        $sp_term = null;
        if ($node->hasField('field_service_provider') && !$node->get('field_service_provider')->isEmpty()) {
            $sp_term = $node->get('field_service_provider')->entity;
        }

        // Build response.
        $response_data = [
            // Core node data.
            'nid' => $node->id(),
            'uuid' => $node->uuid(),
            'title' => $node->getTitle(),
            'created' => $node->getCreatedTime(),
            'changed' => $node->getChangedTime(),
            'authenticated' => true,

            // Request ID.
            'request_id' => $node->hasField('field_request_id') ? $node->get('field_request_id')->value : null,

            // Status with term name.
            'status' => [
                'id' => $status_id,
                'name' => $status_term?->getName(),
            ],

            // Category with term name.
            'category' => [
                'id' => $category_id,
                'name' => $category_term?->getName(),
            ],

            // Address.
            'address' => $node->hasField('field_address') ? $node->get('field_address')->getValue()[0] ?? null : null,

            // Geolocation.
            'geolocation' => $this->getGeolocation($node),

            // Description.
            'description' => $node->hasField('field_description') ? $node->get('field_description')->value : null,

            // Photos/Media.
            'photos' => $this->getMediaUrls($node),

            // Service provider info.
            'service_provider' => [
                'id' => $sp_term?->id(),
                'name' => $sp_term?->getName(),
            ],

            // Completion data.
            'completions' => $this->getCompletionNotes($node),
            'completion_count' => 0,
            'reassignment_allowed' => (bool) ($node->hasField('field_reassign_sp') ? $node->get('field_reassign_sp')->value : false),
        ];

        // Set completion count.
        $response_data['completion_count'] = count($response_data['completions']);

        // Check if last note is from service provider using structured data.
        $last_note_from_sp = false;
        if (!empty($response_data['completions'])) {
            $last_note = end($response_data['completions']);
            $last_note_from_sp = ($last_note['source'] ?? '') === 'service_provider';
        }
        $response_data['last_note_from_sp'] = $last_note_from_sp;

        return $response_data;
    }

    /**
     * Gets geolocation data from the node.
     *
     * @param \Drupal\node\NodeInterface $node
     *   The service request node.
     *
     * @return array|null
     *   Geolocation array with lat/lng or null.
     */
    protected function getGeolocation($node): ?array
    {
        if (!$node->hasField('field_geolocation') || $node->get('field_geolocation')->isEmpty()) {
            return null;
        }

        $geo = $node->get('field_geolocation')->first();
        if (!$geo) {
            return null;
        }

        return [
            'lat' => $geo->get('lat')->getValue(),
            'lng' => $geo->get('lng')->getValue(),
        ];
    }

    /**
     * Gets media URLs from the node.
     *
     * @param \Drupal\node\NodeInterface $node
     *   The service request node.
     *
     * @return array
     *   Array of photo data with url and alt.
     */
    protected function getMediaUrls($node): array
    {
        $photos = [];

        if (!$node->hasField('field_request_media') || $node->get('field_request_media')->isEmpty()) {
            return $photos;
        }

        foreach ($node->get('field_request_media') as $item) {
            $media = $item->entity;
            if (!$media) {
                continue;
            }

            // Check for image field on media entity.
            if ($media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
                $file = $media->get('field_media_image')->entity;
                if ($file) {
                    // Use relative URL to avoid internal hostname issues with proxied requests.
                    $photos[] = [
                        'url' => \Drupal::service('file_url_generator')
                            ->generateString($file->getFileUri()),
                        'alt' => $media->get('field_media_image')->alt ?? '',
                    ];
                }
            }
        }

        return $photos;
    }

    /**
     * Gets completion notes from the node as structured data.
     *
     * @param \Drupal\node\NodeInterface $node
     *   The service request node.
     *
     * @return array
     *   Array of structured completion note objects with keys:
     *   - text: The message content (before metadata)
     *   - source: 'service_provider' or 'moderator'
     *   - author: The author name or email
     *   - date: The timestamp string
     *   - raw: The original HTML for rendering
     */
    protected function getCompletionNotes($node): array
    {
        if (!$node->hasField('field_service_provider_notes') || $node->get('field_service_provider_notes')->isEmpty()) {
            return [];
        }

        $notes = $node->get('field_service_provider_notes')->getValue();
        return array_map(
            function ($note) {
                return $this->parseCompletionNote($note['value']);
            }, $notes
        );
    }

    /**
     * Parses a completion note into structured data.
     *
     * @param string $raw_note
     *   The raw HTML note content.
     *
     * @return array
     *   Structured note data.
     */
    protected function parseCompletionNote(string $raw_note): array
    {
        $result = [
            'text' => '',
            'source' => 'unknown',
            'author' => '',
            'date' => '',
            'raw' => $raw_note,
        ];

        // Split by --- separator (handles both HTML and plain text formats).
        $parts = preg_split('/(<br\s*\/?>\s*)*---(<br\s*\/?>)?|\n---\n?/', $raw_note, 2);

        if (!empty($parts[0])) {
            // Clean up the text part (remove leading/trailing br tags).
            $text = preg_replace('/^(<br\s*\/?>|\s)+|(<br\s*\/?>|\s)+$/i', '', $parts[0]);
            $result['text'] = trim(strip_tags($text));
        }

        // Determine source from marker.
        if (strpos($raw_note, '<!-- source:sp -->') !== false) {
            $result['source'] = 'service_provider';
        } elseif (strpos($raw_note, '<!-- source:moderator -->') !== false) {
            $result['source'] = 'moderator';
        }

        return $result;
    }

    /**
     * Validates that the provided email matches the assigned service provider.
     *
     * @param \Drupal\node\Entity\Node $node
     *   The service request node.
     * @param string                   $email
     *   The email to validate.
     *
     * @return bool|string
     *   TRUE if the email matches the service provider, error message string otherwise.
     */
    protected function validateServiceProviderEmail($node, $email): bool|string
    {
        // Check if the node has a service provider assigned.
        if (!$node->hasField('field_service_provider') || $node->get('field_service_provider')->isEmpty()) {
            return $this->t('No service provider assigned to this service request');
        }

        // Get the referenced service provider taxonomy term.
        $service_provider_term = $node->get('field_service_provider')->entity;
        if (!$service_provider_term) {
            return $this->t('Service provider configuration error');
        }

        // Check if the service provider has an email field.
        if (!$service_provider_term->hasField('field_sp_email') || $service_provider_term->get('field_sp_email')->isEmpty()) {
            return $this->t('Service provider has no email address configured');
        }

        // Get all service provider emails (multi-value field).
        $service_provider_emails = $service_provider_term->get('field_sp_email')->getValue();
        $valid_emails = [];
        $provided_email_clean = strtolower(trim($email));

        // Check against all configured email addresses.
        foreach ($service_provider_emails as $email_item) {
            $sp_email_clean = strtolower(trim($email_item['value']));
            $valid_emails[] = $email_item['value'];

            if ($provided_email_clean === $sp_email_clean) {
                return true;
            }
        }

        // Generic error message (don't expose valid email addresses for security).
        $error_message = $this->t('The provided email address is not authorized for this service request');

        // Log validation failure (don't expose valid emails for security)
        $this->loggerFactory->get('markaspot_service_provider')->warning(
            'Service provider email validation failed for node @nid', [
            '@nid' => $node->id(),
            ]
        );

        return $error_message;
    }

    /**
     * Get all valid email addresses for a service provider.
     *
     * @param \Drupal\node\NodeInterface $node
     *   The service request node.
     *
     * @return array
     *   Array of valid email addresses for the assigned service provider.
     */
    protected function getServiceProviderEmails($node): array
    {
        $valid_emails = [];

        if (!$node->hasField('field_service_provider') || $node->get('field_service_provider')->isEmpty()) {
            return $valid_emails;
        }

        $service_provider_term = $node->get('field_service_provider')->entity;
        if (!$service_provider_term) {
            return $valid_emails;
        }

        if (!$service_provider_term->hasField('field_sp_email') || $service_provider_term->get('field_sp_email')->isEmpty()) {
            return $valid_emails;
        }

        $service_provider_emails = $service_provider_term->get('field_sp_email')->getValue();

        foreach ($service_provider_emails as $email_item) {
            if (!empty($email_item['value'])) {
                $valid_emails[] = trim($email_item['value']);
            }
        }

        return $valid_emails;
    }

    /**
     * Add a status note paragraph to a service request node.
     *
     * @param \Drupal\node\NodeInterface $node
     *   The service request node.
     * @param string                     $note_text
     *   The status note text to add.
     */
    protected function addStatusNote($node, $note_text): void
    {
        // Get the current status to associate with this note.
        $current_status = $node->hasField('field_status') ? $node->get('field_status')->target_id : null;

        // Convert line breaks to <br> tags for proper display.
        $note_text_html = nl2br($note_text, false);

        $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
        $status_note_paragraph = $paragraph_storage->create(
            [
            'type' => 'status',
            'field_status_note' => [
            'value' => $note_text_html,
            'format' => 'basic_html',
            ],
            'field_status_term' => [
            'target_id' => $current_status,
            ],
            ]
        );
        $status_note_paragraph->save();

        // Use field_status_notes (same as service_request.module) not field_notes.
        $notes = $node->get('field_status_notes')->getValue();
        $notes[] = [
        'target_id' => $status_note_paragraph->id(),
        'target_revision_id' => $status_note_paragraph->getRevisionId(),
        ];
        $node->set('field_status_notes', $notes);
    }

    /**
     * Add a service provider completion entry to the multi-value notes field.
     *
     * @param \Drupal\node\NodeInterface $node
     *   The service request node.
     * @param string                     $email
     *   The service provider email.
     * @param string                     $completion_notes
     *   The completion notes from the service provider.
     */
    protected function addServiceProviderCompletion($node, $email, $completion_notes): void
    {
        // Get service provider information.
        $service_provider_name = '';
        if ($node->hasField('field_service_provider') && !$node->get('field_service_provider')->isEmpty()) {
            $service_provider_term = $node->get('field_service_provider')->entity;
            if ($service_provider_term) {
                $service_provider_name = $service_provider_term->getName();
            }
        }

        // Create translatable metadata footer with German formatting.
        $timestamp = $this->dateFormatter->format(time(), 'custom', 'd.m.Y - H:i', 'Europe/Berlin');

        $metadata_footer = "\n\n---\n" .
        $this->t('Abgeschlossen von: @email', ['@email' => $email]) . "\n" .
        $this->t('Abgeschlossen am: @timestamp', ['@timestamp' => $timestamp]) . "\n" .
        $this->t('Dienstleister: @name', ['@name' => $service_provider_name ?: $this->t('Unbekannt')]);

        // Add source marker for language-agnostic parsing.
        $completion_entry = '<!-- source:sp -->' . $completion_notes . $metadata_footer;

        // Convert line breaks to <br> tags for proper display in WYSIWYG and frontend.
        // nl2br() converts \n to <br>\n.
        $completion_entry_html = nl2br($completion_entry, false);

        // Add to multi-value field.
        if ($node->hasField('field_service_provider_notes')) {
            $current_notes = $node->get('field_service_provider_notes')->getValue();
            $current_notes[] = [
            'value' => $completion_entry_html,
            'format' => 'basic_html',
            ];
            $node->set('field_service_provider_notes', $current_notes);
        }
    }

}
