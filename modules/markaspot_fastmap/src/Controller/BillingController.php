<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles billing data (Stripe fields) for jurisdiction groups.
 *
 * Authentication is via service_key from markaspot_fastmap.settings,
 * provided as X-Service-Key header or in the JSON body.
 * Accepts both numeric group IDs and URL slugs via the {group}
 * path parameter.
 */
class BillingController extends ControllerBase {

  use JurisdictionIdResolverTrait;

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
   * @param string $group
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function access(string $group): AccessResultInterface {
    $entity = $this->loadJurisdictionGroup($group);
    if (!$entity) {
      return AccessResult::forbidden('Jurisdiction not found.')
        ->addCacheContexts(['url.path'])
        ->setCacheMaxAge(0);
    }

    $request = \Drupal::request();
    if (!$this->validateServiceKey($request)) {
      return AccessResult::forbidden('Invalid service key.')
        ->setCacheMaxAge(0);
    }

    return AccessResult::allowed()->setCacheMaxAge(0);
  }

  /**
   * Loads a jurisdiction group from a slug or numeric ID.
   *
   * @param string $identifier
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The group entity, or NULL if not found.
   */
  private function loadJurisdictionGroup(string $identifier) {
    $resolved_id = $this->resolveJurisdictionId($identifier);
    if ($resolved_id === NULL) {
      return NULL;
    }

    $group = $this->entityTypeManager()
      ->getStorage('group')
      ->load($resolved_id);
    if (!$group || $group->bundle() !== 'jur') {
      return NULL;
    }

    return $group;
  }

  /**
   * Validates the service_key from the request against config.
   *
   * Accepts the key from the X-Service-Key header (preferred)
   * or JSON body.
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

    // Accept from custom header (preferred) or JSON body only.
    $apiKey = $request->headers->get('X-Service-Key');
    if (!$apiKey) {
      $data = json_decode($request->getContent(), TRUE);
      $apiKey = $data['service_key'] ?? NULL;
    }

    return $expectedKey
      && $apiKey
      && hash_equals($expectedKey, (string) $apiKey);
  }

  /**
   * Returns billing data for a jurisdiction group.
   *
   * @param string $group
   *   The jurisdiction identifier (numeric ID or slug).
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with tier, stripe_customer_id, stripe_subscription_id.
   */
  public function get(string $group, Request $request): JsonResponse {
    if (!$this->validateServiceKey($request)) {
      return new JsonResponse(['error' => 'Invalid service key'], 403);
    }

    $entity = $this->loadJurisdictionGroup($group);
    if (!$entity) {
      return new JsonResponse(['error' => 'Jurisdiction not found'], 404);
    }

    $data = [
      'tier' => 'free',
      'stripe_customer_id' => NULL,
      'stripe_subscription_id' => NULL,
      'expiry_date' => NULL,
      'billing_name' => NULL,
      'billing_email' => NULL,
      'billing_address_line1' => NULL,
      'billing_address_line2' => NULL,
      'billing_city' => NULL,
      'billing_postal_code' => NULL,
      'billing_country' => NULL,
      'billing_tax_id' => NULL,
    ];

    if ($entity->hasField('field_tier') && !$entity->get('field_tier')->isEmpty()) {
      $data['tier'] = $entity->get('field_tier')->value;
    }
    if ($entity->hasField('field_stripe_customer_id') && !$entity->get('field_stripe_customer_id')->isEmpty()) {
      $data['stripe_customer_id'] = $entity->get('field_stripe_customer_id')->value;
    }
    if ($entity->hasField('field_stripe_subscription_id') && !$entity->get('field_stripe_subscription_id')->isEmpty()) {
      $data['stripe_subscription_id'] = $entity->get('field_stripe_subscription_id')->value;
    }
    if ($entity->hasField('field_expiry_date') && !$entity->get('field_expiry_date')->isEmpty()) {
      $data['expiry_date'] = (int) $entity->get('field_expiry_date')->value;
    }

    $billingStringFields = [
      'billing_name' => 'field_billing_name',
      'billing_email' => 'field_billing_email',
      'billing_address_line1' => 'field_billing_address_line1',
      'billing_address_line2' => 'field_billing_address_line2',
      'billing_city' => 'field_billing_city',
      'billing_postal_code' => 'field_billing_postal_code',
      'billing_country' => 'field_billing_country',
      'billing_tax_id' => 'field_billing_tax_id',
    ];
    foreach ($billingStringFields as $key => $fieldName) {
      if ($entity->hasField($fieldName) && !$entity->get($fieldName)->isEmpty()) {
        $data[$key] = $entity->get($fieldName)->value;
      }
    }

    return new JsonResponse($data);
  }

  /**
   * Updates billing fields on a jurisdiction group.
   *
   * Accepts tier, stripe_customer_id, and stripe_subscription_id
   * in the JSON body. Fields can be set to null to clear them.
   *
   * @param string $group
   *   The jurisdiction identifier (numeric ID or slug).
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request with JSON body.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON confirming updated fields, or an error response.
   */
  public function update(string $group, Request $request): JsonResponse {
    if (!$this->validateServiceKey($request)) {
      return new JsonResponse(['error' => 'Invalid service key'], 403);
    }

    $entity = $this->loadJurisdictionGroup($group);
    if (!$entity) {
      return new JsonResponse(['error' => 'Jurisdiction not found'], 404);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!$data) {
      return new JsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    $allowedFields = [
      'tier' => 'field_tier',
      'stripe_customer_id' => 'field_stripe_customer_id',
      'stripe_subscription_id' => 'field_stripe_subscription_id',
      'expiry_date' => 'field_expiry_date',
      'billing_name' => 'field_billing_name',
      'billing_email' => 'field_billing_email',
      'billing_address_line1' => 'field_billing_address_line1',
      'billing_address_line2' => 'field_billing_address_line2',
      'billing_city' => 'field_billing_city',
      'billing_postal_code' => 'field_billing_postal_code',
      'billing_country' => 'field_billing_country',
      'billing_tax_id' => 'field_billing_tax_id',
    ];

    // Maximum lengths matching field storage definitions.
    $fieldMaxLengths = [
      'billing_postal_code' => 20,
      'billing_country' => 2,
      'billing_tax_id' => 50,
    ];

    $validTiers = ['free', 'starter', 'pro', 'heart'];
    $updated = [];

    foreach ($allowedFields as $key => $fieldName) {
      if (!array_key_exists($key, $data)) {
        continue;
      }
      if (!$entity->hasField($fieldName)) {
        continue;
      }

      $value = $data[$key];

      // Validate tier values.
      if ($key === 'tier') {
        if (!in_array($value, $validTiers, TRUE)) {
          return new JsonResponse(
            ['error' => 'Invalid tier value'],
            400
          );
        }
      }

      // Allow null to clear fields (e.g. expiry_date after Stripe payment).
      if ($value === NULL) {
        $entity->set($fieldName, NULL);
      }
      elseif ($key === 'expiry_date') {
        // Timestamp field: store as integer.
        $entity->set($fieldName, (int) $value);
      }
      else {
        $maxLength = $fieldMaxLengths[$key] ?? 255;
        $value = mb_substr(trim((string) $value), 0, $maxLength);
        $entity->set($fieldName, $value);
      }
      $updated[] = $key;
    }

    if (empty($updated)) {
      return new JsonResponse(
        ['error' => 'No valid fields to update'],
        400
      );
    }

    try {
      $entity->save();
      $this->fastmapLogger->info(
        'Billing fields updated for group @id: @fields',
        [
          '@id' => $entity->id(),
          '@fields' => implode(', ', $updated),
        ]
      );
    }
    catch (\Exception $e) {
      $this->fastmapLogger->error(
        'Failed to update billing for group @id: @msg',
        [
          '@id' => $entity->id(),
          '@msg' => $e->getMessage(),
        ]
      );
      return new JsonResponse(
        ['error' => 'Failed to update billing data'],
        500
      );
    }

    return new JsonResponse([
      'updated' => $updated,
      'group_id' => (int) $entity->id(),
    ]);
  }

}
