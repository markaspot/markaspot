<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles FastMap workspace creation with email verification.
 *
 * Flow: POST create-workspace -> stores pending record -> sends verification
 * email -> GET verify/{token} -> provisions workspace -> redirects to dashboard.
 */
class FastMapWorkspaceController extends ControllerBase {

  protected Connection $database;
  protected WorkspaceProvisioningServiceInterface $provisioning;
  protected MailManagerInterface $mailManager;
  protected LoggerInterface $fastmapLogger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->database = $container->get('database');
    $instance->provisioning = $container->get('markaspot_fastmap.workspace_provisioning');
    $instance->mailManager = $container->get('plugin.manager.mail');
    $instance->fastmapLogger = $container->get('logger.channel.markaspot_fastmap');
    return $instance;
  }

  /**
   * POST /api/fastmap/create-workspace
   *
   * Validates input, stores a pending record and sends a verification email.
   * The workspace is NOT created until the token is verified.
   */
  public function createWorkspace(Request $request): JsonResponse {
    $content = $request->getContent();
    $data = json_decode($content, TRUE);

    if (!$data) {
      return new JsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    // Validate service key.
    $config = $this->config('markaspot_fastmap.settings');
    $expectedKey = $config->get('service_key');
    $apiKey = $data['service_key'] ?? $request->query->get('service_key');
    if (!$expectedKey || !$apiKey || !hash_equals($expectedKey, (string) $apiKey)) {
      return new JsonResponse(['error' => 'Invalid API key'], 403);
    }

    // Validate required fields.
    $name = mb_substr(trim($data['name'] ?? ''), 0, 255);
    $slug = trim($data['slug'] ?? '');
    $email = trim($data['email'] ?? '');
    $categories = $data['categories'] ?? [];

    if (!$name || !$slug) {
      return new JsonResponse(['error' => 'name and slug are required'], 400);
    }

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return new JsonResponse(['error' => 'A valid email address is required'], 400);
    }

    if (!preg_match('/^[a-z0-9-]{2,30}$/', $slug)) {
      return new JsonResponse(['error' => 'slug must be 2-30 chars, lowercase alphanumeric and hyphens'], 400);
    }

    if (empty($categories) || !is_array($categories)) {
      return new JsonResponse(['error' => 'categories must be provided'], 400);
    }

    // Check slug uniqueness early.
    $groupStorage = $this->entityTypeManager()->getStorage('group');
    $existing = $groupStorage->loadByProperties(['field_slug' => $slug]);
    if (!empty($existing)) {
      return new JsonResponse(['error' => 'Slug already taken'], 409);
    }

    // Check for existing pending request with same slug.
    $pendingExists = $this->database->select('markaspot_fastmap_pending', 'p')
      ->fields('p', ['id'])
      ->where("JSON_UNQUOTE(JSON_EXTRACT(p.workspace_data, '$.slug')) = :slug", [':slug' => $slug])
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($pendingExists) {
      return new JsonResponse(['error' => 'A pending request for this slug already exists'], 409);
    }

    // Generate verification token.
    $token = bin2hex(random_bytes(32));

    // Store all workspace data for later provisioning.
    $workspaceData = [
      'name' => $name,
      'slug' => $slug,
      'email' => $email,
      'categories' => $categories,
      'lat' => max(-90.0, min(90.0, (float) ($data['lat'] ?? 0))),
      'lng' => max(-180.0, min(180.0, (float) ($data['lng'] ?? 0))),
      'zoom' => (int) ($data['zoom'] ?? 13),
      'template' => $data['template'] ?? 'civic-report',
      'language' => $data['language'] ?? '',
      'boundary' => $data['boundary'] ?? NULL,
    ];

    try {
      $this->database->insert('markaspot_fastmap_pending')
        ->fields([
          'token' => $token,
          'email' => $email,
          'workspace_data' => json_encode($workspaceData, JSON_UNESCAPED_UNICODE),
          'created' => time(),
        ])
        ->execute();
    }
    catch (\Exception $e) {
      $this->fastmapLogger->error('Failed to store pending workspace: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Failed to create pending workspace'], 500);
    }

    // Build verify URL from config or request host.
    $verifyBaseUrl = $config->get('verify_base_url');
    if ($verifyBaseUrl) {
      $verifyUrl = rtrim($verifyBaseUrl, '/') . '/api/fastmap/verify/' . $token;
    }
    else {
      $verifyUrl = $request->getSchemeAndHttpHost() . '/api/fastmap/verify/' . $token;
    }

    $cleanupDays = (int) ($config->get('cleanup_days') ?? 7);
    $language = $workspaceData['language'] ?: 'en';

    // Send verification email. If it fails, roll back the pending row.
    $sent = $this->sendVerificationEmail($email, $verifyUrl, $name, $slug, $language, $cleanupDays);

    if (!$sent) {
      $this->database->delete('markaspot_fastmap_pending')
        ->condition('token', $token)
        ->execute();

      $this->fastmapLogger->error('Verification email failed for @email (slug: @slug). Pending row removed.', [
        '@email' => $email,
        '@slug' => $slug,
      ]);

      return new JsonResponse(['error' => 'Failed to send verification email. Please try again.'], 503);
    }

    // Clean up expired pending requests.
    $this->cleanupExpired();

    return new JsonResponse([
      'slug' => $slug,
      'name' => $name,
      'status' => 'pending',
      'message' => 'Check your email to verify the workspace.',
    ], 202);
  }

  /**
   * GET /api/fastmap/verify/{token}
   *
   * Looks up the pending record, provisions the workspace, then redirects.
   */
  public function verifyWorkspace(string $token): JsonResponse|TrustedRedirectResponse {
    $record = $this->database->select('markaspot_fastmap_pending', 'p')
      ->fields('p')
      ->condition('token', $token)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (!$record) {
      return new JsonResponse(['error' => 'Invalid or expired verification token'], 404);
    }

    // Check expiration (cleanup_days from config).
    $config = $this->config('markaspot_fastmap.settings');
    $cleanupDays = (int) ($config->get('cleanup_days') ?? 7);
    $maxAge = $cleanupDays * 86400;

    if ((time() - (int) $record['created']) > $maxAge) {
      $this->database->delete('markaspot_fastmap_pending')
        ->condition('id', $record['id'])
        ->execute();
      return new JsonResponse(['error' => 'Verification token has expired'], 410);
    }

    $workspaceData = json_decode($record['workspace_data'], TRUE);
    if (!$workspaceData) {
      return new JsonResponse(['error' => 'Corrupted workspace data'], 500);
    }

    try {
      $result = $this->provisioning->provisionWorkspace($workspaceData);

      // Delete the pending record.
      $this->database->delete('markaspot_fastmap_pending')
        ->condition('id', $record['id'])
        ->execute();

      $this->fastmapLogger->info('Workspace provisioned via email verification: @slug (group @id)', [
        '@slug' => $result['slug'],
        '@id' => $result['group_id'],
      ]);

      // Redirect to workspace dashboard or return JSON.
      $baseUrl = $config->get('workspace_base_url');
      if ($baseUrl) {
        $redirectUrl = str_replace(
          ['{slug}', '{id}'],
          [$result['slug'], $result['group_id']],
          $baseUrl
        );
        return new TrustedRedirectResponse($redirectUrl);
      }

      return new JsonResponse([
        'id' => $result['group_id'],
        'slug' => $result['slug'],
        'name' => $result['name'],
        'url' => $result['url'],
        'categories' => $result['categories'],
        'status' => 'provisioned',
      ], 201);
    }
    catch (\RuntimeException $e) {
      // Slug taken race condition or other provisioning error.
      return new JsonResponse(['error' => $e->getMessage()], 409);
    }
  }

  private function sendVerificationEmail(string $email, string $verifyUrl, string $name, string $slug, string $langcode, int $cleanupDays): bool {
    $config = $this->config('markaspot_fastmap.settings');
    $from = $config->get('mail_from') ?: NULL;
    $siteName = $this->config('system.site')->get('name') ?: 'FastMap';

    $params = [
      'workspace_name' => $name,
      'workspace_slug' => $slug,
      'verify_url' => $verifyUrl,
      'site_name' => $siteName,
      'cleanup_days' => (string) $cleanupDays,
    ];

    try {
      $result = $this->mailManager->mail(
        'markaspot_fastmap',
        'workspace_verification',
        $email,
        $langcode,
        $params,
        $from,
        TRUE
      );

      return $result['result'] ?? FALSE;
    }
    catch (\Exception $e) {
      $this->fastmapLogger->error('Failed to send verification email: @msg', ['@msg' => $e->getMessage()]);
      return FALSE;
    }
  }

  private function cleanupExpired(): void {
    $config = $this->config('markaspot_fastmap.settings');
    $cleanupDays = (int) ($config->get('cleanup_days') ?? 7);
    $cutoff = time() - ($cleanupDays * 86400);

    $this->database->delete('markaspot_fastmap_pending')
      ->condition('created', $cutoff, '<')
      ->execute();
  }

}
