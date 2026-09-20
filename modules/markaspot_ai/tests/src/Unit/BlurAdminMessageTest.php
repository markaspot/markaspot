<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\markaspot_ai\Form\MarkaspotAiSettingsForm;
use Drupal\markaspot_ai\Service\NlpClientService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\markaspot_vision\Form\MarkaspotVisionSettingsForm;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests that only admin forms explain unprotected or broken required blur.
 */
#[Group('markaspot_ai')]
class BlurAdminMessageTest extends UnitTestCase {

  /**
   * Both forms use warning/error severity and disable duplicate messages.
   */
  #[DataProvider('messages')]
  public function testAdminMessages(string $formType, ?string $required, string $url, string $key, bool $enabled, bool $visionEnabled, ?string $expected): void {
    $env = [
      'MARKASPOT_BLUR_REQUIRED' => $required,
      'MARKASPOT_BLUR_URL' => $url,
      'MARKASPOT_BLUR_API_KEY' => $key,
      'AI_API_KEY' => '',
    ];
    $previous = [];
    foreach ($env as $name => $value) {
      $previous[$name] = getenv($name);
      putenv($value === NULL ? $name : "$name=$value");
    }
    try {
      $factory = $this->getConfigFactoryStub([
        'markaspot_ai.settings' => ['providers' => ['openai' => ['api_url' => 'https://ai.example']]],
        'markaspot_vision.settings' => ['api_url' => 'https://vision.example', 'enable_blur_preprocessing' => $enabled],
      ]);
      $typed = $this->createMock(TypedConfigManagerInterface::class);
      $container = new ContainerBuilder();
      $container->set('config.factory', $factory);
      $container->set('config.typed', $typed);
      $container->set('string_translation', $this->getStringTranslationStub());
      $generator = $this->createMock(UrlGeneratorInterface::class);
      $generator->method('generateFromRoute')->with('markaspot_vision.settings')->willReturn('/admin/config/services/markaspot-snap');
      $container->set('url_generator', $generator);
      $messenger = $this->createMock(MessengerInterface::class);
      foreach (['addWarning', 'addError'] as $method) {
        $messenger->expects($expected === $method ? $this->exactly(2) : $this->never())->method($method)->with($this->callback(static function ($message) use ($method, $formType, $visionEnabled): bool {
          $text = (string) $message;
          self::assertStringContainsString($method === 'addWarning' ? 'licence plates' : 'environment URL or API key is missing', $text);
          self::assertStringNotContainsString('https://', $text);
          if ($method === 'addWarning' && $formType === 'ai' && $visionEnabled) {
            self::assertStringContainsString('/admin/config/services/markaspot-snap', $text);
          }
          return TRUE;
        }), FALSE)->willReturnSelf();
      }
      $container->set('messenger', $messenger);
      \Drupal::setContainer($container);
      if ($formType === 'vision') {
        $vision = $this->getMockBuilder(ImageProcessingService::class)->disableOriginalConstructor()->onlyMethods(['selfTest'])->getMock();
        (new \ReflectionProperty($vision, 'logger'))->setValue($vision, new NullLogger());
        $container->set('markaspot_vision.image_processing', $vision);
        $form = MarkaspotVisionSettingsForm::create($container);
      }
      else {
        $modules = $this->createMock(ModuleHandlerInterface::class);
        $modules->method('moduleExists')->with('markaspot_vision')->willReturn($visionEnabled);
        $form = new MarkaspotAiSettingsForm($factory, $typed,
          $this->createMock(NlpClientService::class),
          $this->createMock(TokenTrackingService::class),
          $this->createMock(CacheTagsInvalidatorInterface::class), new NullLogger(), $modules);
      }
      $form->buildForm([], new FormState());
      $form->buildForm([], new FormState());
    }
    finally {
      foreach ($previous as $name => $value) {
        putenv($value === FALSE ? $name : "$name=$value");
      }
    }
  }

  /**
   * Warnings for effective unprotected operation, errors for missing ENV.
   */
  public static function messages(): iterable {
    foreach (['ai', 'vision'] as $form) {
      yield [$form, NULL, '', '', FALSE, TRUE, 'addWarning'];
      yield [$form, NULL, '', '', TRUE, TRUE, NULL];
      yield [$form, '0', 'https://blur.example', '', FALSE, TRUE, NULL];
      yield [$form, '1', '', '', FALSE, TRUE, 'addError'];
      yield [$form, NULL, 'https://blur.example', '', FALSE, TRUE, 'addError'];
      yield [$form, NULL, 'https://blur.example', 'key', FALSE, TRUE, NULL];
    }
    yield ['ai', NULL, '', '', TRUE, FALSE, 'addWarning'];
  }

}
