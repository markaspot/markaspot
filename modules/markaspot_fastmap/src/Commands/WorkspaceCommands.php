<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for FastMap workspace operations.
 */
class WorkspaceCommands extends DrushCommands {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Blocks a workspace immediately.
   */
  #[CLI\Command(name: 'markaspot:workspace-block', aliases: ['mas:workspace-block'])]
  #[CLI\Argument(name: 'workspace', description: 'Workspace group ID or field_slug.')]
  #[CLI\Option(name: 'reason', description: 'Reason logged for operators.')]
  #[CLI\Usage(name: 'markaspot:workspace-block amsterdam --reason=spam', description: 'Block a workspace by slug.')]
  public function block(string $workspace, array $options = ['reason' => 'spam suspicion']): void {
    $group = $this->loadWorkspace($workspace);
    $this->setVisibility($group, 'blocked');

    $reason = trim((string) ($options['reason'] ?? 'spam suspicion'));
    $this->logger()->warning('Workspace @id (@label) blocked via Drush. Reason: @reason', [
      '@id' => $group->id(),
      '@label' => $group->label(),
      '@reason' => $reason !== '' ? $reason : 'not specified',
    ]);

    $this->output()->writeln(sprintf(
      'Workspace %s (%s) is now blocked.',
      $group->id(),
      $group->label(),
    ));
  }

  /**
   * Unblocks a workspace by restoring a regular visibility mode.
   */
  #[CLI\Command(name: 'markaspot:workspace-unblock', aliases: ['mas:workspace-unblock'])]
  #[CLI\Argument(name: 'workspace', description: 'Workspace group ID or field_slug.')]
  #[CLI\Option(name: 'visibility', description: 'Visibility to restore: public, submission_only, or authenticated.')]
  #[CLI\Usage(name: 'markaspot:workspace-unblock amsterdam --visibility=public', description: 'Unblock a workspace by slug.')]
  public function unblock(string $workspace, array $options = ['visibility' => 'public']): void {
    $visibility = (string) ($options['visibility'] ?? 'public');
    if (!in_array($visibility, ['public', 'submission_only', 'authenticated'], TRUE)) {
      throw new \InvalidArgumentException('--visibility must be one of: public, submission_only, authenticated.');
    }

    $group = $this->loadWorkspace($workspace);
    $this->setVisibility($group, $visibility);

    $this->logger()->notice('Workspace @id (@label) unblocked via Drush and set to @visibility.', [
      '@id' => $group->id(),
      '@label' => $group->label(),
      '@visibility' => $visibility,
    ]);

    $this->output()->writeln(sprintf(
      'Workspace %s (%s) visibility restored to %s.',
      $group->id(),
      $group->label(),
      $visibility,
    ));
  }

  /**
   * Loads a jurisdiction workspace by numeric ID or slug.
   */
  private function loadWorkspace(string $workspace): GroupInterface {
    $storage = $this->entityTypeManager->getStorage('group');

    if (ctype_digit($workspace)) {
      $group = $storage->load((int) $workspace);
    }
    else {
      if (!preg_match('/^[a-z0-9_-]{1,64}$/', $workspace)) {
        throw new \InvalidArgumentException('Workspace must be a numeric group ID or a valid slug.');
      }
      $matches = $storage->loadByProperties([
        'type' => 'jur',
        'field_slug' => $workspace,
      ]);
      $group = reset($matches) ?: NULL;
    }

    if (!$group instanceof GroupInterface || $group->bundle() !== 'jur') {
      throw new \RuntimeException(sprintf('Workspace "%s" was not found.', $workspace));
    }

    if (!$group->hasField('field_visibility')) {
      throw new \RuntimeException(sprintf('Workspace "%s" has no field_visibility field.', $workspace));
    }

    return $group;
  }

  /**
   * Updates the workspace visibility field.
   */
  private function setVisibility(GroupInterface $group, string $visibility): void {
    $group->set('field_visibility', $visibility);
    $group->save();
  }

}
