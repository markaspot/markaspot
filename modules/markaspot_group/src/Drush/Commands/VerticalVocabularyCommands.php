<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\markaspot_group\Service\VerticalDefinitionRepository;
use Drupal\markaspot_group\Service\VerticalVocabularyApplier;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command for applying stack-level vertical vocabularies.
 */
class VerticalVocabularyCommands extends DrushCommands {

  /**
   * Constructs a VerticalVocabularyCommands object.
   *
   * @param \Drupal\markaspot_group\Service\VerticalDefinitionRepository $definitionRepository
   *   The shipped vertical definition repository.
   * @param \Drupal\markaspot_group\Service\VerticalVocabularyApplier $applier
   *   The vertical vocabulary applier.
   */
  public function __construct(
    protected VerticalDefinitionRepository $definitionRepository,
    protected VerticalVocabularyApplier $applier,
  ) {
    parent::__construct();
  }

  /**
   * Drush 13 auto-discovery factory.
   *
   * The collaborators are built from existing services so stale containers do
   * not prevent Drush from booting before the next cache rebuild.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      new VerticalDefinitionRepository(
        $container->get('extension.list.module'),
      ),
      new VerticalVocabularyApplier(
        $container->get('entity_type.manager'),
        $container->get('markaspot_group.hierarchy_resolver'),
        $container->get('markaspot_group.status_term_scope'),
        $container->get('config.factory'),
        $container->get('language_manager'),
        $container->get('database'),
      ),
    );
  }

  /**
   * Applies one shipped vertical vocabulary to a portfolio root.
   *
   * Entity labels and interface overrides are merged into field_nuxt_config.
   * Existing root-owned service_status terms may be renamed, but terms are
   * never created. Use --dry-run first to inspect every planned change.
   *
   * @param string $verticalId
   *   Shipped vertical definition ID.
   * @param array<string, mixed> $options
   *   Command options.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Per-layer plan or application results.
   */
  #[CLI\Command(
    name: 'markaspot:apply-vertical',
    aliases: ['mas:apply-vertical'],
  )]
  #[CLI\Help(
    description: 'Apply a shipped vertical vocabulary to a portfolio root.',
    synopsis: 'Merge interface vocabulary and conservatively rename matching root-owned service statuses.',
  )]
  #[CLI\Argument(
    name: 'verticalId',
    description: 'Vertical ID, for example municipal, hoa, or retail.',
  )]
  #[CLI\Option(
    name: 'jurisdiction',
    description: 'Required portfolio root jurisdiction group ID. Child jurisdictions are refused.',
  )]
  #[CLI\Option(
    name: 'dry-run',
    description: 'Print every change without writing group config or taxonomy terms.',
  )]
  #[CLI\Option(
    name: 'locales',
    description: 'Comma-separated locale IDs to apply. Defaults to every locale in the definition.',
  )]
  #[CLI\FieldLabels(labels: [
    'layer' => 'Layer',
    'locale' => 'Locale',
    'item' => 'Item',
    'result' => 'Result',
    'detail' => 'Detail',
  ])]
  #[CLI\Usage(
    name: 'drush mas:apply-vertical hoa --jurisdiction=10 --dry-run',
    description: 'Preview all HOA vocabulary changes for portfolio root 10.',
  )]
  #[CLI\Usage(
    name: 'drush mas:apply-vertical retail --jurisdiction=10 --locales=de --dry-run',
    description: 'Preview the proposed German retail vocabulary before sign-off.',
  )]
  public function apply(
    string $verticalId,
    array $options = [
      'jurisdiction' => NULL,
      'dry-run' => FALSE,
      'locales' => NULL,
    ],
  ): RowsOfFields {
    $jurisdiction = filter_var(
      $options['jurisdiction'] ?? NULL,
      FILTER_VALIDATE_INT,
      ['options' => ['min_range' => 1]],
    );
    if ($jurisdiction === FALSE) {
      throw new \RuntimeException(
        'The required --jurisdiction option must be a positive portfolio root group ID.',
      );
    }

    $definition = $this->definitionRepository->load($verticalId);
    $locales = $this->parseLocales($options['locales'] ?? NULL);
    $rows = $this->applier->apply(
      $definition,
      $jurisdiction,
      !empty($options['dry-run']),
      $locales,
    );

    return new RowsOfFields($rows);
  }

  /**
   * Parses a comma-separated locale option.
   *
   * @param mixed $option
   *   Raw Drush option value.
   *
   * @return string[]|null
   *   Unique locale IDs, or NULL when no filter was given.
   */
  protected function parseLocales(mixed $option): ?array {
    if ($option === NULL || $option === FALSE || trim((string) $option) === '') {
      return NULL;
    }

    $locales = array_values(array_unique(array_filter(
      array_map('trim', explode(',', (string) $option)),
      static fn(string $locale): bool => $locale !== '',
    )));
    foreach ($locales as $locale) {
      if (preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale) !== 1) {
        throw new \RuntimeException(sprintf(
          'Invalid locale "%s". Use comma-separated locale IDs such as en,de.',
          $locale,
        ));
      }
    }

    return $locales;
  }

}
