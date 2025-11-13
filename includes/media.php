<?php
/**
 * Media and image size registration functions
 *
 * Registers custom image sizes for job posts, companies, and locations:
 * - jpkcom-acf-job-16x9: 576x324px (16:9 aspect ratio) for job images
 * - jpkcom-acf-job-logo: 512x512px (square) for company logos
 * - jpkcom-acf-job-header: 992x558px (16:9 aspect ratio) for header images
 *
 * @package   JPKCom_ACF_Jobs
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( function: 'jpkcom_acf_jobs_media_size' ) ) {

    /**
     * Register custom image sizes for job posts
     *
     * Registers three image sizes:
     * - jpkcom-acf-job-16x9: 576x324px (16:9, hard crop)
     * - jpkcom-acf-job-logo: 512x512px (square, hard crop)
     * - jpkcom-acf-job-header: 992x558px (16:9, hard crop)
     *
     * @since 1.0.0
     * @return void
     */
    function jpkcom_acf_jobs_media_size(): void {

        add_image_size( 'jpkcom-acf-job-16x9', 576, 324, true );

        add_image_size( 'jpkcom-acf-job-logo', 512, 512, true );

        add_image_size( 'jpkcom-acf-job-header', 992, 558, true );

    }

}
add_action( 'after_setup_theme', 'jpkcom_acf_jobs_media_size' );


if ( ! function_exists( function: 'jpkcom_acf_jobs_image_sizes_to_selector' ) ) {

    /**
     * Add custom image sizes to media library size selector
     *
     * Makes custom image sizes available in the WordPress media library
     * dropdown when inserting images into posts.
     *
     * @since 1.0.0
     *
     * @param string[] $sizes Existing image size options.
     * @return string[] Modified array with custom sizes added.
     */
    function jpkcom_acf_jobs_image_sizes_to_selector( array $sizes ): array {

        return array_merge($sizes, [
            'jpkcom-acf-job-16x9'   => __( 'Job Image (16:9 / Width 576px)', 'jpkcom-acf-jobs' ),
            'jpkcom-acf-job-logo' => __( 'Job Logo (Width 512 / Height 512)', 'jpkcom-acf-jobs' ),
            'jpkcom-acf-job-header' => __( 'Header Image (Width 992 / Height 558)', 'jpkcom-acf-jobs' ),
        ]);

    }

}
add_filter( 'image_size_names_choose', 'jpkcom_acf_jobs_image_sizes_to_selector' );
