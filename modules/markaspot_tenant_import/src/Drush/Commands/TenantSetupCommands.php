<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\markaspot_tenant_import\Service\TenantSetup;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Operator interface for provider-independent Drupal preparation.
 */
final class TenantSetupCommands extends DrushCommands {

  /**
   * Constructs the commands.
   */
  public function __construct(private readonly TenantSetup $setup) {
    parent::__construct();
  }

  /**
   * Drush discovery factory.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('markaspot_tenant_import.tenant_setup'));
  }

  /**
   * Reads setup identity and markers without changing installation state.
   */
  #[CLI\Command(name: 'markaspot:tenant:setup-status', aliases: ['mas:tenant:setup-status'])]
  #[CLI\DefaultTableFields(fields: [
    'contract_version', 'site_uuid', 'permissions_initialized',
    'permissions_initialization_started',
  ])]
  public function status(): RowsOfFields {
    return new RowsOfFields([$this->setup->status()]);
  }

  /**
   * Prepares prerequisites; first permission initialization is explicit.
   */
  #[CLI\Command(name: 'markaspot:tenant:prepare', aliases: ['mas:tenant:prepare'])]
  #[CLI\Option(name: 'expected-site-uuid', description: 'Required installed site UUID, independently verified by the operator.')]
  #[CLI\Option(name: 'initialize-permissions', description: 'Authorize first permission initialization on an owned fresh installation.')]
  #[CLI\Option(name: 'apply', description: 'Apply prerequisites; default is a read-only plan.')]
  #[CLI\DefaultTableFields(fields: ['site_uuid', 'applied', 'permission_action'])]
  public function prepare(
    array $options = [
      'expected-site-uuid' => NULL,
      'initialize-permissions' => FALSE,
      'apply' => FALSE,
    ],
  ): RowsOfFields {
    $expected = $options['expected-site-uuid'] ?? NULL;
    if (!is_string($expected) || $expected === '') {
      throw new \RuntimeException('The --expected-site-uuid option is required.');
    }
    return new RowsOfFields([$this->setup->prepare($expected, !empty($options['initialize-permissions']), !empty($options['apply']))]);
  }

}
