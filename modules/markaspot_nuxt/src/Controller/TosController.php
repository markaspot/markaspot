<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Handles Terms of Service acceptance for authenticated users.
 *
 * Provides endpoints to accept ToS (setting a timestamp on the user entity)
 * and to check the current ToS acceptance status.
 */
class TosController extends ControllerBase {

  /**
   * The time service.
   *
   * @var \Drupal\Core\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $tosLogger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->time = $container->get('datetime.time');
    $instance->tosLogger = $container->get('logger.channel.markaspot_nuxt');
    return $instance;
  }

  /**
   * POST /api/accept-tos.
   *
   * Records the current timestamp as the ToS acceptance time on the
   * authenticated user's field_tos_accepted_at field.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with the acceptance timestamp or an error.
   */
  public function accept(): JsonResponse {
    $account = $this->currentUser();

    if ($account->isAnonymous()) {
      return new JsonResponse(['error' => 'Authentication required'], 401);
    }

    $user = $this->entityTypeManager()
      ->getStorage('user')
      ->load($account->id());

    if (!$user) {
      return new JsonResponse(['error' => 'User not found'], 404);
    }

    if (!$user->hasField('field_tos_accepted_at')) {
      $this->tosLogger->error('field_tos_accepted_at does not exist on user entity. Run update hooks.');
      return new JsonResponse(['error' => 'ToS field not configured'], 500);
    }

    $timestamp = $this->time->getRequestTime();

    try {
      $user->set('field_tos_accepted_at', $timestamp);
      $user->save();

      $this->tosLogger->info('ToS accepted by user @uid at @time.', [
        '@uid' => $account->id(),
        '@time' => $timestamp,
      ]);

      return new JsonResponse([
        'accepted_at' => $timestamp,
      ]);
    }
    catch (\Exception $e) {
      $this->tosLogger->error('Failed to save ToS acceptance for user @uid: @msg', [
        '@uid' => $account->id(),
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'Failed to record ToS acceptance'], 500);
    }
  }

  /**
   * GET /api/tos-status.
   *
   * Returns the ToS acceptance status for the authenticated user.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with accepted (bool) and accepted_at (timestamp or null).
   */
  public function status(): JsonResponse {
    $account = $this->currentUser();

    if ($account->isAnonymous()) {
      return new JsonResponse(['error' => 'Authentication required'], 401);
    }

    $user = $this->entityTypeManager()
      ->getStorage('user')
      ->load($account->id());

    if (!$user) {
      return new JsonResponse(['error' => 'User not found'], 404);
    }

    if (!$user->hasField('field_tos_accepted_at')) {
      return new JsonResponse([
        'accepted' => FALSE,
        'accepted_at' => NULL,
      ]);
    }

    $value = $user->get('field_tos_accepted_at')->value;
    $acceptedAt = $value !== NULL ? (int) $value : NULL;

    return new JsonResponse([
      'accepted' => $acceptedAt !== NULL,
      'accepted_at' => $acceptedAt,
    ]);
  }

}
