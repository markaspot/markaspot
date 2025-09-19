<?php

namespace Drupal\markaspot_confirm\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Class ConfirmController.
 */
class ConfirmController extends ControllerBase {

  public static function create(ContainerInterface $container) {
    $confirm_service = $container->get('markaspot_confirm.confirm');
    $config_factory = $container->get('config.factory');
    return new static($confirm_service, $config_factory);
  }

  /**
   * The confirm service.
   *
   * @var \Drupal\markaspot_confirm\ConfirmServiceInterface
   */
  protected $confirm_service;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new ConfirmController object.
   *
   * @param \Drupal\markaspot_confirm\ConfirmServiceInterface $confirm_service
   *   The confirm service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct($confirm_service, ConfigFactoryInterface $config_factory)  {
    $this->confirm_service = $confirm_service;
    $this->configFactory = $config_factory;
  }

  /**
   * Confirm Node.
   *
   * @param string $uuid
   *   The UUID of the node.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @return array|\Symfony\Component\HttpFoundation\JsonResponse
   *   Return message or JSON response.
   */
  public function doConfirm($uuid, Request $request = NULL) {
    $confirm = $this->confirm_service;
    $nodes = array_filter($confirm->load($uuid));
    $config = $this->configFactory->get('markaspot_confirm.settings');

    // Check if this is a JSON request (Accept header or _format parameter)
    $is_json_request = FALSE;
    if ($request) {
      $accept_header = $request->headers->get('Accept', '');
      $format = $request->query->get('_format');
      $is_json_request = (strpos($accept_header, 'application/json') !== FALSE) || ($format === 'json');
    }

    if (!empty($nodes)) {
      foreach ($nodes as $node){
        $node->field_approved->value = 1;
        $node->save();
      }

      $message = $config->get('api.success_message') ?: $this->t('Thanks for approving this request.');

      if ($is_json_request) {
        return new JsonResponse([
          'success' => TRUE,
          'message' => $message,
          'status' => 'confirmed'
        ]);
      }

      $markup = "<p>" . $message . "</p>";
    } else {
      $message = $config->get('api.not_found_message') ?: $this->t('This request could not be found.');

      if ($is_json_request) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => $message,
          'status' => 'not_found'
        ], 404);
      }

      $markup = "<p>" . $message . "</p>";
    }

    return [
      '#type' => 'markup',
      '#markup' => $markup
    ];
  }

}
