// webpack.config.js
var Encore = require('@symfony/webpack-encore');
var CopyWebpackPlugin = require('copy-webpack-plugin');

Encore
    // the project directory where all compiled assets will be stored
    .setOutputPath('/optout/public/build/')

    // the public path used by the web server to access the previous directory
    .setPublicPath('./')

    .setManifestKeyPrefix('webpack/')

    // will create /optout/public/build/app.js and /optout/public/build/app.css
    .addEntry('app', './assets/js/app.js')

    // .addEntry('bootstrap', './assets/bootstrap/js/bootstrap.bundle.min.js')
    // .addEntry('tmpl',   './assets/js/tmpl.js')
    // .addEntry('jquery',   './assets/js/jquery.min.js')
    // .addEntry('moment',   './assets/js/moment.min.js')

    // allow legacy applications to use $/jQuery as a global variable
    .autoProvidejQuery()

    // enable source maps during development
    .enableSourceMaps(!Encore.isProduction())

    // empty the outputPath dir before each build
    .cleanupOutputBeforeBuild()

    // show OS notifications when builds finish/fail
    .enableBuildNotifications()

    .enableSingleRuntimeChunk()

    // create hashed filenames (e.g. app.abc123.css)
    // .enableVersioning()

    // allow sass/scss files to be processed
    // .enableSassLoader()

    .addPlugin(new CopyWebpackPlugin([
        { from: './assets/images', to: 'images' },
        { from: './assets/js', to: 'js' },
        { from: './assets/bootstrap/js/', to: 'js' },
        { from: './assets/amcharts4/', to: 'amcharts4' }
    ]))
;

// export the final configuration
module.exports = Encore.getWebpackConfig();
