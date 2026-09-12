<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\markaspot_tenant_import\Exception\TenantImportValidationException;
use Drupal\markaspot_tenant_import\Service\TenantImporter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Path;

/**
 * Drush command for importing versioned tenant configuration files.
 */
final class TenantImportCommands extends DrushCommands {

  /**
   * Constructs a TenantImportCommands object.
   */
  public function __construct(
    private readonly TenantImporter $tenantImporter,
  ) {
    parent::__construct();
  }

  /**
   * Drush 13 auto-discovery factory.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('markaspot_tenant_import.tenant_importer'));
  }

  /**
   * Plans or applies one tenant configuration import.
   *
   * @param string $path
   *   Path to a version 1 tenant configuration JSON file.
   * @param array<string, mixed> $options
   *   Command options.
   *
   * @option jurisdiction
   *   Required target jurisdiction group ID.
   * @option apply
   *   Apply the plan. Without this option the command is a dry-run.
   * @option skip
   *   Comma-separated sections to skip.
   * @option send-mails
   *   Send password-reset mail to newly created users. Requires --apply.
   * @option allow-slug-mismatch
   *   Permit tenant.slug to differ from the target field_slug.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Plan or application rows.
   */
  #[CLI\Command(name: 'markaspot:tenant:import', aliases: ['mas:tenant:import'])]
  #[CLI\Help(
    description: 'Import organisations, categories, statuses, users, and selected jurisdiction fields from JSON.',
    synopsis: 'Dry-run by default; use --apply to write the displayed plan.',
  )]
  #[CLI\Argument(name: 'path', description: 'Path to a tenant-config.json file.')]
  #[CLI\Option(name: 'jurisdiction', description: 'Required group ID of the target jur jurisdiction.')]
  #[CLI\Option(name: 'apply', description: 'Apply the plan. Without this option no data is changed.')]
  #[CLI\Option(name: 'skip', description: 'Comma-separated sections: organisations, categories, statuses, users.')]
  #[CLI\Option(name: 'send-mails', description: 'With --apply, send password-reset mail to newly created users.')]
  #[CLI\Option(name: 'allow-cross-tenant-users', description: 'Explicitly allow privileged and cross-root existing users; never rename or unblock them.')]
  #[CLI\Option(name: 'allow-slug-mismatch', description: 'Explicitly allow tenant.slug to differ from the target jurisdiction field_slug.')]
  #[CLI\FieldLabels(labels: [
    'entity' => 'Entity',
    'key' => 'Key',
    'action' => 'Action',
    'reason' => 'Reason',
  ])]
  #[CLI\Usage(
    name: 'drush mas:tenant:import tenant-config.json --jurisdiction=1',
    description: 'Validate the file and print the dry-run plan.',
  )]
  #[CLI\Usage(
    name: 'drush mas:tenant:import tenant-config.json --jurisdiction=1 --apply',
    description: 'Apply the import in a transaction.',
  )]
  public function import(
    string $path,
    array $options = [
      'jurisdiction' => NULL,
      'apply' => FALSE,
      'skip' => NULL,
      'send-mails' => FALSE,
      'allow-cross-tenant-users' => FALSE,
      'allow-slug-mismatch' => FALSE,
    ],
  ): RowsOfFields {
    $jurisdictionId = filter_var(
      $options['jurisdiction'] ?? NULL,
      FILTER_VALIDATE_INT,
      ['options' => ['min_range' => 1]],
    );
    if ($jurisdictionId === FALSE) {
      throw new \RuntimeException(
        'The required --jurisdiction option must be a positive group ID.',
      );
    }

    $skip = $this->parseSkip($options['skip'] ?? NULL);
    try {
      $path = Path::makeAbsolute($path, (string) $this->getConfig()->cwd());
      $configuration = $this->tenantImporter->decodeFile($path);
      $result = $this->tenantImporter->import(
        $configuration,
        $jurisdictionId,
        $skip,
        !empty($options['apply']),
        !empty($options['send-mails']),
        !empty($options['allow-cross-tenant-users']),
        !empty($options['allow-slug-mismatch']),
      );
    }
    catch (TenantImportValidationException $exception) {
      $this->io()->table(
        ['Validation error'],
        array_map(
          static fn(string $error): array => [$error],
          $exception->getErrors(),
        ),
      );
      throw new \RuntimeException(sprintf(
        'Tenant configuration validation failed with %d error(s).',
        count($exception->getErrors()),
      ), 0, $exception);
    }

    $counts = array_count_values(array_column($result['rows'], 'action'));
    $this->logger()->notice(sprintf(
      '%s summary: %s.',
      !empty($options['apply']) ? 'Apply' : 'Dry-run',
      implode(', ', array_map(
        static fn(string $action, int $count): string => sprintf('%s=%d', $action, $count),
        array_keys($counts),
        array_values($counts),
      )),
    ));

    if ($result['created_terms']) {
      $this->logger()->notice(
        'No cache rebuild was run. If new taxonomy terms are not visible immediately, run drush cr.',
      );
    }

    if ($result['errors'] !== []) {
      $this->io()->table(
        ['Entity', 'Key', 'Action', 'Reason'],
        array_map(
          static fn(array $row): array => array_values($row),
          $result['rows'],
        ),
      );
      throw new \RuntimeException(sprintf(
        'Tenant import finished with %d error(s): %s',
        count($result['errors']),
        implode(' | ', $result['errors']),
      ));
    }

    return new RowsOfFields($result['rows']);
  }

  /**
   * Parses the comma-separated skip option.
   *
   * @return string[]
   *   Unique non-empty section names.
   */
  private function parseSkip(mixed $option): array {
    if ($option === NULL || $option === FALSE || trim((string) $option) === '') {
      return [];
    }
    return array_values(array_unique(array_filter(
      array_map('trim', explode(',', (string) $option)),
      static fn(string $section): bool => $section !== '',
    )));
  }

}
