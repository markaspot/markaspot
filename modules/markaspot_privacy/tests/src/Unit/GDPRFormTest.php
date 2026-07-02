<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_privacy\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\markaspot_privacy\Form\GDPRForm;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests GDPRForm's split-link erasure cascade (markaspot-ui#512).
 *
 * @group markaspot_privacy
 * @coversDefaultClass \Drupal\markaspot_privacy\Form\GDPRForm
 */
class GDPRFormTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // cascadeErasureToSplitSiblings() logs via FormBase::logger(), which
    // falls back to \Drupal::service('logger.factory') because GDPRForm
    // never calls setLoggerFactory().
    $channel = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($channel);

    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);
  }

  // ===========================================================================
  // resolveCascadeNodeIds (pure graph walk, no Drupal mocks needed).
  // ===========================================================================

  /**
   * @covers ::resolveCascadeNodeIds
   */
  public function testResolveCascadeNodeIdsOneChild(): void {
    $links = [
      88 => [['source_nid' => 88, 'target_nid' => 456]],
      456 => [['source_nid' => 88, 'target_nid' => 456]],
    ];

    $result = GDPRForm::resolveCascadeNodeIds(88, fn(int $nid): array => $links[$nid] ?? []);

    $this->assertSame([456], $result);
  }

  /**
   * A chain A(1) -> B(2) -> C(3) must cascade transitively to both B and C.
   *
   * @covers ::resolveCascadeNodeIds
   */
  public function testResolveCascadeNodeIdsChain(): void {
    $links = [
      1 => [['source_nid' => 1, 'target_nid' => 2]],
      2 => [
        ['source_nid' => 1, 'target_nid' => 2],
        ['source_nid' => 2, 'target_nid' => 3],
      ],
      3 => [['source_nid' => 2, 'target_nid' => 3]],
    ];

    $result = GDPRForm::resolveCascadeNodeIds(1, fn(int $nid): array => $links[$nid] ?? []);

    $this->assertSame([2, 3], $result);
  }

  /**
   * @covers ::resolveCascadeNodeIds
   */
  public function testResolveCascadeNodeIdsNoLinksReturnsEmpty(): void {
    $result = GDPRForm::resolveCascadeNodeIds(88, fn(int $nid): array => []);

    $this->assertSame([], $result);
  }

  /**
   * A cycle must not infinite loop.
   *
   * A<->B, both rows pointing at each other: the visited-set guard stops
   * re-queueing an already-discovered node.
   *
   * @covers ::resolveCascadeNodeIds
   */
  public function testResolveCascadeNodeIdsCycleGuard(): void {
    $links = [
      1 => [['source_nid' => 1, 'target_nid' => 2]],
      2 => [
        ['source_nid' => 1, 'target_nid' => 2],
        ['source_nid' => 2, 'target_nid' => 1],
      ],
    ];

    $result = GDPRForm::resolveCascadeNodeIds(1, fn(int $nid): array => $links[$nid] ?? []);

    $this->assertSame([2], $result);
  }

  // ===========================================================================
  // cascadeErasureToSplitSiblings (side-effecting, via a testable subclass).
  // ===========================================================================

  /**
   * No-ops entirely when the request-link service is absent.
   *
   * Markaspot_privacy must keep working standalone on sites that never
   * installed markaspot_dashboard: no entity storage lookups at all.
   *
   * @covers ::cascadeErasureToSplitSiblings
   */
  public function testCascadeIsNoOpWhenRequestLinkServiceAbsent(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');

    $form = new TestableGdprFormForCascade(
      $this->createMock(MessengerInterface::class),
      $entityTypeManager,
      $this->mockConfigFactory(),
      NULL
    );

    $form->publicCascade(88);
  }

  /**
   * @covers ::cascadeErasureToSplitSiblings
   */
  public function testCascadeAnonymizesEachLinkedNode(): void {
    $requestLinkService = new class() {

      /**
       * Returns the split-link rows touching the given node ID.
       */
      public function getLinksForNode(int $nid): array {
        return $nid === 88
          ? [['source_nid' => 88, 'target_nid' => 456, 'link_type' => 'split', 'uid' => 12, 'created' => 1]]
          : [];
      }

    };

    $linkedNode = $this->createMock(NodeInterface::class);
    $linkedNode->expects($this->once())->method('setUnpublished');
    $linkedNode->expects($this->once())->method('set')->with('field_e_mail', 'anasasaonymous@example.off');
    $linkedNode->expects($this->once())->method('save');

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('load')->with(456)->willReturn($linkedNode);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($nodeStorage);

    $form = new TestableGdprFormForCascade(
      $this->createMock(MessengerInterface::class),
      $entityTypeManager,
      $this->mockConfigFactory(),
      $requestLinkService
    );

    $form->publicCascade(88);
  }

  /**
   * Builds a config factory stub for the constructor's getEditable() call.
   */
  private function mockConfigFactory(): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('getEditable')->with('markaspot_privacy.settings')->willReturn($config);
    return $configFactory;
  }

}

/**
 * Test-only subclass exposing the protected cascade method.
 */
class TestableGdprFormForCascade extends GDPRForm {

  /**
   * Public wrapper around the protected cascade method under test.
   */
  public function publicCascade(int $nid): void {
    $this->cascadeErasureToSplitSiblings($nid);
  }

}
