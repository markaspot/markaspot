# Changelog

## [11.9.2](https://github.com/markaspot/markaspot/compare/11.9.1...11.9.2) (2026-02-26)

### Features

* **group:** category filtering for child jurisdictions with allow-list ([21b5ee7](https://github.com/markaspot/markaspot/commit/21b5ee75356bf8b8d7bd4c2f1068c09f38248ddc))
* **group:** make pages jurisdiction-aware with sticky start page ([9b98c81](https://github.com/markaspot/markaspot/commit/9b98c81791816e939f0a18e768b7679359b88de7))

### Bug Fixes

* **group:** harden update hook idempotency and relax multi-tenant test ([ce4ad93](https://github.com/markaspot/markaspot/commit/ce4ad9368f3efa1ece01d6bc37816fe5ea784b90))

## [11.9.1](https://github.com/markaspot/markaspot/compare/11.9.0...11.9.1) (2026-02-26)

### Features

* configurable jurisdiction/organisation visibility in API response ([fe19fe6](https://github.com/markaspot/markaspot/commit/fe19fe64794ceaa259a447bc9d822f514a11eada))
* **dashboard:** internal remarks, status note authors, session handoff, user matrix ([6bb2228](https://github.com/markaspot/markaspot/commit/6bb2228c87fa4222bf332ce0e732805e1fb9cf8d))
* **escalation:** add markaspot_escalation module with jurisdiction-aware delegation ([c775916](https://github.com/markaspot/markaspot/commit/c77591688fe48fea56e1c833662b2f2212104fdb))
* **group:** jurisdiction hierarchy, org derivation, taxonomy inheritance, backfill hooks ([84f7af5](https://github.com/markaspot/markaspot/commit/84f7af5ad67bed7994904bf50e8e2342d7fa98cd))
* **group:** members matrix with hierarchy headers, CSRF enforcement, N+1 fix ([3868844](https://github.com/markaspot/markaspot/commit/3868844bf473b59f6aab553fca5a37de83c372fd))
* hierarchical jurisdiction resolution with API parameter cleanup ([d7f9d2b](https://github.com/markaspot/markaspot/commit/d7f9d2b44610eb1e215a2a56a80f8e1fb52ba3a7))
* **multi-tenant:** GeoReport jurisdiction filtering, boundary validation, emergency scoping ([4fc4e62](https://github.com/markaspot/markaspot/commit/4fc4e621b4d40a4da58fa8faba13866cce7be19a))
* **nuxt-api:** settings API extensions, geocoding config, request ID service, JSON schema ([5494528](https://github.com/markaspot/markaspot/commit/549452821e89769b4892b9c3fc044641c3631db0))
* **tenant_admin:** add per-jurisdiction taxonomy management module ([5df8bc8](https://github.com/markaspot/markaspot/commit/5df8bc8dc0c7088ecb411614c99da8ec4da364c3))
* **vision:** jurisdiction-aware AI processing, language translation, GDPR gate ([ebeebcf](https://github.com/markaspot/markaspot/commit/ebeebcfaaa56a03853dcae68fa54bcdfd3d7ba46))

### Bug Fixes

* **group:** jurisdiction-aware initial status for multi-tenant requests ([c047f2f](https://github.com/markaspot/markaspot/commit/c047f2ff7053652376496af33d815a11ae1c0618))

## [11.9.0] - 2026-02-26

### Features
- **ai:** process both embedding and duplicate scan queues ([4a5c39c](https://github.com/markaspot/markaspot/commit/4a5c39c))
- **contact:** add markaspot_contact REST API module ([eb3dded](https://github.com/markaspot/markaspot/commit/eb3dded))
- **group:** auto-assign service requests to sub-jurisdictions by boundary ([793803a](https://github.com/markaspot/markaspot/commit/793803a))
- **nuxt:** add /api/fonts.css endpoint for custom font delivery ([b0b89c4](https://github.com/markaspot/markaspot/commit/b0b89c4))
- **nuxt:** add conditionalFields schema, deprecate legacy category arrays ([f7426b1](https://github.com/markaspot/markaspot/commit/f7426b1))
- **nuxt:** add systemNotice to JSON schema for admin UI editing ([687cb9f](https://github.com/markaspot/markaspot/commit/687cb9f))
- **nuxt:** extend config schema with feature flags and category arrays ([e9c067f](https://github.com/markaspot/markaspot/commit/e9c067f))
- **nuxt:** pass systemNotice from field_nuxt_config to settings API ([30798b0](https://github.com/markaspot/markaspot/commit/30798b0))
- **open311:** add Accept-Language support to single request endpoint ([04f59b5](https://github.com/markaspot/markaspot/commit/04f59b5))
- **open311:** restrict sensitive fields to managers and add gid stats filter ([73ca19f](https://github.com/markaspot/markaspot/commit/73ca19f))
- **service-provider:** add form_alter for read-only notes display ([b4b3f60](https://github.com/markaspot/markaspot/commit/b4b3f60))
- **vision:** add AI hazard and privacy fields to media entity ([43b6923](https://github.com/markaspot/markaspot/commit/43b6923))

### Bug Fixes
- add Vary header for Accept-Language on GeoReport API responses ([988787c](https://github.com/markaspot/markaspot/commit/988787c))
- **ai:** count all service requests in processing status ([b5dd420](https://github.com/markaspot/markaspot/commit/b5dd420))
- **markaspot_group:** change jur-admin scope from insider to individual ([2a8cc44](https://github.com/markaspot/markaspot/commit/2a8cc44))
- **markaspot_group:** add update hooks for group role scope fixes ([66ce851](https://github.com/markaspot/markaspot/commit/66ce851))
- **open311:** use site default language instead of hardcoded 'en' fallback ([2d4e1f9](https://github.com/markaspot/markaspot/commit/2d4e1f9))

### Refactoring
- **open311:** extract language negotiation into shared trait ([5c65b0f](https://github.com/markaspot/markaspot/commit/5c65b0f))

## [11.8.0] - 2026-02-02

### Features
- **ai:** add markaspot_ai module for AI-powered features ([c899888](https://github.com/markaspot/markaspot/commit/c899888))
- **cap:** add CAP 1.2 export module + fix Drush 13 commands ([b244a00](https://github.com/markaspot/markaspot/commit/b244a00))
- **config:** add service_request fields and WMS layer support ([673b029](https://github.com/markaspot/markaspot/commit/673b029))
- **dashboard:** add markaspot_dashboard module, fix AI cron ENV check ([b2645c8](https://github.com/markaspot/markaspot/commit/b2645c8))
- **nuxt:** add custom color picker with Tailwind presets
- **nuxt:** add groupTypes config for JSON:API relationships
- **open311:** add nid sort field for numeric ID sorting
- **profile:** add jur group type config for multi-tenant support
- **service_provider:** add ECA event for SP response notifications
- **update:** add field_all_groups_member for Group 3 upgrade
- **vision:** add hazard level and category fields with update hook

### Bug Fixes
- **deps:** remove npm-asset/leaflet.heat
- **markaspot_group:** cast target_id to int for JSON:API compatibility
- **markaspot_nuxt:** fix XSS vulnerability and remove unused code
- **nuxt_config:** simplify oneOf patterns to object type
- **open311:** prevent leading comma in address formatting
- **open311:** prevent null reference error when status_term is missing
- **publisher:** process all categories in each cron run
- filter stats API by content language
- update info block

### Refactoring
- **nuxt:** make json_form_widget a soft dependency
- move StatusNoteController from markaspot_nuxt to markaspot_dashboard
- **vision:** use CAP standard codes for hazard categories

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
