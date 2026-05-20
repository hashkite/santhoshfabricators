/**
 * @file
 * Gulpfile for Power Alpha Drupal theme.
 *
 * Tasks:
 *   gulp build  – Compile SCSS → CSS (production, minified)
 *   gulp watch  – Watch SCSS files and rebuild on change
 */

const gulp = require('gulp');
const sass = require('gulp-sass')(require('sass'));
const cleanCSS = require('gulp-clean-css');
const rename = require('gulp-rename');
const sourcemaps = require('gulp-sourcemaps');

// Paths
const paths = {
  scss: {
    src: 'scss/style.scss',
    watch: 'scss/**/*.scss',
  },
  css: {
    dest: 'css/',
  },
};

/**
 * Compile SCSS → CSS with sourcemaps (development).
 */
function compileSCSS() {
  return gulp
    .src(paths.scss.src)
    .pipe(sourcemaps.init())
    .pipe(
      sass({
        outputStyle: 'expanded',
        includePaths: ['scss'],
      }).on('error', sass.logError)
    )
    .pipe(sourcemaps.write('.'))
    .pipe(gulp.dest(paths.css.dest));
}

/**
 * Compile SCSS → minified CSS (production).
 */
function buildCSS() {
  return gulp
    .src(paths.scss.src)
    .pipe(
      sass({
        outputStyle: 'compressed',
        includePaths: ['scss'],
      }).on('error', sass.logError)
    )
    .pipe(cleanCSS({ level: 2 }))
    .pipe(gulp.dest(paths.css.dest));
}

/**
 * Watch SCSS files for changes.
 */
function watchFiles() {
  gulp.watch(paths.scss.watch, compileSCSS);
}

// Tasks
gulp.task('default', gulp.series(compileSCSS, watchFiles));

exports.compile = compileSCSS;
exports.build = buildCSS;
exports.watch = gulp.series(compileSCSS, watchFiles);
exports.default = gulp.series(compileSCSS, watchFiles);
