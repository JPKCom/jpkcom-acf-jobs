<?php
/**
 * Template Loader
 */

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

/**
 * Locate template file with override support.
 *
 * Load order (from highest to lowest priority):
 * 1. Child Theme: /wp-content/themes/your-child-theme/jpkcom-acf-jobs/
 * 2. Parent Theme: /wp-content/themes/your-theme/jpkcom-acf-jobs/
 * 3. MU plugin override: /wp-content/mu-plugins/jpkcom-acf-jobs-overrides/templates/
 * 4. Plugin itself: /wp-content/plugins/jpkcom-acf-jobs/templates/
 *
 * @param string $template_name
 * @return string|false
 */
function jpkcom_acf_jobs_locate_template( string $template_name ): string|false {

    $search_paths = [
        trailingslashit( get_stylesheet_directory() ) . 'jpkcom-acf-jobs/' . $template_name,
        trailingslashit( get_template_directory() ) . 'jpkcom-acf-jobs/' . $template_name,
        trailingslashit( WPMU_PLUGIN_DIR ) . 'jpkcom-acf-jobs-overrides/templates/' . $template_name,
    ];

    // Allow developers to add more search paths
    $search_paths = apply_filters( 'jpkcom_acf_jobs_template_paths', $search_paths, $template_name );

    // Search in Child/Parent-Theme or MU-Plugin
    foreach ( $search_paths as $path ) {

        if ( file_exists( filename: $path ) ) {

            return $path;

        }

    }

    // Fallback: Plugin path
    $folder = ( defined( constant_name: 'WP_DEBUG' ) && WP_DEBUG ) ? 'debug-templates/' : 'templates/';
    $plugin_template = trailingslashit( JPKCOM_ACFJOBS_PLUGIN_PATH ) . $folder . $template_name;

    if ( file_exists( filename: $plugin_template ) ) {

        return $plugin_template;

    }

    return false;

}


/**
 * Hook into WordPress locate_template() to include plugin templates when using get_template_part().
 */
add_filter( 'locate_template', function( $template, $template_names ): mixed {

    if ( empty( $template ) && ! empty( $template_names ) ) {

        foreach ( (array) $template_names as $template_name ) {

            if ( str_contains( haystack: $template_name, needle: 'jpkcom-acf-jobs/' ) ) {

                $plugin_template = jpkcom_acf_jobs_locate_template( template_name: $template_name );

                if ( $plugin_template && file_exists( filename: $plugin_template ) ) {

                    return $plugin_template;

                }

            }

        }

    }

    return $template;

}, 10, 2 );


/**
 * Template loader for singular and archive templates
 *
 * @param string $template
 * @return string
 */
function jpkcom_acf_jobs_template_include( string $template ): string {

    // Handle single templates for custom post types
    if ( is_singular( [ 'job', 'job_company', 'job_location' ] ) ) {

        $post_type = get_post_type();

        if ( $post_type ) {

            $single_template = jpkcom_acf_jobs_locate_template( template_name: "single-{$post_type}.php" );

            if ( $single_template ) {

                return $single_template;

            }

        }

    }

    // Handle archive templates
    $archive_post_types = [ 'job', 'job_company', 'job_location' ];

    foreach ( $archive_post_types as $type ) {

        if ( is_post_type_archive( $type ) ) {

            $archive_template = jpkcom_acf_jobs_locate_template( template_name: "archive-{$type}.php" );

            if ( $archive_template ) {

                return $archive_template;

            }

        }

    }

    // Allow external code to modify template path before fallback
    $template = apply_filters( 'jpkcom_acf_jobs_final_template', $template );

    return $template;

}
add_filter( 'template_include', 'jpkcom_acf_jobs_template_include', 20 );


if ( ! function_exists( function: 'jpkcom_acf_jobs_get_template_part' ) ) {
    /**
     * Load partial templates with full override support.
     *
     * @param string $slug Example: 'partials/job/company'
     * @param string $name Optional. Example: 'alternative'
     */
    function jpkcom_acf_jobs_get_template_part( string $slug, string $name = '' ): void {

        $template_name = $slug . ( $name ? '-' . $name : '' ) . '.php';
        $template_path = jpkcom_acf_jobs_locate_template( template_name: $template_name );

        if ( $template_path && file_exists( filename: $template_path ) ) {

            include $template_path;

        } elseif ( defined( constant_name: 'WP_DEBUG' ) && WP_DEBUG ) {

            error_log( message: "[jpkcom_acf_jobs] Template not found: {$template_name}" );

        }

    }

}
