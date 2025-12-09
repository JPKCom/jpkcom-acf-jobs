<?php
/*
Plugin Name: JPKCom ACF Jobs
Plugin URI: https://github.com/JPKCom/jpkcom-acf-jobs
Description: Job application plugin for ACF
Version: 1.2.4
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com/
Contributors: JPKCom
Tags: ACF, Fields, CPT, CTT, Taxonomy, Forms
Requires Plugins: advanced-custom-fields-pro, acf-quickedit-fields
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 8.3
Network: true
Stable tag: 1.2.4
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
Text Domain: jpkcom-acf-jobs
Domain Path: /languages
*/

declare(strict_types=1);

if ( ! defined( constant_name: 'WPINC' ) ) {
    die;
}

/**
 * Plugin Constants
 *
 * @since 1.0.0
 */
if ( ! defined( 'JPKCOM_ACFJOBS_VERSION' ) ) {
	define( 'JPKCOM_ACFJOBS_VERSION', '1.2.4' );
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
 *
 * Loads and initializes the GitHub-based plugin updater with SHA256 checksum verification.
 *
 * @since 1.2.0
 * @return void
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
 * Load plugin text domain for translations
 *
 * Loads translation files from the /languages directory.
 *
 * @since 1.0.0
 * @return void
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
 * Locate file with override support
 *
 * Searches for a file in multiple locations with priority:
 * 1. Child theme
 * 2. Parent theme
 * 3. MU plugin overrides
 * 4. Plugin includes directory
 *
 * @since 1.0.0
 *
 * @param string $filename The filename to locate (without path).
 * @return string|null Full path to the file if found, null otherwise.
 */
function jpkcom_acfjobs_locate_file( string $filename ): ?string {

    $paths = [
        get_stylesheet_directory() . '/jpkcom-acf-jobs/' . $filename,
        get_template_directory() . '/jpkcom-acf-jobs/' . $filename,
        WPMU_PLUGIN_DIR . '/jpkcom-acf-jobs-overrides/' . $filename,
        JPKCOM_ACFJOBS_PLUGIN_PATH . 'includes/' . $filename,
    ];

    /**
     * Filter the file search paths
     *
     * @since 1.0.0
     *
     * @param string[] $paths    Array of paths to search.
     * @param string   $filename The filename being located.
     */
    $paths = apply_filters( 'jpkcom_acfjobs_file_paths', $paths, $filename );

    foreach ( $paths as $path ) {

        if ( file_exists( filename: $path ) ) {

            return $path;

        }

    }

    return null;

}


/**
 * Load media functions
 *
 * @since 1.0.0
 */
$jpkcomAcfJobMedia = jpkcom_acfjobs_locate_file( filename: 'media.php' );

if ( $jpkcomAcfJobMedia ) {

    require_once $jpkcomAcfJobMedia;

}


/**
 * Load archive functions
 *
 * @since 1.0.0
 */
$jpkcomAcfJobArchive = jpkcom_acfjobs_locate_file( filename: 'archive.php' );

if ( $jpkcomAcfJobArchive ) {

    require_once $jpkcomAcfJobArchive;

}


/**
 * Load breadcrumb functions
 *
 * @since 1.0.0
 */
$jpkcomAcfJobBreadcrumb = jpkcom_acfjobs_locate_file( filename: 'breadcrumb.php' );

if ( $jpkcomAcfJobBreadcrumb ) {

    require_once $jpkcomAcfJobBreadcrumb;

}


/**
 * Load pagination functions
 *
 * @since 1.0.0
 */
$jpkcomAcfJobPagination = jpkcom_acfjobs_locate_file( filename: 'pagination.php' );

if ( $jpkcomAcfJobPagination ) {

    require_once $jpkcomAcfJobPagination;

}


/**
 * Load redirect functions
 *
 * @since 1.0.0
 */
$jpkcomAcfJobRedirect = jpkcom_acfjobs_locate_file( filename: 'redirects.php' );

if ( $jpkcomAcfJobRedirect ) {

    require_once $jpkcomAcfJobRedirect;

}


/**
 * Load helper functions
 *
 * @since 1.0.0
 */
$jpkcomAcfJobHelpers = jpkcom_acfjobs_locate_file( filename: 'helpers.php' );

if ( $jpkcomAcfJobHelpers ) {

    require_once $jpkcomAcfJobHelpers;

}


/**
 * Register Custom Post Types & Taxonomies
 *
 * Loads and registers job, job_location, job_company post types
 * and job-attribute taxonomy.
 *
 * @since 1.0.0
 * @return void
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
 * Register ACF Field Groups
 *
 * Loads programmatically registered ACF field groups for jobs,
 * locations, and companies.
 *
 * @since 1.0.0
 * @return void
 */
add_action( 'plugins_loaded', function(): void {

    $field_groups_file = jpkcom_acfjobs_locate_file( filename: 'acf-field_groups.php' );

    if ( $field_groups_file ) {

        require_once $field_groups_file;

    }

});


/**
 * Load template loader
 *
 * Handles template hierarchy and override system for job templates.
 *
 * @since 1.0.0
 */
$jpkcomAcfJobTemplateLoader = jpkcom_acfjobs_locate_file( filename: 'template-loader.php' );

if ( $jpkcomAcfJobTemplateLoader ) {

    require_once $jpkcomAcfJobTemplateLoader;

}


/**
 * Load WPML + ACF field keys fix
 *
 * Ensures ACF field keys are copied to WPML translations for proper field formatting.
 *
 * @since 1.2.2
 */
$jpkcomAcfJobWpmlFix = jpkcom_acfjobs_locate_file( filename: 'wpml-acf-field-keys-fix.php' );

if ( $jpkcomAcfJobWpmlFix ) {

    require_once $jpkcomAcfJobWpmlFix;

}


/**
 * Load Schema.org functions
 *
 * Generates JobPosting JSON-LD structured data.
 *
 * @since 1.0.0
 */
$jpkcomAcfJobSchema = jpkcom_acfjobs_locate_file( filename: 'schema.php' );

if ( $jpkcomAcfJobSchema ) {

    require_once $jpkcomAcfJobSchema;

}


/**
 * Load shortcode functions
 *
 * Registers [jpkcom_acf_jobs_list] and [jpkcom_acf_jobs_attributes] shortcodes.
 *
 * @since 1.0.0
 */
$jpkcomAcfJobShortcodes = jpkcom_acfjobs_locate_file( filename: 'shortcodes.php' );

if ( $jpkcomAcfJobShortcodes ) {

    require_once $jpkcomAcfJobShortcodes;

}
