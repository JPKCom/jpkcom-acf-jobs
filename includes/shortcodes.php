<?php
/**
 * Shortcode functions
 */

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


/**
 * Helper: locate template (uses your existing loader)
 * Returns full path or false.
 */
if ( ! function_exists( function: 'jpkcom_acf_jobs_locate_template' ) ) {

    function jpkcom_acf_jobs_locate_template( string $template_name ): string|false {

        $search_paths = [
            trailingslashit( get_stylesheet_directory() ) . 'jpkcom-acf-jobs/' . $template_name,
            trailingslashit( get_template_directory() ) . 'jpkcom-acf-jobs/' . $template_name,
            trailingslashit( WPMU_PLUGIN_DIR ) . 'jpkcom-acf-jobs-overrides/templates/' . $template_name,
            trailingslashit( JPKCOM_ACFJOBS_PLUGIN_PATH ) . 'templates/' . $template_name,
        ];

        foreach ( $search_paths as $path ) {

            if ( file_exists( filename: $path ) ) {

                return $path;

            }

        }

        return false;

    }

}

/**
 * Register shortcodes on init.
 */
add_action( 'init', function(): void {

    // [jpkcom_acf_jobs_list ...]
    add_shortcode( 'jpkcom_acf_jobs_list', function( $atts ): string {

        $defaults = [
            'type'    => '',    // CSV of job_type values
            'company' => '',    // CSV of company post IDs
            'location'=> '',    // CSV of location post IDs
            'limit'   => 0,     // 0 => no limit (we'll set -1 by default)
            'sort'    => 'DSC', // ASC or DSC
            'style'   => '',
            'class'   => '',
            'title'   => '',
        ];

        $atts = shortcode_atts( $defaults, (array) $atts, 'jpkcom_acf_jobs_list' );

        // Sanitize inputs
        $type_csv     = trim( string: (string) $atts['type'] );
        $company_csv  = trim( string: (string) $atts['company'] );
        $location_csv = trim( string: (string) $atts['location'] );
        $limit        = intval( value: $atts['limit'] );
        $sort         = strtoupper( string: $atts['sort'] ) === 'ASC' ? 'ASC' : 'DESC';
        $style        = trim( string: (string) $atts['style'] );
        $class        = trim( string: (string) $atts['class'] );
        $title        = trim( string: (string) $atts['title'] );

        // Build WP_Query args
        $query_args = [
            'post_type'      => 'job',
            'post_status'    => 'publish',
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'meta_key'       => 'job_featured',
            'orderby'        => [
                'meta_value_num' => 'DESC',
                'date'           => $sort,
            ],
        ];

        // Build meta_query for ACF-stored arrays (checkbox/post_object stored serialized)
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

        // job_type: CSV of values (e.g. FULL_TIME,PART_TIME)
        if ( $type_csv !== '' ) {

            $want = array_filter( array: array_map( callback: 'trim', array: explode( separator: ',', string: $type_csv ) ) );

            if ( ! empty( $want ) ) {

                // We add a meta_query clause for each wanted value with LIKE on serialized value.
                $type_clauses = [ 'relation' => 'OR' ];

                foreach ( $want as $val ) {

                    // Serialized arrays will contain "...\"VALUE\"..." so match with quotes.
                    $type_clauses[] = [
                        'key'     => 'job_type',
                        'value'   => '"' . sanitize_text_field( $val ) . '"',
                        'compare' => 'LIKE',
                    ];

                }

                $meta_query[] = $type_clauses;

            }

        }

        // Company filter: CSV of post IDs
        if ( $company_csv !== '' ) {

            $ids = array_filter( array: array_map( callback: 'absint', array: explode( separator: ',', string: $company_csv ) ) );

            if ( ! empty( $ids ) ) {

                $company_clauses = [ 'relation' => 'OR' ];
                foreach ( $ids as $id ) {
                    $company_clauses[] = [
                        'key'     => 'job_company',
                        'value'   => '"' . $id . '"',
                        'compare' => 'LIKE',
                    ];
                }

                $meta_query[] = $company_clauses;

            }

        }

        // location filter: CSV of post IDs
        if ( $location_csv !== '' ) {

            $ids = array_filter( array: array_map( callback: 'absint', array: explode( separator: ',', string: $location_csv ) ) );

            if ( ! empty( $ids ) ) {

                $location_clauses = [ 'relation' => 'OR' ];

                foreach ( $ids as $id ) {

                    $location_clauses[] = [
                        'key'     => 'job_location',
                        'value'   => '"' . $id . '"',
                        'compare' => 'LIKE',
                    ];

                }

                $meta_query[] = $location_clauses;

            }

        }

        // Only add meta_query if there are meaningful subclauses (more than the relation key)
        if ( count( value: $meta_query ) > 1 ) {

            $query_args['meta_query'] = $meta_query;

        }

        // Allow modification via filter
        $query_args = apply_filters( 'jpkcom_acf_jobs_list_query_args', $query_args, $atts );

        $q = new WP_Query( $query_args );

        // Prepare args for template
        $tpl_args = [
            'posts' => $q->posts,
            'query' => $q,
            'atts'  => $atts,
            'style' => $style,
            'class' => $class,
            'title' => $title,
        ];

        // Render template via buffer. Use your loader to find the template.
        $template_name = 'shortcodes/list.php';
        $path = jpkcom_acf_jobs_locate_template( template_name: $template_name );

        ob_start();
        if ( $path ) {

            // Make variables available inside template
            extract( array: $tpl_args, flags: EXTR_SKIP );
            include $path;

        } else {

            // Fallback inline markup if no template present
            ?>
            <div class="jpkcom-acf-jobs--list<?php if ( ! empty( $class ) ) echo ' ' . esc_attr( $class ); ?>" <?php if ( ! empty( $style ) ) echo 'style="' . esc_attr( $style ) . '"'; ?>>

                <?php if ( ! empty( $title ) ) : ?>
                    <h3 class="mb-3"><?php echo esc_html( $title ); ?></h3>
                <?php endif; ?>

                <?php if ( $q->have_posts() ) : ?>
                    <ul class="list-unstyled">
                        <?php foreach ( $q->posts as $post_item ) : setup_postdata( $post_item ); ?>
                            <li id="post-<?php echo esc_attr( $post_item->ID ); ?>" class="border-bottom py-3">

                                <?php
                                // Locations
                                $locations = get_field( 'job_location', $post_item->ID );
                                $location_names = [];

                                if ( $locations ) {
                                    if ( ! is_array( value: $locations ) ) $locations = [ $locations ];
                                    foreach ( $locations as $location ) {
                                        $location_names[] = esc_html(
                                            get_field( 'job_location_place', $location->ID ) ?: get_the_title( $location->ID )
                                        );
                                    }
                                }

                                // Job Types
                                $job_types = get_field( 'job_type', $post_item->ID );
                                $job_type_values = [];
                                if ( $job_types && is_array( value: $job_types ) ) {
                                    foreach ( $job_types as $type ) {
                                        if ( is_array( value: $type ) && isset( $type['label'] ) ) {
                                            $job_type_values[] = esc_html( $type['label'] );
                                        } elseif ( is_string( value: $type ) ) {
                                            $job_type_values[] = esc_html( $type );
                                        }
                                    }
                                }
                                ?>

                                <div class="row align-items-center">
                                    <div class="col-md-4 col-12 mb-1 mb-md-0">
                                        <h5 class="fs-6 mb-0">
                                            <a href="<?php echo esc_url( get_permalink( $post_item ) ); ?>" class="text-decoration-none text-reset">
                                                <?php echo esc_html( get_the_title( $post_item ) ); ?>
                                            </a>
                                        </h5>
                                    </div>

                                    <div class="col-md-4 col-12 text-md-center text-muted small">
                                        <?php if ( ! empty( $location_names ) ) : ?>
                                            <?php echo esc_html( implode( separator: ', ', array: $location_names ) ); ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-4 col-12 text-md-end small text-uppercase fw-semibold text-secondary">
                                        <?php if ( ! empty( $job_type_values ) ) : ?>
                                            <?php echo esc_html( implode( separator: ', ', array: $job_type_values ) ); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php
                                // Companies
                                $companies = get_field( 'job_company', $post_item->ID );
                                $company_names = [];
                                if ( $companies ) {
                                    if ( ! is_array( value: $companies ) ) $companies = [ $companies ];
                                    foreach ( $companies as $company ) {
                                        $company_names[] = esc_html( get_the_title( $company->ID ) );
                                    }
                                }

                                // Date
                                $date_iso   = get_the_date( 'Y-m-d', $post_item );
                                $date_human = jpkcom_human_readable_relative_date( timestamp: get_the_date( 'U', $post_item ) );
                                ?>

                                <div class="row mt-1 small text-muted">
                                    <div class="col-md-6 col-12">
                                        <?php if ( ! empty( $company_names ) ) : ?>
                                            <?php echo esc_html( implode( separator: ', ', array: $company_names ) ); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6 col-12 text-md-end">
                                        <time datetime="<?php echo esc_attr( $date_iso ); ?>" class="date-posted">
                                            <?php echo esc_html( $date_human ); ?>
                                        </time>
                                    </div>
                                </div>

                            </li>
                        <?php endforeach; wp_reset_postdata(); ?>
                    </ul>
                <?php else : ?>

                    <p class="text-muted mb-0"><?php esc_html_e( 'No jobs found.', 'jpkcom-acf-jobs' ); ?></p>

                <?php endif; ?>

            </div>
            <?php
        }

        return (string) ob_get_clean();

    } );

    // [jpkcom_acf_jobs_attributes ...]
    add_shortcode( 'jpkcom_acf_jobs_attributes', function( $atts ): string {

        $defaults = [
            'id'    => '', // CSV of term IDs (optional)
            'style' => '',
            'class' => '',
            'title' => '',
        ];

        $atts = shortcode_atts( $defaults, (array) $atts, 'jpkcom_acf_jobs_attributes' );

        $ids_csv = trim( string: (string) $atts['id'] );
        $style   = trim( string: (string) $atts['style'] );
        $class   = trim( string: (string) $atts['class'] );
        $title   = trim( string: (string) $atts['title'] );

        $args = [
            'taxonomy'   => 'job-attribute',
            'hide_empty' => false,
        ];

        if ( $ids_csv !== '' ) {

            $ids = array_filter( array: array_map( callback: 'absint', array: explode( separator: ',', string: $ids_csv ) ) );

            if ( ! empty( $ids ) ) {

                $args['include'] = $ids;

            }

        }

        $terms = get_terms( $args );

        // Template name
        $template_name = 'shortcodes/attributes.php';
        $path = jpkcom_acf_jobs_locate_template( template_name: $template_name );

        ob_start();

        if ( $path ) {

            $tpl_args = [
                'terms' => $terms,
                'atts'  => $atts,
                'style' => $style,
                'class' => $class,
                'title' => $title,
            ];

            extract( array: $tpl_args, flags: EXTR_SKIP );
            include $path;

        } else {

            // Fallback output:
            if ( $title ) {

                echo '<h2>' . esc_html( $title ) . '</h2>';

            }

            if ( empty( $terms ) ) {

                echo '<p class="text-muted">' . esc_html__( 'No attributes found.', 'jpkcom-acf-jobs' ) . '</p>';

            } else {

                echo '<div class="jpkcom-acf-jobs--attribute';

                if ( ! empty( $class ) ) {
                    echo ' ' . esc_attr( $class );
                }

                echo '"';

                if ( ! empty( $style ) ) {

                    echo ' style="' . esc_attr( $style ) . '"';

                }

                echo '>';

                if ( ! empty( $title ) ) {

                    echo '<h3 class="mb-3">' . esc_html( $title ) . '</h3>';

                }

                foreach ( $terms as $term ) {

                    $summary = esc_html( $term->name );
                    $desc = wp_kses_post( term_description( $term->term_id, $term->taxonomy ) );
                    echo '<details name="jpkcom-acf-jobs-attribute" class="border rounded p-3 mb-3">';
                    echo '<summary class="px-3 fs-5"><h4 class="d-inline fs-5">' . $summary . '</h4></summary>';
                    echo '<div class="p-3">' . ( $desc ?: '<p class="text-muted">' . esc_html__( 'No description.', 'jpkcom-acf-jobs' ) . '</p>' ) . '</div>';
                    echo '</details>';

                }

                echo '</div>';

            }

        }

        return (string) ob_get_clean();

    } );

} );
