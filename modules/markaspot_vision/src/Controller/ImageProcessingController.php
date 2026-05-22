<?php

namespace Drupal\markaspot_vision\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\markaspot_nuxt\Service\FeatureFlagChecker;
use Drupal\markaspot_vision\Service\ImageProcessingService;

/**
 * Controller for processing images with AI vision services.
 */
class ImageProcessingController extends ControllerBase {

  use JurisdictionIdResolverTrait;

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
   * The feature flag checker.
   *
   * @var \Drupal\markaspot_nuxt\Service\FeatureFlagChecker
   */
  protected FeatureFlagChecker $featureFlagChecker;

  /**
   * Constructs a new ImageProcessingController object.
   *
   * @param \Drupal\markaspot_vision\Service\ImageProcessingService $image_processing_service
   *   The image processing service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\markaspot_nuxt\Service\FeatureFlagChecker $feature_flag_checker
   *   The feature flag checker.
   * @param \Drupal\markaspot_fastmap\Service\TierConfigService|null $tier_config
   *   The tier config service (optional, only on SaaS).
   */
  public function __construct(
    ImageProcessingService $image_processing_service,
    LoggerChannelFactoryInterface $logger_factory,
    FloodInterface $flood,
    FeatureFlagChecker $feature_flag_checker,
    ?TierConfigService $tier_config = NULL,
  ) {
    $this->imageProcessingService = $image_processing_service;
    $this->logger = $logger_factory->get('markaspot_vision');
    $this->flood = $flood;
    $this->featureFlagChecker = $feature_flag_checker;
    $this->tierConfig = $tier_config;
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
      $container->get('markaspot_nuxt.feature_flag_checker'),
      $container->has('markaspot_fastmap.tier_config')
        ? $container->get('markaspot_fastmap.tier_config')
        : NULL,
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
    // Rate limiting: 10 requests per IP per hour.
    $ip = $request->getClientIp();
    if (!$this->flood->isAllowed('markaspot_vision.analyze', 10, 3600, $ip)) {
      return new JsonResponse([
        'error' => $this->t('Too many requests. Please try again later.'),
      ], 429);
    }
    $this->flood->register('markaspot_vision.analyze', 3600, $ip);

    // Decode request body once for all subsequent checks.
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
    }

    // Feature flag gate: reject early when the jurisdiction has disabled
    // AI analysis. Schema default is TRUE so an unconfigured tenant still
    // gets the analysis; only an explicit `false` in field_nuxt_config
    // blocks the call. This prevents the OpenAI request — and the cost —
    // before any model is contacted.
    $jurisdictionIdForFlag = $this->resolveJurisdictionId($data['jurisdiction_id'] ?? NULL);
    $jurisdictionForFlag = $jurisdictionIdForFlag
      ? $this->entityTypeManager()->getStorage('group')->load($jurisdictionIdForFlag)
      : NULL;
    if (!$this->featureFlagChecker->isEnabled('features.aiAnalysis', $jurisdictionForFlag, TRUE)) {
      return new JsonResponse([
        'error' => $this->t('AI analysis is disabled for this jurisdiction.'),
      ], 403);
    }

    // AI budget check (only on SaaS with markaspot_fastmap installed).
    $resolvedJurisdictionId = NULL;
    if ($this->tierConfig) {
      $resolvedJurisdictionId = $this->resolveJurisdictionId($data['jurisdiction_id'] ?? NULL);

      if (!$resolvedJurisdictionId) {
        return new JsonResponse(['error' => 'jurisdiction_id is required.'], 400);
      }

      $group = $this->entityTypeManager()->getStorage('group')->load($resolvedJurisdictionId);
      if (!$group || !$group->hasField('field_tier')) {
        return new JsonResponse(['error' => 'Invalid jurisdiction.'], 400);
      }

      $tier = !$group->get('field_tier')->isEmpty()
        ? $group->get('field_tier')->value
        : 'free';
      if ($this->tierConfig->isAIBudgetExhausted((int) $resolvedJurisdictionId, $tier)) {
        $this->logger->notice('AI budget exhausted for jurisdiction @jid (tier: @tier).', [
          '@jid' => $resolvedJurisdictionId,
          '@tier' => $tier,
        ]);
        return new JsonResponse([
          'status' => 'budget_exhausted',
          'message' => 'Monthly AI analysis budget exhausted.',
        ], 429);
      }
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
        // Skip access('view') check: media may be unpublished (privacy by
        // design) but still needs AI analysis. The endpoint is protected by
        // flood control and requires valid UUIDs.
        $media_entities[$media->id()] = $media;
      }

      if (empty($media_entities)) {
        throw new \Exception('No valid media entities found for the provided media_ids.');
      }

      // Collect file URIs, keyed by media ID for blur result mapping.
      $file_uris = [];
      $media_uri_map = [];
      foreach ($media_entities as $media) {
        $field_media_image = $media->get('field_media_image');
        if ($field_media_image && !$field_media_image->isEmpty()) {
          $file = $field_media_image->entity;
          if ($file) {
            $uri = $file->getFileUri();
            $file_uris[] = $uri;
            $media_uri_map[$media->id()] = $uri;
          }
        }
      }
      if (empty($file_uris)) {
        throw new \Exception('No valid file URIs found for the media entities.');
      }

      // Get user's language preference from request (frontend sends this).
      $langcode = $data['language'] ?? NULL;

      // Get jurisdiction ID for filtering categories in multi-tenant mode.
      // Supports numeric IDs and slugs.
      $jurisdictionId = $this->resolveJurisdictionId($data['jurisdiction_id'] ?? NULL);

      // Process images with ImageProcessingService.
      $ai_result = $this->imageProcessingService->processImages($file_uris, $langcode, $jurisdictionId);
      if (!$ai_result) {
        throw new \Exception('Failed to process images using the AI service.');
      }
      // Decode AI results.
      $decoded_result = json_decode($ai_result['ai_result'], TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Failed to decode AI service response: ' . json_last_error_msg());
      }

      // Extract blur results from the AI processing response.
      // $blur_applied is TRUE if ANY image in the batch was blurred by the
      // preprocessing service. The AI receives the (possibly only partially)
      // blurred batch, and its privacy_remediated_by_blur verdict covers the
      // whole batch: the prompt requires it to be false if ANY image still has
      // residual personal data.
      $blur_results = $ai_result['blur_results'] ?? [];
      $blur_applied = FALSE;
      foreach ($blur_results as $blur_result) {
        if (!empty($blur_result['blurred'])) {
          $blur_applied = TRUE;
          break;
        }
      }

      // Capture and strip the AI's internal remediation verdict before the
      // result is persisted or returned. field_ai_metadata is JSON:API-exposed,
      // so this internal-only signal must never be stored on the entity nor
      // surface to citizens; it only feeds the privacy_handled_by_blur signal.
      $ai_remediated_by_blur = !empty($decoded_result['privacy_remediated_by_blur']);
      unset($decoded_result['privacy_remediated_by_blur']);

      $media_index = 0;
      foreach ($media_entities as $media) {
        try {
          $privacy_flag = !empty($decoded_result['privacy_flag']);
          $hazard_flag = !empty($decoded_result['hazard_flag']);
          $privacy_issues = $decoded_result['privacy_issues'] ?? [];
          $hazard_issues = $decoded_result['hazard_issues'] ?? [];

          $media->set('field_ai_metadata', json_encode($decoded_result));
          $media->set('field_ai_privacy_flag', $privacy_flag);
          $media->set('field_ai_privacy_issues', implode(', ', (array) $privacy_issues));
          $media->set('field_ai_hazard_flag', $hazard_flag);
          $media->set('field_ai_hazard_issues', implode(', ', (array) $hazard_issues));
          $media->set('field_ai_hazard_level', $decoded_result['hazard_level'] ?? 0);
          $media->set('field_ai_hazard_category', $decoded_result['hazard_category'] ?? NULL);

          // Replace original with blurred version if faces or plates were
          // detected.
          $media_uri = $media_uri_map[$media->id()] ?? NULL;
          if ($media_uri && !empty($blur_results[$media_uri]['blurred'])) {
            $this->imageProcessingService->saveBlurredImage(
              $media,
              $blur_results[$media_uri]['contents'],
              $media_uri,
            );
          }

          // Populate alt text with AI-generated description for accessibility.
          if (!empty($decoded_result['alt_text']) && is_array($decoded_result['alt_text'])) {
            $field_media_image = $media->get('field_media_image');
            if ($field_media_image && !$field_media_image->isEmpty()) {
              // Use the corresponding alt text for this media entity.
              $alt_text = $decoded_result['alt_text'][$media_index] ?? $this->t('Documented situation as per description');
              $field_media_image->alt = $alt_text;
              $this->logger->notice('Alt text populated with AI description for media @id (index @index): @alt', [
                '@id' => $media->id(),
                '@index' => $media_index,
                '@alt' => $alt_text,
              ]);
            }
          }

          // Publish media immediately after successful AI screening if safe.
          // This must happen here (not only in hook_node_insert) because
          // entity reference validation rejects unpublished media for
          // anonymous.
          if (!$privacy_flag) {
            $media->setPublished();
          }

          $media->save();
          $this->logger->notice('AI results successfully saved for media entity @id.', [
            '@id' => $media->id(),
          ]);
        }
        catch (\Exception $e) {
          $this->logger->error('Error saving AI results for media @id: @message', [
            '@id' => $media->id(),
            '@message' => $e->getMessage(),
          ]);
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

      // Record AI analysis for budget tracking.
      if ($this->tierConfig && $resolvedJurisdictionId) {
        $this->tierConfig->recordAIAnalysis((int) $resolvedJurisdictionId);
      }

      // Response-only signal for the citizen UI: suppress the privacy prompt
      // only when blur preprocessing actually ran AND the AI judged the privacy
      // concern to be fully remediated by that blur. Both conditions are
      // required so that residual, unblurred personal data (names, documents,
      // IDs, or a face/plate the blur service missed) still surfaces the
      // prompt. Internal moderation (privacy_flag, field_ai_*, depublishing)
      // is unaffected by this signal. The internal verdict was already stripped
      // from $decoded_result above, so it leaks neither here nor into storage.
      $response_result = $decoded_result;
      $response_result['privacy_handled_by_blur'] = $blur_applied && $ai_remediated_by_blur;
      return new JsonResponse($response_result);

    }
    catch (\Exception $e) {
      $this->logger->error('Error in getAIResults: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => $this->t('An error occurred during image analysis.')], 500);
    }
  }

}
