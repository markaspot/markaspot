<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\markaspot_nuxt\Service\FrontendUrlService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require_once dirname(__DIR__, 3) . '/markaspot_nuxt.tokens.inc';

/**
 * @covers ::markaspot_nuxt_tokens
 * @covers ::_markaspot_nuxt_get_frontend_media_url
 * @group markaspot_nuxt
 */
final class MarkaspotNuxtTokensTest extends UnitTestCase {

  /**
   * @covers ::markaspot_nuxt_tokens
   */
  public function testFrontendBaseTokenReturnsEmptyWithoutPublicFrontend(): void {
    $this->setContainerWithFrontendUrl(NULL);

    $replacements = markaspot_nuxt_tokens(
      'markaspot_frontend',
      ['url' => '[markaspot_frontend:url]'],
      [],
      [],
      new BubbleableMetadata()
    );

    $this->assertSame(['[markaspot_frontend:url]' => ''], $replacements);
  }

  /**
   * @covers ::markaspot_nuxt_tokens
   */
  public function testRequestTokenReturnsEmptyWithoutPublicFrontend(): void {
    $this->setContainerWithFrontendUrl(NULL);
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');

    $replacements = markaspot_nuxt_tokens(
      'node',
      ['markaspot_frontend_url' => '[node:markaspot_frontend_url]'],
      ['node' => $node],
      [],
      new BubbleableMetadata()
    );

    $this->assertSame(['[node:markaspot_frontend_url]' => ''], $replacements);
  }

  /**
   * @covers ::markaspot_nuxt_tokens
   */
  public function testRequestTokenUsesNotificationFrontendBase(): void {
    $this->setContainerWithFrontendUrls('https://generic.example', NULL);
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');

    $replacements = markaspot_nuxt_tokens(
      'node',
      ['markaspot_frontend_url' => '[node:markaspot_frontend_url]'],
      ['node' => $node],
      [],
      new BubbleableMetadata()
    );

    $this->assertSame(['[node:markaspot_frontend_url]' => ''], $replacements);
  }

  /**
   * @covers ::_markaspot_nuxt_get_frontend_media_url
   */
  public function testMediaTokenReturnsEmptyWithoutPublicFrontend(): void {
    $this->setContainerWithFrontendUrl(NULL);
    $field = new class {

      /**
       * Simulates a populated media reference field.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')
      ->with('field_request_media')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('field_request_media')
      ->willReturn($field);

    $this->assertSame('', _markaspot_nuxt_get_frontend_media_url($node, new BubbleableMetadata()));
  }

  /**
   * Installs the minimal container needed by the token hooks.
   */
  private function setContainerWithFrontendUrl(?string $frontendUrl): void {
    $this->setContainerWithFrontendUrls($frontendUrl, $frontendUrl);
  }

  /**
   * Installs the minimal container needed by the token hooks.
   */
  private function setContainerWithFrontendUrls(?string $frontendUrl, ?string $notificationFrontendUrl): void {
    $frontendUrlService = $this->createMock(FrontendUrlService::class);
    $frontendUrlService->method('getFrontendBaseUrl')->willReturn($frontendUrl);
    $frontendUrlService->method('getNotificationFrontendBaseUrl')->willReturn($notificationFrontendUrl);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('getCacheContexts')->willReturn([]);
    $config->method('getCacheTags')->willReturn([]);
    $config->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_nuxt.settings')
      ->willReturn($config);

    $container = new ContainerBuilder();
    $container->set('markaspot_nuxt.frontend_url', $frontendUrlService);
    $container->set('config.factory', $configFactory);
    \Drupal::setContainer($container);
  }

}
