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

summary();
