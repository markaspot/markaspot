<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\markaspot_ai\Drush\Commands\MarkaspotAiCommands;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\markaspot_ai\Service\SentimentService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests synthetic-only self-test reporting and safe failures without network.
 */
#[CoversClass(MarkaspotAiCommands::class)]
#[Group('markaspot_ai')]
class SelfTestCommandTest extends UnitTestCase {

  /**
   * Tracks synthetic usage without printing URLs containing credentials.
   */
  #[DataProvider('failures')]
  public function testCommand(bool $fails, bool $mismatch = FALSE, bool $embedFails = FALSE): void {
    $previous = getenv('MARKASPOT_AI_API_URL');
    $blur_env = [
      'MARKASPOT_BLUR_REQUIRED' => getenv('MARKASPOT_BLUR_REQUIRED'),
      'MARKASPOT_BLUR_URL' => getenv('MARKASPOT_BLUR_URL'),
    ];
    putenv('MARKASPOT_BLUR_REQUIRED');
    putenv('MARKASPOT_BLUR_URL');
    putenv('MARKASPOT_AI_API_URL=https://user:secret@provider.example/v1?key=secret');
    try {
      $client = $this->createMock(AiClientService::class);
      $client->method('resolveChatModel')->willReturn('deployment');
      $client->method('resolveEmbeddingModel')->willReturn('vectors');
      $client->expects($this->exactly(2))->method('chat')->willReturnCallback(function ($messages, $options) use ($fails) {
        self::assertSame('openai', $options['provider']);
        self::assertSame(150, $options['max_tokens']);
        self::assertCount(1, $messages);
        if ($fails) {
          throw new \RuntimeException('https://user:secret@provider.example/v1?key=secret', 400);
        }
        return ['choices' => [['message' => ['content' => '{"ok":true}']]], 'usage' => ['prompt_tokens' => 5]];
      });
      $embed = $client->expects($this->once())->method('embed')->with('Synthetic compatibility test.', ['provider' => 'openai']);
      if ($embedFails) {
        $embed->willThrowException(new \RuntimeException('Provider unavailable', 503));
      }
      else {
        $embed->willReturn([
          'model' => 'vectors', 'data' => [['embedding' => [0.1, 0.2]]], 'usage' => ['prompt_tokens' => 2],
        ]);
      }
      $tracking = $this->createMock(TokenTrackingService::class);
      $tracking->expects($this->exactly(($fails ? 0 : 2) + ($embedFails ? 0 : 1)))->method('logUsage')->with(
        'openai', $this->callback('is_string'), 'selftest', $this->callback('is_int'), 0,
      );
      $config = $this->getConfigFactoryStub(['markaspot_ai.settings' => [
        'default_provider' => 'openai', 'providers' => ['openai' => ['chat_model' => 'deployment']],
      ]]);
      $embedding = $this->createMock(EmbeddingService::class);
      $stored = [['model' => 'previous', 'dimensions' => 2, 'count' => 3]];
      $embedding->method('getStoredModelSummary')->willReturn($mismatch ? $stored : []);
      $output = new BufferedOutput();
      $command = new MarkaspotAiCommands(
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(QueueFactory::class),
        $this->createMock(QueueWorkerManagerInterface::class),
        $embedding,
        $this->createMock(SentimentService::class),
        $this->createMock(Connection::class),
        NULL, $client, $tracking, $config,
        $this->createMock(ModuleHandlerInterface::class),
      );
      $command->setOutput($output);
      self::assertSame($fails || $mismatch || $embedFails ? 1 : 0, $command->selftest(['skip-vision' => TRUE]));
      $text = $output->fetch();
      self::assertStringContainsString('host=provider.example', $text);
      self::assertStringContainsString('blur mode=auto-unprotected', $text);
      self::assertStringContainsString('Notice: blur is not enforced', $text);
      if (!$embedFails) {
        self::assertStringContainsString('embedding model=vectors dimensions=2', $text);
      }
      else {
        self::assertStringContainsString('embedding: http 503', $text);
      }
      self::assertStringContainsString('chat JSON: ' . ($fails ? 'http 400: request rejected' : 'ok'), $text);
      self::assertStringNotContainsString('secret', $text);
      self::assertStringNotContainsString('/v1', $text);
      if ($mismatch) {
        self::assertStringContainsString('previous, 2, 3', $text);
        self::assertStringContainsString('embedding identity mismatch', $text);
      }
    }
    finally {
      putenv($previous === FALSE ? 'MARKASPOT_AI_API_URL' : 'MARKASPOT_AI_API_URL=' . $previous);
      foreach ($blur_env as $name => $value) {
        putenv($value === FALSE ? $name : "$name=$value");
      }
    }
  }

  /**
   * Successful checks and rejected provider requests.
   */
  public static function failures(): iterable {
    yield [FALSE];
    yield [TRUE];
    yield [FALSE, TRUE];
    yield [FALSE, TRUE, TRUE];
  }

}
