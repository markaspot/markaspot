<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\user\UserInterface;
use Drupal\markaspot_tenant_import\Service\TenantBootstrapper;
use Drupal\markaspot_tenant_import\Service\TenantImporter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Path;

/**
 * Dedicated-stack bootstrap, separate from shared-database SaaS onboarding.
 */
final class TenantBootstrapCommands extends DrushCommands {

  /**
   * Constructs the command.
   */
  public function __construct(
    private readonly TenantImporter $importer,
    private readonly TenantBootstrapper $bootstrapper,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly EntityTypeManagerInterface $entities,
  ) {
    parent::__construct();
  }

  /**
   * Drush discovery factory.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('markaspot_tenant_import.tenant_importer'), $container->get('markaspot_tenant_import.tenant_bootstrapper'), $container->get('account_switcher'), $container->get('entity_type.manager'));
  }

  /**
   * Plans or applies one dedicated installation from a version 1 document.
   */
  #[CLI\Command(name: 'markaspot:tenant:bootstrap', aliases: ['mas:tenant:bootstrap'])]
  #[CLI\Argument(name: 'path', description: 'Path to tenant-config.json.')]
  #[CLI\Option(name: 'assets-dir', description: 'Explicit directory containing referenced PNG assets.')]
  #[CLI\Option(name: 'apply', description: 'Apply the configuration; default is a read-only preview.')]
  #[CLI\FieldLabels(labels: [
    'jurisdiction_id' => 'Jurisdiction',
    'action' => 'Action',
    'applied' => 'Applied',
    'warnings' => 'Warnings',
    'rows' => 'Import plan',
  ])]
  #[CLI\DefaultTableFields(fields: ['jurisdiction_id', 'action', 'applied'])]
  public function bootstrap(string $path, array $options = ['assets-dir' => NULL, 'apply' => FALSE, 'format' => 'table']): RowsOfFields {
    $cwd = (string) $this->getConfig()->cwd();
    $configuration = $this->importer->decodeFile(Path::makeAbsolute($path, $cwd));
    $assets = empty($options['assets-dir']) ? NULL : Path::makeAbsolute((string) $options['assets-dir'], $cwd);
    $operator = $this->entities->getStorage('user')->load(1);
    if (!$operator instanceof UserInterface || !$operator->isActive() || !$operator->hasPermission('administer group')) {
      throw new \RuntimeException('An active technical administrator with group administration permission is required.');
    }
    $this->accountSwitcher->switchTo($operator);
    try {
      $result = $this->bootstrapper->bootstrap($configuration, $assets, !empty($options['apply']));
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    if (($options['format'] ?? 'table') === 'table') {
      foreach ($result['warnings'] as $warning) {
        $this->logger()->warning($warning);
      }
      if ($result['rows'] !== []) {
        $this->io()->table(['Entity', 'Key', 'Action', 'Reason'], array_map('array_values', $result['rows']));
      }
    }
    return new RowsOfFields([$result]);
  }

}
