<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\markaspot_nuxt\Service\FrontendUrlService;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\markaspot_nuxt\Service\FrontendUrlService
 * @group markaspot_nuxt
 */
final class FrontendUrlServiceTest extends UnitTestCase {

  /**
   * Previous FRONTEND_BASE_URL environment value.
   *
   * @var string|false
   */
  private string|false $previousFrontendBaseUrl;

  /**
   * Previous MARKASPOT_MAIL_FRONTEND_BASE_URL environment value.
   *
   * @var string|false
   */
  private string|false $previousMailFrontendBaseUrl;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->previousFrontendBaseUrl = getenv('FRONTEND_BASE_URL');
    $this->previousMailFrontendBaseUrl = getenv('MARKASPOT_MAIL_FRONTEND_BASE_URL');
    putenv('FRONTEND_BASE_URL');
    putenv('MARKASPOT_MAIL_FRONTEND_BASE_URL');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->previousFrontendBaseUrl === FALSE) {
      putenv('FRONTEND_BASE_URL');
    }
    else {
      putenv('FRONTEND_BASE_URL=' . $this->previousFrontendBaseUrl);
    }
    if ($this->previousMailFrontendBaseUrl === FALSE) {
      putenv('MARKASPOT_MAIL_FRONTEND_BASE_URL');
    }
    else {
      putenv('MARKASPOT_MAIL_FRONTEND_BASE_URL=' . $this->previousMailFrontendBaseUrl);
    }
    parent::tearDown();
  }

  /**
   * @covers ::getFrontendBaseUrl
   */
  public function testReturnsNormalizedConfiguredFrontendBaseUrl(): void {
    $service = $this->service(TRUE, 'https://bonn-mobility.example/');

    $this->assertSame('https://bonn-mobility.example', $service->getFrontendBaseUrl());
  }

  /**
   * @covers ::getFrontendBaseUrl
   */
  public function testRejectsInternalConfiguredFrontendBaseUrl(): void {
    $service = $this->service(TRUE, 'http://default/');

    $this->assertNull($service->getFrontendBaseUrl());
  }

  /**
   * @covers ::getFrontendBaseUrl
   */
  public function testRejectsInternalFrontendEnvFallback(): void {
    putenv('FRONTEND_BASE_URL=http://[::1]/');
    $service = $this->service(FALSE, '');

    $this->assertNull($service->getFrontendBaseUrl());
  }

  /**
   * @covers ::getFrontendBaseUrl
   */
  public function testRejectsIpLiteralFrontendEnvFallback(): void {
    putenv('FRONTEND_BASE_URL=http://10.10.10.10/');
    $service = $this->service(FALSE, '');

    $this->assertNull($service->getFrontendBaseUrl());
  }

  /**
   * @covers ::getFrontendBaseUrl
   */
  public function testRejectsPublicIpLiteralFrontendEnvFallback(): void {
    putenv('FRONTEND_BASE_URL=http://93.184.216.34/');
    $service = $this->service(FALSE, '');

    $this->assertNull($service->getFrontendBaseUrl());
  }

  /**
   * @covers ::getFrontendBaseUrl
   */
  public function testRejectsSingleLabelFrontendEnvFallback(): void {
    putenv('FRONTEND_BASE_URL=http://cloud-drupal/');
    $service = $this->service(FALSE, '');

    $this->assertNull($service->getFrontendBaseUrl());
  }

  /**
   * @covers ::getFrontendBaseUrl
   */
  public function testRejectsTrailingDotLocalhostFrontendEnvFallback(): void {
    putenv('FRONTEND_BASE_URL=http://localhost./');
    $service = $this->service(FALSE, '');

    $this->assertNull($service->getFrontendBaseUrl());
  }

  /**
   * @covers ::getFrontendBaseUrl
   */
  public function testRejectsAlternativeIpv4FrontendEnvFallback(): void {
    putenv('FRONTEND_BASE_URL=http://0x7f.0.0.1/');
    $service = $this->service(FALSE, '');

    $this->assertNull($service->getFrontendBaseUrl());
  }

  /**
   * @covers ::getFrontendBaseUrl
   */
  public function testReturnsNormalizedFrontendEnvFallback(): void {
    putenv('FRONTEND_BASE_URL=https://bonn-mobility.example/');
    $service = $this->service(FALSE, '');

    $this->assertSame('https://bonn-mobility.example', $service->getFrontendBaseUrl());
  }

  /**
   * @covers ::getFrontendBaseUrl
   */
  public function testReturnsNormalizedMailFrontendEnvFallback(): void {
    putenv('MARKASPOT_MAIL_FRONTEND_BASE_URL=https://bonn-mobility.example/');
    $service = $this->service(FALSE, '');

    $this->assertSame('https://bonn-mobility.example', $service->getFrontendBaseUrl());
  }

  /**
   * @covers ::generateFrontendUrl
   */
  public function testGenerateFrontendUrlReturnsEmptyWithoutPublicFrontend(): void {
    $service = $this->service(FALSE, '');

    $this->assertSame('', $service->generateFrontendUrl('confirm/example', 'example.route'));
  }

  /**
   * @covers ::generateConfirmationUrl
   */
  public function testGenerateConfirmationUrlReturnsEmptyWithoutPublicFrontend(): void {
    putenv('FRONTEND_BASE_URL=http://default/');
    $service = $this->service(FALSE, '');

    $this->assertSame('', $service->generateConfirmationUrl('example-uuid'));
  }

  /**
   * Builds the service with a mocked config factory.
   */
  private function service(bool $frontendEnabled, ?string $frontendBaseUrl): FrontendUrlService {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['frontend_enabled', $frontendEnabled],
        ['frontend_base_url', $frontendBaseUrl],
      ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_nuxt.settings')
      ->willReturn($config);

    return new FrontendUrlService($configFactory);
  }

}
