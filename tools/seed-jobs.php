<?php
/**
 * Seed the job fixtures the abilities need and a normal site does not have.
 *
 * The six jobs a fresh test install carries cover only the happy path: one
 * company, one location, no expiry, none closed, no external URL, no layout rows
 * and no long-form field carrying anything worth formatting. Every interesting
 * branch of includes/jobs-data.php and includes/abilities.php therefore has no
 * data behind it, which is how detail.layout[].image shipped as an unmeasured
 * schema declaration.
 *
 * Run from the DDEV project root:
 *
 *     ddev wp eval-file wp-content/plugins/jpkcom-acf-jobs/tools/seed-jobs.php
 *
 * Every post created here carries a _jpkcom_seed meta row, and tools/unseed-jobs.php
 * removes exactly those and nothing else. Never run this against production.
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

if ( ! function_exists( 'get_field' ) ) {
	echo "ACF is not active; nothing seeded.\n";
	exit( 1 );
}

$created = [];

/**
 * Create one published post and mark it as seeded.
 *
 * @param string $type  Post type.
 * @param string $title Post title.
 * @return int New post ID.
 */
function jpkcom_seed_post( string $type, string $title ): int {
	$id = wp_insert_post(
		[
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => '',
		],
		true
	);

	if ( is_wp_error( $id ) ) {
		echo 'FAILED ' . $title . ': ' . $id->get_error_message() . "\n";
		exit( 1 );
	}

	update_post_meta( $id, '_jpkcom_seed', 1 );

	return (int) $id;
}

// A second company and a second location, so every "several of them" branch has
// something to be several of.
$company = jpkcom_seed_post( 'job_company', 'Zweitfirma AG' );
update_field( 'job_company_url', [ 'title' => 'Website', 'url' => 'https://zweitfirma.example.com/', 'target' => '' ], $company );
$created['company_2'] = $company;

$location = jpkcom_seed_post( 'job_location', 'Zweitort' );
update_field( 'job_location_place', 'Zweitstadt', $location );
update_field( 'job_location_street', 'Zweitweg 2', $location );
// A leading zero that must survive as a string. ACF's zip field is a number field.
update_field( 'job_location_zip', '01067', $location );
update_field( 'job_location_region', 'Sachsen', $location );
update_field( 'job_location_country', 'DE', $location );
$created['location_2'] = $location;

// No job_featured meta ROW at all — not a stored zero. Two independent causes
// then keep this job out of every listing, and get-job is the only thing on the
// site that reports either.
$created['no_featured_row'] = jpkcom_seed_post( 'job', 'Seed: ohne job_featured-Zeile' );

$expired = jpkcom_seed_post( 'job', 'Seed: abgelaufen' );
update_field( 'job_featured', 0, $expired );
update_field( 'job_expiry_date', '20250101', $expired );
$created['expired'] = $expired;

// The same state, stored in a spelling jpkcom_acf_jobs_normalise_date() refuses
// and redirects.php still acts on. The detail-page predicate must withhold here.
$unreadable = jpkcom_seed_post( 'job', 'Seed: unlesbares Ablaufdatum' );
update_field( 'job_featured', 0, $unreadable );
update_post_meta( $unreadable, 'job_expiry_date', '2025-11-30 00:00:00' );
update_field( 'job_base_salary_group', [ 'job_salary' => 424242, 'job_salary_currency' => 'EUR', 'job_salary_period' => 'MONTH' ], $unreadable );
update_field( 'job_location', [ $location ], $unreadable );
$created['unreadable_expiry'] = $unreadable;

$closed = jpkcom_seed_post( 'job', 'Seed: Stelle vergeben' );
update_field( 'job_featured', 0, $closed );
update_field( 'job_closed', 1, $closed );
$created['closed'] = $closed;

$multi = jpkcom_seed_post( 'job', 'Seed: mehrere Beschaeftigungsarten' );
update_field( 'job_featured', 0, $multi );
update_field( 'job_type', [ 'FULL_TIME', 'PART_TIME', 'INTERN' ], $multi );
update_field( 'job_company', [ $company ], $multi );
update_field( 'job_location', [ $location ], $multi );
update_field( 'job_base_salary_group', [ 'job_salary' => 4200, 'job_salary_currency' => 'EUR', 'job_salary_period' => 'MONTH' ], $multi );
$created['multi_type'] = $multi;

// Redirects every visitor without manage_options away with a 307, so its detail
// page never renders and its salary and address were never published.
$redirecting = jpkcom_seed_post( 'job', 'Seed: leitet extern weiter' );
update_field( 'job_featured', 0, $redirecting );
update_field( 'job_url', [ 'title' => 'Bewerben', 'url' => 'https://example.com/apply', 'target' => '' ], $redirecting );
update_field( 'job_company', [ $company ], $redirecting );
update_field( 'job_location', [ $location ], $redirecting );
update_field( 'job_base_salary_group', [ 'job_salary' => 9999, 'job_salary_currency' => 'EUR', 'job_salary_period' => 'MONTH' ], $redirecting );
$created['redirecting'] = $redirecting;

// A site-relative target: redirects.php resolves it through home_url() before
// dispatching, so the stored value is not the destination.
$relative = jpkcom_seed_post( 'job', 'Seed: relative Weiterleitung' );
update_field( 'job_featured', 0, $relative );
update_field( 'job_url', [ 'title' => 'Bewerben', 'url' => '/bewerben', 'target' => '' ], $relative );
$created['relative_url'] = $relative;

// THE measurement fixture. A shortcode and a bare URL alone on its own line:
// read in ACF's default formatted mode, acf_the_content runs do_shortcode at
// priority 11 and WP_Embed::autoembed at 8, and with no post context autoembed
// fetches the remote URL and wp_insert_post()s an oembed_cache row. Count
// oembed_cache posts before and after a get-job call on this job.
$embedding = jpkcom_seed_post( 'job', 'Seed: Shortcode und nackte URL' );
update_field( 'job_featured', 0, $embedding );
update_field( 'job_application_show_description', 1, $embedding );
update_field(
	'job_application_description',
	"<p>Bewerbungen bitte hier:</p>\n[gallery ids=\"1,2\"]\n\nhttps://wordpress.org/\n\n<p>Wir freuen uns.</p>",
	$embedding
);
update_field( 'job_application_show_button', 1, $embedding );
update_field( 'job_application_button', [ 'title' => 'Jetzt bewerben', 'url' => 'https://example.com/bewerben', 'target' => '' ], $embedding );
update_field( 'job_application_show_shortcode', 1, $embedding );
update_field( 'job_application_shortcode', '[jpkcom_internal_form id="7"]', $embedding );
$created['embedding'] = $embedding;

// A layout row WITH an image and one without, so both halves of
// detail.layout[].image ( [string,null] ) are produced by one record. An
// attachment is created when the site has none, because a test install usually
// has none at all.
$attachment = get_posts(
	[
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	]
);

$attachment_id = (int) ( $attachment[0] ?? 0 );

if ( $attachment_id < 1 ) {
	$uploads = wp_upload_dir();
	$file    = trailingslashit( $uploads['path'] ) . 'jpkcom-seed-image.png';

	// A 1x1 PNG, so nothing outside WordPress is needed to produce one.
	file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );

	$attachment_id = (int) wp_insert_attachment(
		[
			'post_mime_type' => 'image/png',
			'post_title'     => 'jpkcom-seed-image',
			'post_status'    => 'inherit',
		],
		$file
	);

	if ( $attachment_id > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file ) );
		update_post_meta( $attachment_id, '_jpkcom_seed', 1 );
	}
}

$layout = jpkcom_seed_post( 'job', 'Seed: mit Layout-Zeilen' );
update_field( 'job_featured', 0, $layout );
update_field(
	'job_layout_content',
	[
		[
			'acf_fc_layout' => 'layout_2_col_text_img',
			'text_left'     => "<p>Erste Zeile mit Shortcode:</p>\n[gallery ids=\"1,2\"]\n\nhttps://wordpress.org/\n",
			'img_right'     => $attachment_id,
		],
		[
			'acf_fc_layout' => 'layout_wysiwyg_full',
			'wysiwyg_full'  => '<p>Zweite Zeile, ohne Bild.</p>',
		],
	],
	$layout
);
$created['layout_rows'] = $layout;

$created['attachment'] = $attachment_id;

echo "SEEDED\n";

foreach ( $created as $label => $id ) {
	echo str_pad( $label, 20 ) . $id . "\n";
}

echo "\nRemove again with tools/unseed-jobs.php.\n";
