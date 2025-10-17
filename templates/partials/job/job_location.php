<?php
/**
 * Template Partial: job_location
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<?php if ( get_field( 'job_location' ) ) { ?>

    <h3 class="fs-4"><?php echo __( 'Location', 'jpkcom-acf-jobs' ); ?></h3>
    <?php

    $locations = get_field( 'job_location' );

    if ( $locations && is_array( value: $locations ) ) {

        echo '<address class="d-block">';
        $total = count( value: $locations );
        $i = 0;

        foreach ( $locations as $location ) {

            echo '<strong>' . get_the_title( $location->ID ) . '</strong><br>';

            if ( get_field( 'job_location_street', $location->ID ) ) {
                echo get_field( 'job_location_street', $location->ID ) . '<br>';
            }

            if ( get_field( 'job_location_zip', $location->ID ) && get_field( 'job_location_place', $location->ID ) ) {
                echo get_field( 'job_location_zip', $location->ID ) . ' ';
            }

            if ( get_field( 'job_location_place', $location->ID ) ) {
                echo get_field( 'job_location_place', $location->ID ) . '<br>';
            }

            if ( get_field( 'job_location_region', $location->ID ) ) {
                echo get_field( 'job_location_region', $location->ID ) . '<br>';
            }

            if ( get_field( 'job_location_country', $location->ID ) ) {
                echo get_field( 'job_location_country', $location->ID ) . '<br>';
            }

            $i++;

            if ( $i < $total ) {
                echo '</address><address class="d-block">';
            }

        }

        echo '</address>';

    }
    ?>

    <hr>

<?php } ?>
