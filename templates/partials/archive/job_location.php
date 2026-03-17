<?php
/**
 * Template Partial: job_location
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<?php if ( get_field( 'job_location' ) ) { ?>
    <li class="d-block">
        <strong><?php echo esc_html__( 'Location', 'jpkcom-acf-jobs' ); ?>:</strong><br>
        <?php
        $locations = get_field( 'job_location' );
        if ( $locations && is_array( value: $locations ) ) {
            $total = count( value: $locations );
            $i = 0;
            foreach ( $locations as $location ) {
                echo esc_html( get_the_title( $location->ID ) );
                $i++;
                if ( $i < $total ) {
                    echo ',<br>';
                }
            }
        }
        ?>
    </li>
<?php } ?>
