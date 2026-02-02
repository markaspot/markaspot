# Mark-a-Spot Resubmission

This module provides functionality for handling request resubmissions in Mark-a-Spot.

## Features

- Resubmission workflow for service requests
- Notification system for resubmission requests
- Configuration for resubmission criteria
- Admin interface for managing resubmissions

## Configuration

Navigate to **Admin → Configuration → Mark-a-Spot → Resubmission Settings** (`/admin/config/markaspot/resubmission`) to configure:

- Resubmission timing settings
- Notification templates
- Workflow conditions
- User permissions

## Integration

This module works with:

- Mark-a-Spot service request workflow
- Email notifications
- Status management
- User management

## Technical Notes

- Implements cron-based resubmission processing
- Provides services for notification generation
- Integrates with workflow state management
- Handles email communication with requesters