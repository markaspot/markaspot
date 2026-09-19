<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Psr\Log\NullLogger;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\markaspot_vision\Form\MarkaspotVisionSettingsForm;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests that platform blur policy survives a forged unchecked submission.
 */
#[CoversClass(MarkaspotVisionSettingsForm::class)]
#[Group('markaspot_vision')]
class RequiredBlurFormTest extends UnitTestCase {

  /**
   * Required blur renders checked/disabled and never saves false.
   */
  #[DataProvider('requiredModes')]
  public function testEnforcedCheckboxAndSave(bool $auto): void {
    $previous = getenv('MARKASPOT_BLUR_REQUIRED');
    $url = getenv('MARKASPOT_BLUR_URL');
    putenv($auto ? 'MARKASPOT_BLUR_REQUIRED' : 'MARKASPOT_BLUR_REQUIRED=true');
    putenv('MARKASPOT_BLUR_URL=https://platform.example/private?secret=test');
    try {
      // Keep the real environment reader while avoiding unrelated dependencies.
      $vision = $this->getMockBuilder(ImageProcessingService::class)->disableOriginalConstructor()->onlyMethods(['selfTest'])->getMock();
      (new \ReflectionProperty($vision, 'logger'))->setValue($vision, new NullLogger());
      $saved = [];
      $config = $this->createMock(Config::class);
      $config->method('get')->willReturn(NULL);
      $config->method('set')->willReturnCallback(function ($key, $value) use (&$saved, $config) {
        $saved[$key] = $value;
        return $config;
      });
      $config->method('clear')->willReturnSelf();
      $config->method('save')->willReturnSelf();
      $factory = $this->createMock(ConfigFactoryInterface::class);
      $factory->method('getEditable')->willReturn($config);
      $container = new ContainerBuilder();
      $container->set('config.factory', $factory);
      $container->set('config.typed', $this->createMock(TypedConfigManagerInterface::class));
      $container->set('markaspot_vision.image_processing', $vision);
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('messenger', $this->createMock(MessengerInterface::class));
      \Drupal::setContainer($container);
      $form = MarkaspotVisionSettingsForm::create($container);
      $state = new FormState();
      $render = $form->buildForm([], $state);
      $checkbox = $render['blur']['enable_blur_preprocessing'];
      self::assertTrue($checkbox['#default_value']);
      self::assertTrue($checkbox['#disabled']);
      self::assertSame('Enforced by the hosting platform (' . ($auto ? 'auto-required' : 'strict') . ').', (string) $checkbox['#description']);
      self::assertTrue($render['blur']['blur_service_url']['#disabled']);
      self::assertSame('Set by the hosting platform', (string) $render['blur']['blur_service_url']['#default_value']);
      $state->setValue('enable_blur_preprocessing', FALSE);
      $state->setValue('blur_service_url', 'https://untrusted.example/blur');
      $form->submitForm($render, $state);
      self::assertArrayNotHasKey('enable_blur_preprocessing', $saved);
      self::assertArrayNotHasKey('blur_service_url', $saved);
    }
    finally {
      putenv($previous === FALSE ? 'MARKASPOT_BLUR_REQUIRED' : 'MARKASPOT_BLUR_REQUIRED=' . $previous);
      putenv($url === FALSE ? 'MARKASPOT_BLUR_URL' : 'MARKASPOT_BLUR_URL=' . $url);
    }
  }

  /**
   * Both required modes lock and preserve stored settings.
   */
  public static function requiredModes(): iterable {
    yield [FALSE];
    yield [TRUE];
  }

}
