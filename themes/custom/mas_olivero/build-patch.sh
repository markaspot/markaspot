#!/bin/sh

# ## Prepare Drupal directory.
# 1. Ensure that 9.1.x branch is checked out.
# 2. chmod  -R 777 sites && git reset && git checkout . && git clean -df && git status
# 3. Pull the latest code.
# 4. Checkout a new branch. Call it the name of the latest tag.
#
# ## Prepare the core patch branch within Olivero.
# 1. Rebase the 8.x-1.x branch into the core-patch branch
#
# ## Prepare script
# 1. Update path of DRUPAL_ROOT variable
# 2. Update comment number.
# 3. Move script to its own directory. mv build-patch.sh ../oliveropatchscript/script.sh
#
# ## Composer changes.
# Add "drupal/olivero": "self.version", to core/composer.json
# Run COMPOSER_ROOT_VERSION=9.1.x-dev composer update drupal/core
#
# ## Run script
# 1. chmod +x build-patch.sh
# 2. ./build-patch.sh
# 3. Patch will be placed in DRUPAL_ROOT
#
# ## Generate Interdiff
# 1. git commit -m "<latest tag>"
# 2. git diff <prev tag> > interdiff.patch
#
# ## Test patch
# 1. git checkout 9.1.x
# 2. git checkout .
# 3. git apply <patch>
# 4. composer install
# 5. Install Drupal (using SQLite) and verify theme works.
#


COMMENT_NUM=220
ISSUE_NUM=3111409
DRUPAL_ROOT='/Users/mherchel/sites/drupal9head'

echo "\n[ Make sure the olivero is deleted ]"
rm -rf $DRUPAL_ROOT/core/themes/olivero

echo "\n[ Clone Olivero contrib theme into core/themes/olivero ]"
cd $DRUPAL_ROOT/core/themes
git clone https://git.drupal.org/project/olivero.git

echo "\n[ Checkout core-patch branch ]"
cd $DRUPAL_ROOT/core/themes/olivero
git checkout core-patch

echo "\n[ Remove the contrib module's git history ]"
rm -rf .git

echo "\n[ Remove unwanted files and directories ]"
rm -rf scripts
rm composer.json
rm README.md
rm LICENSE.txt
rm CODE_OF_CONDUCT.md
rm drupalci.yml
rm .eslintignore
rm .eslintrc.json
rm .eslintrc.passing.json
rm .gitignore
rm .prettierrc.json
rm package.json
rm yarn.lock
rm build-patch.sh
rm -rf .tugboat

# Return to the Drupal root directory.
cd $DRUPAL_ROOT

# Overwrite core stylelint.
mv core/themes/olivero/.stylelintrc.json core/.stylelintrc.json

# Move cspell config and dictionary to appropriate core directories.
mv core/themes/olivero/.cspell.json core/.cspell.json
mv core/themes/olivero/dictionary.txt core/misc/cspell/dictionary.txt

# Move tests to the right folder.
# mkdir core/tests/Drupal/FunctionalJavascriptTests/Theme
# mv core/themes/olivero/tests/src/FunctionalJavascript/* core/tests/Drupal/FunctionalJavascriptTests/Theme
mv core/themes/olivero/tests/src/Functional/* core/tests/Drupal/FunctionalTests/Theme

echo "\n[ Ignore the style linting??? ]"
echo "themes/olivero/**/*.css
!themes/olivero/**/*.pcss.css" >> $DRUPAL_ROOT/core/.stylelintignore

echo "\n[ Make olivero known to composer ]"
sed -i 's|"drupal/node": "self.version",|"drupal/node": "self.version",\n        "drupal/olivero": "self.version",|g' $DRUPAL_ROOT/core/composer.json

echo "\n[ Run yarn prettier ]"
cd $DRUPAL_ROOT/core

# Return to the Drupal root directory.
cd $DRUPAL_ROOT

echo "\n[ Create the source-only patch ]"
git add $DRUPAL_ROOT/core/themes/olivero
git add $DRUPAL_ROOT/core/composer.json
git add $DRUPAL_ROOT/composer.lock
git add $DRUPAL_ROOT/core/.stylelintignore
git add $DRUPAL_ROOT/core/.stylelintrc.json
git add $DRUPAL_ROOT/core/.cspell.json
git add $DRUPAL_ROOT/core/misc/cspell/dictionary.txt
# git add $DRUPAL_ROOT/core/tests/Drupal/FunctionalJavascriptTests/Theme
git add $DRUPAL_ROOT/core/tests/Drupal/FunctionalTests/Theme

# Remove all CSS files from staging, and then add back in only the postcss files.
git reset $DRUPAL_ROOT/core/themes/olivero/**/**/*.css
git add $DRUPAL_ROOT/core/themes/olivero/**/**/*.pcss.css

# Remove all JS files from staging, and then add back in only the ES6 files.
git reset $DRUPAL_ROOT/core/themes/olivero/**/**/*.js
git add $DRUPAL_ROOT/core/themes/olivero/**/**/*.es6.js

git diff --staged -C -C > ~/Downloads/$ISSUE_NUM-$COMMENT_NUM-add-olivero-source-only.patch

echo "\n[ Build all the CSS files ]"
cd $DRUPAL_ROOT/core

# Install node packages as defined in core/package.json.
yarn install

# Run the build commands for CSS and JS.
yarn build:css
yarn build:js

# Return to the Drupal root directory.
cd $DRUPAL_ROOT

echo "\n[ Create the main patch ]"
git add $DRUPAL_ROOT/core/themes/olivero
git diff --staged -C -C > ~/Downloads/$ISSUE_NUM-$COMMENT_NUM-add-olivero.patch

# Clean up.
# rm -rf $DRUPAL_ROOT/core/themes/olivero
# git reset --hard HEAD
