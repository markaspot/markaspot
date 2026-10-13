<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_media\Kernel;

use Drupal\image\Controller\ImageStyleDownloadController;
use Drupal\image\Entity\ImageStyle;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Symfony\Component\HttpFoundation\Request;
use Drupal\system\FileDownloadController;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests staff access to unpublished request images and their private files.
 */
#[Group('markaspot_media')]
#[RunTestsInSeparateProcesses]
final class RequestImageAccessTest extends KernelTestBase {

  use ImageFieldCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'file', 'image', 'node',
    'media', 'inline_entity_form', 'markaspot_media',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
    $container->register('stream_wrapper.private', PrivateStream::class)
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'file', 'node', 'media'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'file', 'image']);
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
    mkdir($this->siteDirectory . '/private');
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Request'])->save();
    MediaType::create([
      'id' => 'request_image',
      'label' => 'Request image',
      'source' => 'image',
      'source_configuration' => ['source_field' => 'field_media_image'],
    ])->save();
    $this->createImageField('field_media_image', 'media', 'request_image', ['uri_scheme' => 'private']);
    FieldStorageConfig::create([
      'field_name' => 'field_request_media',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_request_media',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Images',
      'settings' => [
        'handler' => 'default:media',
        'handler_settings' => ['target_bundles' => ['request_image' => 'request_image']],
      ],
    ])->save();
  }

  /**
   * Staff can save an unchanged private image without publishing it.
   */
  public function testPrivateImageValidationAndDownload(): void {
    $staff = $this->createUser([
      'access content', 'view media', 'edit own service_request content',
      'edit any request_image media',
    ]);
    [$media, $file] = $this->createImage();
    $node = $this->createRequest($media, (int) $staff->id());
    $this->container->get('current_user')->setAccount($staff);
    $this->assertTrue($node->access('update', $staff));
    $this->assertTrue($media->access('view', $staff));
    $this->assertTrue($file->access('view', $staff));
    $this->assertTrue($file->access('download', $staff));
    $this->assertCount(0, $media->get('field_media_image')->validate());
    $media->setName('Edited without changing the photograph')->save();
    $this->assertFalse(Media::load($media->id())->isPublished());

    $outsider = $this->createUser([
      'access content', 'view media', 'edit own service_request content',
      'edit any request_image media',
    ]);
    foreach ([$outsider, User::getAnonymousUser()] as $viewer) {
      $this->assertFalse($media->access('view', $viewer));
      $this->assertFalse($file->access('view', $viewer));
      $this->assertFalse($file->access('download', $viewer));
    }
  }

  /**
   * Every parent must permit editing; orphaned images gain no new permission.
   */
  public function testAllParentsAndRelinking(): void {
    $staff = $this->createUser(['access content', 'view media', 'edit own service_request content']);
    $other = $this->createUser(['access content']);
    [$media] = $this->createImage();
    $this->assertFalse($media->access('view', $staff));
    $parent = $this->createRequest($media, (int) $staff->id());
    $this->resetAccess();
    $access = $media->access('view', $staff, TRUE);
    $this->assertTrue($access->isAllowed());
    $this->assertSame(0, $access->getCacheMaxAge());
    $second = $this->createRequest($media, (int) $other->id());
    $this->resetAccess();
    $this->assertFalse($media->access('view', $staff));
    $second->delete();
    $parent->setOwner($other)->save();
    $this->resetAccess();
    $this->assertFalse($media->access('view', $staff));
  }

  /**
   * The public-file path and normal published-media access remain unchanged.
   */
  public function testPublicFilesAndPublishedMedia(): void {
    $viewer = $this->createUser(['access content', 'view media']);
    [$media, $file] = $this->createImage('public');
    $this->assertFalse($media->access('view', $viewer));
    $this->assertTrue($file->access('view', $viewer));
    $media->setPublished()->save();
    $this->resetAccess();
    $this->assertTrue($media->access('view', $viewer));
  }

  /**
   * A denied reference becomes a form error, including unchanged references.
   */
  public function testDeniedReferenceMapsToFormError(): void {
    [$media] = $this->createImage();
    $viewer = $this->createUser(['access content', 'edit any request_image media']);
    $this->container->get('current_user')->setAccount($viewer);
    $violations = $media->get('field_media_image')->validate();
    $this->assertCount(1, $violations);
    $this->assertSame('0', $violations[0]->getPropertyPath());
    $display = $this->container->get('entity_display.repository')->getFormDisplay('media', 'request_image');
    $widget = $display->getRenderer('field_media_image');
    $form_state = new FormState();
    $element = ['#parents' => ['field_media_image', 0], '#type' => 'managed_file'];
    $target = $widget->errorElement($element, $violations[0], [], $form_state);
    $this->assertIsArray($target);
    $form_state->setError($target, $violations[0]->getMessage());
    $this->assertNotEmpty($form_state->getErrors());
  }

  /**
   * Exercises the actual request form, including inline-media validation/save.
   */
  public function testRequestFormSaveWithInlineImage(): void {
    $staff = $this->createUser([
      'access content', 'view media', 'edit own service_request content',
      'edit any request_image media',
    ]);
    [$media, $file] = $this->createImage();
    $node = $this->createRequest($media, (int) $staff->id());
    $this->container->get('current_user')->setAccount($staff);
    $display = $this->container->get('entity_display.repository')->getFormDisplay('node', 'service_request');
    $display->setComponent('title', ['type' => 'string_textfield'])
      ->setComponent('field_request_media', ['type' => 'inline_entity_form_simple'])
      ->save();
    $form = $this->container->get('entity_type.manager')->getFormObject('node', 'default');
    $form->setEntity($node);
    $state = (new FormState())->setValues([
      'op' => 'Save',
      'title' => [['value' => 'Saved through the request form']],
      'field_request_media' => [[
        'inline_entity_form' => [
          'field_media_image' => [['fids' => (string) $file->id(), 'alt' => 'Test image', 'width' => 1, 'height' => 1]],
        ],
      ],
      ],
    ]);
    $this->container->get('form_builder')->submitForm($form, $state);
    $this->assertTrue($state->getTriggeringElement()['#ief_submit_trigger']);
    $this->assertSame([], $state->getErrors());
    $stored = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $this->assertSame('Saved through the request form', $stored->label());
    $this->assertSame($media->id(), $stored->get('field_request_media')->target_id);
    $this->assertFalse(Media::load($media->id())->isPublished());
  }

  /**
   * The legacy selector retains old references without admitting new ones.
   */
  public function testSelectionDoesNotAllowNewReferences(): void {
    $staff = $this->createUser(['access content', 'view media', 'edit own service_request content']);
    $this->container->get('current_user')->setAccount($staff);
    [$existing] = $this->createImage();
    [$other] = $this->createImage();
    $parent = $this->createRequest($existing, (int) $staff->id());
    $this->createRequest($other, (int) $staff->id());
    $this->assertTrue($other->access('view', $staff));
    $manager = $this->container->get('plugin.manager.entity_reference_selection');
    $field = $parent->getFieldDefinition('field_request_media');
    $handler = $manager->getSelectionHandler($field, $parent);
    $this->assertSame('existing_request_media:media', $handler->getPluginId());
    $valid = $handler->validateReferenceableEntities([$existing->id(), $other->id()]);
    $this->assertContains($existing->id(), $valid);
    $this->assertNotContains($other->id(), $valid);
    $this->assertSame([], $handler->getReferenceableEntities());
    $new = Node::create(['type' => 'service_request', 'uid' => $staff->id()]);
    $this->assertSame([], $manager->getSelectionHandler($field, $new)->validateReferenceableEntities([$existing->id()]));
    $unrelated = clone $field;
    $unrelated->setSetting('handler', 'default:media');
    $this->assertSame([], $manager->getSelectionHandler($unrelated, $parent)->validateReferenceableEntities([$existing->id()]));
    $restricted = clone $field;
    $restricted->setSetting('handler_settings', ['target_bundles' => []]);
    $this->assertSame([], $manager->getSelectionHandler($restricted, $parent)->validateReferenceableEntities([$existing->id()]));
  }

  /**
   * Private download uses the same authorization and remains non-public.
   */
  public function testPrivateDownloadController(): void {
    $staff = $this->createUser(['access content', 'edit own service_request content']);
    [$media, $file] = $this->createImage();
    $this->createRequest($media, (int) $staff->id());
    $this->container->get('current_user')->setAccount($staff);
    $controller = new FileDownloadController($this->container->get('stream_wrapper_manager'));
    $request = Request::create('/system/files/photo.png', 'GET', [
      'file' => StreamWrapperManager::getTarget($file->getFileUri()),
    ]);
    $response = $controller->download($request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->container->get('current_user')->setAccount(User::getAnonymousUser());
    $this->resetAccess();
    $this->expectException(AccessDeniedHttpException::class);
    $controller->download($request);
  }

  /**
   * Generated private previews obey the same access checks as the source.
   */
  public function testPrivateImagePreview(): void {
    $staff = $this->createUser(['access content', 'edit own service_request content']);
    [$media, $file] = $this->createImage();
    $this->createRequest($media, (int) $staff->id());
    $this->container->get('current_user')->setAccount($staff);
    $style = ImageStyle::load('thumbnail');
    $this->assertNotNull($style);
    $request = Request::create('/system/files/styles/thumbnail/private/photo.png', 'GET', [
      'file' => StreamWrapperManager::getTarget($file->getFileUri()),
      'itok' => $style->getPathToken($file->getFileUri()),
    ]);
    $controller = new ImageStyleDownloadController(
      $this->container->get('lock'),
      $this->container->get('image.factory'),
      $this->container->get('stream_wrapper_manager'),
      $this->container->get('file_system'),
    );
    $response = $controller->deliver($request, 'private', $style, 'private');
    $this->assertSame(200, $response->getStatusCode());
    $this->container->get('current_user')->setAccount(User::getAnonymousUser());
    $this->resetAccess();
    $this->expectException(AccessDeniedHttpException::class);
    $controller->deliver($request, 'private', $style, 'private');
  }

  /**
   * Creates a real managed file and unpublished anonymous image.
   */
  private function createImage(string $scheme = 'private'): array {
    $uri = $scheme . '://photo-' . $this->randomMachineName() . '.png';
    $image = imagecreatetruecolor(2, 2);
    imagepng($image, $uri);
    $file = File::create(['uri' => $uri, 'uid' => 0, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'request_image',
      'name' => 'Synthetic private image',
      'uid' => 0,
      'status' => 0,
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'Test image'],
    ]);
    $media->save();
    return [$media, $file];
  }

  /**
   * Creates a published parent request.
   */
  private function createRequest(Media $media, int $owner): Node {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Synthetic request',
      'uid' => $owner,
      'status' => 1,
      'field_request_media' => [$media->id()],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Starts a fresh access decision, as on a subsequent HTTP request.
   */
  private function resetAccess(): void {
    foreach (['node', 'media', 'file'] as $type) {
      $this->container->get('entity_type.manager')->getAccessControlHandler($type)->resetCache();
    }
  }

}
