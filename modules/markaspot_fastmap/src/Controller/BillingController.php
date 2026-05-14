<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\BillingStateResolver;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles billing data (Stripe fields) for jurisdiction groups.
 *
 * Write authentication is via service_key from markaspot_fastmap.settings,
 * provided as X-Service-Key header or in the JSON body. Read access is bound
 * to the authenticated workspace owner session.
 * Accepts both numeric group IDs and URL slugs via the {group}
 * path parameter.
 */
class BillingController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  private const STRIPE_CUSTOMER_FIELD = 'field_stripe_customer_id';
  private const STRIPE_CUSTOMER_TABLE = 'group__field_stripe_customer_id';
  private const STRIPE_CUSTOMER_COLUMN = 'field_stripe_customer_id_value';

  /**
   * The fastmap logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $fastmapLogger;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The current request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The billing state resolver.
   *
   * @var \Drupal\markaspot_fastmap\Service\BillingStateResolver
   */
  protected BillingStateResolver $billingStateResolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->fastmapLogger = $container->get('logger.channel.markaspot_fastmap');
    $instance->database = $container->get('database');
    $instance->requestStack = $container->get('request_stack');
    $instance->billingStateResolver = $container->get('markaspot_fastmap.billing_state_resolver');
    return $instance;
  }

  /**
   * Access check: validates group is a jur bundle and request is in scope.
   *
   * @param string $group
   *   The jurisdiction identifier (numeric ID or slug).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account being checked.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function access(string $group, AccountInterface $account): AccessResultInterface {
    $entity = $this->loadJurisdictionGroup($group);
    if (!$entity) {
      return AccessResult::forbidden('Jurisdiction not found.')
        ->addCacheContexts(['url.path'])
        ->setCacheMaxAge(0);
    }

    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return AccessResult::forbidden('Current request not found.')
        ->setCacheMaxAge(0);
    }

    if ($request->isMethod('GET')) {
      return $this->billingReadAccess($entity, $account);
    }

    if (!$this->validateServiceKey($request)) {
      $this->logBillingAudit('billing.access_invalid_key', 'warning', $entity, $request);
      return AccessResult::forbidden('Invalid service key.')
        ->setCacheMaxAge(0);
    }

    return AccessResult::allowed()->setCacheMaxAge(0);
  }

  /**
   * Checks authenticated read access for billing data.
   *
   * Billing details contain PII and Stripe identifiers. The global FastMap
   * service key is intentionally not accepted for reads because it is not
   * bound to a single jurisdiction.
   */
  private function billingReadAccess(GroupInterface $entity, AccountInterface $account): AccessResultInterface {
    if ((int) $account->id() === 1) {
      return AccessResult::allowed()
        ->addCacheContexts(['user'])
        ->addCacheableDependency($entity);
    }

    if (in_array('administrator', $account->getRoles(), TRUE)) {
      return AccessResult::allowed()
        ->addCacheContexts(['user.roles'])
        ->addCacheableDependency($entity);
    }

    $membership = $entity->getMember($account);
    $isTenantAdmin = FALSE;
    if ($membership) {
      foreach ($membership->getRoles() as $role) {
        if ($this->isJurisdictionRole($role, 'tenant_admin')) {
          $isTenantAdmin = TRUE;
          break;
        }
      }
    }

    return AccessResult::allowedIf($isTenantAdmin)
      ->addCacheContexts(['user'])
      ->addCacheableDependency($entity);
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
    if (!$this->isJurisdictionGroup($group)) {
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
    $entity = $this->loadJurisdictionGroup($group);
    if (!$entity) {
      return new JsonResponse(['error' => 'Jurisdiction not found'], 404);
    }
    if (!$this->billingReadAccess($entity, $this->currentUser())->isAllowed()) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $data = [
      'tier' => NULL,
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

    // Compute the workspace lifecycle state from billing fields. The frontend
    // tier picker keys the "Current Plan" badge off this, so it never shows a
    // tier the user has not actually paid for. See WorkspaceProvisioningService
    // — tier is NEVER set eagerly; only the Stripe webhook activates it.
    // The same state resolver is consumed by the operator-admin listing
    // (BillingAdminController) to guarantee both surfaces agree on which
    // lifecycle bucket a workspace is in.
    $data['effective_state'] = $this->billingStateResolver->resolve(
      $data['tier'],
      $data['stripe_customer_id'],
      $data['stripe_subscription_id'],
      $data['expiry_date']
    );

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
      $this->logBillingAudit('billing.access_invalid_key', 'warning', $group, $request);
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

    $transaction = $this->database->startTransaction();

    $customerReconcile = $this->reconcileStripeCustomer($entity, $data, $request);
    if ($customerReconcile instanceof JsonResponse) {
      $transaction->rollBack();
      return $customerReconcile;
    }

    $allowedFields = [
      'tier' => 'field_tier',
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
    $updated = $customerReconcile === 'initial_set' ? ['stripe_customer_id'] : [];

    foreach ($allowedFields as $key => $fieldName) {
      if (!array_key_exists($key, $data)) {
        continue;
      }
      if (!$entity->hasField($fieldName)) {
        continue;
      }

      $value = $data[$key];

      // Validate tier values. NULL is accepted: customer.subscription.deleted
      // resets tier to NULL (demo-equivalent), never to 'free'.
      if ($key === 'tier' && $value !== NULL) {
        if (!in_array($value, $validTiers, TRUE)) {
          $transaction->rollBack();
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
        // PII fields (billing_name, billing_email, billing_address_*) feed
        // the public-facing Impressum auto-generator. Stripe accepts free
        // text for customer.name + the tax_id business_name field, so
        // escape at storage time to defend the Impressum render path
        // against persistent XSS (CWE-79) without relying on every
        // future template to opt into Twig auto-escaping.
        if (str_starts_with($key, 'billing_')) {
          $value = Html::escape($value);
        }
        $entity->set($fieldName, $value);
      }
      $updated[] = $key;
    }

    if (empty($updated)) {
      $transaction->rollBack();
      return new JsonResponse(
        ['error' => 'No valid fields to update'],
        400
      );
    }

    try {
      // Group config carries new_revision=true, but the save path needs an
      // explicit setNewRevision() call to write a new groups_revision row
      // capturing the change. Required for GoBD §3.5.3 "Unveränderbarkeit"
      // + "Nachvollziehbarkeit" of billing stammdaten (AO §147).
      $entity->setNewRevision(TRUE);
      $entity->setRevisionLogMessage(sprintf(
        'Billing fields updated via Stripe sync: %s',
        implode(', ', $updated)
      ));
      $entity->setRevisionCreationTime(time());
      $entity->save();
      unset($transaction);
      $this->fastmapLogger->info(
        'Billing fields updated for group @id: @fields',
        [
          '@id' => $entity->id(),
          '@fields' => implode(', ', $updated),
        ]
      );
    }
    catch (\Exception $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
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

  /**
   * Reconciles the claimed Stripe customer against the stored scope anchor.
   *
   * @return string|\Symfony\Component\HttpFoundation\JsonResponse
   *   "initial_set", "match", or an error response.
   */
  private function reconcileStripeCustomer(GroupInterface $entity, array $data, Request $request): string|JsonResponse {
    $claimed = $this->extractClaimedStripeCustomer($data, $request);
    $stored = $this->getStoredStripeCustomer($entity);

    if ($claimed === NULL) {
      return new JsonResponse(['error' => 'stripe_customer_id required for billing update'], 400);
    }

    if ($stored !== NULL) {
      if (hash_equals($stored, $claimed)) {
        $this->logBillingAudit('billing.match', 'info', $entity, $request, $claimed, $stored);
        return 'match';
      }

      $this->logBillingAudit('billing.scope_violation', 'warning', $entity, $request, $claimed, $stored);
      return new JsonResponse(['error' => 'Stripe customer mismatch'], 403);
    }

    $result = $this->setInitialStripeCustomerAtomically($entity, $claimed);
    if ($result === 'initial_set') {
      $entity->set(self::STRIPE_CUSTOMER_FIELD, $claimed);
      $this->logBillingAudit('billing.initial_customer_set', 'info', $entity, $request, $claimed, NULL);
      return 'initial_set';
    }

    if ($result === 'match') {
      $entity->set(self::STRIPE_CUSTOMER_FIELD, $claimed);
      $this->logBillingAudit('billing.match', 'info', $entity, $request, $claimed, $claimed);
      return 'match';
    }

    $this->logBillingAudit('billing.scope_violation', 'warning', $entity, $request, $claimed, is_string($result) ? $result : NULL);
    return new JsonResponse(['error' => 'Stripe customer mismatch'], 403);
  }

  /**
   * Extracts the claimed Stripe customer from body or header.
   */
  private function extractClaimedStripeCustomer(array $data, Request $request): ?string {
    $claimed = $data['stripe_customer_id'] ?? $request->headers->get('X-Stripe-Customer');
    if ($claimed === NULL) {
      return NULL;
    }

    $claimed = mb_substr(trim((string) $claimed), 0, 255);
    return $claimed !== '' ? $claimed : NULL;
  }

  /**
   * Gets the currently stored Stripe customer ID.
   */
  private function getStoredStripeCustomer(GroupInterface $entity): ?string {
    if (!$entity->hasField(self::STRIPE_CUSTOMER_FIELD) || $entity->get(self::STRIPE_CUSTOMER_FIELD)->isEmpty()) {
      return NULL;
    }

    $value = trim((string) $entity->get(self::STRIPE_CUSTOMER_FIELD)->value);
    return $value !== '' ? $value : NULL;
  }

  /**
   * Sets the initial Stripe customer ID with a DB-level race guard.
   *
   * @return string
   *   "initial_set", "match", or the conflicting stored customer ID.
   */
  private function setInitialStripeCustomerAtomically(GroupInterface $entity, string $claimed): string {
    $groupId = (int) $entity->id();
    $langcode = $this->getEntityLangcode($entity);

    $updated = $this->database->update(self::STRIPE_CUSTOMER_TABLE)
      ->fields([self::STRIPE_CUSTOMER_COLUMN => $claimed])
      ->condition('entity_id', $groupId)
      ->condition('deleted', 0)
      ->condition('delta', 0)
      ->condition('langcode', $langcode)
      ->condition(self::STRIPE_CUSTOMER_COLUMN, '')
      ->execute();
    if ($updated > 0) {
      return 'initial_set';
    }

    try {
      $this->database->insert(self::STRIPE_CUSTOMER_TABLE)
        ->fields([
          'bundle' => $entity->bundle(),
          'deleted' => 0,
          'entity_id' => $groupId,
          'revision_id' => $this->getGroupRevisionId($entity),
          'langcode' => $langcode,
          'delta' => 0,
          self::STRIPE_CUSTOMER_COLUMN => $claimed,
        ])
        ->execute();
      return 'initial_set';
    }
    catch (IntegrityConstraintViolationException) {
      $stored = $this->loadStripeCustomerFromStorage($groupId, $langcode);
      if ($stored !== NULL && hash_equals($stored, $claimed)) {
        return 'match';
      }
      return $stored ?? '';
    }
  }

  /**
   * Loads the persisted Stripe customer ID after an atomic insert conflict.
   */
  private function loadStripeCustomerFromStorage(int $groupId, string $langcode): ?string {
    $value = $this->database->select(self::STRIPE_CUSTOMER_TABLE, 'f')
      ->fields('f', [self::STRIPE_CUSTOMER_COLUMN])
      ->condition('entity_id', $groupId)
      ->condition('deleted', 0)
      ->condition('delta', 0)
      ->condition('langcode', $langcode)
      ->execute()
      ->fetchField();

    $value = is_string($value) ? trim($value) : '';
    return $value !== '' ? $value : NULL;
  }

  /**
   * Gets the entity langcode needed for field table writes.
   */
  private function getEntityLangcode(GroupInterface $entity): string {
    try {
      $language = $entity->language();
      if ($language && method_exists($language, 'getId')) {
        return $language->getId();
      }
    }
    catch (\Throwable) {
    }

    return 'en';
  }

  /**
   * Gets the current group revision ID needed for field table writes.
   */
  private function getGroupRevisionId(GroupInterface $entity): int {
    if (method_exists($entity, 'getRevisionId') && $entity->getRevisionId()) {
      return (int) $entity->getRevisionId();
    }

    $revisionId = $this->database->select('groups', 'g')
      ->fields('g', ['revision_id'])
      ->condition('id', (int) $entity->id())
      ->execute()
      ->fetchField();

    return $revisionId ? (int) $revisionId : 0;
  }

  /**
   * Writes a structured billing audit event.
   */
  private function logBillingAudit(string $event, string $level, GroupInterface|string $group, Request $request, ?string $claimed = NULL, ?string $stored = NULL): void {
    $context = [
      '@group_id' => $group instanceof GroupInterface ? (string) $group->id() : $group,
      '@claimed_customer' => $claimed ?? '',
      '@stored_customer' => $stored ?? '',
      '@method' => $request->getMethod(),
      '@source_ip' => $request->getClientIp() ?? '',
    ];
    $message = $event . ' group_id=@group_id claimed_customer=@claimed_customer stored_customer=@stored_customer method=@method source_ip=@source_ip';

    if ($level === 'warning') {
      $this->fastmapLogger->warning($message, $context);
      return;
    }

    $this->fastmapLogger->info($message, $context);
  }

}
