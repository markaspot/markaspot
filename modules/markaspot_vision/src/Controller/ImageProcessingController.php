<?php

namespace Drupal\markaspot_vision\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
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
   * Constructs a new ImageProcessingController object.
   *
   * @param \Drupal\markaspot_vision\Service\ImageProcessingService $image_processing_service
   *   The image processing service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   */
  public function __construct(
    ImageProcessingService $image_processing_service,
    LoggerChannelFactoryInterface $logger_factory,
    FloodInterface $flood,
  ) {
    $this->imageProcessingService = $image_processing_service;
    $this->logger = $logger_factory->get('markaspot_vision');
    $this->flood = $flood;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_vision.image_processing'),
      $container->get('logger.factory'),
      $container->get('flood')
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

    try {
      // Decode incoming request content.
      $data = json_decode($request->getContent(), TRUE);
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

      // Collect file URIs.
      $file_uris = [];
      foreach ($media_entities as $media) {
        $field_media_image = $media->get('field_media_image');
        if ($field_media_image && !$field_media_image->isEmpty()) {
          $file = $field_media_image->entity;
          if ($file) {
            $file_uris[] = $file->getFileUri();
          }
        }
      }
      if (empty($file_uris)) {
        throw new \Exception('No valid file URIs found for the media entities.');
      }

      // Get user's language preference from request (frontend sends this).
      $langcode = $data['language'] ?? NULL;

      // Get jurisdiction ID for filtering categories (multi-tenant mode, supports slugs).
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

          // Populate alt text with AI-generated description for accessibility.
          if (!empty($decoded_result['alt_text']) && is_array($decoded_result['alt_text'])) {
            $field_media_image = $media->get('field_media_image');
            if ($field_media_image && !$field_media_image->isEmpty()) {
              // Use the corresponding alt text for this media entity (by index).
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
          // entity reference validation rejects unpublished media for anonymous.
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

      // Return only the AI result for frontend compatibility.
      return new JsonResponse($decoded_result);

    }
    catch (\Exception $e) {
      $this->logger->error('Error in getAIResults: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => $this->t('An error occurred during image analysis.')], 500);
    }
  }

}
