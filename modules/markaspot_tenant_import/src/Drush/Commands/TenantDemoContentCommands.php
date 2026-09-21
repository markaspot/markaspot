<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\markaspot_tenant_import\Service\TenantDemoContent;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Explicit test-only synthetic content, separate from tenant configuration.
 */
final class TenantDemoContentCommands extends DrushCommands {

  /**
   * Constructs the command.
   */
  public function __construct(
    private readonly TenantDemoContent $demo,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly EntityTypeManagerInterface $entities,
  ) {
    parent::__construct();
  }

  /**
   * Drush discovery factory; no shared service registration changes required.
   */
  public static function create(ContainerInterface $container): self {
    return new self(new TenantDemoContent(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('lock'),
      $container->get('database'),
      $container->get('file_system'),
      $container->get('markaspot_open311.processor'),
      $container->get('current_user'),
    ), $container->get('account_switcher'), $container->get('entity_type.manager'));
  }

  /**
   * Creates validated synthetic fixtures; preview is the default.
   */
  #[CLI\Command(name: 'markaspot:tenant:demo-content', aliases: ['mas:tenant:demo-content'])]
  #[CLI\Argument(name: 'fixture', description: 'Local v1 synthetic fixture JSON.')]
  #[CLI\Option(name: 'assets-dir', description: 'Directory containing hash-pinned synthetic assets.')]
  #[CLI\Option(name: 'expected-site-uuid', description: 'Required independently verified site UUID.')]
  #[CLI\Option(name: 'jurisdiction-id', description: 'Required root jurisdiction ID.')]
  #[CLI\Option(name: 'confirm-test-data', description: 'Explicitly confirm synthetic data on a nonproduction Mailpit installation.')]
  #[CLI\Option(name: 'apply', description: 'Create the fixture; default only validates and reports coverage.')]
  #[CLI\DefaultTableFields(fields: ['site_uuid', 'jurisdiction_id', 'fixture_id', 'applied', 'action', 'requests'])]
  public function seed(
    string $fixture,
    array $options = [
      'assets-dir' => NULL,
      'expected-site-uuid' => NULL,
      'jurisdiction-id' => NULL,
      'confirm-test-data' => FALSE,
      'apply' => FALSE,
    ],
  ): RowsOfFields {
    if (is_link($fixture) || !is_file($fixture) || filesize($fixture) > 1024 * 1024) {
      throw new \RuntimeException('Fixture must be a regular local JSON file of at most 1 MiB.');
    }
    $data = json_decode(file_get_contents($fixture), TRUE, 64, JSON_THROW_ON_ERROR);
    $assets = $options['assets-dir'] ?? dirname($fixture);
    $jurisdiction = filter_var($options['jurisdiction-id'] ?? NULL, FILTER_VALIDATE_INT, [
      'options' => ['min_range' => 1],
    ]);
    if (!is_array($data) || !is_string($assets) || !is_string($options['expected-site-uuid'] ?? NULL) || $jurisdiction === FALSE) {
      throw new \RuntimeException('Fixture object, expected site UUID and positive jurisdiction ID are required.');
    }
    // Drush starts anonymously, but ordinary group reference validation uses
    // the execution account. Use the dedicated installation's technical owner,
    // without changing report ownership, fixture authors or stored permissions.
    $operator = $this->entities->getStorage('user')->load(1);
    if (!$operator instanceof UserInterface || !$operator->isActive() || !$operator->hasPermission('administer group')) {
      throw new \RuntimeException('An active technical administrator with group administration permission is required.');
    }
    $this->accountSwitcher->switchTo($operator);
    try {
      return new RowsOfFields([
        $this->demo->seed($data, $assets, $options['expected-site-uuid'], $jurisdiction, !empty($options['confirm-test-data']), !empty($options['apply'])),
      ]);
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

}
