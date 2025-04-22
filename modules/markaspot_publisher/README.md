# Mark-a-Spot Publisher

This module provides automatic publishing capabilities for service requests in Mark-a-Spot.

## Features

- Configurable automatic publishing of service requests based on:
  - Time elapsed since creation
  - Request status
  - Category-specific settings
- Respects intentional unpublishing by administrators
- Background processing via cron jobs

## Configuration

Navigate to **Admin → Configuration → Mark-a-Spot → Publisher Settings** (`/admin/config/markaspot/publisher`) to configure:

- Publishable status terms
- Default publishing period (days)
- Manual unpublish detection threshold
- Cron settings (enable/disable, interval)
- Category-specific overrides

## Integration

This module works with:

- Mark-a-Spot service request workflow
- Taxonomy-based status management
- Drupal's cron system

## Technical Notes

- Uses a queue worker to process publishing operations
- Category-specific overrides via taxonomy term fields
- Implements safeguards against publishing manually unpublished content