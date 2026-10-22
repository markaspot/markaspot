<?php

namespace Drupal\markaspot_privacy\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\markaspot_privacy\ReportEmailErasureInterface;
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
   * The markaspot_dashboard split-link service, or NULL when unavailable.
   *
   * Duck-typed and resolved defensively (container->has() in create()):
   * markaspot_privacy must keep working standalone on sites that never
   * installed markaspot_dashboard.
   *
   * @var object|null
   */
  protected $requestLinkService;

  /**
   * The module handler, NULL when constructed without one.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface|null
   */
  protected ?ModuleHandlerInterface $moduleHandlerService = NULL;

  /**
   * Constructs form object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param object|null $request_link_service
   *   The markaspot_dashboard.request_link service, or NULL when the
   *   markaspot_dashboard module is not installed.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface|null $module_handler
   *   The module handler, used to announce the anonymization.
   * @param \Drupal\markaspot_privacy\ReportEmailErasureInterface|null $emailErasure
   *   Revision-aware email erasure, required when submitting erasure.
   */
  public function __construct(MessengerInterface $messenger, EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, $request_link_service = NULL, ?ModuleHandlerInterface $module_handler = NULL, protected ?ReportEmailErasureInterface $emailErasure = NULL) {
    $this->messenger = $messenger;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory->getEditable('markaspot_privacy.settings');
    $this->requestLinkService = $request_link_service;
    $this->moduleHandlerService = $module_handler;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->has('markaspot_dashboard.request_link')
        ? $container->get('markaspot_dashboard.request_link')
        : NULL,
      $container->get('module_handler'),
      $container->get('markaspot_privacy.report_email_erasure')
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
      $this->anonymizeNode($node);

      $title = $node->title->value;

      $this->messenger->addMessage($this->t('The service request "@title" has been removed from the system.', ['@title' => $title]), 'info');

      $this->logger('markaspot_privacy')->notice('User deleted %title.',
        ['%title' => $title]);

      $this->cascadeErasureToSplitSiblings((int) $node->id());

      $form_state->setRedirect('<front>');

    }

  }

  /**
   * Unpublishes a node and overwrites its citizen contact email.
   *
   * Shared by the target node and every node it cascades to.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to anonymize.
   */
  protected function anonymizeNode($node): void {
    if ($this->emailErasure === NULL) {
      throw new \LogicException('Revision-aware report email erasure is required.');
    }
    $this->emailErasure->erase($node);
    // The report is kept, so entity delete hooks do not run: let modules
    // purge data derived from it (see hook_markaspot_report_anonymized()).
    $this->moduleHandlerService?->invokeAll('markaspot_report_anonymized', [$node]);
  }

  /**
   * Cascades GDPR erasure to every node linked via markaspot_request_links.
   *
   * A split request (markaspot-ui#512) copies the citizen's PII onto a NEW
   * node the citizen never sees and did not separately consent to erase.
   * Without this cascade, erasing the original leaves the split-off sibling
   * silently holding their contact data forever. Walks the link graph in
   * BOTH directions and TRANSITIVELY (a child can itself be split again),
   * with a visited-set cycle guard. No-ops when the request-link service is
   * unavailable (markaspot_dashboard not installed).
   *
   * @param int $nid
   *   The already-anonymized node's ID.
   */
  protected function cascadeErasureToSplitSiblings(int $nid): void {
    if ($this->requestLinkService === NULL || !method_exists($this->requestLinkService, 'getLinksForNode')) {
      return;
    }

    $service = $this->requestLinkService;
    $cascadeNids = self::resolveCascadeNodeIds($nid, static fn(int $n): array => $service->getLinksForNode($n));

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    foreach ($cascadeNids as $linkedNid) {
      $linkedNode = $nodeStorage->load($linkedNid);
      if ($linkedNode !== NULL) {
        $this->anonymizeNode($linkedNode);
        $this->logger('markaspot_privacy')->notice('Cascaded GDPR erasure to split-linked node @nid.', ['@nid' => $linkedNid]);
      }
    }
  }

  /**
   * Resolves every node ID reachable via split links from $rootNid.
   *
   * Pure breadth-first graph walk, decoupled from entity loading so it is
   * unit-testable without a Drupal bootstrap: $linksForNode is called with
   * each newly-discovered node ID and must return the split-link rows
   * touching it (either direction), matching
   * RequestLinkServiceInterface::getLinksForNode()'s row shape.
   *
   * @param int $rootNid
   *   The starting (already-erased) node ID.
   * @param callable $linksForNode
   *   Callable(int $nid): array<array{source_nid: int, target_nid: int}>.
   *
   * @return int[]
   *   The distinct linked node IDs to cascade erasure to (excludes
   *   $rootNid itself), in discovery order.
   */
  public static function resolveCascadeNodeIds(int $rootNid, callable $linksForNode): array {
    $visited = [$rootNid => TRUE];
    $queue = [$rootNid];
    $result = [];

    while ($queue !== []) {
      $current = array_shift($queue);
      foreach ($linksForNode($current) as $row) {
        $linkedNid = (int) $row['source_nid'] === $current ? (int) $row['target_nid'] : (int) $row['source_nid'];
        if (isset($visited[$linkedNid])) {
          continue;
        }
        $visited[$linkedNid] = TRUE;
        $result[] = $linkedNid;
        $queue[] = $linkedNid;
      }
    }

    return $result;
  }

}
