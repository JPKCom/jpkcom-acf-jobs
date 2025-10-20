<?php
/**
 * Pagination functions
 */

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( function: 'jpkcom_acf_jobs_pagination' ) ) {

    function jpkcom_acf_jobs_pagination( $pages = '', $range = 2 ): void {

        $showitems = ( $range * 2 ) + 1;
        global $paged;

        if ( empty( $paged ) ) $paged = 1;

        if ( $pages == '' ) {

            global $wp_query;
            $pages = $wp_query->max_num_pages;
            if ( ! $pages ) $pages = 1;

        }

        if ( 1 != $pages ) {

            echo '<nav aria-label="' . esc_html__( 'Page navigation', 'jpkcom-acf-jobs' ) . '">';
            echo '<ul class="pagination pagination-lg justify-content-center my-4">';

            if ( $paged > 1 ) {

                echo '<li class="page-item">
                        <a class="page-link" href="' . get_pagenum_link( 1 ) . '" aria-label="' . esc_html__( 'First Page', 'jpkcom-acf-jobs' ) . '">
                            <span aria-hidden="true">&laquo;</span>
                        </a>
                    </li>';

            } else {

                echo '<li class="page-item disabled">
                        <span class="page-link" aria-hidden="true">&laquo;</span>
                    </li>';

            }

            if ( $paged > 1 ) {

                echo '<li class="page-item">
                        <a class="page-link" href="' . get_pagenum_link( $paged - 1 ) . '" aria-label="' . esc_html__( 'Previous Page', 'jpkcom-acf-jobs' ) . '">
                            <span aria-hidden="true">&lsaquo;</span>
                        </a>
                    </li>';

            } else {

                echo '<li class="page-item disabled">
                        <span class="page-link" aria-hidden="true">&lsaquo;</span>
                    </li>';

            }

            for ( $i = 1; $i <= $pages; $i++ ) {

                if ( 1 != $pages && ( ! ( $i >= $paged + $range + 1 || $i <= $paged - $range - 1 ) || $pages <= $showitems ) ) {

                    echo ( $paged == $i )
                        ? '<li class="page-item active"><span class="page-link"><span class="visually-hidden">' . __( 'Current Page', 'jpkcom-acf-jobs' ) . ' </span>' . $i . '</span></li>'
                        : '<li class="page-item"><a class="page-link" href="' . get_pagenum_link( $i ) . '"><span class="visually-hidden">' . __( 'Page', 'jpkcom-acf-jobs' ) . ' </span>' . $i . '</a></li>';

                }

            }

            if ( $paged < $pages ) {

                echo '<li class="page-item">
                        <a class="page-link" href="' . get_pagenum_link( $paged + 1 ) . '" aria-label="' . esc_html__( 'Next Page', 'jpkcom-acf-jobs' ) . '">
                            <span aria-hidden="true">&rsaquo;</span>
                        </a>
                    </li>';

            } else {

                echo '<li class="page-item disabled">
                        <span class="page-link" aria-hidden="true">&rsaquo;</span>
                    </li>';

            }

            if ( $paged < $pages ) {

                echo '<li class="page-item">
                        <a class="page-link" href="' . get_pagenum_link( $pages ) . '" aria-label="' . esc_html__( 'Last Page', 'jpkcom-acf-jobs' ) . '">
                            <span aria-hidden="true">&raquo;</span>
                        </a>
                    </li>';

            } else {

                echo '<li class="page-item disabled">
                        <span class="page-link" aria-hidden="true">&raquo;</span>
                    </li>';

            }

            echo '</ul>';
            echo '</nav>';

        }

    }

}
