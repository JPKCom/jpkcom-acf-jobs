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

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	return $value;
}

// includes/abilities.php calls this at file scope, so the require would fatal
// without it and the run would never reach a single assertion.
function add_action( string $hook_name, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return true;
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

/**
 * Stand-in for WP_Term, carrying only the properties the reader may touch.
 */
class WP_Term {
	public int $term_id;
	public string $slug;
	public string $name;
	public string $taxonomy;
	public string $description;

	public function __construct( int $term_id, string $slug, string $name, string $taxonomy = 'job-attribute', string $description = '' ) {
		$this->term_id     = $term_id;
		$this->slug        = $slug;
		$this->name        = $name;
		$this->taxonomy    = $taxonomy;
		$this->description = $description;
	}
}

$GLOBALS['jpkcom_test_posts'] = [
	184 => new WP_Post( 184, 'Stelle 01', 'publish', 'job' ),
	182 => new WP_Post( 182, 'Testfirma GmbH', 'publish' ),
	183 => new WP_Post( 183, 'Teststadt', 'publish', 'job_location' ),
	5   => new WP_Post( 5, 'Unannounced GmbH', 'draft', 'job' ),
	500 => new WP_Post( 500, 'Stealth Mode GmbH', 'draft', 'job_company' ),
	501 => new WP_Post( 501, 'Confidential GmbH', 'publish', 'job_company', 'hunter2' ),
	700 => new WP_Post( 700, 'A page', 'publish', 'page' ),
	701 => new WP_Post( 701, 'Secret job', 'publish', 'job', 'hunter2' ),
];

/**
 * Stand-in for WP_Query.
 *
 * Records every argument set in $GLOBALS['jpkcom_test_queries'] so a test can
 * assert what was asked for, and then answers from the fixture above.
 *
 * It deliberately IGNORES post_status and has_password and hands back every post
 * of the requested type. That is not laziness: it models a site whose query
 * filters have widened the result set, which is the case the projection has to
 * survive. An assertion about the returned vocabulary can therefore only pass
 * because jpkcom_acf_jobs_normalise_related() drops the draft and the
 * password-protected record a second time — with a status-honouring stub the same
 * assertion would pass even if that projection were removed.
 */
class WP_Query {
	public array $posts       = [];
	public int $found_posts   = 0;
	public array $query_vars  = [];

	public function __construct( array $args = [] ) {
		$this->query_vars                 = $args;
		$GLOBALS['jpkcom_test_queries'][] = $args;

		$wanted = (string) ( $args['post_type'] ?? '' );
		$ids    = [];

		foreach ( $GLOBALS['jpkcom_test_posts'] as $id => $post ) {
			if ( $post->post_type === $wanted ) {
				$ids[] = (int) $id;
			}
		}

		$this->found_posts = count( $ids );

		$limit = (int) ( $args['posts_per_page'] ?? 0 );

		if ( $limit > 0 ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		$this->posts = $ids;
	}
}

$GLOBALS['jpkcom_test_queries'] = [];

function get_terms( array $args = [] ): array {
	if ( 'job-attribute' !== ( $args['taxonomy'] ?? '' ) ) {
		return [];
	}

	return [
		new WP_Term( 20, 'firmenwagen', 'Firmenwagen' ),
		new WP_Term( 21, 'vier-tage-woche', 'Vier-Tage-Woche' ),
	];
}

function update_postmeta_cache( array $post_ids ): array|false {
	return false;
}

function update_object_term_cache( array $object_ids, string $object_type ): void {
}

function determine_locale(): string {
	return 'en_US';
}

function get_locale(): string {
	return 'en_US';
}

function get_post( mixed $id = null ): ?WP_Post {
	// Mirrors real get_post(): a falsy id ( null, 0, '' ) is "empty" and falls
	// back to the current global post rather than failing lookup outright.
	// jpkcom_acf_jobs_get_job_data()'s `$post_id < 1` guard exists precisely
	// because of this fallback — without it, a caller passing 0 would silently
	// receive whatever post the current request left in $GLOBALS['post'].
	if ( empty( $id ) ) {
		return $GLOBALS['post'] ?? null;
	}

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

function metadata_exists( string $meta_type, int $object_id, string $meta_key ): bool {
	return false;
}

// The five ACF flexible-content functions the reader's detail branch walks
// job_layout_content with. get_sub_field()'s second argument is the same
// $format_value switch get_field() carries, and it is false for the same reason.
function have_rows( string $selector, mixed $post_id = false ): bool {
	return false;
}

function the_row( bool $format = false ): array {
	return [];
}

function get_row_layout(): string|false {
	return false;
}

function get_sub_field( string $selector, bool $format_value = true ): mixed {
	return null;
}

function reset_rows(): bool {
	return true;
}
