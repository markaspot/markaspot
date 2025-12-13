<?php
namespace Drupal\markaspot_language;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MarkaspotLanguageTaxonomy {
  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new YourClassName object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * Enable translation for all taxonomy vocabularies.
   */
  public function enableTranslationForAllVocabularies() {
    $vocabularies = Vocabulary::loadMultiple();
    foreach ($vocabularies as $vocabulary) {
      $vocabulary_id = $vocabulary->id();
      $config = $this->configFactory->getEditable('language.content_settings.taxonomy_term.' . $vocabulary_id);
      $config
        ->set('third_party_settings.content_translation.enabled', TRUE)
        ->set('third_party_settings.content_translation.bundle_settings.untranslatable_fields_hide', '0')
        ->set('default_langcode', 'current_interface')
        ->set('language_alterable', TRUE)
        ->save();

      // Enable translation for name and description fields
      $fieldConfig = ['name', 'description'];
      foreach ($fieldConfig as $fieldName) {
        $fieldConfig = $this->configFactory->getEditable('core.base_field_override.taxonomy_term.' . $vocabulary_id . '.' . $fieldName);
        if ($fieldConfig->get('translatable') !== NULL) {
          $fieldConfig->set('translatable', TRUE)->save();
        }
      }
    }
  }

  /**
   * Enable translation for the body field on specific node bundles.
   *
   * @param string[] $bundles
   *   Node bundles to update.
   * @param string $fieldName
   *   Field machine name to update.
   *
   * @return int
   *   The number of field configs updated.
   */
  public function enableTranslationForNodeBodyFields(array $bundles = ['page', 'boilerplate'], $fieldName = 'body') {
    $updated = 0;

    foreach ($bundles as $bundle) {
      $field = FieldConfig::loadByName('node', $bundle, $fieldName);
      if ($field && !$field->isTranslatable()) {
        $field->setTranslatable(TRUE);
        $field->save();
        $updated++;
      }
    }

    return $updated;
  }

  /**
   * Disable translation for all taxonomy vocabularies.
   */
  public function disableTranslationForAllVocabularies() {
    $vocabularies = Vocabulary::loadMultiple();
    foreach ($vocabularies as $vocabulary) {
      $vocabulary_id = $vocabulary->id();
      $config = $this->configFactory->getEditable('language.content_settings.taxonomy_term.' . $vocabulary_id);
      $config
        ->set('third_party_settings.content_translation.enabled', FALSE)
        ->set('third_party_settings.content_translation.bundle_settings.untranslatable_fields_hide', '0')
        ->set('default_langcode', 'site_default')
        ->set('language_alterable', FALSE)
        ->save();
    }
  }
}
