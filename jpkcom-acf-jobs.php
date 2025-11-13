<?php
/*
Plugin Name: JPKCom ACF Jobs
Plugin URI: https://github.com/JPKCom/jpkcom-acf-jobs
Description: Job application plugin for ACF
Version: 1.2.0
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com/
Contributors: JPKCom
Tags: ACF, Fields, CPT, CTT, Taxonomy, Forms
Requires Plugins: advanced-custom-fields-pro, acf-quickedit-fields
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 8.3
Network: true
Stable tag: 1.2.0
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
Text Domain: jpkcom-acf-jobs
Domain Path: /languages
*/

use JPKComAcfJobsGitUpdate\JPKComGitPluginUpdater;

if ( ! defined( constant_name: 'WPINC' ) ) {
    die;
}

/**
 * Plugin Constants
 */
if ( ! defined( 'JPKCOM_ACFJOBS_VERSION' ) ) {
	define( 'JPKCOM_ACFJOBS_VERSION', '1.2.0' );
}

if ( ! defined( 'JPKCOM_ACFJOBS_BASENAME' ) ) {
	define( 'JPKCOM_ACFJOBS_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'JPKCOM_ACFJOBS_PLUGIN_PATH' ) ) {
	define( 'JPKCOM_ACFJOBS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'JPKCOM_ACFJOBS_PLUGIN_URL' ) ) {
	define( 'JPKCOM_ACFJOBS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}


/**
 * Initialize Plugin Updater
 */
add_action( 'init', static function (): void {
	$updater_file = JPKCOM_ACFJOBS_PLUGIN_PATH . 'includes/class-plugin-updater.php';

	if ( file_exists( $updater_file ) ) {
		require_once $updater_file;

		if ( class_exists( 'JPKComAcfJobsGitUpdate\\JPKComGitPluginUpdater' ) ) {
			new \JPKComAcfJobsGitUpdate\JPKComGitPluginUpdater(
				plugin_file: __FILE__,
				current_version: JPKCOM_ACFJOBS_VERSION,
				manifest_url: 'https://jpkcom.github.io/jpkcom-acf-jobs/plugin_jpkcom-acf-jobs.json'
			);
		}
	}
}, 5 );


/**
 * Load language files
 */
function jpkcom_acfjobs_textdomain(): void {
    load_plugin_textdomain(
        'jpkcom-acf-jobs',
        false,
        dirname( path: JPKCOM_ACFJOBS_BASENAME ) . '/languages'
    );
}

add_action( 'plugins_loaded', 'jpkcom_acfjobs_textdomain' );


/**
 * Load file with overwrite logic
 */
function jpkcom_acfjobs_locate_file( string $filename ): ?string {

    $paths = [
        get_stylesheet_directory() . '/jpkcom-acf-jobs/' . $filename,
        get_template_directory() . '/jpkcom-acf-jobs/' . $filename,
        WPMU_PLUGIN_DIR . '/jpkcom-acf-jobs-overrides/' . $filename,
        JPKCOM_ACFJOBS_PLUGIN_PATH . 'includes/' . $filename,
    ];

    $paths = apply_filters( 'jpkcom_acfjobs_file_paths', $paths, $filename );

    foreach ( $paths as $path ) {

        if ( file_exists( filename: $path ) ) {

            return $path;

        }

    }

    return null;

}


/**
 * Media functions
 */
$jpkcomAcfJobMedia = jpkcom_acfjobs_locate_file( filename: 'media.php' );

if ( $jpkcomAcfJobMedia ) {

    require_once $jpkcomAcfJobMedia;

}


/**
 * Archive functions
 */
$jpkcomAcfJobArchive = jpkcom_acfjobs_locate_file( filename: 'archive.php' );

if ( $jpkcomAcfJobArchive ) {

    require_once $jpkcomAcfJobArchive;

}


/**
 * Breadcrumb functions
 */
$jpkcomAcfJobBreadcrumb = jpkcom_acfjobs_locate_file( filename: 'breadcrumb.php' );

if ( $jpkcomAcfJobBreadcrumb ) {

    require_once $jpkcomAcfJobBreadcrumb;

}


/**
 * Pagination functions
 */
$jpkcomAcfJobPagination = jpkcom_acfjobs_locate_file( filename: 'pagination.php' );

if ( $jpkcomAcfJobPagination ) {

    require_once $jpkcomAcfJobPagination;

}


/**
 * Redirect functions
 */
$jpkcomAcfJobRedirect = jpkcom_acfjobs_locate_file( filename: 'redirects.php' );

if ( $jpkcomAcfJobRedirect ) {

    require_once $jpkcomAcfJobRedirect;

}


/**
 * Helper functions
 */
$jpkcomAcfJobHelpers = jpkcom_acfjobs_locate_file( filename: 'helpers.php' );

if ( $jpkcomAcfJobHelpers ) {

    require_once $jpkcomAcfJobHelpers;

}


/**
 * Custom Post Types & Taxonomies
 */
add_action( 'init', function(): void {

    $post_types_file = jpkcom_acfjobs_locate_file( filename: 'acf-post_types.php' );

    if ( $post_types_file ) {

        require_once $post_types_file;

    }

    $taxonomies_file = jpkcom_acfjobs_locate_file( filename: 'acf-taxonomies.php' );

    if ( $taxonomies_file ) {

        require_once $taxonomies_file;

    }

}, 5 );


/**
 * ACF Field Groups
 */
add_action( 'plugins_loaded', function(): void {

    $field_groups_file = jpkcom_acfjobs_locate_file( filename: 'acf-field_groups.php' );

    if ( $field_groups_file ) {

        require_once $field_groups_file;

    }

});


/**
 * Template Loader
 */
$jpkcomAcfJobTemplateLoader = jpkcom_acfjobs_locate_file( filename: 'template-loader.php' );

if ( $jpkcomAcfJobTemplateLoader ) {

    require_once $jpkcomAcfJobTemplateLoader;

}


/**
 * Schema.org functions
 */
$jpkcomAcfJobSchema = jpkcom_acfjobs_locate_file( filename: 'schema.php' );

if ( $jpkcomAcfJobSchema ) {

    require_once $jpkcomAcfJobSchema;

}


/**
 * Shortcode functions
 */
$jpkcomAcfJobShortcodes = jpkcom_acfjobs_locate_file( filename: 'shortcodes.php' );

if ( $jpkcomAcfJobShortcodes ) {

    require_once $jpkcomAcfJobShortcodes;

}
