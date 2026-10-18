<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Commands;

use Drupal\markaspot_fastmap\Demo\DemoSeed;
use Drupal\markaspot_fastmap\Service\DemoWorkspaceReset;
use Drupal\markaspot_fastmap\Service\DemoWorkspaceSeeder;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for template demo workspaces.
 */
class DemoWorkspaceCommands extends DrushCommands {

  public function __construct(
    protected readonly DemoWorkspaceSeeder $seeder,
    protected readonly DemoWorkspaceReset $reset,
  ) {
    parent::__construct();
  }

  /**
   * Creates or updates one template demo workspace from a seed YAML file.
   */
  #[CLI\Command(name: 'markaspot:seed-demo-workspace', aliases: ['mas:seed-demo-workspace'])]
  #[CLI\Argument(name: 'seed', description: 'Path to the seed YAML (format: markaspot_fastmap/demo_seeds/README.md).')]
  #[CLI\Option(name: 'reset', description: 'Remove visitor content first. Requires the slug in demo_workspaces.')]
  #[CLI\Usage(name: 'markaspot:seed-demo-workspace demo_seeds/demo-school-routes.yml', description: 'Seed the school route demo.')]
  public function seed(string $seed, array $options = ['reset' => FALSE]): void {
    $definition = DemoSeed::fromFile($seed);
    $result = !empty($options['reset'])
      ? $this->reset->resetWorkspace($definition->data['slug'], $definition)
      : $this->seeder->seed($definition);

    $this->output()->writeln(sprintf(
      'Demo workspace %s (group %d) %s, seed version %d.',
      $definition->data['slug'],
      $result['group_id'],
      !empty($options['reset']) ? 'reset' : (!empty($result['created']) ? 'created' : 'updated'),
      $definition->data['seed_version'],
    ));
    foreach ($result['reports'] as $key => $requestId) {
      $this->output()->writeln(sprintf('  %s => %s', $key, $requestId));
    }
  }

  /**
   * Resets every allowlisted demo workspace to its seeded state.
   */
  #[CLI\Command(name: 'markaspot:reset-demo-workspaces', aliases: ['mas:reset-demo-workspaces'])]
  #[CLI\Option(name: 'slug', description: 'Reset only this allowlisted demo workspace.')]
  #[CLI\Usage(name: 'markaspot:reset-demo-workspaces', description: 'Reset all slugs in markaspot_fastmap.settings:demo_workspaces.')]
  public function resetAll(array $options = ['slug' => NULL]): int {
    $slug = $options['slug'] ?? NULL;
    $results = is_string($slug) && $slug !== ''
      ? [$slug => $this->resetOne($slug)]
      : $this->reset->resetAll();

    if ($results === []) {
      $this->output()->writeln('No demo workspaces are allowlisted (markaspot_fastmap.settings:demo_workspaces).');
      return self::EXIT_SUCCESS;
    }
    $failed = 0;
    foreach ($results as $resetSlug => $result) {
      if (is_string($result)) {
        $failed++;
        $this->output()->writeln(sprintf('%s: FAILED: %s', $resetSlug, $result));
        continue;
      }
      $this->output()->writeln(sprintf(
        '%s: reset, removed %d nodes, %d media, %d terms; kept %d media used outside the demo.',
        $resetSlug,
        $result['removed']['node'],
        $result['removed']['media'],
        $result['removed']['taxonomy_term'],
        $result['removed']['media_kept'],
      ));
    }
    return $failed > 0 ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Resets one slug and turns a failure into its message.
   */
  private function resetOne(string $slug): array|string {
    try {
      return $this->reset->resetWorkspace($slug);
    }
    catch (\Throwable $e) {
      return $e->getMessage();
    }
  }

}
