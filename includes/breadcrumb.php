<?php
/**
 * Breadcrumb functions
 */

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( function: 'jpkcom_acf_jobs_breadcrumb' ) ) {

/**
 * Custom Breadcrumb Output
 */
    function jpkcom_acf_jobs_breadcrumb(): void {

        global $post;

        echo '<nav aria-label="' . esc_html__( 'Breadcrumb', 'jpkcom-acf-jobs' ) . '" class="overflow-x-auto text-nowrap mb-4 mt-2 py-2 px-3 bg-body-tertiary rounded">';
        echo '<ol class="breadcrumb flex-nowrap mb-0">';

        echo '<li class="breadcrumb-item"><a href="' . esc_url( home_url( '/' ) ) . '"><i class="fa-solid fa-house"></i><span class="visually-hidden">' . esc_html__( 'Home', 'jpkcom-acf-jobs' ) . '</span></a></li>';

        if ( is_singular( 'job' ) ) {

            $archive_link = get_post_type_archive_link( 'job' );

            if ( $archive_link ) {

                echo '<li class="breadcrumb-item"><a href="' . esc_url( $archive_link ) . '">' . esc_html__( 'Jobs', 'jpkcom-acf-jobs' ) . '</a></li>';

            }

            echo '<li class="breadcrumb-item active" aria-current="page">' . esc_html( get_the_title() ) . '</li>';

        } elseif ( is_post_type_archive( 'job' ) ) {

            echo '<li class="breadcrumb-item active" aria-current="page">' . esc_html__( 'Jobs', 'jpkcom-acf-jobs' ) . '</li>';

        } else {

            echo '<li class="breadcrumb-item active" aria-current="page">' . esc_html( get_the_title() ) . '</li>';

        }

        echo '</ol>';
        echo '</nav>';

    }

}
