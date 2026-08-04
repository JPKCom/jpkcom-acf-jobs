<?php
/**
 * Shared harness for the behavioural test files.
 *
 * This repo has no PHPUnit, no Composer and no autoloader, so a test that wants
 * to call plugin code has to supply the WordPress functions that code touches.
 * Every stub here is a function includes/jobs-data.php or includes/abilities.php
 * actually calls, and nothing else — this is a stub set, not a framework.
 *
 * Not named bootstrap.php and not named test-*.php on purpose: the first would
 * suggest a framework that does not exist, the second would make CI run it.
 *
 * @package JPKCom_ACF_Jobs
 * @since 1.4.0
 */

declare(strict_types=1);

$root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}

$pass = 0;
$fail = 0;

/**
 * Record one assertion.
 *
 * @param string $label Human-readable check name.
 * @param bool   $ok    Whether the assertion holds.
 * @param string $why   Explanation printed on failure.
 */
function chk( string $label, bool $ok, string $why = '' ): void {
	global $pass, $fail;

	if ( $ok ) {
		$pass++;
		echo "  PASS  {$label}\n";
		return;
	}

	$fail++;
	echo "  FAIL  {$label}\n";

	if ( '' !== $why ) {
		echo "        {$why}\n";
	}
}

/**
 * Print the tally and exit with a status CI can read.
 *
 * @return never
 */
function summary(): void {
	global $pass, $fail;

	printf( "\n  %d passed, %d failed\n", $pass, $fail );

	exit( $fail > 0 ? 1 : 0 );
}

function current_time( string $type, int|bool $gmt = 0 ): string {
	return '2026-01-15';
}

function sanitize_text_field( string $str ): string {
	return trim( strip_tags( $str ) );
}

function absint( mixed $maybeint ): int {
	return abs( (int) $maybeint );
}

/**
 * Stand-in for WP_Post, carrying only the properties the reader is allowed to touch.
 */
class WP_Post {
	public int $ID;
	public string $post_title;
	public string $post_status;
	public string $post_type;
	public string $post_password;

	public function __construct( int $id, string $title, string $status, string $type = 'job_company', string $password = '' ) {
		$this->ID            = $id;
		$this->post_title    = $title;
		$this->post_status   = $status;
		$this->post_type     = $type;
		$this->post_password = $password;
	}
}

$GLOBALS['jpkcom_test_posts'] = [
	184 => new WP_Post( 184, 'Stelle 01', 'publish', 'job' ),
	182 => new WP_Post( 182, 'Testfirma GmbH', 'publish' ),
	5   => new WP_Post( 5, 'Unannounced GmbH', 'draft', 'job' ),
	700 => new WP_Post( 700, 'A page', 'publish', 'page' ),
	701 => new WP_Post( 701, 'Secret job', 'publish', 'job', 'hunter2' ),
];

function get_post( mixed $id = null ): ?WP_Post {
	return $GLOBALS['jpkcom_test_posts'][ (int) $id ] ?? null;
}

function get_the_title( mixed $post = null ): string {
	$p = is_object( $post ) ? $post : get_post( $post );
	return $p instanceof WP_Post ? $p->post_title : '';
}

function get_field( string $selector, mixed $post_id = false, bool $format_value = true, bool $escape_html = false ): mixed {
	return null;
}

function get_permalink( mixed $post = null ): string {
	return 'https://example.test/job/' . ( is_object( $post ) ? $post->ID : (int) $post ) . '/';
}

function get_the_date( string $format = '', mixed $post = null ): string {
	return '2026-01-10';
}

function get_the_terms( mixed $post, string $taxonomy ): array|false {
	return false;
}

function is_wp_error( mixed $thing ): bool {
	return false;
}

function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
	return trim( strip_tags( $text ) );
}

function wp_get_attachment_image_src( int $id, string $size = 'thumbnail' ): array|false {
	return false;
}
