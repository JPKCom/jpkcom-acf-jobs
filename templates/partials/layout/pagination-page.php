<?php
/**
 * Template Partial: pagination-page
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<nav aria-label="<?php echo esc_html__( 'Job page navigation', 'jpkcom-acf-jobs' ); ?>">
    <ul class="pagination justify-content-center">
        <li class="page-item">
            <a href="<?php echo get_post_type_archive_link( 'job' ); ?>" class="page-link">&larr; <?php echo __( 'Back to overview', 'jpkcom-acf-jobs' ); ?></a>
        </li>
        <li class="page-item">
            <?php previous_post_link('%link'); ?>
        </li>
        <li class="page-item">
            <?php next_post_link('%link'); ?>
        </li>
    </ul>
</nav>