<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anonymous requests reach no JSON:API user data, staff requests stay as is.
 *
 * @group markaspot_nuxt
 */
#[RunTestsInSeparateProcesses]
final class UserJsonApiAnonymousKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'file',
    'file_mdm',
    'file_mdm_exif',
    'file_mdm_font',
    'image',
    'image_effects',
    'node',
    'taxonomy',
    'media',
    'markaspot_media',
    'entity_reference_revisions',
    'paragraphs',
    'field_permissions',
    'entity',
    'flexible_permissions',
    'group',
    'gnode',
    'markaspot_validation',
    'markaspot_group',
    'serialization',
    'jsonapi',
    'markaspot_nuxt',
  ];

  /**
   * A published page owned by a named account.
   */
  private Node $page;

  /**
   * The page author.
   */
  private User $author;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    Role::load(RoleInterface::AUTHENTICATED_ID)->grantPermission('access content')->save();

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->author = User::create(['name' => 'author@example.org', 'mail' => 'author@example.org', 'status' => 1]);
    $this->author->save();
    $this->page = Node::create(['type' => 'page', 'title' => 'Page', 'uid' => $this->author->id(), 'status' => 1]);
    $this->page->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Every user resource route answers anonymous requests with 403.
   */
  public function testAnonymousUserRoutesAreForbidden(): void {
    $this->setCurrentUser(new AnonymousUserSession());
    $uuid = $this->author->uuid();
    foreach ([
      '/jsonapi/user/user',
      '/jsonapi/user/user?sort=name&page[limit]=50',
      "/jsonapi/user/user/$uuid",
      "/jsonapi/user/user/$uuid/roles",
      "/jsonapi/user/user/$uuid/relationships/roles",
    ] as $path) {
      $response = $this->get($path);
      $this->assertSame(403, $response->getStatusCode(), $path);
      $this->assertStringNotContainsString('author@example.org', (string) $response->getContent(), $path);
    }
  }

  /**
   * User references inside other resources stay hidden from anonymous.
   */
  public function testAnonymousRequestsSeeNoUserReferences(): void {
    $this->setCurrentUser(new AnonymousUserSession());
    $path = '/jsonapi/node/page/' . $this->page->uuid();

    $plain = $this->get($path);
    $this->assertSame(200, $plain->getStatusCode(), (string) $plain->getContent());
    $data = json_decode((string) $plain->getContent(), TRUE)['data'];
    $this->assertArrayNotHasKey('uid', $data['relationships'] ?? []);
    $this->assertArrayNotHasKey('revision_uid', $data['relationships'] ?? []);

    $included = $this->get($path . '?include=uid');
    $body = (string) $included->getContent();
    $this->assertStringNotContainsString($this->author->uuid(), $body);
    $this->assertStringNotContainsString('author@example.org', $body);

    $related = $this->get($path . '/uid');
    $this->assertSame(403, $related->getStatusCode());
    $this->assertStringNotContainsString($this->author->uuid(), (string) $related->getContent());

    $filtered = $this->get('/jsonapi/node/page?filter[uid.name]=author@example.org');
    $this->assertStringNotContainsString($this->page->uuid(), (string) $filtered->getContent());
  }

  /**
   * Logged-in staff keep their access to user resources and references.
   */
  public function testStaffAccessIsUnchanged(): void {
    $staff = $this->createUser(['access content', 'access user profiles'], 'staff');
    $this->setCurrentUser($staff);

    $collection = $this->get('/jsonapi/user/user');
    $this->assertSame(200, $collection->getStatusCode(), (string) $collection->getContent());
    $this->assertStringContainsString($this->author->uuid(), (string) $collection->getContent());

    $included = $this->get('/jsonapi/node/page/' . $this->page->uuid() . '?include=uid');
    $this->assertSame(200, $included->getStatusCode());
    $data = json_decode((string) $included->getContent(), TRUE);
    $this->assertSame($this->author->uuid(), $data['data']['relationships']['uid']['data']['id']);
  }

  /**
   * Sends a JSON:API GET request through the HTTP kernel.
   */
  private function get(string $path): Response {
    $request = Request::create($path);
    $request->headers->set('Accept', 'application/vnd.api+json');
    return $this->container->get('http_kernel')->handle($request);
  }

}
