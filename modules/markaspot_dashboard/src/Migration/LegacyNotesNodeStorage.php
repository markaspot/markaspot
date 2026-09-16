<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Migration;

use Drupal\node\NodeStorage;

/**
 * CLI-only node writer; never registered as a storage handler.
 *
 * @internal
 */
final class LegacyNotesNodeStorage extends NodeStorage {

  use LegacyNotesStorageTrait;

}
