<?php
/**
 * Redirect functions
 */

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


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
