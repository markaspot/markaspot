# Mark-a-Spot Radix Theme

This theme is intended for use with the Mark-a-Spot 3.x distro based on Drupal 8.

## Installation

MasRadix theme uses [Gulp](http://gulpjs.com) to compile Sass. Gulp needs Node.

#### Step 1
Make sure you have Node and npm installed. 
You can read a guide on how to install node here: https://docs.npmjs.com/getting-started/installing-node

#### Step 2
Install bower: `npm install -g bower`.

#### Step 3
Go to the root of MasRadix theme and run the following commands: `npm run setup`.

#### Step 4
Update `browserSyncProxy` in **config.json**.

#### Step 5
Run the following command to compile Sass and watch for changes: `gulp`.

#### Docksal
Using Docksal, install bower and gulp:

```
$ fin exec npm install bower -g
$ fin exec npm install gulp 
$ fin exec npm install

```