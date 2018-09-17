
# Changelog

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
