<?php
/**
 * Template Partial: job_short_description
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<p class="lead">
    <?php echo wp_kses_post( get_field( 'job_short_description' ) ); ?>
</p>
