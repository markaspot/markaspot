<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Utility\Token;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\ResubmissionRequestBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 *
 */
#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\ResubmissionRequestBuilder::class)]
#[Group('markaspot_mail')]
final class ResubmissionRequestBuilderTest extends UnitTestCase {

  /**
   *
   */
  public function testGetTypeReturnsEcaResubmission(): void {
    $this->assertSame(MailType::ECA_RESUBMISSION, $this->buildBuilder()->getType());
  }

  /**
   *
   */
  public function testSupportsOnlyResubmitRequest(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_resubmission', 'resubmit_request'));
    $this->assertFalse($builder->supports('markaspot_resubmission', 'other'));
    $this->assertFalse($builder->supports('other', 'resubmit_request'));
  }

  /**
   *
   */
  public function testBuildReturnsNullWhenNodeMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(logger: $logger);

    $ctx = new MailContext(
      module: 'markaspot_resubmission',
      key: 'resubmit_request',
      langcode: 'en',
      params: [],
      to: 'citizen@example.com',
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   *
   */
  public function testBuildFallsBackToHardcodedCopyWhenConfigMissing(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(FALSE);

    $builder = $this->buildBuilder();
    $ctx = new MailContext(
      module: 'markaspot_resubmission',
      key: 'resubmit_request',
      langcode: 'en',
      params: ['node' => $node],
      to: 'citizen@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertSame('platform', $msg->mode);
    $this->assertStringContainsString('needs more information', $msg->subject);
    $this->assertSame('Please clarify your report', $msg->content['headline']);
  }

  /**
   *
   */
  public function testBuildAppliesConfigTemplateViaTokenReplace(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(FALSE);

    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolveField')->willReturnCallback(
      fn (string $configName, string $key, string $field, string $langcode): string => match ($field) {
        'subject' => 'Please clarify [node:title]',
        'body' => "Hello,\n\nPlease clarify the report [node:request_id].",
        default => '',
      },
    );

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnCallback(
      fn (string $tmpl): string => str_replace(
        ['[node:title]', '[node:request_id]'],
        ['Pothole on Main St', '42-2026'],
        $tmpl,
      ),
    );

    $builder = $this->buildBuilder(
      textResolver: $textResolver,
      token: $token,
    );
    $ctx = new MailContext(
      module: 'markaspot_resubmission',
      key: 'resubmit_request',
      langcode: 'en',
      params: ['node' => $node],
      to: 'citizen@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('Please clarify Pothole on Main St', $msg->subject);
    $this->assertSame('Hello,', (string) $msg->content['intro']);
    $this->assertSame(['Please clarify the report 42-2026.'], array_map('strval', $msg->content['body_blocks']));
  }

  /**
   * Builds the subject with optional dep injection.
   */
  private function buildBuilder(
    ?MailTextResolver $textResolver = NULL,
    ?Token $token = NULL,
    ?LoggerInterface $logger = NULL,
  ): ResubmissionRequestBuilder {
    if ($textResolver === NULL) {
      $textResolver = $this->createMock(MailTextResolver::class);
      $textResolver->method('resolveField')->willReturn('');
    }
    if ($token === NULL) {
      $token = $this->createMock(Token::class);
      $token->method('replace')->willReturnCallback(fn (string $t): string => $t);
    }
    $builder = new ResubmissionRequestBuilder(
      $textResolver,
      $token,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    $builder->setStringTranslation($this->getStringTranslationStub());
    return $builder;
  }

}
