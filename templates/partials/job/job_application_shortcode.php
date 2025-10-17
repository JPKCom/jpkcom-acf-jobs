<?php
/**
 * Template Partial: job_application_shortcode
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<?php if ( get_field( 'job_application_show_shortcode' ) ) { ?>
    <?php if ( get_field( 'job_application_shortcode' ) ) { ?>

        <?php echo do_shortcode( get_field( 'job_application_shortcode' ) ); ?>

    <?php } ?>
<?php } ?>
