<?php

/**
 * @file
 * Post update functions for the markaspot_icons module.
 */

/**
 * Normalizes legacy icon notations on all icon fields to Lucide.
 *
 * A post update instead of hook_update_N(): migration scripts that activate
 * markaspot_icons through core.extension set its schema to the newest update
 * number, which skips every update hook. Post updates are tracked separately
 * and still run on those databases.
 */
function markaspot_icons_post_update_normalize_legacy_icons(): string {
  \Drupal::moduleHandler()->loadInclude('markaspot_icons', 'install');
  return _markaspot_icons_repair_icons();
}
