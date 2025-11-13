<?php
/**
 * Archive query modification functions
 *
 * Modifies the main query for job archives to:
 * - Filter out expired jobs (based on job_expiry_date)
 * - Sort by featured status (job_featured) then by date
 * - Only show published jobs
 *
 * @package   JPKCom_ACF_Jobs
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


/**
 * Modify job archive query to exclude expired jobs and sort by featured status
 *
 * Applied to the main query on job archive pages only (not admin).
 * Filters jobs to show only:
 * - Jobs without expiry date
 * - Jobs with empty expiry date
 * - Jobs with expiry date >= today
 *
 * Sorting order:
 * 1. Featured jobs first (job_featured field, DESC)
 * 2. Then by publication date (DESC)
 *
 * @since 1.0.0
 *
 * @param WP_Query $query The WordPress query object.
 * @return void Modifies query by reference.
 */
add_action( 'pre_get_posts', function( $query ): void {

    if ( ! is_admin() && $query->is_main_query() && is_post_type_archive( 'job' ) ) {

        $meta_query = [
            'relation' => 'AND',
            [
                'key'     => 'job_featured',
                'compare' => 'EXISTS',
            ],
            [
                'relation' => 'OR',
                [
                    'key'     => 'job_expiry_date',
                    'value'   => date( format: 'Y-m-d' ),
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
                [
                    'key'     => 'job_expiry_date',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => 'job_expiry_date',
                    'value'   => '',
                    'compare' => '=',
                ],
            ],
        ];

        $query->set( 'meta_query', $meta_query );

        $query->set( 'meta_key', 'job_featured' );
        $query->set( 'orderby', [
            'meta_value_num' => 'DESC',
            'date'           => 'DESC',
        ] );
    }

});
