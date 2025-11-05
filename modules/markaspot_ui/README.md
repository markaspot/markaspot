# Mark-a-Spot UI

This module provides a centralized toolbar integration and settings management interface for all Mark-a-Spot modules.

## Features

- Dynamically discovers all Mark-a-Spot modules with settings pages
- Provides a toolbar item with quick access to all module settings
- Creates a centralized settings overview page
- Configurable post-login redirect for streamlined workflows
- Headless mode protection for decoupled architectures
- Uses proper caching for optimal performance
- Automatically updates when modules are installed or uninstalled

## Usage

After enabling the module, you'll see a new "Mark-a-Spot" icon in the admin toolbar.
Clicking this icon will reveal links to the settings pages of all installed Mark-a-Spot modules.

You can also access the settings overview page directly at `/admin/config/markaspot/ui`.

## Configuration

### Login Redirect

Configure automatic redirect after user login at `/admin/config/markaspot/ui`.

**Features:**
- Enable/disable automatic redirect after login
- Set custom redirect path (default: `/admin/content/management`)
- Automatic detection and preservation of password reset flow
- Security: validates paths to prevent open redirect vulnerabilities
- Respects existing `?destination` parameters

**Use Cases:**
- Redirect moderators directly to content management after login
- Create streamlined workflows for specific user roles
- Skip unnecessary steps in the login flow

**Password Reset Protection:**
The redirect is automatically disabled during password reset flow, ensuring users can properly complete password changes without interference.

**Example:**
```yaml
# config/sync/markaspot_ui.settings.yml
login_redirect_enabled: true
login_redirect_path: '/admin/content/management'
```

### Headless Mode Protection

For decoupled/headless setups where content is displayed through a separate frontend application (like Nuxt).

**When enabled:**
- Anonymous users accessing Drupal UI paths are redirected to `/user/login`
- Protects: `/admin`, `/node`, `/taxonomy`, `/media`, etc.
- Preserves: JSON:API endpoints, authentication paths, authenticated access

**When disabled:**
- Normal Drupal behavior (for traditional theme-based installations)

## Technical Details

### Module Discovery

The module dynamically detects any installed module with:
1. A name starting with `markaspot_`
2. A valid settings route (checking common route patterns)

It also includes specific non-prefixed modules that are part of the Mark-a-Spot ecosystem.

### Login Redirect Implementation

Uses an EventSubscriber pattern for reliable, secure redirects:

```php
// Service: markaspot_ui.login_redirect_subscriber
// Class: \Drupal\markaspot_ui\EventSubscriber\LoginRedirectSubscriber
// Priority: 10 (runs after authentication, before default redirect)
```

**Security measures:**
- Path validation prevents external URLs (open redirect protection)
- Checks for password reset session to avoid interference
- Respects existing destination parameters
- Logs errors for invalid paths