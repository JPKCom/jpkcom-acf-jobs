<?php
/**
 * Template Partial: job_closed
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<?php if ( get_field ('job_closed' ) ) { ?>

    <div class="alert alert-warning d-flex p-3" role="alert">
        <p><strong><?php echo __( 'Job vacancy currently already filled!', 'jpkcom-acf-jobs' ); ?></strong></p>
    </div>

<?php } ?>
