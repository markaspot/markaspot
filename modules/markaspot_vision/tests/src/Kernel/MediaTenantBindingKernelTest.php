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
use Drupal\node\NodeInterface;
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
 * Real media, reports, jurisdiction groups, Group relationships, upload
 * fingerprints and tenant bindings; only the AI provider, the tenant feature
 * resolver and the SaaS budget ledger are doubles, so every assertion about
 * "no provider call" and "no charge" is observable.
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
    'filter', 'options', 'entity', 'flexible_permissions', 'group', 'gnode',
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
    $relationship_types = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    $relationship_types->createFromPlugin(GroupType::load('jur'), 'group_node:service_request')->save();
    $this->addField('node', 'service_request', 'field_request_media', 'entity_reference', ['target_type' => 'media'], -1);
    // Reports may name several jurisdictions; the resolver must see all.
    $this->addField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group'], -1);
    $this->addField('group', 'jur', 'field_tier', 'string');

    $this->tenantA = $this->createTenant('Tenant A');
    $this->tenantB = $this->createTenant('Tenant B');

    Role::create(['id' => 'staff', 'label' => 'Staff'])
      ->grantPermission('update any media')
      ->grantPermission('view own unpublished media')
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    unset($GLOBALS['config']['markaspot_vision.settings']);
    parent::tearDown();
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
      ->addArgument(new Reference('keyvalue'))
      ->addArgument(new Reference('config.factory'));
  }

  /**
   * The upload request fixes the tenant; analysis can only follow it.
   */
  public function testUploadBindingIsFixedAtUploadTime(): void {
    $request = $this->anonymousUploadRequest('upload-header');
    $media = $this->createUpload($request, NULL, (string) $this->tenantB->id());
    $this->assertSame((int) $this->tenantB->id(), $this->binding($media));

    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantA->id()));
    $this->assertStatus(200, $this->analyze($request, [$media], NULL));
    $this->assertStatus(200, $this->analyze($request, [$media], $this->tenantB->id()));
    $this->assertSame([(int) $this->tenantB->id(), (int) $this->tenantB->id()], $this->providerCalls);
    $this->assertSame((int) $this->tenantB->id(), $this->binding($media), 'The binding never moves.');

    // An upload naming an unknown jurisdiction is left unbound, not bound to
    // a fallback.
    $other = $this->createUpload($request, NULL, '999999');
    $this->assertNull($this->binding($other));
  }

  /**
   * With the setting on, an upload not bound at upload time is refused.
   */
  public function testRequiredUploadJurisdictionRefusesUnboundUploads(): void {
    $GLOBALS['config']['markaspot_vision.settings']['require_upload_jurisdiction'] = TRUE;
    $request = $this->anonymousUploadRequest('upload-required');
    $unbound = $this->createUpload($request);

    $this->assertStatus(400, $this->analyze($request, [$unbound], $this->tenantA->id()));
    $this->assertNull($this->binding($unbound), 'A refused request binds nothing.');

    $bound = $this->createUpload($request, NULL, (string) $this->tenantA->id());
    $this->assertStatus(200, $this->analyze($request, [$bound], NULL));
    $this->assertSame([(int) $this->tenantA->id()], $this->providerCalls);
  }

  /**
   * Transition: an upload made without the header is bound once by analysis.
   */
  public function testTransitionalUploadIsBoundOnceByItsFirstAnalysis(): void {
    $request = $this->anonymousUploadRequest('upload-transition');
    $media = $this->createUpload($request);
    $this->assertNull($this->binding($media));

    $this->assertStatus(200, $this->analyze($request, [$media], $this->tenantA->id()));
    $this->assertSame((int) $this->tenantA->id(), $this->binding($media));
    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantB->id()));
    $this->assertStatus(200, $this->analyze($request, [$media], NULL));
    $this->assertSame([(int) $this->tenantA->id(), (int) $this->tenantA->id()], $this->providerCalls);
  }

  /**
   * The binding outlives the upload session for owners and staff.
   */
  public function testBindingOutlivesTheUploadSession(): void {
    $owner = $this->createUser('owner');
    $request = $this->sessionRequest($owner);
    $media = $this->createUpload($request, $owner, (string) $this->tenantA->id());

    // The anonymous upload proof expires; cookie owners may still analyze.
    $this->container->get('keyvalue.expirable')->get(MediaAnalysisAccessGuard::STORE)->delete($media->uuid());
    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantB->id()));
    $this->assertStatus(200, $this->analyze($request, [$media], NULL));
    $this->assertSame([(int) $this->tenantA->id()], $this->providerCalls);

    // The binding goes with the media (hook_media_delete calls forget()).
    $this->resolver()->forget($media);
    $this->assertNull($this->binding($media));
  }

  /**
   * Media of a report are analysed and charged under the report's tenant.
   */
  public function testAttachedMediaFollowTheReportTenant(): void {
    $owner = $this->createUser('owner');
    $request = $this->sessionRequest($owner);
    $media = $this->createUpload($request, $owner);
    $this->createReport([$this->tenantA], [$media]);

    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantB->id()));
    $this->assertSame([], $this->providerCalls, 'A conflicting tenant is refused before the provider.');

    $this->assertStatus(200, $this->analyze($request, [$media], NULL));
    $this->assertStatus(200, $this->analyze($request, [$media], $this->tenantA->id()));
    $this->assertSame([(int) $this->tenantA->id(), (int) $this->tenantA->id()], $this->providerCalls);
  }

  /**
   * A report naming several jurisdictions is a conflict, not its first item.
   */
  public function testMultiValuedReportJurisdictionIsRefused(): void {
    $request = $this->anonymousUploadRequest('upload-multi');
    $media = $this->createUpload($request, NULL, (string) $this->tenantA->id());
    $this->createReport([$this->tenantA, $this->tenantB], [$media]);

    $this->assertStatus(409, $this->analyze($request, [$media], $this->tenantA->id()));
    $this->assertStatus(409, $this->analyze($request, [$media], NULL));
    $this->assertSame([], $this->providerCalls);
  }

  /**
   * A report without resolvable scope never adopts the caller's tenant.
   */
  public function testReportWithoutResolvableScopeIsRefused(): void {
    $request = $this->anonymousUploadRequest('upload-orphan');
    $media = $this->createUpload($request);
    $this->createReport([], [$media]);

    $this->assertStatus(409, $this->analyze($request, [$media], $this->tenantA->id()));
    $this->assertNull($this->binding($media), 'The caller could not bind it either.');
    $this->assertSame([], $this->providerCalls);
  }

  /**
   * A report's Group relationship resolves its tenant.
   */
  public function testGroupRelationshipResolvesTheReportTenant(): void {
    $request = $this->anonymousUploadRequest('upload-gnode');
    $media = $this->createUpload($request);
    $report = $this->createReport([], [$media]);
    $this->tenantB->addRelationship($report, 'group_node:service_request');

    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantA->id()));
    $this->assertStatus(200, $this->analyze($request, [$media], NULL));
    $this->assertSame([(int) $this->tenantB->id()], $this->providerCalls);
  }

  /**
   * Staff re-analysis keeps the report tenant too.
   */
  public function testStaffReanalysisIsScopedToTheReportTenant(): void {
    $uploader = $this->anonymousUploadRequest('upload-staff');
    $media = $this->createUpload($uploader);
    $this->createReport([$this->tenantB], [$media]);

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
    $media = $this->createUpload($request, $integration, (string) $this->tenantB->id());

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
    $this->createReport([$this->tenantA], [$first]);
    $this->createReport([$this->tenantB], [$second]);
    $this->assertStatus(409, $this->analyze($request, [$first, $second], $this->tenantA->id()));

    $third = $this->createUpload($request, NULL, (string) $this->tenantA->id());
    $fourth = $this->createUpload($request, NULL, (string) $this->tenantB->id());
    $this->assertStatus(409, $this->analyze($request, [$third, $fourth], NULL));
    $this->assertSame([], $this->providerCalls);
  }

  /**
   * Two first analyses racing for one unbound upload: the loser is refused.
   */
  public function testConcurrentFirstBindingRefusesTheLoser(): void {
    $request = $this->anonymousUploadRequest('upload-race');
    $media = $this->createUpload($request);
    $tenant_a = (int) $this->tenantA->id();

    // Request B resolved "unbound" and is about to claim, when request A's
    // claim lands first.
    $racing = new class(
      $this->container->get('entity_type.manager'),
      $this->container->get('keyvalue'),
      $this->container->get('config.factory'),
    ) extends MediaTenantScopeResolver {

      /**
       * The jurisdiction the competing request binds first.
       */
      public int $competitor = 0;

      /**
       * {@inheritdoc}
       */
      protected function claim(array $media_entities, int $jurisdiction_id): bool {
        foreach ($media_entities as $media) {
          $this->keyValue->get(self::STORE)->setIfNotExists($media->uuid(), $this->competitor);
        }
        return parent::claim($media_entities, $jurisdiction_id);
      }

    };
    $racing->competitor = $tenant_a;

    $this->assertStatus(409, $this->analyze($request, [$media], $this->tenantB->id(), TRUE, $racing));
    $this->assertSame([], $this->providerCalls);
    $this->assertSame([], $this->budgetChecks, 'The loser is refused before its budget is consulted.');
    $this->assertSame([], $this->charges);
    $this->assertSame($tenant_a, $this->binding($media));
  }

  /**
   * A disabled tenant cannot escape its policy on a non-Fastmap install.
   */
  public function testDisabledTenantCannotBypassByOmittingOrChangingTheId(): void {
    $this->disabledTenants = [(int) $this->tenantA->id()];
    $request = $this->anonymousUploadRequest('upload-disabled');
    $media = $this->createUpload($request, NULL, (string) $this->tenantA->id());

    $this->assertStatus(403, $this->analyze($request, [$media], NULL));
    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantB->id()));

    // A fresh unbound upload on a multi-tenant install must name its tenant.
    $fresh = $this->createUpload($request);
    $this->assertStatus(400, $this->analyze($request, [$fresh], NULL));
    $this->assertSame([], $this->providerCalls);
  }

  /**
   * One jurisdiction in total is the validated single-tenant fallback.
   */
  public function testSingleTenantInstallBindsToItsJurisdiction(): void {
    $this->tenantB->delete();
    $request = $this->anonymousUploadRequest('upload-single');
    $media = $this->createUpload($request);
    $this->assertSame((int) $this->tenantA->id(), $this->binding($media), 'Single-tenant uploads are bound without a header.');

    $this->assertStatus(200, $this->analyze($request, [$media], NULL));
    $this->assertSame([(int) $this->tenantA->id()], $this->providerCalls);

    $this->disabledTenants = [(int) $this->tenantA->id()];
    $this->assertStatus(403, $this->analyze($request, [$this->createUpload($request)], NULL), 'The fallback applies the tenant policy.');
  }

  /**
   * Unpublished tenants still count as tenants: never unscoped.
   */
  public function testUnpublishedTenantsAreNotTreatedAsNoTenants(): void {
    $this->tenantB->delete();
    $this->tenantA->setUnpublished()->save();
    $request = $this->anonymousUploadRequest('upload-unpublished');
    $media = $this->createUpload($request);
    $this->assertNull($this->binding($media));

    $this->assertStatus(400, $this->analyze($request, [$media], NULL));
    $this->assertStatus(400, $this->analyze($request, [$media], $this->tenantA->id()));
    $this->assertSame([], $this->providerCalls, 'The feature check cannot be skipped by unpublishing.');
  }

  /**
   * Installs without any jurisdiction keep working unscoped.
   */
  public function testInstallWithoutJurisdictionsStaysUnscoped(): void {
    $this->tenantA->delete();
    $this->tenantB->delete();
    $request = $this->anonymousUploadRequest('upload-plain');
    $media = $this->createUpload($request);

    $this->assertStatus(200, $this->analyze($request, [$media], NULL));
    $this->assertSame([NULL], $this->providerCalls);
    $this->assertStatus(400, $this->analyze($request, [$media], NULL, TRUE), 'Fastmap always needs a tenant.');
  }

  /**
   * Fastmap checks and charges the budget of the media tenant only.
   */
  public function testFastmapChargesTheMediaTenantOnly(): void {
    $request = $this->anonymousUploadRequest('upload-fastmap');
    $media = $this->createUpload($request);
    $this->createReport([$this->tenantA], [$media]);

    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantB->id(), TRUE));
    $this->assertSame([], $this->budgetChecks, 'A foreign tenant is refused before its budget is consulted.');
    $this->assertSame([], $this->charges);

    $this->assertStatus(200, $this->analyze($request, [$media], $this->tenantA->id(), TRUE));
    $this->assertSame([[(int) $this->tenantA->id(), 'pro']], $this->budgetChecks);
    $this->assertSame([(int) $this->tenantA->id()], $this->charges);
  }

  /**
   * Fastmap keeps an upload-bound tenant for policy and budget.
   */
  public function testFastmapUploadBindingDecidesTheBudget(): void {
    $request = $this->anonymousUploadRequest('upload-fastmap-bound');
    $media = $this->createUpload($request, NULL, (string) $this->tenantB->id());

    $this->assertStatus(403, $this->analyze($request, [$media], $this->tenantA->id(), TRUE));
    $this->assertStatus(200, $this->analyze($request, [$media], NULL, TRUE));
    $this->assertSame([[(int) $this->tenantB->id(), 'pro']], $this->budgetChecks);
    $this->assertSame([(int) $this->tenantB->id()], $this->charges);

    $unbound = $this->createUpload($request);
    $this->assertStatus(400, $this->analyze($request, [$unbound], NULL, TRUE));
  }

  /**
   * Unknown jurisdictions and foreign upload sessions are refused.
   */
  public function testInvalidJurisdictionAndForeignSessionAreRefused(): void {
    $request = $this->anonymousUploadRequest('upload-own');
    $media = $this->createUpload($request);
    $this->assertStatus(400, $this->analyze($request, [$media], 999999));

    $this->tenantB->setUnpublished()->save();
    $this->assertStatus(400, $this->analyze($request, [$media], $this->tenantB->id()));

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
   * @param \Drupal\markaspot_vision\Service\MediaTenantScopeResolver|null $resolver
   *   A resolver replacing the registered one.
   */
  private function analyze(Request $context, array $media, int|string|null $jurisdiction_id, bool $fastmap = FALSE, ?MediaTenantScopeResolver $resolver = NULL): JsonResponse {
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
      $resolver ?? $this->resolver(),
      $tier,
    );
    $controller->setStringTranslation($this->container->get('string_translation'));
    return $controller->getAIResults($request);
  }

  /**
   * Returns the registered resolver.
   */
  private function resolver(): MediaTenantScopeResolver {
    return $this->container->get('markaspot_vision_test.scope_resolver');
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
   * Returns the stored tenant binding of a media item.
   */
  private function binding(MediaInterface $media): ?int {
    $bound = $this->container->get('keyvalue')->get(MediaTenantScopeResolver::STORE)->get($media->uuid());
    return $bound === NULL ? NULL : (int) $bound;
  }

  /**
   * Creates an upload the way the media insert hook records it.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The upload request (upload token or session).
   * @param \Drupal\Core\Session\AccountInterface|null $owner
   *   The uploading account; anonymous when NULL.
   * @param string|null $jurisdiction
   *   The jurisdiction the upload request names in the upload header.
   */
  private function createUpload(Request $request, ?AccountInterface $owner = NULL, ?string $jurisdiction = NULL): MediaInterface {
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
    $upload = clone $request;
    $upload->headers = clone $request->headers;
    if ($jurisdiction !== NULL) {
      $upload->headers->set(MediaTenantScopeResolver::UPLOAD_HEADER, $jurisdiction);
    }
    $this->guard()->recordUpload($media, $upload);
    $this->resolver()->bindUpload($media, $upload);
    return $media;
  }

  /**
   * Creates a report of the given tenants referencing the media.
   *
   * @param \Drupal\group\Entity\GroupInterface[] $tenants
   *   The tenants named in field_jurisdiction.
   * @param \Drupal\media\MediaInterface[] $media
   *   The referenced media.
   */
  private function createReport(array $tenants, array $media): NodeInterface {
    $report = Node::create([
      'type' => 'service_request',
      'title' => 'Report',
      'uid' => 1,
      'field_jurisdiction' => array_map(static fn (GroupInterface $tenant): array => ['target_id' => $tenant->id()], $tenants),
      'field_request_media' => array_map(static fn (MediaInterface $item): array => ['target_id' => $item->id()], $media),
    ]);
    $report->save();
    return $report;
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
  private function addField(string $entity_type, string $bundle, string $name, string $type, array $settings = [], int $cardinality = 1): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'type' => $type,
      'settings' => $settings,
      'cardinality' => $cardinality,
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
