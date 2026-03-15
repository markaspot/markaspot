<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\group\Entity\GroupInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles billing data (Stripe fields) for jurisdiction groups.
 *
 * Authentication is via service_key from markaspot_fastmap.settings,
 * matching the pattern used by FastMapWorkspaceController.
 */
class BillingController extends ControllerBase {

  /**
   * The fastmap logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $fastmapLogger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->fastmapLogger = $container->get('logger.channel.markaspot_fastmap');
    return $instance;
  }

  /**
   * Access check: validates group is a jur bundle.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function access(GroupInterface $group): AccessResultInterface {
    // Only jur groups have billing data.
    if ($group->bundle() !== 'jur') {
      return AccessResult::forbidden('Not a jurisdiction group.');
    }
    return AccessResult::allowed();
  }

  /**
   * Validates the service_key from the request against config.
   *
   * Accepts the key from the X-Service-Key header (preferred),
   * JSON body, or query parameter (deprecated).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return bool
   *   TRUE if the key is valid.
   */
  private function validateServiceKey(Request $request): bool {
    $config = $this->config('markaspot_fastmap.settings');
    $expectedKey = $config->get('service_key');

    // Accept from custom header (preferred), JSON body, or query param (deprecated).
    $apiKey = $request->headers->get('X-Service-Key');
    if (!$apiKey) {
      $data = json_decode($request->getContent(), TRUE);
      $apiKey = $data['service_key'] ?? $request->query->get('service_key');
    }

    return $expectedKey && $apiKey && hash_equals($expectedKey, (string) $apiKey);
  }

  /**
   * Returns billing data for a jurisdiction group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group entity.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with tier, stripe_customer_id, stripe_subscription_id.
   */
  public function get(GroupInterface $group, Request $request): JsonResponse {
    if (!$this->validateServiceKey($request)) {
      return new JsonResponse(['error' => 'Invalid service key'], 403);
    }

    $data = [
      'tier' => 'free',
      'stripe_customer_id' => NULL,
      'stripe_subscription_id' => NULL,
    ];

    if ($group->hasField('field_tier') && !$group->get('field_tier')->isEmpty()) {
      $data['tier'] = $group->get('field_tier')->value;
    }
    if ($group->hasField('field_stripe_customer_id') && !$group->get('field_stripe_customer_id')->isEmpty()) {
      $data['stripe_customer_id'] = $group->get('field_stripe_customer_id')->value;
    }
    if ($group->hasField('field_stripe_subscription_id') && !$group->get('field_stripe_subscription_id')->isEmpty()) {
      $data['stripe_subscription_id'] = $group->get('field_stripe_subscription_id')->value;
    }

    return new JsonResponse($data);
  }

  /**
   * Updates billing fields on a jurisdiction group.
   *
   * Accepts tier, stripe_customer_id, and stripe_subscription_id in the
   * JSON body. Fields can be set to null to clear them.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group entity.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request with JSON body.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON confirming updated fields, or an error response.
   */
  public function update(GroupInterface $group, Request $request): JsonResponse {
    if (!$this->validateServiceKey($request)) {
      return new JsonResponse(['error' => 'Invalid service key'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!$data) {
      return new JsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    $allowedFields = [
      'tier' => 'field_tier',
      'stripe_customer_id' => 'field_stripe_customer_id',
      'stripe_subscription_id' => 'field_stripe_subscription_id',
    ];

    $validTiers = ['free', 'starter', 'pro', 'heart'];
    $updated = [];

    foreach ($allowedFields as $key => $fieldName) {
      if (!array_key_exists($key, $data)) {
        continue;
      }
      if (!$group->hasField($fieldName)) {
        continue;
      }

      $value = $data[$key];

      // Validate tier values.
      if ($key === 'tier') {
        if (!in_array($value, $validTiers, TRUE)) {
          return new JsonResponse(['error' => 'Invalid tier value'], 400);
        }
      }

      // Allow null to clear Stripe fields.
      if ($value === NULL) {
        $group->set($fieldName, NULL);
      }
      else {
        // Sanitize string values.
        $value = mb_substr(trim((string) $value), 0, 255);
        $group->set($fieldName, $value);
      }
      $updated[] = $key;
    }

    if (empty($updated)) {
      return new JsonResponse(['error' => 'No valid fields to update'], 400);
    }

    try {
      $group->save();
      $this->fastmapLogger->info('Billing fields updated for group @id: @fields', [
        '@id' => $group->id(),
        '@fields' => implode(', ', $updated),
      ]);
    }
    catch (\Exception $e) {
      $this->fastmapLogger->error('Failed to update billing for group @id: @msg', [
        '@id' => $group->id(),
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'Failed to update billing data'], 500);
    }

    return new JsonResponse([
      'updated' => $updated,
      'group_id' => $group->id(),
    ]);
  }

}
