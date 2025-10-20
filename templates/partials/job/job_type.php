<?php
/**
 * Template Partial: job_type
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<?php if ( get_field( 'job_type' ) ) { ?>

    <h3 class="fs-4"><?php echo __( 'Type', 'jpkcom-acf-jobs' ); ?></h3>
    <?php

    $types = get_field( 'job_type' );

    if ( $types && is_array( value: $types ) ) {

        $total = count( value: $types );
        $i = 0;

        echo '<p class="fs-5">';

        foreach ( $types as $type ) {

            echo $type['label'];
            $i++;

            if ( $i < $total ) {
                echo ', ';

            }

        }

        echo '</p>';

    }
    ?>

    <?php if ( get_field( 'job_work_type' ) ) {

        $job_work_type = get_field( 'job_work_type' );
        $job_work_type_label = '';

        if ( $job_work_type['value'] === 'homeoffice' ) {

            $job_work_type_label = __( 'Home office', 'jpkcom-acf-jobs' );

        } elseif ( $job_work_type['value'] === 'onsitework' ) {

            $job_work_type_label = __( 'Onsite work', 'jpkcom-acf-jobs' );

        } else {

            $job_work_type_label = __( 'Home office and onsite work', 'jpkcom-acf-jobs' );

        }

        echo '<p>' . __( 'Ways of working', 'jpkcom-acf-jobs' ) . ': ';
        echo $job_work_type_label;
        echo '</p>';

    } ?>

    <hr>

<?php } ?>
