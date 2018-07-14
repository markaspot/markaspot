// webpack.config.js

const path = require('path');
const webpack = require('webpack');

module.exports = {
  context: path.resolve(__dirname, './js'),
  entry: {
    app: './map.es6.js',
  },
  watchOptions: {
    aggregateTimeout: 300,
    poll: 1000
  },
  output: {
    path: path.resolve(__dirname, './js'),
    filename: 'map.js',
  },
  module: {
    rules: [
      {
        test: /\.js$/,
        exclude: [/node_modules/],
        use: [
          {
            loader: 'babel-loader',
            options: {presets: ['env']}
          }
        ],
      },
    ],
  }
}
