<?php

namespace Drupal\Tests\markaspot_open311\Unit;

use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\markaspot_open311\Controller\GeoreportStatsController;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Testable wrapper for protected GeoreportStatsController helpers.
 */
class TestGeoreportStatsController extends GeoreportStatsController {

  /**
   * Injects a mock entity type manager.
   */
  public function setEntityTypeManagerForTest(EntityTypeManagerInterface $entity_type_manager): void {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Exposes translated label resolution for unit tests.
   */
  public function getTranslatedTermLabelForTest(int $tid, string $fallback, string $langcode): string {
    return $this->getTranslatedTermLabel($tid, $fallback, $langcode);
  }

}

/**
 * Tests the GeoReport stats controller.
 *
 * @group markaspot_open311
 * @coversDefaultClass \Drupal\markaspot_open311\Controller\GeoreportStatsController
 */
class GeoreportStatsControllerTest extends UnitTestCase {

  /**
   * Missing aggregate-policy wiring must exclude every report row.
   */
  public function testMissingFormOnlyPolicyFailsClosed(): void {
    $controller = new GeoreportStatsController(
      $this->createMock(Connection::class),
      new RequestStack(),
      $this->createMock(LanguageManagerInterface::class),
    );
    $method = new \ReflectionMethod($controller, 'getFormOnlySqlRestriction');
    $this->assertSame(' AND 1 = 0', $method->invoke($controller, TRUE));
  }

  /**
   * Session aggregates must never be stored in a shared cache.
   */
  public function testSessionStatsUsePrivateCachePolicy(): void {
    $controller = $this->createPartialMock(GeoreportStatsController::class, ['currentUser']);
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAnonymous')->willReturn(FALSE);
    $controller->method('currentUser')->willReturn($account);
    $response = new JsonResponse(['total' => 2]);
    $method = new \ReflectionMethod($controller, 'applyStatsCachePolicy');
    $method->invoke($controller, $response, FALSE);
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertFalse($response->headers->hasCacheControlDirective('public'));
  }

  /**
   * Tests translated taxonomy term labels are used when available.
   *
   * @covers ::getTranslatedTermLabel
   */
  public function testTranslatedTermLabelUsesRequestedTranslation(): void {
    $translated_term = $this->createMock(TermInterface::class);
    $translated_term->expects($this->once())
      ->method('label')
      ->willReturn('Kleidersammlung');

    $term = $this->createMock(TermInterface::class);
    $term->expects($this->once())
      ->method('hasTranslation')
      ->with('de')
      ->willReturn(TRUE);
    $term->expects($this->once())
      ->method('getTranslation')
      ->with('de')
      ->willReturn($translated_term);

    $controller = $this->createControllerWithTerm($term);

    $this->assertSame(
      'Kleidersammlung',
      $controller->getTranslatedTermLabelForTest(10, 'Clothing Container', 'de')
    );
  }

  /**
   * Tests untranslated terms fall back to their default label.
   *
   * @covers ::getTranslatedTermLabel
   */
  public function testTranslatedTermLabelFallsBackToDefaultTermLabel(): void {
    $term = $this->createMock(TermInterface::class);
    $term->expects($this->once())
      ->method('hasTranslation')
      ->with('pl')
      ->willReturn(FALSE);
    $term->expects($this->never())
      ->method('getTranslation');
    $term->expects($this->once())
      ->method('label')
      ->willReturn('Clothing Container');

    $controller = $this->createControllerWithTerm($term);

    $this->assertSame(
      'Clothing Container',
      $controller->getTranslatedTermLabelForTest(10, 'Clothing Container', 'pl')
    );
  }

  /**
   * Tests missing terms preserve the SQL fallback label.
   *
   * @covers ::getTranslatedTermLabel
   */
  public function testTranslatedTermLabelFallsBackWhenTermMissing(): void {
    $controller = $this->createControllerWithTerm(NULL);

    $this->assertSame(
      'Clothing Container',
      $controller->getTranslatedTermLabelForTest(10, 'Clothing Container', 'de')
    );
  }

  /**
   * Creates the controller with mocked taxonomy term storage.
   */
  private function createControllerWithTerm(?TermInterface $term): TestGeoreportStatsController {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with(10)
      ->willReturn($term);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->once())
      ->method('getStorage')
      ->with('taxonomy_term')
      ->willReturn($storage);

    $controller = new TestGeoreportStatsController(
      $this->createMock(Connection::class),
      new RequestStack(),
      $this->createLanguageManager(),
    );
    $controller->setEntityTypeManagerForTest($entity_type_manager);

    return $controller;
  }

  /**
   * Creates a language manager mock for controller construction.
   */
  private function createLanguageManager(): LanguageManagerInterface {
    $default_language = $this->createMock(LanguageInterface::class);
    $default_language->method('getId')->willReturn('en');

    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->method('getDefaultLanguage')->willReturn($default_language);
    $language_manager->method('getLanguages')->willReturn(['en' => $default_language]);

    return $language_manager;
  }

}
