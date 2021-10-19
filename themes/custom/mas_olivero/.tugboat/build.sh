#!/usr/bin/env bash
set -eo pipefail

URI=$1

if [[ -z "$URI" ]]; then
  echo "ERROR: you must pass the URI of the URI to build to $0." 1>&2;
  exit 23
fi

set -ux

# Run Drupal updates.
vendor/bin/drush --yes \
  --uri=$URI \
  updb
vendor/bin/drush --yes \
  --uri=$URI \
  cache:rebuild
# # Set the default theme to bartik.
# vendor/bin/drush --yes \
#   --uri=$URI \
#   config:set system.theme default bartik
# # Rebuild cache.
# vendor/bin/drush --yes \
#   --uri=$URI \
#   cache:rebuild
# # Uninstall the olivero theme.
# vendor/bin/drush --yes \
#   --uri=$URI \
#   theme:uninstall olivero || true
# # Clean out old Image style.
# vendor/bin/drush --yes \
#   --uri=$URI \
#   config:delete image.style.olivero_hero || true
# # Now enable the olivero theme again.
# vendor/bin/drush --yes \
#   --uri=$URI \
#   theme:enable olivero
# # Set the olivero theme as default.
# vendor/bin/drush --yes \
#   --uri=$URI \
#   config-set system.theme default olivero
# # Rebuild the cache again.
# vendor/bin/drush --yes \
#   --uri=$URI \
#   cache:rebuild
