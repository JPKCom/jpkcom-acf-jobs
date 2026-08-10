<?php
/**
 * Remove everything tools/seed-jobs.php created, and nothing else.
 *
 * Run from the DDEV project root:
 *
 *     ddev wp eval-file wp-content/plugins/jpkcom-acf-jobs/tools/unseed-jobs.php
 *
 * The selector is the _jpkcom_seed meta row that seed-jobs.php writes, not a post
 * ID range and not a title pattern: an ID range silently deletes whatever a later
 * run of anything else put in the same range.
 *
 * It also reports the oembed_cache count, because a stray row there is the finding
 * the seed exists to produce — see spec section 10.
 *
 * Deliberately without declare(strict_types=1): WP-CLI's eval-file evaluates the
 * file rather than including it, and a declare() is not the first statement of
 * that eval.
 *
 * @package JPKCom_ACF_Jobs
 * @since   1.4.0
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

global $wpdb;

$ids = $wpdb->get_col(
	$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_jpkcom_seed' )
);

if ( ! $ids ) {
	echo "Nothing to remove.\n";
}

foreach ( $ids as $id ) {
	$post = get_post( (int) $id );

	if ( ! $post ) {
		continue;
	}

	printf( "deleting %d  %s  %s\n", $post->ID, $post->post_type, $post->post_title );

	if ( 'attachment' === $post->post_type ) {
		// true, so the file goes with the row.
		wp_delete_attachment( (int) $id, true );
		continue;
	}

	wp_delete_post( (int) $id, true );
}

echo "\nremaining:\n";

foreach ( [ 'job', 'job_company', 'job_location', 'attachment', 'oembed_cache' ] as $type ) {
	$posts = get_posts(
		[
			'post_type'      => $type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]
	);

	printf( "  %-14s %s\n", $type, $posts ? implode( ',', $posts ) : '(none)' );
}

echo "\nAn oembed_cache row above means a long-form field was read in ACF's formatted\n";
echo "mode by something that ran during these measurements. It is a finding, not noise.\n";
