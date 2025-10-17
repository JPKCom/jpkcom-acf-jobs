<?php
/**
 * Archive functions
 */

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


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
