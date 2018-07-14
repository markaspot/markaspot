# Installation profile of Mark-a-Spot D8 Distribution

This profile defines basic configuration and installation files. No make files here for now. 
Installation needs composer.json of the mark-a-spot dev repo.

JS Development:

```
$ fin bash
$ npm install --save-dev babel-loader webpack webpack-dev-server@2 webpack-cli -g
$ webpack -p --watch
```