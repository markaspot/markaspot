<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupType;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
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
    'serialization', 'jsonapi',
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
   * Simulates an editorial publication while the model request is in flight.
   */
  private ?\Closure $duringAnalysis = NULL;

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
    // Exercise actual metadata and publication saves, rather than swallowing
    // missing-field exceptions in the controller's per-media error handler.
    $vision_config = new FileStorage(dirname(__DIR__, 3) . '/config/install');
    foreach ($vision_config->listAll('field.storage.media.field_ai_') as $name) {
      $definition = $vision_config->read($name);
      FieldStorageConfig::create($definition)->save();
      FieldConfig::create($vision_config->read('field.field.media.request_image.' . $definition['field_name']))->save();
    }
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
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // The module itself is not installed (it pulls in the whole Nuxt layer);
    // register the real resolver the way its services.yml does.
    $container->register('markaspot_vision_test.scope_resolver', MediaTenantScopeResolver::class)
      ->addArgument(new Reference('entity_type.manager'))
      ->addArgument(new Reference('keyvalue'))
      ->addArgument(new Reference('config.factory'))
      ->addArgument(new Reference('state'));
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
   * A headerless upload made after the cutoff is refused on multi-tenant.
   */
  public function testHeaderlessUploadAfterCutoffIsRefused(): void {
    $request = $this->anonymousUploadRequest('upload-after-cutoff');
    $this->recordCutoff();
    $unbound = $this->createUpload($request);

    $this->assertStatus(400, $this->analyze($request, [$unbound], $this->tenantA->id()));
    $this->assertStatus(400, $this->analyze($request, [$unbound], $this->tenantA->id(), TRUE));
    $this->assertNull($this->binding($unbound), 'A refused request binds nothing.');
    $this->assertSame([], $this->providerCalls);
    $this->assertSame([], $this->budgetChecks, 'Refused before the budget is consulted.');

    $bound = $this->createUpload($request, NULL, (string) $this->tenantA->id());
    $this->assertStatus(200, $this->analyze($request, [$bound], NULL));
    $this->assertSame([(int) $this->tenantA->id()], $this->providerCalls);
  }

  /**
   * The update records the cutoff once and never moves it.
   */
  public function testUpdateRecordsTheCutoffOnce(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_vision.install';
    $state = $this->container->get('state');
    $this->assertNull($state->get(MediaTenantScopeResolver::CUTOFF_STATE));

    $request = $this->anonymousUploadRequest('upload-update');
    $this->createUpload($request);
    $last = $this->createUpload($request);
    markaspot_vision_update_11808();
    $this->assertSame((int) $last->id(), $state->get(MediaTenantScopeResolver::CUTOFF_STATE));

    $state->set(MediaTenantScopeResolver::CUTOFF_STATE, 1000);
    $this->assertStringStartsWith('Skipped', markaspot_vision_update_11808());
    $this->assertSame(1000, $state->get(MediaTenantScopeResolver::CUTOFF_STATE));
  }

  /**
   * Without a recorded cutoff there is no legacy cohort at all.
   */
  public function testMissingCutoffMeansNoLegacyCohort(): void {
    $request = $this->anonymousUploadRequest('upload-no-cutoff');
    $unbound = $this->createUpload($request);
    $this->assertStatus(400, $this->analyze($request, [$unbound], $this->tenantA->id()));
    $this->assertSame([], $this->providerCalls);
  }

  /**
   * A legacy upload from before the cutoff is bound once by its analysis.
   */
  public function testLegacyUploadBeforeCutoffIsClaimedOnce(): void {
    $request = $this->anonymousUploadRequest('upload-legacy');
    $media = $this->createUpload($request, NULL, NULL, [], TRUE);
    $this->recordCutoff();
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
   * One unresolved report among several blocks the media.
   */
  public function testOneUnresolvedParentAmongSeveralIsRefused(): void {
    $request = $this->anonymousUploadRequest('upload-two-parents');
    $media = $this->createUpload($request, NULL, (string) $this->tenantB->id());
    $this->createReport([$this->tenantB], [$media]);
    $this->createReport([], [$media]);

    $this->assertStatus(409, $this->analyze($request, [$media], $this->tenantB->id()));
    $this->assertStatus(409, $this->analyze($request, [$media], NULL));
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
   * A backdated `created` date does not make a new upload legacy.
   */
  public function testBackdatedUploadAfterCutoffIsNotLegacy(): void {
    $request = $this->anonymousUploadRequest('upload-backdated');
    $genuine = $this->createUpload($request, NULL, NULL, [], TRUE);
    $this->recordCutoff();

    // JSON:API accepts attributes.created on create, also anonymously.
    $backdated = $this->createUpload($request, NULL, NULL, ['created' => 1]);
    $this->assertSame(1, (int) $backdated->getCreatedTime());
    $this->assertStatus(400, $this->analyze($request, [$backdated], $this->tenantA->id()));

    $bound = $this->createUpload($request, NULL, (string) $this->tenantB->id());
    $this->assertStatus(400, $this->analyze($request, [$bound, $backdated], $this->tenantB->id()));
    $this->assertNull($this->binding($backdated), 'Neither request bound the backdated upload.');
    $this->assertSame([], $this->providerCalls);

    // The genuine pre-cutoff upload is still claimable, once.
    $this->assertStatus(200, $this->analyze($request, [$genuine], $this->tenantA->id()));
    $this->assertStatus(403, $this->analyze($request, [$genuine], $this->tenantB->id()));
    $this->assertSame([(int) $this->tenantA->id()], $this->providerCalls);
  }

  /**
   * A new upload filling an ID gap below the cutoff is never legacy.
   *
   * JSON:API create accepts the raw internal `mid` attribute (it is not a
   * public field name, so EntityResource skips its field access check, and
   * the denormalizer maps it to the entity's ID). This drives the real
   * JSON:API denormalizer; the insert marker, not the ID, decides.
   */
  public function testGapFillingUploadBelowCutoffIsNotLegacy(): void {
    $request = $this->anonymousUploadRequest('upload-gap');
    $first = $this->createUpload($request, NULL, NULL, [], TRUE);
    $gone = $this->createUpload($request, NULL, NULL, [], TRUE);
    $last = $this->createUpload($request, NULL, NULL, [], TRUE);
    $gap = (int) $gone->id();
    $gone->delete();
    $this->recordCutoff();
    $this->assertSame((int) $last->id(), (int) $this->container->get('state')->get(MediaTenantScopeResolver::CUTOFF_STATE));

    $resource_type = $this->container->get('jsonapi.resource_type.repository')->get('media', 'request_image');
    $media = $this->container->get('jsonapi.serializer')->denormalize([
      'data' => [
        'type' => 'media--request_image',
        'attributes' => ['name' => 'gap', 'status' => FALSE, 'mid' => $gap],
      ],
    ], JsonApiDocumentTopLevel::class, 'api_json', ['resource_type' => $resource_type]);
    $file = File::create(['uri' => $this->getTestFiles('image')[0]->uri, 'status' => 1]);
    $file->save();
    $media->set('field_media_image', ['target_id' => $file->id(), 'alt' => 'gap']);
    $media->save();
    $this->assertSame($gap, (int) $media->id(), 'The raw mid attribute reached storage (core behaviour).');
    $this->guard()->recordUpload($media, $request);
    $this->resolver()->bindUpload($media, $request);
    $this->assertSame(MediaTenantScopeResolver::UNBOUND_MARKER, $this->storedValue($media));

    $this->assertStatus(400, $this->analyze($request, [$media], $this->tenantA->id()));
    $this->assertStatus(400, $this->analyze($request, [$media], $this->tenantA->id(), TRUE));
    $this->assertNull($this->binding($media));

    // The same through the entity API with an explicit ID.
    $first_id = (int) $first->id();
    $first->delete();
    $explicit = $this->createUpload($request, NULL, NULL, ['mid' => $first_id]);
    $this->assertSame($first_id, (int) $explicit->id());
    $this->assertStatus(400, $this->analyze($request, [$explicit], $this->tenantB->id(), TRUE));

    $this->assertSame([], $this->providerCalls);
    $this->assertSame([], $this->budgetChecks);
    $this->assertSame([], $this->charges);

    // The marker goes with the media.
    $this->resolver()->forget($media);
    $this->assertNull($this->storedValue($media));
  }

  /**
   * Only a single-tenant install may turn the marker into a binding.
   */
  public function testMarkerTurnsIntoBindingOnlyOnSingleTenant(): void {
    $request = $this->anonymousUploadRequest('upload-marker');
    $media = $this->createUpload($request);
    $this->assertSame(MediaTenantScopeResolver::UNBOUND_MARKER, $this->storedValue($media));
    $this->assertStatus(400, $this->analyze($request, [$media], $this->tenantA->id()));

    $this->tenantB->delete();
    $this->assertStatus(200, $this->analyze($request, [$media], NULL));
    $this->assertSame((int) $this->tenantA->id(), $this->binding($media));
    $this->assertSame([(int) $this->tenantA->id()], $this->providerCalls);
  }

  /**
   * A bound image cannot carry a new headerless image into its tenant.
   */
  public function testMixedBoundAndUnboundBatchIsRefused(): void {
    $request = $this->anonymousUploadRequest('upload-bound-unbound');
    $this->recordCutoff();
    $bound = $this->createUpload($request, NULL, (string) $this->tenantB->id());
    $unbound = $this->createUpload($request);

    $this->assertStatus(400, $this->analyze($request, [$bound, $unbound], $this->tenantB->id()));
    $this->assertStatus(400, $this->analyze($request, [$bound, $unbound], NULL));
    $this->assertNull($this->binding($unbound), 'The headerless image was not adopted by the batch.');
    $this->assertSame([], $this->providerCalls);
  }

  /**
   * Two first analyses racing for one unbound upload: the loser is refused.
   */
  public function testConcurrentFirstBindingRefusesTheLoser(): void {
    $request = $this->anonymousUploadRequest('upload-race');
    $media = $this->createUpload($request, NULL, NULL, [], TRUE);
    $this->recordCutoff();
    $tenant_a = (int) $this->tenantA->id();

    // Request B resolved "unbound" and is about to claim, when request A's
    // claim lands first.
    $racing = new class(
      $this->container->get('entity_type.manager'),
      $this->container->get('keyvalue'),
      $this->container->get('config.factory'),
      $this->container->get('state'),
    ) extends MediaTenantScopeResolver {

      /**
       * The jurisdiction the competing request binds first.
       */
      public int $competitor = 0;

      /**
       * {@inheritdoc}
       */
      protected function claim(array $media_entities, int $jurisdiction_id, bool $replace_marker = FALSE): bool {
        foreach ($media_entities as $media) {
          $this->keyValue->get(self::STORE)->setIfNotExists($media->uuid(), $this->competitor);
        }
        return parent::claim($media_entities, $jurisdiction_id, $replace_marker);
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
   * A queue budget reservation blocks the controller before any provider call.
   */
  public function testQueueAndControllerShareTheBudgetLock(): void {
    $request = $this->anonymousUploadRequest('upload-budget-lock');
    $media = $this->createUpload($request, NULL, (string) $this->tenantA->id());
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(2))->method('acquire')
      ->with('markaspot_media_intake:budget:' . $this->tenantA->id(), 30)->willReturn(FALSE);
    $this->assertStatus(429, $this->analyze($request, [$media], $this->tenantA->id(), TRUE, NULL, $lock));
    $this->assertSame([], $this->providerCalls);
    $this->assertSame([], $this->charges);
  }

  /**
   * An active queue job excludes interactive blur and analysis of its image.
   */
  public function testIntakeLockPreventsConcurrentControllerAnalysis(): void {
    $request = $this->anonymousUploadRequest('upload-intake-lock');
    $media = $this->createUpload($request, NULL, (string) $this->tenantA->id());
    $this->container->get('keyvalue')->get('markaspot_vision.media_intake')->set($media->id(), ['status' => 'pending']);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')
      ->with('markaspot_media_intake:' . $media->id(), 900)->willReturn(FALSE);
    $this->assertStatus(409, $this->analyze($request, [$media], $this->tenantA->id(), FALSE, NULL, $lock));
    $this->assertSame([], $this->providerCalls);
    $this->assertSame([], $this->charges);
  }

  /**
   * A tenant's other images can reserve budget while the provider is running.
   */
  public function testBudgetLockReleasedBeforeProviderCall(): void {
    $request = $this->anonymousUploadRequest('upload-budget-release');
    $media = $this->createUpload($request, NULL, (string) $this->tenantA->id());
    $released = FALSE;
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $lock->method('release')->willReturnCallback(function (string $name) use (&$released): void {
      if ($name === 'markaspot_media_intake:budget:' . $this->tenantA->id()) {
        $released = TRUE;
      }
    });
    $this->duringAnalysis = function () use (&$released): void {
      $this->assertTrue($released, 'Only budget check and reservation hold the tenant lock.');
      $this->assertSame([(int) $this->tenantA->id()], $this->charges);
    };
    $this->assertStatus(200, $this->analyze($request, [$media], $this->tenantA->id(), TRUE, NULL, $lock));
  }

  /**
   * A manual publication during analysis survives the controller's save.
   */
  public function testManualDecisionDuringControllerAnalysisIsPreserved(): void {
    foreach ([TRUE, FALSE] as $published) {
      $request = $this->anonymousUploadRequest('upload-editorial-' . (int) $published);
      $media = $this->createUpload($request, NULL, (string) $this->tenantA->id());
      $this->duringAnalysis = function () use ($media, $published): void {
        $fresh = $this->container->get('entity_type.manager')->getStorage('media')->loadUnchanged($media->id());
        ($published ? $fresh->setPublished() : $fresh->setUnpublished())->save();
        $this->container->get('keyvalue')->get('markaspot_open311.media_publication_manual')->set($media->id(), TRUE);
      };
      $this->assertStatus(200, $this->analyze($request, [$media], $this->tenantA->id()));
      $this->assertSame($published, Media::load($media->id())->isPublished());
    }
  }

  /**
   * Intake records are not editorial overrides and respect report status.
   */
  public function testImportedMediaPublicationRequiresPublishedReport(): void {
    foreach ([TRUE, FALSE] as $published) {
      $request = $this->anonymousUploadRequest('upload-imported-' . (int) $published);
      $media = $this->createUpload($request, NULL, (string) $this->tenantA->id());
      $report = $this->createReport([$this->tenantA], [$media]);
      ($published ? $report->setPublished() : $report->setUnpublished())->save();
      $this->container->get('keyvalue')->get('markaspot_vision.media_intake')->set($media->id(), ['status' => 'pending']);
      $this->assertStatus(200, $this->analyze($request, [$media], $this->tenantA->id()));
      $fresh = $this->container->get('entity_type.manager')->getStorage('media')->loadUnchanged($media->id());
      $this->assertSame($published, $fresh->isPublished());
      $marker = json_decode($fresh->get('field_ai_metadata')->value, TRUE)['intake_screening'];
      $this->assertFalse($marker['privacy_screened']);
      $this->assertFalse($marker['processed']);
    }
  }

  /**
   * Same-file byte replacement during analysis cannot be marked or published.
   */
  public function testSourceBytesChangedDuringAnalysisFailClosed(): void {
    $request = $this->anonymousUploadRequest('upload-replaced-bytes');
    $media = $this->createUpload($request, NULL, (string) $this->tenantA->id());
    $this->duringAnalysis = static function () use ($media): void {
      file_put_contents($media->get('field_media_image')->entity->getFileUri(), 'Fictional replaced bytes');
    };
    $this->assertStatus(500, $this->analyze($request, [$media], $this->tenantA->id(), TRUE));
    $this->assertFalse(Media::load($media->id())->isPublished());
    $this->assertSame([(int) $this->tenantA->id()], $this->charges, 'The interrupted model call remains accounted for.');
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
   * @param \Drupal\Core\Lock\LockBackendInterface|null $operationLock
   *   Optional lock boundary for contention tests.
   */
  private function analyze(Request $context, array $media, int|string|null $jurisdiction_id, bool $fastmap = FALSE, ?MediaTenantScopeResolver $resolver = NULL, ?LockBackendInterface $operationLock = NULL): JsonResponse {
    $body = ['media_ids' => array_map(static fn (MediaInterface $item): string => $item->uuid(), $media)];
    if ($jurisdiction_id !== NULL) {
      $body['jurisdiction_id'] = $jurisdiction_id;
    }
    $request = Request::create('/api/vision/analyze', 'POST', [], [], [], [], json_encode($body));
    $request->headers->replace($context->headers->all());
    if ($context->hasSession()) {
      $request->setSession($context->getSession());
    }

    $methods = array_diff(get_class_methods(ImageProcessingService::class), ['__construct', 'processScreenedImages']);
    $provider = $this->getMockBuilder(ImageProcessingService::class)
      ->disableOriginalConstructor()->onlyMethods($methods)->getMock();
    $provider->method('processImages')->willReturnCallback(function (array $uris, ?string $langcode, ?int $jurisdiction): array {
      $this->providerCalls[] = $jurisdiction;
      if ($this->duringAnalysis) {
        ($this->duringAnalysis)();
      }
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
      $this->container->get('keyvalue'),
      $tier,
      NULL,
      $operationLock ?? $this->container->get('lock'),
    );
    $controller->setStringTranslation($this->container->get('string_translation'));
    return $controller->getAIResults($request);
  }

  /**
   * Records the cutoff at the highest existing media ID, as the update does.
   */
  private function recordCutoff(): void {
    $ids = $this->container->get('entity_type.manager')->getStorage('media')->getQuery()
      ->accessCheck(FALSE)
      ->sort('mid', 'DESC')
      ->range(0, 1)
      ->execute();
    $this->container->get('state')->set(MediaTenantScopeResolver::CUTOFF_STATE, $ids ? (int) reset($ids) : 0);
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
    $bound = $this->storedValue($media);
    return is_int($bound) || (is_string($bound) && ctype_digit($bound)) ? (int) $bound : NULL;
  }

  /**
   * Returns the raw binding-store value (binding or insert marker).
   */
  private function storedValue(MediaInterface $media): mixed {
    return $this->container->get('keyvalue')->get(MediaTenantScopeResolver::STORE)->get($media->uuid());
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
   * @param array $values
   *   Extra entity values, e.g. a client-supplied `created` date.
   * @param bool $legacy
   *   TRUE for media from before this code: no insert hook marked them.
   */
  private function createUpload(Request $request, ?AccountInterface $owner = NULL, ?string $jurisdiction = NULL, array $values = [], bool $legacy = FALSE): MediaInterface {
    $source = $this->getTestFiles('image')[0];
    $file = File::create(['uri' => $source->uri, 'status' => 1]);
    $file->save();
    $media = Media::create($values + [
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
    if (!$legacy) {
      // What markaspot_vision_media_insert() does for every new upload.
      $this->resolver()->bindUpload($media, $upload);
    }
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
