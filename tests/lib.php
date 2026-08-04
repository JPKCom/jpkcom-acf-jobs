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

// One callback per hook, registered by assigning to this map. Without it the
// ability path's own argument filter cannot be exercised at all, and the guards
// that run AFTER it — the post type, the status and the dropped-clause check —
// would be asserted only against arguments nothing had touched.
$GLOBALS['jpkcom_test_filters'] = [];

function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	$callback = $GLOBALS['jpkcom_test_filters'][ $hook_name ] ?? null;

	if ( null === $callback ) {
		return $value;
	}

	return $callback( $value, ...$args );
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
 * Stand-in for WP_Error, carrying only what the ability path sets and reads.
 *
 * The status in $data is the load-bearing part: the REST run controller returns
 * the WP_Error verbatim and rest_ensure_response() answers 500 without it, which
 * tells an agent "transient fault, retry unchanged".
 */
class WP_Error {
	public string $code;
	public string $message;
	public array $data;

	public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = is_array( $data ) ? $data : [];
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
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
	public array $posts        = [];
	public int $found_posts    = 0;
	public int $max_num_pages  = 0;
	public array $query_vars   = [];

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

		$total = count( $ids );
		$limit = (int) ( $args['posts_per_page'] ?? 0 );
		$paged = max( 1, (int) ( $args['paged'] ?? 1 ) );

		if ( $limit > 0 ) {
			// absint(), exactly as WP_Query::get_posts() computes the LIMIT offset.
			// It does not throw on an overflowed product, it CASTS one — a float
			// beyond PHP_INT_MAX collapses to 0 and page one's records come back
			// labelled with whatever page number was asked for. Modelling that is
			// the whole point; a stub that threw would hide it behind a fatal.
			$ids = array_slice( $ids, absint( ( $paged - 1 ) * $limit ), $limit );
		}

		// Mirrors WP_Query::set_found_posts(), which returns early when posts is
		// empty and leaves found_posts and max_num_pages at zero. That early return
		// is exactly what makes a page past the last one report a corpus of zero
		// next to a page number of three, and a stub that answered with the real
		// total anyway would leave the recovery guard untestable.
		if ( [] === $ids ) {
			$this->posts = [];
			return;
		}

		$this->found_posts   = $total;
		$this->max_num_pages = $limit > 0 ? (int) ceil( $total / $limit ) : 1;
		$this->posts         = $ids;

		if ( 'ids' === ( $args['fields'] ?? '' ) ) {
			return;
		}

		// A real WP_Query hands back WP_Post objects unless fields => 'ids', and the
		// projection has to survive both shapes: a site filter may set fields.
		$this->posts = array_map(
			static fn( int $id ): WP_Post => $GLOBALS['jpkcom_test_posts'][ $id ],
			$ids
		);
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

// Deliberately NOT the same value as determine_locale(). The resolver tries
// determine_locale() first and get_locale() only as a last resort; with both
// stubbed to the same string, deleting the determine_locale() branch entirely
// would leave every language assertion green.
function get_locale(): string {
	return 'en_GB';
}

// Counted, because "how much work did this cost" is as much a part of an answer as
// the answer itself: two refusals that differ only in the number of lookups they
// perform still tell a caller which of the two IDs exists.
$GLOBALS['jpkcom_test_get_post_calls'] = 0;

function get_post( mixed $id = null ): ?WP_Post {
	$GLOBALS['jpkcom_test_get_post_calls']++;

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

// Field values a test wants the reader to see, keyed by post ID and then by
// field name. Empty by default, so every existing assertion keeps seeing the
// null ACF returns for an unset field.
$GLOBALS['jpkcom_test_fields'] = [];

function get_field( string $selector, mixed $post_id = false, bool $format_value = true, bool $escape_html = false ): mixed {
	$fields = $GLOBALS['jpkcom_test_fields'][ (int) $post_id ] ?? [];

	return $fields[ $selector ] ?? null;
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

function get_post_type( mixed $post = null ): string|false {
	$p = get_post( $post );

	return $p instanceof WP_Post ? $p->post_type : false;
}

function get_term_by( string $field, mixed $value, string $taxonomy = '' ): WP_Term|false {
	// Deliberately narrow: the ability path is only ever allowed to resolve a slug
	// inside job-attribute, and a stub that answered for any field or any taxonomy
	// would make the taxonomy argument untestable.
	if ( 'slug' !== $field || 'job-attribute' !== $taxonomy ) {
		return false;
	}

	foreach ( get_terms( [ 'taxonomy' => 'job-attribute' ] ) as $term ) {
		if ( $term->slug === $value ) {
			return $term;
		}
	}

	return false;
}

$GLOBALS['jpkcom_test_options'] = [];

function get_option( string $option, mixed $default_value = false ): mixed {
	return $GLOBALS['jpkcom_test_options'][ $option ] ?? $default_value;
}

function get_post_type_archive_link( string $post_type ): string|false {
	return 'https://example.test/' . $post_type . 's/';
}

// Real, not a constant false: the ability path decides its own control flow with
// this, so a stub that always said "no error" would run a WP_Error on as if it
// were a result array.
function is_wp_error( mixed $thing ): bool {
	return $thing instanceof WP_Error;
}

function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
	return trim( strip_tags( $text ) );
}

// Attachment IDs a test wants to resolve to a URL. Empty by default, so an
// unseeded image field still reports the false ACF gives for a missing file.
$GLOBALS['jpkcom_test_attachments'] = [];

function wp_get_attachment_image_src( int $id, string $size = 'thumbnail' ): array|false {
	$url = $GLOBALS['jpkcom_test_attachments'][ $id ] ?? null;

	if ( null === $url ) {
		return false;
	}

	return [ $url, 300, 200, false ];
}

// Meta keys that exist as a ROW, whatever their value. job_featured is the one
// that matters: the visibility rule excludes a missing row, not a stored zero.
$GLOBALS['jpkcom_test_meta_rows'] = [];

function metadata_exists( string $meta_type, int $object_id, string $meta_key ): bool {
	return in_array( $meta_key, $GLOBALS['jpkcom_test_meta_rows'][ (int) $object_id ] ?? [], true );
}

// The five ACF flexible-content functions the reader's detail branch walks
// job_layout_content with. get_sub_field()'s second argument is the same
// $format_value switch get_field() carries, and it is false for the same reason.
//
// Rows a test wants the reader to find, keyed by post ID and then by field name,
// each row a map of sub-field name => value plus an acf_fc_layout. Empty by
// default, so a test that seeds nothing sees exactly what ACF reports for a job
// with no content rows.
$GLOBALS['jpkcom_test_rows'] = [];

// The single active loop, modelling ACF's loop stack far enough for one reader.
$GLOBALS['jpkcom_test_row_loop'] = null;

function have_rows( string $selector, mixed $post_id = false ): bool {
	$key = (int) $post_id . '|' . $selector;

	if ( ( $GLOBALS['jpkcom_test_row_loop']['key'] ?? null ) !== $key ) {
		$GLOBALS['jpkcom_test_row_loop'] = [
			'key'   => $key,
			'rows'  => $GLOBALS['jpkcom_test_rows'][ (int) $post_id ][ $selector ] ?? [],
			'index' => -1,
		];
	}

	$loop = $GLOBALS['jpkcom_test_row_loop'];

	if ( ( $loop['index'] + 1 ) < count( $loop['rows'] ) ) {
		return true;
	}

	// Real have_rows() pops the exhausted loop off ACF's stack, which is what lets
	// a second read of the same field start over instead of finding no rows.
	$GLOBALS['jpkcom_test_row_loop'] = null;

	return false;
}

function the_row( bool $format = false ): array {
	if ( null === $GLOBALS['jpkcom_test_row_loop'] ) {
		return [];
	}

	$GLOBALS['jpkcom_test_row_loop']['index']++;

	return current_test_row();
}

/**
 * Return the row the loop currently points at.
 *
 * @return array The current row, or [] when no loop is active.
 */
function current_test_row(): array {
	$loop = $GLOBALS['jpkcom_test_row_loop'];

	if ( null === $loop || $loop['index'] < 0 ) {
		return [];
	}

	return $loop['rows'][ $loop['index'] ] ?? [];
}

function get_row_layout(): string|false {
	return current_test_row()['acf_fc_layout'] ?? false;
}

function get_sub_field( string $selector, bool $format_value = true ): mixed {
	return current_test_row()[ $selector ] ?? null;
}

function reset_rows(): bool {
	$GLOBALS['jpkcom_test_row_loop'] = null;

	return true;
}
