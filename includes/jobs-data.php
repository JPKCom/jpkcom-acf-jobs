<?php
/**
 * Job data access
 *
 * One place for the two things the plugin previously did in several: deciding
 * which jobs are visible, and reading a job as data rather than as markup.
 *
 * @package   JPKCom_ACF_Jobs
 * @since     1.4.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( function: 'jpkcom_acf_jobs_build_job_query_args' ) ) {

    /**
     * Build WP_Query arguments for the job visibility rule.
     *
     * This is the single source for "which jobs does this site show". It is called
     * from three places that previously each carried their own copy: the
     * [jpkcom_acf_jobs_list] shortcode, the job archive's pre_get_posts handler, and
     * the Abilities API callbacks.
     *
     * Keys whose value is null are omitted from the result, because the archive sets
     * its query through WP_Query::set() and must not inherit a page size or a status.
     *
     * @since 1.4.0
     *
     * @param array $args {
     *     Optional. Query parameters.
     *
     *     @type int|null    $posts_per_page             Page size. Omitted when null. Never defaulted to -1.
     *     @type int|null    $paged                      Page number. Omitted when null.
     *     @type string      $order                      'ASC' or 'DESC' for the date component. Default 'DESC'.
     *     @type string|null $post_status                Post status. Omitted when null.
     *     @type string[]    $job_type                   job_type values (not labels).
     *     @type int[]       $company                    job_company post IDs.
     *     @type int[]       $location                   job_location post IDs.
     *     @type int[]       $attribute                  job-attribute term IDs.
     *     @type string      $search                     Free-text search.
     *     @type bool        $exclude_password_protected Whether to drop password-protected jobs.
     * }
     * @return array WP_Query arguments.
     */
    function jpkcom_acf_jobs_build_job_query_args( array $args = [] ): array {

        $order = ( isset( $args['order'] ) && strtoupper( string: (string) $args['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';

        $query_args = [
            'post_type' => 'job',
            'meta_key'  => 'job_featured',
            'orderby'   => [
                'meta_value_num' => 'DESC',
                'date'           => $order,
            ],
        ];

        if ( isset( $args['post_status'] ) ) {

            $query_args['post_status'] = $args['post_status'];

        }

        if ( isset( $args['posts_per_page'] ) ) {

            $query_args['posts_per_page'] = (int) $args['posts_per_page'];

        }

        if ( isset( $args['paged'] ) ) {

            $query_args['paged'] = (int) $args['paged'];

        }

        if ( ! empty( $args['search'] ) && is_string( value: $args['search'] ) ) {

            $query_args['s'] = sanitize_text_field( $args['search'] );

        }

        if ( ! empty( $args['exclude_password_protected'] ) ) {

            // get_the_title() prepends the protected-title format while ACF has no
            // notion of a post password and returns the salary and address in full,
            // so such a record would contradict itself.
            $query_args['has_password'] = false;

        }

        // DO NOT SIMPLIFY THIS BLOCK. Two plausible cleanups each shrink the result
        // set with no error and no log line:
        //
        // 1. The NOT EXISTS clause looks redundant next to the empty-string clause.
        //    It is not: WP_Meta_Query rewrites every INNER JOIN to LEFT JOIN as soon
        //    as one NOT EXISTS clause is present. Remove it and every job that has no
        //    job_expiry_date row at all disappears from the list.
        // 2. The comparison looks like it could move to PHP. It cannot: ACF stores
        //    dates as Ymd, and the SQL side compares the raw column through
        //    type => 'DATE'. Measured on WP 7.0.2, CAST('20251130' AS DATE) yields
        //    '2025-11-30', so this comparison is correct as written; a PHP string
        //    comparison against the raw value would not be.
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
                    // Site timezone, not UTC: WordPress sets the PHP timezone to UTC
                    // (wp-settings.php), so date() would keep an expired job listing
                    // visible for the length of the UTC offset after local midnight.
                    'value'   => current_time( 'Y-m-d' ),
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

        // job_type: checkbox values stored as a serialized array, matched with quotes.
        $types = array_values( array_filter( array_map( 'strval', (array) ( $args['job_type'] ?? [] ) ) ) );

        if ( ! empty( $types ) ) {

            $type_clauses = [ 'relation' => 'OR' ];

            foreach ( $types as $val ) {

                $type_clauses[] = [
                    'key'     => 'job_type',
                    'value'   => '"' . sanitize_text_field( $val ) . '"',
                    'compare' => 'LIKE',
                ];

            }

            $meta_query[] = $type_clauses;

        }

        // job_company / job_location: post objects stored as a serialized array of IDs.
        foreach ( [ 'company' => 'job_company', 'location' => 'job_location' ] as $key => $meta_key ) {

            $ids = array_values( array_filter( array_map( 'absint', (array) ( $args[ $key ] ?? [] ) ) ) );

            if ( empty( $ids ) ) {

                continue;

            }

            $clauses = [ 'relation' => 'OR' ];

            foreach ( $ids as $id ) {

                $clauses[] = [
                    'key'     => $meta_key,
                    'value'   => '"' . $id . '"',
                    'compare' => 'LIKE',
                ];

            }

            $meta_query[] = $clauses;

        }

        $query_args['meta_query'] = $meta_query;

        // job_attribute is the one taxonomy-backed field, and load_terms => 1 makes
        // ACF discard the stored meta and read wp_get_object_terms() instead. The
        // meta is therefore write-only from a reader's point of view and must not be
        // filtered with the LIKE pattern the three fields above use.
        $terms = array_values( array_filter( array_map( 'absint', (array) ( $args['attribute'] ?? [] ) ) ) );

        if ( ! empty( $terms ) ) {

            $query_args['tax_query'] = [
                [
                    'taxonomy' => 'job-attribute',
                    'field'    => 'term_id',
                    'terms'    => $terms,
                    'operator' => 'IN',
                ],
            ];

        }

        return $query_args;

    }

}
