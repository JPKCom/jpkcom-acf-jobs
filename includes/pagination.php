<?php
/**
 * Pagination navigation functions
 *
 * Generates Bootstrap 5 styled pagination with accessible markup
 * for job archives and other paginated content.
 *
 * @package   JPKCom_ACF_Jobs
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( function: 'jpkcom_acf_jobs_pagination' ) ) {

    /**
     * Output Bootstrap 5 pagination navigation
     *
     * Generates numbered pagination with first/last and prev/next controls.
     * Includes proper ARIA labels and accessible markup.
     *
     * Features:
     * - First/Last page links (« »)
     * - Previous/Next page links (‹ ›)
     * - Numbered page links with range control
     * - Active page indicator
     * - Disabled state for unavailable actions
     *
     * @since 1.0.0
     *
     * @global int       $paged    Current page number (set by WordPress).
     * @global WP_Query  $wp_query WordPress query object.
     *
     * @param string|int $pages Optional. Total number of pages. Default empty (auto-detect from query).
     * @param int        $range Optional. Number of page links to show on either side of current page. Default 2.
     * @return void Outputs HTML directly.
     */
    function jpkcom_acf_jobs_pagination( string|int $pages = '', int $range = 2 ): void {

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
