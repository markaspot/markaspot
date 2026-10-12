<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_vision\Controller\ImageProcessingController;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;
use Drupal\markaspot_vision\Service\MediaTenantScopeResolver;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Binds vision analysis scope and budget charging to the media tenant.
 *
 * Real media, reports, jurisdiction groups, upload fingerprints and tenant
 * bindings; only the AI provider, the tenant feature resolver and the SaaS
 * budget ledger are doubles, so every assertion about "no provider call" and
 * "no charge" is observable.
 *
 * @group markaspot_vision
 */
final class MediaTenantBindingKernelTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use TestFileCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'image', 'media', 'node', 'text',
    'filter', 'options', 'entity', 'flexible_permissions', 'group',
  ];

  /**
   * Tenant A.
   */
  private GroupInterface $tenantA;

  /**
   * Tenant B.
   */
  private GroupInterface $tenantB;

  /**
   * Jurisdiction IDs whose aiAnalysis feature is switched off.
   *
   * @var int[]
   */
  private array $disabledTenants = [];

  /**
   * Jurisdiction IDs passed to the provider, one entry per call.
   *
   * @var array<int|null>
   */
  private array $providerCalls = [];

  /**
   * Budget checks as [jurisdiction ID, tier].
   *
   * @var array<array{int, string}>
   */
  private array $budgetChecks = [];

  /**
   * Jurisdiction IDs charged for an analysis.
   *
   * @var int[]
   */
  private array $charges = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'file', 'media', 'node', 'group', 'group_relationship', 'group_config_wrapper'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'file', 'image', 'media', 'node', 'group']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $root = User::create(['uid' => 1, 'name' => 'root', 'status' => 1]);
    $root->save();
    // Groups record their creator as owner and first member.
    $this->container->get('current_user')->setAccount($root);

    $this->createMediaType('image', ['id' => 'request_image', 'label' => 'Request image']);
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $this->addField('node', 'service_request', 'field_request_media', 'entity_reference', ['target_type' => 'media']);
    $this->addField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->addField('group', 'jur', 'field_tier', 'string');

    $this->tenantA = $this->createTenant('Tenant A');
    $this->tenantB = $this->createTenant('Tenant B');

    Role::create(['id' => 'staff', 'label' => 'Staff'])
      ->grantPermission('update any media')
      ->grantPermission('view own unpublished media')
      ->save();
  }

  /**
   * A fresh anonymous upload is bound by its first validated analysis.
   */
  public function testAnonymousUploadIsBoundToItsFirstJurisdiction(): void {
    $request = $this->anonymousUploadRequest('upload-a');
    $media = $this->createUpload($request);

    $this->assertStatus(200, $this->analyze($request, [$media], $this->tenantA->id()));
    $this->assertSame([(int) $this->tenantA->id()], $this->providerCalls, 'The provider runs in the tenant context of the binding.');
    $this->assertSame(
      (int) $this->tenantA->id(),
      $this->container->get('keyvalue.expirable')->get(MediaTenantScopeResolver::STORE)->get($media->uuid()),
    );

    // The same upload cannot be re-analysed under another tenant.
    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantB->id()));
    // Omitting the ID keeps the binding instead of escaping it.
    $this->assertStatus(200, $this->analyze($request, [$media], NULL));
    $this->assertSame([(int) $this->tenantA->id(), (int) $this->tenantA->id()], $this->providerCalls);
  }

  /**
   * Media of a report are analysed and charged under the report's tenant.
   */
  public function testAttachedMediaFollowTheReportTenant(): void {
    $owner = $this->createUser('owner');
    $request = $this->sessionRequest($owner);
    $media = $this->createUpload($request, $owner);
    $this->createReport($this->tenantA, [$media]);

    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantB->id()));
    $this->assertSame([], $this->providerCalls, 'A conflicting tenant is refused before the provider.');

    $this->assertStatus(200, $this->analyze($request, [$media], NULL));
    $this->assertStatus(200, $this->analyze($request, [$media], $this->tenantA->id()));
    $this->assertSame([(int) $this->tenantA->id(), (int) $this->tenantA->id()], $this->providerCalls);
  }

  /**
   * Staff re-analysis keeps the report tenant too.
   */
  public function testStaffReanalysisIsScopedToTheReportTenant(): void {
    $uploader = $this->anonymousUploadRequest('upload-staff');
    $media = $this->createUpload($uploader);
    $this->createReport($this->tenantB, [$media]);

    $staff = $this->createUser('staff', ['staff']);
    $request = $this->sessionRequest($staff);
    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantA->id()));
    $this->assertStatus(200, $this->analyze($request, [$media], $this->tenantB->id()));
    $this->assertSame([(int) $this->tenantB->id()], $this->providerCalls);
  }

  /**
   * A server-side integration keeps the binding of its upload context.
   */
  public function testApiKeyIntegrationKeepsTheUploadBinding(): void {
    $integration = $this->createUser('integration');
    $this->container->get('current_user')->setAccount($integration);
    // Stateless API-key request: no session, but the same per-upload token.
    $request = $this->jsonRequest();
    $request->headers->set('X-CSRF-Token', 'integration-upload');
    $media = $this->createUpload($request, $integration);

    $this->assertStatus(200, $this->analyze($request, [$media], $this->tenantB->id()));
    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantA->id()));
    $this->assertSame([(int) $this->tenantB->id()], $this->providerCalls);
  }

  /**
   * A batch spanning tenants is refused as a whole.
   */
  public function testMixedTenantBatchIsRefused(): void {
    $request = $this->anonymousUploadRequest('upload-mixed');
    $first = $this->createUpload($request);
    $second = $this->createUpload($request);
    $this->createReport($this->tenantA, [$first]);
    $this->createReport($this->tenantB, [$second]);

    $this->assertStatus(409, $this->analyze($request, [$first, $second], $this->tenantA->id()));
    $this->assertStatus(409, $this->analyze($request, [$first, $second], NULL));

    // Bound fresh uploads mix the same way.
    $third = $this->createUpload($request);
    $fourth = $this->createUpload($request);
    $this->assertStatus(200, $this->analyze($request, [$third], $this->tenantA->id()));
    $this->assertStatus(200, $this->analyze($request, [$fourth], $this->tenantB->id()));
    $this->assertStatus(409, $this->analyze($request, [$third, $fourth], NULL));
    $this->assertCount(2, $this->providerCalls, 'Only the two single-tenant batches reached the provider.');
  }

  /**
   * A disabled tenant cannot escape its policy on a non-Fastmap install.
   */
  public function testDisabledTenantCannotBypassByOmittingOrChangingTheId(): void {
    $this->disabledTenants = [(int) $this->tenantA->id()];
    $request = $this->anonymousUploadRequest('upload-disabled');
    $media = $this->createUpload($request);
    $this->createReport($this->tenantA, [$media]);

    $this->assertStatus(403, $this->analyze($request, [$media], NULL));
    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantB->id()));

    // A fresh upload on a multi-tenant install must name its tenant.
    $fresh = $this->createUpload($request);
    $this->assertStatus(400, $this->analyze($request, [$fresh], NULL));
    $this->assertSame([], $this->providerCalls);
  }

  /**
   * One published jurisdiction is the validated single-tenant fallback.
   */
  public function testSingleTenantInstallFallsBackToItsJurisdiction(): void {
    $this->tenantB->setUnpublished()->save();
    $request = $this->anonymousUploadRequest('upload-single');
    $media = $this->createUpload($request);

    $this->assertStatus(200, $this->analyze($request, [$media], NULL));
    $this->assertSame([(int) $this->tenantA->id()], $this->providerCalls);

    $this->disabledTenants = [(int) $this->tenantA->id()];
    $other = $this->createUpload($request);
    $this->assertStatus(403, $this->analyze($request, [$other], NULL), 'The fallback applies the tenant policy.');
    // An unpublished jurisdiction cannot be named either.
    $this->assertStatus(400, $this->analyze($request, [$this->createUpload($request)], $this->tenantB->id()));
  }

  /**
   * Installs without jurisdictions keep working unscoped.
   */
  public function testInstallWithoutJurisdictionsStaysUnscoped(): void {
    $this->tenantA->delete();
    $this->tenantB->delete();
    $request = $this->anonymousUploadRequest('upload-plain');
    $media = $this->createUpload($request);

    $this->assertStatus(200, $this->analyze($request, [$media], NULL));
    $this->assertSame([NULL], $this->providerCalls);
  }

  /**
   * Fastmap checks and charges the budget of the media tenant only.
   */
  public function testFastmapChargesTheMediaTenantOnly(): void {
    $request = $this->anonymousUploadRequest('upload-fastmap');
    $media = $this->createUpload($request);
    $this->createReport($this->tenantA, [$media]);

    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantB->id(), TRUE));
    $this->assertSame([], $this->budgetChecks, 'A foreign tenant is refused before its budget is consulted.');
    $this->assertSame([], $this->charges);

    $this->assertStatus(200, $this->analyze($request, [$media], $this->tenantA->id(), TRUE));
    $this->assertSame([[(int) $this->tenantA->id(), 'pro']], $this->budgetChecks);
    $this->assertSame([(int) $this->tenantA->id()], $this->charges);
  }

  /**
   * Fastmap refuses a fresh upload without a tenant and keeps the binding.
   */
  public function testFastmapFreshUploadNeedsAndKeepsItsTenant(): void {
    $request = $this->anonymousUploadRequest('upload-fastmap-fresh');
    $media = $this->createUpload($request);

    $this->assertStatus(400, $this->analyze($request, [$media], NULL, TRUE));
    $this->assertStatus(200, $this->analyze($request, [$media], $this->tenantB->id(), TRUE));
    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantA->id(), TRUE));
    $this->assertSame([(int) $this->tenantB->id()], $this->charges);
  }

  /**
   * Unknown jurisdictions and foreign upload sessions are refused.
   */
  public function testInvalidJurisdictionAndForeignSessionAreRefused(): void {
    $request = $this->anonymousUploadRequest('upload-own');
    $media = $this->createUpload($request);

    $this->assertStatus(400, $this->analyze($request, [$media], 999999));

    $stranger = $this->anonymousUploadRequest('upload-stranger');
    $this->assertStatus(403, $this->analyze($stranger, [$media], $this->tenantA->id()));
    $this->assertSame([], $this->providerCalls);
  }

  /**
   * Runs the analysis endpoint with doubles for provider, policy and budget.
   *
   * @param \Symfony\Component\HttpFoundation\Request $context
   *   The request carrying the caller's session or upload token.
   * @param \Drupal\media\MediaInterface[] $media
   *   The media to analyse.
   * @param int|string|null $jurisdiction_id
   *   The jurisdiction named by the caller.
   * @param bool $fastmap
   *   Whether the SaaS budget ledger is installed.
   */
  private function analyze(Request $context, array $media, int|string|null $jurisdiction_id, bool $fastmap = FALSE): JsonResponse {
    $body = ['media_ids' => array_map(static fn (MediaInterface $item): string => $item->uuid(), $media)];
    if ($jurisdiction_id !== NULL) {
      $body['jurisdiction_id'] = $jurisdiction_id;
    }
    $request = Request::create('/api/vision/analyze', 'POST', [], [], [], [], json_encode($body));
    $request->headers->replace($context->headers->all());
    if ($context->hasSession()) {
      $request->setSession($context->getSession());
    }

    $provider = $this->createMock(ImageProcessingService::class);
    $provider->method('processImages')->willReturnCallback(function (array $uris, ?string $langcode, ?int $jurisdiction): array {
      $this->providerCalls[] = $jurisdiction;
      return [
        'ai_result' => json_encode(['privacy_flag' => FALSE, 'privacy_issues' => []]),
        'blur_results' => [],
        'skipped_uris' => [],
      ];
    });
    $features = $this->createMock(FeatureScopeResolver::class);
    $features->method('isEnabledEffective')->willReturnCallback(
      fn (string $path, GroupInterface $jurisdiction): bool => !in_array((int) $jurisdiction->id(), $this->disabledTenants, TRUE),
    );
    $tier = NULL;
    if ($fastmap) {
      $tier = $this->createMock(TierConfigService::class);
      $tier->method('isAIBudgetExhausted')->willReturnCallback(function (int $group_id, string $tier_name): bool {
        $this->budgetChecks[] = [$group_id, $tier_name];
        return FALSE;
      });
      $tier->method('recordAIAnalysis')->willReturnCallback(function (int $group_id): void {
        $this->charges[] = $group_id;
      });
    }

    $controller = new ImageProcessingController(
      $provider,
      $this->container->get('logger.factory'),
      $this->container->get('flood'),
      $features,
      $this->guard(),
      $this->container->get('config.factory'),
      $this->container->get('markaspot_vision_test.scope_resolver'),
      $tier,
    );
    $controller->setStringTranslation($this->container->get('string_translation'));
    return $controller->getAIResults($request);
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // The module itself is not installed (it pulls in the whole Nuxt layer);
    // register the real resolver the way its services.yml does.
    $container->register('markaspot_vision_test.scope_resolver', MediaTenantScopeResolver::class)
      ->addArgument(new Reference('entity_type.manager'))
      ->addArgument(new Reference('keyvalue.expirable'))
      ->addArgument(new Reference('config.factory'));
  }

  /**
   * Returns the real upload-context guard.
   */
  private function guard(): MediaAnalysisAccessGuard {
    return new MediaAnalysisAccessGuard(
      $this->container->get('current_user'),
      $this->container->get('keyvalue.expirable'),
      $this->container->get('request_stack'),
    );
  }

  /**
   * Creates an uploaded request image tied to the request's upload context.
   */
  private function createUpload(Request $request, ?AccountInterface $owner = NULL): MediaInterface {
    $source = $this->getTestFiles('image')[0];
    $file = File::create(['uri' => $source->uri, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'request_image',
      'name' => 'upload',
      'uid' => $owner ? $owner->id() : 0,
      'status' => 0,
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'upload'],
    ]);
    $media->save();
    $this->guard()->recordUpload($media, $request);
    return $media;
  }

  /**
   * Creates a report of a tenant referencing the media.
   *
   * @param \Drupal\group\Entity\GroupInterface $tenant
   *   The tenant.
   * @param \Drupal\media\MediaInterface[] $media
   *   The referenced media.
   */
  private function createReport(GroupInterface $tenant, array $media): void {
    Node::create([
      'type' => 'service_request',
      'title' => 'Report',
      'uid' => 1,
      'field_jurisdiction' => $tenant->id(),
      'field_request_media' => array_map(static fn (MediaInterface $item): array => ['target_id' => $item->id()], $media),
    ])->save();
  }

  /**
   * Creates a published tenant on a paid tier.
   */
  private function createTenant(string $label): GroupInterface {
    $tenant = Group::create(['type' => 'jur', 'label' => $label, 'field_tier' => 'pro']);
    $tenant->save();
    return $tenant;
  }

  /**
   * Creates an active user.
   */
  private function createUser(string $name, array $roles = []): AccountInterface {
    $user = User::create(['name' => $name, 'status' => 1, 'roles' => $roles]);
    $user->save();
    return $user;
  }

  /**
   * Builds an anonymous request carrying a per-upload token.
   */
  private function anonymousUploadRequest(string $token): Request {
    $this->container->get('current_user')->setAccount(User::getAnonymousUser());
    $request = $this->jsonRequest();
    $request->headers->set('X-CSRF-Token', $token);
    return $request;
  }

  /**
   * Builds a cookie-authenticated request for the account.
   */
  private function sessionRequest(AccountInterface $account): Request {
    $this->container->get('current_user')->setAccount($account);
    $session = new Session(new MockArraySessionStorage());
    $session->set('uid', (int) $account->id());
    $request = $this->jsonRequest();
    $request->setSession($session);
    return $request;
  }

  /**
   * Builds an empty JSON request.
   */
  private function jsonRequest(): Request {
    $request = Request::create('/api/vision/analyze', 'POST');
    $request->headers->set('Content-Type', 'application/json');
    return $request;
  }

  /**
   * Adds a field to a bundle.
   */
  private function addField(string $entity_type, string $bundle, string $name, string $type, array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'type' => $type,
      'settings' => $settings,
      'cardinality' => $type === 'entity_reference' && $name === 'field_request_media' ? -1 : 1,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $name,
    ])->save();
  }

  /**
   * Asserts a JSON response status with its body in the failure message.
   */
  private function assertStatus(int $expected, JsonResponse $response, string $message = ''): void {
    $this->assertSame($expected, $response->getStatusCode(), trim($message . ' ' . $response->getContent()));
  }

}
