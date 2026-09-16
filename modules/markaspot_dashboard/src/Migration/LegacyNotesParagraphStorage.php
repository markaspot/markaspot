<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Migration;

use Drupal\paragraphs\ParagraphsStorage;

/**
 * CLI-only paragraph writer; never registered as a storage handler.
 *
 * @internal
 */
final class LegacyNotesParagraphStorage extends ParagraphsStorage {

  use LegacyNotesStorageTrait;

}
