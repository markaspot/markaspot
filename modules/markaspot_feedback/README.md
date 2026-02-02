# Mark-a-Spot Feedback Module

## Overview

The Mark-a-Spot Feedback module adds an additional feedback field to service request nodes, allowing citizens to provide additional feedback collected via email or other channels.

## Features

- Adds `field_feedback` (text_long) to the `service_request` content type
- Configures field_permissions with public permission type
- Automatically adds field to form and view displays
- Provides proper install and uninstall hooks with cleanup
- User feedback forms for service requests
- Satisfaction rating system
- Comment management for service requests
- Feedback analytics

## Field Details

- **Field Name**: `field_feedback`
- **Entity Type**: `node`
- **Bundle**: `service_request`
- **Field Type**: `text_long` (Long text)
- **Label**: "Additional Feedback"
- **Description**: "This is additional citizen feedback which has been collected via E-Mail."
- **Cardinality**: 1
- **Required**: No
- **Translatable**: No (field instance), Yes (storage)
- **Permissions**: Public (via field_permissions module)

## Installation

```bash
# Install the module
ddev drush pm:enable markaspot_feedback -y

# Clear cache
ddev drush cr
```

### What happens during installation:

1. Creates `field_feedback` field storage on node entity type
2. Creates `field_feedback` field instance on service_request bundle
3. Sets up field_permissions (public permission type)
4. Adds field to service_request form display (text_textarea, weight 50)
5. Adds field to service_request view display (text_default, weight 50)
6. Logs all operations to watchdog

### Install Hook Idempotency

The install hook is idempotent - it checks if the field already exists before creating it, so it can be run multiple times safely without creating duplicates.

## Uninstallation

```bash
# Uninstall the module
ddev drush pm:uninstall markaspot_feedback -y

# Purge any remaining field data (runs automatically)
ddev drush cron
```

### What happens during uninstallation:

1. Deletes `field_feedback` field instance from service_request
2. Checks if field storage is used by other bundles
3. Deletes field storage if only used by service_request
4. Purges field data from database (batch operation)
5. Deletes module configuration
6. Logs all cleanup operations to watchdog

### Safe Uninstall

The uninstall hook includes safety checks:
- Only deletes field storage if no other bundles use the field
- Warns if field is used by other bundles
- Initiates field data purge (batch size: 100)

## Configuration

Navigate to **Admin → Configuration → Mark-a-Spot → Feedback Settings** (`/admin/config/markaspot/feedback`) to configure:

- Feedback form settings
- Rating options
- Email notifications for feedback
- Comment moderation settings

After installation, you can also configure:

1. **Field Display**: Manage field display settings at:
   - Form: `/admin/structure/types/manage/service_request/form-display`
   - View: `/admin/structure/types/manage/service_request/display`

2. **Field Settings**: Edit field settings at:
   - `/admin/structure/types/manage/service_request/fields/node.service_request.field_feedback`

3. **Permissions**: Field permissions are managed via field_permissions:
   - Current setting: Public (all users can view/edit)
   - Modify at: Field settings page

## Dependencies

- Drupal Core 11.x
- node module
- text module
- field_permissions module
- Mark-a-Spot (markaspot profile)

## Integration

This module works with:

- Mark-a-Spot service request workflow
- JSON:API for headless access
- Email notifications
- User management
- Analytics reporting

## Usage

### Programmatic Access

```php
// Load a service request node
$node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);

// Get feedback value
$feedback = $node->get('field_feedback')->value;

// Set feedback value
$node->set('field_feedback', 'Additional citizen feedback...');
$node->save();
```

### JSON:API Access

The field is accessible via JSON:API at:
```
GET /jsonapi/node/service_request/{uuid}
```

Field will appear in the response attributes:
```json
{
  "data": {
    "type": "node--service_request",
    "id": "uuid",
    "attributes": {
      "field_feedback": "Additional feedback text..."
    }
  }
}
```

## Troubleshooting

### Field not appearing in forms

```bash
# Clear cache
ddev drush cr

# Verify field exists
ddev drush field:info node service_request

# Check form display
ddev drush config:get core.entity_form_display.node.service_request.default content.field_feedback
```

### Permission issues

```bash
# Check field permissions
ddev drush config:get field.storage.node.field_feedback third_party_settings

# Verify field_permissions module is enabled
ddev drush pm:list --filter=field_permissions
```

### Uninstall issues

```bash
# Check watchdog logs
ddev drush watchdog:show --type=markaspot_feedback

# Manually purge field data
ddev drush php:eval "field_purge_batch(100);"

# Check field map
ddev drush php:eval "print_r(\Drupal::service('entity_field.manager')->getFieldMap()['node']['field_feedback'] ?? 'Field not found');"
```

## Technical Notes

- Extends Drupal's comment system
- Implements custom feedback forms
- Provides services for feedback processing
- Integrates with user notifications
- Uses Drupal Field API for field management
- Implements proper install/uninstall hooks
- Idempotent installation process
- Safe uninstallation with field usage checks

## Development

### Install Hook (`markaspot_feedback_install()`)

Located in `markaspot_feedback.install`:
- Creates field storage and instance
- Configures displays
- Idempotent (safe to run multiple times)

### Uninstall Hook (`markaspot_feedback_uninstall()`)

Located in `markaspot_feedback.install`:
- Removes field instance
- Removes field storage (if safe)
- Purges field data
- Cleans up configuration

### Helper Functions

- `_markaspot_feedback_create_feedback_field()`: Creates field and displays
- `_markaspot_feedback_delete_feedback_field()`: Removes field and purges data

## Testing

```bash
# Test installation
ddev drush pm:enable markaspot_feedback -y
ddev drush field:info node service_request | grep field_feedback

# Test uninstallation
ddev drush pm:uninstall markaspot_feedback -y
ddev drush field:info node service_request | grep field_feedback

# Test reinstallation (idempotency)
ddev drush pm:enable markaspot_feedback -y
ddev drush watchdog:show --type=markaspot_feedback --count=10

# Verify field permissions
ddev drush config:get field.storage.node.field_feedback third_party_settings
```

## Logging

All operations are logged to watchdog with type `markaspot_feedback`:

```bash
# View logs
ddev drush watchdog:show --type=markaspot_feedback

# Watch logs in real-time
ddev drush watchdog:tail --type=markaspot_feedback
```

Log messages include:
- Field storage creation
- Field instance creation
- Display configuration
- Field deletion
- Data purge initiation
- Safety warnings

## Contributing

When modifying this module:

1. Follow Drupal coding standards
2. Test install/uninstall hooks thoroughly
3. Ensure idempotency of install hook
4. Add appropriate logging
5. Update this README
6. Test with field_permissions module

## License

GPL-2.0-or-later

## Maintainers

Part of the Mark-a-Spot distribution.