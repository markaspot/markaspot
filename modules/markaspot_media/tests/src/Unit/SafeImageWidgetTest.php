<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_media\Unit;

use Drupal\Core\Form\FormState;
use Drupal\markaspot_media\Plugin\Field\FieldWidget\SafeImageWidget;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\ConstraintViolation;

/**
 * Tests error mapping without weakening validation.
 */
#[Group('markaspot_media')]
final class SafeImageWidgetTest extends UnitTestCase {

  /**
   * Every violation targets an array and remains a form error.
   */
  #[DataProvider('paths')]
  public function testErrorElement(string $path, bool $child): void {
    $widget = (new \ReflectionClass(SafeImageWidget::class))->newInstanceWithoutConstructor();
    $element = [
      '#parents' => ['image', 0],
      '#type' => 'image',
      'alt' => ['#parents' => ['image', 0, 'alt']],
    ];
    $error = new ConstraintViolation('Access denied', '', [], NULL, $path, NULL);
    $state = new FormState();
    $target = $widget->errorElement($element, $error, [], $state);
    $this->assertSame($child ? $element['alt'] : $element, $target);
    $state->setError($target, $error->getMessage());
    $this->assertCount(1, $state->getErrors());
  }

  /**
   * Supplies whole-item, property and unexpected violation paths.
   */
  public static function paths(): array {
    return [
      'whole item' => ['0', FALSE],
      'alt property' => ['0.alt', TRUE],
      'missing child' => ['0.target_id', FALSE],
      'empty path' => ['', FALSE],
      'scalar child' => ['0.#type', FALSE],
    ];
  }

}
