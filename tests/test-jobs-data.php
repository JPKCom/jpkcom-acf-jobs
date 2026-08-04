<?php
/**
 * Behavioural guards for includes/jobs-data.php.
 *
 * The first assertion is that the require actually produced the functions:
 * without it an ABSPATH mismatch would hit the file's own exit; and the process
 * would end at status 0, which CI reports as green having run nothing.
 *
 * Run with:
 *     php tests/test-jobs-data.php
 *
 * @package JPKCom_ACF_Jobs
 * @since 1.4.0
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

require_once $root . '/includes/jobs-data.php';

echo "\nLoad\n";

chk(
	'jobs-data.php defines the query builder',
	function_exists( 'jpkcom_acf_jobs_build_job_query_args' ),
	'The require produced nothing. Without this check the run would exit 0 and CI would be green.'
);

if ( ! function_exists( 'jpkcom_acf_jobs_build_job_query_args' ) ) {
	summary();
}

echo "\nVisibility rule\n";

$args = jpkcom_acf_jobs_build_job_query_args();

chk( 'post_type is job', ( $args['post_type'] ?? null ) === 'job' );
chk( 'meta_key is job_featured', ( $args['meta_key'] ?? null ) === 'job_featured' );
chk( 'a meta_query is always produced', isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) );

$mq = $args['meta_query'];

chk( 'meta_query relation is AND', ( $mq['relation'] ?? null ) === 'AND' );
chk( 'job_featured EXISTS clause is present', ( $mq[0]['key'] ?? null ) === 'job_featured' && ( $mq[0]['compare'] ?? null ) === 'EXISTS' );

$expiry = $mq[1] ?? [];

chk( 'expiry group relation is OR', ( $expiry['relation'] ?? null ) === 'OR' );
chk(
	'expiry group has all three cases',
	3 === count( array_filter( $expiry, 'is_array' ) ),
	'The NOT EXISTS clause is what makes WP_Meta_Query rewrite every INNER JOIN to '
	. 'LEFT JOIN. Dropping it as redundant next to the empty-string clause silently '
	. 'removes every job that has no job_expiry_date row at all.'
);
chk(
	'the NOT EXISTS clause survives',
	1 === count( array_filter( $expiry, static fn( $c ) => is_array( $c ) && ( $c['compare'] ?? '' ) === 'NOT EXISTS' ) )
);
chk(
	'today comes from current_time(), not date()',
	1 === count( array_filter( $expiry, static fn( $c ) => is_array( $c ) && ( $c['value'] ?? null ) === '2026-01-15' ) ),
	'The stub returns 2026-01-15 for current_time(). A real date here means the builder called date()/gmdate().'
);
chk(
	'the date comparison is typed DATE',
	1 === count( array_filter( $expiry, static fn( $c ) => is_array( $c ) && ( $c['type'] ?? null ) === 'DATE' ) ),
	'Without type => DATE the raw Ymd storage is compared as a string.'
);

echo "\nOmitted keys\n";

chk(
	'no posts_per_page unless asked for',
	! array_key_exists( 'posts_per_page', $args ),
	'archive.php sets the archive query through $query->set() and must not inherit a page size.'
);
chk( 'no post_status unless asked for', ! array_key_exists( 'post_status', $args ) );
chk( 'no meta_query filter clauses when no filters were given', 2 === count( array_filter( $mq, 'is_array' ) ) );

echo "\nBounded page size\n";

$paged = jpkcom_acf_jobs_build_job_query_args( [ 'posts_per_page' => 10, 'paged' => 3, 'post_status' => 'publish' ] );

chk( 'posts_per_page is passed through', 10 === ( $paged['posts_per_page'] ?? null ) );
chk( 'paged is passed through', 3 === ( $paged['paged'] ?? null ) );
chk( 'post_status is passed through', 'publish' === ( $paged['post_status'] ?? null ) );

echo "\nFilters\n";

$typed = jpkcom_acf_jobs_build_job_query_args( [ 'job_type' => [ 'FULL_TIME', 'PART_TIME' ] ] );
$clause = $typed['meta_query'][2] ?? [];

chk( 'a job_type filter adds one OR group', ( $clause['relation'] ?? null ) === 'OR' );
chk( 'each value becomes a quoted LIKE', ( $clause[0]['value'] ?? null ) === '"FULL_TIME"' && ( $clause[0]['compare'] ?? null ) === 'LIKE' );
chk( 'both values are present', 2 === count( array_filter( $clause, 'is_array' ) ) );

$attr = jpkcom_acf_jobs_build_job_query_args( [ 'attribute' => [ 20, 21 ] ] );

chk(
	'an attribute filter becomes a tax_query, not a meta LIKE',
	( $attr['tax_query'][0]['taxonomy'] ?? null ) === 'job-attribute'
	&& ( $attr['tax_query'][0]['field'] ?? null ) === 'term_id'
	&& ( $attr['tax_query'][0]['terms'] ?? null ) === [ 20, 21 ],
	'load_terms => 1 makes ACF discard the job_attribute meta and read the term '
	. 'relations, so the meta is write-only from a reader\'s point of view. A LIKE '
	. 'filter would filter on a store the site never reads.'
);
chk( 'the taxonomy slug carries a hyphen', ! isset( $attr['tax_query'][0]['taxonomy'] ) || ! str_contains( (string) $attr['tax_query'][0]['taxonomy'], '_' ) );

echo "\nPassword-protected jobs\n";

$safe = jpkcom_acf_jobs_build_job_query_args( [ 'exclude_password_protected' => true ] );

chk(
	'password-protected jobs can be excluded',
	false === ( $safe['has_password'] ?? null ),
	'get_the_title() prepends "Protected:" while ACF hands out the full salary and address.'
);

echo "\nNormalisers\n";

chk( 'normalisers exist', function_exists( 'jpkcom_acf_jobs_normalise_choices' ) && function_exists( 'jpkcom_acf_jobs_normalise_date' ) );

chk(
	'ACF choice pairs survive intact',
	jpkcom_acf_jobs_normalise_choices( [ [ 'value' => 'FULL_TIME', 'label' => 'Vollzeit' ] ] ) === [ [ 'value' => 'FULL_TIME', 'label' => 'Vollzeit' ] ]
);
chk(
	'raw meta strings are accepted too',
	jpkcom_acf_jobs_normalise_choices( [ 'FULL_TIME' ] ) === [ [ 'value' => 'FULL_TIME', 'label' => 'FULL_TIME' ] ],
	'A theme may replace acf-field_groups.php through the override system, after which '
	. 'ACF falls back to the raw meta and the value arrives as a bare string.'
);
chk( 'a scalar becomes a one-element list', jpkcom_acf_jobs_normalise_choices( 'INTERN' ) === [ [ 'value' => 'INTERN', 'label' => 'INTERN' ] ] );
chk( 'null becomes an empty list', jpkcom_acf_jobs_normalise_choices( null ) === [] );
chk( 'false becomes an empty list', jpkcom_acf_jobs_normalise_choices( false ) === [] );
chk( 'garbage becomes an empty list', jpkcom_acf_jobs_normalise_choices( [ [ 'nonsense' => 1 ] ] ) === [] );

chk( 'a single choice unwraps', jpkcom_acf_jobs_normalise_choice( [ 'value' => 'EUR', 'label' => '€' ] ) === [ 'value' => 'EUR', 'label' => '€' ] );
chk( 'a single choice from a bare string', jpkcom_acf_jobs_normalise_choice( 'MONTH' ) === [ 'value' => 'MONTH', 'label' => 'MONTH' ] );
chk( 'an empty single choice is null', jpkcom_acf_jobs_normalise_choice( '' ) === null );

chk( 'a stored Ymd date normalises', jpkcom_acf_jobs_normalise_date( '20251130' ) === '2025-11-30' );
chk( 'an already formatted date survives', jpkcom_acf_jobs_normalise_date( '2025-11-30' ) === '2025-11-30' );
chk(
	'an unparseable date is null, not a fatal',
	jpkcom_acf_jobs_normalise_date( 'n/a' ) === null,
	'schema.php:71 does date( "Y-m-d", strtotime( $x ) ). Under strict_types strtotime() '
	. 'returning false makes date() throw a TypeError, which on the WP 6.9 floor is an '
	. 'uncaught fatal inside an ability callback.'
);
chk( 'an empty date is null', jpkcom_acf_jobs_normalise_date( '' ) === null );
chk( 'a non-string date is null', jpkcom_acf_jobs_normalise_date( [ 'x' ] ) === null );
chk(
	'an overflowing Ymd month/day is rejected, not silently rolled forward',
	jpkcom_acf_jobs_normalise_date( '20251340' ) === null,
	'Without the createFromFormat()/format() round-trip check, month 13 day 40 overflows '
	. 'into a real date instead of failing to parse — measured live as 2026-02-09.'
);
chk(
	'a wildly overflowing Ymd date is rejected',
	jpkcom_acf_jobs_normalise_date( '20259999' ) === null,
	'Measured live: without the round-trip check this overflows to 2033-06-07.'
);
chk(
	'an overflowing Y-m-d day is rejected, not silently rolled forward',
	jpkcom_acf_jobs_normalise_date( '2025-02-30' ) === null,
	'Measured live: without the round-trip check this overflows to 2025-03-02.'
);
chk(
	'a NUL byte in the raw value does not throw',
	jpkcom_acf_jobs_normalise_date( "2025\x001130" ) === null,
	'DateTimeImmutable::createFromFormat() throws ValueError for an embedded NUL byte as of '
	. 'PHP 8.3, regardless of its position in the string or which of the two formats is tried. '
	. 'MySQL longtext can carry one via an importer, WP-CLI, a WPML copy or direct SQL, and an '
	. 'uncaught ValueError out of an ability callback is a fatal on the WP 6.9 floor.'
);

chk( 'br markup becomes newlines', jpkcom_acf_jobs_plain_text( 'a<br />b' ) === "a\nb" );
chk( 'tags are stripped', jpkcom_acf_jobs_plain_text( '<p>hello <b>world</b></p>' ) === 'hello world' );
chk( 'a non-string is an empty string', jpkcom_acf_jobs_plain_text( null ) === '' );
chk(
	'shortcodes are left inert, not executed',
	jpkcom_acf_jobs_plain_text( 'before [contact-form-7 id="1"] after' ) === 'before [contact-form-7 id="1"] after',
	'The normaliser must not expand anything. Execution is what get_field()\'s formatted '
	. 'mode does, and what the reader exists to avoid.'
);

echo "\nRelated posts\n";

chk( 'a WP_Post projects to id and title only', jpkcom_acf_jobs_normalise_related( [ new WP_Post( 182, 'Testfirma GmbH', 'publish' ) ] ) === [ [ 'id' => 182, 'title' => 'Testfirma GmbH' ] ] );
chk(
	'a draft related post is dropped',
	jpkcom_acf_jobs_normalise_related( [ new WP_Post( 5, 'Unannounced GmbH', 'draft' ) ] ) === [],
	'ACF resolves post_object fields through acf_get_posts() with post_status any.'
);
chk(
	'a password-protected related post is dropped',
	jpkcom_acf_jobs_normalise_related( [ new WP_Post( 701, 'Secret job', 'publish', 'job', 'hunter2' ) ] ) === [],
	'The reader\'s own gate ( jpkcom_acf_jobs_get_job_data() ) treats a password as first-class. '
	. 'A related-post projection that skips it would expose a password-protected company or '
	. 'location through a field the gate never touches.'
);
chk(
	'a bare id is re-resolved, not dereferenced',
	jpkcom_acf_jobs_normalise_related( [ 182 ] ) === [ [ 'id' => 182, 'title' => 'Testfirma GmbH' ] ],
	'When the _job_company key reference is missing — the case wpml-acf-field-keys-fix.php '
	. 'exists to repair — get_field() returns raw IDs and every renderer in this repo '
	. 'dereferences ->ID on an int.'
);
chk( 'an unresolvable id is skipped', jpkcom_acf_jobs_normalise_related( [ 99999 ] ) === [] );

// get_post()'s stub falls back to $GLOBALS['post'] for any falsy id, exactly as real
// get_post() does. absint() maps false, '', null, 'abc' and 0 all to 0, so every one of
// these is a live path to the same footgun jpkcom_acf_jobs_get_job_data()'s own
// $post_id < 1 guard exists for — and ACF genuinely returns false for an unassigned
// post_object field with allow_null => 1, which job_company and job_location both are.
// Planting a published job here — one that would resolve happily on its own — means
// each assertion below can only pass because normalise_related() rejects a non-positive
// id before ever calling get_post(). With $GLOBALS['post'] unset, as it is everywhere
// else in this file, the same bug would be invisible — which is exactly how it shipped.
$GLOBALS['post'] = new WP_Post( 900, 'Leftover Global Job', 'publish', 'job' );

chk(
	'a non-array is an empty list',
	jpkcom_acf_jobs_normalise_related( false ) === [],
	'ACF returns false, not an array, for an unassigned post_object field. absint( false ) '
	. 'is 0, and get_post( 0 ) falls back to $GLOBALS[\'post\'] in real WordPress — without a '
	. 'guard this projects the current job as its own employer or location.'
);
chk( 'an empty string does not resolve to the global post', jpkcom_acf_jobs_normalise_related( '' ) === [] );
chk( 'zero does not resolve to the global post', jpkcom_acf_jobs_normalise_related( 0 ) === [] );
chk( 'a non-numeric string does not resolve to the global post', jpkcom_acf_jobs_normalise_related( 'abc' ) === [] );
chk(
	'a null element does not resolve to the global post',
	jpkcom_acf_jobs_normalise_related( [ null ] ) === [],
	'Bare null is caught earlier by the is_array() check and never reaches this path — '
	. 'wrapped in an array it reaches the same absint()-then-get_post() call the other four do.'
);

unset( $GLOBALS['post'] );

echo "\nReader gate\n";

chk( 'a published job resolves', jpkcom_acf_jobs_get_job_data( 184 ) !== [] );
chk(
	'a draft job does not resolve',
	jpkcom_acf_jobs_get_job_data( 5 ) === [],
	'A bare int would otherwise read the meta of an unannounced opening.'
);
chk(
	'another post type does not resolve',
	jpkcom_acf_jobs_get_job_data( 700 ) === [],
	'Without a post_type gate the reader is a title oracle over every private post on the site.'
);
chk(
	'a password-protected job does not resolve',
	jpkcom_acf_jobs_get_job_data( 701 ) === [],
	'get_the_title() prepends "Protected:" while ACF returns the salary and address in full.'
);
chk( 'a nonexistent id does not resolve', jpkcom_acf_jobs_get_job_data( 999999 ) === [] );

// get_post()'s stub treats a falsy id as "empty" and falls back to
// $GLOBALS['post'], exactly as real WordPress does. Planting a published job
// there — one the reader would otherwise happily return — means this
// assertion can only pass because $post_id < 1 rejects it outright; without
// that guard, get_post( 0 ) would resolve to this fixture, not to null, and
// the reader would return its data. Cleared immediately after so no later
// assertion in this file (or, if lib.php is reused, in another test file)
// inherits it.
$GLOBALS['post'] = new WP_Post( 900, 'Leftover Global Job', 'publish', 'job' );

chk(
	'id 0 does not resolve',
	jpkcom_acf_jobs_get_job_data( 0 ) === [],
	'get_post( 0 ) is "empty" and falls back to $GLOBALS[\'post\'] in real WordPress. '
	. 'Without the $post_id < 1 guard this would resolve to whatever post the current '
	. 'request left there instead of correctly rejecting id 0.'
);

unset( $GLOBALS['post'] );

summary();
