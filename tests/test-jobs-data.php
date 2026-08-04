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
	'a bare id is re-resolved, not dereferenced',
	jpkcom_acf_jobs_normalise_related( [ 182 ] ) === [ [ 'id' => 182, 'title' => 'Testfirma GmbH' ] ],
	'When the _job_company key reference is missing — the case wpml-acf-field-keys-fix.php '
	. 'exists to repair — get_field() returns raw IDs and every renderer in this repo '
	. 'dereferences ->ID on an int.'
);
chk( 'an unresolvable id is skipped', jpkcom_acf_jobs_normalise_related( [ 99999 ] ) === [] );
chk( 'a non-array is an empty list', jpkcom_acf_jobs_normalise_related( false ) === [] );

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
chk( 'id 0 does not resolve', jpkcom_acf_jobs_get_job_data( 0 ) === [] );

summary();
