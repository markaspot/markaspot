#!/bin/sh
set -e

# Import default content via Drupal migrations.
# Called by start.sh or standalone.

# Determine project root from script location
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Find project root by walking up from script location until we find
# composer.json + web/ (works from both project root and profile scripts dir)
PROJECT_ROOT="$SCRIPT_DIR"
while [ "$PROJECT_ROOT" != "/" ]; do
    if [ -f "$PROJECT_ROOT/composer.json" ] && [ -d "$PROJECT_ROOT/web" ]; then
        break
    fi
    PROJECT_ROOT="$(dirname "$PROJECT_ROOT")"
done
if [ "$PROJECT_ROOT" = "/" ]; then
    echo "ERROR: Could not find project root (no composer.json + web/ found above $SCRIPT_DIR)"
    exit 1
fi

cd "$PROJECT_ROOT"

# DRUSH_URI can be passed as environment variable for multisite support
# e.g., DRUSH_URI="--uri=aachen.ddev.site" ./import.sh

# Use DRUSH_CMD if set by start.sh, otherwise detect
if [ -z "$DRUSH_CMD" ]; then
  if command -v drush >/dev/null 2>&1; then
    DRUSH_CMD="drush"
  elif [ -f "$PROJECT_ROOT/vendor/bin/drush" ]; then
    DRUSH_CMD="php -d memory_limit=-1 $PROJECT_ROOT/vendor/bin/drush"
  else
    echo "ERROR: drush not found"
    exit 1
  fi
fi

# Install migration modules (skip in Docker where deps are pre-installed)
if command -v composer >/dev/null 2>&1; then
  composer require drupal/migrate_tools drupal/migrate_plus drupal/migrate_source_csv --update-no-dev --quiet 2>/dev/null || true
fi

$DRUSH_CMD $DRUSH_URI en markaspot_default_content -y 2>/dev/null || true
$DRUSH_CMD $DRUSH_URI en migrate_tools migrate_plus migrate_source_csv -y

# Rebuild cache to register migrations from migrations/ directory
$DRUSH_CMD $DRUSH_URI cache:rebuild -q

printf "\e[36mMigration modules enabled and registered...\e[0m\n"

# Ensure /artifacts symlink exists (migrations use absolute path /artifacts/)
ARTIFACTS_SRC="$PROJECT_ROOT/web/profiles/contrib/markaspot/modules/markaspot_default_content/artifacts"
if [ -d "$ARTIFACTS_SRC" ] && [ ! -e "/artifacts" ]; then
  ln -sf "$ARTIFACTS_SRC" /artifacts 2>/dev/null || \
    sudo ln -sf "$ARTIFACTS_SRC" /artifacts 2>/dev/null || \
    printf "\e[33mCould not create /artifacts symlink. Migrations may fail.\e[0m\n"
fi

# Define migration IDs (groups first, then content)
MIGRATIONS="
  markaspot_migrate_default_content_group_jurisdiction
  markaspot_migrate_default_content_group_organisation
  markaspot_migrate_default_content_page
  markaspot_migrate_default_content_boilerplate
  markaspot_migrate_default_content_service_status
  markaspot_migrate_default_content_service_category
  markaspot_migrate_default_content_service_provider
  markaspot_migrate_default_content_block
"

# Loop over migration IDs
for MIGRATION_ID in $MIGRATIONS; do
  $DRUSH_CMD $DRUSH_URI migrate-import "$MIGRATION_ID" 2>&1 || \
    printf "\e[33mMigration %s failed or already imported\e[0m\n" "$MIGRATION_ID"
done

printf "\e[36mChecking for optional block configs...\e[0m\n"

# Set the source path using project root (not $PWD which may differ)
source_path="$PROJECT_ROOT/web/profiles/contrib/markaspot/modules/markaspot_default_content/config/_optional/"

# Check if the directory exists before importing
if [ -d "$source_path" ]; then
  printf "\e[36mImporting the config for blocks...\e[0m\n"
  $DRUSH_CMD $DRUSH_URI cim --source "$source_path" --partial -y
else
  printf "\e[33mOptional config directory not found at: $source_path\e[0m\n"
  printf "\e[33mSkipping optional config import...\e[0m\n"
fi

# Uninstall migration modules (keep files, only remove from DB)
$DRUSH_CMD $DRUSH_URI pmu migrate_source_csv migrate_plus migrate_tools markaspot_default_content -y 2>/dev/null || true
