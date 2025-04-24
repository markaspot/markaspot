# Mark-a-Spot SHS Tweak

This module provides enhancements to the Simple Hierarchical Select (SHS) widgets used in Mark-a-Spot and adds dynamic category information handling.

## Features

- Improved hierarchical select widgets
- Enhanced user experience for category selection
- Mobile-friendly dropdown improvements
- Custom styling for select widgets
- Category description display in forms
- Form disabling functionality based on category

## Integration

This module works with:

- Simple Hierarchical Select module
- Mark-a-Spot service request forms
- Category taxonomy management
- Form display configuration
- Frontend client applications via API endpoints

## API Endpoints

### Category Description API

Endpoint: `/api/markaspotshstweak/{term}/{last_child}`

This endpoint returns category information including:
- Description content
- Form control options (e.g., whether to disable the form)

Example response:
```json
{
  "description": "Category description content as HTML",
  "options": {
    "disableForm": true
  }
}
```

## Technical Notes

- Extends SHS widget functionality
- Provides custom JavaScript for widget behavior
- Implements styling improvements
- Enhances mobile usability for hierarchical selects
- Adds boolean field `field_disable_form` to service categories
- API integration with frontend applications

## Usage

### Disabling Forms for Specific Categories

1. Edit a service category term
2. Check the "Disable form" checkbox
3. Save the term

When this category is selected in the frontend report forms, the form will automatically be disabled, and users will see an informational message. This is useful for categories where reporting is temporarily suspended or being handled through other channels.