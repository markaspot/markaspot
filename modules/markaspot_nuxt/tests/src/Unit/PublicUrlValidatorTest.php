<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\markaspot_nuxt\Service\PublicUrlValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests public URL validation and generated URL rebasing.
 */
#[CoversClass(PublicUrlValidator::class)]
#[Group('markaspot_nuxt')]
final class PublicUrlValidatorTest extends UnitTestCase {

  /**
   * Validator under test.
   */
  private PublicUrlValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->validator = new PublicUrlValidator();
  }

  /**
   * Public DNS and globally routable IP targets remain valid.
   */
  public function testAcceptsPublicTargets(): void {
    $this->assertTrue($this->validator->isPublicHttpUrl('https://management.example.com/logo.png'));
    $this->assertTrue($this->validator->isPublicHttpUrl('https://management.example.com:8443/logo.png'));
    $this->assertTrue($this->validator->isPublicHttpUrl('https://8.8.8.8/logo.png'));
    $this->assertTrue($this->validator->isPublicHttpUrl('https://[2606:4700:4700::1111]/logo.png'));
  }

  /**
   * Internal, reserved and alternative numeric hosts are rejected.
   */
  public function testRejectsNonPublicTargets(): void {
    $urls = [
      'http://127.0.0.1:8080/logo.png',
      'http://127.1/logo.png',
      'http://127.0.0.1./logo.png',
      'http://0177.0.0.1/logo.png',
      'http://10.0.0.1./logo.png',
      'http://100.64.0.1/logo.png',
      'http://192.0.2.1/logo.png',
      'http://224.0.0.1/logo.png',
      'http://[::1]:8080/logo.png',
      'http://[::ffff:127.0.0.1]/logo.png',
      'http://[2001:db8::1]/logo.png',
      'http://[100::1]/logo.png',
      'http://[64:ff9b::7f00:1]/logo.png',
      'http://[64:ff9b:1::1]/logo.png',
      'http://[2001:2::1]/logo.png',
      'http://[2001:10::1]/logo.png',
      'http://[2001:20::1]/logo.png',
      'http://[2002:7f00:1::]/logo.png',
      'http://[3fff::1]/logo.png',
      'http://[fec0::1]/logo.png',
      'http://192.88.99.1/logo.png',
      'http://host.docker.internal/logo.png',
      'http://service.default.svc.cluster.local/logo.png',
      'http://service.default.svc/logo.png',
      'http://host.example.test/logo.png',
      'http://service.in-addr.arpa/logo.png',
      'http://cloud-drupal/logo.png',
    ];

    foreach ($urls as $url) {
      $this->assertFalse($this->validator->isPublicHttpUrl($url), $url);
    }
  }

  /**
   * Base URLs reject credentials, query strings and fragments.
   */
  public function testNormalizesOnlyPublicBaseUrls(): void {
    $this->assertSame(
      'https://management.example.com/base',
      $this->validator->normalizeBaseUrl('https://management.example.com/base/'),
    );
    $this->assertSame(
      'https://management.example.com',
      $this->validator->normalizeOrigin('https://management.example.com/'),
    );
    $this->assertNull($this->validator->normalizeOrigin('https://management.example.com/base'));
    $this->assertNull($this->validator->normalizeBaseUrl("https://management.example.com/'onmouseover=alert(1)"));
    $this->assertNull($this->validator->normalizeBaseUrl('https://user@example.com/base'));
    $this->assertNull($this->validator->normalizeBaseUrl('https://management.example.com/base?x=1'));
    $this->assertNull($this->validator->normalizeBaseUrl('https://management.example.com/base#fragment'));
    $this->assertNull($this->validator->normalizeBaseUrl('http://127.0.0.1:8080'));
    $this->assertNull($this->validator->normalizeBaseUrl('https://management.example.com:99999'));
  }

  /**
   * Internal URLs from tokens and assets are rebased in one pass.
   */
  public function testRewritesInternalGeneratedUrls(): void {
    $input = implode("\n", [
      '<img src="http://127.0.0.1:8080/sites/default/files/logo.png">',
      'Node: http://127.1/node/42',
      'Token:http://127.0.0.1:8080/node/43',
      'File: //service.default.svc.cluster.local/sites/default/files/photo.jpg',
      'Public: https://wb-duisburg.de/info/datenschutz.php',
    ]);

    $output = $this->validator->rewriteNonPublicHttpUrls(
      $input,
      'https://management.wbd.test.mark-a-spot.com',
    );

    $this->assertStringContainsString('https://management.wbd.test.mark-a-spot.com/sites/default/files/logo.png', $output);
    $this->assertStringContainsString('https://management.wbd.test.mark-a-spot.com/node/42', $output);
    $this->assertStringContainsString('Token:https://management.wbd.test.mark-a-spot.com/node/43', $output);
    $this->assertStringContainsString('https://management.wbd.test.mark-a-spot.com/sites/default/files/photo.jpg', $output);
    $this->assertStringContainsString('https://wb-duisburg.de/info/datenschutz.php', $output);
    $this->assertStringNotContainsString('127.', $output);
    $this->assertStringNotContainsString('cluster.local', $output);
  }

  /**
   * Public protocol-relative URLs gain an explicit HTTPS scheme.
   */
  public function testKeepsPublicProtocolRelativeUrls(): void {
    $this->assertSame(
      'https://cdn.example.com/logo.png',
      $this->validator->rebaseGeneratedUrl(
        '//cdn.example.com/logo.png',
        'https://management.example.com',
      ),
    );
  }

  /**
   * Missing public configuration removes the internal authority.
   */
  public function testFallsBackToRootRelativePathWithoutPublicBase(): void {
    $this->assertSame(
      '/sites/default/files/logo.png',
      $this->validator->rebaseGeneratedUrl('http://127.0.0.1:8080/sites/default/files/logo.png', ''),
    );
  }

  /**
   * Public CDN URLs, including signed parameters, remain byte-identical.
   */
  public function testPreservesPublicAbsoluteUrl(): void {
    $url = 'https://cdn.example.com:8443/logo.png?X-Amz-Signature=abc123#asset';
    $this->assertSame(
      $url,
      $this->validator->rebaseGeneratedUrl($url, 'https://management.wbd.test.mark-a-spot.com'),
    );
  }

  /**
   * A request-derived public-looking host is treated as local, not trusted.
   */
  public function testRewritesRequestHostToConfiguredOrigin(): void {
    $this->assertSame(
      '<a href="https://management.example.com/reset?token=secret">reset</a>',
      $this->validator->rewriteNonPublicHttpUrls(
        '<a href="https://attacker.example.net/reset?token=secret">reset</a>',
        'https://management.example.com',
        ['attacker.example.net'],
      ),
    );
    $this->assertSame(
      '<a href="/reset?token=secret">reset</a>',
      $this->validator->rewriteNonPublicHttpUrls(
        '<a href="https://attacker.example.net/reset?token=secret">reset</a>',
        '',
        ['attacker.example.net'],
      ),
    );
  }

  /**
   * Invalid path-bearing origins cannot inject markup during replacement.
   */
  public function testRejectsHtmlBreakingReplacementBase(): void {
    $output = $this->validator->rewriteNonPublicHttpUrls(
      "<a href='http://127.0.0.1:8080/node/42'>Open</a>",
      "https://management.example.com/'onmouseover=alert(1)",
    );

    $this->assertSame("<a href='/node/42'>Open</a>", $output);
    $this->assertStringNotContainsString('onmouseover', $output);
  }

  /**
   * Log-safe URL output never contains path, query or fragment secrets.
   */
  public function testRedactsUrlForLog(): void {
    $this->assertSame(
      'http://127.0.0.1:8080/[redacted]',
      $this->validator->redactForLog('http://127.0.0.1:8080/reset/token-in-path?token=secret#fragment'),
    );
  }

}
