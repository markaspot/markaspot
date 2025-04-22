# Mark-a-Spot Request ID

This module provides unique identifier management for service requests in Mark-a-Spot.

## Features

- Automatic generation of unique request IDs
- Configurable ID formats and patterns
- Reference handling for request identification
- Search by request ID

## Configuration

Navigate to **Admin → Configuration → Mark-a-Spot → Request ID Settings** (`/admin/config/markaspot/request-id`) to configure:

- ID generation patterns
- Prefix and suffix options
- Numbering systems
- Display settings

## Integration

This module works with:

- Mark-a-Spot service request workflow
- Search functionality
- Views and displays
- Open311 API integration

## Technical Notes

- Implements entity hooks for ID generation
- Provides token integration
- Handles ID uniqueness and validation
- Supports migration of legacy IDs