<?php
/**
 * Template Partial: job_company
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<?php if ( get_field( 'job_company' ) ) { ?>

    <h3 class="fs-4"><?php echo __( 'Company', 'jpkcom-acf-jobs' ); ?></h3>
    <?php

    $companies = get_field( 'job_company' );

    if ( $companies && is_array( value: $companies ) ) {
        
        $total = count( value: $companies );
        $i = 0;

        foreach ( $companies as $company ) {

            $job_company_url_HTML_Before = '';
            $job_company_url_HTML_After = '';
            $job_company_url_HTML_Closing = '';
            $job_company_url = '';

            if ( get_field( 'job_company_url', $company->ID ) ) {

                $job_company_url_HTML_Before = '<a class="link-light link-offset-2 link-underline-opacity-25 link-underline-opacity-100-hover" href="';
                $job_company_url_HTML_After = '">';
                $job_company_url_HTML_Closing = '</a>';
                $job_company_url_array = get_field( 'job_company_url', $company->ID );
                $job_company_url = esc_url( $job_company_url_array['url'] );

            }

            echo '<div class="row mb-3">';
            echo '<div class="col-2">';

            if ( get_field( 'job_company_logo', $company->ID ) ) {

                $job_company_logo = get_field( 'job_company_logo', $company->ID );
                $size = 'jpkcom-acf-job-logo';

                echo $job_company_url_HTML_Before . $job_company_url . $job_company_url_HTML_After;
                echo wp_get_attachment_image( $job_company_logo, $size );

                    if ( is_array( value: $job_company_logo ) && isset( $job_company_logo['ID'] ) ) {

                        echo wp_get_attachment_image( $job_company_logo['ID'], $size, false, [
                            'class' => 'img-fluid rounded shadow-sm',
                            'alt'   => esc_attr( $job_company_logo['alt'] ?? get_the_title( $company->ID ) ),
                        ] );

                    } elseif ( is_numeric( value: $job_company_logo ) ) {

                        echo wp_get_attachment_image( $job_company_logo, $size, false, [
                            'class' => 'img-fluid rounded shadow-sm',
                        ] );

                    }

                echo $job_company_url_HTML_Closing;

            }

            echo '</div>';
            echo '<div class="col-10">';
            echo '<p class="fs-5"><strong>' . $job_company_url_HTML_Before . $job_company_url . $job_company_url_HTML_After . get_the_title( $company->ID ) . $job_company_url_HTML_Closing . '</strong></p>';
            echo '</div>';
            echo '</div>';

        }

    }
    ?>

    <hr>

<?php } ?>
