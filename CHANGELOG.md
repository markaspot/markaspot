
# Changelog

## [11.7.0] - 2025-01-23

### Added
- **Passwordless Authentication**: New module for passwordless login with configurable redirect and group support
- **Emergency Mode**: New module with translations and configuration options
- **Jurisdiction Support**: Added as optional group type for multi-tenant setups
- **Icon Module**: New markaspot_icon module for enhanced iconography
- **Publisher Module**: Queue-based publishing workflow for service requests
- **Stats Module**: Enhanced statistics module with improved routing
- **Confirm Module**: New confirmation flow module for Mark-a-Spot workflows
- **Feedback Module**: Service provider assignment feedback notes and citizen feedback validation by status
- **Address Field**: Added to GeoReport search functionality
- **Media Status Updates**: Support for delta-based updates via extended_attributes
- **Published Status Flag**: Added to Open311 API extended attributes
- **Media Alt Text**: Added to extended GeoReport property
- **Custom Token**: New token for use as site:url replacement in emails
- **Queue Workers**: Implemented for high-volume jobs and cron-related tasks
- **Field Disable**: Added field_disable_form for category terms
- **Dark Mode**: Added dark mode style for MapLibre

### Changed
- **Drupal 11 Compatibility**: Updated dependencies and constraints for Drupal 11 support
- **Map Configuration**: Separated map config for headless setups, made markaspot_map optional
- **Headless Architecture**: Cleaned up routes for decoupled Nuxt frontend with UUID-based routing
- **Group Module API**: Updated from deprecated GroupContent to GroupRelationship
- **Icon Picker**: Switched to modern iconpicker widget and library
- **MapBox Migration**: Removed MapBox dependencies, replaced with MapLibre
- **ECA to EventSubscriber**: Replaced ECA with EventSubscriber for email sending in resubmission module
- **API Performance**: Improved with caching and query optimization
- **Gin Theme Integration**: Updated Mark-a-Spot UI module to work with Gin theme and Gin Toolbar
- **Vector Styles**: Added fallback map vector style URL
- **Field Group**: Bumped to 4.0 to resolve doubled asterisk issue
- **Archive Days**: Refactored handling with new taxonomy term field for config overrides

### Fixed
- **markaspot_request_id**: Removed ineffective database update function, added field existence checks, fixed form labels, cleaned up legacy module references
- **GeoReport API**: Improved field handling and validation errors
- **Extended Attributes**: Ensured Drupal extended attributes appear in single request endpoint
- **Unpublished Nodes**: Enable user 1 and bypass node access users to view unpublished nodes via GeoReport API
- **Cookie Handling**: RemoveCookieSubscriber now checks session UID directly
- **Installation**: Resolved dependencies and cleaned up optional modules
- **Node Display**: Enhanced in Gin theme
- **Service Request Links**: Only link to published service requests
- **Migration**: Added migration from markaspot_map to markaspot_nuxt
- **Email Validation**: Restored email verification for citizen feedback, support multiple email addresses
- **File Deletion**: Prevented aggressive file deletion in markaspot_nuxt module
- **Page Parameters**: Restored page and offset parameters for service requests
- **Address Updates**: Fixed address update handling
- **Status Notes**: Fixed field_status changes to add status_note automatically
- **Symfony Compatibility**: Fixed Symfony incompatibility issues
- **MapLibre**: Fixed map initialization, scrolling, and library loading
- **Media URLs**: Fixed protocol handling to respect current request stack
- **Nominatim**: Improved formatting of Nominatim addresses
- **Type Safety**: Fixed type safety in GeoreportProcessorService and request_id module
- **Null Values**: Added fallback for empty initial status and undefined color properties
- **JSON/XML Format**: Switched to event subscriber to check for request format
- **Widget Hooks**: Updated deprecated __WIDGET_TYPE_form_alter hook in shstweak module
- **Library Aggregation**: Fixed JavaScript error when aggregation of MapBox library enabled

### Deprecated
- **markaspot_action_front**: Deprecated for headless architectures (optional for traditional themes)
- **markaspot_action_stats**: Deprecated for headless architectures (optional for traditional themes)

### Removed
- **MapBox**: Removed all MapBox dependencies in favor of MapLibre
- **Trend Module**: Removed deprecated trend module
- **Metatag Module**: Removed from dependencies
- **Views Data Export Hook**: Removed deprecated hook
- **Field Formatter Class**: Removed as dependency
- **Deprecated Actions**: Created update hook to remove deprecated actions

### Security
- **Headless Mode Protection**: Added protection for standard Drupal routes in headless mode
- **Session Handling**: Remove session when api_key is passed as form parameter

### Refactored
- **Code Quality**: Extensive linting and PHP_CodeSniffer fixes across multiple modules
- **Logging**: Reduced excessive logging in feedback, open311, and service provider modules
- **Publisher Module**: Refactored with Drupal best practices
- **Open311 Server**: Major refactoring of Open311 server code
- **Uninstall Hooks**: Added hooks to preserve crucial field data on uninstall
- **Service Provider**: Separated service provider from feedback module

## [10.6.0] - 2023-12-06
### New Features
- **Map Integrations**: Integrated Maplibre and Mapbox into a dedicated git package with added dependencies for enhanced map functionalities. Included maplibre-gl-js for improved map and widget integration.
- **Internationalization Enhancements**: Introduced multi-lingual support for terms and added a module for translation, broadening global usability.
- **User Interface Improvements**: Implemented scrolling pop-ups and fixed logo padding in the toolbar for an enhanced user experience.
- **API Flexibility**: Added a validation bypass for API users.

### Fixes and Improvements
- **Installation and Setup**: Corrected directory post-installation issues and updated maplibre-gl-leaflet constraints.
- **Library Updates**: Updated various dependencies and libraries in line with Drupal 10 requirements.
- **Performance Enhancements**: Numerous bug fixes and minor enhancements for overall stability and performance improvement.

## [9.5.1] - Previous Version
- The version prior to the updates and improvements leading to 10.6.0.

## [8.5.0] - 2020-07-01

- Update Core to 8.9.1
- Added Privacy (GDPR) Module
- Added Resubmission Module
- Added settings and reset to Static JSON Module
- Update Geolocation Nominatim Module
- Update dependent Drupal Modules

## [8.4.4] - 2018-09-19

- Fix installation issues with 8.6 and default content module (#74)

## [8.4.3] - 2018-09-17

- Update Core to >8.6
- Fix static file generation

## [8.4.2] - 2018-07-14

- Theme Updates.
- Add exif orientation module
- Make vue.js filter translatable
- Update Drush, VBO

## [8.4.1] - 2018-03-31

- Theme Updates.
- Add Stats Block.
- Fix markaspot/mark-a-spot#68 geoReport endpoint format detection.
- Update to Drupal 8.5.x

## [8.4.0-rc3] - 2018-02-19

- Bug fixes.
- Add js-files as es6 where needed.


## [8.4.0-rc2] - 2018-02-06

- Minified vue.js.

## [8.4.0-rc1] - 2018-02-06

- Fixed path for dateFormat library.
- Fixed link in footer, about page. Close markaspot/mark-a-spot#64 (travis)
- Fixed properties for title and request_id.
- Switch to node hook in several modules.
- Fixed category and status design in list teasers.
- Restructured default content, now with referenced images.
- Fixed button and selectbox styles.

## [8.4.0-beta1 - 8.4.0-beta4] 2018-02-04

- Fixed image upload widget.
- Added Mark-a-Spot Group module.
- Update components to handle new base field for request_ids.
- Added Mark-a-Spot Request ID module.
- Refactored ID-Generation (Module now deprecated)
- Added update hook for front page.
- Update stats view.
- Fixed a leaking cache error.
- Added Mark-a-Spot Front Page module for enabling panelized Home.
- Added Mark-a-Spot trend module.
- Added boostrap-select, Fixed buttons and shadows.
- Fixed status rest display.
- Added boostrap-select.
- Defined map request block in config.
- Switch shariff design.
- Changed footer layout, Fixed logo stuff.
- Added new config for stats, modify blocks for panelized pages.
- Fixed default image icons in teaser.
- Added Organisation and Status fields.
- Added all Mark-a-Spot modules to profile
- Added theme and module dependencies
- Added MasRadix theme and Geolocation Nominatim

## [8.3.2 - 8.3.3] 2017-11-14

- Update map module with composer installer extension.
- Move installer-extender to top.
- Added composer.lock to repo
- Added shariff as dependency.
- Added installer paths and Fixed geoPHP.

## [8.3.1] 2017-11-12

- Changed shariff library to dev-master.
- Updated Slideout library.
- Reduced repos and Added asset packagist.
- Fixed link for editing nodes via management view.
- Update Leaflet libries and Drupal core to 8.4
- Update Features and Addedress module

## [8.3.0] 2017-05-14

- Added shariff and font-awesome as library requirement (tag: 8.3.0-rc2, tag: 8.3.0)
- Removed message module dependency.
- Removed facets from .info.yml dependeny
- Added scripts for distro installation; remove facets.
- Issue #2878220 by tormi: Installation issue (a non-existent service "rest.link_manager")
- Added new custom token module, close markaspot/mark-a-spot#54 (tag: 8.3.0-rc1)
- Added some management view based on search api index.
- Added pathauto settings, change module order on install
- Updated dependencies and index and request view. (tag: 8.3.0-alpha1)
