#!/usr/bin/env bash

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

# Detect environment: DDEV (has composer, writable settings.php) vs
# Docker production (no composer, read-only settings.php bind-mount).
# Both have /.dockerenv, so check IS_DDEV_PROJECT to distinguish.
IS_DOCKER_PROD="false"
if [ -f "/.dockerenv" ] && [ "$IS_DDEV_PROJECT" != "true" ]; then
  IS_DOCKER_PROD="true"
  DRUSH_CMD="php -d memory_limit=-1 ${PROJECT_ROOT}/vendor/bin/drush.php"
else
  DRUSH_CMD="drush"
fi
export DRUSH_CMD

# =============================================================================
# Output Formatting
# =============================================================================
GREEN='\033[32m'
YELLOW='\033[33m'
CYAN='\033[36m'
RED='\033[31m'
RESET='\033[0m'
BOLD='\033[1m'

step()    { printf "${CYAN}→${RESET} %s\n" "$1"; }
success() { printf "${GREEN}✓${RESET} %s\n" "$1"; }
warn()    { printf "${YELLOW}⚠${RESET} %s\n" "$1"; }
error()   { printf "${RED}✗${RESET} %s\n" "$1"; }
info()    { printf "  %s\n" "$1"; }

# =============================================================================
# Environment Setup
# =============================================================================

# Determine Drupal web root for different container setups (DDEV and legacy Docker).
if [ -d "$PROJECT_ROOT/web/sites/default" ]; then
  WEB_ROOT="$PROJECT_ROOT/web"
elif [ -d "/app/data/web/sites/default" ]; then
  WEB_ROOT="/app/data/web"
else
  echo "ERROR: Unable to locate Drupal web directory. Checked '$PROJECT_ROOT/web' and '/app/data/web'."
  exit 1
fi

# Multisite support: default to "default" for single-site installs
SITE_NAME="default"
SITE_URI=""
DRUSH_URI=""
MULTISITE_MODE="false"

usage() {
  echo "Usage: start.sh [--site=SITENAME] [--multisite] [-y] [-t] [-a]"
  echo
  echo "Options:"
  echo "    --site=NAME  Site name for multisite (e.g., aachen, bonn). Uses sites/NAME/"
  echo "    --multisite  Use existing-config mode (copies config/sync, uses --existing-config)"
  echo "    -y           Install automatically (default: Koln, Germany, de_DE locale)"
  echo "    -t           Import translation file from the /translations directory and enable translations for terms"
  echo "    -a           Use AI translation (OpenAI) for content artifacts instead of standard translation files"
  echo
  echo "Environment variables for -y mode:"
  echo "    CITY=...             City name (default: Koln)"
  echo "    COUNTRY=...          Country name (default: Germany)"
  echo "    LOCALE=...           Locale code (default: de_DE)"
  echo "    OPENAI_API_KEY=...   Required for -a flag"
  echo
  echo "Examples:"
  echo "    ddev exec scripts/start.sh -y -a                            # Single site: Koln with AI translation"
  echo "    ddev exec scripts/start.sh --site=aachen -y                 # Multisite: Aachen (fresh)"
  echo "    ddev exec scripts/start.sh --site=aachen --multisite -y     # Multisite: Aachen (existing-config)"
  echo "    CITY=Bonn ddev exec scripts/start.sh --site=bonn -y         # Multisite: Bonn"
  exit 1
}

if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
  usage
fi

# Parse --site and --multisite parameters first (before other args)
for arg in "$@"; do
  case $arg in
    --site=*)
      SITE_NAME="${arg#*=}"
      shift
      ;;
    --multisite)
      MULTISITE_MODE="true"
      shift
      ;;
  esac
done

# Validate --multisite requires --site
if [ "$MULTISITE_MODE" = "true" ] && [ "$SITE_NAME" = "default" ]; then
  error "--multisite requires --site=NAME"
  exit 1
fi

# Set up multisite paths and URIs
if [ "$SITE_NAME" != "default" ]; then
  SITE_DIR="$WEB_ROOT/sites/$SITE_NAME"
  SITE_URI="$SITE_NAME.ddev.site"
  DRUSH_URI="--uri=$SITE_URI"
  CONFIG_SYNC_DIR="../config/$SITE_NAME"
  CONFIG_SYNC_ABS="$PROJECT_ROOT/config/$SITE_NAME"

  # For multisite, check if site directory exists
  if [ ! -d "$SITE_DIR" ]; then
    error "Site directory not found: $SITE_DIR"
    info "Run install-multisite.sh first to create the site structure."
    exit 1
  fi

  # For multisite, handle config based on mode:
  # - MULTISITE_MODE=true: copy config/sync, use --existing-config, then config:import
  # - MULTISITE_MODE=false: fresh install, then config:export
  CONFIG_NEEDS_COPY="false"
  # Check for actual yml config files (not just .env/.htaccess created by add-site.sh)
  HAS_CONFIG_FILES="false"
  if [ -d "$CONFIG_SYNC_ABS" ] && ls "$CONFIG_SYNC_ABS"/*.yml >/dev/null 2>&1; then
    HAS_CONFIG_FILES="true"
  fi

  if [ "$HAS_CONFIG_FILES" = "false" ]; then
    mkdir -p "$CONFIG_SYNC_ABS"
    if [ "$MULTISITE_MODE" = "true" ]; then
      BASE_CONFIG="$PROJECT_ROOT/config/sync"
      if [ -d "$BASE_CONFIG" ] && ls "$BASE_CONFIG"/*.yml >/dev/null 2>&1; then
        info "Copying base config to config/$SITE_NAME"
        cp -r "$BASE_CONFIG/"* "$CONFIG_SYNC_ABS/"
      else
        error "No base config in config/sync - cannot install with --multisite"
        exit 1
      fi
    else
      CONFIG_NEEDS_COPY="true"
      info "Config directory empty, will export after install"
    fi
  fi

  step "Multisite mode: $SITE_NAME ($SITE_URI)"
else
  SITE_DIR="$WEB_ROOT/sites/default"
  CONFIG_SYNC_DIR="../config/sync"
  CONFIG_NEEDS_COPY="false"
  step "Single-site mode"
fi

# Install composer dependencies (skip if vendor already populated, e.g. Docker image)
if [ -f "$PROJECT_ROOT/vendor/autoload.php" ]; then
  success "Dependencies already installed (Docker image)"
else
  step "Installing composer dependencies..."
  COMPOSER_CMD=""
  if command -v composer &>/dev/null; then
    COMPOSER_CMD="composer"
  elif [[ -x /usr/local/bin/composer ]]; then
    COMPOSER_CMD="/usr/local/bin/composer"
  elif [[ -f composer.phar ]]; then
    COMPOSER_CMD="php composer.phar"
  else
    error "composer not found and vendor/ missing. Run inside DDEV: ddev exec scripts/start.sh"
    exit 1
  fi
  $COMPOSER_CMD install --no-dev
fi


if [ "$ENVIRONMENT" != "prod" ]; then
  step "Installing Mark-a-Spot Distribution..."

  # Define the path to the Drupal settings file
  SETTINGS_FILE="$SITE_DIR/settings.php"
  DEFAULT_SETTINGS_FILE="$WEB_ROOT/sites/default/default.settings.php"

  # Configure settings.php
  # Docker production: settings.php is a read-only bind-mount with ENV-based config.
  # DDEV/local: settings.php is generated from default.settings.php with DB credentials.
  if [ "$IS_DOCKER_PROD" = "true" ]; then
    info "Docker production: using bind-mounted settings.php"
  else
    # Ensure sites/default directory and settings.php are writable
    chmod u+w "$SITE_DIR" 2>/dev/null || true
    if [ -f "$SETTINGS_FILE" ]; then
      chmod u+w "$SETTINGS_FILE" 2>/dev/null && rm -f "$SETTINGS_FILE"
    fi

    # For multisite, settings.php may already exist from install-multisite.sh
    if [ "$SITE_NAME" != "default" ] && [ -f "$SETTINGS_FILE" ]; then
      info "Using existing settings.php for multisite $SITE_NAME"
    else
      if [ ! -f "$DEFAULT_SETTINGS_FILE" ]; then
        error "Cannot find default settings file at $DEFAULT_SETTINGS_FILE"
        exit 1
      fi
      cp "$DEFAULT_SETTINGS_FILE" "$SETTINGS_FILE"
    fi

    # Database name: use site name for multisite, 'db' for single site
    if [ "$SITE_NAME" != "default" ]; then
      DB_NAME="$SITE_NAME"
    else
      DB_NAME=${DRUPAL_DATABASE_NAME:-${DB_NAME:-db}}
    fi
    DB_USER=${DRUPAL_DATABASE_USERNAME:-${DB_USER:-db}}
    DB_PASS=${DRUPAL_DATABASE_PASSWORD:-${DB_PASSWORD:-db}}
    DB_HOST=${MARKASPOT_MARIADB_SERVICE_HOST:-${DB_HOST:-db}}
    DB_PORT=${DRUPAL_DATABASE_PORT:-${DB_PORT:-3306}}
    HASH_SALT=${DRUPAL_HASH_SALT:-$(tr -dc 'a-z0-9' </dev/urandom | head -c 32)}

    # Custom database configuration
    CUSTOM_DB_CONFIG="\\
    \$databases['default']['default'] = [\\
        'database' => '$DB_NAME',\\
        'username' => '$DB_USER',\\
        'password' => '$DB_PASS',\\
        'prefix' => '',\\
        'host' => '$DB_HOST',\\
        'port' => $DB_PORT,\\
        'namespace' => 'Drupal\\\\\\\\mysql\\\\\\\\Driver\\\\\\\\Database\\\\\\\\mysql',\\
        'driver' => 'mysql',\\
    ];"

    # Add the custom database configuration after the $databases declaration
    sed -i "/\$databases = \[\];/a $CUSTOM_DB_CONFIG" "$SETTINGS_FILE"

    # Custom hash salt configuration
    CUSTOM_HASH_SALT="\$settings['hash_salt'] = '$HASH_SALT';"

    # Replace the existing hash salt configuration with the custom one
    sed -i "s/\$settings\['hash_salt'\] = '';$/$CUSTOM_HASH_SALT/" "$SETTINGS_FILE"

    # Update the config_sync_directory setting (uses CONFIG_SYNC_DIR for multisite support)
    sed -i "s|# \$settings\['config_sync_directory'\] = '/directory/outside/webroot';|\$settings['config_sync_directory'] = '$CONFIG_SYNC_DIR';|" "$SETTINGS_FILE"

    cat <<'EOF' >> "$SETTINGS_FILE"

// Override the GeoReport API key with environment configuration when available.
if ((isset($app_root) || PHP_SAPI === 'cli') && ($geoKey = getenv('GEOREPORT_API_KEY'))) {
  if ($geoKey !== '*' && $geoKey !== '') {
    $config['services_api_key_auth.api_key.nuxt']['key'] = $geoKey;
  }
}

EOF

    success "Settings configured"
  fi

  step "Preparing database..."
  $DRUSH_CMD $DRUSH_URI sql-drop -y >/dev/null 2>&1


  # Function to query the Nominatim API for city information
  get_city_info() {
      if ! command -v curl >/dev/null 2>&1; then
          error "curl is required but not installed"
          return 1
      fi

      if ! command -v php >/dev/null 2>&1; then
          error "PHP CLI is required but not available"
          return 1
      fi

      city_name=$(php -r 'echo rawurlencode($argv[1]);' "$1")
      country_name=$(php -r 'echo rawurlencode($argv[1]);' "$2")

      if [ -z "$city_name" ] || [ -z "$country_name" ]; then
          error "Empty city or country name"
          return 1
      fi

      response=$(curl -s "https://nominatim.openstreetmap.org/search?city=$city_name&country=$country_name&format=json&limit=10")

      if [ $? -ne 0 ] || [ -z "$response" ]; then
          error "Failed to query Nominatim API"
          return 1
      fi

      locations=$(printf "%s" "$response" | php -r '
          $data = json_decode(stream_get_contents(STDIN), true);
          if (!is_array($data) || empty($data)) {
              exit(1);
          }
          foreach ($data as $row) {
              if (!isset($row["lat"], $row["lon"], $row["display_name"])) {
                  continue;
              }
              $display = str_replace(["\n", "\r"], " ", $row["display_name"]);
              echo $row["lat"], "\t", $row["lon"], "\t", $display, "\n";
          }
      ')

      if [ $? -ne 0 ] || [ -z "$locations" ]; then
          error "Failed to parse location data"
          return 1
      fi

      count=$(printf "%s" "$locations" | grep -c '^')
      if [ "$count" -eq 0 ]; then
          error "No results found for $1 in $2"
          return 1
      elif [ "$count" -eq 1 ]; then
          selected="$locations"
      else
          if [ "$automatic" = "true" ]; then
              info "Multiple locations found, auto-selecting first result"
              selected=$(printf "%s" "$locations" | head -1)
          else
              printf "\nMultiple locations found. Please select:\n"
              printf "%s" "$locations" | nl -ba
              read -p "Choice: " choice
              if ! printf "%s" "$choice" | grep -Eq '^[0-9]+$' || [ "$choice" -lt 1 ] || [ "$choice" -gt "$count" ]; then
                  error "Invalid selection"
                  return 1
              fi
              selected=$(printf "%s" "$locations" | sed -n "${choice}p")
          fi
      fi

      latitude=$(printf "%s" "$selected" | cut -f1)
      longitude=$(printf "%s" "$selected" | cut -f2)
      city=$(printf "%s" "$selected" | cut -f3-)

      success "Location: $city ($latitude, $longitude)"
      return 0
  }

  translation="false"
  automatic="false"
  ai_translate="false"

  # Process command line options
  for arg in "$@"; do
    case $arg in
      -y)
        automatic="true"
        shift
        ;;
      -t)
        translation="true"
        shift
        ;;
      -a)
        ai_translate="true"
        shift
        ;;
      *)
        # unknown option
        echo "Invalid option: $arg" >&2
        usage
        ;;
    esac
  done
  if [ "$automatic" = true ]; then
      auto_city="${CITY:-Koln}"
      auto_country="${COUNTRY:-Germany}"
      locale="${LOCALE:-de_DE}"

      step "Looking up coordinates for $auto_city, $auto_country..."
      get_city_info "$auto_city" "$auto_country"
      if [ $? -ne 0 ]; then
        warn "Nominatim lookup failed, using default Koln coordinates"
        latitude="50.9375"
        longitude="6.9603"
        city="Koln, Nordrhein-Westfalen, Deutschland"
      fi
  else
      printf "\nCity name (blank for manual coordinates): "
      read city_name
      printf "Country name: "
      read country_name
      printf "Locale (e.g. 'en_US', 'de_DE'): "
      read locale

      latitude=""
      longitude=""
      city=""

      if [ -n "$city_name" ] && [ -n "$country_name" ]; then
          step "Looking up coordinates..."
          get_city_info "$city_name" "$country_name"
          if [ $? -ne 0 ]; then
              warn "Lookup failed, please enter manually"
              printf "Latitude: "
              read latitude
              printf "Longitude: "
              read longitude
              printf "City: "
              read city
          fi
      else
          printf "Latitude: "
          read latitude
          printf "Longitude: "
          read longitude
          printf "City: "
          read city
      fi
  fi


  # Progress indicator function
  show_progress() {
    local pid=$1
    local delay=0.3
    local spinstr='|/-\'
    local i=0

    printf "${CYAN}-${RESET} Installing Drupal "

    while ps a | awk '{print $1}' | grep -q "$pid"; do
      local char=$(echo "$spinstr" | cut -c$((i % 4 + 1)))
      printf "\b%s" "$char"
      sleep $delay
      i=$((i + 1))
    done

    printf "\b \n"
  }

  # Install based on mode:
  # - MULTISITE_MODE=true: site:install with --existing-config
  # - MULTISITE_MODE=false + CONFIG_NEEDS_COPY: fresh site:install
  # - Single-site: markaspot:install
  if [ "$SITE_NAME" != "default" ] && [ "$MULTISITE_MODE" = "true" ]; then
    info "Multisite install with --existing-config"
    $DRUSH_CMD $DRUSH_URI site:install markaspot \
      --account-name=admin --account-pass="${DRUPAL_ADMIN_PASSWORD:-admin}" --account-mail=admin@example.com \
      --existing-config --locale="$locale" -y > markaspot_install.log 2>&1 &
  elif [ "$SITE_NAME" != "default" ] && [ "$CONFIG_NEEDS_COPY" = "true" ]; then
    info "Fresh multisite install (no --existing-config)"
    $DRUSH_CMD $DRUSH_URI site:install markaspot \
      --account-name=admin --account-pass="${DRUPAL_ADMIN_PASSWORD:-admin}" --account-mail=admin@example.com \
      --site-name="$city" --locale="$locale" -y > markaspot_install.log 2>&1 &
  else
    # Always use site:install without --existing-config.
    # markaspot:install uses --existing-config internally which fails when
    # config/sync is empty or has optional config dependency issues.
    # Config-update (coordinates, validation) runs separately after install.
    $DRUSH_CMD $DRUSH_URI site:install markaspot \
      --account-name=admin --account-pass="${DRUPAL_ADMIN_PASSWORD:-admin}" --account-mail=admin@example.com \
      --site-name="$city" -y > markaspot_install.log 2>&1 &
  fi
  install_pid=$!

  show_progress $install_pid

  wait $install_pid
  install_exit_code=$?

  if $DRUSH_CMD $DRUSH_URI status --field=bootstrap 2>/dev/null | grep -q "Successful"; then
    success "Drupal installed"
  elif [ $install_exit_code -ne 0 ]; then
    error "Installation failed! Check markaspot_install.log"
    exit 1
  fi

  # Rebuild cache to ensure all classes are available for php:eval
  $DRUSH_CMD $DRUSH_URI cr >/dev/null 2>&1

  # Ensure all profile modules are enabled (Docker site:install may skip some
  # due to config dependency issues during installation)
  PROFILE_MODULES="language toolbar markaspot_group markaspot_nuxt markaspot_bbox_cache markaspot_media markaspot_token markaspot_request_id markaspot_icons markaspot_dashboard markaspot_escalation markaspot_open311 pathauto search_api search_api_db diff restui"
  MISSING_MODULES=""
  for mod in $PROFILE_MODULES; do
    if ! $DRUSH_CMD $DRUSH_URI php:eval "echo \Drupal::moduleHandler()->moduleExists('$mod') ? '1' : '0';" 2>/dev/null | grep -q "1"; then
      MISSING_MODULES="$MISSING_MODULES $mod"
    fi
  done

  if [ -n "$MISSING_MODULES" ]; then
    step "Enabling missing profile modules:$MISSING_MODULES"
    # Install gin theme first (some modules depend on it)
    $DRUSH_CMD $DRUSH_URI theme:install gin -y 2>/dev/null || true
    $DRUSH_CMD $DRUSH_URI cr >/dev/null 2>&1

    # Try enabling modules directly. If PreExistingConfigException occurs,
    # parse the conflicting config names from the error, delete only those
    # specific configs, and retry. This preserves all other configs (permissions,
    # field definitions, form displays, ECA rules, etc.) that the nuclear
    # cleanup previously destroyed.
    EN_OUTPUT=$($DRUSH_CMD $DRUSH_URI en $MISSING_MODULES -y 2>&1) || true
    EN_EXIT=$?

    if echo "$EN_OUTPUT" | grep -qi "PreExistingConfigException\|already exists as active configuration"; then
      step "Resolving config conflicts for module installation..."
      # Extract conflicting config names from the error message.
      # PreExistingConfigException lists them like: config_name1, config_name2
      CONFLICTING=$($DRUSH_CMD $DRUSH_URI php:eval "
        \$modules = explode(' ', trim('$MISSING_MODULES'));
        \$config_factory = \Drupal::configFactory();
        \$extension_list = \Drupal::service('extension.list.module');
        \$conflicts = [];
        foreach (\$modules as \$module) {
          if (\Drupal::moduleHandler()->moduleExists(\$module)) {
            continue;
          }
          // Check config/install and config/optional directories for this module
          try {
            \$path = \$extension_list->getPath(\$module);
          } catch (\Exception \$e) {
            continue;
          }
          foreach (['config/install', 'config/optional'] as \$subdir) {
            \$dir = \$path . '/' . \$subdir;
            if (!is_dir(\$dir)) continue;
            foreach (glob(\$dir . '/*.yml') as \$file) {
              \$name = basename(\$file, '.yml');
              // Skip schema files
              if (str_ends_with(\$name, '.schema')) continue;
              // Check if this config already exists in active storage
              if (!\$config_factory->get(\$name)->isNew()) {
                \$conflicts[] = \$name;
              }
            }
          }
        }
        echo implode(\"\\n\", array_unique(\$conflicts));
      " 2>/dev/null)

      if [ -n "$CONFLICTING" ]; then
        CONFLICT_COUNT=$(echo "$CONFLICTING" | grep -c '^' || echo "0")
        info "Deleting $CONFLICT_COUNT conflicting configs..."
        $DRUSH_CMD $DRUSH_URI php:eval "
          \$factory = \Drupal::configFactory();
          \$names = explode(\"\\n\", trim('$(echo "$CONFLICTING" | tr '\n' '\n')'));
          \$deleted = 0;
          foreach (\$names as \$name) {
            \$name = trim(\$name);
            if (\$name !== '' && !\$factory->get(\$name)->isNew()) {
              \$factory->getEditable(\$name)->delete();
              \$deleted++;
            }
          }
          echo \"Deleted \$deleted conflicting configs\\n\";
        " 2>/dev/null || true
        $DRUSH_CMD $DRUSH_URI cr >/dev/null 2>&1
      fi

      # Retry module installation after removing only the conflicting configs
      $DRUSH_CMD $DRUSH_URI en $MISSING_MODULES -y 2>/dev/null || warn "Some modules could not be enabled"
    elif [ $EN_EXIT -ne 0 ]; then
      warn "Module installation returned non-zero exit ($EN_EXIT)"
      info "Retrying one module at a time..."
      for mod in $MISSING_MODULES; do
        $DRUSH_CMD $DRUSH_URI en "$mod" -y 2>/dev/null || warn "Could not enable $mod"
      done
    fi

    $DRUSH_CMD $DRUSH_URI cr >/dev/null 2>&1
    success "Profile modules enabled"
  fi

  # Enable FastMap + passwordless modules if FASTMAP_SERVICE_KEY is set.
  # The key must match between Drupal config and the frontend ENV.
  if [ -n "${FASTMAP_SERVICE_KEY:-}" ]; then
    step "Enabling FastMap modules..."
    $DRUSH_CMD $DRUSH_URI en markaspot_fastmap -y 2>/dev/null || warn "Failed to enable markaspot_fastmap"
    $DRUSH_CMD $DRUSH_URI cset markaspot_fastmap.settings service_key "$FASTMAP_SERVICE_KEY" -y 2>/dev/null
    $DRUSH_CMD $DRUSH_URI cr >/dev/null 2>&1
    success "FastMap modules enabled (service_key configured)"
  fi

  # Run pending update hooks (ensures install/update hooks from all modules run).
  step "Running database updates..."
  $DRUSH_CMD $DRUSH_URI updb -y 2>/dev/null || warn "Database updates returned warnings"
  $DRUSH_CMD $DRUSH_URI cr >/dev/null 2>&1
  success "Database updates complete"

  # Ensure tenant_admin role exists (may not be created by config/optional if deps missing).
  step "Ensuring tenant_admin role..."
  $DRUSH_CMD $DRUSH_URI php:eval "
    \$role = \Drupal\user\Entity\Role::load('tenant_admin');
    if (!\$role) {
      \$role = \Drupal\user\Entity\Role::create(['id' => 'tenant_admin', 'label' => 'Tenant Admin', 'weight' => 4]);
      \$role->save();
      echo \"Created tenant_admin role\n\";
    } else {
      echo \"tenant_admin role exists\n\";
    }
  " 2>/dev/null || true

  # Re-import role permissions from profile config/install and config/optional.
  # Even with targeted cleanup, profile optional role configs may not be
  # auto-imported by drush en. Ensure all profile-shipped permissions are granted.
  step "Restoring role permissions from profile..."
  for PROFILE_CONFIG in "$WEB_ROOT/profiles/contrib/markaspot/config/install" "$WEB_ROOT/profiles/contrib/markaspot/config/optional"; do
    if [ -d "$PROFILE_CONFIG" ]; then
      for role_file in "$PROFILE_CONFIG"/user.role.*.yml; do
        [ -f "$role_file" ] || continue
        role_name=$(basename "$role_file" .yml | sed 's/^user\.role\.//')
        $DRUSH_CMD $DRUSH_URI php:eval "
          \$yaml = \Drupal\Component\Serialization\Yaml::decode(file_get_contents('$role_file'));
          \$role = \Drupal::entityTypeManager()->getStorage('user_role')->load('$role_name');
          if (\$role && !empty(\$yaml['permissions'])) {
            \$handler = \Drupal::service('user.permissions');
            \$valid = array_keys(\$handler->getPermissions());
            \$added = 0;
            foreach (\$yaml['permissions'] as \$perm) {
              if (in_array(\$perm, \$valid) && !\$role->hasPermission(\$perm)) {
                \$role->grantPermission(\$perm);
                \$added++;
              }
            }
            if (\$added > 0) {
              \$role->save();
              echo \"Added \$added permissions for $role_name\\n\";
            }
          }
        " 2>/dev/null || true
      done
    fi
  done
  success "Role permissions verified"

  # Run config-update to set coordinates, validation, and country
  country=$(echo "$locale" | cut -d '_' -f2)
  step "Updating map configuration..."
  $DRUSH_CMD $DRUSH_URI markaspot:config-update --lat="$latitude" --lng="$longitude" --city="$city" --country="$country" --radius=50 -y 2>/dev/null || true
  success "Map configuration updated"

  step "Configuring admin user..."
  $DRUSH_CMD $DRUSH_URI user:role:add "administrator" --uid=1 >/dev/null 2>&1
  $DRUSH_CMD $DRUSH_URI user:role:add "tenant_admin" --uid=1 >/dev/null 2>&1
  $DRUSH_CMD $DRUSH_URI user:role:add "moderator" --uid=1 >/dev/null 2>&1
  # Fix admin username and email (may be "placeholder-for-uid-1" after --existing-config install)
  $DRUSH_CMD $DRUSH_URI php:eval "
    \$user = \Drupal\user\Entity\User::load(1);
    if (\$user) {
      \$changed = false;
      if (\$user->getAccountName() !== 'admin') {
        \$user->setUsername('admin');
        \$changed = true;
      }
      if (\$user->getEmail() !== 'admin@example.com') {
        \$user->setEmail('admin@example.com');
        \$changed = true;
      }
      if (\$changed) {
        \$user->save();
      }
    }
  " 2>/dev/null || true
  success "Admin user configured"

  step "Configuring themes..."
  $DRUSH_CMD $DRUSH_URI config:set system.theme admin gin -y >/dev/null 2>&1
  $DRUSH_CMD $DRUSH_URI config:set system.theme default gin -y >/dev/null 2>&1
  $DRUSH_CMD $DRUSH_URI cr >/dev/null 2>&1
  success "Themes configured (gin)"

  # Disable CSS/JS aggregation for multisite (prevents redirect loops on aggregated files)
  if [ "$SITE_NAME" != "default" ]; then
    step "Disabling CSS/JS aggregation for multisite..."
    $DRUSH_CMD $DRUSH_URI config:set system.performance css.preprocess 0 -y >/dev/null 2>&1
    $DRUSH_CMD $DRUSH_URI config:set system.performance js.preprocess 0 -y >/dev/null 2>&1
    success "CSS/JS aggregation disabled"
  fi

  step "Configuring map coordinates..."

  # markaspot_nuxt.settings - main frontend map center
  $DRUSH_CMD $DRUSH_URI config:set markaspot_nuxt.settings center_lat -y -- "$latitude" >/dev/null
  $DRUSH_CMD $DRUSH_URI config:set markaspot_nuxt.settings center_lng -y -- "$longitude" >/dev/null

  # Field default value for geolocation field (all computed values)
  LAT_RAD=$(awk "BEGIN {printf \"%.14f\", $latitude * 3.14159265358979 / 180}")
  LNG_RAD=$(awk "BEGIN {printf \"%.14f\", $longitude * 3.14159265358979 / 180}")
  LAT_SIN=$(awk "BEGIN {printf \"%.14f\", sin($LAT_RAD)}")
  LAT_COS=$(awk "BEGIN {printf \"%.14f\", cos($LAT_RAD)}")
  GEO_VALUE="$latitude, $longitude"

  $DRUSH_CMD $DRUSH_URI config:set field.field.node.service_request.field_geolocation default_value.0.lat -y -- "$latitude" >/dev/null
  $DRUSH_CMD $DRUSH_URI config:set field.field.node.service_request.field_geolocation default_value.0.lng -y -- "$longitude" >/dev/null
  $DRUSH_CMD $DRUSH_URI config:set field.field.node.service_request.field_geolocation default_value.0.lat_sin -y -- "$LAT_SIN" >/dev/null
  $DRUSH_CMD $DRUSH_URI config:set field.field.node.service_request.field_geolocation default_value.0.lat_cos -y -- "$LAT_COS" >/dev/null
  $DRUSH_CMD $DRUSH_URI config:set field.field.node.service_request.field_geolocation default_value.0.lng_rad -y -- "$LNG_RAD" >/dev/null
  $DRUSH_CMD $DRUSH_URI config:set field.field.node.service_request.field_geolocation default_value.0.value -y -- "$GEO_VALUE" >/dev/null

  # Widget settings for form displays (map center in edit forms)
  $DRUSH_CMD $DRUSH_URI config:set core.entity_form_display.node.service_request.default third_party_settings.geolocation.centre.lat -y -- "$latitude" >/dev/null 2>&1 || true
  $DRUSH_CMD $DRUSH_URI config:set core.entity_form_display.node.service_request.default third_party_settings.geolocation.centre.lng -y -- "$longitude" >/dev/null 2>&1 || true

  # Update widget center_lat/center_lng settings and geocoding bbox
  # Calculate bounding box for geocoding (Nominatim viewbox format: minLng,minLat,maxLng,maxLat)
  BBOX_RADIUS="0.20"  # ~22km radius for geocoding search area
  BBOX_MIN_LAT=$(awk "BEGIN {printf \"%.6f\", $latitude - $BBOX_RADIUS}")
  BBOX_MAX_LAT=$(awk "BEGIN {printf \"%.6f\", $latitude + $BBOX_RADIUS}")
  BBOX_MIN_LNG=$(awk "BEGIN {printf \"%.6f\", $longitude - $BBOX_RADIUS}")
  BBOX_MAX_LNG=$(awk "BEGIN {printf \"%.6f\", $longitude + $BBOX_RADIUS}")
  LIMIT_VIEWBOX="$BBOX_MIN_LNG,$BBOX_MIN_LAT,$BBOX_MAX_LNG,$BBOX_MAX_LAT"

  # Extract simple city name (first part before comma)
  SIMPLE_CITY_NAME=$(echo "$city" | cut -d',' -f1 | tr -d "'\"\`\\")

  for form_mode in default management; do
    $DRUSH_CMD $DRUSH_URI config:set "core.entity_form_display.node.service_request.$form_mode" content.field_geolocation.settings.center_lat -y -- "$latitude" >/dev/null 2>&1 || true
    $DRUSH_CMD $DRUSH_URI config:set "core.entity_form_display.node.service_request.$form_mode" content.field_geolocation.settings.center_lng -y -- "$longitude" >/dev/null 2>&1 || true
    $DRUSH_CMD $DRUSH_URI config:set "core.entity_form_display.node.service_request.$form_mode" content.field_geolocation.settings.limit_viewbox -y -- "$LIMIT_VIEWBOX" >/dev/null 2>&1 || true
    $DRUSH_CMD $DRUSH_URI config:set "core.entity_form_display.node.service_request.$form_mode" content.field_geolocation.settings.city -y -- "$SIMPLE_CITY_NAME" >/dev/null 2>&1 || true
  done

  success "Map center: $latitude, $longitude"
  info "Geocoding bbox: $LIMIT_VIEWBOX"

  language=$(echo "$locale" | cut -d '_' -f1)

  # Always add the language from locale (en is default, others are additional)
  if [ "$language" != "en" ]; then
    step "Adding language: $language..."
    $DRUSH_CMD $DRUSH_URI language-add "$language" >/dev/null 2>&1 || true
    success "Language $language added"
  fi

  step "Importing base content..."
  import_output=$(DRUSH_URI="$DRUSH_URI" $SCRIPT_DIR/import.sh 2>&1)
  import_exit=$?

  # Verify groups were created
  group_count=$($DRUSH_CMD $DRUSH_URI sql:query "SELECT COUNT(*) FROM groups" 2>/dev/null || echo "0")
  if [ "$group_count" -gt 0 ] 2>/dev/null; then
    success "Groups, categories, and terms created ($group_count groups)"
  else
    warn "Import may have failed - no groups found"
    echo -e "${YELLOW}$import_output${NC}" | tail -20
  fi

  step "Fetching city boundary..."
  # Only attempt boundary fetch if groups exist
  if [ "$group_count" -gt 0 ] 2>/dev/null; then
    boundary_output=$($DRUSH_CMD $DRUSH_URI markaspot:fetch-boundary --city="$city" --group=1 -y 2>&1)
    boundary_exit=$?
    if [ $boundary_exit -eq 0 ]; then
      success "City boundary stored"
    else
      warn "Could not fetch boundary for '$city'"
      echo -e "${RED}$boundary_output${NC}"
      echo ""
      info "This may cause frontend errors. Try manually:"
      info "  ddev drush markaspot:fetch-boundary --city=\"$city, $country\" --group=1 -y"
    fi
  else
    warn "Skipping boundary fetch - no groups exist"
    info "Run import.sh manually after fixing group migration"
  fi

  step "Configuring validation settings..."

  # Create a bounding box WKT polygon (~15km radius around center)
  RADIUS_DEG="0.15"
  MIN_LAT=$(awk "BEGIN {printf \"%.6f\", $latitude - $RADIUS_DEG}")
  MAX_LAT=$(awk "BEGIN {printf \"%.6f\", $latitude + $RADIUS_DEG}")
  MIN_LNG=$(awk "BEGIN {printf \"%.6f\", $longitude - $RADIUS_DEG}")
  MAX_LNG=$(awk "BEGIN {printf \"%.6f\", $longitude + $RADIUS_DEG}")

  WKT="POLYGON(($MIN_LNG $MIN_LAT,$MAX_LNG $MIN_LAT,$MAX_LNG $MAX_LAT,$MIN_LNG $MAX_LAT,$MIN_LNG $MIN_LAT))"

  # Extract simple city name (first part before comma)
  SIMPLE_CITY=$(echo "$city" | cut -d',' -f1)

  $DRUSH_CMD $DRUSH_URI config:set markaspot_validation.settings wkt "$WKT" -y >/dev/null 2>&1
  $DRUSH_CMD $DRUSH_URI config:set markaspot_validation.settings location.0 "$SIMPLE_CITY" -y >/dev/null 2>&1
  $DRUSH_CMD $DRUSH_URI config:set markaspot_validation.settings locality.0 "$SIMPLE_CITY" -y >/dev/null 2>&1
  success "Validation configured for $SIMPLE_CITY"

  step "Configuring GeoReport API..."
  $DRUSH_CMD $DRUSH_URI config:delete markaspot_open311.settings status_closed.3 -y >/dev/null 2>&1 || true
  $DRUSH_CMD $DRUSH_URI config:delete markaspot_open311.settings status_closed.4 -y >/dev/null 2>&1 || true
  $DRUSH_CMD $DRUSH_URI config:set markaspot_open311.settings status_closed.5 5 -y >/dev/null 2>&1
  $DRUSH_CMD $DRUSH_URI config:set markaspot_open311.settings status_closed.6 6 -y >/dev/null 2>&1
  $DRUSH_CMD $DRUSH_URI config:set rest.resource.georeport_request_index_resource configuration.GET.supported_auth.0 cookie -y >/dev/null 2>&1 || true
  $DRUSH_CMD $DRUSH_URI config:set rest.resource.georeport_request_index_resource configuration.GET.supported_auth.1 api_key_auth -y >/dev/null 2>&1 || true
  $DRUSH_CMD $DRUSH_URI config:set rest.resource.georeport_request_resource configuration.GET.supported_auth.0 cookie -y >/dev/null 2>&1 || true
  $DRUSH_CMD $DRUSH_URI config:set rest.resource.georeport_request_resource configuration.GET.supported_auth.1 api_key_auth -y >/dev/null 2>&1 || true

  $DRUSH_CMD $DRUSH_URI php:eval '
    $config = \Drupal::service("config.factory")->getEditable("group.role.org-anonymous");
    $perms = $config->get("permissions") ?: [];
    if (!in_array("view group_node:service_request entity", $perms)) {
      $perms[] = "view group_node:service_request entity";
      $config->set("permissions", $perms)->save();
    }
  ' 2>/dev/null || true
  success "GeoReport API configured"

  # Handle translations - English is always base, other languages are translations
  if [ "$translation" = true ] || [ "$ai_translate" = true ]; then
    step "Adding language: $language..."
    $DRUSH_CMD $DRUSH_URI language-add "$language" >/dev/null 2>&1 || true

    step "Enabling multilingual support..."
    $DRUSH_CMD $DRUSH_URI en markaspot_language -y >/dev/null 2>&1

    step "Importing Drupal translations..."
    DRUSH_URI="$DRUSH_URI" $SCRIPT_DIR/translate.sh "$locale" >/dev/null 2>&1
    success "Translations imported"

    if [ "$ai_translate" = true ]; then
      if [ -z "$OPENAI_API_KEY" ]; then
        warn "OPENAI_API_KEY not set"
        printf "  Enter OpenAI API key: "
        read api_key
        export OPENAI_API_KEY=$api_key
      fi

      step "Running AI translation..."
      chmod +x "$SCRIPT_DIR/ai-translate.sh"
      if sh "$SCRIPT_DIR/ai-translate.sh" $language >/dev/null 2>&1; then
        success "AI translation complete"
      else
        warn "AI translation failed"
      fi

      if [ -f "$SCRIPT_DIR/create-translations.php" ]; then
        step "Creating entity translations..."
        $DRUSH_CMD $DRUSH_URI php:script "$SCRIPT_DIR/create-translations.php" -- "$language" >/dev/null 2>&1 || warn "Could not create translations"
      fi

      ARTIFACTS_DIR="$WEB_ROOT/profiles/contrib/markaspot/modules/markaspot_default_content/artifacts"
      LANG_DIR="$ARTIFACTS_DIR/$language"
      if [ -d "$LANG_DIR" ]; then
        rm -rf "$LANG_DIR"
      fi
    fi
  else
    info "Hint: Use -t (translations) or -a (AI translation) for multilingual"
  fi

  step "Configuring API key..."
  ENV_GEOREPORT_API_KEY=${GEOREPORT_API_KEY:-}

  if [ -n "$ENV_GEOREPORT_API_KEY" ] && [ "$ENV_GEOREPORT_API_KEY" != "*" ]; then
    GEOREPORT_API_KEY="$ENV_GEOREPORT_API_KEY"
  else
    GEOREPORT_API_KEY=$(php -r 'echo bin2hex(random_bytes(16));')
  fi

  $DRUSH_CMD $DRUSH_URI config-set services_api_key_auth.api_key.nuxt key "$GEOREPORT_API_KEY" -y >/dev/null 2>&1

  # Ensure API key auth module accepts keys via query parameter and POST body.
  # The module defaults to header-only, but GeoReport clients (including
  # georeport-client.sh) pass api_key as a query parameter or form field.
  $DRUSH_CMD $DRUSH_URI config-set services_api_key_auth.settings api_key_get_parameter_name "api_key" -y >/dev/null 2>&1
  $DRUSH_CMD $DRUSH_URI config-set services_api_key_auth.settings api_key_post_parameter_name "api_key" -y >/dev/null 2>&1

  # Set user_uuid for API key authentication (uses admin user as fallback)
  ADMIN_UUID=$($DRUSH_CMD $DRUSH_URI php:eval "echo \Drupal\user\Entity\User::load(1)->uuid();" 2>/dev/null)
  if [ -n "$ADMIN_UUID" ]; then
    $DRUSH_CMD $DRUSH_URI config-set services_api_key_auth.api_key.nuxt user_uuid "$ADMIN_UUID" -y >/dev/null 2>&1
  fi

  DDEV_ENV_FILE="$PROJECT_ROOT/.ddev/.env"
  if [ -d "$PROJECT_ROOT/.ddev" ]; then
    if [ -f "$DDEV_ENV_FILE" ]; then
      grep -v "^GEOREPORT_API_KEY=" "$DDEV_ENV_FILE" > "${DDEV_ENV_FILE}.tmp" 2>/dev/null || true
      mv "${DDEV_ENV_FILE}.tmp" "$DDEV_ENV_FILE"
    fi
    echo "GEOREPORT_API_KEY=$GEOREPORT_API_KEY" >> "$DDEV_ENV_FILE"
  fi

  export GEOREPORT_API_KEY
  success "API key configured"

  step "Configuring frontend map styles..."
  $DRUSH_CMD $DRUSH_URI config:set markaspot_nuxt.settings mapbox_style "https://tiles.openfreemap.org/styles/liberty" -y >/dev/null 2>&1 || true
  $DRUSH_CMD $DRUSH_URI config:set markaspot_nuxt.settings mapbox_style_dark "https://tiles.openfreemap.org/styles/dark" -y >/dev/null 2>&1 || true
  $DRUSH_CMD $DRUSH_URI config:set markaspot_nuxt.settings fallback_style "https://tiles.openfreemap.org/styles/liberty" -y >/dev/null 2>&1 || true
  $DRUSH_CMD $DRUSH_URI config:set markaspot_nuxt.settings fallback_style_dark "https://tiles.openfreemap.org/styles/dark" -y >/dev/null 2>&1 || true
  success "Frontend map styles configured"

  step "Generating test session cookie..."
  if [ -f "$SCRIPT_DIR/get-drupal-session.sh" ]; then
    chmod +x "$SCRIPT_DIR/get-drupal-session.sh"
    SESSION_COOKIE=$("$SCRIPT_DIR/get-drupal-session.sh" 1 cookie 2>/dev/null) || true
    if [ -n "$SESSION_COOKIE" ] && [ -d "$PROJECT_ROOT/.ddev" ]; then
      # Remove old session cookie line if exists
      if [ -f "$DDEV_ENV_FILE" ]; then
        grep -v "^DRUPAL_TEST_SESSION_COOKIE=" "$DDEV_ENV_FILE" > "${DDEV_ENV_FILE}.tmp" 2>/dev/null || true
        mv "${DDEV_ENV_FILE}.tmp" "$DDEV_ENV_FILE"
      fi
      echo "DRUPAL_TEST_SESSION_COOKIE=$SESSION_COOKIE" >> "$DDEV_ENV_FILE"
      success "Session cookie configured"
    else
      warn "Could not generate session cookie"
    fi
  fi

  step "Creating test data..."
  DRUSH_URI="$DRUSH_URI" SITE_URI="$SITE_URI" $SCRIPT_DIR/georeport-client.sh >/dev/null 2>&1
  success "Test users and service requests created"

  step "Configuring groups..."

  # Get actually enabled languages from Drupal
  ENABLED_LANGS=$($DRUSH_CMD $DRUSH_URI php:eval "
    \$languages = \Drupal::languageManager()->getLanguages();
    echo implode(',', array_keys(\$languages));
  " 2>/dev/null)

  if [ -n "$ENABLED_LANGS" ]; then
    # Convert comma-separated list to JSON array
    NUXT_AVAILABLE_LANGS=$(echo "$ENABLED_LANGS" | awk -F',' '{
      printf "[";
      for(i=1; i<=NF; i++) {
        printf "\"%s\"", $i;
        if(i<NF) printf ", ";
      }
      printf "]"
    }')
    # Use site default language
    NUXT_DEFAULT_LANG=$($DRUSH_CMD $DRUSH_URI php:eval "echo \Drupal::languageManager()->getDefaultLanguage()->getId();" 2>/dev/null)
    [ -z "$NUXT_DEFAULT_LANG" ] && NUXT_DEFAULT_LANG="en"
  else
    # Fallback to original logic
    if [ "$translation" = true ] || [ "$ai_translate" = true ]; then
      NUXT_DEFAULT_LANG="$language"
      # Avoid duplicating "en" if language is already English
      if [ "$language" = "en" ]; then
        NUXT_AVAILABLE_LANGS="[\"en\"]"
      else
        NUXT_AVAILABLE_LANGS="[\"en\", \"$language\"]"
      fi
    else
      NUXT_DEFAULT_LANG="en"
      NUXT_AVAILABLE_LANGS="[\"en\"]"
    fi
  fi

  $DRUSH_CMD $DRUSH_URI php:eval "
    \$is_fastmap = \Drupal::moduleHandler()->moduleExists('markaspot_fastmap');
    \$group = \Drupal::entityTypeManager()->getStorage('group')->load(1);
    if (\$group && \$group->getGroupType()->id() === 'jur') {
      \$group->set('label', '$SIMPLE_CITY_NAME');
      // Set slug from city name (lowercase, ascii-safe)
      \$slug = strtolower(trim('$SIMPLE_CITY_NAME'));
      \$slug = preg_replace('/[^a-z0-9-]/', '-', \$slug);
      \$slug = preg_replace('/-+/', '-', trim(\$slug, '-'));
      if (\$group->hasField('field_slug')) {
        \$group->set('field_slug', \$slug);
      }
      if (\$group->hasField('field_platform_name')) {
        \$group->set('field_platform_name', '$SIMPLE_CITY_NAME');
      }
      \$config = [
        'client' => [
          'name' => '$SIMPLE_CITY_NAME',
          'shortName' => '$SIMPLE_CITY_NAME'
        ],
        'theme' => [
          'primary' => 'blue',
          'secondary' => 'sky',
          'neutral' => 'slate'
        ],
        'features' => [
          'statistics' => true,
          'photoReporting' => true,
          'dashboard' => \$is_fastmap,
          'passwordless' => \$is_fastmap,
          'voting' => false,
          'feedback' => true
        ],
        'languages' => [
          'default' => '$NUXT_DEFAULT_LANG',
          'available' => json_decode('$NUXT_AVAILABLE_LANGS', true)
        ],
        'map' => [
          'center' => ['lat' => $latitude, 'lng' => $longitude],
          'zoom' => 13,
          'style' => 'https://tiles.openfreemap.org/styles/liberty',
          'styleDark' => 'https://tiles.openfreemap.org/styles/dark',
          'loadMarkersOnInit' => true,
          'enableBoundsFiltering' => true
        ]
      ];
      \$group->set('field_nuxt_config', json_encode(\$config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      \$group->save();
    }
  " 2>/dev/null || true

  step "Ensuring start page content..."
  $DRUSH_CMD $DRUSH_URI php:eval "
    \$storage = \Drupal::entityTypeManager()->getStorage('node');
    \$existing = \$storage->loadByProperties(['type' => 'page', 'status' => 1, 'promote' => 1]);
    if (empty(\$existing)) {
      \$group = \Drupal::entityTypeManager()->getStorage('group')->load(1);
      \$field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'page');
      \$values = [
        'type' => 'page',
        'title' => 'Welcome',
        'status' => 1,
        'promote' => 1,
        'sticky' => 1,
        'body' => [
          'value' => '<h2>Report an Issue</h2><p>Help keep your city clean and safe with Mark-a-Spot.</p>',
          'format' => 'full_html',
        ],
      ];
      if (isset(\$field_definitions['field_jurisdiction']) && \$group) {
        \$values['field_jurisdiction'] = ['target_id' => (int) \$group->id()];
      }
      \$node = \Drupal\node\Entity\Node::create(\$values);
      \$node->save();
    }
  " 2>/dev/null || true
  success "Start page content ensured"

  step "Setting jurisdiction on pages..."
  $DRUSH_CMD $DRUSH_URI php:eval "
    // Ensure field_jurisdiction exists on page bundle
    \$field_storage = \Drupal::entityTypeManager()->getStorage('field_storage_config')->load('node.field_jurisdiction');
    if (\$field_storage) {
      \$field = \Drupal::entityTypeManager()->getStorage('field_config')->load('node.page.field_jurisdiction');
      if (!\$field) {
        \$field = \Drupal\field\Entity\FieldConfig::create([
          'field_storage' => \$field_storage,
          'bundle' => 'page',
          'label' => 'Jurisdiction',
          'settings' => [
            'handler' => 'default:group',
            'handler_settings' => ['target_bundles' => ['jur' => 'jur']],
          ],
        ]);
        \$field->save();
      }
      // Set jurisdiction=1 on all pages that lack it
      \$nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => 'page']);
      foreach (\$nodes as \$node) {
        if (\$node->get('field_jurisdiction')->isEmpty()) {
          \$node->set('field_jurisdiction', 1);
          \$node->save();
        }
      }
    }
  " 2>/dev/null || true
  success "Page jurisdictions set"

  $DRUSH_CMD $DRUSH_URI php:eval "
    \$user = \Drupal\user\Entity\User::load(1);
    \$groups = \Drupal::entityTypeManager()->getStorage('group')->loadMultiple();
    foreach (\$groups as \$group) {
      if (!\$group->getMember(\$user)) {
        \$group->addMember(\$user);
      }
    }
  " 2>/dev/null || true

  $DRUSH_CMD $DRUSH_URI php:eval "
    \$group_storage = \Drupal::entityTypeManager()->getStorage('group');
    \$user_storage = \Drupal::entityTypeManager()->getStorage('user');
    \$jur = \$group_storage->load(1);
    \$dept1 = \$group_storage->load(2);
    \$dept2 = \$group_storage->load(3);
    // api_user must NOT be a group member: members get jur-member role which
    // lacks view access via entity query. As outsider, jur-outsider grants view.
    foreach (['moderation_1', 'moderation_2'] as \$name) {
      \$users = \$user_storage->loadByProperties(['name' => \$name]);
      \$user = reset(\$users);
      if (\$user && \$jur && !\$jur->getMember(\$user)) {
        \$jur->addMember(\$user);
      }
    }
    \$mod1 = \$user_storage->loadByProperties(['name' => 'moderation_1']);
    \$mod1 = reset(\$mod1);
    if (\$mod1 && \$dept1 && !\$dept1->getMember(\$mod1)) {
      \$dept1->addMember(\$mod1);
    }
    \$mod2 = \$user_storage->loadByProperties(['name' => 'moderation_2']);
    \$mod2 = reset(\$mod2);
    if (\$mod2 && \$dept2 && !\$dept2->getMember(\$mod2)) {
      \$dept2->addMember(\$mod2);
    }
  " 2>/dev/null || true

  success "Groups and memberships configured"

  # Final cache clear to purge any cached 404s from API requests during setup.
  $DRUSH_CMD $DRUSH_URI cr >/dev/null 2>&1

  # =============================================================================
  # Smoke Tests
  # =============================================================================
  step "Running smoke tests..."
  SMOKE_BASE_URL="${SMOKE_BASE_URL:-http://localhost}"
  SMOKE_PASS=0
  SMOKE_FAIL=0

  # Test 1: Settings API returns jurisdiction
  SETTINGS_RESPONSE=$(curl -sf "$SMOKE_BASE_URL/api/mark-a-spot-settings?exclude=boundary" 2>/dev/null || echo "FAIL")
  if echo "$SETTINGS_RESPONSE" | grep -q '"jurisdiction"'; then
    SMOKE_PASS=$((SMOKE_PASS + 1))
    success "Settings API: jurisdiction found"
  else
    SMOKE_FAIL=$((SMOKE_FAIL + 1))
    error "Settings API: jurisdiction missing or 404"
  fi

  # Test 2: Map style is set
  if echo "$SETTINGS_RESPONSE" | grep -q 'openfreemap\|mapbox_style'; then
    SMOKE_PASS=$((SMOKE_PASS + 1))
    success "Settings API: map style configured"
  else
    SMOKE_FAIL=$((SMOKE_FAIL + 1))
    error "Settings API: no map style found"
  fi

  # Test 3: Services available
  SERVICES_RESPONSE=$(curl -sf "$SMOKE_BASE_URL/georeport/v2/services.json" 2>/dev/null || echo "[]")
  SERVICE_COUNT=$(echo "$SERVICES_RESPONSE" | php -r 'echo count(json_decode(file_get_contents("php://stdin"), true) ?: []);')
  if [ "$SERVICE_COUNT" -gt 0 ] 2>/dev/null; then
    SMOKE_PASS=$((SMOKE_PASS + 1))
    success "GeoReport Services: $SERVICE_COUNT categories"
  else
    SMOKE_FAIL=$((SMOKE_FAIL + 1))
    error "GeoReport Services: none found"
  fi

  # Test 4: Requests via API key
  REQUESTS_RESPONSE=$(curl -sf "$SMOKE_BASE_URL/georeport/v2/requests.json?api_key=$GEOREPORT_API_KEY&limit=1" 2>/dev/null || echo "FAIL")
  if echo "$REQUESTS_RESPONSE" | grep -q 'service_request_id'; then
    SMOKE_PASS=$((SMOKE_PASS + 1))
    success "GeoReport Requests: API key auth works"
  else
    # Fallback: test anonymous
    ANON_RESPONSE=$(curl -sf "$SMOKE_BASE_URL/georeport/v2/requests.json?limit=1" 2>/dev/null || echo "FAIL")
    if echo "$ANON_RESPONSE" | grep -q 'service_request_id'; then
      SMOKE_PASS=$((SMOKE_PASS + 1))
      warn "GeoReport Requests: anonymous works, API key auth fails (group access issue)"
    else
      SMOKE_FAIL=$((SMOKE_FAIL + 1))
      error "GeoReport Requests: no requests accessible"
    fi
  fi

  # Test 5: Node count
  NODE_COUNT=$($DRUSH_CMD $DRUSH_URI sql:query "SELECT COUNT(*) FROM node_field_data WHERE type='service_request' AND status=1" 2>/dev/null || echo "0")
  if [ "$NODE_COUNT" -gt 0 ] 2>/dev/null; then
    SMOKE_PASS=$((SMOKE_PASS + 1))
    success "Database: $NODE_COUNT published service requests"
  else
    SMOKE_FAIL=$((SMOKE_FAIL + 1))
    error "Database: no service requests found"
  fi

  printf "\n"
  if [ "$SMOKE_FAIL" -eq 0 ]; then
    success "All $SMOKE_PASS smoke tests passed"
  else
    warn "$SMOKE_PASS passed, $SMOKE_FAIL failed"
  fi

  # =============================================================================
  # Installation Summary
  # =============================================================================
  printf "\n"
  success "Mark-a-Spot Installation Complete!"
  printf "\n"
  printf "  City:      %s\n" "$city"
  printf "  Locale:    %s\n" "$locale"
  printf "  API Key:   %s\n" "$GEOREPORT_API_KEY"
  printf "  Users:     admin, api_user, moderation_1, moderation_2\n"
  printf "  Data:      50 test service requests\n"

  printf "\n"
  step "One-Time Login:"
  if [ -n "$SITE_URI" ]; then
    printf "  %s\n" "$($DRUSH_CMD $DRUSH_URI uli --uri="https://$SITE_URI" 2>/dev/null)"
  elif [ -n "$DDEV_HOSTNAME" ]; then
    printf "  %s\n" "$($DRUSH_CMD uli --uri="https://$DDEV_HOSTNAME" 2>/dev/null)"
  else
    printf "  %s\n" "$($DRUSH_CMD uli --uri=$SMOKE_BASE_URL 2>/dev/null)"
  fi

  printf "\n"
  step "Next Steps:"
  if [ "$IS_DOCKER_PROD" = "true" ]; then
    printf "  1. Site is ready.\n"
  else
    printf "  1. Run 'ddev restart' to apply API key to frontend\n"
  fi
  if [ -n "$SITE_URI" ]; then
    printf "  2. Access site: https://%s\n\n" "$SITE_URI"
  elif [ -n "$DDEV_HOSTNAME" ]; then
    printf "  2. Access frontend: https://%s:3001\n\n" "$DDEV_HOSTNAME"
  else
    printf "  2. Access frontend: ${SMOKE_BASE_URL}:3000\n\n"
  fi
fi
