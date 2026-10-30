<?php

namespace Drupal\markaspot_vision\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;
use Drupal\markaspot_vision\Service\MediaTenantScopeResolver;
use Drupal\markaspot_vision\Service\OriginalImageStore;
use Drupal\markaspot_vision\Service\MediaIntakeScreening;
use Drupal\markaspot_vision\Service\ScreeningMarker;
use Drupal\markaspot_ai\Utility\BlurPolicy;

/**
 * Controller for processing images with AI vision services.
 */
class ImageProcessingController extends ControllerBase {

  /**
   * The Image Processing Service.
   *
   * @var \Drupal\markaspot_vision\Service\ImageProcessingService
   */
  protected ImageProcessingService $imageProcessingService;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected FloodInterface $flood;

  /**
   * The tier config service.
   *
   * @var \Drupal\markaspot_fastmap\Service\TierConfigService|null
   */
  protected ?TierConfigService $tierConfig;

  /**
   * Keeps unblurred originals for staff, where the site opted in.
   */
  protected ?OriginalImageStore $originalImageStore;

  /**
   * The feature flag checker.
   *
   * @var \Drupal\markaspot_nuxt\Service\FeatureScopeResolver
   */
  protected FeatureScopeResolver $featureScopeResolver;

  /**
   * Guards media analysis access for anonymous upload flows.
   *
   * @var \Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard
   */
  protected MediaAnalysisAccessGuard $mediaAnalysisAccessGuard;

  /**
   * Vision configuration factory.
   */
  protected ConfigFactoryInterface $visionConfigFactory;

  /**
   * Resolves the tenant the analyzed media belong to.
   */
  protected MediaTenantScopeResolver $mediaTenantScopeResolver;

  /**
   * Constructs a new ImageProcessingController object.
   *
   * @param \Drupal\markaspot_vision\Service\ImageProcessingService $image_processing_service
   *   The image processing service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\markaspot_nuxt\Service\FeatureScopeResolver $feature_scope_resolver
   *   The effective feature scope resolver.
   * @param \Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard $media_analysis_access_guard
   *   Guards access to media analysis.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\markaspot_vision\Service\MediaTenantScopeResolver $media_tenant_scope_resolver
   *   Resolves the tenant the analyzed media belong to.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValueFactory
   *   Provides separate intake and editorial-publication collections.
   * @param \Drupal\markaspot_fastmap\Service\TierConfigService|null $tier_config
   *   The tier config service (optional, only on SaaS).
   * @param \Drupal\markaspot_vision\Service\OriginalImageStore|null $original_image_store
   *   The original image store (optional).
   * @param \Drupal\Core\Lock\LockBackendInterface|null $operationLock
   *   Shared publication and tenant-budget locks.
   */
  public function __construct(
    ImageProcessingService $image_processing_service,
    LoggerChannelFactoryInterface $logger_factory,
    FloodInterface $flood,
    FeatureScopeResolver $feature_scope_resolver,
    MediaAnalysisAccessGuard $media_analysis_access_guard,
    ConfigFactoryInterface $config_factory,
    MediaTenantScopeResolver $media_tenant_scope_resolver,
    protected KeyValueFactoryInterface $keyValueFactory,
    ?TierConfigService $tier_config = NULL,
    ?OriginalImageStore $original_image_store = NULL,
    protected ?LockBackendInterface $operationLock = NULL,
  ) {
    $this->imageProcessingService = $image_processing_service;
    $this->logger = $logger_factory->get('markaspot_vision');
    $this->flood = $flood;
    $this->featureScopeResolver = $feature_scope_resolver;
    $this->mediaAnalysisAccessGuard = $media_analysis_access_guard;
    $this->visionConfigFactory = $config_factory;
    $this->mediaTenantScopeResolver = $media_tenant_scope_resolver;
    $this->tierConfig = $tier_config;
    $this->originalImageStore = $original_image_store;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore-next-line new.static
    return new static(
      $container->get('markaspot_vision.image_processing'),
      $container->get('logger.factory'),
      $container->get('flood'),
      $container->get('markaspot_nuxt.feature_scope_resolver'),
      $container->get('markaspot_vision.media_analysis_access_guard'),
      $container->get('config.factory'),
      $container->get('markaspot_vision.media_tenant_scope'),
      $container->get('keyvalue'),
      $container->has('markaspot_fastmap.tier_config')
        ? $container->get('markaspot_fastmap.tier_config')
        : NULL,
      $container->get('markaspot_vision.original_image_store'),
      $container->get('lock'),
    );
  }

  /**
   * Handles the AI results request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response with AI results.
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
  public function getAIResults(Request $request): JsonResponse {
    $budget_lock_name = NULL;
    $intake_locks = [];
    $rate_limit_max = $this->getRateLimitMax();

    // A zero limit is an explicit operational kill switch for this limiter.
    if ($rate_limit_max > 0) {
      // Bind anonymous traffic to the same private upload-session fingerprint
      // used by the media access guard. Falling back to the client IP keeps
      // non-browser integrations protected without grouping all users behind
      // the Nuxt or Kubernetes proxy into one shared bucket.
      $identifier = $this->mediaAnalysisAccessGuard->getRateLimitIdentifier($request)
        ?? 'ip:' . ($request->getClientIp() ?? 'unknown');

      if (!$this->flood->isAllowed('markaspot_vision.analyze', $rate_limit_max, 3600, $identifier)) {
        return new JsonResponse([
          'error' => $this->t('Too many requests. Please try again later.'),
        ], 429);
      }
      $this->flood->register('markaspot_vision.analyze', 3600, $identifier);
    }

    // Decode request body once for all subsequent checks.
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
    }

    try {
      if (empty($data['media_ids']) || !is_array($data['media_ids'])) {
        return new JsonResponse([
          'error' => $this->t('Invalid input: "media_ids" is required and should be an array.'),
        ], 400);
      }

      if (count($data['media_ids']) > 5) {
        return new JsonResponse([
          'error' => $this->t('Too many media items. Maximum is @max.', ['@max' => 5]),
        ], 400);
      }

      // Fetch media entities by UUIDs (single query).
      $media_storage = $this->entityTypeManager()->getStorage('media');
      $media_entities = [];
      $loaded = $media_storage->loadByProperties(['uuid' => $data['media_ids']]);
      foreach ($loaded as $media) {
        // Do not use access('view'): media may be unpublished by design until
        // AI analysis completes. Instead, require update access/ownership from
        // a real user session or the same upload session that created media.
        if (!$this->mediaAnalysisAccessGuard->canAnalyze($media, $request)) {
          $this->logger->warning('Rejected vision analysis for media @id: request is not tied to the upload context.', [
            '@id' => $media->id(),
          ]);
          return new JsonResponse([
            'error' => $this->t('You are not allowed to analyze one or more uploaded media items.'),
          ], 403);
        }
        $media_entities[$media->id()] = $media;
      }

      if (empty($media_entities)) {
        throw new \Exception('No valid media entities found for the provided media_ids.');
      }

      // ControllerBase::keyValue() caches only its first collection. Use the
      // factory to keep intake and editorial-publication state separate.
      $intake_store = $this->keyValueFactory->get(MediaIntakeScreening::STORE);

      // The queue and interactive analysis must never transform an imported
      // image concurrently. Order locks consistently for multi-image batches.
      ksort($media_entities, SORT_NUMERIC);
      foreach ($media_entities as $mid => $media) {
        if ($this->operationLock && $intake_store->has($mid)) {
          $name = MediaIntakeScreening::QUEUE . ':' . $mid;
          if (!$this->operationLock->acquire($name, 900)) {
            return new JsonResponse(['error' => 'Imported image screening is already running. Please retry.'], 409);
          }
          $intake_locks[] = $name;
          $media_entities[$mid] = $media_storage->loadUnchanged($mid);
        }
      }

      // Tenant scope follows the media, not the caller: the jurisdiction of a
      // report already referencing them or the binding made at upload (see
      // MediaTenantScopeResolver for the transitional first-analysis binding).
      // Feature policy, provider context and budget all use this scope, and
      // it is settled, unbound media included, before any model is contacted
      // or any budget is checked.
      $scope = $this->mediaTenantScopeResolver->resolve(
        $media_entities,
        $data['jurisdiction_id'] ?? NULL,
        $this->tierConfig !== NULL,
      );
      if (!$scope->isAllowed()) {
        $this->logger->warning('Rejected vision analysis: @reason', ['@reason' => $scope->error]);
        return new JsonResponse(['error' => $scope->error], $scope->status);
      }
      $jurisdiction = $scope->jurisdiction;

      // Feature flag gate: schema default is TRUE so an unconfigured tenant
      // still gets the analysis; only an explicit `false` in
      // field_nuxt_config blocks the call, before any model is contacted.
      if ($jurisdiction instanceof GroupInterface
        && !$this->featureScopeResolver->isEnabledEffective('aiAnalysis', $jurisdiction, TRUE)) {
        return new JsonResponse([
          'error' => $this->t('AI analysis is disabled for this jurisdiction.'),
        ], 403);
      }

      $jurisdictionId = $jurisdiction instanceof GroupInterface ? (int) $jurisdiction->id() : NULL;

      // Collect file URIs, keyed by media ID for blur result mapping.
      $file_uris = [];
      $media_uri_map = [];
      $media_source_hashes = [];
      $persisted_privacy_holds = [];
      foreach ($media_entities as $media) {
        // Snapshot before this request replaces the stored model verdict.
        if ($media->hasField('field_ai_privacy_flag')
          && !empty($media->get('field_ai_privacy_flag')->value)) {
          $issues = trim((string) $media->get('field_ai_privacy_issues')->value);
          $persisted_privacy_holds[$media->uuid()] = $issues !== ''
            ? [$issues] : [];
        }
        $field_media_image = $media->get('field_media_image');
        if ($field_media_image && !$field_media_image->isEmpty()) {
          $file = $field_media_image->entity;
          if ($file) {
            $uri = $file->getFileUri();
            $file_uris[] = $uri;
            $media_uri_map[$media->id()] = $uri;
            $media_source_hashes[$uri] = @hash_file('sha256', $uri);
          }
        }
      }
      if (empty($file_uris)) {
        throw new \Exception('No valid file URIs found for the media entities.');
      }

      // AI budget check (only on SaaS with markaspot_fastmap installed).
      if ($this->tierConfig) {
        if (!$jurisdiction instanceof GroupInterface || !$jurisdiction->hasField('field_tier')) {
          return new JsonResponse(['error' => 'Invalid jurisdiction.'], 400);
        }

        $tier = !$jurisdiction->get('field_tier')->isEmpty()
          ? $jurisdiction->get('field_tier')->value
          : 'free';
        if ($this->operationLock) {
          $name = 'markaspot_media_intake:budget:' . $jurisdiction->id();
          if (!$this->operationLock->acquire($name, 30)) {
            $this->operationLock->wait($name, 1);
            if (!$this->operationLock->acquire($name, 30)) {
              return new JsonResponse(['error' => 'AI analysis is already reserving budget for this jurisdiction. Please retry.'], 429);
            }
          }
          $budget_lock_name = $name;
        }
        if ($this->tierConfig->isAIBudgetExhausted((int) $jurisdiction->id(), $tier)) {
          $this->logger->notice('AI budget exhausted for jurisdiction @jid (tier: @tier).', [
            '@jid' => $jurisdiction->id(),
            '@tier' => $tier,
          ]);
          return new JsonResponse([
            'status' => 'budget_exhausted',
            'message' => 'Monthly AI analysis budget exhausted.',
          ], 429);
        }
      }

      // Get user's language preference from request (frontend sends this).
      $langcode = $data['language'] ?? NULL;

      // Process images with ImageProcessingService.
      if ($this->tierConfig && $jurisdictionId) {
        // Reserve before the external call so an interruption cannot escape
        // accounting. The shared tenant lock covers check and reservation.
        $this->tierConfig->recordAIAnalysis($jurisdictionId);
      }
      if ($budget_lock_name !== NULL) {
        $this->operationLock?->release($budget_lock_name);
        $budget_lock_name = NULL;
      }
      $screened_uris = [];
      foreach ($media_entities as $media) {
        $metadata = json_decode((string) $media->get('field_ai_metadata')?->value, TRUE);
        $uri = $media_uri_map[$media->id()] ?? NULL;
        if ($uri && ScreeningMarker::accepts($metadata['intake_screening'] ?? [], $media_source_hashes[$uri], BlurPolicy::isRequired($this->logger))) {
          $screened_uris[$uri] = $metadata['intake_screening'];
        }
      }
      $ai_result = $screened_uris
        ? $this->imageProcessingService->processScreenedImages($screened_uris, $langcode, $jurisdictionId, $file_uris)
        : $this->imageProcessingService->processImages($file_uris, $langcode, $jurisdictionId);
      if (!$ai_result) {
        throw new \Exception('Failed to process images using the AI service.');
      }
      // Decode AI results.
      $decoded_result = json_decode($ai_result['ai_result'], TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Failed to decode AI service response: ' . json_last_error_msg());
      }
      // Strip any echo of our own response-only keys before ANY use — a
      // non-compliant provider must not be able to smuggle them into the
      // persisted field_ai_metadata audit blob or the response; the
      // authoritative values are computed below.
      unset($decoded_result['privacy_handled_by_blur'], $decoded_result['blurred_previews'], $decoded_result['privacy_flags'], $decoded_result['privacy_reblurred'], $decoded_result['markaspot_privacy_escalation']);
      // The observation only makes the model describe before it judges; it is
      // free text outside the description's privacy rule, so it is neither
      // stored in field_ai_metadata nor returned.
      unset($decoded_result['observation'], $decoded_result['intake_screening'], $decoded_result['intake_suggestions']);
      // Normalized once: the media field holds 64 characters, and the raw
      // model value stays in field_ai_metadata for the audit trail.
      $hazard_category = $this->imageProcessingService->normalizeHazardCategory($decoded_result['hazard_category'] ?? NULL);
      $privacy_flag = !empty($decoded_result['privacy_flag']);
      $privacy_issues = $decoded_result['privacy_issues'] ?? [];
      if (!is_array($privacy_issues)) {
        $privacy_issues = [$privacy_issues];
      }
      $privacy_issues = array_values(array_filter($privacy_issues, static function ($issue): bool {
        if (is_string($issue)) {
          return trim($issue) !== '';
        }
        return !empty($issue);
      }));
      $effective_privacy_flag = $privacy_flag || !empty($privacy_issues);
      $unclassified_privacy_flag = $privacy_flag && empty($privacy_issues);
      $unclassified_privacy_issue = 'AI privacy flag set without a classifiable privacy issue.';

      // Extract blur results from the AI processing response. $blur_applied is
      // TRUE if the blur preprocessing service actually blurred faces/plates in
      // any image of the batch. This is the deterministic ground truth used to
      // suppress the citizen-facing privacy notice (see response below).
      $blur_results = $ai_result['blur_results'] ?? [];
      $skipped_uris = $ai_result['skipped_uris'] ?? [];
      $batch_has_skipped_media = !empty($skipped_uris);
      $privacy_attribution = $this->resolvePrivacyAttribution(
        $media_entities,
        $media_uri_map,
        $skipped_uris,
        $decoded_result['privacy_image_flags'] ?? NULL,
        count($file_uris) - count($skipped_uris),
        $effective_privacy_flag,
        $privacy_issues,
      );
      $blur_applied = FALSE;
      foreach ($blur_results as $blur_result) {
        if (!empty($blur_result['blurred'])) {
          $blur_applied = TRUE;
          break;
        }
      }

      // Residual findings the normal blur left behind: sites that chose
      // re-blurring over holding the photo escalate those media (strong, then
      // full), so they can be published instead of waiting for moderation.
      $reblur_enabled = $this->isResidualReblurEnabled();
      $escalation = $reblur_enabled
        ? $this->escalateResidualPrivacy($media_entities, $media_uri_map, $blur_results, $skipped_uris, $privacy_issues, $unclassified_privacy_flag, $effective_privacy_flag, $privacy_attribution, $persisted_privacy_holds)
        : ['applied' => [], 'failed' => [], 'rechecks' => 0];
      $reblurred = $escalation['applied'];
      if ($reblurred) {
        $blur_applied = TRUE;
      }

      // Response-only blurred thumbnails (data URLs), keyed by media UUID, so
      // the citizen's upload preview can show the privacy-protected version.
      $blurred_previews = [];

      $privacy_holds = [];
      $media_index = 0;
      foreach ($media_entities as $media) {
        $media_source_changed = FALSE;
        try {
          $hazard_flag = !empty($decoded_result['hazard_flag']);
          $hazard_issues = $decoded_result['hazard_issues'] ?? [];

          // Per-media blur ground truth: the blur service blurred a face/plate
          // on THIS media. Used to save the anonymised bytes.
          $media_uri = $media_uri_map[$media->id()] ?? NULL;
          $media_was_blurred = $media_uri && !empty($blur_results[$media_uri]['blurred']);
          $media_was_skipped = !$media_uri || isset($skipped_uris[$media_uri]);
          $media_privacy_issues = $privacy_issues;

          // Persisted moderation flag: a successful blur overwrite clears only
          // concerns covered by face/plate anonymisation. Residual findings
          // such as readable documents, IDs, names, or addresses still hold the
          // media for review. Skipped media always fails closed because no
          // complete analysis happened for that file.
          if ($media_was_blurred && !$media_was_skipped) {
            $media_privacy_issues = $this->filterBlurHandledPrivacyIssues($media_privacy_issues);
            $privacy_held = !empty($media_privacy_issues) || $unclassified_privacy_flag;
          }
          else {
            $privacy_held = $effective_privacy_flag || $media_was_skipped;
          }
          // Attribution cannot revoke an earlier persisted privacy hold.
          $previously_held = isset($persisted_privacy_holds[$media->uuid()]);
          if ($previously_held) {
            $media_privacy_issues = $privacy_attribution[$media->uuid()] === FALSE
              ? $persisted_privacy_holds[$media->uuid()]
              : array_merge($media_privacy_issues,
                $persisted_privacy_holds[$media->uuid()]);
            $privacy_held = TRUE;
          }
          elseif ($privacy_attribution[$media->uuid()] === FALSE) {
            $media_privacy_issues = [];
            $privacy_held = FALSE;
          }

          // An escalated blur covered what the model still saw: nothing left
          // to hold, and the audit trail is in the log, not in the issues
          // field (any text there is a hold for the node hook).
          if ($media_uri && isset($reblurred[$media_uri])) {
            $media_privacy_issues = [];
            $privacy_held = FALSE;
          }
          if ($privacy_held && empty($media_privacy_issues)
            && ($unclassified_privacy_flag || $previously_held)) {
            $media_privacy_issues[] = $unclassified_privacy_issue;
          }
          if ($media_was_skipped) {
            $skip_reason = $media_uri
              ? ($skipped_uris[$media_uri] ?: 'unknown') : 'missing file URI';
            $media_privacy_issues[] = 'AI analysis skipped for this media (' . $skip_reason . ')';
          }
          $privacy_holds[$media->uuid()] = $privacy_held;

          // Deliberate split (do NOT reconcile): field_ai_metadata stores the
          // RAW model output (provenance/audit), while field_ai_privacy_flag is
          // the effective moderation verdict. The node hook reads
          // field_ai_privacy_flag, so it must reflect residual privacy issues,
          // not the mere fact that blur preprocessing ran successfully.
          // The raw model output, plus which escalation covered what it saw.
          $media_metadata = $decoded_result;
          if ($media_uri && !$media_was_skipped) {
            $media_metadata['intake_screening'] = [
              'privacy_screened' => ($blur_results[$media_uri]['processed'] ?? FALSE) === TRUE,
              'processed' => ($blur_results[$media_uri]['processed'] ?? FALSE) === TRUE,
              'blur_required' => BlurPolicy::isRequired($this->logger),
              'origin' => 'vision',
              'blurred' => $media_was_blurred,
              'source_sha256' => $media_was_blurred && empty($blur_results[$media_uri]['persisted'])
                ? hash('sha256', $blur_results[$media_uri]['contents'])
                : $media_source_hashes[$media_uri],
            ];
          }
          $media_attribution = $privacy_attribution[$media->uuid()];
          $media_metadata['markaspot_privacy_escalation'] = [
            'mode' => $reblurred[$media_uri] ?? 'none',
            'reason' => $previously_held && $privacy_held ? 'hold_kept'
              : ($media_was_skipped ? 'analysis_skipped'
                : ($media_attribution === NULL ? 'attribution_invalid'
                  : ($media_attribution ? 'attributed' : 'not_attributed'))),
          ];
          $media->set('field_ai_metadata', json_encode($media_metadata));
          $media->set('field_ai_privacy_flag', $privacy_held);
          $media->set('field_ai_privacy_issues', implode(', ', (array) $media_privacy_issues));
          $media->set('field_ai_hazard_flag', $hazard_flag);
          $media->set('field_ai_hazard_issues', implode(', ', (array) $hazard_issues));
          $media->set('field_ai_hazard_level', $decoded_result['hazard_level'] ?? 0);
          $media->set('field_ai_hazard_category', $hazard_category);

          // Replace original with blurred version if faces or plates were
          // detected on THIS media.
          if ($media_was_blurred) {
            // Refuse before keeping anything: a media pointing at someone
            // else's photo must not get a copy of its original either.
            $this->imageProcessingService->persistBlurResult(
              $media,
              $blur_results[$media_uri],
              $media_uri,
              $this->originalImageStore,
            );
            // Response-only blurred thumbnail for the citizen upload preview.
            // These are the user's own, already-anonymised bytes (data URL),
            // so the preview shows the privacy-protected version, not the
            // unblurred local original.
            $blur_mime = $blur_results[$media_uri]['mime'] ?? 'image/jpeg';
            $blurred_previews[$media->uuid()] = 'data:' . $blur_mime
              . ';base64,' . base64_encode($blur_results[$media_uri]['contents']);
          }

          // Populate alt text with AI-generated description for accessibility.
          if (!empty($decoded_result['alt_text']) && is_array($decoded_result['alt_text'])) {
            $field_media_image = $media->get('field_media_image');
            if ($field_media_image && !$field_media_image->isEmpty()) {
              // Use the corresponding alt text for this media entity.
              $alt_text = $decoded_result['alt_text'][$media_index] ?? $this->t('Documented situation as per description');
              // The image alt column holds 512 characters; a longer model
              // answer must not fail the save and lose every AI result.
              $alt_text = mb_substr((string) $alt_text, 0, 512);
              $field_media_image->alt = $alt_text;
              $this->logger->notice('Alt text populated with AI description for media @id (index @index): @alt', [
                '@id' => $media->id(),
                '@index' => $media_index,
                '@alt' => $alt_text,
              ]);
            }
          }

          // Publish media immediately after successful AI screening if safe.
          // This must happen here (not only in hook_node_insert) because entity
          // reference validation rejects unpublished media for anonymous users.
          // $privacy_held is the same predicate markaspot_vision's node hook
          // applies on later saves. Successful blur clears the persisted hold
          // above, so anonymised media can stay published.
          //
          // Skip when the publication is under explicit editorial control via
          // the GeoReport media publication API: a trusted consumer's decision
          // wins over (re-)screening, so a re-analysis must not flip the state
          // back. Fresh uploads have no such mark, so first-screen publishing
          // is unaffected.
          $publication_lock_name = 'markaspot_open311.media_publication.' . $media->id();
          if ($this->operationLock && !$this->operationLock->acquire($publication_lock_name, 30)) {
            throw new \RuntimeException('Media publication is being edited. Please retry analysis.');
          }
          try {
            if ($this->operationLock) {
              $fresh = $media_storage->loadUnchanged($media->id());
              if (!$fresh || $fresh->get('field_media_image')->target_id !== $media->get('field_media_image')->target_id) {
                $media_source_changed = TRUE;
                throw new \RuntimeException('The media source changed during analysis.');
              }
              $expected_hash = $media_metadata['intake_screening']['source_sha256'] ?? NULL;
              if (!$media_was_skipped && (!$expected_hash || $expected_hash !== @hash_file('sha256', $media_uri))) {
                $media_source_changed = TRUE;
                throw new \RuntimeException('The media bytes changed during analysis.');
              }
              if ($fresh->isPublished()) {
                $media->setPublished();
              }
              else {
                $media->setUnpublished();
              }
            }
            $manual_publication = $this->keyValueFactory->get('markaspot_open311.media_publication_manual');
            if (!$manual_publication->get($media->id())) {
              $can_publish = TRUE;
              if ($intake_store->has($media->id())) {
                $parents = $this->entityTypeManager()->getStorage('node');
                $ids = $parents->getQuery()->accessCheck(FALSE)
                  ->condition('type', 'service_request')
                  ->condition('field_request_media', $media->id())->execute();
                $can_publish = !empty($ids);
                foreach ($parents->loadMultiple($ids) as $parent) {
                  $can_publish = $can_publish && $parent->isPublished();
                }
              }
              if (!$can_publish || $privacy_held) {
                $media->setUnpublished();
              }
              else {
                $media->setPublished();
              }
            }
            $media->save();
          }
          finally {
            $this->operationLock?->release($publication_lock_name);
          }
          $this->logger->notice('AI results successfully saved for media entity @id.', [
            '@id' => $media->id(),
          ]);
        }
        catch (\Exception $e) {
          $this->logger->error('Error saving AI results for media @id: @message', [
            '@id' => $media->id(),
            '@message' => $e->getMessage(),
          ]);
          if (!empty($media_was_blurred) || $media_source_changed) {
            throw $e;
          }
        }
        $media_index++;
      }

      // Transfer hazard data from media to parent service_request node.
      // Find nodes referencing any of these media entities.
      $media_ids = array_keys($media_entities);
      if (!empty($media_ids)) {
        $node_storage = $this->entityTypeManager()->getStorage('node');
        $nids = $node_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'service_request')
          ->condition('field_request_media', $media_ids, 'IN')
          ->execute();

        foreach ($node_storage->loadMultiple($nids) as $node) {
          // Determine the highest hazard level across all media on this node.
          $max_hazard_level = 0;
          $referenced_media = $node->get('field_request_media')->referencedEntities();
          foreach ($referenced_media as $ref_media) {
            if ($ref_media->hasField('field_ai_hazard_level')) {
              $level = (int) $ref_media->get('field_ai_hazard_level')->value;
              if ($level > $max_hazard_level) {
                $max_hazard_level = $level;
              }
            }
          }

          if ($node->hasField('field_hazard_level')) {
            $node->set('field_hazard_level', $max_hazard_level);
            $node->save();
            $this->logger->notice('Updated field_hazard_level to @level on node @nid.', [
              '@level' => $max_hazard_level,
              '@nid' => $node->id(),
            ]);
          }
        }
      }

      // Record AI analysis for budget tracking, charged to the media tenant.
      if ($this->tierConfig && $jurisdictionId) {
        // Every privacy re-check is a model call of its own.
        for ($i = 0; $i < $escalation['rechecks']; $i++) {
          $this->tierConfig->recordAIAnalysis($jurisdictionId);
        }
      }

      // Response-only signal for the citizen UI: suppress the privacy notice
      // when the blur service actually blurred faces/plates. This uses the
      // blur service's deterministic ground truth, NOT the model's self-report.
      // Internal moderation is independent and fail-closed for unprocessed or
      // unblurred media. If every analysed media item was successfully blurred,
      // the response clears only issues covered by that blur operation.
      $response_result = $decoded_result;
      $response_result['hazard_category'] = $hazard_category;
      // Strip any echo of our own response-only keys in case a non-compliant
      // provider returns them; the authoritative values are computed here.
      unset($response_result['privacy_handled_by_blur'], $response_result['blurred_previews'], $response_result['privacy_flags'], $response_result['privacy_reblurred']);
      $all_analyzed_media_blurred = $blur_applied && !$batch_has_skipped_media;
      foreach ($media_entities as $media) {
        $media_uri = $media_uri_map[$media->id()] ?? NULL;
        if (!$media_uri) {
          $all_analyzed_media_blurred = FALSE;
          break;
        }
        if (empty($blur_results[$media_uri]['blurred']) && !isset($skipped_uris[$media_uri])) {
          $all_analyzed_media_blurred = FALSE;
          break;
        }
      }

      $response_privacy_issues = $all_analyzed_media_blurred
        ? $this->filterBlurHandledPrivacyIssues($privacy_issues)
        : $privacy_issues;
      if ($unclassified_privacy_flag && empty($response_privacy_issues)) {
        $response_privacy_issues[] = $unclassified_privacy_issue;
      }
      if ($batch_has_skipped_media) {
        $response_privacy_issues[] = 'One or more uploaded images could not be AI-screened.';
      }
      $response_result['privacy_flag'] = !empty($response_privacy_issues) || $batch_has_skipped_media;
      $response_result['privacy_issues'] = $response_privacy_issues;
      $response_result['privacy_handled_by_blur'] = $blur_applied;

      // Use the same trusted attribution as escalation and moderation. NULL
      // means invalid attribution: retain the aggregate response fallback.
      // Skipped media are always flagged by resolvePrivacyAttribution().
      $response_privacy_flags = [];
      foreach ($media_entities as $media) {
        $response_privacy_flags[$media->uuid()] =
          isset($persisted_privacy_holds[$media->uuid()])
          || ($privacy_attribution[$media->uuid()]
            ?? (bool) $response_result['privacy_flag']);
      }
      // Escalated media are covered; the verdict clears once nothing else is
      // flagged. privacy_reblurred tells the citizen why a photo looks
      // coarser than their original.
      // A media whose escalation failed stays held: it stays flagged here too,
      // whatever the model's attribution said, so the verdict cannot clear.
      if ($reblurred || $escalation['failed']) {
        $reblur_levels = [];
        foreach ($media_entities as $media) {
          $media_uri = $media_uri_map[$media->id()] ?? NULL;
          if ($media_uri && isset($reblurred[$media_uri])) {
            $response_privacy_flags[$media->uuid()] = FALSE;
            $reblur_levels[$media->uuid()] = $reblurred[$media_uri];
          }
          elseif ($media_uri && isset($escalation['failed'][$media_uri])) {
            $response_privacy_flags[$media->uuid()] = TRUE;
          }
        }
        if (!$batch_has_skipped_media && !in_array(TRUE, $response_privacy_flags, TRUE)) {
          $response_result['privacy_flag'] = FALSE;
          $response_result['privacy_issues'] = [];
        }
        if ($reblur_levels) {
          $response_result['privacy_reblurred'] = $reblur_levels;
        }
      }
      // The complete moderation verdict includes prior holds and normal blur.
      $response_result['privacy_flag'] = in_array(TRUE, $privacy_holds, TRUE)
        || count($privacy_holds) !== count($media_entities);
      if (!$response_result['privacy_flag']) {
        $response_result['privacy_issues'] = [];
      }
      $response_result['privacy_flags'] = $response_privacy_flags;
      // Blurred thumbnails (data URLs) so the citizen preview shows the
      // privacy-protected version. Only present when something was blurred.
      if (!empty($blurred_previews)) {
        $response_result['blurred_previews'] = $blurred_previews;
      }
      return new JsonResponse($response_result);

    }
    catch (\Exception $e) {
      $this->logger->error('Error in getAIResults: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => $this->t('An error occurred during image analysis.')], 500);
    }
    finally {
      if ($budget_lock_name !== NULL) {
        $this->operationLock?->release($budget_lock_name);
      }
      foreach (array_reverse($intake_locks) as $name) {
        $this->operationLock?->release($name);
      }
    }
  }

  /**
   * Returns a fail-safe configured maximum for the hourly Vision limiter.
   */
  protected function getRateLimitMax(): int {
    $configured = $this->visionConfigFactory
      ->get('markaspot_vision.settings')
      ->get('rate_limit_max');

    if (is_int($configured)) {
      return $configured >= 0 ? $configured : 10;
    }

    if (is_string($configured) && ctype_digit($configured)) {
      return (int) $configured;
    }

    return 10;
  }

  /**
   * Whether residual privacy findings are re-blurred instead of held.
   */
  protected function isResidualReblurEnabled(): bool {
    return $this->visionConfigFactory
      ->get('markaspot_vision.settings')
      ->get('residual_privacy_handling') === 'reblur';
  }

  /**
   * Resolves trusted per-image flags for moderation, escalation and UI hints.
   *
   * @param \Drupal\media\MediaInterface[] $media_entities
   *   Media in the order supplied to the analysis.
   * @param array $media_uri_map
   *   File URI per media id.
   * @param array $skipped_uris
   *   URIs omitted from the model input.
   * @param mixed $model_image_flags
   *   The model's untrusted per-image attribution.
   * @param int $analyzed_image_count
   *   Number of images actually supplied to the model.
   * @param bool $effective_privacy_flag
   *   Whether the batch has any privacy finding.
   * @param array $privacy_issues
   *   Findings with optional explicit, 1-based image references.
   *
   * @return array<string, bool|null>
   *   Flags keyed by media UUID. NULL retains the aggregate fallback. Missing
   *   or skipped files are always TRUE and consume no attribution entry.
   */
  private function resolvePrivacyAttribution(array $media_entities, array $media_uri_map, array $skipped_uris, mixed $model_image_flags, int $analyzed_image_count, bool $effective_privacy_flag, array $privacy_issues): array {
    $attribution_valid = is_array($model_image_flags)
      && array_is_list($model_image_flags)
      && count($model_image_flags) === $analyzed_image_count
      && count(array_filter($model_image_flags, 'is_bool')) === count($model_image_flags);
    // An aggregate finding attributed to no analysed image is untrustworthy,
    // even if another file was skipped. Fail closed on the whole batch.
    if ($attribution_valid && $effective_privacy_flag && !in_array(TRUE, $model_image_flags, TRUE)) {
      $attribution_valid = FALSE;
    }
    // Only explicit numbered references are evidence of a contradiction.
    if ($attribution_valid) {
      foreach ($privacy_issues as $issue) {
        if (!is_string($issue)) {
          continue;
        }
        preg_match_all(
          '/\b(?:image|photo|picture|bild|foto)\s+([0-9]+)\b/iu',
          $issue,
          $references,
        );
        foreach ($references[1] as $number) {
          $index = (int) $number - 1;
          if ($index >= 0 && $index < $analyzed_image_count
            && $model_image_flags[$index] === FALSE) {
            $attribution_valid = FALSE;
            break 2;
          }
        }
      }
    }

    $flags = [];
    $index = 0;
    foreach ($media_entities as $media) {
      $uri = $media_uri_map[$media->id()] ?? NULL;
      if (!$uri || isset($skipped_uris[$uri])) {
        $flags[$media->uuid()] = TRUE;
        continue;
      }
      $flags[$media->uuid()] = $attribution_valid ? $model_image_flags[$index] : NULL;
      $index++;
    }
    return $flags;
  }

  /**
   * Re-blurs media that the normal blur left with a privacy finding.
   *
   * Strong mode (eager detection plus text regions) counts only when the
   * detector matching the finding fired (text for names and documents,
   * faces or plates for people and vehicles) and the model then sees nothing
   * left; findings no detector can locate, and every doubt, go to the full
   * blur. A boolean list with exactly one entry per analysed image scopes
   * escalation, moderation and UI hints to attributed photos. Missing or
   * malformed attribution, or an aggregate finding attributed to no image,
   * or a numbered finding contradicting a FALSE entry, retains the batch-level
   * moderation predicate for every photo. Attribution alone never clears a
   * persisted hold: escalation must cover the previous findings too. Skipped
   * media and media whose escalation fails stay held.
   *
   * @param \Drupal\media\MediaInterface[] $media_entities
   *   Analysed media, keyed by id.
   * @param array $media_uri_map
   *   File URI per media id.
   * @param array $blur_results
   *   Blur results per URI; escalated bytes replace 'contents'.
   * @param array $skipped_uris
   *   URIs the analysis skipped; they stay held.
   * @param array $privacy_issues
   *   The model's privacy issues for the batch.
   * @param bool $unclassified_privacy_flag
   *   Whether the model flagged privacy without naming an issue.
   * @param bool $effective_privacy_flag
   *   The batch verdict for media the normal blur did not touch.
   * @param array<string, bool|null> $privacy_attribution
   *   Shared trusted attribution; FALSE exempts photos without a prior hold.
   * @param array<string, array> $persisted_privacy_holds
   *   Previous findings per held media UUID, read before this analysis.
   *
   * @return array
   *   'applied': blur mode ('strong' or 'full') keyed by file URI; 'failed':
   *   URIs whose escalation failed (still held); 'rechecks': model calls made.
   */
  private function escalateResidualPrivacy(array $media_entities, array $media_uri_map, array &$blur_results, array $skipped_uris, array $privacy_issues, bool $unclassified_privacy_flag, bool $effective_privacy_flag, array $privacy_attribution, array $persisted_privacy_holds): array {
    $applied = [];
    $failed = [];
    $rechecks = 0;
    foreach ($media_entities as $media) {
      $uri = $media_uri_map[$media->id()] ?? NULL;
      if (!$uri || isset($skipped_uris[$uri]) || !isset($blur_results[$uri]['source'])) {
        continue;
      }
      $media_issues = !empty($blur_results[$uri]['blurred'])
        ? $this->filterBlurHandledPrivacyIssues($privacy_issues)
        : $privacy_issues;
      $held = !empty($blur_results[$uri]['blurred'])
        ? (!empty($media_issues) || $unclassified_privacy_flag)
        : $effective_privacy_flag;
      $previously_held = isset($persisted_privacy_holds[$media->uuid()]);
      if (!$previously_held
        && (!$held || $privacy_attribution[$media->uuid()] === FALSE)) {
        continue;
      }
      if ($previously_held) {
        $media_issues = $privacy_attribution[$media->uuid()] === FALSE
          ? $persisted_privacy_holds[$media->uuid()]
          : array_merge($media_issues, $persisted_privacy_holds[$media->uuid()]);
      }

      $source = $blur_results[$uri]['source'];
      $mime = $blur_results[$uri]['mime'] ?? 'image/jpeg';
      $escalated = NULL;
      $mode = NULL;
      $needs = $this->classifyResidualIssues($media_issues);
      if (($unclassified_privacy_flag
        && $privacy_attribution[$media->uuid()] !== FALSE)
        || ($previously_held && !$persisted_privacy_holds[$media->uuid()])) {
        // A new finding cannot classify an older, unexplained hold.
        $needs['other'] = TRUE;
      }
      if (!$needs['other']) {
        try {
          $strong = $this->imageProcessingService->blurSensitiveAreas($source, $mime, ImageProcessingService::BLUR_MODE_STRONG);
          // Blurring something unrelated proves nothing about the finding.
          $covered = !empty($strong['blurred'])
            && (!$needs['text'] || ($strong['texts'] ?? 0) > 0)
            && (!$needs['regions'] || (($strong['faces'] ?? 0) + ($strong['plates'] ?? 0)) > 0);
          if ($covered) {
            $rechecks++;
            $check = $this->imageProcessingService->screenPrivacy($strong['contents'], $mime, $media_issues);
            if ($check !== NULL && !$check['privacy_flag']) {
              $escalated = $strong['contents'];
              $mode = ImageProcessingService::BLUR_MODE_STRONG;
            }
          }
        }
        catch (\Exception $e) {
          $this->logger->warning('Strong blur failed for media @id; trying the full blur: @message', [
            '@id' => $media->id(),
            '@message' => mb_substr($e->getMessage(), 0, 200),
          ]);
        }
      }
      if ($escalated === NULL) {
        try {
          $full = $this->imageProcessingService->blurSensitiveAreas($source, $mime, ImageProcessingService::BLUR_MODE_FULL);
          if (!empty($full['blurred'])) {
            $escalated = $full['contents'];
            $mode = ImageProcessingService::BLUR_MODE_FULL;
          }
        }
        catch (\Exception $e) {
          $this->logger->error('Full blur failed for media @id; the photo stays held for moderation: @message', [
            '@id' => $media->id(),
            '@message' => mb_substr($e->getMessage(), 0, 200),
          ]);
        }
      }
      if ($escalated === NULL) {
        $failed[$uri] = TRUE;
        continue;
      }

      $blur_results[$uri]['contents'] = $escalated;
      $blur_results[$uri]['blurred'] = TRUE;
      unset($blur_results[$uri]['persisted']);
      $applied[$uri] = $mode;
      $this->logger->notice('GDPR audit: residual privacy finding on media @id covered by the @mode blur.', [
        '@id' => $media->id(),
        '@mode' => $mode,
      ]);
    }
    return ['applied' => $applied, 'failed' => $failed, 'rechecks' => $rechecks];
  }

  /**
   * Sorts residual privacy issues by the detector that can cover them.
   *
   * @param array $issues
   *   Residual privacy issue strings; none means an unclassified flag.
   *
   * @return array
   *   'text' (names, addresses, documents), 'regions' (people, vehicles) and
   *   'other' (nothing a detector locates, or unclassified), each a bool.
   */
  private function classifyResidualIssues(array $issues): array {
    $needs = ['text' => FALSE, 'regions' => FALSE, 'other' => empty($issues)];
    foreach ($issues as $issue) {
      $text = is_scalar($issue) ? trim((string) $issue) : '';
      // Stems without a trailing boundary so German compounds match
      // (Briefumschlag, Namensschild, Klingelschild).
      $is_text = preg_match('/\b(document|dokument|ausweis|passport|ids?\b|name|address|adress|anschrift|letter|brief|envelope|umschlag|text|schrift|writing|handwrit|handschrift|signature|unterschrift|phone|telefon|e-?mail|house\s+number|hausnummer|label|etikett|doorbell|klingel|screen|bildschirm|display)/iu', $text) === 1;
      $is_region = preg_match('/\b(face|facial|gesicht|person(?!al|enbezogen)|people|mensch|kennzeichen|kfz|licen[cs]e\s+plate|number\s+plate|registration\s+plate|plate)/iu', $text) === 1;
      $needs['text'] = $needs['text'] || $is_text;
      $needs['regions'] = $needs['regions'] || $is_region;
      $needs['other'] = $needs['other'] || (!$is_text && !$is_region);
    }
    return $needs;
  }

  /**
   * Removes AI privacy issues already handled by face/plate blurring.
   *
   * @param array $privacy_issues
   *   AI privacy issue strings.
   *
   * @return array
   *   Residual privacy issues that still need moderation.
   */
  private function filterBlurHandledPrivacyIssues(array $privacy_issues): array {
    return array_values(array_filter($privacy_issues, function ($issue): bool {
      return !$this->isBlurHandledPrivacyIssue($issue);
    }));
  }

  /**
   * Checks whether a privacy issue is covered by face/plate blurring.
   */
  private function isBlurHandledPrivacyIssue($issue): bool {
    if (!is_scalar($issue)) {
      return FALSE;
    }

    $text = trim((string) $issue);
    if ($text === '') {
      return FALSE;
    }

    // Blur handles faces, people without identifiable faces, and vehicle
    // plates. It does not anonymise documents, IDs, names, addresses, contact
    // data, signatures, or house numbers.
    $unhandled_pattern = '/\b('
      . 'document|documents|passport|id|ids|name|names|address|'
      . 'house\s+number|phone|email|e-mail|signature|'
      . 'driver\'?s?\s+licen[cs]e|ausweis|dokument|dokumente|'
      . 'adresse|hausnummer|telefon|unterschrift|identifiable|'
      . 'recognizable|recognisable|identifizierbar|erkennbar|'
      . 'wiedererkennbar'
      . ')\b/iu';
    $has_unhandled_pii = preg_match($unhandled_pattern, $text) === 1;
    if ($has_unhandled_pii) {
      return FALSE;
    }

    $blur_handled_pattern = '/\b('
      . 'face|faces|facial|person|persons|people|mensch|menschen|'
      . 'personen|gesicht|kennzeichen'
      . ')\b|licen[cs]e\s+plate|number\s+plate|registration\s+plate|'
      . 'vehicle\s+plate|kfz[-\s]?kennzeichen/iu';
    return preg_match($blur_handled_pattern, $text) === 1;
  }

}
