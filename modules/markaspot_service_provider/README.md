# Mark-a-Spot Service Provider Module

Provides service provider workflow management for Mark-a-Spot service requests.

## Overview

This module handles the service provider side of the service request workflow, allowing external service providers to:

- Access a dedicated response form at `/service-response/{uuid}`
- Submit completion notes and mark requests as completed
- Support multiple completions with reassignment workflow
- Receive notifications via ECA (Event-Condition-Action) rules

## Features

### Service Provider Response Form

- **Route**: `/service-response/{uuid}`
- **Purpose**: Allows service providers to submit completion notes for assigned requests
- **Features**:
  - Display existing completion notes
  - Support for multiple completions (if reassignment is enabled)
  - Automatic form disabling after completion (unless reassignment allowed)
  - Option to mark request as completed with status change

### REST API

- **POST/PATCH** `/api/service-response/{uuid}` - Submit completion notes
- **GET** `/api/service-response/{uuid}` - Get service request details

#### Request Format (POST/PATCH)

```json
{
  "email_verification": "provider@example.com",
  "completion_notes": "Work completed successfully.",
  "set_status": true
}
```

#### Response Format

```json
{
  "message": "Service request completed by service provider",
  "nid": 123,
  "success": true
}
```

### Email Validation

The module validates service provider identity by comparing the provided email against the multi-value `field_sp_email` field on the service provider taxonomy term. This ensures only authorized providers can submit completions.

### Completion Tracking

Completions are stored in the multi-value `field_service_provider_notes` field with metadata:

```
[Completion notes text]

---
Completed by: provider@example.com
Completed on: 13.10.2025 - 14:30
Service Provider: Example Service Provider
```

### Reassignment Workflow

The module supports multiple completions through the `field_reassign_sp` flag:

- If `field_reassign_sp` is FALSE and completion notes exist: Form is disabled, API returns 403
- If `field_reassign_sp` is TRUE: Multiple completions are allowed

### Status Management

When `set_status` is enabled in a completion:

1. The request status is updated to the configured completion status (from settings)
2. An optional status note paragraph is created and attached to `field_notes`

## Configuration

### Settings Page

**Path**: `/admin/structure/markaspot/service-provider/settings`

**Completion Settings**:

- **Completion Status**: The status term to apply when a provider marks a request as completed
- **Completion Status Note**: Optional note text to add to status notes (supports tokens)
- **Allow Multiple Completions**: Global setting for multiple completion support

**Cron Notification Settings**:

- **Enable Cron Notifications**: Toggle periodic notifications to service providers
- **Notification Interval**: How often to check for pending requests (15 minutes to daily)

### Token Support

Status notes support Drupal tokens. Available tokens include:

- `[node:title]` - Service request title
- `[node:request_id]` - Service request ID
- `[node:field_address:locality]` - Request location
- And all other node tokens

## Required Fields

This module expects the following fields on service request nodes:

- `field_service_provider` (entity reference to taxonomy term) - Assigned service provider
- `field_service_provider_notes` (text_long, unlimited) - Completion notes storage
- `field_reassign_sp` (boolean) - Reassignment flag
- `field_status` (entity reference to taxonomy term) - Request status
- `field_notes` (entity reference to paragraph) - Status notes

The service provider taxonomy term should have:

- `field_sp_email` (email, unlimited) - Service provider email addresses

## Service Provider Taxonomy

The module references the `field_service_provider` field which should link to a service provider taxonomy vocabulary. Each term should have:

- **Name**: Service provider organization name
- **field_sp_email**: One or more email addresses (multi-value field)

## Email Notifications via ECA

Email notifications to service providers are handled using **ECA (Event-Condition-Action)** rules, similar to the `markaspot_resubmission` module pattern. This provides maximum flexibility and allows site administrators to customize notification logic without code changes.

### Setting Up ECA Notifications

1. **Install ECA Module**: Ensure the ECA module is installed and enabled
2. **Create an ECA Model**: Configure events to trigger notifications
3. **Use Helper Methods**: Access service provider emails via the service

### Getting Service Provider Emails

The module provides a helper method to retrieve service provider emails:

```php
$service = \Drupal::service('markaspot_service_provider.service_provider');
$emails = $service->getServiceProviderEmails($node);

// Returns array of email addresses from field_sp_email on the service provider term
// Example: ['provider@example.com', 'backup@example.com']
```

### Cron-based Notifications (Optional)

When **Enable Cron Notifications** is enabled in module settings:

- The module's cron hook runs at the configured interval
- It finds all service requests with assigned service providers
- Logs the count of eligible requests
- Can be integrated with ECA to trigger notification events

This allows for periodic reminder emails to service providers about pending work.

### Example ECA Use Cases

- **Assignment Notification**: Send email when `field_service_provider` is set
- **Reminder Notification**: Use cron to send periodic reminders about pending requests
- **Completion Notification**: Notify admin when provider submits completion notes
- **Custom Logic**: Add conditions based on status, category, location, etc.

## Security

- Email validation ensures only authorized service providers can submit completions
- UUID-based routing prevents enumeration of service requests
- REST endpoints are publicly accessible but require email verification
- Form access requires 'access content' permission

## Integration

### With Mark-a-Spot Open311

This module integrates with Mark-a-Spot's service request workflow by:

- Using standard Mark-a-Spot field names and structures
- Supporting the same status workflow as citizen feedback
- Maintaining separation between citizen and provider interactions

### With Feedback Module

This module is separated from `markaspot_feedback`, which handles citizen satisfaction surveys. The two modules operate independently:

- **markaspot_feedback**: Citizen satisfaction surveys at `/feedback/{uuid}`
- **markaspot_service_provider**: Provider completion tracking at `/service-response/{uuid}`

## Development

### Service Class

The `ServiceProviderService` class provides helper methods:

- `getServiceProviderEmails($node)` - Get all valid provider emails for a request (for use in ECA rules)
- `isReassignmentAllowed($node)` - Check if multiple completions are allowed
- `getCompletionNotes($node)` - Get all completion notes for a request

### Controller Methods

The `ServiceProviderController` provides:

- `updateResponse($uuid, Request)` - Handle POST/PATCH completion submissions
- `getServiceRequest($uuid)` - Handle GET requests for service request data

Protected helper methods:
- `validateServiceProviderEmail($node, $email)` - Validate provider email
- `addServiceProviderCompletion($node, $email, $notes)` - Add completion with metadata
- `addStatusNote($node, $note_text)` - Add status note paragraph
- `getServiceProviderEmails($node)` - Get valid provider emails

## License

GPL-2.0+

## Maintainers

Part of the Mark-a-Spot project.
