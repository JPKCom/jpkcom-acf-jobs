<?php
/**
 * Template Partial: meta
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<p class="entry-meta small mb-3 text-body-secondary">
    <time datetime="<?php echo get_the_date( 'Y-m-d' ); ?>" class="date-posted"><?php echo jpkcom_human_readable_relative_date( timestamp: get_the_date( 'U' ) ); ?></time>
</p>
