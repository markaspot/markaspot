<?php

namespace Drupal\markaspot_vision\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\markaspot_vision\Service\ImageProcessingService;

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
   * Constructs a new ImageProcessingController object.
   *
   * @param \Drupal\markaspot_vision\Service\ImageProcessingService $image_processing_service
   *   The image processing service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ImageProcessingService $image_processing_service,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->imageProcessingService = $image_processing_service;
    $this->logger = $logger_factory->get('markaspot_vision');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_vision.image_processing'),
      $container->get('logger.factory')
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
  public function getAIResults(Request $request): JsonResponse {
    try {
      // Decode incoming request content
      $data = json_decode($request->getContent(), TRUE);
      if (empty($data['media_ids']) || !is_array($data['media_ids'])) {
        throw new \Exception('Invalid input: "media_ids" is required and should be an array.');
      }

      // Fetch media entities by UUIDs
      $media_entities = [];
      foreach ($data['media_ids'] as $uuid) {
        $media_storage = $this->entityTypeManager()->getStorage('media');
        $media = $media_storage->loadByProperties(['uuid' => $uuid]);
        $media = reset($media); // Get the first entity matching the UUID
        if ($media) {
          $media_entities[$media->id()] = $media;
        }
      }

      if (empty($media_entities)) {
        throw new \Exception('No valid media entities found for the provided media_ids.');
      }

      // Collect file URIs
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
      // Process images with ImageProcessingService
      $ai_result = $this->imageProcessingService->processImages($file_uris);
      if (!$ai_result) {
        throw new \Exception('Failed to process images using the AI service.');
      }
      // Decode AI results
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
          $media->set('field_ai_privacy_issues', implode(', ', (array)$privacy_issues));
          $media->set('field_ai_hazard_flag', $hazard_flag);
          $media->set('field_ai_hazard_issues', implode(', ', (array)$hazard_issues));
          
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

          $this->logger->notice('Decoded result: @result', [
            '@result' => print_r($decoded_result, TRUE),
          ]);
          $this->logger->notice('Privacy issues: @issues', [
            '@issues' => print_r($privacy_issues, TRUE),
          ]);

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

      // Return only the AI result for frontend compatibility.
      return new JsonResponse($decoded_result);

    }
    catch (\Exception $e) {
      $this->logger->error('Error in getAIResults: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

}
