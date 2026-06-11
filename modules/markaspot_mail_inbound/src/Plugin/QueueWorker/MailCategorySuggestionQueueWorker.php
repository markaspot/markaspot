<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Service\InboundMailPromoter;
use Drupal\markaspot_mail_inbound\Service\MailCategorySuggestionService;
use Drupal\markaspot_mail_inbound\Service\PromotableCategoryRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes AI category suggestions for staged inbound mails.
 *
 * Items are enqueued by MailIngestOrchestrator::stageMail() as
 * ['mail_id' => int] when the suggestion service reports it could run
 * (cheap static gate: module setting + at least one AI service wired).
 *
 * After a successful TEXT suggestion, the auto-promote gate is checked:
 * settings auto_promote_enabled (default FALSE) + threshold + is_report must
 * be true + the category must exist and be jurisdiction-scoped (isPromotable).
 * Both coded and codeless categories are eligible for auto-promote. Vision
 * suggestions are never auto-promoted because no numeric confidence is
 * available. When is_report=false the text path stores confidence=NULL, which
 * also suppresses auto-promote via the same NULL guard.
 *
 * Failure policy: fail-fast. Entity load/save failures are rethrown for
 * Drupal's retry mechanism. Other errors (AI call failures, invalid responses)
 * are recorded as suggestion_status=failed by the suggestion service itself
 * and are NOT rethrown; the item is dropped without retry.
 *
 * Cron time ~30 seconds: suggestions are fast (one AI call per mail) and the
 * queue is expected to be shallow.
 */
#[QueueWorker(
  id: 'markaspot_mail_inbound_suggest',
  title: new TranslatableMarkup('AI category suggestions for inbound mail'),
  cron: ['time' => 30],
)]
final class MailCategorySuggestionQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the queue worker.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin id.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\markaspot_mail_inbound\Service\MailCategorySuggestionService $suggestionService
   *   The suggestion service.
   * @param \Drupal\markaspot_mail_inbound\Service\InboundMailPromoter $promoter
   *   The promoter (for auto-promote).
   * @param \Drupal\markaspot_mail_inbound\Service\PromotableCategoryRepository $categoryRepository
   *   Category promotability checker.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory for auto-promote settings.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Logger channel.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MailCategorySuggestionService $suggestionService,
    protected InboundMailPromoter $promoter,
    protected PromotableCategoryRepository $categoryRepository,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('markaspot_mail_inbound.category_suggestion'),
      $container->get('markaspot_mail_inbound.promoter'),
      $container->get('markaspot_mail_inbound.category_repository'),
      $container->get('config.factory'),
      $container->get('logger.factory')->get('markaspot_mail_inbound'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $mailId = is_array($data) ? (int) ($data['mail_id'] ?? 0) : 0;
    if ($mailId <= 0) {
      $this->logger->warning('AI suggestion queue item missing mail_id; dropping.');
      return;
    }

    /** @var \Drupal\markaspot_mail_inbound\Entity\InboundMail|null $mail */
    $mail = $this->entityTypeManager->getStorage('inbound_mail')->load($mailId);
    if ($mail === NULL) {
      // Mail was deleted while queued; no retry needed.
      $this->logger->info('AI suggestion queue: mail @id no longer exists; dropping item.', ['@id' => $mailId]);
      return;
    }

    if ($mail->getState() !== InboundMail::STATE_STAGED) {
      // Already promoted or discarded; suggestion is no longer relevant.
      $this->logger->info('AI suggestion queue: mail @id is @state, not staged; dropping item.', [
        '@id' => $mailId,
        '@state' => $mail->getState(),
      ]);
      return;
    }

    // Run the suggestion. This updates suggestion_status and the related fields
    // directly on the entity and saves it.
    try {
      $this->suggestionService->suggest($mail);
    }
    catch (\Throwable $e) {
      $this->logger->warning('AI suggestion for mail @id threw @type; requeueing.', [
        '@id' => $mailId,
        '@type' => get_class($e),
      ]);
      throw $e;
    }

    // Re-load to pick up the saved state (suggest() may have saved internally).
    /** @var \Drupal\markaspot_mail_inbound\Entity\InboundMail|null $mail */
    $mail = $this->entityTypeManager->getStorage('inbound_mail')->load($mailId);
    if ($mail === NULL || $mail->getSuggestionStatus() !== InboundMail::SUGGESTION_DONE) {
      return;
    }

    $this->maybeAutoPromote($mail);
  }

  /**
   * Checks the auto-promote gate and promotes when all conditions are met.
   *
   * Gate:
   *  1. settings.auto_promote_enabled must be TRUE (default FALSE)
   *  2. settings.auto_promote_confidence_threshold (default 0.85)
   *  3. confidence >= threshold (NULL = vision path, never auto-promotes)
   *  4. suggested category is jurisdiction-scoped and valid (isPromotable);
   *     both coded and codeless categories satisfy this gate.
   *
   * @param \Drupal\markaspot_mail_inbound\Entity\InboundMail $mail
   *   A mail whose suggestion_status is DONE.
   */
  protected function maybeAutoPromote(InboundMail $mail): void {
    $settings = $this->configFactory->get('markaspot_mail_inbound.settings');

    if (!($settings->get('auto_promote_enabled') ?? FALSE)) {
      return;
    }

    $threshold = (float) ($settings->get('auto_promote_confidence_threshold') ?? 0.85);
    $confidence = $mail->getSuggestionConfidence();

    // Vision path has no confidence (NULL); never auto-promote vision results.
    if ($confidence === NULL || $confidence < $threshold) {
      return;
    }

    $tid = $mail->getSuggestedCategoryTid();
    if ($tid === NULL || $tid <= 0) {
      return;
    }

    if (!$this->categoryRepository->isPromotable($tid, $mail->getJurisdictionId())) {
      $this->logger->info('Auto-promote skipped for mail @id: suggested tid @tid is not valid for this jurisdiction.', [
        '@id' => $mail->id(),
        '@tid' => $tid,
      ]);
      return;
    }

    // Pass suggested address as an address hint for geocoding.
    $suggestedAddress = $mail->getSuggestedAddress();

    try {
      $node = $this->promoter->promoteToServiceRequest($mail, $tid, $suggestedAddress);
      $this->logger->notice('Auto-promoted mail @id to service request @nid (tid @tid, confidence @conf).', [
        '@id' => $mail->id(),
        '@nid' => $node->id(),
        '@tid' => $tid,
        '@conf' => round($confidence, 3),
      ]);
    }
    catch (\RuntimeException $e) {
      // Promoter not available or state changed between suggestion and now.
      $this->logger->warning('Auto-promote of mail @id failed (@type); not retrying.', [
        '@id' => $mail->id(),
        '@type' => get_class($e),
      ]);
    }
    catch (\Throwable $e) {
      // Transient error: rethrow so the queue retries.
      $this->logger->error('Auto-promote of mail @id threw @type; requeueing.', [
        '@id' => $mail->id(),
        '@type' => get_class($e),
      ]);
      throw $e;
    }
  }

}
