<?php
/**
 * Shape guards for the Abilities API registration arrays.
 *
 * jpkcom_acf_jobs_get_ability_definitions() is deliberately pure — no registry
 * access, no WordPress state beyond __() and one filter — which is what lets this
 * file assert the registration arrays without a WordPress install.
 *
 * Run with:
 *     php tests/test-abilities.php
 *
 * @package JPKCom_ACF_Jobs
 * @since 1.4.0
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

define( 'JPKCOM_ACFJOBS_ABILITIES', true );

// Pinned low on purpose. includes/abilities.php defines this only when it is not
// already defined, and at its real value of 500 the truncation branch is
// unreachable from a fixture — which is how it shipped undetectable in the first
// place. The post fixture holds three job_company records and one job_location,
// so a cap of 1 truncates the first and not the second.
define( 'JPKCOM_ACFJOBS_ABILITY_VOCABULARY_LIMIT', 1 );

require_once $root . '/includes/jobs-data.php';
require_once $root . '/includes/abilities.php';

echo "\nLoad\n";

chk(
	'abilities.php defines the definition builder',
	function_exists( 'jpkcom_acf_jobs_get_ability_definitions' ),
	'The require produced nothing. Without this check the run would exit 0 and CI would report green.'
);

if ( ! function_exists( 'jpkcom_acf_jobs_get_ability_definitions' ) ) {
	summary();
}

$defs = jpkcom_acf_jobs_get_ability_definitions();

echo "\nRegistration arrays\n";

chk( 'three abilities are defined', 3 === count( $defs ) );

foreach ( [ 'jpkcom-acf-jobs/list-filters', 'jpkcom-acf-jobs/query-jobs', 'jpkcom-acf-jobs/get-job' ] as $name ) {

	chk( "{$name} is defined", isset( $defs[ $name ] ) );

	if ( ! isset( $defs[ $name ] ) ) {
		continue;
	}

	$d = $defs[ $name ];

	chk(
		"{$name} matches the core name regex",
		1 === preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name ),
		'Exactly one slash, lowercase, no underscores.'
	);

	foreach ( [ 'label', 'description', 'category', 'execute_callback', 'permission_callback', 'input_schema', 'output_schema', 'meta' ] as $key ) {
		chk( "{$name} has {$key}", isset( $d[ $key ] ) && '' !== $d[ $key ] );
	}

	chk( "{$name} is in jpkcom-content", 'jpkcom-content' === ( $d['category'] ?? null ) );
	chk( "{$name} callbacks are callable names", is_callable( $d['execute_callback'] ?? null ) && is_callable( $d['permission_callback'] ?? null ) );

	$ann = $d['meta']['annotations'] ?? [];

	chk(
		"{$name} sets all three annotations as booleans",
		is_bool( $ann['readonly'] ?? null ) && is_bool( $ann['destructive'] ?? null ) && is_bool( $ann['idempotent'] ?? null ),
		'They default to null, and the REST run controller derives the HTTP verb from them: '
		. 'an ability without annotations is POST-only.'
	);
	chk( "{$name} is readonly", true === ( $ann['readonly'] ?? null ) && false === ( $ann['destructive'] ?? null ) );
	chk( "{$name} is exposed over REST and MCP", true === ( $d['meta']['show_in_rest'] ?? null ) && true === ( $d['meta']['mcp']['public'] ?? null ) );

	chk( "{$name} input schema is an object", 'object' === ( $d['input_schema']['type'] ?? null ) );
	chk( "{$name} output schema is an object", 'object' === ( $d['output_schema']['type'] ?? null ) );

	foreach ( ( $d['input_schema']['properties'] ?? [] ) as $prop => $schema ) {
		chk( "{$name} input.{$prop} is described", ! empty( $schema['description'] ) );
	}

	foreach ( ( $d['output_schema']['properties'] ?? [] ) as $prop => $schema ) {
		chk( "{$name} output.{$prop} is described", ! empty( $schema['description'] ) );
	}
}

echo "\nCalling with no arguments\n";

foreach ( [ 'jpkcom-acf-jobs/list-filters', 'jpkcom-acf-jobs/query-jobs' ] as $name ) {
	chk(
		"{$name} declares a top-level input default",
		array_key_exists( 'default', $defs[ $name ]['input_schema'] ?? [] ),
		'WP_Ability::normalize_input() substitutes the top-level default when the input is '
		. 'exactly null, and nothing else does. Without it, calling the ability with no '
		. 'arguments — the most obvious call there is — fails validate_input() before the '
		. 'callback ever runs.'
	);
	chk(
		"{$name}'s default encodes as an object, not an array",
		'{}' === json_encode( $defs[ $name ]['input_schema']['default'] ?? [] ),
		'PHP serialises an empty array as []. Core\'s REST list controller special-cases '
		. 'exactly that and rewrites it to {}; the MCP Adapter hands the schema to clients raw.'
	);
}

chk(
	'get-job requires an id',
	in_array( 'id', $defs['jpkcom-acf-jobs/get-job']['input_schema']['required'] ?? [], true ),
	'get-job has nothing sensible to do without one, so failing validation is correct there.'
);

echo "\njob_type is an enum, not a free string\n";

$types = $defs['jpkcom-acf-jobs/query-jobs']['input_schema']['properties']['job_type']['items']['enum'] ?? null;

chk(
	'query-jobs constrains job_type to the registered values',
	is_array( $types ) && 8 === count( $types ) && in_array( 'FULL_TIME', $types, true ) && ! in_array( 'Vollzeit', $types, true ),
	'Nothing validates the value today, so a label or a typo becomes an unindexable meta '
	. 'scan that answers "no such jobs" instead of "your filter was wrong". The labels are '
	. 'locale-dependent and are never valid input.'
);

echo "\nNo lang input, one language output\n";

foreach ( [ 'jpkcom-acf-jobs/list-filters', 'jpkcom-acf-jobs/query-jobs', 'jpkcom-acf-jobs/get-job' ] as $name ) {

	chk(
		"{$name} declares no lang input",
		! array_key_exists( 'lang', $defs[ $name ]['input_schema']['properties'] ?? [] ),
		'Nothing in this release can switch WPML\'s language context, so a declared lang '
		. 'parameter is a false statement in the schema: a client sending lang=fr would '
		. 'receive German and have no way to notice.'
	);

	$language = $defs[ $name ]['output_schema']['properties']['language'] ?? null;

	chk(
		"{$name} returns a language string",
		is_array( $language ) && 'string' === ( $language['type'] ?? null ),
		'All three abilities echo the language the site actually resolved. Without it a '
		. 'caller cannot tell which language the answer is in.'
	);

	chk(
		"{$name}'s language description states the WPML semantics",
		is_array( $language )
		&& str_contains( (string) ( $language['description'] ?? '' ), 'absent' )
		&& str_contains( (string) ( $language['description'] ?? '' ), 'not translated' ),
		'job is translated without display-as-translated, so an untranslated job is absent '
		. 'rather than substituted, and address and attribute values are not translated at '
		. 'all. A caller that does not know reads an incomplete list as a complete one.'
	);

}

chk( 'the language resolver exists', function_exists( 'jpkcom_acf_jobs_ability_language' ) );

if ( function_exists( 'jpkcom_acf_jobs_ability_language' ) ) {
	chk(
		'without WPML the language comes from determine_locale()',
		'en_US' === jpkcom_acf_jobs_ability_language(),
		'The harness stubs determine_locale() to en_US and registers no wpml_current_language '
		. 'filter. Anything else means the resolver reached for WPML state that is not there — '
		. 'and referencing an undefined constant is a fatal on PHP 8.'
	);
}

echo "\nFilter lists come from the full vocabulary, not from the counted pass\n";

chk( 'the vocabulary reader exists', function_exists( 'jpkcom_acf_jobs_ability_related_vocabulary' ) );

if ( function_exists( 'jpkcom_acf_jobs_ability_related_vocabulary' ) ) {

	$GLOBALS['jpkcom_test_queries'] = [];

	$vocabulary = jpkcom_acf_jobs_ability_related_vocabulary( 'job_company' );
	$asked      = $GLOBALS['jpkcom_test_queries'][0] ?? [];
	$records    = $vocabulary['records'] ?? null;

	chk( 'it queries the requested post type', 'job_company' === ( $asked['post_type'] ?? null ) );
	chk(
		'it asks for published, unprotected records',
		'publish' === ( $asked['post_status'] ?? null ) && false === ( $asked['has_password'] ?? null )
	);
	chk(
		'it loads no post content',
		'ids' === ( $asked['fields'] ?? null ),
		'A vocabulary covering every company on the site must not drag every company row '
		. 'through the query result.'
	);
	chk(
		'it is bounded and computes no total',
		is_int( $asked['posts_per_page'] ?? null ) && 0 < ( $asked['posts_per_page'] ?? 0 )
		&& true === ( $asked['no_found_rows'] ?? null ),
		'An unbounded vocabulary query is exactly the -1 the shared builder refuses to '
		. 'default to, and nothing here needs a total.'
	);
	chk(
		'only the published, unprotected company survives the projection',
		[ [ 'id' => 182, 'title' => 'Testfirma GmbH' ] ] === $records,
		'The harness WP_Query hands back every job_company regardless of status, so this can '
		. 'only pass because the projection drops the draft (500) and the password-protected '
		. 'one (501) a second time.'
	);
	chk(
		'it fetches one more record than the cap, so truncation is detectable at all',
		( JPKCOM_ACFJOBS_ABILITY_VOCABULARY_LIMIT + 1 ) === ( $asked['posts_per_page'] ?? null ),
		'Asking for exactly the cap with no_found_rows makes truncation invisible to the code '
		. 'itself: a site with 600 companies would return 500 and report nothing at all.'
	);
	chk(
		'a truncated vocabulary says so',
		true === ( $vocabulary['truncated'] ?? null ),
		'Three job_company fixtures against a cap of 1. Silent truncation is exactly what this '
		. 'feature refuses to do everywhere else.'
	);

	$untruncated = jpkcom_acf_jobs_ability_related_vocabulary( 'job_location' );

	chk(
		'an untruncated vocabulary says so too',
		false === ( $untruncated['truncated'] ?? null ),
		'One job_location fixture against a cap of 1: exactly at the cap is not over it.'
	);
}

if ( function_exists( 'jpkcom_acf_jobs_ability_list_filters' ) ) {

	$GLOBALS['jpkcom_test_queries'] = [];

	$listed  = jpkcom_acf_jobs_ability_list_filters( null );
	$queries = $GLOBALS['jpkcom_test_queries'];

	chk( 'list-filters returns an array, not an error', is_array( $listed ) );

	chk(
		'list-filters reports a truncated vocabulary',
		true === ( $listed['vocabulary_truncated'] ?? null ),
		'The company vocabulary is over the pinned cap, and the caller has to be told that the '
		. 'filter menu it just received is incomplete.'
	);

	$job_queries = array_values(
		array_filter( $queries, static fn( array $q ): bool => 'job' === ( $q['post_type'] ?? null ) )
	);

	chk(
		'list-filters runs the visibility query and the three count queries',
		4 === count( $job_queries ),
		'One bounded pass over the listed jobs, plus published_total, hidden_missing_featured '
		. 'and hidden_expired.'
	);

	foreach ( $job_queries as $i => $q ) {
		chk(
			"job query {$i} pins post_status to publish",
			'publish' === ( $q['post_status'] ?? null ),
			'Pinned explicitly and unconditionally on every one of them. Core widens an '
			. 'unspecified post_status to include private posts for a caller holding '
			. 'read_private_posts, so two callers would otherwise see different "public" job '
			. 'lists and the answer would stop being cacheable.'
		);
		chk(
			"job query {$i} excludes password-protected jobs",
			false === ( $q['has_password'] ?? null ),
			'get_the_title() prepends the protected-title format while ACF has no notion of a '
			. 'post password and hands out the salary and address in full.'
		);
	}

	$visibility_query = $job_queries[0] ?? [];

	chk(
		'the visibility query asks for ids only and is bounded one past the count limit',
		'ids' === ( $visibility_query['fields'] ?? null )
		&& ( JPKCOM_ACFJOBS_ABILITY_COUNT_LIMIT + 1 ) === ( $visibility_query['posts_per_page'] ?? null )
	);
	chk(
		'the visibility query carries the shared visibility rule, not a local copy',
		'job_featured' === ( $visibility_query['meta_key'] ?? null )
		&& is_array( $visibility_query['meta_query'] ?? null ),
		'It has to be the same rule the archive and the shortcode run, or list-filters '
		. 'describes a site nobody sees.'
	);

	foreach ( array_slice( $job_queries, 1 ) as $i => $q ) {
		chk(
			'count query ' . ( $i + 1 ) . ' fetches no rows it does not need',
			'ids' === ( $q['fields'] ?? null ) && 1 === ( $q['posts_per_page'] ?? null )
			&& false === ( $q['no_found_rows'] ?? null )
		);
	}

	$vocabulary_queries = array_values(
		array_filter(
			$queries,
			static fn( array $q ): bool => in_array( $q['post_type'] ?? '', [ 'job_company', 'job_location' ], true )
		)
	);

	chk( 'list-filters runs both vocabulary queries', 2 === count( $vocabulary_queries ) );

	foreach ( $vocabulary_queries as $i => $q ) {
		chk(
			"vocabulary query {$i} pins post_status to publish and excludes passwords",
			'publish' === ( $q['post_status'] ?? null ) && false === ( $q['has_password'] ?? null )
		);
	}

	chk(
		'list-filters echoes the resolved language',
		is_array( $listed ) && 'en_US' === ( $listed['language'] ?? null )
	);

	chk(
		'companies come from the vocabulary, not from the counted jobs',
		is_array( $listed ) && [ [ 'id' => 182, 'name' => 'Testfirma GmbH', 'count' => 0 ] ] === ( $listed['companies'] ?? null ),
		'Every get_field() in the harness returns null, so the pass over the visible jobs '
		. 'finds no company at all. A company here can only have come from the full '
		. 'vocabulary — and its count is 0, which is the honest answer rather than a missing key.'
	);

	chk(
		'locations come from the vocabulary too',
		is_array( $listed ) && [ [ 'id' => 183, 'name' => 'Teststadt', 'place' => '', 'count' => 0 ] ] === ( $listed['locations'] ?? null )
	);

	chk(
		'attributes are the full taxonomy with zero counts',
		is_array( $listed ) && 2 === count( $listed['attributes'] ?? [] )
		&& 0 === ( $listed['attributes'][0]['count'] ?? null )
		&& 'firmenwagen' === ( $listed['attributes'][0]['slug'] ?? null ),
		'hide_empty is false, so a term no job carries is still offered — with a count of 0.'
	);
}

echo "\nFilter normalisation\n";

chk(
	'a filter that normalises to nothing is an error, not a dropped clause',
	is_object( jpkcom_acf_jobs_ability_normalise_filter( [ 'acme' ], 'company', 20 ) ),
	'The shortcode does array_filter( array_map( "absint", … ) ) and then skips the clause '
	. 'when the result is empty, so company=["acme"] returns EVERY job. That is the class '
	. 'that returned 19 of 19 posts as a filtered answer in jpkcom-post-filter.'
);
chk( 'a well-formed id survives', jpkcom_acf_jobs_ability_normalise_filter( [ '182' ], 'company', 20 ) === [ 182 ] );
chk( 'a zero is rejected', is_object( jpkcom_acf_jobs_ability_normalise_filter( [ 0 ], 'company', 20 ) ) );
chk( 'a negative id is rejected', is_object( jpkcom_acf_jobs_ability_normalise_filter( [ -5 ], 'company', 20 ) ) );
chk( 'an empty request is not a filter at all', jpkcom_acf_jobs_ability_normalise_filter( [], 'company', 20 ) === [] );
chk(
	'exceeding the per-axis cap is an error, not a truncation',
	is_object( jpkcom_acf_jobs_ability_normalise_filter( range( 1, 21 ), 'company', 20 ) ),
	'Each extra value is one more unindexable LIKE scan.'
);

chk( 'an absent axis is not a filter either', jpkcom_acf_jobs_ability_normalise_filter( null, 'company', 20 ) === [] );
chk(
	'a bare scalar is refused rather than wrapped',
	is_object( jpkcom_acf_jobs_ability_normalise_filter( '182', 'company', 20 ) ),
	'Wrapping it would be a guess. A CSV string is the shortcode\'s input shape, not this one\'s.'
);
chk( 'exactly the cap is not over it', jpkcom_acf_jobs_ability_normalise_filter( range( 1, 20 ), 'company', 20 ) === range( 1, 20 ) );
chk(
	'a slug axis keeps its strings',
	jpkcom_acf_jobs_ability_normalise_filter( [ ' firmenwagen ' ], 'attribute', 20 ) === [ 'firmenwagen' ]
);
chk( 'a blank slug is rejected', is_object( jpkcom_acf_jobs_ability_normalise_filter( [ '   ' ], 'attribute', 20 ) ) );
chk(
	'an object in a filter never reaches a string cast',
	is_object( jpkcom_acf_jobs_ability_normalise_filter( [ new stdClass() ], 'attribute', 20 ) ),
	'A cast of an object without __toString throws, and a Throwable out of an ability '
	. 'callback is an uncaught fatal on the 6.9 floor.'
);

$normalise_error = jpkcom_acf_jobs_ability_normalise_filter( [ 'acme' ], 'company', 20 );

chk(
	'the normalisation error carries a 400, not a bare code',
	$normalise_error instanceof WP_Error && 400 === ( $normalise_error->get_error_data()['status'] ?? null ),
	'The REST run controller returns the WP_Error verbatim and rest_ensure_response() '
	. 'defaults to 500 without data[status]. A 5xx tells an agent "transient fault, retry '
	. 'unchanged" — the exact opposite of what this message is for.'
);
chk(
	'the message names the axis and the valid form',
	$normalise_error instanceof WP_Error
	&& str_contains( $normalise_error->get_error_message(), 'company' )
	&& str_contains( $normalise_error->get_error_message(), 'acme' ),
	'A caller that is not told which value was wrong cannot correct itself in one turn.'
);

echo "\nPage size\n";

chk( 'per_page is clamped up', 1 === jpkcom_acf_jobs_ability_clamp_per_page( 0 ) );
chk( 'per_page is clamped down', 50 === jpkcom_acf_jobs_ability_clamp_per_page( 5000 ) );
chk(
	'per_page never becomes -1',
	1 === jpkcom_acf_jobs_ability_clamp_per_page( -1 ),
	'The shortcode default IS -1, and the builder refuses it, but the clamp is what keeps '
	. 'an API caller from reaching an unbounded query.'
);
chk( 'a non-numeric per_page falls back to the default', 10 === jpkcom_acf_jobs_ability_clamp_per_page( 'lots' ) );
chk( 'a numeric string is honoured', 25 === jpkcom_acf_jobs_ability_clamp_per_page( '25' ) );

echo "\nquery-jobs\n";

/**
 * Return every recorded query against a post type, newest run last.
 *
 * @param string $post_type Post type to filter the recorded queries by.
 * @return array List of recorded WP_Query argument arrays.
 */
function recorded_queries( string $post_type ): array {
	return array_values(
		array_filter(
			$GLOBALS['jpkcom_test_queries'],
			static fn( array $q ): bool => $post_type === ( $q['post_type'] ?? null )
		)
	);
}

/**
 * Return the listing query, which is always the first query query-jobs runs.
 *
 * Deliberately NOT recorded_queries( 'job' )[0]: the two visibility counts pin
 * their own post type, so a listing query that a filter widened to another post
 * type would drop out of that list and one of the counts would be asserted in its
 * place. That is exactly the widening these assertions exist to catch.
 *
 * @return array The recorded WP_Query arguments of the listing query.
 */
function listing_query(): array {
	return $GLOBALS['jpkcom_test_queries'][0] ?? [];
}

/**
 * Find one meta_query clause by its key, at any nesting depth.
 *
 * @param mixed  $meta_query Recorded meta_query.
 * @param string $key        Meta key to look for.
 * @return array|null The clause, or null when the query carries none.
 */
function find_meta_clause( mixed $meta_query, string $key ): ?array {
	if ( ! is_array( $meta_query ) ) {
		return null;
	}

	foreach ( $meta_query as $index => $clause ) {
		if ( 'relation' === $index || ! is_array( $clause ) ) {
			continue;
		}

		if ( $key === ( $clause['key'] ?? null ) ) {
			return $clause;
		}

		$nested = find_meta_clause( $clause, $key );

		if ( null !== $nested ) {
			return $nested;
		}
	}

	return null;
}

$GLOBALS['jpkcom_test_queries'] = [];

$result  = jpkcom_acf_jobs_ability_query_jobs( null );
$listing = listing_query();

chk(
	'query-jobs answers with a result rather than the 501 placeholder',
	is_array( $result ),
	'The registration was complete from Task 5 on; the callback was not.'
);
chk(
	'query-jobs reports the resolved language',
	is_array( $result ) && 'en_US' === ( $result['language'] ?? null ),
	'The output schema declares it and list-filters already returns it. Without it a caller '
	. 'cannot tell which language the job titles are in.'
);

chk(
	'a page-1 call runs one listing query and the two visibility counts',
	3 === count( recorded_queries( 'job' ) ),
	'Nothing here may cost a query per returned job, and the visibility counts are the two '
	. 'the output schema declares.'
);

chk(
	'the listing query pins post_status to publish',
	'publish' === ( $listing['post_status'] ?? null ),
	'Pinned explicitly and unconditionally. Core widens an unspecified post_status to include '
	. 'private posts for a caller holding read_private_posts, so two callers would otherwise '
	. 'see different "public" job lists.'
);
chk( 'the listing query excludes password-protected jobs', false === ( $listing['has_password'] ?? null ) );
chk(
	'the listing query carries the shared visibility rule, not a local copy',
	'job_featured' === ( $listing['meta_key'] ?? null ) && is_array( $listing['meta_query'] ?? null ),
	'It has to be the same rule the archive and the shortcode run, or query-jobs answers for '
	. 'a site nobody sees.'
);
chk(
	'the listing query is bounded by the clamped page size',
	10 === ( $listing['posts_per_page'] ?? null ) && 1 === ( $listing['paged'] ?? null ),
	'Per-property defaults are resolved in the callback: core applies only the top-level one.'
);

chk(
	'the ordering carries a deterministic tiebreaker',
	'DESC' === ( $listing['orderby']['ID'] ?? null ),
	'ORDER BY meta_value_num DESC, date DESC leaves ties unresolved and MySQL permutes tied '
	. 'rows per execution — measured in Task 3, four fetches of unmodified code produced three '
	. 'different orderings because the seeded jobs share a post_date. Harmless for the site\'s '
	. 'own unpaginated listing; here it makes a job appear on two pages or on none.'
);
chk(
	'the tiebreaker is not in the shared builder',
	! isset( jpkcom_acf_jobs_build_job_query_args( [] )['orderby']['ID'] ),
	'The builder is shared with the shortcode and the archive, whose front-end ordering this '
	. 'feature promised not to change.'
);

chk(
	'the reader gate drops what the query handed back',
	is_array( $result ) && [ 184 ] === array_column( $result['jobs'] ?? [], 'id' ),
	'The harness WP_Query returns every job regardless of status, so this can only pass '
	. 'because jpkcom_acf_jobs_get_job_data() drops the draft (5) and the password-protected '
	. 'one (701) a second time.'
);
chk(
	'the compact record carries is_closed',
	is_array( $result ) && array_key_exists( 'is_closed', $result['jobs'][0] ?? [] ),
	'job_closed is read by no query and rendered by no template, so a record without it lets '
	. 'a client tell a user to apply for a filled position.'
);
chk(
	'the compact record carries no detail block',
	is_array( $result ) && ! array_key_exists( 'detail', $result['jobs'][0] ?? [] ),
	'Address, salary and the application data are get-job\'s job, and job_layout_content is '
	. 'unbounded.'
);

chk(
	'unknown encodes as an object, not an array',
	is_array( $result ) && '{}' === json_encode( $result['unknown'] ?? [] ),
	'PHP serialises an empty array as []. The MCP Adapter hands the schema to clients raw, so '
	. 'a caller reading unknown as a map gets a type mismatch on every unfiltered call.'
);
chk(
	'filters echoes the defaults that were actually applied',
	is_array( $result ) && true === ( $result['filters']['include_closed'] ?? null )
	&& 'DESC' === ( $result['filters']['order'] ?? null )
);
chk(
	'the archive url is published when the archive is enabled',
	is_array( $result ) && 'https://example.test/jobs/' === ( $result['archive_url'] ?? null )
);
chk(
	'the visibility block reports both shortfall causes',
	is_array( $result ) && is_int( $result['visibility']['hidden_missing_featured'] ?? null )
	&& is_int( $result['visibility']['hidden_expired'] ?? null )
);

echo "\nWhat the reader emits has to satisfy what the schema declares\n";

/**
 * Report every value in a record whose type the schema does not permit.
 *
 * A stand-in for rest_validate_value_from_schema(), reduced to the type check.
 * Core runs the real thing over every ability result and answers
 * ability_invalid_output instead of the result, so a property the reader can
 * leave null while the schema declares a scalar type is not a documentation
 * defect — it is a total outage of that ability.
 *
 * @param mixed  $value  Value to check.
 * @param mixed  $schema Declared schema for it.
 * @param string $path   Path prefix used in the message.
 * @return array List of human-readable violations.
 */
function schema_type_violations( mixed $value, mixed $schema, string $path = '' ): array {
	if ( ! is_array( $schema ) || ! isset( $schema['type'] ) ) {
		return [];
	}

	$allowed = (array) $schema['type'];

	$actual = match ( true ) {
		null === $value     => 'null',
		is_bool( $value )   => 'boolean',
		is_int( $value )    => 'integer',
		is_float( $value )  => 'number',
		is_string( $value ) => 'string',
		is_array( $value )  => array_is_list( $value ) ? 'array' : 'object',
		is_object( $value ) => 'object',
		default             => 'unknown',
	};

	// An integer satisfies number, and an empty PHP array is both a JSON array
	// and — once jpkcom_acf_jobs_ability_json_object() has run — a JSON object.
	$ok = in_array( $actual, $allowed, true )
		|| ( 'integer' === $actual && in_array( 'number', $allowed, true ) )
		|| ( [] === $value && in_array( 'object', $allowed, true ) );

	if ( ! $ok ) {
		return [ ( '' === $path ? 'root' : $path ) . ' is ' . $actual . ', schema allows [' . implode( '|', $allowed ) . ']' ];
	}

	$out = [];

	if ( is_array( $value ) && in_array( 'object', $allowed, true ) ) {
		foreach ( ( $schema['properties'] ?? [] ) as $prop => $sub ) {
			if ( array_key_exists( $prop, $value ) ) {
				$out = array_merge( $out, schema_type_violations( $value[ $prop ], $sub, $path . '.' . $prop ) );
			}
		}
	}

	if ( is_array( $value ) && in_array( 'array', $allowed, true ) && isset( $schema['items'] ) ) {
		foreach ( $value as $index => $item ) {
			$out = array_merge( $out, schema_type_violations( $item, $schema['items'], $path . '[' . $index . ']' ) );
		}
	}

	return $out;
}

$response_violations = schema_type_violations( $result, $defs['jpkcom-acf-jobs/query-jobs']['output_schema'], 'query-jobs' );
$compact_violations  = schema_type_violations(
	jpkcom_acf_jobs_get_job_data( 184, false ),
	$defs['jpkcom-acf-jobs/query-jobs']['output_schema']['properties']['jobs']['items'],
	'job'
);
$full_violations = schema_type_violations(
	jpkcom_acf_jobs_get_job_data( 184, true ),
	$defs['jpkcom-acf-jobs/get-job']['output_schema'],
	'job'
);

chk(
	'the whole query-jobs response satisfies its own output schema',
	[] === $response_violations,
	'Core validates every ability result against output_schema and returns ability_invalid_output '
	. 'in its place. ' . implode( '; ', $response_violations )
);
chk(
	'the compact record satisfies the declared output schema',
	[] === $compact_violations,
	'Measured on WP 7.0.2 with ACF Pro 6.8.6: work_type is null for all six seeded jobs, and '
	. 'with a bare "object" declared for it EVERY query-jobs call failed with '
	. 'ability_invalid_output rather than returning a job. ' . implode( '; ', $compact_violations )
);
chk(
	'the full record satisfies the declared output schema',
	[] === $full_violations,
	'The same mechanism, in the properties get-job adds. ' . implode( '; ', $full_violations )
);

echo "\nA requested filter that normalises to nothing never reaches WP_Query\n";

$GLOBALS['jpkcom_test_queries'] = [];

$bad = jpkcom_acf_jobs_ability_query_jobs( [ 'company' => [ 'acme' ] ] );

chk(
	'company=["acme"] is an error, not a count',
	$bad instanceof WP_Error && 'jpkcom_acf_jobs_invalid_filter' === $bad->get_error_code(),
	'absint("acme") is 0, the shortcode filters that out and skips the clause, and the '
	. 'response then contains every job on the site.'
);
chk(
	'and it carries a 400',
	$bad instanceof WP_Error && 400 === ( $bad->get_error_data()['status'] ?? null )
);
chk(
	'and no query ran at all',
	[] === $GLOBALS['jpkcom_test_queries'],
	'The refusal has to come before the query, not after it.'
);

$blank_search = jpkcom_acf_jobs_ability_query_jobs( [ 'search' => '<b></b>' ] );

chk(
	'a search that sanitises away is an error, not an unfiltered list',
	$blank_search instanceof WP_Error && 400 === ( $blank_search->get_error_data()['status'] ?? null ),
	'includes/jobs-data.php tests ! empty( $args["search"] ) BEFORE sanitize_text_field, so '
	. '"<b></b>" passes that test, sanitises to "" and sets s => "", which WP_Query ignores — '
	. 'every job comes back as a search result.'
);

$bad_order = jpkcom_acf_jobs_ability_query_jobs( [ 'order' => new stdClass() ] );

chk(
	'an order that is not a string is refused before the builder casts it',
	$bad_order instanceof WP_Error,
	'includes/jobs-data.php:52 casts $args["order"] to string, which throws for an object '
	. 'without __toString. A Throwable out of an ability callback is an uncaught fatal on the '
	. '6.9 floor.'
);
chk(
	'an order outside the enum is refused too',
	jpkcom_acf_jobs_ability_query_jobs( [ 'order' => 'sideways' ] ) instanceof WP_Error,
	'The builder maps anything that is not ASC to DESC, so an unrecognised direction would be '
	. 'answered silently with the opposite of what was asked for.'
);

echo "\nA well-formed value that matches nothing is not an error\n";

$GLOBALS['jpkcom_test_queries'] = [];

$missing = jpkcom_acf_jobs_ability_query_jobs( [ 'company' => [ 999 ] ] );

chk( 'company=[999] returns a result', is_array( $missing ) );
chk(
	'the value lands in unknown',
	is_array( $missing ) && [ 999 ] === ( ( (array) ( $missing['unknown'] ?? [] ) )['company'] ?? null ),
	'That is what tells a typo apart from a genuinely empty result set.'
);
chk(
	'and the empty result is honest',
	is_array( $missing ) && 0 === ( $missing['total'] ?? null ) && [] === ( $missing['jobs'] ?? null )
);
chk(
	'an axis that resolves to nothing runs no listing query',
	2 === count( recorded_queries( 'job' ) ),
	'Passing an empty clause list to the builder would drop the clause and return every job — '
	. 'the exact bug this ability exists to avoid. Only the two visibility counts remain.'
);

$GLOBALS['jpkcom_test_queries'] = [];

$narrowed       = jpkcom_acf_jobs_ability_query_jobs( [ 'company' => [ 182 ] ] );
$narrow_listing = listing_query();
$company_clause = find_meta_clause( $narrow_listing['meta_query'] ?? null, 'job_company' );

chk(
	'a known company reaches WP_Query as a LIKE clause on the serialised id',
	is_array( $company_clause ) && '"182"' === ( $company_clause['value'] ?? null )
	&& 'LIKE' === ( $company_clause['compare'] ?? null ),
	'A suite can pass while no filter ever reaches the query. This is the assertion that '
	. 'catches it.'
);
chk(
	'and the applied filter is echoed back',
	is_array( $narrowed ) && [ 182 ] === ( $narrowed['filters']['company'] ?? null )
);

$GLOBALS['jpkcom_test_queries'] = [];

// 182 is a job_company, 183 is a job_location, 999 exists at all. Only the first
// belongs in this axis, and only a check on the post TYPE can tell 183 apart
// from it — an existence check passes 183 just as happily.
$mixed         = jpkcom_acf_jobs_ability_query_jobs( [ 'company' => [ 182, 183, 999 ] ] );
$mixed_unknown = (array) ( $mixed['unknown'] ?? [] );

chk(
	'an id of the wrong post type is not a company',
	is_array( $mixed ) && [ 183, 999 ] === ( $mixed_unknown['company'] ?? null ),
	'get_post_type( 183 ) is job_location. An existence check would accept it, build a clause '
	. 'on job_company for it and answer "no such jobs" instead of "wrong ID".'
);
chk(
	'filters echoes what was applied, not what was asked for',
	is_array( $mixed ) && [ 182 ] === ( $mixed['filters']['company'] ?? null ),
	'Echoing the request would list 999 in filters AND in unknown at the same time, which '
	. 'says the value was both applied and not found.'
);
chk(
	'a partially known axis still queries the known part',
	'"182"' === ( find_meta_clause( listing_query()['meta_query'] ?? null, 'job_company' )['value'] ?? null ),
	'Two unusable values must not take the usable one down with them.'
);

$GLOBALS['jpkcom_test_queries'] = [];

$searched = jpkcom_acf_jobs_ability_query_jobs( [ 'search' => 'Stelle' ] );

chk(
	'a search term reaches WP_Query as s',
	'Stelle' === ( listing_query()['s'] ?? null ),
	'Every other axis is asserted to reach the query; without this one the whole search path '
	. 'could be deleted and the suite would stay green.'
);
chk(
	'and it is echoed back',
	is_array( $searched ) && 'Stelle' === ( $searched['filters']['search'] ?? null )
);

$GLOBALS['jpkcom_test_queries'] = [];

$by_slug     = jpkcom_acf_jobs_ability_query_jobs( [ 'attribute' => [ 'firmenwagen' ] ] );
$tax_listing = listing_query();

chk(
	'an attribute slug is resolved to a term id and filtered as a tax_query',
	'job-attribute' === ( $tax_listing['tax_query'][0]['taxonomy'] ?? null )
	&& [ 20 ] === ( $tax_listing['tax_query'][0]['terms'] ?? null )
	&& 'term_id' === ( $tax_listing['tax_query'][0]['field'] ?? null ),
	'save_terms and load_terms are both on, so ACF discards the stored job_attribute meta on '
	. 'read. A meta LIKE would filter on a store the site never reads.'
);
chk(
	'the caller keeps speaking slugs',
	is_array( $by_slug ) && [ 'firmenwagen' ] === ( $by_slug['filters']['attribute'] ?? null )
);

$GLOBALS['jpkcom_test_queries'] = [];

jpkcom_acf_jobs_ability_query_jobs( [ 'include_closed' => false ] );

$closed_clause = find_meta_clause( listing_query()['meta_query'] ?? null, 'job_closed' );

chk(
	'include_closed=false reaches WP_Query as a job_closed clause',
	is_array( $closed_clause ),
	'The shared builder knows nothing about job_closed, so a callback that merely accepted the '
	. 'parameter would answer with the filled positions included.'
);
chk(
	'the default leaves job_closed unfiltered',
	null === find_meta_clause( $listing['meta_query'] ?? null, 'job_closed' ),
	'Default true, matching the site itself, which lists filled positions and marks them.'
);

echo "\nA page past the last one\n";

$GLOBALS['jpkcom_test_queries'] = [];

$page2 = jpkcom_acf_jobs_ability_query_jobs( [ 'page' => 2, 'per_page' => 2 ] );

chk(
	'a page that still has posts runs exactly one listing query',
	3 === count( recorded_queries( 'job' ) ),
	'Dropping the $query->posts === [] half of the guard doubles the query count of every '
	. 'paginated call.'
);
chk(
	'page 2 reports the real totals',
	is_array( $page2 ) && 3 === ( $page2['total'] ?? null ) && 2 === ( $page2['total_pages'] ?? null )
	&& 2 === ( $page2['page'] ?? null )
);

$GLOBALS['jpkcom_test_queries'] = [];

$page3 = jpkcom_acf_jobs_ability_query_jobs( [ 'page' => 3, 'per_page' => 2 ] );

chk(
	'a page past the last one still reports the real totals',
	is_array( $page3 ) && 3 === ( $page3['total'] ?? null ) && 2 === ( $page3['total_pages'] ?? null ),
	'WP_Query::set_found_posts() returns early when posts is empty, so found_posts and '
	. 'max_num_pages stay 0. A response saying total 0 beside page 3 reads as an empty corpus.'
);
chk( 'it echoes the requested page back', is_array( $page3 ) && 3 === ( $page3['page'] ?? null ) );
chk( 'and it returns no jobs', is_array( $page3 ) && [] === ( $page3['jobs'] ?? null ) );
chk(
	'the recovery costs exactly one extra query',
	4 === count( recorded_queries( 'job' ) ),
	'One re-run for page 1, on that path only.'
);

$GLOBALS['jpkcom_test_queries'] = [];

$clamped = jpkcom_acf_jobs_ability_query_jobs( [ 'per_page' => 5000, 'order' => 'asc' ] );

chk(
	'the page size that reaches WP_Query is the clamped one',
	50 === ( listing_query()['posts_per_page'] ?? null ),
	'The clamp is what keeps an API caller from reaching an unbounded query.'
);
chk( 'and the response reports the applied page size', is_array( $clamped ) && 50 === ( $clamped['per_page'] ?? null ) );
chk(
	'ASC reaches both the date component and the tiebreaker',
	'ASC' === ( listing_query()['orderby']['date'] ?? null )
	&& 'ASC' === ( listing_query()['orderby']['ID'] ?? null )
);

echo "\nWhat the argument filter is not allowed to change\n";

$GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] = static function ( array $args ): array {
	$args['post_type']   = 'page';
	$args['post_status'] = 'any';

	unset( $args['has_password'] );

	return $args;
};

$GLOBALS['jpkcom_test_queries'] = [];

jpkcom_acf_jobs_ability_query_jobs( null );

$hijacked = listing_query();

chk(
	'the argument filter cannot widen the post type, the status or the password gate',
	'job' === ( $hijacked['post_type'] ?? null ) && 'publish' === ( $hijacked['post_status'] ?? null )
	&& false === ( $hijacked['has_password'] ?? null ),
	'The results of these abilities are deliberately independent of the caller and of any '
	. 'site callback, so two callers never see different "public" job lists.'
);

$GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] = static function ( array $args ): array {
	$args['meta_query'] = [];

	return $args;
};

$GLOBALS['jpkcom_test_queries'] = [];

$clause_dropped = jpkcom_acf_jobs_ability_query_jobs( [ 'company' => [ 182 ] ] );

chk(
	'a callback that drops a requested clause is refused, not answered',
	$clause_dropped instanceof WP_Error
	&& 'jpkcom_acf_jobs_filter_not_applied' === $clause_dropped->get_error_code()
	&& 500 === ( $clause_dropped->get_error_data()['status'] ?? null ),
	'This is the same failure as company=["acme"], one layer further down: the caller asked '
	. 'for one company and the query would have returned every job. Nothing the caller sends '
	. 'can cause it and nothing it changes can avoid it, which is why this one is a 5xx.'
);
chk(
	'and no unfiltered list is run in its place',
	[] === $GLOBALS['jpkcom_test_queries'],
	'Returning the unfiltered result would present a wrong answer as a right one.'
);

// One axis per callback, each dropping only its own clause, so exactly one branch
// of the post-condition can be the thing that fires.
$dropped_axes = [
	'search'         => [ static fn ( array $a ): array => array_diff_key( $a, [ 's' => 0 ] ), [ 'search' => 'zzznope' ] ],
	'attribute'      => [ static fn ( array $a ): array => array_diff_key( $a, [ 'tax_query' => 0 ] ), [ 'attribute' => [ 'firmenwagen' ] ] ],
	'include_closed' => [ static fn ( array $a ): array => array_diff_key( $a, [ 'meta_query' => 0 ] ), [ 'include_closed' => false ] ],
];

foreach ( $dropped_axes as $axis => $case ) {

	$GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] = $case[0];
	$GLOBALS['jpkcom_test_queries']                                      = [];

	$refused = jpkcom_acf_jobs_ability_query_jobs( $case[1] );

	chk(
		"a callback that drops the {$axis} clause is refused, not answered",
		$refused instanceof WP_Error && 'jpkcom_acf_jobs_filter_not_applied' === $refused->get_error_code()
		&& 500 === ( $refused->get_error_data()['status'] ?? null ),
		"Measured live for search: with s unset, search=\"zzznope\" returned total 6 and six jobs "
		. 'while echoing filters.search back — every job on the site presented as a search result.'
	);
	chk(
		"and no unfiltered list is run in place of the {$axis} filter",
		[] === $GLOBALS['jpkcom_test_queries']
	);

}

unset( $GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] );

echo "\nThe page number is bounded at both ends\n";

foreach ( [ 0, -3, 'nonsense' ] as $under ) {

	$GLOBALS['jpkcom_test_queries'] = [];

	jpkcom_acf_jobs_ability_query_jobs( [ 'page' => $under ] );

	chk(
		'page ' . var_export( $under, true ) . ' is clamped up to 1',
		1 === ( listing_query()['paged'] ?? null ),
		'paged => 0 makes WP_Query fall back to the paged query var of the surrounding request, '
		. 'which inside an ability callback is whatever page the caller happened to be on.'
	);

}

$GLOBALS['jpkcom_test_queries'] = [];

$overflowed = jpkcom_acf_jobs_ability_query_jobs( [ 'page' => PHP_INT_MAX, 'per_page' => 10 ] );
$offset     = ( ( listing_query()['paged'] ?? 0 ) - 1 ) * ( listing_query()['posts_per_page'] ?? 1 );

chk(
	'a page number beyond the bound cannot overflow the LIMIT offset',
	is_int( $offset ),
	'WP_Query computes absint( ( $page - 1 ) * $posts_per_page ) for the LIMIT offset. Measured '
	. 'live on WP 7.0.2: page 1844674407370955161 at per_page 10 overflows that product to a '
	. 'float, the offset collapses to 0, and page ONE\'s six records come back labelled '
	. '"page 1844674407370955161, total_pages 1". A caller paginating on those numbers is handed '
	. 'the same six jobs twice.'
);
chk(
	'the page it reports is the page it queried',
	is_array( $overflowed ) && ( $overflowed['page'] ?? null ) === ( listing_query()['paged'] ?? null ),
	'A response that echoes a page it never asked the database for is the wrong answer the '
	. 'recovery guard exists to prevent.'
);
chk(
	'and the bound is the largest page that stays exact at the largest page size',
	JPKCOM_ACFJOBS_ABILITY_PAGE_MAX === ( listing_query()['paged'] ?? null )
	&& JPKCOM_ACFJOBS_ABILITY_PAGE_MAX * JPKCOM_ACFJOBS_ABILITY_PER_PAGE_MAX <= PHP_INT_MAX,
	'Derived rather than picked: per_page is clamped to at most PER_PAGE_MAX first, so bounding '
	. 'page at intdiv( PHP_INT_MAX, PER_PAGE_MAX ) keeps the product exact for every page size '
	. 'the ability accepts, and rejects no page that could address a real record.'
);

$GLOBALS['jpkcom_test_options']['jpkcom_acf_job_disable_archive'] = 1;

$withheld = jpkcom_acf_jobs_ability_query_jobs( null );

chk(
	'archive_url is withheld when the site owner disabled the archive',
	is_array( $withheld ) && '' === ( $withheld['archive_url'] ?? null ),
	'That address answers with a 307 to an esc_url_raw-sanitised, wp_redirect-dispatched '
	. 'target on any host, and publishing the link would silently reverse the site owner\'s '
	. 'explicit "do not publish a job list" setting.'
);

unset( $GLOBALS['jpkcom_test_options']['jpkcom_acf_job_disable_archive'] );

// Deliberately after the resolver assertion above: a constant cannot be undefined
// again, and this is the branch a real multilingual site takes.
define( 'ICL_LANGUAGE_CODE', 'fr' );

chk(
	'WPML\'s own language wins when WPML is present',
	function_exists( 'jpkcom_acf_jobs_ability_language' ) && 'fr' === jpkcom_acf_jobs_ability_language(),
	'ICL_LANGUAGE_CODE is what WPML defines once it has resolved a language. Without this '
	. 'branch a multilingual site would report the site default for every request.'
);

echo "\nSource guards\n";

/**
 * Assert that no line in the ability path matches a pattern.
 *
 * @param string $label   Human-readable check name.
 * @param string $pattern PCRE pattern that must not match.
 * @param string $why     Explanation printed on failure.
 */
function forbid_in_ability_path( string $label, string $pattern, string $why ): void {
	global $pass, $fail, $root;

	$hits = [];

	foreach ( [ $root . '/includes/abilities.php', $root . '/includes/jobs-data.php' ] as $path ) {
		foreach ( explode( "\n", (string) file_get_contents( $path ) ) as $no => $line ) {
			$trimmed = ltrim( $line );

			if ( str_starts_with( $trimmed, '//' ) || str_starts_with( $trimmed, '*' ) || str_starts_with( $trimmed, '/*' ) ) {
				continue;
			}

			if ( preg_match( $pattern, $line ) ) {
				$hits[] = sprintf( '%s:%d  %s', basename( $path ), $no + 1, trim( $line ) );
			}
		}
	}

	if ( empty( $hits ) ) {
		$pass++;
		echo "  PASS  {$label}\n";
		return;
	}

	$fail++;
	echo "  FAIL  {$label}\n        {$why}\n";

	foreach ( $hits as $hit ) {
		echo "        {$hit}\n";
	}
}

forbid_in_ability_path(
	'no get_fields() anywhere in the ability path',
	'/\bget_fields\s*\(/',
	'job_company and job_location carry job_company_jobs / job_location_jobs — bidirectional '
	. 'back-references to every linked job regardless of status, written without a status '
	. 'check. Reading a related record generically bypasses the entire visibility rule.'
);

forbid_in_ability_path(
	'no get_term() without its taxonomy',
	'/\bget_term\s*\(\s*[^,)]+\s*\)/',
	'get_term( $id ) resolves an ID in ANY taxonomy, so a stale job_attribute ID would emit '
	. 'a category or a private taxonomy term as a job attribute.'
);

forbid_in_ability_path(
	'no date()/gmdate() in the ability path',
	'/\b(gm)?date\s*\(/',
	'current_time() is the only permitted source of "today", and stored dates go through '
	. 'jpkcom_acf_jobs_normalise_date().'
);

forbid_in_ability_path(
	'no permission callback returns true unconditionally',
	'/return\s+true\s*;\s*\/\/\s*permission/',
	'A permission callback that always passes makes the capability filter decorative.'
);

forbid_in_ability_path(
	'long-form fields are never read in formatted mode',
	'/get_field\(\s*[^,]+,\s*[^,)]+\s*\)/',
	'get_field() defaults to $format_value = true, which pipes wysiwyg values through '
	. 'acf_the_content — do_shortcode at priority 11 and wp_embed autoembed at 8. The third '
	. 'argument must always be explicit in this path.'
);

/**
 * Assert that every long-form read passes an explicit false, not merely a third argument.
 *
 * The guard above only sees the two-argument call. It does not see the far more
 * likely regression: somebody tidying an explicit false into an explicit true,
 * which reads as harmless and re-arms the entire acf_the_content chain.
 */
function long_form_reads_pass_false(): void {
	global $pass, $fail, $root;

	// Fields whose ACF type formats through acf_the_content on read. Every
	// get_sub_field() in this path reads a flexible-content sub-field, and every
	// layout in that group carries a wysiwyg, so those need no name list.
	$long_form = [ 'job_short_description', 'job_application_description' ];

	$hits = [];
	$seen = 0;

	foreach ( [ $root . '/includes/abilities.php', $root . '/includes/jobs-data.php' ] as $path ) {
		foreach ( explode( "\n", (string) file_get_contents( $path ) ) as $no => $line ) {
			$trimmed = ltrim( $line );

			if ( str_starts_with( $trimmed, '//' ) || str_starts_with( $trimmed, '*' ) || str_starts_with( $trimmed, '/*' ) ) {
				continue;
			}

			$calls = [];

			if ( preg_match_all( '/get_sub_field\(([^)]*)\)/', $line, $m ) ) {
				foreach ( $m[1] as $args ) {
					$calls[] = [ 'get_sub_field(' . $args . ')', $args ];
				}
			}

			foreach ( $long_form as $field ) {
				$pattern = '/get_field\(\s*[\'"]' . preg_quote( $field, '/' ) . '[\'"]([^)]*)\)/';

				if ( preg_match_all( $pattern, $line, $m ) ) {
					foreach ( $m[1] as $args ) {
						$calls[] = [ 'get_field( \'' . $field . '\'' . $args . ')', $args ];
					}
				}
			}

			foreach ( $calls as $call ) {
				$seen++;

				if ( ! preg_match( '/,\s*false\s*$/', $call[1] ) ) {
					$hits[] = sprintf( '%s:%d  %s', basename( $path ), $no + 1, trim( $call[0] ) );
				}
			}
		}
	}

	if ( 0 === $seen ) {
		$hits[] = 'no long-form read found at all — the field names this guard watches have moved';
	}

	if ( empty( $hits ) ) {
		$pass++;
		echo "  PASS  every long-form read passes an explicit false\n";
		return;
	}

	$fail++;
	echo "  FAIL  every long-form read passes an explicit false\n";
	echo "        An explicit true is not safer than a missing argument, it is the same call.\n";
	echo "        acf_the_content carries do_shortcode at 11 and WP_Embed::autoembed at 8, and\n";
	echo "        with no post context autoembed fetches the remote URL and wp_insert_post()s an\n";
	echo "        oembed_cache row. A bare URL in a job description is enough: a read-only\n";
	echo "        ability would then write to the database for any logged-in subscriber.\n";

	foreach ( $hits as $hit ) {
		echo "        {$hit}\n";
	}
}

long_form_reads_pass_false();

/**
 * Assert that every permission callback actually checks a capability.
 *
 * The comment-matching guard above catches only the one spelling it was written
 * for. This reads each callback body instead, so a bare `return true;` is caught
 * whatever it is or is not annotated with.
 */
function permission_callbacks_check_a_capability(): void {
	global $pass, $fail, $root;

	$source = (string) file_get_contents( $root . '/includes/abilities.php' );
	$hits   = [];
	$found  = 0;

	preg_match_all(
		'/function\s+(jpkcom_acf_jobs_ability_permission_\w+)\s*\([^)]*\)\s*:\s*bool\s*\{/',
		$source,
		$matches,
		PREG_OFFSET_CAPTURE
	);

	foreach ( $matches[0] as $index => $match ) {
		$name  = $matches[1][ $index ][0];
		$start = $match[1] + strlen( $match[0] );
		$depth = 1;
		$end   = $start;
		$len   = strlen( $source );

		for ( $pos = $start; $pos < $len && $depth > 0; $pos++ ) {
			if ( '{' === $source[ $pos ] ) {
				$depth++;
			}

			if ( '}' === $source[ $pos ] ) {
				$depth--;
			}

			$end = $pos;
		}

		$body = substr( $source, $start, $end - $start );
		$found++;

		if ( ! str_contains( $body, 'current_user_can(' ) ) {
			$hits[] = "{$name}() never calls current_user_can()";
		}

		if ( preg_match( '/return\s+(true|false)\s*;/', $body ) ) {
			$hits[] = "{$name}() returns a literal instead of the result of a capability check";
		}
	}

	if ( 3 !== $found ) {
		$hits[] = sprintf( 'found %d permission callbacks, expected one per ability', $found );
	}

	if ( empty( $hits ) ) {
		$pass++;
		echo "  PASS  every permission callback checks a capability\n";
		return;
	}

	$fail++;
	echo "  FAIL  every permission callback checks a capability\n";
	echo "        A callback that short-circuits makes jpkcom_acf_jobs_ability_capability\n";
	echo "        decorative, and every logged-in user can then run every ability whatever the\n";
	echo "        site filtered the capability down to.\n";

	foreach ( $hits as $hit ) {
		echo "        {$hit}\n";
	}
}

permission_callbacks_check_a_capability();

summary();
