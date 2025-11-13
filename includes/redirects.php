<?php
/**
 * Redirect and access control functions
 *
 * Handles three types of redirects:
 * 1. Job URL redirects (external job application URLs)
 * 2. Location/Company access control (non-editors can't view directly)
 * 3. Expired job redirects (redirect to archive if job expired)
 *
 * @package   JPKCom_ACF_Jobs
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


/**
 * Redirect job posts to external URL if configured
 *
 * Checks for job_url ACF field and redirects to external application URL.
 * Skips redirect for administrators and when WP_DEBUG is enabled.
 * Uses 307 (Temporary Redirect) to preserve the request method.
 *
 * @since 1.0.0
 * @return void
 */
add_action( 'template_redirect', function(): void {

    if ( is_admin() ) {

        return;

    }

    if ( is_singular( 'job' ) ) {

        global $post;

        if ( ! $post ) {

            return;

        }

        if ( current_user_can( 'administrator' ) ) {

            return;

        }

        $job_url = get_field( 'job_url', $post->ID );

        if ( is_array( value: $job_url ) && ! empty( $job_url['url'] ) ) {

            $redirect_url = $job_url['url'];

            if ( strpos( haystack: $redirect_url, needle: home_url() ) === false && strpos( haystack: $redirect_url, needle: '://' ) === false ) {

                $redirect_url = home_url( $redirect_url );

            }

            if ( defined( constant_name: 'WP_DEBUG' ) && WP_DEBUG ) {

                return;

            }

            wp_redirect( $redirect_url, 307 );

            exit;

        }

    }

});


/**
 * Restrict direct access to job_location and job_company posts
 *
 * Redirects non-editors from directly viewing location/company posts
 * to the main job archive. Uses 302 (Found) status code.
 *
 * @since 1.0.0
 * @return void
 */
add_action( 'template_redirect', function(): void {

    if ( is_singular( [ 'job_location', 'job_company' ] ) ) {

        global $post;

        if ( ! $post ) {

            return;

        }

        if ( ! current_user_can( 'edit_post', $post->ID ) ) {

            wp_safe_redirect( get_post_type_archive_link( 'job' ), 302 );

            exit;

        }

    }

});


/**
 * Redirect expired jobs to archive
 *
 * Checks job_expiry_date ACF field and redirects to job archive if expired.
 * Editors can still view expired jobs. Uses 307 (Temporary Redirect) status.
 *
 * @since 1.0.0
 * @return void
 */
add_action( 'template_redirect', function(): void {

    if ( is_singular( 'job' ) ) {

        global $post;

        if ( ! $post ) {

            return;

        }

        if ( current_user_can( 'edit_post', $post->ID ) ) {

            return;

        }

        $expiry_date = get_field( 'job_expiry_date', $post->ID );

        if ( empty( $expiry_date ) ) {

            return;

        }

        $today = date( format: 'Y-m-d' );
        $is_expired = ( $expiry_date < $today );

        if ( $is_expired ) {

            wp_safe_redirect( get_post_type_archive_link( 'job' ), 307 );

            exit;

        }

    }

});
