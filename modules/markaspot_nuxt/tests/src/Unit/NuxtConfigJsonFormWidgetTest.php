<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\markaspot_nuxt\Plugin\Field\FieldWidget\NuxtConfigJsonFormWidget;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests preservation when schema form values are merged into Nuxt config.
 */
#[CoversClass(NuxtConfigJsonFormWidget::class)]
#[Group('markaspot_nuxt')]
final class NuxtConfigJsonFormWidgetTest extends UnitTestCase {

  /**
   * Tests that an unknown top-level key remains unchanged.
   */
  public function testUnknownTopLevelKeyIsPreserved(): void {
    $existing = [
      'sso' => ['providers' => [['id' => 'oidc']]],
      'client' => ['name' => 'Old name'],
    ];
    $form_data = ['client' => ['name' => 'New name']];

    $merged = NuxtConfigJsonFormWidget::mergePreservingUnknownKeys($existing, $form_data, $this->schema());

    $this->assertSame($existing['sso'], $merged['sso']);
  }

  /**
   * Tests that an unknown nested feature flag remains unchanged.
   */
  public function testUnknownFeatureFlagIsPreserved(): void {
    $existing = [
      'features' => [
        'dashboard' => TRUE,
        'aiDuplicates' => FALSE,
      ],
    ];
    $form_data = ['features' => ['dashboard' => TRUE]];

    $merged = NuxtConfigJsonFormWidget::mergePreservingUnknownKeys($existing, $form_data, $this->schema());

    $this->assertArrayHasKey('aiDuplicates', $merged['features']);
    $this->assertFalse($merged['features']['aiDuplicates']);
  }

  /**
   * Tests that an existing explicit FALSE remains persisted.
   */
  public function testExistingFalseRemainsFalse(): void {
    $existing = ['features' => ['dashboard' => FALSE]];
    $form_data = ['features' => ['dashboard' => FALSE]];

    $merged = NuxtConfigJsonFormWidget::mergePreservingUnknownKeys($existing, $form_data, $this->schema());

    $this->assertArrayHasKey('dashboard', $merged['features']);
    $this->assertFalse($merged['features']['dashboard']);
  }

  /**
   * Tests that changing TRUE to FALSE persists the FALSE value.
   */
  public function testTrueToFalseChangeIsPersisted(): void {
    $existing = ['features' => ['dashboard' => TRUE]];
    $form_data = ['features' => ['dashboard' => FALSE]];

    $merged = NuxtConfigJsonFormWidget::mergePreservingUnknownKeys($existing, $form_data, $this->schema());

    $this->assertArrayHasKey('dashboard', $merged['features']);
    $this->assertFalse($merged['features']['dashboard']);
  }

  /**
   * Tests that FALSE does not materialize an absent boolean key.
   */
  public function testAbsentBooleanRemainsAbsentWhenSubmittedFalse(): void {
    $form_data = ['features' => ['dashboard' => FALSE]];

    $merged = NuxtConfigJsonFormWidget::mergePreservingUnknownKeys([], $form_data, $this->schema());

    $this->assertArrayNotHasKey('features', $merged);
  }

  /**
   * Tests that an emptied known text value removes the existing key.
   */
  public function testEmptyTextRemovesKnownKey(): void {
    $existing = [
      'client' => [
        'name' => 'Old name',
        'unknownClientOption' => 'preserved',
      ],
    ];
    $form_data = ['client' => ['name' => '']];

    $merged = NuxtConfigJsonFormWidget::mergePreservingUnknownKeys($existing, $form_data, $this->schema());

    $this->assertArrayNotHasKey('name', $merged['client']);
    $this->assertSame('preserved', $merged['client']['unknownClientOption']);
  }

  /**
   * Tests that submitted known values replace existing values.
   */
  public function testKnownValuesOverwriteExistingValues(): void {
    $existing = [
      'client' => ['name' => 'Old name'],
      'features' => ['dashboard' => FALSE],
    ];
    $form_data = [
      'client' => ['name' => 'New name'],
      'features' => ['dashboard' => TRUE],
    ];

    $merged = NuxtConfigJsonFormWidget::mergePreservingUnknownKeys($existing, $form_data, $this->schema());

    $this->assertSame('New name', $merged['client']['name']);
    $this->assertTrue($merged['features']['dashboard']);
  }

  /**
   * Tests that a non-rendered additional-properties map remains unchanged.
   */
  public function testNonRenderedAdditionalPropertiesArePreserved(): void {
    $schema = (object) [
      'type' => 'object',
      'properties' => new \stdClass(),
      'additionalProperties' => (object) ['type' => 'boolean'],
    ];
    $existing = [
      'subject' => TRUE,
      'changed' => FALSE,
    ];

    $merged = NuxtConfigJsonFormWidget::mergePreservingUnknownKeys($existing, [], $schema);

    $this->assertSame($existing, $merged);
  }

  /**
   * Tests that a non-scalar boolean artifact never overwrites existing data.
   *
   * The contrib form builder does not render boolean properties; its value
   * handler yields an empty array for them. That artifact must not flip an
   * existing TRUE flag to FALSE.
   */
  public function testNonScalarBooleanSubmissionPreservesExistingValue(): void {
    $existing = ['features' => ['dashboard' => TRUE]];
    $form_data = ['features' => ['dashboard' => []]];

    $merged = NuxtConfigJsonFormWidget::mergePreservingUnknownKeys($existing, $form_data, $this->schema());

    $this->assertTrue($merged['features']['dashboard']);
  }

  /**
   * Tests that a checkbox-style integer submission persists a new TRUE flag.
   */
  public function testIntegerCheckboxValuePersistsNewTrueFlag(): void {
    $form_data = ['features' => ['dashboard' => 1]];

    $merged = NuxtConfigJsonFormWidget::mergePreservingUnknownKeys([], $form_data, $this->schema());

    $this->assertTrue($merged['features']['dashboard']);
  }

  /**
   * Tests that a rendered dynamic-property editor is authoritative.
   */
  public function testSubmittedAdditionalPropertiesReplaceDynamicKeys(): void {
    $schema = $this->conditionalFieldsSchema();
    $existing = [
      'field_priority' => ['categories' => [1, 2]],
      'field_old' => ['categories' => [9]],
    ];
    $form_data = [
      '__markaspot_additional_properties' => [
        'field_priority' => ['categories' => [1, 2, 3]],
      ],
    ];

    $merged = NuxtConfigJsonFormWidget::mergePreservingUnknownKeys($existing, $form_data, $schema);

    $this->assertSame([1, 2, 3], $merged['field_priority']['categories']);
    $this->assertArrayNotHasKey('field_old', $merged);
  }

  /**
   * Tests that dynamic keys survive when no editor was rendered.
   */
  public function testDynamicKeysPreservedWithoutEditorMarker(): void {
    $schema = $this->conditionalFieldsSchema();
    $existing = ['field_priority' => ['categories' => [1]]];

    $merged = NuxtConfigJsonFormWidget::mergePreservingUnknownKeys($existing, [], $schema);

    $this->assertSame($existing, $merged);
  }

  /**
   * Tests that an empty submitted object keeps a compact scalar original.
   */
  public function testEmptySubmittedObjectPreservesScalarOriginal(): void {
    $schema = (object) [
      'type' => 'object',
      'properties' => (object) [
        'formFirst' => (object) [
          'type' => 'object',
          'properties' => (object) [
            'mobileLayout' => (object) ['type' => 'string'],
          ],
        ],
      ],
    ];
    $existing = ['formFirst' => FALSE];
    $form_data = ['formFirst' => ['mobileLayout' => '']];

    $merged = NuxtConfigJsonFormWidget::mergePreservingUnknownKeys($existing, $form_data, $schema);

    $this->assertFalse($merged['formFirst']);
  }

  /**
   * Tests that oneOf-only nodes are pinned to their object branch.
   */
  public function testFlattenOneOfNodesPinsObjectBranch(): void {
    $schema = (object) [
      'type' => 'object',
      'properties' => (object) [
        'features' => (object) [
          'type' => 'object',
          'properties' => (object) [
            'formFirst' => (object) [
              'oneOf' => [
                (object) ['type' => 'boolean'],
                (object) [
                  'type' => 'object',
                  'properties' => (object) ['mobileLayout' => (object) ['type' => 'string']],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    NuxtConfigJsonFormWidget::flattenOneOfNodes($schema);

    $flattened = $schema->properties->features->properties->formFirst;
    $this->assertSame('object', $flattened->type);
    $this->assertTrue(isset($flattened->properties->mobileLayout));
    $this->assertFalse(isset($flattened->oneOf));
  }

  /**
   * Builds a schema with a dynamic-property (additionalProperties) section.
   */
  private function conditionalFieldsSchema(): object {
    return (object) [
      'type' => 'object',
      'properties' => new \stdClass(),
      'additionalProperties' => (object) [
        'type' => 'object',
        'properties' => (object) [
          'categories' => (object) ['type' => 'array'],
        ],
      ],
    ];
  }

  /**
   * Returns a minimal schema used by the merge tests.
   */
  private function schema(): object {
    return (object) [
      'type' => 'object',
      'properties' => (object) [
        'client' => (object) [
          'type' => 'object',
          'properties' => (object) [
            'name' => (object) ['type' => 'string'],
          ],
        ],
        'features' => (object) [
          'type' => 'object',
          'properties' => (object) [
            'dashboard' => (object) ['type' => 'boolean'],
          ],
        ],
      ],
    ];
  }

}
