# How to deploy to Tugboat

* The tugboat database and files directory can be placed in [Dropbox](https://www.dropbox.com/work/Lullabot/Front-End%20Development/Olivero/Tugboat%20Files) by a user with the appropriate permissions.
* To generate a database dump:
  * `drush sql-dump > olivero-db.sql`
  * `gzip olivero-db.sql`
  * Then upload the file to the Dropbox folder above.
  * Modify the `PRELOADED_DB_DUMP` [Tugboat environment setting](https://dashboard.tugboat.qa/5defe1da41466e70268be4fc/settings/)
* To generate the files zip file
  * `cd` into `sites/default/files`
  * run `zip path/to/zipfile.zip -r *`
  * Upload the file into the Dropbox folder above.
  * Modify the `PRELOADED_FILES_ZIP` [Tugboat environment setting](https://dashboard.tugboat.qa/5defe1da41466e70268be4fc/settings/)
* To add modules
  * Add new lines similar to `composer require drupal/webform` into line 30(ish) at within this repository's `.tugboat/config.yml` file.
  * Ensure that the modules are enabled in the database (we're not importing config).

For more information on Tugboat, visit [https://tugboat.qa/](Tugboat.qa). Tugboat's pretty awesome 😍!
