<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_dashboard\Service\MaintenanceBrandingResolver;
use Drupal\Tests\UnitTestCase;

require_once dirname(__DIR__, 3) . '/src/Service/MaintenanceBrandingResolverInterface.php';
require_once dirname(__DIR__, 3) . '/src/Service/MaintenanceBrandingResolver.php';

/**
 * @coversDefaultClass \Drupal\markaspot_dashboard\Service\MaintenanceBrandingResolver
 * @group markaspot_dashboard
 */
final class MaintenanceBrandingResolverTest extends UnitTestCase {

  /**
   * @covers ::resolve
   */
  public function testNumericJurisdictionReturnsPublicBrandingOnly(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('label')->willReturn('Fallback label');
    $group->method('hasField')->willReturnCallback(
      static fn(string $name): bool => in_array($name, [
        'field_nuxt_config',
        'field_logo_light',
        'field_logo_dark',
      ], TRUE),
    );

    $nuxtConfig = $this->fieldList('value', json_encode([
      'client' => ['name' => 'Mängelmelder'],
      'languages' => ['default' => 'de'],
    ], JSON_THROW_ON_ERROR));
    $lightFile = $this->createMock(FileInterface::class);
    $lightFile->method('getFileUri')->willReturn('public://logos/light.svg');
    $darkFile = $this->createMock(FileInterface::class);
    $darkFile->method('getFileUri')->willReturn('private://logos/dark.svg');
    $light = $this->fieldList('entity', $lightFile);
    $dark = $this->fieldList('entity', $darkFile);
    $group->method('get')->willReturnCallback(
      static fn(string $name): FieldItemListInterface => match ($name) {
        'field_nuxt_config' => $nuxtConfig,
        'field_logo_light' => $light,
        'field_logo_dark' => $dark,
      },
    );

    $resolver = $this->resolver([1], $group);

    self::assertSame([
      'tenantName' => 'Mängelmelder',
      'logoLight' => '/' . trim(PublicStream::basePath(), '/') . '/logos/light.svg',
      'logoDark' => '',
      'defaultLocale' => 'de',
    ], $resolver->resolve('1'));
  }

  /**
   * @covers ::resolve
   */
  public function testNuxtConfigProvidesPublicSvgFallbacks(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('label')->willReturn('Fallback label');
    $group->method('hasField')->willReturnCallback(
      static fn(string $name): bool => in_array($name, [
        'field_nuxt_config',
        'field_logo_light',
        'field_logo_dark',
      ], TRUE),
    );

    $nuxtConfig = $this->fieldList('value', json_encode([
      'client' => ['name' => 'WBD-Mängelmelder'],
      'theme' => [
        'logos' => [
          'light' => '/sites/default/files/logos/wbd-logo-light.svg',
          'dark' => 'public://logos/wbd-logo-dark.svg',
        ],
      ],
      'languages' => ['default' => 'de'],
    ], JSON_THROW_ON_ERROR));
    $empty = $this->emptyFieldList();
    $group->method('get')->willReturnCallback(
      static fn(string $name): FieldItemListInterface => match ($name) {
        'field_nuxt_config' => $nuxtConfig,
        'field_logo_light', 'field_logo_dark' => $empty,
      },
    );

    self::assertSame([
      'tenantName' => 'WBD-Mängelmelder',
      'logoLight' => '/sites/default/files/logos/wbd-logo-light.svg',
      'logoDark' => '/sites/default/files/logos/wbd-logo-dark.svg',
      'defaultLocale' => 'de',
    ], $this->resolver([1], $group)->resolve('1'));
  }

  /**
   * @covers ::resolve
   */
  public function testNuxtConfigRejectsExternalAndTraversalLogoPaths(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('label')->willReturn('WBD-Mängelmelder');
    $group->method('hasField')->willReturnCallback(
      static fn(string $name): bool => in_array($name, [
        'field_nuxt_config',
        'field_logo_light',
        'field_logo_dark',
      ], TRUE),
    );

    $nuxtConfig = $this->fieldList('value', json_encode([
      'theme' => [
        'logos' => [
          'light' => 'https://example.com/logo.svg',
          'dark' => '/sites/default/files/../private/logo.svg',
        ],
      ],
    ], JSON_THROW_ON_ERROR));
    $empty = $this->emptyFieldList();
    $group->method('get')->willReturnCallback(
      static fn(string $name): FieldItemListInterface => match ($name) {
        'field_nuxt_config' => $nuxtConfig,
        'field_logo_light', 'field_logo_dark' => $empty,
      },
    );

    self::assertSame([
      'tenantName' => 'WBD-Mängelmelder',
      'logoLight' => '',
      'logoDark' => '',
      'defaultLocale' => 'de',
    ], $this->resolver([1], $group)->resolve('1'));
  }

  /**
   * @covers ::resolve
   */
  public function testNuxtConfigRejectsEncodedLogoPaths(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('label')->willReturn('WBD-Mängelmelder');
    $group->method('hasField')->willReturnCallback(
      static fn(string $name): bool => in_array($name, [
        'field_nuxt_config',
        'field_logo_light',
        'field_logo_dark',
      ], TRUE),
    );

    $nuxtConfig = $this->fieldList('value', json_encode([
      'theme' => [
        'logos' => [
          'light' => '/sites/default/files/%252e%252e/private/logo.svg',
          'dark' => '/sites/default/files/logos/logo%00.svg',
        ],
      ],
    ], JSON_THROW_ON_ERROR));
    $empty = $this->emptyFieldList();
    $group->method('get')->willReturnCallback(
      static fn(string $name): FieldItemListInterface => match ($name) {
        'field_nuxt_config' => $nuxtConfig,
        'field_logo_light', 'field_logo_dark' => $empty,
      },
    );

    self::assertSame([
      'tenantName' => 'WBD-Mängelmelder',
      'logoLight' => '',
      'logoDark' => '',
      'defaultLocale' => 'de',
    ], $this->resolver([1], $group)->resolve('1'));
  }

  /**
   * @covers ::resolve
   */
  public function testAmbiguousFallbackReturnsNoBranding(): void {
    $resolver = $this->resolver([1, 2]);

    self::assertNull($resolver->resolve());
  }

  /**
   * Builds the resolver around a deterministic entity query result.
   *
   * @param array<int, int> $ids
   *   Published jurisdiction ids.
   * @param \Drupal\group\Entity\GroupInterface|null $group
   *   Optional resolved jurisdiction.
   */
  private function resolver(array $ids, ?GroupInterface $group = NULL): MaintenanceBrandingResolver {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->with(0, 2)->willReturnSelf();
    $query->method('execute')->willReturn($ids);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    if ($group) {
      $storage->method('load')->willReturn($group);
    }
    else {
      $storage->expects($this->never())->method('load');
    }

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('group')->willReturn($storage);

    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')->with('jurisdiction_group_type')->willReturn('jur');
    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')->with('default_langcode')->willReturn('de');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(
      static fn(string $name): ImmutableConfig => match ($name) {
        'markaspot_open311.settings' => $open311Config,
        'system.site' => $siteConfig,
      },
    );

    return new MaintenanceBrandingResolver(
      $entityTypeManager,
      $configFactory,
    );
  }

  /**
   * Creates a non-empty field list exposing its first item property.
   */
  private function fieldList(string $property, mixed $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->willReturnCallback(
      static fn(string $name): mixed => $name === $property ? $value : NULL,
    );
    return $field;
  }

  /**
   * Creates an empty field list.
   */
  private function emptyFieldList(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    return $field;
  }

}
