<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_mail_inbound\Controller\InboundMailApiController;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Service\InboundMailPromoter;
use Drupal\markaspot_mail_inbound\Service\InboundMailReplyService;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel test for the inbound-mail dashboard REST controller (#482).
 *
 * Proves the frontend contract:
 * - list: items shape, jurisdiction QUERY scoping (member vs global), state
 *   filter, pagination total, the fidelity block,
 * - detail: full payload incl. 403 for an outsider,
 * - promote: success payload (nid/request_id/uuid/ungeolocated plus the
 *   location/description sources), 409 on a non-staged mail or a held
 *   promotion lock, 422 on a non-promotable category, a malformed location
 *   or a confirmed location outside the jurisdiction (child, then root
 *   boundary), 503 when the Open311 processor is unavailable,
 * - discard: success + 409,
 * - reply: success (sent=true via the test mail collector), 422 on empty
 *   body, 409 on a promoted mail.
 *
 * Routing-level guards (permission, CSRF header, cookie auth) are declared
 * in markaspot_mail_inbound.routing.yml and exercised in the live smoke;
 * here the controller logic and the per-entity access checks are proven.
 *
 * @group markaspot_mail_inbound
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class InboundMailApiControllerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'options',
    'taxonomy',
    'field_permissions',
    'file',
    'entity',
    'flexible_permissions',
    'group',
    // The module ships a jsonapi_extras.jsonapi_resource_config in
    // config/install (the disabled inbound_mail JSON:API resource), so
    // installConfig(['markaspot_mail_inbound']) needs the stack present.
    'serialization',
    'jsonapi',
    'jsonapi_extras',
    'markaspot_mail_inbound',
    // Provides the boundary validator the promoter checks locations with.
    'markaspot_validation',
  ];

  /**
   * The first jurisdiction group id.
   */
  protected int $gidA;

  /**
   * The second jurisdiction group id.
   */
  protected int $gidB;

  /**
   * The promotable (coded) category term id.
   */
  protected int $categoryTid;

  /**
   * A codeless category term id (promotable via direct field_category set).
   */
  protected int $codelessTid;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Public: a private unreferenced definition would be compiled away and
    // the consumers' $container->has() check would fall back silently.
    $container->register('markaspot_group.jurisdiction_scope_validator', StubJurisdictionScopeValidator::class)
      ->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installEntitySchema('inbound_mail');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node', 'user', 'group']);

    StubJurisdictionScopeValidator::$map = [];

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    // Consume uid 1: core's super-user policy grants it everything, which
    // would defeat the scoping and 403 assertions below.
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    \Drupal::entityTypeManager()->getStorage('node_type')->create([
      'type' => 'service_request',
      'name' => 'Service Request',
    ])->save();
    $this->createNodeField('body', 'text_with_summary');
    $this->createNodeField('field_e_mail', 'email');
    $this->createNodeField('field_category', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createNodeField('field_gdpr', 'boolean');
    // Stands in for the geolocation field; the processor double seeds a
    // default coordinate like the production processor does.
    $this->createNodeField('field_geolocation', 'string');
    // Stands in for the address field; the processor double copies the
    // address_string request key into it.
    $this->createNodeField('field_address', 'string');
    // field_source / field_email_message_id ship as module config.
    $this->installConfig(['markaspot_mail_inbound']);

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    // GeoJSON boundary, read by markaspot_validation's BoundaryValidator.
    $this->createField('group', 'jur', 'field_boundary', 'string_long', [], 1);
    $a = Group::create(['type' => 'jur', 'label' => 'City A']);
    $a->save();
    $this->gidA = (int) $a->id();
    $b = Group::create(['type' => 'jur', 'label' => 'City B']);
    $b->save();
    $this->gidB = (int) $b->id();

    Vocabulary::create(['vid' => 'service_category', 'name' => 'Service Category'])->save();
    $this->createField('taxonomy_term', 'service_category', 'field_service_code', 'string', [], 1);
    $coded = Term::create(['vid' => 'service_category', 'name' => 'Streetlight', 'field_service_code' => '1.3']);
    $coded->save();
    $this->categoryTid = (int) $coded->id();
    $codeless = Term::create(['vid' => 'service_category', 'name' => 'Uncoded']);
    $codeless->save();
    $this->codelessTid = (int) $codeless->id();

    Role::create(['id' => 'triage', 'label' => 'Triage'])
      ->grantPermission('triage inbound mail')
      ->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
  }

  /**
   * Generic field creation helper.
   */
  protected function createField(string $entityType, string $bundle, string $name, string $type, array $settings, int $cardinality): void {
    if (!FieldStorageConfig::loadByName($entityType, $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'type' => $type,
        'cardinality' => $cardinality,
        'settings' => $settings,
      ])->save();
    }
    if (!FieldConfig::loadByName($entityType, $bundle, $name)) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'bundle' => $bundle,
        'label' => $name,
        'required' => FALSE,
      ])->save();
    }
  }

  /**
   * Creates a node field on service_request.
   */
  protected function createNodeField(string $name, string $type, array $settings = [], int $cardinality = 1): void {
    $this->createField('node', 'service_request', $name, $type, $settings, $cardinality);
  }

  /**
   * Creates a user, registers their scope and makes them current.
   *
   * @param string[] $roles
   *   Role ids.
   * @param int[] $jurisdictions
   *   Allowed jurisdiction gids for the stub validator.
   */
  protected function actAs(array $roles, array $jurisdictions = []): UserInterface {
    $user = User::create([
      'name' => 'user' . random_int(100000, 999999),
      'status' => 1,
      'roles' => $roles,
    ]);
    $user->save();
    StubJurisdictionScopeValidator::$map[(int) $user->id()] = $jurisdictions;
    $this->container->get('current_user')->setAccount($user);
    return $user;
  }

  /**
   * Creates a saved inbound mail.
   */
  protected function mail(int $gid, string $state = InboundMail::STATE_STAGED, string $subject = 'Broken light'): InboundMail {
    $values = [
      'from_address' => 'citizen@example.org',
      'from_name' => 'Citizen',
      'subject' => $subject,
      'body' => ['value' => 'A streetlight is broken near the church.', 'format' => 'plain_text'],
      'message_id' => 'orig-' . random_int(100000, 999999) . '@example.org',
      'state' => $state,
    ];
    $values['thread_message_ids'] = [$values['message_id']];
    if ($gid > 0) {
      $values['jurisdiction_id'] = ['target_id' => $gid];
    }
    /** @var \Drupal\markaspot_mail_inbound\Entity\InboundMail $mail */
    $mail = $this->container->get('entity_type.manager')->getStorage('inbound_mail')->create($values);
    $mail->save();
    return $mail;
  }

  /**
   * Builds the controller from the container (processor-less promoter).
   */
  protected function controller(): InboundMailApiController {
    return InboundMailApiController::create($this->container);
  }

  /**
   * Builds a controller whose promoter is wired to a processor double.
   *
   * @param object|null $geocoder
   *   A geocoder double, or NULL for none.
   * @param object|null $hierarchyResolver
   *   A hierarchy resolver double (markaspot_group is not installed here).
   */
  protected function controllerWithWorkingPromoter(?object $geocoder = NULL, ?object $hierarchyResolver = NULL): InboundMailApiController {
    $statusTerm = Term::create(['vid' => 'service_category', 'name' => 'Open']);
    $statusTerm->save();
    $entityTypeManager = $this->container->get('entity_type.manager');

    $processor = new class($entityTypeManager) {

      public function __construct(protected $entityTypeManager) {}

      /**
       * Mirrors the real processor's create-path field mapping.
       */
      public function prepareNodeProperties(array $requestData, string $operation): array {
        $term = NULL;
        if (isset($requestData['service_code'])) {
          $terms = $this->entityTypeManager->getStorage('taxonomy_term')
            ->loadByProperties([
              'vid' => 'service_category',
              'field_service_code' => $requestData['service_code'],
            ]);
          $term = $terms ? reset($terms) : NULL;
        }
        $values = [
          'type' => 'service_request',
          'langcode' => 'und',
          'title' => $requestData['service_code'] ?? 'service_request',
        ];
        if ($term) {
          $values['field_category'] = (int) $term->id();
        }
        if (isset($requestData['email'])) {
          $values['field_e_mail'] = ['value' => $requestData['email']];
        }
        if (isset($requestData['description'])) {
          $values['body'] = ['value' => $requestData['description'], 'format' => 'plain_text'];
        }
        if (isset($requestData['address_string'])) {
          $values['field_address'] = $requestData['address_string'];
        }
        // Mirror the production processor: when the request carries no
        // coordinates a jurisdiction DEFAULT is seeded. The promoter must
        // clear it again so an email report without a location stays
        // explicitly ungeolocated (#482 live-smoke finding).
        $values['field_geolocation'] = isset($requestData['lat'])
          ? $requestData['lat'] . ',' . $requestData['long']
          : '52.3730796,4.8924534';
        return $values;
      }

      /**
       * No status vocabulary in this test.
       */
      public function getInitialStatusTid(?int $jurisdictionId = NULL): ?int {
        return NULL;
      }

    };

    $promoter = new InboundMailPromoter(
      $entityTypeManager,
      $this->container->get('module_handler'),
      $this->container->get('event_dispatcher'),
      $this->container->get('logger.factory')->get('markaspot_mail_inbound'),
      $this->container->get('entity_field.manager'),
      $this->container->get('config.factory'),
      $this->container->get('file_system'),
      $this->container->get('token'),
      $this->container->get('markaspot_mail_inbound.internal_remark_writer'),
      $this->container->get('lock'),
      $processor,
      $geocoder,
      $this->container->get('language_manager'),
      $this->container->get('markaspot_validation.boundary_validator'),
      $hierarchyResolver,
    );

    return new InboundMailApiController(
      $entityTypeManager,
      $this->container->get('current_user'),
      $promoter,
      $this->container->get('markaspot_mail_inbound.reply'),
      $this->container->get('markaspot_mail_inbound.category_repository'),
      $this->container->get('markaspot_mail_inbound.fidelity'),
      $this->container->get('logger.factory')->get('markaspot_mail_inbound'),
      $this->container->get('markaspot_group.jurisdiction_scope_validator'),
    );
  }

  /**
   * Decodes a JSON response.
   */
  protected function json(JsonResponse $response): array {
    return (array) json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * The list scopes its QUERY to the member's jurisdictions + unscoped mail.
   */
  public function testListScopesQueryForMembers(): void {
    $mailA = $this->mail($this->gidA);
    $this->mail($this->gidB);
    $unscoped = $this->mail(0);

    $this->actAs(['triage'], [$this->gidA]);
    $response = $this->controller()->list(Request::create('/api/inbound-mail'));
    $data = $this->json($response);

    $this->assertSame(2, $data['total'], 'Member sees their jurisdiction + unscoped mail, never City B.');
    $ids = array_column($data['items'], 'id');
    $this->assertContains((int) $mailA->id(), $ids);
    $this->assertContains((int) $unscoped->id(), $ids);

    // Row shape (frontend contract #482).
    $row = $data['items'][array_search((int) $mailA->id(), $ids, TRUE)];
    $this->assertSame('Citizen', $row['from_name']);
    // Data minimization: the citizen's address is detail-only, never in a
    // list row (the list renders from_name only).
    $this->assertArrayNotHasKey('from_address', $row);
    $this->assertSame('Broken light', $row['subject']);
    $this->assertStringContainsString('streetlight is broken', $row['snippet']);
    $this->assertSame('staged', $row['state']);
    $this->assertSame($this->gidA, $row['jurisdiction_id']);
    $this->assertSame('City A', $row['jurisdiction_label']);
    $this->assertIsInt($row['created']);
    $this->assertIsInt($row['changed']);
    $this->assertSame(0, $row['attachment_count']);
    $this->assertNull($row['nid']);
    $this->assertNull($row['request_id']);

    // The fidelity block ships the four contract keys.
    $this->assertArrayHasKey('fidelity', $data);
    $this->assertFalse($data['fidelity']['imap_configured']);
    $this->assertTrue($data['fidelity']['outbound_available'], 'The kernel test mail collector counts as outbound.');
    $this->assertIsBool($data['fidelity']['private_fs']);
    // codeless_categories was removed: all jurisdiction-scoped categories are
    // promotable regardless of whether they carry a field_service_code.
    $this->assertArrayNotHasKey('codeless_categories', $data['fidelity']);
  }

  /**
   * A global admin sees everything; state filter and pagination apply.
   */
  public function testListGlobalAdminStateFilterAndPagination(): void {
    $this->mail($this->gidA);
    $this->mail($this->gidB);
    $this->mail(0, InboundMail::STATE_DISCARDED);

    $this->actAs(['administrator']);
    $controller = $this->controller();

    $all = $this->json($controller->list(Request::create('/api/inbound-mail')));
    $this->assertSame(3, $all['total']);
    // Staged-first default ordering.
    $this->assertSame('staged', $all['items'][0]['state']);
    $this->assertSame('discarded', $all['items'][2]['state']);

    $staged = $this->json($controller->list(Request::create('/api/inbound-mail', 'GET', ['state' => 'staged'])));
    $this->assertSame(2, $staged['total']);
    $this->assertCount(2, $staged['items']);

    $paged = $this->json($controller->list(Request::create('/api/inbound-mail', 'GET', ['page' => 1, 'limit' => 2])));
    $this->assertSame(3, $paged['total']);
    $this->assertCount(1, $paged['items']);
    $this->assertSame(1, $paged['page']);
    $this->assertSame(2, $paged['limit']);

    $invalid = $controller->list(Request::create('/api/inbound-mail', 'GET', ['state' => 'bogus']));
    $this->assertSame(422, $invalid->getStatusCode());
  }

  /**
   * Missing scope validator: non-global list falls back to unscoped mail only.
   *
   * Without the validator the collection query cannot prove membership, so it
   * fails closed to unscoped mail for non-global users. A global user keeps the
   * explicit bypass and sees the real total.
   */
  public function testListFailsClosedToUnscopedMailWithoutScopeValidator(): void {
    $this->mail($this->gidA);
    $this->mail($this->gidB);
    $unscoped = $this->mail(0);

    // A controller built WITHOUT the scope validator. The query itself should
    // restrict non-global users to unscoped mail, before any entity access
    // defense-in-depth filter runs.
    $controller = new InboundMailApiController(
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get('markaspot_mail_inbound.promoter'),
      $this->container->get('markaspot_mail_inbound.reply'),
      $this->container->get('markaspot_mail_inbound.category_repository'),
      $this->container->get('markaspot_mail_inbound.fidelity'),
      $this->container->get('logger.factory')->get('markaspot_mail_inbound'),
      NULL,
    );

    // Non-global triage user with no allowed jurisdictions.
    $this->actAs(['triage'], []);
    $data = $this->json($controller->list(Request::create('/api/inbound-mail')));
    $this->assertSame([(int) $unscoped->id()], array_column($data['items'], 'id'), 'Missing validator: non-global users only receive unscoped mail.');
    $this->assertSame(1, $data['total'], 'Missing validator: total is scoped to unscoped mail, never the full query count (3).');

    // The global bypass through the same validator-less controller keeps the
    // accurate full total.
    $this->actAs(['administrator']);
    $all = $this->json($controller->list(Request::create('/api/inbound-mail')));
    $this->assertSame(3, $all['total']);
  }

  /**
   * Detail returns the full payload for a member, 403 for an outsider.
   */
  public function testDetailPayloadAndOutsiderDenied(): void {
    $mail = $this->mail($this->gidA);

    $this->actAs(['triage'], [$this->gidA]);
    $data = $this->json($this->controller()->detail($mail));
    $this->assertSame((int) $mail->id(), $data['id']);
    // The address IS part of detail (a triage user inspecting one mail),
    // while the list omits it (data minimization).
    $this->assertSame('citizen@example.org', $data['from_address']);
    $this->assertSame('A streetlight is broken near the church.', $data['body']);
    $this->assertSame('City A', $data['jurisdiction_label']);
    $this->assertSame([], $data['attachments']);
    $this->assertNull($data['nid']);

    $this->actAs(['triage'], [$this->gidB]);
    $response = $this->controller()->detail($mail);
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * By-node returns the promoted mail payload, with access re-checked.
   */
  public function testByNodePayloadAndAccess(): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Promoted email report',
    ]);
    $node->save();

    $staged = $this->mail($this->gidA, InboundMail::STATE_STAGED, 'Staged stale');
    $staged->set('nid', ['target_id' => (int) $node->id()]);
    $staged->set('changed', 300);
    $staged->save();

    $oldPromoted = $this->mail($this->gidA, InboundMail::STATE_PROMOTED, 'Old promoted');
    $oldPromoted->set('nid', ['target_id' => (int) $node->id()]);
    $oldPromoted->set('changed', 100);
    $oldPromoted->save();

    $mail = $this->mail($this->gidA, InboundMail::STATE_PROMOTED);
    $mail->set('body', [
      'value' => "Original citizen text.\n\n---\nStaff reply:\nWe need more detail.",
      'format' => 'plain_text',
    ]);
    $mail->set('thread_message_ids', ['orig@example.org', 'reply@example.org']);
    $mail->set('nid', ['target_id' => (int) $node->id()]);
    $mail->set('changed', 200);
    $mail->save();

    $this->actAs(['triage'], [$this->gidA]);
    $data = $this->json($this->controller()->byNode($node));
    $this->assertSame((int) $mail->id(), $data['id']);
    $this->assertSame((int) $node->id(), $data['nid']);
    $this->assertSame('Citizen', $data['from_name']);
    $this->assertSame('citizen@example.org', $data['from_address']);
    $this->assertSame('Broken light', $data['subject']);
    $this->assertSame((string) $mail->get('message_id')->value, $data['message_id']);
    $this->assertSame(['orig@example.org', 'reply@example.org'], $data['thread_message_ids']);
    $this->assertIsInt($data['created']);
    $this->assertStringContainsString('Original citizen text.', $data['body']);
    $this->assertStringContainsString('Staff reply:', $data['body']);

    $this->actAs(['triage'], [$this->gidB]);
    $denied = $this->controller()->byNode($node);
    $this->assertSame(403, $denied->getStatusCode());

    $this->actAs(['triage'], [$this->gidA]);
    $otherNode = Node::create([
      'type' => 'service_request',
      'title' => 'Web report',
    ]);
    $otherNode->save();
    $missing = $this->controller()->byNode($otherNode);
    $this->assertSame(404, $missing->getStatusCode());

    \Drupal::entityTypeManager()->getStorage('node_type')->create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
    $pageNode = Node::create([
      'type' => 'page',
      'title' => 'Not a service request',
    ]);
    $pageNode->save();
    $wrongBundle = $this->controller()->byNode($pageNode);
    $this->assertSame(404, $wrongBundle->getStatusCode());
  }

  /**
   * Promote: success payload, 409 / 422 / 503 error contract.
   *
   * A codeless category is now promotable (the promoter sets field_category
   * directly). Unknown/foreign-jurisdiction category tids are rejected (422).
   */
  public function testPromoteContract(): void {
    $this->actAs(['triage'], [$this->gidA]);
    $mail = $this->mail($this->gidA);

    // 422: missing category_tid.
    $missing = $this->controllerWithWorkingPromoter()->promote($mail, $this->postRequest([]));
    $this->assertSame(422, $missing->getStatusCode());
    $this->assertSame('category_required', $this->json($missing)['code']);

    // 422: a completely unknown tid is not valid for any jurisdiction.
    $unknown = $this->controllerWithWorkingPromoter()->promote($mail, $this->postRequest(['category_tid' => 99999]));
    $this->assertSame(422, $unknown->getStatusCode());
    $this->assertSame('invalid_category', $this->json($unknown)['code']);

    // 503: the container promoter has no Open311 processor in this test.
    $unavailable = $this->controller()->promote($mail, $this->postRequest(['category_tid' => $this->categoryTid]));
    $this->assertSame(503, $unavailable->getStatusCode());
    $this->assertSame('unavailable', $this->json($unavailable)['code']);
    $this->assertSame(InboundMail::STATE_STAGED, $mail->getState(), 'A failed promotion leaves the mail staged.');

    // Success via the processor double (coded category).
    $ok = $this->json($this->controllerWithWorkingPromoter()->promote($mail, $this->postRequest(['category_tid' => $this->categoryTid])));
    $this->assertIsInt($ok['nid']);
    $this->assertNull($ok['request_id'], 'markaspot_request_id is not installed here.');
    $this->assertNotEmpty($ok['uuid']);
    $this->assertTrue($ok['ungeolocated'], 'The processor-seeded default coordinate was cleared: no location in the mail means an explicitly ungeolocated report.');
    $promotedNode = $this->container->get('entity_type.manager')->getStorage('node')->load($ok['nid']);
    $this->assertTrue($promotedNode->get('field_geolocation')->isEmpty(), 'The default coordinate is gone from the node itself.');
    // The legacy {category_tid} payload keeps today's behavior.
    $this->assertNull($ok['location_source']);
    $this->assertSame('original', $ok['description_source'], 'No AI summary stored: the original message is the description.');
    $this->assertFalse($ok['address_text_applied']);

    // 409: the mail is promoted now.
    // The passed entity is still staged in memory (the promoter worked on a
    // reloaded copy), so this exercises the in-lock state re-check.
    $again = $this->controllerWithWorkingPromoter()->promote($mail, $this->postRequest(['category_tid' => $this->categoryTid]));
    $this->assertSame(409, $again->getStatusCode());
    $this->assertSame('not_staged', $this->json($again)['code']);
    $this->assertSame(1, $this->countNodes(), 'The second promotion of the same mail created nothing.');

    // A codeless category is now promotable: it should succeed (when staged).
    $mailForCodeless = $this->mail($this->gidA);
    $codelessOk = $this->controllerWithWorkingPromoter()->promote($mailForCodeless, $this->postRequest(['category_tid' => $this->codelessTid]));
    $this->assertSame(200, $codelessOk->getStatusCode(), 'A codeless category is promotable; field_category is set directly by the promoter.');

    // The outsider may not promote a foreign mail at all.
    $this->actAs(['triage'], [$this->gidB]);
    $foreign = $this->controllerWithWorkingPromoter()->promote($this->mail($this->gidA), $this->postRequest(['category_tid' => $this->categoryTid]));
    $this->assertSame(403, $foreign->getStatusCode());
    $this->assertSame('access_denied', $this->json($foreign)['code']);
  }

  /**
   * Promote: malformed locations are rejected before anything is created.
   */
  public function testPromoteRejectsInvalidLocation(): void {
    $this->actAs(['triage'], [$this->gidA]);
    $mail = $this->mail($this->gidA);
    $controller = $this->controllerWithWorkingPromoter();

    $invalid = [
      'lat only' => ['lat' => 50.5],
      'string lat' => ['lat' => '50.5', 'lng' => 6.5],
      'lat out of range' => ['lat' => 91, 'lng' => 6.5],
      'lng out of range' => ['lat' => 50.5, 'lng' => 181],
      'list instead of object' => [50.5, 6.5],
      'scalar' => 'Marktplatz 1',
    ];
    foreach ($invalid as $label => $location) {
      $response = $controller->promote($mail, $this->postRequest([
        'category_tid' => $this->categoryTid,
        'location' => $location,
      ]));
      $this->assertSame(422, $response->getStatusCode(), $label);
      $this->assertSame('invalid_location', $this->json($response)['code'], $label);
    }
    // JSON has no Infinity literal; an overflowing number decodes to INF.
    $infinite = $controller->promote($mail, $this->rawPostRequest('{"category_tid": ' . $this->categoryTid . ', "location": {"lat": 1e999, "lng": 6.5}}'));
    $this->assertSame(422, $infinite->getStatusCode());
    $this->assertSame('invalid_location', $this->json($infinite)['code']);

    $this->assertSame(0, $this->countNodes());
    $this->assertSame(InboundMail::STATE_STAGED, $this->reloadMail($mail)->getState());
  }

  /**
   * Promote: a confirmed point is boundary-checked (child, then root).
   */
  public function testPromoteConfirmedLocationAgainstBoundary(): void {
    $this->setBoundary($this->gidA);
    // A child jurisdiction without geometry of its own below City A.
    $child = Group::create(['type' => 'jur', 'label' => 'District of A']);
    $child->save();
    $gidChild = (int) $child->id();
    $resolver = $this->rootResolver([$gidChild => $this->gidA]);
    $geocoder = $this->geocoder([]);

    $this->actAs(['triage'], [$this->gidA, $gidChild]);
    $controller = $this->controllerWithWorkingPromoter($geocoder, $resolver);

    // Outside the boundary: 422, nothing created, the mail stays staged.
    $outsideMail = $this->mail($this->gidA);
    $outside = $controller->promote($outsideMail, $this->confirmedLocationRequest(48.1, 11.5));
    $this->assertSame(422, $outside->getStatusCode());
    $this->assertSame('location_outside_jurisdiction', $this->json($outside)['code']);
    $this->assertSame(0, $this->countNodes());
    $this->assertSame(InboundMail::STATE_STAGED, $this->reloadMail($outsideMail)->getState());

    // The child has no boundary: its root's boundary decides.
    $childOutside = $controller->promote($this->mail($gidChild), $this->confirmedLocationRequest(48.1, 11.5));
    $this->assertSame(422, $childOutside->getStatusCode(), 'A child without geometry falls back to the root boundary.');

    // Inside: geolocated at exactly the confirmed point, never geocoded.
    $ok = $this->json($controller->promote($outsideMail, $this->postRequest([
      'category_tid' => $this->categoryTid,
      'use_suggested_address' => TRUE,
      'location' => ['lat' => 50.5, 'lng' => 6.5],
    ])));
    $this->assertFalse($ok['ungeolocated']);
    $this->assertSame('confirmed', $ok['location_source']);
    $this->assertFalse($ok['address_text_applied'], 'With coordinates the address text is left to the reverse geocoder.');
    $this->assertSame('50.5,6.5', $this->loadNode($ok['nid'])->get('field_geolocation')->value);
    $this->assertSame([], $geocoder->queried, 'A confirmed point is never geocoded on the server.');
    $this->assertSame([], $this->missingLocationReplies(), 'A geolocated report triggers no missing-location auto-reply.');

    $childOk = $controller->promote($this->mail($gidChild), $this->confirmedLocationRequest(50.2, 6.2));
    $this->assertSame(200, $childOk->getStatusCode());
  }

  /**
   * Promote: suggested address as geocoding hint or as plain address text.
   */
  public function testPromoteSuggestedAddressModes(): void {
    $this->setBoundary($this->gidA);
    $geocoder = $this->geocoder([
      'Marktplatz 1, City A' => ['lat' => 50.4, 'lng' => 6.4],
      'Marktplatz 1, Elsewhere' => ['lat' => 48.1, 'lng' => 11.5],
    ]);
    $this->actAs(['triage'], [$this->gidA]);
    $controller = $this->controllerWithWorkingPromoter($geocoder);

    // Location null: no coordinates, no geocoding, the text is kept.
    $textOnly = $this->json($controller->promote($this->mailWithSuggestions('Marktplatz 1, City A'), $this->postRequest([
      'category_tid' => $this->categoryTid,
      'use_suggested_address' => TRUE,
      'location' => NULL,
    ])));
    $this->assertTrue($textOnly['ungeolocated']);
    $this->assertNull($textOnly['location_source']);
    $this->assertTrue($textOnly['address_text_applied']);
    $this->assertSame('Marktplatz 1, City A', $this->loadNode($textOnly['nid'])->get('field_address')->value);
    $this->assertSame([], $geocoder->queried);
    $this->assertCount(1, $this->missingLocationReplies(), 'An ungeolocated report still asks the citizen for the location.');

    // Location key absent: the suggestion is the geocoding hint.
    $hinted = $this->json($controller->promote($this->mailWithSuggestions('Marktplatz 1, City A'), $this->postRequest([
      'category_tid' => $this->categoryTid,
      'use_suggested_address' => TRUE,
    ])));
    $this->assertFalse($hinted['ungeolocated']);
    $this->assertSame('suggested', $hinted['location_source']);
    $this->assertFalse($hinted['address_text_applied']);
    $this->assertSame(['Marktplatz 1, City A'], $geocoder->queried);

    // A hint resolving outside the jurisdiction is dropped; the text stays.
    $dropped = $this->json($controller->promote($this->mailWithSuggestions('Marktplatz 1, Elsewhere'), $this->postRequest([
      'category_tid' => $this->categoryTid,
      'use_suggested_address' => TRUE,
    ])));
    $this->assertTrue($dropped['ungeolocated'], 'A geocoded point outside the jurisdiction is dropped.');
    $this->assertNull($dropped['location_source']);
    $this->assertTrue($dropped['address_text_applied']);

    // Unticked: neither hint nor text, even though a suggestion exists.
    $unticked = $this->json($controller->promote($this->mailWithSuggestions('Marktplatz 1, City A'), $this->postRequest([
      'category_tid' => $this->categoryTid,
      'use_suggested_address' => FALSE,
      'location' => NULL,
    ])));
    $this->assertTrue($unticked['ungeolocated']);
    $this->assertFalse($unticked['address_text_applied']);
    $this->assertTrue($this->loadNode($unticked['nid'])->get('field_address')->isEmpty());
    $this->assertCount(2, $geocoder->queried, 'Only the two auto-mode promotions geocoded.');
  }

  /**
   * Promote: staff can opt out of the AI summary as description.
   */
  public function testPromoteDescriptionChoice(): void {
    $this->actAs(['triage'], [$this->gidA]);
    $controller = $this->controllerWithWorkingPromoter();

    // Default (and the legacy payload): the AI summary is the description.
    $suggested = $this->json($controller->promote($this->mailWithSuggestions(NULL), $this->postRequest(['category_tid' => $this->categoryTid])));
    $this->assertSame('suggested', $suggested['description_source']);
    $this->assertSame("Broken light

A streetlight next to the church is out.", $this->loadNode($suggested['nid'])->get('body')->value);

    $original = $this->json($controller->promote($this->mailWithSuggestions(NULL), $this->postRequest([
      'category_tid' => $this->categoryTid,
      'use_suggested_description' => FALSE,
    ])));
    $this->assertSame('original', $original['description_source']);
    $this->assertSame("Broken light

A streetlight is broken near the church.", $this->loadNode($original['nid'])->get('body')->value);
  }

  /**
   * Promote: a held promotion lock answers 409 and creates nothing.
   */
  public function testPromoteWhileLockedIsConflict(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(FALSE);
    $this->container->set('lock', $lock);

    $this->actAs(['triage'], [$this->gidA]);
    $mail = $this->mail($this->gidA);
    $response = $this->controllerWithWorkingPromoter()->promote($mail, $this->postRequest(['category_tid' => $this->categoryTid]));

    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('promotion_in_progress', $this->json($response)['code']);
    $this->assertSame(0, $this->countNodes());
    $this->assertSame(InboundMail::STATE_STAGED, $this->reloadMail($mail)->getState());
  }

  /**
   * Discard: success + 409 on a non-staged mail.
   */
  public function testDiscardContract(): void {
    $this->actAs(['triage'], [$this->gidA]);
    $mail = $this->mail($this->gidA);

    $ok = $this->json($this->controller()->discard($mail));
    $this->assertSame('discarded', $ok['state']);
    $this->assertSame(InboundMail::STATE_DISCARDED, $mail->getState());

    $again = $this->controller()->discard($mail);
    $this->assertSame(409, $again->getStatusCode());
  }

  /**
   * Reply: sent via the collector, recorded; 422 / 409 error contract.
   */
  public function testReplyContract(): void {
    $this->actAs(['triage'], [$this->gidA]);
    $mail = $this->mail($this->gidA);

    $empty = $this->controller()->reply($mail, $this->postRequest(['body' => '  ']));
    $this->assertSame(422, $empty->getStatusCode());

    // 422: the server enforces the max_body_length setting (install default
    // 10000) — the client-side limit is advisory only.
    $tooLong = $this->controller()->reply($mail, $this->postRequest(['body' => str_repeat('x', 10001)]));
    $this->assertSame(422, $tooLong->getStatusCode());
    $this->assertSame([], $this->container->get('state')->get('system.test_mail_collector') ?? [], 'No over-length mail left the system.');

    $ok = $this->json($this->controller()->reply($mail, $this->postRequest(['body' => 'Where exactly is the light?'])));
    $this->assertTrue($ok['sent']);
    $this->assertTrue($ok['recorded']);

    $captured = $this->container->get('state')->get('system.test_mail_collector') ?? [];
    $this->assertCount(1, $captured);
    $this->assertSame('markaspot_mail_inbound', $captured[0]['module']);
    $this->assertSame('triage_reply', $captured[0]['key']);
    $this->assertSame('citizen@example.org', $captured[0]['to']);

    // Recorded on the conversation log; the mail stays staged.
    $reloaded = $this->container->get('entity_type.manager')->getStorage('inbound_mail')->load($mail->id());
    $this->assertStringContainsString('Staff reply (', $reloaded->getBody());
    $this->assertStringContainsString('Where exactly is the light?', $reloaded->getBody());
    $this->assertSame(InboundMail::STATE_STAGED, $reloaded->getState());

    // 409 on a non-staged mail.
    $promoted = $this->mail($this->gidA, InboundMail::STATE_PROMOTED);
    $conflict = $this->controller()->reply($promoted, $this->postRequest(['body' => 'Hello']));
    $this->assertSame(409, $conflict->getStatusCode());
  }

  /**
   * AI suggestion fields appear in list rows and detail.
   *
   * Fidelity.ai_available is present. Pins the four frontend-contract keys
   * introduced with the AI suggestion feature (#482): suggested_category_tid,
   * suggested_category_label, suggestion_confidence, suggestion_status.
   * Values are set directly on the entity to isolate the controller
   * serialization from the suggestion service.
   */
  public function testSuggestionFieldsInListAndDetail(): void {
    $this->actAs(['administrator']);

    // A mail with no suggestion (default state).
    $this->mail($this->gidA);
    $listData = $this->json($this->controller()->list(Request::create('/api/inbound-mail')));
    $this->assertCount(1, $listData['items']);
    $row = $listData['items'][0];

    // All four keys must be present in list rows, with their null/none
    // defaults.
    $this->assertArrayHasKey('suggested_category_tid', $row, 'List row must carry suggested_category_tid');
    $this->assertArrayHasKey('suggested_category_label', $row, 'List row must carry suggested_category_label');
    $this->assertArrayHasKey('suggestion_confidence', $row, 'List row must carry suggestion_confidence');
    $this->assertArrayHasKey('suggestion_status', $row, 'List row must carry suggestion_status');
    $this->assertNull($row['suggested_category_tid']);
    $this->assertNull($row['suggested_category_label']);
    $this->assertNull($row['suggestion_confidence']);
    $this->assertSame(InboundMail::SUGGESTION_NONE, $row['suggestion_status']);

    // fidelity.ai_available must be present.
    $this->assertArrayHasKey('ai_available', $listData['fidelity'], 'fidelity block must carry ai_available');
    $this->assertIsBool($listData['fidelity']['ai_available']);

    // A mail with a staged suggestion result (set directly on entity).
    $suggestedMail = $this->mail($this->gidA);
    $suggestedMail->setSuggestedCategoryTid($this->categoryTid);
    $suggestedMail->setSuggestionConfidence(0.92);
    $suggestedMail->setSuggestionStatus(InboundMail::SUGGESTION_DONE);
    $suggestedMail->save();

    // List: both mails appear; check the suggested one.
    $listData2 = $this->json($this->controller()->list(Request::create('/api/inbound-mail')));
    $this->assertSame(2, $listData2['total']);
    $suggestedRow = NULL;
    foreach ($listData2['items'] as $item) {
      if ($item['id'] === (int) $suggestedMail->id()) {
        $suggestedRow = $item;
        break;
      }
    }
    $this->assertNotNull($suggestedRow, 'Suggested mail must appear in list.');
    $this->assertSame($this->categoryTid, $suggestedRow['suggested_category_tid']);
    $this->assertSame('Streetlight', $suggestedRow['suggested_category_label']);
    $this->assertEqualsWithDelta(0.92, (float) $suggestedRow['suggestion_confidence'], 0.001);
    $this->assertSame(InboundMail::SUGGESTION_DONE, $suggestedRow['suggestion_status']);

    // Detail: full payload carries the same keys and values.
    $detailData = $this->json($this->controller()->detail($suggestedMail));
    $this->assertArrayHasKey('suggested_category_tid', $detailData, 'Detail must carry suggested_category_tid');
    $this->assertArrayHasKey('suggested_category_label', $detailData, 'Detail must carry suggested_category_label');
    $this->assertArrayHasKey('suggestion_confidence', $detailData, 'Detail must carry suggestion_confidence');
    $this->assertArrayHasKey('suggestion_status', $detailData, 'Detail must carry suggestion_status');
    $this->assertSame($this->categoryTid, $detailData['suggested_category_tid']);
    $this->assertSame('Streetlight', $detailData['suggested_category_label']);
    $this->assertEqualsWithDelta(0.92, (float) $detailData['suggestion_confidence'], 0.001);
    $this->assertSame(InboundMail::SUGGESTION_DONE, $detailData['suggestion_status']);
    // Address and summary are detail-only and NULL when absent.
    $this->assertArrayHasKey('suggested_address', $detailData);
    $this->assertArrayHasKey('suggested_description', $detailData);
    $this->assertNull($detailData['suggested_address']);
    $this->assertNull($detailData['suggested_description']);
    $this->assertArrayNotHasKey('suggested_address', $suggestedRow, 'Data minimization: no address in list rows.');
    $this->assertArrayNotHasKey('suggested_description', $suggestedRow);

    $suggestedMail->setSuggestedAddress('Marktplatz 1');
    $suggestedMail->setSuggestedDescription('A streetlight is out.');
    $suggestedMail->save();
    $detailWithText = $this->json($this->controller()->detail($suggestedMail));
    $this->assertSame('Marktplatz 1', $detailWithText['suggested_address']);
    $this->assertSame('A streetlight is out.', $detailWithText['suggested_description']);

    // Fidelity ai_available is present and boolean in the list response.
    // (The detail endpoint does not embed fidelity, only the list does.)
    $this->assertArrayHasKey('ai_available', $listData2['fidelity']);
    $this->assertIsBool($listData2['fidelity']['ai_available']);
  }

  /**
   * Builds a JSON POST request.
   */
  protected function postRequest(array $payload): Request {
    return $this->rawPostRequest((string) json_encode($payload));
  }

  /**
   * Builds a promote request with a staff-confirmed location.
   */
  protected function confirmedLocationRequest(float $lat, float $lng): Request {
    return $this->postRequest([
      'category_tid' => $this->categoryTid,
      'location' => ['lat' => $lat, 'lng' => $lng],
    ]);
  }

  /**
   * Builds a POST request with a raw JSON body.
   */
  protected function rawPostRequest(string $body): Request {
    return Request::create('/api/inbound-mail/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
  }

  /**
   * Creates a staged City A mail with an AI address and summary.
   */
  protected function mailWithSuggestions(?string $address): InboundMail {
    $mail = $this->mail($this->gidA);
    $mail->setSuggestedAddress($address);
    $mail->setSuggestedDescription('A streetlight next to the church is out.');
    $mail->setSuggestionStatus(InboundMail::SUGGESTION_DONE);
    $mail->save();
    return $mail;
  }

  /**
   * Gives a jurisdiction a GeoJSON square (lat 50..51, lng 6..7).
   */
  protected function setBoundary(int $gid): void {
    $group = Group::load($gid);
    $group->set('field_boundary', (string) json_encode([
      'type' => 'Polygon',
      'coordinates' => [[[6, 50], [7, 50], [7, 51], [6, 51], [6, 50]]],
    ]));
    $group->save();
  }

  /**
   * A geocoder double resolving addresses from a map and recording queries.
   *
   * @param array<string, array{lat: float, lng: float}> $map
   *   Coordinates keyed by address.
   */
  protected function geocoder(array $map): object {
    return new class($map) {

      /**
       * Addresses the double was asked for.
       *
       * @var string[]
       */
      public array $queried = [];

      public function __construct(protected array $map) {}

      /**
       * Mirrors MarkaspotGeocoderService::getCoordinatesFromAddress().
       */
      public function getCoordinatesFromAddress(string $address): ?array {
        $this->queried[] = $address;
        return $this->map[$address] ?? NULL;
      }

    };
  }

  /**
   * A hierarchy resolver double (child gid => root gid, roots map to self).
   *
   * @param array<int, int> $roots
   *   Root gids keyed by child gid.
   */
  protected function rootResolver(array $roots): object {
    return new class($roots) {

      public function __construct(protected array $roots) {}

      /**
       * Mirrors JurisdictionHierarchyResolver::getRootJurisdictionId().
       */
      public function getRootJurisdictionId(int $groupId): ?int {
        return $this->roots[$groupId] ?? $groupId;
      }

    };
  }

  /**
   * Counts service request nodes.
   */
  protected function countNodes(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('node')
      ->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Loads a node fresh from storage.
   */
  protected function loadNode(int $nid): Node {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($nid);
    $this->assertInstanceOf(Node::class, $node);
    return $node;
  }

  /**
   * Reloads a mail fresh from storage.
   */
  protected function reloadMail(InboundMail $mail): InboundMail {
    $reloaded = $this->container->get('entity_type.manager')->getStorage('inbound_mail')->loadUnchanged($mail->id());
    $this->assertInstanceOf(InboundMail::class, $reloaded);
    return $reloaded;
  }

  /**
   * Missing-location auto-replies captured by the test mail collector.
   *
   * @return array<int, array<string, mixed>>
   *   The captured messages.
   */
  protected function missingLocationReplies(): array {
    $captured = $this->container->get('state')->get('system.test_mail_collector') ?? [];
    return array_values(array_filter($captured, static fn(array $message): bool => ($message['key'] ?? '') === InboundMailReplyService::KEY_AUTO_REPLY_MISSING_LOCATION));
  }

}
