<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the narrowly scoped Anthropic default migration.
 */
#[Group('markaspot_ai')]
class AnthropicDefaultUpdateTest extends UnitTestCase {

  /**
   * Only the exact retired identifier may be changed.
   */
  #[DataProvider('models')]
  public function testUpdate(?string $stored, bool $changes): void {
    require_once dirname(__DIR__, 3) . '/markaspot_ai.install';
    $config = $this->createMock(Config::class);
    $config->expects($this->once())->method('get')->with('providers.anthropic.chat_model')->willReturn($stored);
    $config->expects($changes ? $this->once() : $this->never())->method('set')->with('providers.anthropic.chat_model', 'claude-sonnet-4-6')->willReturnSelf();
    $config->expects($changes ? $this->once() : $this->never())->method('save');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('markaspot_ai.settings')->willReturn($config);
    $container = new ContainerBuilder();
    $container->set('config.factory', $factory);
    \Drupal::setContainer($container);
    markaspot_ai_update_11901();
  }

  /**
   * Existing, custom, blank and already migrated model settings.
   */
  public static function models(): iterable {
    yield ['claude-sonnet-4-20250514', TRUE];
    yield ['custom-deployment', FALSE];
    yield ['claude-sonnet-4-6', FALSE];
    yield ['', FALSE];
    yield [NULL, FALSE];
    yield [' claude-sonnet-4-20250514', FALSE];
  }

}
