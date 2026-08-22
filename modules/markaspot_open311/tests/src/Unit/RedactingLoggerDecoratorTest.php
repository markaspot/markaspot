<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\markaspot_open311\Logger\LogRedactor;
use Drupal\markaspot_open311\Logger\RedactingLoggerDecorator;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 3) . '/src/Logger/LogRedactor.php';
require_once dirname(__DIR__, 3) . '/src/Logger/RedactingLoggerDecorator.php';

/**
 * Tests the redacting logger decorator.
 *
 * @group markaspot_open311
 * @coversDefaultClass \Drupal\markaspot_open311\Logger\RedactingLoggerDecorator
 */
final class RedactingLoggerDecoratorTest extends UnitTestCase {

  /**
   * Tests sensitive message and context values are redacted before forwarding.
   *
   * @covers ::log
   */
  public function testLogRedactsSensitiveValues(): void {
    $inner = $this->createMock(LoggerInterface::class);
    $inner->expects($this->once())
      ->method('log')
      ->with(
        'notice',
        'Failed api_key=[REDACTED]',
        $this->callback(function (array $context): bool {
          $this->assertSame('/path?api_key=[REDACTED]', $context['request_uri']);
          $this->assertSame('/source?TOKEN=[REDACTED]', $context['referer']);
          $this->assertSame('Authorization: Bearer [REDACTED]', $context['@message']);
          $this->assertSame('Cookie: [REDACTED]', $context['%message']);
          $this->assertSame('/path?access_token=[REDACTED]', $context['@url']);
          $this->assertSame('/path?api-key=[REDACTED]', $context['@uri']);
          $this->assertSame('/path?key=[REDACTED]', $context['link']);
          $this->assertSame(['leave' => 'unchanged'], $context['extra']);
          return TRUE;
        }),
      );

    $decorator = new RedactingLoggerDecorator($inner, new LogRedactor());
    $decorator->log('notice', 'Failed api_key=message-secret', [
      'request_uri' => '/path?api_key=request-secret',
      'referer' => '/source?TOKEN=referer-secret',
      '@message' => 'Authorization: Bearer bearer-secret',
      '%message' => 'Cookie: session=cookie-secret',
      '@url' => '/path?access_token=url-secret',
      '@uri' => '/path?api-key=uri-secret',
      'link' => '/path?key=link-secret',
      'extra' => ['leave' => 'unchanged'],
    ]);
  }

  /**
   * Tests non-sensitive Stringable messages are forwarded unchanged.
   *
   * @covers ::log
   */
  public function testLogPreservesUnchangedStringableMessage(): void {
    $message = new class implements \Stringable {

      /**
       * {@inheritdoc}
       */
      public function __toString(): string {
        return 'Safe message';
      }

    };

    $inner = $this->createMock(LoggerInterface::class);
    $inner->expects($this->once())
      ->method('log')
      ->with('info', $this->identicalTo($message), ['safe' => 'context']);

    (new RedactingLoggerDecorator($inner, new LogRedactor()))
      ->log('info', $message, ['safe' => 'context']);
  }

  /**
   * Tests PSR-3 placeholder values and Stringable URLs are redacted.
   *
   * @covers ::log
   */
  public function testLogRedactsPsrPlaceholderContext(): void {
    $url = new class implements \Stringable {

      /**
       * {@inheritdoc}
       */
      public function __toString(): string {
        return '/path?api_key=psr-secret';
      }

    };

    $inner = $this->createMock(LoggerInterface::class);
    $inner->expects($this->once())
      ->method('log')
      ->with(
        'error',
        'Failed {url}: {message} {error} @error',
        $this->callback(function (array $context): bool {
          $this->assertSame('/path?api_key=[REDACTED]', $context['url']);
          $this->assertSame('/source?token=[REDACTED]', $context['uri']);
          $this->assertSame('Authorization: Bearer [REDACTED]', $context['message']);
          $this->assertSame('api_key=[REDACTED]', $context['@error']);
          $this->assertSame('api_key=[REDACTED]', $context['error']);
          return TRUE;
        }),
      );

    (new RedactingLoggerDecorator($inner, new LogRedactor()))
      ->log('error', 'Failed {url}: {message} {error} @error', [
        'url' => $url,
        'uri' => '/source?token=uri-secret',
        'message' => 'Authorization: Bearer bearer-secret',
        '@error' => 'api_key=drupal-error-secret',
        'error' => 'api_key=psr-error-secret',
      ]);
  }

}
