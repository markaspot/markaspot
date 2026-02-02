# Mark-a-Spot Archive

This module provides automatic archiving functionality for service requests in Mark-a-Spot.

## Features

- Automatically archives service requests based on configurable criteria:
  - Time elapsed since creation
  - Request status
  - Category-specific settings
- Anonymizes personal data during archiving
- Background processing via cron jobs

## Configuration

Navigate to **Admin → Configuration → Mark-a-Spot → Archive Settings** (`/admin/config/markaspot/archive`) to configure:

- Archivable status terms
- Default archiving period (days)
- Field anonymization settings
- Cron settings (enable/disable, interval)
- Category-specific overrides

## Integration

This module works with:

- Mark-a-Spot service request workflow
- Mark-a-Spot privacy features
- Taxonomy-based status management
- Drupal's cron system

## Technical Notes

- Uses a queue worker to process archiving operations
- Category-specific overrides via taxonomy term fields
- Implements data privacy protections through field anonymization