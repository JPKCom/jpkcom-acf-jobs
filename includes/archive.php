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

        // The rule itself lives in includes/jobs-data.php, shared with the
        // [jpkcom_acf_jobs_list] shortcode and the Abilities API. No post_status is
        // set here on purpose: a front-end main query defers to core visibility, so
        // an editor still sees their own drafts and private jobs where core allows
        // it. Only the shortcode and the abilities pin 'publish'.
        $shared = jpkcom_acf_jobs_build_job_query_args();

        $query->set( 'meta_query', $shared['meta_query'] );
        $query->set( 'meta_key', $shared['meta_key'] );
        $query->set( 'orderby', $shared['orderby'] );
    }

});
