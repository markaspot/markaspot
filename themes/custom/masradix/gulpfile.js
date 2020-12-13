// Include gulp.
var gulp = require('gulp');
var browserSync = require('browser-sync').create();
var config = require('./config.json');

// Include plugins.
var sass = require('gulp-sass');
var imagemin = require('gulp-imagemin');
var pngcrush = require('imagemin-pngcrush');
var shell = require('gulp-shell');
var plumber = require('gulp-plumber');
var notify = require('gulp-notify');
var autoprefix = require('gulp-autoprefixer');
var glob = require('gulp-sass-glob');
var uglify = require('gulp-uglify');
var concat = require('gulp-concat');
var rename = require('gulp-rename');
var sourcemaps = require('gulp-sourcemaps');


// Compress images.
gulp.task('images', () =>
  gulp.src(config.images.src)
    .pipe(imagemin({
      progressive: true,
      svgoPlugins: [{ removeViewBox: false }],
      use: [pngcrush()]
    }))
    .pipe(gulp.dest(config.images.dest))
);

// CSS.
gulp.task('css', gulp.series('images', () =>
  gulp.src(config.css.src)
    .pipe(glob())
    .pipe(plumber({
      errorHandler: function (error) {
        notify.onError({
          title:    "Gulp",
          subtitle: "Failure!",
          message:  "Error: <%= error.message %>",
          sound:    "Beep"
        }) (error);
        this.emit('end');
      }}))
    .pipe(sourcemaps.init())
    .pipe(sass({
      style: 'compressed',
      errLogToConsole: true,
      includePaths: config.css.includePaths
    }))
    .pipe(autoprefix('last 2 versions', '> 1%', 'ie 9', 'ie 10'))
    .pipe(sourcemaps.write('./'))
    .pipe(gulp.dest(config.css.dest))
    .pipe(browserSync.reload({ stream: true, match: '**/*.css' }))
));



// Fonts.
gulp.task('fonts', () =>
  gulp.src(config.fonts.src)
    .pipe(gulp.dest(config.fonts.dest))
);

// Watch task.
gulp.task('watch', () =>
  gulp.watch(config.css.src, gulp.series('css'))
);

// Static Server + Watch

gulp.task('serve', gulp.series('css', 'fonts', 'watch', () =>
  browserSync.init({
    proxy: config.proxy
  })
));

// Run drush to clear the theme registry.
gulp.task('drush', () =>
   shell.task([
  'drush cache-clear theme-registry'
  ]
 ));

// Default Task
gulp.task('default', gulp.series('css', 'serve', (done) => {

  // image changes
  gulp.watch(config.images.src, gulp.series('images'));

  // CSS changes
  gulp.watch(config.css.src.watch, gulp.series('css'));

  done();

}));

// gulp.task('default', gulp.series('default'));
