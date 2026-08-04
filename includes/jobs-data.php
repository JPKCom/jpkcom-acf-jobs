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

if ( ! function_exists( function: 'jpkcom_acf_jobs_normalise_choices' ) ) {

    /**
     * Normalise an ACF choice-list value to {value,label} pairs.
     *
     * A checkbox with return_format 'array' yields [ ['value'=>…, 'label'=>…], … ].
     * The same field yields bare strings when ACF falls back to the raw meta, which
     * happens whenever the field group is not registered — a theme replacing
     * acf-field_groups.php through the override system is enough. Both shapes are
     * the contract, not a defensive afterthought.
     *
     * @since 1.4.0
     *
     * @param mixed $value Raw ACF value.
     * @return array List of [ 'value' => string, 'label' => string ].
     */
    function jpkcom_acf_jobs_normalise_choices( mixed $value ): array {

        if ( is_scalar( value: $value ) ) {

            $value = [ $value ];

        }

        if ( ! is_array( value: $value ) ) {

            return [];

        }

        $out = [];

        foreach ( $value as $item ) {

            if ( is_array( value: $item ) && isset( $item['value'] ) && is_scalar( value: $item['value'] ) ) {

                $label = ( isset( $item['label'] ) && is_scalar( value: $item['label'] ) ) ? (string) $item['label'] : (string) $item['value'];

                $out[] = [
                    'value' => (string) $item['value'],
                    'label' => $label,
                ];

                continue;

            }

            if ( is_scalar( value: $item ) && (string) $item !== '' ) {

                $out[] = [
                    'value' => (string) $item,
                    'label' => (string) $item,
                ];

            }

        }

        return $out;

    }

}

if ( ! function_exists( function: 'jpkcom_acf_jobs_normalise_choice' ) ) {

    /**
     * Normalise a single-choice ACF value (button_group, select) to one pair.
     *
     * @since 1.4.0
     *
     * @param mixed $value Raw ACF value.
     * @return array|null [ 'value' => string, 'label' => string ], or null when empty.
     */
    function jpkcom_acf_jobs_normalise_choice( mixed $value ): ?array {

        if ( is_array( value: $value ) && isset( $value['value'] ) ) {

            $value = [ $value ];

        }

        $pairs = jpkcom_acf_jobs_normalise_choices( $value );

        return $pairs[0] ?? null;

    }

}

if ( ! function_exists( function: 'jpkcom_acf_jobs_normalise_date' ) ) {

    /**
     * Normalise a stored date to Y-m-d.
     *
     * ACF stores date fields as Ymd and formats them on read, so both spellings
     * reach this function depending on whether the field's key reference row exists.
     * Anything else yields null rather than a guess.
     *
     * Deliberately not written as date( 'Y-m-d', strtotime( $raw ) ), the form used in
     * includes/schema.php:71: under strict_types a false from strtotime() makes date()
     * throw a TypeError, and on the WP 6.9 floor a Throwable out of an ability callback
     * is an uncaught fatal.
     *
     * is_string() is not enough to make createFromFormat() safe: a PHP string can carry
     * an embedded NUL byte anywhere in it, and as of PHP 8.3 that makes
     * DateTimeImmutable::createFromFormat() throw ValueError instead of returning false —
     * confirmed regardless of the NUL's position or which of the two formats below is
     * tried. MySQL longtext happily stores one; an importer, WP-CLI, a WPML copy or direct
     * SQL against job_expiry_date is enough to plant it. The try/catch below is what
     * closes that door; everything else that can go wrong here (invalid UTF-8, absurdly
     * long input, an overflowing month/day/date) was measured to fail closed already —
     * createFromFormat() returns false rather than throwing, and format() never throws for
     * the two hardcoded, always-valid format strings used here.
     *
     * @since 1.4.0
     *
     * @param mixed $raw Stored value.
     * @return string|null Date as Y-m-d, or null.
     */
    function jpkcom_acf_jobs_normalise_date( mixed $raw ): ?string {

        if ( ! is_string( value: $raw ) || $raw === '' ) {

            return null;

        }

        foreach ( [ 'Ymd', 'Y-m-d' ] as $format ) {

            try {

                $date = DateTimeImmutable::createFromFormat( $format, $raw );

            } catch ( \Throwable $e ) {

                continue;

            }

            if ( $date instanceof DateTimeImmutable && $date->format( $format ) === $raw ) {

                return $date->format( 'Y-m-d' );

            }

        }

        return null;

    }

}

if ( ! function_exists( function: 'jpkcom_acf_jobs_plain_text' ) ) {

    /**
     * Reduce stored markup to plain text without executing anything.
     *
     * job_short_description is a textarea with new_lines => 'br', so its stored value
     * is HTML. This turns it back into text. It expands nothing: shortcode expansion
     * is exactly what get_field()'s formatted mode does and what every caller of this
     * function exists to avoid.
     *
     * @since 1.4.0
     *
     * @param mixed $value Stored value.
     * @return string Plain text, empty when the value was not a string.
     */
    function jpkcom_acf_jobs_plain_text( mixed $value ): string {

        if ( ! is_string( value: $value ) ) {

            return '';

        }

        $value = preg_replace( '#<br\s*/?>#i', "\n", $value );

        return wp_strip_all_tags( (string) $value );

    }

}

if ( ! function_exists( function: 'jpkcom_acf_jobs_normalise_related' ) ) {

    /**
     * Project ACF post-object values to {id,title}, dropping anything unpublished.
     *
     * Never returns a WP_Post. WP_Post implements no JsonSerializable and exposes
     * post_password, post_content and post_status as public properties, so encoding
     * one would publish a related company's plaintext password. ACF resolves
     * post_object fields through acf_get_posts() with post_status 'any', so drafts
     * and private records genuinely arrive here.
     *
     * Bare integers are accepted and re-resolved: when a translation's ACF key
     * reference row is missing — the case includes/wpml-acf-field-keys-fix.php exists
     * to repair — get_field() returns raw IDs, and every renderer in this repo
     * dereferences ->ID on them.
     *
     * A bare id is rejected before the lookup when absint() reduces it to less than 1.
     * absint() maps false, '', null, 'abc' and 0 all to 0, and real get_post( 0 ) treats
     * 0 as empty and falls back to the current global post — the same footgun
     * jpkcom_acf_jobs_get_job_data()'s own `$post_id < 1` guard exists for. ACF genuinely
     * returns false, not an array, for an unassigned post_object field with
     * allow_null => 1, which both job_company and job_location are, so an unresolved id
     * reaching get_post() unguarded would project the current job as its own employer or
     * location. A password is checked for the same reason the reader's own gate checks
     * one: nothing about a post_object relation implies the related post is public.
     *
     * @since 1.4.0
     *
     * @param mixed $value Raw ACF value.
     * @return array List of [ 'id' => int, 'title' => string ].
     */
    function jpkcom_acf_jobs_normalise_related( mixed $value ): array {

        if ( $value instanceof WP_Post || is_scalar( value: $value ) ) {

            $value = [ $value ];

        }

        if ( ! is_array( value: $value ) ) {

            return [];

        }

        $out = [];

        foreach ( $value as $item ) {

            if ( $item instanceof WP_Post ) {

                $post = $item;

            } else {

                $id = absint( $item );

                if ( $id < 1 ) {

                    continue;

                }

                $post = get_post( $id );

            }

            if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' || $post->post_password !== '' ) {

                continue;

            }

            $out[] = [
                'id'    => (int) $post->ID,
                'title' => (string) get_the_title( $post ),
            ];

        }

        return $out;

    }

}

if ( ! function_exists( function: 'jpkcom_acf_jobs_attachment_url' ) ) {

    /**
     * Resolve an ACF image value to a single URL.
     *
     * ACF hands out an image field either as an attachment ID or as an array of
     * roughly thirty keys, depending on the field's return format and on whether
     * the field group is registered at all. Both shapes reduce to one URL here.
     * The full array is deliberately never emitted: it carries the uploader's
     * name, the file path on disk and every registered intermediate size, none of
     * which a job listing needs.
     *
     * @since 1.4.0
     *
     * @param mixed $value Raw ACF image value.
     * @return string|null Image URL, or null when the value resolves to no attachment.
     */
    function jpkcom_acf_jobs_attachment_url( mixed $value ): ?string {

        if ( is_array( value: $value ) && isset( $value['ID'] ) ) {

            $value = $value['ID'];

        }

        if ( ! is_scalar( value: $value ) ) {

            return null;

        }

        $attachment_id = absint( $value );

        if ( $attachment_id < 1 ) {

            return null;

        }

        $src = wp_get_attachment_image_src( $attachment_id, 'jpkcom-acf-job-logo' );

        if ( ! is_array( value: $src ) || ! isset( $src[0] ) || ! is_string( value: $src[0] ) || $src[0] === '' ) {

            return null;

        }

        return $src[0];

    }

}

if ( ! function_exists( function: 'jpkcom_acf_jobs_get_job_data' ) ) {

    /**
     * Read one job as a JSON-serialisable array.
     *
     * The gate is the first act, because no field reader in this plugin has one:
     * schema.php checks the post type, nothing anywhere checks the status, and the
     * existing readers are safe only because the shortcode hands them posts a
     * post_type/post_status query already filtered. A function taking a bare int
     * inherits none of that, and current_user_can( 'read' ) is every logged-in user.
     *
     * Returns [] for "does not exist" and for "not readable" alike, so the ability
     * on top of it cannot be used to probe which IDs exist.
     *
     * @since 1.4.0
     *
     * @param int  $post_id Job post ID.
     * @param bool $full    Whether to include the detail fields (§5.3 of the spec).
     * @return array The record, or [] when the job is not readable.
     */
    function jpkcom_acf_jobs_get_job_data( int $post_id, bool $full = false ): array {

        if ( $post_id < 1 || ! function_exists( function: 'get_field' ) ) {

            return [];

        }

        $post = get_post( $post_id );

        if ( ! $post instanceof WP_Post ) {

            return [];

        }

        if ( $post->post_type !== 'job' || $post->post_status !== 'publish' || $post->post_password !== '' ) {

            return [];

        }

        // Every read below passes $post_id explicitly. There is no global $post
        // inside an ability callback, and the single-job partials — which all read
        // the global — are therefore not the reference for this function.
        //
        // The third argument of get_field() is explicit everywhere, and false for
        // every long-form field. That is the load-bearing rule of this file:
        // get_field()'s default formatted mode pipes a wysiwyg value through
        // acf_the_content, which carries do_shortcode at priority 11 and
        // WP_Embed::autoembed at 8. With no post context — which is exactly an
        // ability callback — autoembed fetches the remote URL and wp_insert_post()s
        // an oembed_cache row, so a declared read-only ability would write to the
        // database and make outbound HTTP requests. A URL alone on one line of a
        // job description is enough to trigger it.
        $today = current_time( 'Y-m-d' );

        // Read formatted, so this mirrors includes/redirects.php, which decides
        // its 307 from exactly this shape. ACF returns '' or false for an unset
        // link field, never null, so ?? does not catch it.
        $job_url = get_field( 'job_url', $post_id, true );

        $external_url = '';

        if ( is_array( value: $job_url ) && isset( $job_url['url'] ) && is_string( value: $job_url['url'] ) ) {

            $external_url = trim( $job_url['url'] );

        }

        $expiry_date = jpkcom_acf_jobs_normalise_date( get_field( 'job_expiry_date', $post_id, true ) );
        $is_expired  = $expiry_date !== null && $expiry_date < $today;

        $companies = [];

        foreach ( jpkcom_acf_jobs_normalise_related( get_field( 'job_company', $post_id, true ) ) as $related ) {

            $companies[] = [
                'id'   => (int) $related['id'],
                'name' => (string) $related['title'],
            ];

        }

        $locations = [];

        foreach ( jpkcom_acf_jobs_normalise_related( get_field( 'job_location', $post_id, true ) ) as $related ) {

            $location_id = (int) $related['id'];
            $place       = get_field( 'job_location_place', $location_id, false );

            $locations[] = [
                'id'    => $location_id,
                'name'  => (string) $related['title'],
                'place' => is_scalar( value: $place ) ? (string) $place : '',
            ];

        }

        // The term relations, not the job_attribute meta: save_terms and
        // load_terms are both on, so ACF discards the meta on read and the stored
        // copy can name a term the job no longer carries.
        $term_objects = get_the_terms( $post, 'job-attribute' );

        if ( is_wp_error( $term_objects ) || ! is_array( value: $term_objects ) ) {

            $term_objects = [];

        }

        $attributes = [];

        foreach ( $term_objects as $index => $term ) {

            if ( ! $term instanceof WP_Term ) {

                unset( $term_objects[ $index ] );

                continue;

            }

            $attributes[] = [
                'slug' => (string) $term->slug,
                'name' => (string) $term->name,
            ];

        }

        $permalink = (string) get_permalink( $post );

        $record = [
            'id'                   => (int) $post->ID,
            'title'                => (string) get_the_title( $post ),
            'url'                  => $external_url !== '' ? $external_url : $permalink,
            'redirects_externally' => $external_url !== '',
            'date'                 => (string) get_the_date( 'Y-m-d', $post ),
            'is_featured'          => (bool) get_field( 'job_featured', $post_id, true ),
            'is_closed'            => (bool) get_field( 'job_closed', $post_id, true ),
            'is_expired'           => $is_expired,
            'summary'              => jpkcom_acf_jobs_plain_text( get_field( 'job_short_description', $post_id, false ) ),
            'job_types'            => jpkcom_acf_jobs_normalise_choices( get_field( 'job_type', $post_id, true ) ),
            'work_type'            => jpkcom_acf_jobs_normalise_choice( get_field( 'job_work_type', $post_id, true ) ),
            'companies'            => $companies,
            'locations'            => $locations,
            'attributes'           => $attributes,
            'expiry_date'          => $expiry_date,
        ];

        if ( ! $full ) {

            return $record;

        }

        // Listed is not the same question as detail. A job with a job_url is
        // listed and merely redirects; a job with no job_featured row at all is
        // absent from every listing although its page renders perfectly well.
        // The EXISTS test is on the meta row, not on the value: the visibility
        // rule excludes a missing row, not a stored zero.
        $record['listed'] = true;

        if ( ! metadata_exists( 'post', $post_id, 'job_featured' ) ) {

            $record['listed']        = false;
            $record['listed_reason'] = 'missing_job_featured';

        } elseif ( $is_expired ) {

            $record['listed']        = false;
            $record['listed_reason'] = 'expired';

        }

        // The detail-page rule. Address, salary, attributes and application data
        // are public only as a side effect of a job's page rendering for a
        // visitor. Two states mean no such page exists — includes/redirects.php
        // 307s every caller without manage_options away from a job carrying a
        // job_url, and every caller without edit_post away from an expired one —
        // and the third, a post password, was already refused by the gate above.
        // Emitting the detail block in those states would publish data the site
        // has deliberately never shown.
        if ( $external_url !== '' ) {

            $record['detail_omitted_reason'] = 'redirects_externally';

            return $record;

        }

        if ( $is_expired ) {

            $record['detail_omitted_reason'] = 'expired';

            return $record;

        }

        $detail_companies = [];

        foreach ( $companies as $company ) {

            $company_url = get_field( 'job_company_url', $company['id'], true );
            $website     = '';

            if ( is_array( value: $company_url ) && isset( $company_url['url'] ) && is_string( value: $company_url['url'] ) ) {

                $website = $company_url['url'];

            }

            $detail_companies[] = [
                'id'       => $company['id'],
                'name'     => $company['name'],
                'url'      => $website,
                'logo_url' => jpkcom_acf_jobs_attachment_url( get_field( 'job_company_logo', $company['id'], false ) ),
            ];

        }

        $detail_locations = [];

        foreach ( $locations as $location ) {

            $address = [
                'id'    => $location['id'],
                'name'  => $location['name'],
                'place' => $location['place'],
            ];

            foreach ( [ 'street' => 'job_location_street', 'region' => 'job_location_region', 'country' => 'job_location_country' ] as $key => $field_name ) {

                $value = get_field( $field_name, $location['id'], false );

                $address[ $key ] = is_scalar( value: $value ) ? (string) $value : '';

            }

            // job_location_zip is an ACF number field. Read raw and never cast to
            // int: 01067 is a postal code, 1067 is a different one.
            $zip = get_field( 'job_location_zip', $location['id'], false );

            $address['zip'] = is_scalar( value: $zip ) ? (string) $zip : '';

            $detail_locations[] = $address;

        }

        $salary_group = get_field( 'job_base_salary_group', $post_id, true );
        $salary       = null;

        if ( is_array( value: $salary_group ) && isset( $salary_group['job_salary'] ) && is_numeric( $salary_group['job_salary'] ) ) {

            $currency = jpkcom_acf_jobs_normalise_choice( $salary_group['job_salary_currency'] ?? null );
            $period   = jpkcom_acf_jobs_normalise_choice( $salary_group['job_salary_period'] ?? null );

            $salary = [
                'amount'   => (float) $salary_group['job_salary'],
                'currency' => (string) ( $currency['value'] ?? '' ),
                'period'   => (string) ( $period['value'] ?? '' ),
            ];

        }

        $detail_attributes = [];

        foreach ( $term_objects as $term ) {

            $detail_attributes[] = [
                'term_id'     => (int) $term->term_id,
                'slug'        => (string) $term->slug,
                'name'        => (string) $term->name,
                'description' => jpkcom_acf_jobs_plain_text( $term->description ),
            ];

        }

        // The same gate the templates apply, and for the same reason it is not
        // cosmetic: the stored value survives the toggle being switched off, and
        // it is typically an internal applicant-tracking link or a recruiting
        // mailbox. job_application_shortcode is never emitted at all — it names
        // internal form IDs and has no meaning outside this site.
        //
        // Both keys are always present, and the gate empties the value rather
        // than removing the key: an application block with every field switched
        // off would otherwise be an empty PHP array, which json_encode() writes
        // as [] while the schema declares an object.
        $application = [
            'description' => '',
            'button'      => null,
        ];

        if ( get_field( 'job_application_show_description', $post_id, true ) ) {

            $application['description'] = jpkcom_acf_jobs_plain_text( get_field( 'job_application_description', $post_id, false ) );

        }

        if ( get_field( 'job_application_show_button', $post_id, true ) ) {

            $button = get_field( 'job_application_button', $post_id, true );

            if ( is_array( value: $button ) && isset( $button['url'] ) && is_string( value: $button['url'] ) && $button['url'] !== '' ) {

                $button_title = ( isset( $button['title'] ) && is_scalar( value: $button['title'] ) ) ? (string) $button['title'] : '';

                $application['button'] = [
                    'title' => $button_title,
                    'url'   => $button['url'],
                ];

            }

        }

        // job_layout_content is unbounded flexible content carrying one wysiwyg
        // and one attachment array per row, so it is capped rather than emitted
        // whole. Every sub-field is read through get_sub_field( …, false ): the
        // formatted read of a wysiwyg sub-field is the exact call the rule at the
        // top of this function exists to prevent.
        $layout_rows      = [];
        $layout_truncated = false;
        $layout_max_rows  = 20;

        if (
            function_exists( function: 'have_rows' )
            && function_exists( function: 'the_row' )
            && function_exists( function: 'get_row_layout' )
            && function_exists( function: 'get_sub_field' )
            && function_exists( function: 'reset_rows' )
        ) {

            while ( have_rows( 'job_layout_content', $post_id ) ) {

                if ( count( $layout_rows ) >= $layout_max_rows ) {

                    $layout_truncated = true;

                    // The loop is still on ACF's loop stack at this point, and
                    // leaving it there leaks into every later have_rows() call in
                    // the same request.
                    reset_rows();

                    break;

                }

                the_row();

                $layout_name = get_row_layout();
                $text        = '';
                $image       = null;

                foreach ( [ 'text_left', 'text_right', 'wysiwyg_full' ] as $sub_field ) {

                    $value = get_sub_field( $sub_field, false );

                    if ( is_string( value: $value ) && $value !== '' ) {

                        $text = jpkcom_acf_jobs_plain_text( $value );

                        break;

                    }

                }

                foreach ( [ 'img_left', 'img_right' ] as $sub_field ) {

                    $resolved = jpkcom_acf_jobs_attachment_url( get_sub_field( $sub_field, false ) );

                    if ( $resolved !== null ) {

                        $image = $resolved;

                        break;

                    }

                }

                $layout_rows[] = [
                    'layout' => is_string( value: $layout_name ) ? $layout_name : '',
                    'text'   => $text,
                    'image'  => $image,
                ];

            }

        }

        $record['detail'] = [
            'companies'             => $detail_companies,
            'locations'             => $detail_locations,
            'salary'                => $salary,
            'attributes'            => $detail_attributes,
            'application'           => $application,
            'layout'                => $layout_rows,
            'layout_rows_truncated' => $layout_truncated,
        ];

        return $record;

    }

}
