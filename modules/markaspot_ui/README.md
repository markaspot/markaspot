# Mark-a-Spot UI

This module provides a centralized toolbar integration and settings management interface for all Mark-a-Spot modules.

## Features

- Dynamically discovers all Mark-a-Spot modules with settings pages
- Provides a toolbar item with quick access to all module settings
- Creates a centralized settings overview page
- Uses proper caching for optimal performance
- Automatically updates when modules are installed or uninstalled

## Usage

After enabling the module, you'll see a new "Mark-a-Spot" icon in the admin toolbar. 
Clicking this icon will reveal links to the settings pages of all installed Mark-a-Spot modules.

You can also access the settings overview page directly at `/admin/config/markaspot`.

## Technical Details

The module dynamically detects any installed module with:
1. A name starting with `markaspot_`
2. A valid settings route (checking common route patterns)

It also includes specific non-prefixed modules that are part of the Mark-a-Spot ecosystem.