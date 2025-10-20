<?php
/**
 * Template Partial: job_type
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<?php if ( get_field( 'job_type' ) ) { ?>
    <li class="d-block">
        <strong><?php echo __( 'Type', 'jpkcom-acf-jobs' ); ?>:</strong><br>
        <?php
        $types = get_field( 'job_type' );
        if ( $types && is_array( value: $types ) ) {
            $total = count( value: $types );
            $i = 0;
            foreach ( $types as $type ) {
                echo $type['label'];
                $i++;
                if ( $i < $total ) {
                    echo ',<br>';
                }
            }
        }
        ?>
    </li>
<?php } ?>
