<?php
/**
 * Media functions
 */

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( function: 'jpkcom_acf_jobs_media_size' ) ) {

    function jpkcom_acf_jobs_media_size(): void {

        add_image_size( 'jpkcom-acf-job-16x9', 576, 324, true );

        add_image_size( 'jpkcom-acf-job-logo', 512, 512, true );

        add_image_size( 'jpkcom-acf-job-header', 992, 558, true );

    }

}
add_action( 'after_setup_theme', 'jpkcom_acf_jobs_media_size' );


if ( ! function_exists( function: 'jpkcom_acf_jobs_image_sizes_to_selector' ) ) {

    function jpkcom_acf_jobs_image_sizes_to_selector( $sizes ): array {

        return array_merge($sizes, [
            'jpkcom-acf-job-16x9'   => __( 'Job Image (16:9 / Width 576px)', 'jpkcom-acf-jobs' ),
            'jpkcom-acf-job-logo' => __( 'Job Logo (Width 512 / Height 512)', 'jpkcom-acf-jobs' ),
            'jpkcom-acf-job-header' => __( 'Header Image (Width 992 / Height 558)', 'jpkcom-acf-jobs' ),
        ]);

    }

}
add_filter( 'image_size_names_choose', 'jpkcom_acf_jobs_image_sizes_to_selector' );
