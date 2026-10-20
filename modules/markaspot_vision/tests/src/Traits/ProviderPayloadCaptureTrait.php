<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Traits;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\taxonomy\TermInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs processImages() offline and captures the exact provider request.
 *
 * Everything the service reads is a test double built from a case array, so
 * the captured body depends only on the service code and the shipped
 * markaspot_vision.settings. Shared by the golden payload tests of this module
 * and of modules that implement hook_markaspot_vision_prompt_alter().
 */
trait ProviderPayloadCaptureTrait {

  /**
   * A 1x1 PNG; its bytes and detected MIME type never change.
   */
  protected static string $goldenPixel = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII=';

  /**
   * Returns the shipped vision settings with offline API values.
   */
  protected static function shippedVisionSettings(array $overrides = []): array {
    $path = dirname(__DIR__, 3) . '/config/install/markaspot_vision.settings.yml';
    $settings = Yaml::decode((string) file_get_contents($path));
    return array_merge($settings, [
      'api_key' => 'golden-key',
      'api_url' => 'https://vision.invalid/v1/chat/completions',
    ], $overrides);
  }

  /**
   * Sends one analysis through the real service and returns the raw body.
   *
   * @param array $case
   *   Keys: settings (config overrides), tenant_prompt (NULL: the group has
   *   no field_ai_system_prompt), jurisdiction_id (NULL: single install),
   *   langcode, images, preset (NULL: no wording resolver, otherwise the
   *   i18n.wording value; "report" stores no wording like provisioning),
   *   group_label, service_definitions (bool). Blur preprocessing against
   *   https://blur.invalid/... reports every image as blurred.
   * @param mixed $moduleHandler
   *   Optional module handler handed to the service after the resolver.
   *
   * @return string
   *   The JSON request body exactly as it was sent to the provider.
   */
  protected function captureProviderBody(array $case, mixed $moduleHandler = NULL): string {
    $case += [
      'settings' => [],
      'tenant_prompt' => '',
      'jurisdiction_id' => 42,
      'langcode' => 'de',
      'images' => 1,
      'preset' => 'report',
      'group_label' => 'Musterstadt',
      'service_definitions' => TRUE,
    ];
    $image = tempnam(sys_get_temp_dir(), 'vision-golden-');
    file_put_contents($image, base64_decode(self::$goldenPixel));
    try {
      $values = self::shippedVisionSettings($case['settings']);
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('get')->willReturnCallback(static fn (string $key) => $values[$key] ?? NULL);
      $configFactory = $this->createMock(ConfigFactoryInterface::class);
      $configFactory->method('get')->willReturn($config);

      $style = $this->createMock(ImageStyleInterface::class);
      $style->method('buildUri')->willReturn($image);
      $imageStorage = $this->createMock(EntityStorageInterface::class);
      $imageStorage->method('load')->willReturn($style);

      $group = $this->goldenGroup($case);
      $groupStorage = $this->createMock(EntityStorageInterface::class);
      $groupStorage->method('load')->willReturnCallback(static fn ($id) => (int) $id === (int) $case['jurisdiction_id'] ? $group : NULL);

      $properties = ['vid' => 'service_category', 'status' => 1];
      if ($case['jurisdiction_id']) {
        $properties['field_jurisdiction'] = $case['jurisdiction_id'];
      }
      $terms = $this->goldenTerms((string) $case['langcode'], (bool) $case['service_definitions']);
      $termStorage = $this->createMock(EntityStorageInterface::class);
      $termStorage->method('loadByProperties')->with($properties)->willReturn($terms);

      $entities = $this->createMock(EntityTypeManagerInterface::class);
      $entities->method('getStorage')->willReturnMap([
        ['image_style', $imageStorage],
        ['group', $groupStorage],
        ['taxonomy_term', $termStorage],
      ]);

      $body = NULL;
      $handler = function (RequestInterface $request) use (&$body, $case) {
        // The blur service answers with the same pixel, marked as blurred.
        if ($request->getUri()->getHost() === 'blur.invalid') {
          return new FulfilledPromise(new Response(200, ['X-Image-Blurred' => 'true', 'X-Detections-Faces' => '1'], base64_decode(self::$goldenPixel)));
        }
        $body = (string) $request->getBody();
        return new FulfilledPromise(new Response(200, [], json_encode(['choices' => [['message' => ['content' => json_encode([
          'category' => 71,
          'is_reportable_issue' => TRUE,
          'description' => 'x',
          'alt_text' => array_fill(0, $case['images'], 'x'),
          'hazard_flag' => FALSE,
          'hazard_issues' => [],
          'hazard_level' => 0,
          'hazard_category' => NULL,
          'privacy_flag' => FALSE,
          'privacy_issues' => [],
          'privacy_image_flags' => array_fill(0, $case['images'], FALSE),
          'attributes' => [],
        ])]]]])));
      };

      $logger = $this->createMock(LoggerInterface::class);
      $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactory->method('get')->willReturn($logger);
      $time = $this->createMock(TimeInterface::class);
      $time->method('getCurrentTime')->willReturn(1700000000);

      $arguments = [
        new Client(['handler' => $handler]),
        $configFactory,
        $entities,
        $this->createMock(FileSystemInterface::class),
        $loggerFactory,
        $this->createMock(StateInterface::class),
        $time,
        $this->createMock(FileUsageInterface::class),
        $case['preset'] === NULL ? NULL : new CitizenWordingResolver(),
      ];
      if ($moduleHandler !== NULL) {
        $arguments[] = $moduleHandler;
      }
      $service = new ImageProcessingService(...$arguments);
      $result = $service->processImages(array_fill(0, $case['images'], $image), $case['langcode'], $case['jurisdiction_id']);
      $this->assertNotNull($result, 'The analysis must reach the provider.');
      $this->assertIsString($body);
      return $body;
    }
    finally {
      unlink($image);
    }
  }

  /**
   * Builds the jurisdiction group of a case.
   */
  private function goldenGroup(array $case): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('id')->willReturn($case['jurisdiction_id']);
    $group->method('label')->willReturn($case['group_label']);
    $group->method('getUntranslated')->willReturnSelf();

    $nuxtConfig = ['client' => ['name' => $case['group_label']]];
    if ($case['preset'] !== NULL && $case['preset'] !== 'report') {
      $nuxtConfig['i18n']['wording'] = $case['preset'];
    }
    $fields = [
      'field_nuxt_config' => $this->goldenField(json_encode($nuxtConfig)),
    ];
    if ($case['tenant_prompt'] !== NULL) {
      $fields['field_ai_system_prompt'] = $this->goldenField($case['tenant_prompt']);
    }
    $group->method('hasField')->willReturnCallback(static fn (string $name) => isset($fields[$name]));
    $group->method('get')->willReturnCallback(static fn (string $name) => $fields[$name] ?? NULL);
    $group->expects($this->never())->method('set');
    $group->expects($this->never())->method('save');
    return $group;
  }

  /**
   * Builds a field item list holding one plain value.
   */
  private function goldenField(?string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === NULL || $value === '');
    $field->method('__get')->willReturnCallback(static fn (string $name) => match ($name) {
      'value' => $value,
      default => NULL,
    });
    return $field;
  }

  /**
   * Builds a small category tree: one parent with two leaves, one leaf.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   Terms keyed by ID in the order storage would return them.
   */
  private function goldenTerms(string $langcode, bool $serviceDefinitions): array {
    $labels = [
      'de' => [70 => 'Straße', 71 => 'Schlagloch', 72 => 'Gehweg "uneben"', 73 => 'Grün & Bäume'],
      'en' => [70 => 'Road', 71 => 'Pothole', 72 => 'Pavement "uneven"', 73 => 'Green & trees'],
    ][$langcode] ?? [70 => 'Road', 71 => 'Pothole', 72 => 'Pavement "uneven"', 73 => 'Green & trees'];
    $parents = [70 => NULL, 71 => 70, 72 => 70, 73 => NULL];
    $definition = json_encode(['attributes' => [
      [
        'code' => 'depth',
        'datatype' => 'singlevaluelist',
        'variable' => TRUE,
        'required' => TRUE,
        'description' => 'Depth of the hole',
        'values' => [['key' => 'shallow', 'name' => 'Shallow'], ['key' => 'deep', 'name' => 'Deep']],
      ],
      ['code' => 'note', 'datatype' => 'string', 'variable' => TRUE, 'description' => "Free\nnote"],
      ['code' => 'fixed', 'datatype' => 'string', 'variable' => FALSE],
    ]]);
    $terms = [];
    foreach ($labels as $tid => $label) {
      $parent = $this->createMock(FieldItemListInterface::class);
      // The service reads target_id with ??, which asks __isset first.
      $parent->method('__isset')->willReturnCallback(static fn (string $name) => $name === 'target_id' && $parents[$tid] !== NULL);
      $parent->method('__get')->willReturnCallback(static fn (string $name) => $name === 'target_id' ? $parents[$tid] : NULL);
      $hasDefinition = $serviceDefinitions && $tid === 71;
      $definitionField = $this->goldenField($hasDefinition ? $definition : NULL);
      $term = $this->createMock(TermInterface::class);
      $term->method('id')->willReturn($tid);
      $term->method('label')->willReturn($label);
      $term->method('getName')->willReturn($label);
      $term->method('get')->willReturnCallback(static fn (string $name) => $name === 'parent' ? $parent : $definitionField);
      $term->method('hasField')->willReturnCallback(static fn (string $name) => $name === 'field_service_definition' && $hasDefinition);
      $term->method('hasTranslation')->willReturn(TRUE);
      $term->method('getTranslation')->willReturnSelf();
      $terms[$tid] = $term;
    }
    return $terms;
  }

}
