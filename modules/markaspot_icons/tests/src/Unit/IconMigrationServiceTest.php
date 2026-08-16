<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_icons\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\iconify_field\Service\IconResolverInterface;
use Drupal\markaspot_icons\IconMigrationService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests deterministic icon migration mapping and format preservation.
 */
#[CoversClass(IconMigrationService::class)]
#[Group('markaspot_icons')]
class IconMigrationServiceTest extends UnitTestCase {

  /**
   * Tests known aliases preserve both supported storage formats.
   */
  public function testLucideAliasesPreserveFormat(): void {
    $service = $this->createService();

    $colon = $service->planIconRepair('lucide:child');
    $this->assertSame('lucide:baby', $colon['replacement']);
    $this->assertSame('alias', $colon['repair']);

    $iconify = $service->planIconRepair('i-lucide-tree');
    $this->assertSame('i-lucide-trees', $iconify['replacement']);
    $this->assertSame('alias', $iconify['repair']);

    $legacyCircle = $service->planIconRepair('i-lucide-plus-circle');
    $this->assertSame('i-lucide-circle-plus', $legacyCircle['replacement']);
  }

  /**
   * Tests aliases supplied by the installed Lucide collection.
   */
  public function testCollectionAliasIsCanonicalized(): void {
    $resolver = $this->createMock(IconResolverInterface::class);
    $resolver->expects($this->once())
      ->method('loadCollection')
      ->with('lucide')
      ->willReturn([
        'aliases' => [
          'circle-help' => ['parent' => 'circle-question-mark'],
        ],
      ]);
    $renderer = $this->createMock(RendererInterface::class);

    $plan = $this->createService($resolver, $renderer)
      ->inspectIconValue('lucide:circle-help');

    $this->assertSame('lucide:circle-question-mark', $plan['replacement']);
    $this->assertSame('alias', $plan['repair']);
  }

  /**
   * Tests canonical names stay unchanged when they resolve.
   */
  public function testResolvableTreePineRemainsUnchanged(): void {
    $plan = $this->createService()->planIconRepair(
      'i-lucide-tree-pine',
      TRUE,
    );

    $this->assertNull($plan['issue']);
    $this->assertNull($plan['replacement']);
  }

  /**
   * Tests unresolved icons use a format-preserving default.
   */
  public function testUnresolvedIconsUseFormatPreservingFallback(): void {
    $service = $this->createService();

    $colon = $service->planIconRepair('lucide:not-an-icon', FALSE);
    $this->assertSame('lucide:circle-alert', $colon['replacement']);
    $this->assertSame('fallback', $colon['repair']);

    $iconify = $service->planIconRepair('i-lucide-not-an-icon', FALSE);
    $this->assertSame('i-lucide-circle-alert', $iconify['replacement']);
    $this->assertSame('fallback', $iconify['repair']);
  }

  /**
   * Tests FontAwesome leftovers use aliases or the safe default.
   */
  public function testFontAwesomeLeftoversUseAliasesAndFallback(): void {
    $service = $this->createService();

    $alias = $service->planIconRepair('fa-child');
    $this->assertSame('i-lucide-baby', $alias['replacement']);
    $this->assertSame('alias', $alias['repair']);
    $this->assertTrue($alias['fixable']);

    $fallback = $service->planIconRepair('fa-made-up', FALSE);
    $this->assertSame('i-lucide-circle-alert', $fallback['replacement']);
    $this->assertSame('fallback', $fallback['repair']);
    $this->assertTrue($fallback['fixable']);
  }

  /**
   * Tests missing resolver behavior is conservative.
   */
  public function testMissingResolverDoesNotGuess(): void {
    $service = $this->createService();
    $plan = $service->planIconRepair('lucide:custom-name');

    $this->assertSame('resolver-unavailable', $plan['issue']);
    $this->assertNull($plan['replacement']);
    $this->assertFalse($plan['fixable']);

    $fontAwesome = $service->planIconRepair('fa-made-up');
    $this->assertSame('manual-review', $fontAwesome['repair']);
    $this->assertNull($fontAwesome['replacement']);
    $this->assertFalse($fontAwesome['fixable']);
  }

  /**
   * Creates the service with no optional runtime resolver.
   */
  private function createService(
    ?IconResolverInterface $resolver = NULL,
    ?RendererInterface $renderer = NULL,
  ): IconMigrationService {
    return new IconMigrationService(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(MessengerInterface::class),
      $resolver,
      $renderer,
    );
  }

}
