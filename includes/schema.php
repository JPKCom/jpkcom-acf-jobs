<?php
/**
 * Schema.org JobPosting generation functions
 *
 * Generates structured data (JSON-LD) for job postings according to
 * Schema.org specifications for improved search engine visibility.
 *
 * @package   JPKCom_ACF_Jobs
 * @since     1.0.0
 * @link      https://schema.org/JobPosting
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


/**
 * Generate Schema.org JobPosting JSON-LD for a single job post
 *
 * Creates a complete JobPosting schema including:
 * - Basic job information (title, description, dates)
 * - Employment type (FULL_TIME, PART_TIME, etc.)
 * - Hiring organization details and logo
 * - Job location(s) with postal addresses
 * - Work type (on-site, remote/TELECOMMUTE)
 * - Base salary information
 * - Application contact/URL
 * - Job benefits from taxonomy terms
 *
 * The schema can be filtered using the 'jpkcom_acf_jobs_schema_job_posting' hook.
 *
 * @since 1.0.0
 *
 * @param int|null $post_id Optional. Post ID of the job post. Default null (uses current post).
 * @return string JSON-LD formatted string ready for output in <script> tag, or empty string on failure.
 */
function jpkcom_acf_jobs_get_schema_job_posting( ?int $post_id = null ): string {

    if ( ! $post_id ) {

        $post_id = get_the_ID();

    }

    if ( ! $post_id || get_post_type( $post_id ) !== 'job' ) {

        return '';

    }

    $schema = [
        '@context'  => 'https://schema.org',
        '@type'     => 'JobPosting',
        'title'     => get_the_title( $post_id ),
        'description' => wp_kses_post( get_field( 'job_short_description', $post_id ) ?? get_the_content( null, false, $post_id ) ),
        'identifier' => [
            '@type'  => 'PropertyValue',
            'name'   => get_bloginfo( 'name' ),
            'value'  => (string) $post_id,
        ],
        'datePosted' => get_the_date( 'Y-m-d', $post_id ),
    ];

    $expiry = get_field( 'job_expiry_date', $post_id );

    if ( ! empty( $expiry ) ) {

        $schema['validThrough'] = date( format: 'Y-m-d', timestamp: strtotime( datetime: $expiry ) );

    }

    $job_types = get_field('job_type', $post_id);

    if ( $job_types && is_array( value: $job_types ) ) {

        $values = [];

        foreach ( $job_types as $type ) {

            if ( is_array(value: $type) && isset($type['value']) ) {

                $values[] = $type['value'];

            } elseif ( is_string(value: $type) ) {

                $values[] = $type;
            }

        }

        if ( ! empty( $values ) ) {

            $schema['employmentType'] = $values;

        }
        
    }

    $companies = get_field( 'job_company', $post_id );

    if ( $companies ) {

        if ( ! is_array( value: $companies ) ) {

            $companies = [ $companies ];

        }

        $first_company = $companies[0];
        $company_data = [
            '@type' => 'Organization',
            'name'  => get_the_title( $first_company->ID ),
        ];

        $logo = get_field( 'job_company_logo', $first_company->ID );

        if ( is_array( value: $logo ) && ! empty( $logo['ID'] ) ) {

            $logo_src = wp_get_attachment_image_src( $logo['ID'], 'jpkcom-acf-job-logo' );

            if ( $logo_src && ! empty( $logo_src[0] ) ) {

                $company_data['logo'] = esc_url( $logo_src[0] );

            }

        }

        $schema['hiringOrganization'] = $company_data;

    } else {

        $schema['hiringOrganization'] = [
            '@type' => 'Organization',
            'name'  => 'confidential',
        ];

    }

    $locations = get_field( 'job_location', $post_id );

    if ( $locations ) {

        if ( ! is_array( value: $locations ) ) {

            $locations = [ $locations ];

        }

        $schema['jobLocation'] = [];

        foreach ( $locations as $location ) {

            $location_array = [
                '@type' => 'Place',
                'address' => [
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => get_field( 'job_location_street', $location->ID ) ?: '',
                    'postalCode'      => get_field( 'job_location_zip', $location->ID ) ?: '',
                    'addressLocality' => get_field( 'job_location_place', $location->ID ) ?: '',
                    'addressRegion'   => get_field( 'job_location_region', $location->ID ) ?: '',
                    'addressCountry'  => get_field( 'job_location_country', $location->ID ) ?: '',
                ]
            ];
            $schema['jobLocation'][] = $location_array;

        }

    }

    $work_type = get_field( 'job_work_type', $post_id );

    if ( $work_type && is_array( value: $work_type ) ) {

        $value = $work_type['value'] ?? $work_type[0] ?? '';

        if ( $value === 'homeoffice' ) {

            $schema['jobLocationType'] = 'TELECOMMUTE';

            $locations = get_field( 'job_location', $post_id );

            if ( $locations ) {

                if ( ! is_array( value: $locations ) ) {

                    $locations = [ $locations ];

                }

                $schema['applicantLocationRequirements'] = [];

                foreach ( $locations as $location ) {

                    $country = get_field( 'job_location_country', $location->ID ) ?: '';


                    if ( ! empty( $country ) ) {

                        $name = trim( string: $country );

                        $schema['applicantLocationRequirements'][] = [
                            '@type' => 'Country',
                            'name'  => $name,
                        ];

                    }

                }

            }

        }

    }

    $salary_group = get_field( 'job_base_salary_group', $post_id );

    if ( $salary_group && is_array( value: $salary_group ) ) {

        $amount = $salary_group['job_salary'] ?? null;
        $currency_field = $salary_group['job_salary_currency'] ?? null;
        $period_field   = $salary_group['job_salary_period'] ?? null;

        $currency = is_array( value: $currency_field ) ? ($currency_field['value'] ?? $currency_field['return_value'] ?? '') : $currency_field;
        $period   = is_array( value: $period_field ) ? ($period_field['value'] ?? $period_field['return_value'] ?? '') : $period_field;

        if ( $amount && $currency ) {

            $schema['baseSalary'] = [
                '@type' => 'MonetaryAmount',
                'currency' => strtoupper( string: $currency ),
                'value' => [
                    '@type' => 'QuantitativeValue',
                    'value' => number_format( num: $amount, decimals: 2, decimal_separator: ',', thousands_separator: '.'),
                    'unitText' => $period ?: 'YEAR',
                ],
            ];

        }

    }

    $apply_btn = get_field( 'job_application_button', $post_id );

    if ( $apply_btn && is_array( value: $apply_btn ) && ! empty( $apply_btn['url'] ) ) {

        $schema['directApply'] = true;
        $schema['applicationContact'] = [
            '@type' => 'ContactPoint',
            'url'   => esc_url( $apply_btn['url'] ),
        ];

    }

    $attributes = get_field( 'job_attribute', $post_id );

    if ( $attributes ) {

        $benefits = [];
        foreach ( $attributes as $term_id ) {

            $term = get_term( $term_id );
            if ( $term && ! is_wp_error( $term ) ) {

                $benefits[] = $term->name;

            }

        }

        if ( ! empty( $benefits ) ) {

            $schema['jobBenefits'] = $benefits;

        }

    }

    /**
     * Filter the JobPosting schema before output
     *
     * @since 1.0.0
     *
     * @param array $schema  The complete schema array.
     * @param int   $post_id The job post ID.
     */
    $schema = apply_filters( 'jpkcom_acf_jobs_schema_job_posting', $schema, $post_id );

    return wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ?: '';
}
