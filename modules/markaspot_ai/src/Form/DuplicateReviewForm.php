<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\markaspot_ai\Service\DuplicateDetectionService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for reviewing a single AI duplicate match.
 */
class DuplicateReviewForm extends FormBase {

  /**
   * Constructs a DuplicateReviewForm object.
   *
   * @param \Drupal\markaspot_ai\Service\DuplicateDetectionService $duplicateDetection
   *   The duplicate detection service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   */
  public function __construct(
    protected DuplicateDetectionService $duplicateDetection,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_ai.duplicate_detection'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_ai_duplicate_review_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $match = NULL): array {
    if ($match === NULL) {
      $this->messenger()->addError($this->t('No match ID provided.'));
      return $form;
    }

    $matchRecord = $this->duplicateDetection->getMatch($match);

    if ($matchRecord === NULL) {
      $this->messenger()->addError($this->t('Match record @id not found.', [
        '@id' => $match,
      ]));
      return $form;
    }

    $form['match_id'] = [
      '#type' => 'value',
      '#value' => (int) $matchRecord['id'],
    ];

    // Load both nodes for side-by-side comparison.
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $sourceNode = $nodeStorage->load($matchRecord['source_nid']);
    $matchNode = $nodeStorage->load($matchRecord['match_nid']);

    // Match metadata.
    $form['metadata'] = [
      '#type' => 'details',
      '#title' => $this->t('Match Details'),
      '#open' => TRUE,
    ];

    $form['metadata']['info'] = [
      '#type' => 'table',
      '#header' => [$this->t('Property'), $this->t('Value')],
      '#rows' => [
        [
          $this->t('Similarity score'),
          round((float) $matchRecord['similarity_score'], 4),
        ],
        [
          $this->t('Distance'),
          $matchRecord['distance_meters'] !== NULL
            ? $this->t('@dist m', ['@dist' => round((float) $matchRecord['distance_meters'], 1)])
            : $this->t('N/A'),
        ],
        [
          $this->t('Status'),
          $matchRecord['status'],
        ],
        [
          $this->t('Detected'),
          $matchRecord['created']
            ? $this->dateFormatter->format((int) $matchRecord['created'], 'short')
            : $this->t('Unknown'),
        ],
      ],
    ];

    // Side-by-side node comparison.
    $form['comparison'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-duplicate-comparison']],
    ];

    $form['comparison']['source'] = $this->buildNodeSummary(
      $this->t('Source request'),
      $sourceNode,
      (int) $matchRecord['source_nid']
    );

    $form['comparison']['match'] = $this->buildNodeSummary(
      $this->t('Matching request'),
      $matchNode,
      (int) $matchRecord['match_nid']
    );

    // Action buttons.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['confirm'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirm duplicate'),
      '#button_type' => 'primary',
      '#name' => 'confirm',
    ];

    $form['actions']['reject'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reject match'),
      '#name' => 'reject',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('markaspot_ai.admin_processing_status'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $matchId = (int) $form_state->getValue('match_id');
    $triggeringElement = $form_state->getTriggeringElement();
    $action = $triggeringElement['#name'] ?? 'reject';

    $status = ($action === 'confirm') ? 'confirmed' : 'rejected';
    $reviewerUid = (int) $this->currentUser()->id();

    $result = $this->duplicateDetection->reviewMatch($matchId, $status, $reviewerUid);

    if ($result) {
      $this->messenger()->addStatus($this->t('Match @id has been @status.', [
        '@id' => $matchId,
        '@status' => $status,
      ]));
    }
    else {
      $this->messenger()->addError($this->t('Failed to update match @id.', [
        '@id' => $matchId,
      ]));
    }

    $form_state->setRedirectUrl(
      Url::fromRoute('markaspot_ai.admin_processing_status')
    );
  }

  /**
   * Builds a render array summarizing a node for comparison.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $label
   *   The label for this side of the comparison.
   * @param \Drupal\node\NodeInterface|null $node
   *   The node entity, or NULL if not found.
   * @param int $nid
   *   The node ID (used in fallback when node is missing).
   *
   * @return array
   *   A render array for the node summary.
   */
  protected function buildNodeSummary($label, $node, int $nid): array {
    $build = [
      '#type' => 'details',
      '#title' => $label,
      '#open' => TRUE,
    ];

    if ($node === NULL) {
      $build['missing'] = [
        '#markup' => $this->t('Node @nid no longer exists.', ['@nid' => $nid]),
      ];
      return $build;
    }

    $rows = [];

    // Title with link.
    $link = Link::fromTextAndUrl($node->getTitle(), $node->toUrl())->toString();
    $rows[] = [$this->t('Title'), $link];

    // Request ID (nid).
    $rows[] = [$this->t('ID'), $node->id()];

    // Body.
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body = $node->get('body')->value;
      $rows[] = [
        $this->t('Description'),
        mb_strimwidth(strip_tags($body), 0, 300, '...'),
      ];
    }

    // Address.
    if ($node->hasField('field_address') && !$node->get('field_address')->isEmpty()) {
      $address = $node->get('field_address')->first();
      $addressLine = $address->address_line1 ?? '';
      $locality = $address->locality ?? '';
      $rows[] = [$this->t('Address'), trim($addressLine . ', ' . $locality, ', ')];
    }

    // Category.
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      $category = $node->get('field_category')->entity;
      $rows[] = [$this->t('Category'), $category ? $category->label() : $this->t('Unknown')];
    }

    // Status.
    if ($node->hasField('field_status') && !$node->get('field_status')->isEmpty()) {
      $status = $node->get('field_status')->entity;
      $rows[] = [$this->t('Status'), $status ? $status->label() : $this->t('Unknown')];
    }

    // Created date.
    $rows[] = [
      $this->t('Created'),
      $this->dateFormatter->format($node->getCreatedTime(), 'short'),
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Field'), $this->t('Value')],
      '#rows' => $rows,
    ];

    return $build;
  }

}
