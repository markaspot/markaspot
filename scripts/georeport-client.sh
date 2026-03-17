#!/usr/bin/env bash
#
# GeoReport Client Script
# Creates users, sets up API key, and generates test service requests
#

set -e

# Use DRUSH_CMD if set by start.sh, otherwise detect
if [ -n "$DRUSH_CMD" ]; then
  DRUSH="$DRUSH_CMD"
elif command -v drush >/dev/null 2>&1; then
  DRUSH="drush"
elif [ -f "./vendor/bin/drush" ]; then
  DRUSH="./vendor/bin/drush"
else
  echo "ERROR: drush not found"
  exit 1
fi

# Pass through DRUSH_URI for multisite support
# (set by start.sh: DRUSH_URI="$DRUSH_URI" georeport-client.sh)
DRUSH_ARGS="$DRUSH_URI"

# Determine API endpoint (DDEV uses 'web', legacy Docker uses VIRTUAL_HOST)
if [ -n "$DDEV_HOSTNAME" ] || [ -f "/.dockerenv" ]; then
  API_HOST="http://web"
elif [ -n "$VIRTUAL_HOST" ]; then
  API_HOST="http://$VIRTUAL_HOST"
else
  API_HOST="http://localhost"
fi

printf "\e[36mCreating users...\e[0m\n"

# Create API user
printf "  Creating api_user...\n"
$DRUSH $DRUSH_ARGS user:create "api_user" --password="api_password" 2>/dev/null || echo "  api_user already exists"
$DRUSH $DRUSH_ARGS user:role:add "api_user" "api_user" 2>/dev/null || true

# Create 2 moderator users
printf "  Creating moderator users...\n"
$DRUSH $DRUSH_ARGS user:create "moderation_1" --mail="moderation_1@example.com" --password="mod_password" 2>/dev/null || echo "  moderation_1 already exists"
$DRUSH $DRUSH_ARGS user:role:add "moderator" "moderation_1" 2>/dev/null || true

$DRUSH $DRUSH_ARGS user:create "moderation_2" --mail="moderation_2@example.com" --password="mod_password" 2>/dev/null || echo "  moderation_2 already exists"
$DRUSH $DRUSH_ARGS user:role:add "moderator" "moderation_2" 2>/dev/null || true

printf "\e[32m+\e[0m Users created: api_user, moderation_1, moderation_2\n"

# Get API user UUID and link to API key
printf "\e[36mConfiguring API key...\e[0m\n"
UUID=$($DRUSH $DRUSH_ARGS sql:query "SELECT uuid FROM users WHERE uid = (SELECT uid FROM users_field_data WHERE name = 'api_user')" --database=default 2>/dev/null || echo "")

if [ -n "$UUID" ]; then
  $DRUSH $DRUSH_ARGS config-set services_api_key_auth.api_key.nuxt user_uuid "$UUID" -y
  printf "\e[32m+\e[0m API key linked to api_user (UUID: %s)\n" "$UUID"
else
  echo "Warning: Could not get api_user UUID"
fi

# Get the API key from the configuration
API_KEY=${GEOREPORT_API_KEY:-$($DRUSH $DRUSH_ARGS config-get services_api_key_auth.api_key.nuxt key --format=string 2>/dev/null || echo "*")}
printf "  Using API key: %s\n" "$API_KEY"

# Set the center latitude and longitude
CENTER_LAT=$($DRUSH $DRUSH_ARGS cget markaspot_nuxt.settings center_lat --format=string 2>/dev/null || echo "50.0")
CENTER_LNG=$($DRUSH $DRUSH_ARGS cget markaspot_nuxt.settings center_lng --format=string 2>/dev/null || echo "7.0")

# Set the radius in kilometers
RADIUS=15

# Calculate the radius in degrees (1 degree ~ 111.32 km)
RADIUS_IN_DEGREES=$(awk "BEGIN {print ($RADIUS / 111.32)}")

# Retrieve the services list from the server
printf "\e[36mRetrieving services from %s...\e[0m\n" "$API_HOST"
services_json=$(curl -s -w '\n%{http_code}\n' "${API_HOST}/georeport/v2/services.json")
# Check for errors in the response
response_code=$(echo "$services_json" | tail -n 1)
if [ "$response_code" != "200" ]; then
  echo "Error: Failed to retrieve service codes (HTTP $response_code)"
  exit 1
fi

# Extract the service codes from the JSON response
SERVICES=$(echo "$services_json" | head -n -1 | grep -o '"service_code":"[^"]*"' | awk -F':' '{print $2}' | tr -d '"')

echo "------------------------------------------------------------------------------------------------------------------"
printf "%-10s %-30s %-15s %-15s %-12s %-15s %-8s\n" "Request #" "Email" "Latitude" "Longitude" "Request Time" "Response Code" "Service Code"
echo "------------------------------------------------------------------------------------------------------------------"


for i in $(seq 1 50); do
  # Generate random coordinates within radius of center
  RANDOM_ANGLE=$(awk -v seed="$RANDOM$((i * 10))" 'BEGIN {srand(seed); print rand() * 2 * 3.141592653589793;}')
  RANDOM_RADIUS=$(awk -v seed="$RANDOM$((i * 10))" -v max="$RADIUS_IN_DEGREES" 'BEGIN {srand(seed); print sqrt(rand()) * max;}')

  LATITUDE_OFFSET=$(awk -v radius="$RANDOM_RADIUS" -v angle="$RANDOM_ANGLE" 'BEGIN {print radius * sin(angle);}')
  LONGITUDE_OFFSET=$(awk -v radius="$RANDOM_RADIUS" -v angle="$RANDOM_ANGLE" 'BEGIN {print radius * cos(angle);}')

  LATITUDE=$(awk -v center_lat="$CENTER_LAT" -v offset="$LATITUDE_OFFSET" 'BEGIN {print center_lat + offset;}')
  LONGITUDE=$(awk -v center_lng="$CENTER_LNG" -v offset="$LONGITUDE_OFFSET" 'BEGIN {print center_lng + offset;}')

  RANDOM_SERVICE_CODE=$(printf "%s\n" "$SERVICES" | awk 'BEGIN {srand();}{a[NR]=$0}END{print a[int(rand()*NR)+1]}')
  EMAIL="test_$(tr -dc A-Za-z0-9 </dev/urandom | head -c10)@example.com"

  DESCRIPTION="Duris sanctius sic erectos cepit vos erat quin. Fuerat arce pontus sine nisi melioris. \
  Haec inposuit pendebat sibi septemque caesa pluvialibus. Feras effigiem aurea animalibus. Vesper ante \
  quod frigore animal! Caecoque lucis terrae his utque. Quarum foret suis praeter videre crescendo obsistitur."

  # Generate a random number from 1 to 6
  RANDOM_NUMBER=$(( (RANDOM % 6) + 1 ))

  # Set the media URL with the random number
  MEDIA_URL="https://markaspot.de/demo-images/image_${RANDOM_NUMBER}.jpg"

  REQUEST_START=$(date +%s 2>/dev/null || echo 0)
  RESPONSE=$(curl -s --location "${API_HOST}/georeport/v2/requests.json?api_key=${API_KEY}" \
    --header 'Content-Type: application/x-www-form-urlencoded' \
    --data-urlencode 'service_code='"$RANDOM_SERVICE_CODE"'' \
    --data-urlencode 'description='"$DESCRIPTION"'' \
    --data-urlencode 'email='"$EMAIL"'' \
    --data-urlencode 'lat='"$LATITUDE"'' \
    --data-urlencode 'long='"$LONGITUDE"'' \
    --data-urlencode 'media_url='"$MEDIA_URL"'' \
    --write-out "%{http_code}" \
    --output /dev/null)

  REQUEST_END=$(date +%s 2>/dev/null || echo 0)
  REQUEST_TIME=$((REQUEST_END - REQUEST_START))
  printf "%-10s %-30s %-15s %-15s %-12s %-15s %-8s\n" "$i" "$EMAIL" "$LATITUDE" "$LONGITUDE" "${REQUEST_TIME}s" "$RESPONSE" "$RANDOM_SERVICE_CODE"
done

echo "------------------------------------------------------------------------------------------------------------------"

printf "\n\e[32m Setup Complete!\e[0m\n\n"
printf "  Users: api_user, moderation_1, moderation_2\n"
printf "  API Key: %s\n" "$API_KEY"
printf "  Test requests: 50\n\n"

# Find project root for DDEV config update
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
_PROJECT_ROOT="$SCRIPT_DIR"
while [ "$_PROJECT_ROOT" != "/" ]; do
    if [ -f "$_PROJECT_ROOT/composer.json" ] && [ -d "$_PROJECT_ROOT/web" ]; then
        break
    fi
    _PROJECT_ROOT="$(dirname "$_PROJECT_ROOT")"
done

# Auto-update DDEV docker-compose if it exists
DDEV_NODE_CONFIG="$_PROJECT_ROOT/.ddev/docker-compose.node-dev.yaml"
if [ -f "$DDEV_NODE_CONFIG" ] && [ -n "$API_KEY" ] && [ "$API_KEY" != "*" ]; then
  printf "\e[36mUpdating DDEV node-dev configuration with API key...\e[0m\n"
  sed -i.bak "s/GEOREPORT_API_KEY=.*/GEOREPORT_API_KEY=$API_KEY/" "$DDEV_NODE_CONFIG"
  rm -f "${DDEV_NODE_CONFIG}.bak"
  printf "\e[32m+\e[0m Updated %s\n" "$DDEV_NODE_CONFIG"
  printf "\e[33m  Run 'ddev restart' to apply the API key to frontend.\e[0m\n\n"
else
  printf "\n\e[33mHint: Update GEOREPORT_API_KEY in .ddev/docker-compose.node-dev.yaml\e[0m\n"
  printf "\e[33m      then run 'ddev restart' to apply to frontend.\e[0m\n\n"
fi
