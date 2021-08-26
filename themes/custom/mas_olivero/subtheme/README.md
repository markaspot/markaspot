
CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Installation
 * Configuration


INTRODUCTION
------------

Use these files to create a sub-theme of the Olivero theme included in Drupal 9
core. This is only recommended if you want to make minor tweaks and understand
that Olivero could break your modifications as it changes.


INSTALLATION
------------

Run this command to create an Olivero sub-theme:
core/scripts/create-subtheme.sh my_theme

This will generate a sub-theme with the machine name my_theme and the title
"My theme".


CONFIGURATION
-------------

In order to inherit block placement of Olivero, you need to make sure the
Olivero theme (the base theme) is installed and set as the site's default
theme, before you install your sub theme, and set it as default.
