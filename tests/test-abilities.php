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
		// Cast because an ability with no input properties declares them as a
		// stdClass, so that the published schema encodes as {} rather than as the
		// [] that violates its own "type": "object".
		! array_key_exists( 'lang', (array) ( $defs[ $name ]['input_schema']['properties'] ?? [] ) ),
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
		'list-filters runs the visibility query and the two count queries',
		3 === count( $job_queries ),
		'One bounded pass over the listed jobs, plus published_total and the count of jobs '
		. 'carrying a job_featured row. It was four: hidden_missing_featured and hidden_expired '
		. 'each had their own query, and the second of them was the paraphrase of the rule that '
		. 'disagreed with it. Both are differences now, so one query fewer answers more.'
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
	'a page-1 call runs exactly one listing query',
	1 === count( recorded_queries( 'job' ) ),
	'Nothing here may cost a query per returned job. It was three: the site-wide visibility '
	. 'counts ran on every call and were reported beside a filtered total they said nothing '
	. 'about. They belong to list-filters and are gone from here.'
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
	'query-jobs reports no visibility block at all',
	is_array( $result ) && ! array_key_exists( 'visibility', $result ),
	'Site-wide counts beside a filtered total were added by the reader and turned into jobs '
	. 'that do not exist. The block lives on list-filters, which is asserted separately.'
);

echo "\nCalling an ability with no input at all\n";

// The four spellings of "no input", and they must all answer the same way.
//
// This is not a hypothetical. WP_Ability::normalize_input() substitutes the
// top-level schema default VERBATIM into the callback when the input is exactly
// null, and that default is a stdClass — it has to be, because
// WP_Ability::get_input_schema() is handed to MCP clients raw and an empty PHP
// array serialises as [] against a property declared type: object. So one value
// serves two incompatible jobs, and the callback receives an OBJECT for the most
// obvious call the ability has. The declared default itself is included below
// rather than a hand-rolled stdClass, so this asserts against the very value core
// substitutes rather than against a guess about its shape.
//
// The same defect shipped once in jpkcom-post-filter and was written up there
// afterwards; our own spec §6 documents the mechanism under "per-property
// defaults are applied in the callbacks". A guard that only ever passes an array
// cannot see it.
$no_input = [
	'null'                 => null,
	'an empty array'       => [],
	'an empty stdClass'    => new stdClass(),
	'the declared default' => $defs['jpkcom-acf-jobs/query-jobs']['input_schema']['default'],
];

$GLOBALS['jpkcom_test_queries'] = [];

$baseline = jpkcom_acf_jobs_ability_query_jobs( [] );

foreach ( $no_input as $label => $spelling ) {

	$answer = jpkcom_acf_jobs_ability_query_jobs( $spelling );

	chk(
		"query-jobs called with {$label} returns the unfiltered first page",
		is_array( $answer ) && json_encode( $answer ) === json_encode( $baseline ),
		'All four mean "no input given" to a caller and must produce one answer: the first page '
		. 'of all listed jobs. Answering 400 for the call that takes no parameters makes the '
		. 'ability unusable through the entry point every client tries first.'
	);

	chk(
		"list-filters called with {$label} still answers",
		is_array( jpkcom_acf_jobs_ability_list_filters( $spelling ) ),
		'list-filters is only unaffected because its callback ignores $input entirely. That is '
		. 'an accident of implementation, not a guarantee, so it is asserted rather than assumed.'
	);

}

// "Empty object" is not the whole question, and an assertion that only ever
// passes an EMPTY one is satisfied by special-casing stdClass and emptying it —
// which fixes the reported call and still drops every property a non-empty object
// carries. Measured: that shortcut leaves the four assertions above green.
$GLOBALS['jpkcom_test_queries'] = [];

$object_input = jpkcom_acf_jobs_ability_query_jobs( (object) [ 'per_page' => 3, 'order' => 'ASC' ] );

chk(
	'an object input is read, not merely tolerated',
	is_array( $object_input ) && 3 === ( $object_input['per_page'] ?? null )
	&& 'ASC' === ( $object_input['filters']['order'] ?? null )
	&& 3 === ( listing_query()['posts_per_page'] ?? null ),
	'The question is what the input CARRIES, not what class it is. Emptying a stdClass by name '
	. 'answers the no-input call and silently discards every property of an object that has some.'
);
chk(
	'and it agrees with the array spelling of the same request',
	json_encode( $object_input )
	=== json_encode( jpkcom_acf_jobs_ability_query_jobs( [ 'per_page' => 3, 'order' => 'ASC' ] ) )
);

chk(
	'get-job declares no top-level default, so the substitution cannot reach it',
	! array_key_exists( 'default', $defs['jpkcom-acf-jobs/get-job']['input_schema'] ),
	'get-job requires an id. Nothing is substituted for a null input and validate_input() '
	. 'refuses before the callback runs, which is the correct answer there rather than this bug.'
);

// The same question one callback over. get-job cannot be reached by the default
// substitution, but it carries the identical is_array() gate, so an object
// carrying a perfectly usable id was refused with a 400. Fixing the instance and
// leaving its twin is how this defect reached a second plugin in the first place.
chk(
	'get-job reads an object input the same as an array input',
	json_encode( jpkcom_acf_jobs_ability_get_job( (object) [ 'id' => 184 ] ) )
	=== json_encode( jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] ) ),
	'Both spellings carry the same id, so they are the same request. Only a scalar carries no '
	. 'properties at all, and that stays a 400.'
);
chk(
	'and a scalar still is not a request',
	jpkcom_acf_jobs_ability_get_job( 'nonsense' ) instanceof WP_Error
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
	0 === count( recorded_queries( 'job' ) ),
	'Passing an empty clause list to the builder would drop the clause and return every job — '
	. 'the exact bug this ability exists to avoid. Nothing else runs on this path now that the '
	. 'site-wide visibility counts have moved to list-filters.'
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
$mixed = jpkcom_acf_jobs_ability_query_jobs( [ 'company' => [ 182, 183, 999 ] ] );

// is_array() first, and not only for tidiness: this call returns a WP_Error the
// moment anything upstream refuses, and indexing one as an array is a fatal that
// takes every later assertion in this file down with it. The (array) cast is here
// because `unknown` is a stdClass when empty.
$mixed_unknown = is_array( $mixed ) ? (array) ( $mixed['unknown'] ?? [] ) : [];

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
	1 === count( recorded_queries( 'job' ) ),
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
	2 === count( recorded_queries( 'job' ) ),
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

// A door only the post-condition can see: a callback that empties the attribute
// terms while leaving the clause, its taxonomy, its field and its IN operator in
// place.
$post_condition_only = [
	'attribute terms emptied by a callback' => [
		static function ( array $a ): array {
			if ( isset( $a['tax_query'][0]['terms'] ) ) {
				$a['tax_query'][0]['terms'] = [];
			}

			return $a;
		},
		[ 'attribute' => [ 'firmenwagen' ] ],
	],
];

foreach ( $post_condition_only as $label => $case ) {

	$GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] = $case[0];
	$GLOBALS['jpkcom_test_queries']                                      = [];

	$caught = jpkcom_acf_jobs_ability_query_jobs( $case[1] );

	chk(
		"the post-condition catches {$label}",
		$caught instanceof WP_Error && 'jpkcom_acf_jobs_filter_not_applied' === $caught->get_error_code(),
		'The input-side check cannot see this one: the term was fine when the caller sent it. '
		. 'An emptied terms list is answered by core with 1=0 rather than with every job, so the '
		. 'result stays honest — but naming the attribute as applied when it never ran is not.'
	);
	chk(
		"and no query runs for {$label}",
		[] === $GLOBALS['jpkcom_test_queries']
	);

}

/**
 * Rewrite every first-order clause on a meta key, wherever it is nested.
 *
 * @param array  $mq      Meta query.
 * @param string $key     Meta key to rewrite.
 * @param array  $changes Keys to merge into the matching clauses.
 * @return array The rewritten meta query.
 */
function rewrite_meta_clause( array $mq, string $key, array $changes ): array {
	foreach ( $mq as $index => $clause ) {
		if ( 'relation' === $index || ! is_array( $clause ) ) {
			continue;
		}

		if ( $key === ( $clause['key'] ?? null ) ) {
			$mq[ $index ] = array_merge( $clause, $changes );
			continue;
		}

		$mq[ $index ] = rewrite_meta_clause( $clause, $key, $changes );
	}

	return $mq;
}

/**
 * Set the relation of the group that directly contains a clause on a meta key.
 *
 * @param array  $mq       Meta query.
 * @param string $key      Meta key whose enclosing group is meant.
 * @param string $relation Relation to set.
 * @return array The rewritten meta query.
 */
function set_group_relation( array $mq, string $key, string $relation ): array {
	foreach ( $mq as $index => $clause ) {
		if ( 'relation' === $index || ! is_array( $clause ) ) {
			continue;
		}

		if ( $key === ( $clause['key'] ?? null ) ) {
			$mq['relation'] = $relation;

			return $mq;
		}

		$rewritten = set_group_relation( $clause, $key, $relation );

		if ( $rewritten !== $clause ) {
			$mq[ $index ] = $rewritten;

			return $mq;
		}
	}

	return $mq;
}

// Presence was the wrong question. Every callback below leaves each clause in
// place and intact, and changes only what the clauses MEAN together — the
// operator rather than the operand. Live baseline on /home/jpk/ddev/posts:
// job_type=["FULL_TIME"] is 2 of 6 jobs; with the top-level relation flipped to
// OR it is 6 of 6, and filters.job_type still says ["FULL_TIME"].
$meaning_hijacks = [
	'the top-level meta relation flipped to OR' => [
		static function ( array $a ): array {
			$a['meta_query']['relation'] = 'OR';

			return $a;
		},
		[ 'job_type' => [ 'FULL_TIME' ] ],
	],
	'an axis group flipped from OR to AND' => [
		static function ( array $a ): array {
			$a['meta_query'] = set_group_relation( $a['meta_query'], 'job_company', 'AND' );

			return $a;
		},
		[ 'company' => [ 182 ] ],
	],
	'an axis LIKE turned into NOT LIKE' => [
		static function ( array $a ): array {
			$a['meta_query'] = rewrite_meta_clause( $a['meta_query'], 'job_company', [ 'compare' => 'NOT LIKE' ] );

			return $a;
		},
		[ 'company' => [ 182 ] ],
	],
	'the visibility EXISTS turned into NOT EXISTS' => [
		static function ( array $a ): array {
			$a['meta_query'] = rewrite_meta_clause( $a['meta_query'], 'job_featured', [ 'compare' => 'NOT EXISTS' ] );

			return $a;
		},
		[],
	],
	'the expiry group flipped from OR to AND' => [
		static function ( array $a ): array {
			$a['meta_query'] = set_group_relation( $a['meta_query'], 'job_expiry_date', 'AND' );

			return $a;
		},
		[],
	],
	'the expiry DATE comparison turned into CHAR' => [
		static function ( array $a ): array {
			$a['meta_query'] = rewrite_meta_clause( $a['meta_query'], 'job_expiry_date', [ 'type' => 'CHAR' ] );

			return $a;
		},
		[],
	],
	'the job_closed group flipped from OR to AND' => [
		static function ( array $a ): array {
			$a['meta_query'] = set_group_relation( $a['meta_query'], 'job_closed', 'AND' );

			return $a;
		},
		[ 'include_closed' => false ],
	],
	'the attribute operator turned into NOT IN' => [
		static function ( array $a ): array {
			$a['tax_query'][0]['operator'] = 'NOT IN';

			return $a;
		},
		[ 'attribute' => [ 'firmenwagen' ] ],
	],
	'the attribute field switched to slug' => [
		static function ( array $a ): array {
			$a['tax_query'][0]['field'] = 'slug';

			return $a;
		},
		[ 'attribute' => [ 'firmenwagen' ] ],
	],
	'a second taxonomy clause combined with OR' => [
		static function ( array $a ): array {
			$a['tax_query']['relation'] = 'OR';
			$a['tax_query'][]           = [
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => [ 1 ],
				'operator' => 'NOT IN',
			];

			return $a;
		},
		[ 'attribute' => [ 'firmenwagen' ] ],
	],
];

foreach ( $meaning_hijacks as $label => $case ) {

	$GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] = $case[0];
	$GLOBALS['jpkcom_test_queries']                                      = [];

	$rebuked = jpkcom_acf_jobs_ability_query_jobs( $case[1] );

	chk(
		"the executed query still means what was asked: {$label}",
		$rebuked instanceof WP_Error
		&& 'jpkcom_acf_jobs_filter_not_applied' === $rebuked->get_error_code()
		&& 500 === ( $rebuked->get_error_data()['status'] ?? null ),
		'A relation is not a value that can go missing — it is the operator that decides what '
		. 'the values mean together, and an AND group turned OR leaves every clause present and '
		. 'intact while inverting the answer. Checking presence cannot see it. A 500 rather than '
		. 'a 400 because this is a site-side misconfiguration: nothing the caller sent caused it '
		. 'and nothing it can send avoids it.'
	);
	chk(
		"and no query runs for {$label}",
		[] === $GLOBALS['jpkcom_test_queries']
	);

}

unset( $GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] );

echo "\nThe clauses the ability built are the clauses that run\n";

/**
 * Rewrite every clause on a meta key, wherever it is nested, through a callback.
 *
 * @param array    $mq  Meta query.
 * @param string   $key Meta key whose clauses are meant.
 * @param callable $fn  Receives one clause, returns the replacement.
 * @return array The rewritten meta query.
 */
function map_meta_clause( array $mq, string $key, callable $fn ): array {
	foreach ( $mq as $index => $clause ) {
		if ( 'relation' === $index || ! is_array( $clause ) ) {
			continue;
		}

		if ( $key === ( $clause['key'] ?? null ) ) {
			$mq[ $index ] = $fn( $clause );
			continue;
		}

		$mq[ $index ] = map_meta_clause( $clause, $key, $fn );
	}

	return $mq;
}

/**
 * Append one clause to the group that directly contains a clause on a meta key.
 *
 * @param array  $mq     Meta query.
 * @param string $key    Meta key whose enclosing group is meant.
 * @param array  $clause Clause to add to that group.
 * @return array The rewritten meta query.
 */
function add_to_group( array $mq, string $key, array $clause ): array {
	foreach ( $mq as $index => $element ) {
		if ( 'relation' === $index || ! is_array( $element ) ) {
			continue;
		}

		if ( $key === ( $element['key'] ?? null ) ) {
			$mq[] = $clause;

			return $mq;
		}

		$rewritten = add_to_group( $element, $key, $clause );

		if ( $rewritten !== $element ) {
			$mq[ $index ] = $rewritten;

			return $mq;
		}
	}

	return $mq;
}

// Rounds 1 to 4 each asked one more question of each clause — is it present, is
// its compare right, is its type right, is its enclosing relation right — and
// each round found a question nobody had asked yet. None of the callbacks below
// is answerable by that list. They change a VALUE, they ADD a value inside an OR
// group, they move a boundary date, they widen a term list, they reorder three
// clauses. The list of things a clause can be altered in is not bounded by what
// anyone thought of, so the question here is not what changed but whether what
// runs is what the ability built.
//
// Every number in the labels below was measured on /home/jpk/ddev/posts (WP
// 7.0.2, 6 published jobs, two FULL_TIME) against the code as it stood before
// this round, plus one seeded job expiring 2020-01-01 for the expiry cases.
$identity_hijacks = [

	// Round 5, critical A. Baseline job_type=["FULL_TIME"] is 2 of 6. With key,
	// compare and the group relation left exactly as built and only the value
	// emptied: 6 of 6, filters.job_type still ["FULL_TIME"].
	'only the axis LIKE value, emptied' => [
		static function ( array $a ): array {
			$a['meta_query'] = map_meta_clause(
				$a['meta_query'],
				'job_type',
				static function ( array $c ): array {
					$c['value'] = '';

					return $c;
				}
			);

			return $a;
		},
		[ 'job_type' => [ 'FULL_TIME' ] ],
	],

	// Round 5, critical B. The expiry OR group has three clauses and only two of
	// them were ever checked. Baseline 6 of 6 with the seeded 2020 job correctly
	// absent; with the third clause's compare turned from '=' into '!=' it is 7,
	// and the expired job is back in a list of jobs the site does not show.
	'only the third expiry clause, = turned into !=' => [
		static function ( array $a ): array {
			$a['meta_query'] = map_meta_clause(
				$a['meta_query'],
				'job_expiry_date',
				static function ( array $c ): array {
					if ( '=' === ( $c['compare'] ?? '' ) ) {
						$c['compare'] = '!=';
					}

					return $c;
				}
			);

			return $a;
		},
		[],
	],

	// Round 4 concluded that once the top-level AND is guaranteed a callback can
	// only narrow by adding. It can not: an addition INSIDE an OR group widens.
	// Measured: job_type=["FULL_TIME"] with "PART_TIME" appended to the same OR
	// group answers 4 of 6 while filters.job_type still says ["FULL_TIME"].
	'one more value smuggled into the axis OR group' => [
		static function ( array $a ): array {
			$a['meta_query'] = add_to_group(
				$a['meta_query'],
				'job_type',
				[
					'key'     => 'job_type',
					'value'   => '"PART_TIME"',
					'compare' => 'LIKE',
				]
			);

			return $a;
		},
		[ 'job_type' => [ 'FULL_TIME' ] ],
	],

	// The visibility rule's operand rather than an axis one. compare stays '>=',
	// type stays DATE, only the boundary moves — and every job that ever carried
	// an expiry date is current again. Measured: 6 becomes 7.
	'the expiry boundary date moved to 1970-01-01' => [
		static function ( array $a ): array {
			$a['meta_query'] = map_meta_clause(
				$a['meta_query'],
				'job_expiry_date',
				static function ( array $c ): array {
					if ( '>=' === ( $c['compare'] ?? '' ) ) {
						$c['value'] = '1970-01-01';
					}

					return $c;
				}
			);

			return $a;
		},
		[],
	],

	// The tax clause keeps its taxonomy, its field, its IN operator and a
	// non-empty terms list — the four things round 3 and round 4 check — and
	// filters for every attribute rather than the one asked for. Measured:
	// attribute=["firmenwagen"] is 4 of 6, widened to 6 of 6.
	'the attribute terms widened to every term' => [
		static function ( array $a ): array {
			if ( isset( $a['tax_query'][0]['terms'] ) ) {
				$a['tax_query'][0]['terms'] = [ 20, 21 ];
			}

			return $a;
		},
		[ 'attribute' => [ 'firmenwagen' ] ],
	],

	// Reordering the three expiry clauses inside their OR group changes nothing
	// core will do with them, and it is refused all the same. That direction is
	// deliberate: this comparison errs towards refusing a query the ability did
	// not build, never towards accepting one it did not.
	'the three expiry clauses reordered' => [
		static function ( array $a ): array {
			foreach ( $a['meta_query'] as $index => $group ) {
				if ( 'relation' === $index || ! is_array( $group ) ) {
					continue;
				}

				$clauses = [];

				foreach ( $group as $inner => $clause ) {
					if ( is_array( $clause ) && 'job_expiry_date' === ( $clause['key'] ?? null ) ) {
						$clauses[] = $clause;
					}
				}

				if ( 3 === count( $clauses ) ) {
					$a['meta_query'][ $index ] = array_merge( [ 'relation' => 'OR' ], array_reverse( $clauses ) );
				}
			}

			return $a;
		},
		[],
	],

];

foreach ( $identity_hijacks as $label => $case ) {

	$GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] = $case[0];
	$GLOBALS['jpkcom_test_queries']                                      = [];

	$refused = jpkcom_acf_jobs_ability_query_jobs( $case[1] );

	chk(
		"a clause that is not the one the ability built is refused: {$label}",
		$refused instanceof WP_Error
		&& 'jpkcom_acf_jobs_filter_not_applied' === $refused->get_error_code()
		&& 500 === ( $refused->get_error_data()['status'] ?? null ),
		'Four rounds enumerated properties — presence, then compare, then type, then relation — '
		. 'and each round found a property nobody had listed. This one is not a longer list: the '
		. 'ability records the clauses it built and requires the executed query to carry them.'
	);
	chk(
		"and no query runs for {$label}",
		[] === $GLOBALS['jpkcom_test_queries']
	);

}

// The message has to name the part that diverged, or a site owner is told only
// that something in a query they cannot see is wrong. A top-level meta element is
// either a group of clauses or one bare clause, and the visibility rule is the
// bare one — the case a helper written for groups answers "no idea" for.
$GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] = static function ( array $args ): array {
	$args['meta_query'] = rewrite_meta_clause( $args['meta_query'], 'job_featured', [ 'compare' => 'NOT EXISTS' ] );

	return $args;
};

$named = jpkcom_acf_jobs_ability_query_jobs( [] );

chk(
	'the refusal names the part of the query that diverged',
	$named instanceof WP_Error && str_contains( $named->get_error_message(), 'visibility' ),
	'Got: ' . ( $named instanceof WP_Error ? $named->get_error_message() : 'no error at all' )
);

unset( $GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] );

// The other half of the rule, and it has to be asserted or the guard above is
// satisfied by refusing everything. A callback may still ADD to the query — that
// is what the filter is for — and an addition joined by the top-level AND can
// only ever narrow. The permission is established rather than assumed: the AND
// is verified on the executed arguments, and an addition anywhere else (see the
// OR group case above) is refused.
$permitted_additions = [
	'a site clause added under the top-level AND' => static function ( array $a ): array {
		$a['meta_query'][] = [
			'key'     => 'job_internal',
			'compare' => 'NOT EXISTS',
		];

		return $a;
	},
	'a tax_query added where the caller asked for no attribute' => static function ( array $a ): array {
		$a['tax_query'] = [
			[
				'taxonomy' => 'job-attribute',
				'field'    => 'term_id',
				'terms'    => [ 20 ],
				'operator' => 'IN',
			],
		];

		return $a;
	},
	// Identity, not ===. WP_Meta_Query does not care in which order a clause
	// spells its own keys, so neither may this check: a callback that rebuilds a
	// clause it has read is not one that changed it.
	'the same clause re-spelled with its keys in another order' => static function ( array $a ): array {
		$a['meta_query'] = map_meta_clause(
			$a['meta_query'],
			'job_company',
			static fn ( array $c ): array => [
				'compare' => $c['compare'],
				'value'   => $c['value'],
				'key'     => $c['key'],
			]
		);

		return $a;
	},
];

foreach ( $permitted_additions as $label => $callback ) {

	$GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] = $callback;
	$GLOBALS['jpkcom_test_queries']                                      = [];

	$allowed = jpkcom_acf_jobs_ability_query_jobs( [ 'company' => [ 182 ] ] );

	chk(
		"a callback may still shape the query: {$label}",
		is_array( $allowed ) && [] !== $GLOBALS['jpkcom_test_queries'],
		'A guard that refused every callback would satisfy every assertion above and destroy the '
		. 'filter. Measured live: a clause added under the top-level AND narrows 6 jobs to 2 and '
		. 'the response claims nothing about it, which is the one direction that cannot become a '
		. 'false claim.'
	);

}

unset( $GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] );

echo "\nA callback contributes clauses, and nothing else reaches WP_Query\n";

// The clause half of this guard is identity-based. The half beside it was a list
// of query vars to re-assert after the filter — post type, status, password gate,
// the four pagination values, the row count, the projection — and a list is the
// shape that failed four times over. Two holes were found in it by review and a
// third by reading wp-includes/class-wp-query.php:
//
//   posts_per_archive_page  :2010 overrides posts_per_page for an archive or a
//                           search, and this query is a post type archive.
//                           Measured on WP 7.0.2: per_page 3 answered with
//                           total_pages 1, per_page 3 and all six jobs.
//   showposts               :2006 does the same thing under a second name. Nobody
//                           had listed it. Same measurement, same result.
//   post_password           :2582 is preferred over has_password by an if/elseif,
//                           so the re-asserted gate was dead code. Measured with
//                           one job protected: baseline total 5; hijacked
//                           total 1, total_pages 1, jobs [].
//
// So the list is gone. Nothing a callback returns reaches WP_Query except the two
// clause structures, and those are governed by identity above. The assertions
// below are therefore not "these three names are handled" — the last one is a var
// that does not exist, and it is carried no further than the three that do.
$var_hijacks = [
	'posts_per_archive_page, an alias for posts_per_page' => [ 'posts_per_archive_page' => 100 ],
	'showposts, the second alias for the same value'      => [ 'showposts' => 100 ],
	'post_password, which core prefers over has_password' => [ 'post_password' => 'hunter2' ],
	'perm, which reopens post_status'                     => [ 'perm' => 'readable' ],
	'offset, nopaging and a page of its own at once'      => [
		'offset'   => 5,
		'nopaging' => true,
		'paged'    => 7,
	],
	'a query var that does not exist at all'              => [ 'jpkcom_invented_query_var' => 'anything' ],
];

foreach ( $var_hijacks as $label => $addition ) {

	$GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] = static fn ( array $a ): array => array_merge( $a, $addition );
	$GLOBALS['jpkcom_test_queries']                                      = [];

	$answer = jpkcom_acf_jobs_ability_query_jobs( [ 'per_page' => 2 ] );
	$ran    = listing_query();
	$extra  = [];

	// By value, not by key: `paged` is a var the ability sets for itself, so its
	// presence proves nothing and only its VALUE can show whose it is.
	foreach ( $addition as $var => $value ) {

		if ( array_key_exists( $var, $ran ) && $ran[ $var ] === $value ) {
			$extra[] = $var;
		}
	}

	chk(
		"a callback cannot contribute a query var: {$label}",
		[] === $extra,
		'Not dropped by having been named — never carried. Carried: ' . implode( ', ', $extra )
	);
	chk(
		"and the response is still about the slice it reports: {$label}",
		is_array( $answer )
		&& 2 === ( $answer['per_page'] ?? null )
		&& 1 === ( $answer['page'] ?? null )
		&& 2 === ( $answer['total_pages'] ?? null )
		&& 3 === ( $answer['total'] ?? null ),
		'per_page, page, total and total_pages are a promise about which slice of the result set '
		. 'this response is, and every one of these breaks it without touching a clause. Measured '
		. 'live for two of them: per_page 3 answered with total_pages 1 and all six jobs.'
	);

}

unset( $GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] );

// The route non-carriage cannot close, and the reason the checks that decide the
// answer are on what came back. pre_get_posts fires inside get_posts(), holds the
// query by reference and belongs to core, not to this ability's filter — so the
// slice a response describes has to be verified against the rows the query
// actually returned rather than against the arguments it was given.
$outside_hijacks = [
	'a page size rewritten from inside the query' => [
		static fn ( array $a ): array => array_merge( $a, [ 'posts_per_archive_page' => 100 ] ),
		'more rows came back than the page the response describes can hold',
	],
	'the row count switched off from inside'      => [
		static fn ( array $a ): array => array_merge( $a, [ 'no_found_rows' => true ] ),
		'the total would be smaller than the number of jobs printed beside it',
	],
];

foreach ( $outside_hijacks as $label => $case ) {

	$GLOBALS['jpkcom_test_pre_get_posts'] = $case[0];
	$GLOBALS['jpkcom_test_queries']       = [];

	$outside = jpkcom_acf_jobs_ability_query_jobs( [ 'per_page' => 2 ] );

	chk(
		"the answer is checked against what came back: {$label}",
		$outside instanceof WP_Error
		&& 'jpkcom_acf_jobs_filter_not_applied' === $outside->get_error_code()
		&& 500 === ( $outside->get_error_data()['status'] ?? null ),
		$case[1] . ', and no argument this ability sets can prevent a pre_get_posts callback from '
		. 'doing it. What it can do is refuse to describe the result as something it is not.'
	);

}

$GLOBALS['jpkcom_test_pre_get_posts'] = null;

// The same rule seen from the other side: what a callback CANNOT take back. Each
// of these was a refusal while the value was still read from the callback; now
// the value never leaves the ability, so the query simply runs as built. Both are
// true statements about the response, and this is the one that costs the caller
// nothing.
$reclaim_attempts = [
	'the sort direction'    => [
		static function ( array $a ): array {
			$a['orderby']['date'] = 'ASC';

			return $a;
		},
		[ 'order' => 'DESC' ],
		static fn ( array $q ): bool => 'DESC' === ( $q['orderby']['date'] ?? null ) && 'DESC' === ( $q['orderby']['ID'] ?? null ),
	],
	'the featured-first sort key' => [
		static fn ( array $a ): array => array_diff_key( $a, [ 'meta_key' => 0 ] ),
		[],
		static fn ( array $q ): bool => 'job_featured' === ( $q['meta_key'] ?? null ),
	],
	'the search term, dropped'    => [
		static fn ( array $a ): array => array_diff_key( $a, [ 's' => 0 ] ),
		[ 'search' => 'Stelle' ],
		static fn ( array $q ): bool => 'Stelle' === ( $q['s'] ?? null ),
	],
	'the search term, grown past what core applies' => [
		static fn ( array $a ): array => array_merge( $a, [ 's' => str_repeat( 'a', 1601 ) ] ),
		[ 'search' => 'Stelle' ],
		static fn ( array $q ): bool => 'Stelle' === ( $q['s'] ?? null ),
	],
	'the projection'              => [
		static fn ( array $a ): array => array_merge( $a, [ 'fields' => 'id=>parent' ] ),
		[],
		static fn ( array $q ): bool => ! isset( $q['fields'] ),
	],
];

foreach ( $reclaim_attempts as $label => $case ) {

	$GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] = $case[0];
	$GLOBALS['jpkcom_test_queries']                                      = [];

	$kept = jpkcom_acf_jobs_ability_query_jobs( $case[1] );

	chk(
		"a callback cannot take back {$label}",
		is_array( $kept ) && $case[2]( listing_query() ),
		'The value the query ran with has to be the one the ability built, and the response '
		. 'reports it. Executed: ' . json_encode( array_intersect_key( listing_query(), [ 'orderby' => 0, 'meta_key' => 0, 's' => 0, 'fields' => 0 ] ) )
	);

}

unset( $GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] );

// Neither of these is a clause, and neither is caught by anything that looks at
// clauses. Both make the response contradict itself, which is the same defect
// one layer over: a caller cannot tell that the numbers it is reading are not
// about the jobs it is reading.
$GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] = static fn ( array $a ): array => array_merge( $a, [ 'no_found_rows' => true ] );
$GLOBALS['jpkcom_test_queries']                                      = [];

$counted = jpkcom_acf_jobs_ability_query_jobs( [] );

chk(
	'a callback cannot switch off the totals it is reported next to',
	is_array( $counted ) && 3 === ( $counted['total'] ?? null ) && 1 === ( $counted['total_pages'] ?? null ),
	'WP_Query skips set_found_posts() entirely for a truthy no_found_rows, so found_posts and '
	. 'max_num_pages stay 0. Measured live on WP 7.0.2 at per_page 3: total 0, total_pages 0, '
	. 'and three jobs in the same response.'
);

$GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] = static fn ( array $a ): array => array_merge( $a, [ 'fields' => 'id=>parent' ] );
$GLOBALS['jpkcom_test_queries']                                      = [];

$projected = jpkcom_acf_jobs_ability_query_jobs( [] );

chk(
	'a callback cannot choose a projection the reader cannot read',
	is_array( $projected ) && 3 === ( $projected['total'] ?? null ) && [] !== ( $projected['jobs'] ?? [] ),
	'Core returns bare stdClass rows for fields => id=>parent. Measured live: total 6 beside an '
	. 'empty jobs list. Pinned rather than accommodated, because the set of projections a reader '
	. 'can survive is another list nobody can finish.'
);

unset( $GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] );

echo "\nThe canonical form, which everything above rests on\n";

/**
 * Build an array nested to a given depth.
 *
 * @param int $depth How many levels to nest.
 * @return array The nested array.
 */
function nested_array( int $depth ): array {
	$out = [ 'leaf' => 'value' ];

	for ( $i = 0; $i < $depth; $i++ ) {
		$out = [ $out ];
	}

	return $out;
}

/**
 * Build a meta query with one clause buried at a given depth.
 *
 * @param int    $depth How many groups to wrap the clause in.
 * @param string $key   Meta key the buried clause carries.
 * @return array The nested meta query.
 */
function nested_clause( int $depth, string $key ): array {
	$out = [ [ 'key' => $key, 'compare' => 'LIKE', 'value' => '"x"' ] ];

	for ( $i = 0; $i < $depth; $i++ ) {
		$out = [ $out ];
	}

	return $out;
}

// Four ways to weaken jpkcom_acf_jobs_ability_canonical() left the suite green,
// which means the mechanism the whole guard now rests on had no assertion of its
// own. The properties below are the load-bearing ones, and each is asserted as a
// property rather than as a literal string, so the form may change spelling and
// may not change meaning.

// 1. Two structures must never share a canonical. Without the length prefix on
//    strings, the pair below collides: one clause carrying a single key whose
//    VALUE contains the separators, and one carrying two keys — the executed
//    clause has no `key` at all and reads as though it did.
$smuggled = [ 'key' => 'job_type;s:value=>s:"FULL_TIME"' ];
$genuine  = [
	'key'   => 'job_type',
	'value' => '"FULL_TIME"',
];

chk(
	'a value carrying the separators cannot imitate a second key',
	jpkcom_acf_jobs_ability_canonical( $smuggled ) !== jpkcom_acf_jobs_ability_canonical( $genuine ),
	'Strings are length-prefixed for exactly this reason. Without it these two produce the same '
	. 'string, and the collision is in the WIDENING direction: a clause that lost its key would '
	. 'be accepted as the clause that had one.'
);

// 2. Scalar types are part of a value. '182' and 182 produce different SQL through
//    a LIKE comparison, and null, '', 0, false and '0' are five different answers
//    to "what does this clause compare against".
$distinct = [
	'null'         => null,
	'empty string' => '',
	'zero int'     => 0,
	'zero string'  => '0',
	'false'        => false,
	'true'         => true,
	'one int'      => 1,
	'one string'   => '1',
	'one float'    => 1.0,
];

$seen      = [];
$collision = '';

foreach ( $distinct as $name => $value ) {

	$form = jpkcom_acf_jobs_ability_canonical( $value );

	if ( isset( $seen[ $form ] ) ) {
		$collision = $seen[ $form ] . ' and ' . $name;
	}

	$seen[ $form ] = $name;

}

chk(
	'nine scalars that mean nine different things have nine different forms',
	'' === $collision,
	'Collided: ' . $collision
);

chk(
	'key order inside a clause is not content',
	jpkcom_acf_jobs_ability_canonical( [ 'key' => 'job_type', 'compare' => 'LIKE' ] )
	=== jpkcom_acf_jobs_ability_canonical( [ 'compare' => 'LIKE', 'key' => 'job_type' ] ),
	'WP_Meta_Query reads a clause by key, so a callback that rebuilds a clause it has read has '
	. 'not changed it.'
);
chk(
	'list order is',
	jpkcom_acf_jobs_ability_canonical( [ [ 'a' ], [ 'b' ] ] )
	!== jpkcom_acf_jobs_ability_canonical( [ [ 'b' ], [ 'a' ] ] ),
	'Erring towards refusing a query the ability did build, never towards accepting one it did '
	. 'not.'
);
chk(
	'an object is not the array of the same properties',
	jpkcom_acf_jobs_ability_canonical( (object) [ 'key' => 'job_type' ] )
	!== jpkcom_acf_jobs_ability_canonical( [ 'key' => 'job_type' ] ),
	'WP_Meta_Query does not read an object clause the way it reads an array one.'
);
chk(
	'the recursion is bounded and does not throw',
	is_string( jpkcom_acf_jobs_ability_canonical( nested_array( 40 ) ) ),
	'A callback may return anything, and unbounded recursion on a deep array is a stack overflow '
	. 'no ability callback may risk.'
);
chk(
	'and the walk really stops rather than merely surviving',
	jpkcom_acf_jobs_ability_canonical( nested_array( 200 ) ) === jpkcom_acf_jobs_ability_canonical( nested_array( 400 ) ),
	'Below the limit two structures become indistinguishable, which is what "bounded" costs and '
	. 'the only mechanical evidence that the bound exists — a walk that ran to the bottom would '
	. 'tell 200 levels from 400. It cannot be exploited against a commitment: the deepest fragment '
	. 'this ability builds is a clause inside a group, so anything deep enough to be truncated '
	. 'already differs from it far higher up.'
);

echo "\nThe branches the guards decide on\n";

// Six branches of the two guard helpers had no assertion at all: each was a
// mutation that left the suite green. They are pure functions, so they are
// asserted directly rather than through a callback that has to reach them.
$built_args = jpkcom_acf_jobs_build_job_query_args(
	[
		'post_status' => 'publish',
		'order'       => 'DESC',
		'attribute'   => [ 20 ],
	]
);

$built_args['orderby']['ID'] = 'DESC';

chk(
	'the precondition passes the query the builder actually builds',
	'' === jpkcom_acf_jobs_ability_unbacked_claim( $built_args, [ 'the site visibility rule' => 'job_featured' ], true, '', 'DESC' ),
	'Without this every assertion below is satisfied by a helper that refuses everything.'
);
chk(
	'a query with no meta_query at all is refused by name',
	'the site visibility rule' === jpkcom_acf_jobs_ability_unbacked_claim( array_diff_key( $built_args, [ 'meta_query' => 0 ] ), [], false, '', 'DESC' )
);
chk(
	'a tax clause with an emptied terms list backs no attribute claim',
	'attribute' === jpkcom_acf_jobs_ability_unbacked_claim(
		array_merge( $built_args, [ 'tax_query' => [ [ 'taxonomy' => 'job-attribute', 'field' => 'term_id', 'terms' => [], 'operator' => 'IN' ] ] ] ),
		[],
		true,
		'',
		'DESC'
	),
	'WP_Tax_Query answers an empty terms list with 1=0, so the result stays honest — but naming '
	. 'the attribute as applied when it never ran is not.'
);

$order_cases = [
	'orderby is not an array'      => array_merge( $built_args, [ 'orderby' => 'date' ] ),
	'the date component is gone'   => array_merge( $built_args, [ 'orderby' => [ 'ID' => 'DESC' ] ] ),
	'the tiebreaker is gone'       => array_merge( $built_args, [ 'orderby' => [ 'date' => 'DESC' ] ] ),
	'a direction points the other way' => array_merge( $built_args, [ 'orderby' => [ 'date' => 'ASC', 'ID' => 'DESC' ] ] ),
	'a direction is not a scalar'  => array_merge( $built_args, [ 'orderby' => [ 'date' => [ 'DESC' ], 'ID' => 'DESC' ] ] ),
];

foreach ( $order_cases as $label => $case ) {

	chk(
		"the sort promise is unbacked when {$label}",
		'order' === jpkcom_acf_jobs_ability_unbacked_claim( $case, [], false, '', 'DESC' ),
		'filters.order reports the direction back, and the ID tiebreaker is what makes page 1 and '
		. 'page 2 add up to the result set exactly. A non-scalar direction must not be cast — that '
		. 'throws, and a Throwable out of an ability callback is an uncaught fatal on the floor.'
	);

}

$two_alike = [
	'meta' => [
		[ 'label' => 'job_type', 'canonical' => jpkcom_acf_jobs_ability_canonical( [ 'key' => 'job_type' ] ) ],
		[ 'label' => 'job_type', 'canonical' => jpkcom_acf_jobs_ability_canonical( [ 'key' => 'job_type' ] ) ],
	],
	'tax'  => [],
];

chk(
	'two identical commitments need two elements to keep them',
	'' !== jpkcom_acf_jobs_ability_query_divergence( $two_alike, [ 'meta_query' => [ [ 'key' => 'job_type' ] ] ] ),
	'A commitment is consumed once it is matched. Without that, one surviving clause would '
	. 'satisfy every commitment that looks like it, and a callback could collapse two axes into '
	. 'one and be told nothing.'
);
chk(
	'and two elements do keep them',
	'' === jpkcom_acf_jobs_ability_query_divergence( $two_alike, [ 'meta_query' => [ [ 'key' => 'job_type' ], [ 'key' => 'job_type' ] ] ] )
);
$not_arrays = [
	'a string'  => 'not an array',
	'an int'    => 42,
	'null'      => null,
	'an object' => (object) [ 'meta_query' => [ [ 'key' => 'job_type' ] ] ],
];

foreach ( $not_arrays as $label => $case ) {

	chk(
		"arguments that are not an array are a divergence, not a pass: {$label}",
		'' !== jpkcom_acf_jobs_ability_query_divergence( $two_alike, $case ),
		'is_array() is the first question, and answering "nothing diverged" for a non-array would '
		. 'be the one wrong answer this whole guard exists to prevent. The object is the case that '
		. 'makes the guard load-bearing rather than tidy: indexing one that implements no '
		. 'ArrayAccess is an Error, and a Throwable out of an ability callback is an uncaught '
		. 'fatal on the 6.9 floor.'
	);

}

chk(
	'a clause buried deeper than the walk goes is not reported as present',
	false === jpkcom_acf_jobs_ability_has_meta_clause( nested_clause( 30, 'job_type' ), 'job_type' ),
	'The depth limit exists so a rewritten argument cannot overflow the stack. It has to fail '
	. 'CLOSED: reporting a clause nobody can reach as present would back a claim the query does '
	. 'not carry.'
);
chk(
	'and one within reach is',
	true === jpkcom_acf_jobs_ability_has_meta_clause( nested_clause( 3, 'job_type' ), 'job_type' ),
	'Without this the assertion above is satisfied by a helper that always says no.'
);

echo "\nA request the site's own query builder cannot express\n";

// The one case the identity check above has nothing to say about: a clause that
// was never built cannot go missing, and there is nothing to compare it with.
// Neither of the two below needs a test seam.
//
//   job_type  the builder reduces the value list with array_filter(), which
//             discards the string "0" like every other falsy value. The
//             vocabulary comes from an ACF field definition the SITE registers,
//             so a choice whose value is "0" is a site's decision, not this
//             plugin's.
//   search    the builder tests ! empty( $args['search'] ) before it looks at the
//             term, and empty( '0' ) is true. A caller searching for a single
//             zero needs nothing unusual on the site at all.
//
// Both would otherwise set no clause, run unfiltered, and name the axis in
// `filters` as applied.
$vocabulary                              = jpkcom_acf_jobs_job_type_choices();
$GLOBALS['jpkcom_test_job_type_choices'] = $vocabulary + [ '0' => 'Nullstelle' ];
$GLOBALS['jpkcom_test_queries']          = [];

$zero_type = jpkcom_acf_jobs_ability_query_jobs( [ 'job_type' => [ '0' ] ] );

chk(
	'a job_type value the shared builder drops is refused, not answered unfiltered',
	$zero_type instanceof WP_Error && 'jpkcom_acf_jobs_filter_not_applied' === $zero_type->get_error_code()
	&& 500 === ( $zero_type->get_error_data()['status'] ?? null ),
	'The value passed every input check: it is in the registered vocabulary. It is the builder '
	. 'that cannot carry it, and the builder is resolved through the plugin\'s file override '
	. 'chain, so a site can replace it with one that carries less.'
);
chk(
	'and no unfiltered list runs in place of that job_type',
	[] === $GLOBALS['jpkcom_test_queries']
);

$GLOBALS['jpkcom_test_job_type_choices'] = [];
$GLOBALS['jpkcom_test_queries']          = [];

$zero_search = jpkcom_acf_jobs_ability_query_jobs( [ 'search' => '0' ] );

chk(
	'a search term the shared builder drops is refused, not answered unfiltered',
	$zero_search instanceof WP_Error && 'jpkcom_acf_jobs_filter_not_applied' === $zero_search->get_error_code()
	&& 500 === ( $zero_search->get_error_data()['status'] ?? null ),
	'empty( "0" ) is true, so s is never set and WP_Query returns every job on the site while '
	. 'filters.search echoes the term back as applied.'
);
chk(
	'and no unfiltered list runs in place of the search for "0"',
	[] === $GLOBALS['jpkcom_test_queries']
);

// Four ways to make page, per_page and total_pages a lie without touching a
// single filter clause. All read out of wp-includes/class-wp-query.php on the
// 6.9.4 floor: :2805-2808 offset overrides paged, :2017-2021 a posts_per_page of
// -1 switches nopaging on by itself, :2798 a truthy nopaging drops the LIMIT.
$pagination_hijacks = [
	'an offset that overrides paged' => static fn ( array $a ): array => $a + [ 'offset' => 5 ],
	'nopaging'                       => static fn ( array $a ): array => $a + [ 'nopaging' => true ],
	'an unbounded posts_per_page'    => static fn ( array $a ): array => array_merge( $a, [ 'posts_per_page' => -1 ] ),
	'a page of its own choosing'     => static fn ( array $a ): array => array_merge( $a, [ 'paged' => 7 ] ),
];

foreach ( $pagination_hijacks as $label => $callback ) {

	$GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] = $callback;
	$GLOBALS['jpkcom_test_queries']                                      = [];

	$answer = jpkcom_acf_jobs_ability_query_jobs( [ 'per_page' => 2, 'page' => 1 ] );
	$ran    = listing_query();

	chk(
		"the argument filter cannot rewrite the pagination: {$label}",
		is_array( $answer )
		&& ! isset( $ran['offset'] ) && empty( $ran['nopaging'] )
		&& 2 === ( $ran['posts_per_page'] ?? null ) && 1 === ( $ran['paged'] ?? null )
		&& 2 === ( $answer['per_page'] ?? null ) && 1 === ( $answer['page'] ?? null ),
		'per_page, page and total_pages are a promise about which slice of the result set this '
		. 'response is. Any of these four breaks that promise without touching a filter, and the '
		. 'caller has no way to detect it.'
	);

}

unset( $GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] );

echo "\nA filter core will not apply must not be claimed as applied\n";

/**
 * Report every filter the response claims that the executed query did not carry.
 *
 * The post-condition inside the callback checks the arguments it is about to
 * hand to WP_Query. This checks the other end: what the query actually ran,
 * against what the response tells the caller was applied. Anything core discards
 * *inside* WP_Query — after the callback's own check has passed — is invisible to
 * that check and visible to this one.
 *
 * @param mixed $response Ability result, or a WP_Error.
 * @param array $query    Recorded arguments of the executed listing query.
 * @return array List of human-readable violations.
 */
function filters_not_carried( mixed $response, array $query ): array {
	if ( ! is_array( $response ) ) {
		return [];
	}

	$claimed = (array) ( $response['filters'] ?? [] );
	$out     = [];

	foreach ( [ 'job_type' => 'job_type', 'company' => 'job_company', 'location' => 'job_location' ] as $axis => $meta_key ) {
		if ( ! empty( $claimed[ $axis ] ) && null === find_meta_clause( $query['meta_query'] ?? null, $meta_key ) ) {
			$out[] = "filters.{$axis} claimed, but no {$meta_key} clause was executed";
		}
	}

	if ( ! empty( $claimed['attribute'] ) ) {
		$terms = null;

		foreach ( (array) ( $query['tax_query'] ?? [] ) as $clause ) {
			if ( is_array( $clause ) && 'job-attribute' === ( $clause['taxonomy'] ?? null ) ) {
				$terms = $clause['terms'] ?? null;
			}
		}

		if ( empty( $terms ) ) {
			$out[] = 'filters.attribute claimed, but no job-attribute terms were executed';
		}
	}

	if ( isset( $claimed['search'] ) && ( $query['s'] ?? '' ) !== $claimed['search'] ) {
		$out[] = sprintf(
			'filters.search claimed as %d bytes, but the executed query carried %d',
			strlen( (string) $claimed['search'] ),
			strlen( (string) ( $query['s'] ?? '' ) )
		);
	}

	if ( false === ( $claimed['include_closed'] ?? null ) && null === find_meta_clause( $query['meta_query'] ?? null, 'job_closed' ) ) {
		$out[] = 'filters.include_closed is false, but no job_closed clause was executed';
	}

	return $out;
}

// 1600 is read from wp-includes/class-wp-query.php:867 on BOTH instances —
// WP 6.9.4 at /home/jpk/ddev/test2 and WP 7.0.2 at /home/jpk/ddev/posts carry a
// byte-identical guard, so there is no stricter version to take. strlen(), so the
// unit is BYTES: a multibyte term reaches the limit in fewer characters.
$search_limit = 1600;

chk(
	'the limit the callback enforces is the one core actually applies',
	defined( 'JPKCOM_ACFJOBS_ABILITY_SEARCH_MAX_BYTES' )
	&& $search_limit === JPKCOM_ACFJOBS_ABILITY_SEARCH_MAX_BYTES,
	'Read out of the core source on both instances rather than taken on trust. If core ever '
	. 'lowers it, this is the assertion that says so.'
);

$GLOBALS['jpkcom_test_queries'] = [];

$at_limit = jpkcom_acf_jobs_ability_query_jobs( [ 'search' => str_repeat( 'a', $search_limit ) ] );

chk(
	'a search term exactly at the limit is accepted and reaches WP_Query',
	is_array( $at_limit ) && $search_limit === strlen( (string) ( listing_query()['s'] ?? '' ) ),
	'The boundary is inclusive in core: the guard fires above 1600, not at it. Refusing at the '
	. 'limit would be its own wrong answer.'
);

$GLOBALS['jpkcom_test_queries'] = [];

$over_limit = jpkcom_acf_jobs_ability_query_jobs( [ 'search' => str_repeat( 'a', $search_limit + 1 ) ] );

chk(
	'one byte over the limit is refused',
	$over_limit instanceof WP_Error && 'jpkcom_acf_jobs_invalid_filter' === $over_limit->get_error_code()
	&& 400 === ( $over_limit->get_error_data()['status'] ?? null ),
	'Core empties s beyond 1600 bytes and WP_Query then matches everything, so the answer was '
	. 'every job on the site with filters.search echoing the term back as if it had been used.'
);
chk(
	'the message names the limit, so a caller can shorten and retry in one turn',
	$over_limit instanceof WP_Error && str_contains( $over_limit->get_error_message(), (string) $search_limit )
);
chk(
	'and no query ran',
	[] === $GLOBALS['jpkcom_test_queries'],
	'Truncating to the limit instead would return results for a query the caller never made.'
);

$GLOBALS['jpkcom_test_queries'] = [];

// 801 two-byte characters is 1602 bytes. Core counts bytes, so this is over the
// limit even though it is well under 1600 characters; mb_strlen() here would let
// it through and reproduce the defect for every non-ASCII caller.
$multibyte = jpkcom_acf_jobs_ability_query_jobs( [ 'search' => str_repeat( 'ü', 801 ) ] );

chk(
	'the limit is counted in bytes, as core counts it',
	$multibyte instanceof WP_Error && 'jpkcom_acf_jobs_invalid_filter' === $multibyte->get_error_code()
	&& 400 === ( $multibyte->get_error_data()['status'] ?? null ),
	// The code and the status are asserted, not merely "an error". Counting
	// characters still ends in a refusal, because the post-condition re-checks
	// the length in bytes — but it arrives as filter_not_applied / 500, which
	// tells the caller a server fault it cannot fix instead of a term it can
	// shorten. Asserting only instanceof WP_Error left that mutation green.
	'strlen( str_repeat( "ü", 801 ) ) is 1602. Counting characters would pass it to a core '
	. 'guard that counts bytes, and the wrong answer would come back for accented search terms '
	. 'only — the hardest possible version of this bug to notice.'
);

$sweep = [
	'no input'                => [],
	'null'                    => null,
	'the declared default'    => $defs['jpkcom-acf-jobs/query-jobs']['input_schema']['default'],
	'job_type'                => [ 'job_type' => [ 'FULL_TIME' ] ],
	'company'                 => [ 'company' => [ 182 ] ],
	'location'                => [ 'location' => [ 183 ] ],
	'attribute'               => [ 'attribute' => [ 'firmenwagen' ] ],
	'a short search'          => [ 'search' => 'Stelle' ],
	'a search at the limit'   => [ 'search' => str_repeat( 'a', $search_limit ) ],
	'a search over the limit' => [ 'search' => str_repeat( 'a', $search_limit + 1 ) ],
	'a multibyte search'      => [ 'search' => str_repeat( 'ü', 801 ) ],
	'include_closed=false'    => [ 'include_closed' => false ],
	'every axis at once'      => [
		'job_type'       => [ 'FULL_TIME' ],
		'company'        => [ 182 ],
		'location'       => [ 183 ],
		'attribute'      => [ 'firmenwagen' ],
		'search'         => 'Stelle',
		'include_closed' => false,
	],
];

foreach ( $sweep as $label => $spelling ) {

	$GLOBALS['jpkcom_test_queries'] = [];

	$swept      = jpkcom_acf_jobs_ability_query_jobs( $spelling );
	$violations = filters_not_carried( $swept, listing_query() );

	chk(
		"filters claims nothing the query did not carry: {$label}",
		[] === $violations,
		'A response that reports a filter the query never applied is a wrong answer presented as '
		. 'a right one, whether the clause was dropped by this plugin, by a site callback, or by '
		. 'core inside WP_Query. ' . implode( '; ', $violations )
	);

}

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

echo "\nThe detail-page rule\n";

chk(
	'the detail-page predicate exists',
	function_exists( 'jpkcom_acf_jobs_detail_page_renders' ),
	'get-job needs one named place that answers "would this job\'s page render for a visitor", '
	. 'because that is the only question the detail block is allowed to depend on.'
);

if ( function_exists( 'jpkcom_acf_jobs_detail_page_renders' ) ) {

	$GLOBALS['jpkcom_test_fields'][184] = [];

	chk(
		'a published job with nothing set has a detail page',
		true === jpkcom_acf_jobs_detail_page_renders( 184 ),
		'Without this the three false cases below would all pass against a predicate that '
		. 'always says no, and get-job would answer every job without its detail block.'
	);

	$GLOBALS['jpkcom_test_fields'][184]['job_url'] = [ 'url' => 'https://ats.example.test/apply/1' ];

	chk(
		'a job carrying a job_url has none',
		false === jpkcom_acf_jobs_detail_page_renders( 184 ),
		'includes/redirects.php:32-86 307s every caller without manage_options to that target '
		. 'before single-job.php runs, so the address, the salary and the application data of '
		. 'such a job were never published to anyone.'
	);

	$GLOBALS['jpkcom_test_fields'][184]['job_url'] = [ 'url' => "\t \n" ];

	chk(
		'a whitespace-only job_url redirects just as effectively',
		false === jpkcom_acf_jobs_detail_page_renders( 184 ),
		'redirects.php tests ! empty() on the RAW value, and "\t \n" is not empty: the visitor '
		. 'is sent away and no page renders. Trimming first — which is what the reader does for '
		. 'its own url field — answers the opposite for the same job.'
	);

	unset( $GLOBALS['jpkcom_test_fields'][184]['job_url'] );

	$GLOBALS['jpkcom_test_fields'][184]['job_expiry_date'] = '2025-11-30';

	chk(
		'an expired job has none',
		false === jpkcom_acf_jobs_detail_page_renders( 184 ),
		'includes/redirects.php:132-173 307s every caller without edit_post to the archive.'
	);

	// current_time() is stubbed to 2026-01-15, and the site lists a job through its
	// last day: the visibility rule compares the stored date >= today.
	$GLOBALS['jpkcom_test_fields'][184]['job_expiry_date'] = '2026-01-15';

	chk(
		'a job expiring today still has one',
		true === jpkcom_acf_jobs_detail_page_renders( 184 ),
		'An off-by-one here withholds the detail data of every job on its last day, which is '
		. 'the day it is most likely to be asked about.'
	);

	// ACF hands out Ymd whenever the field's key reference row is missing, which is
	// the state includes/wpml-acf-field-keys-fix.php exists to repair.
	$GLOBALS['jpkcom_test_fields'][184]['job_expiry_date'] = '20251130';

	chk(
		'an expiry stored as Ymd is read as a date, not compared as a string',
		false === jpkcom_acf_jobs_detail_page_renders( 184 ),
		'redirects.php compares the raw value against a Y-m-d today, so "20251130" < "2026-01-15" '
		. 'holds only by accident of both starting with the same two digits. This goes through '
		. 'jpkcom_acf_jobs_normalise_date() instead.'
	);

	unset( $GLOBALS['jpkcom_test_fields'][184]['job_expiry_date'] );

	// The rest of the fail-open audit. Every remaining value the predicate cannot
	// read is pinned here, in the direction the SITE takes for the same value —
	// which is the only correct reference, because the question is "was this data
	// ever published", not "is this value well-formed".
	$GLOBALS['jpkcom_test_fields'][184]['job_url'] = 'https://ats.example.test/apply/1';

	chk(
		'a job_url stored as a bare string still renders',
		true === jpkcom_acf_jobs_detail_page_renders( 184 ),
		'redirects.php:62 requires is_array() before it redirects, so for this shape the site '
		. 'serves the page like any other and its address and salary really are published. '
		. 'Withholding here would be over-withholding, not caution.'
	);

	$GLOBALS['jpkcom_test_fields'][184]['job_url'] = [ 'url' => [ 'nested' ] ];

	chk(
		'a job_url whose target is not a string withholds',
		false === jpkcom_acf_jobs_detail_page_renders( 184 ),
		'! empty() is true for it, so redirects.php enters its redirect branch and hands an array '
		. 'to strpos() under strict_types — a TypeError, so that page answers 500 rather than '
		. 'rendering. No visitor ever saw this data either.'
	);

	unset( $GLOBALS['jpkcom_test_fields'][184]['job_url'] );

	$GLOBALS['jpkcom_test_fields'][184]['job_expiry_date'] = [ '2025-11-30' ];

	chk(
		'an expiry stored as an array withholds',
		false === jpkcom_acf_jobs_detail_page_renders( 184 ),
		'The site would render this one — PHP compares an array as greater than any string, so '
		. 'redirects.php finds it unexpired. Withholding is deliberate: an array in a date field '
		. 'is corrupt data, and the predicate never guesses on a value it cannot read.'
	);

	unset( $GLOBALS['jpkcom_test_fields'][184]['job_expiry_date'] );

	chk(
		'a password-protected job has none',
		false === jpkcom_acf_jobs_detail_page_renders( 701 ),
		'get_the_title() prepends the protected-title format while ACF hands out the salary and '
		. 'the address in full, so such a record contradicts itself.'
	);
	chk( 'a draft job has none', false === jpkcom_acf_jobs_detail_page_renders( 5 ) );
	chk( 'a post of another type has none', false === jpkcom_acf_jobs_detail_page_renders( 700 ) );
	chk( 'an id no post carries has none', false === jpkcom_acf_jobs_detail_page_renders( 999999 ) );

	// Same trap as the reader's own gate: real get_post( 0 ) treats 0 as empty and
	// falls back to the current global post.
	$GLOBALS['post'] = new WP_Post( 900, 'Leftover Global Job', 'publish', 'job' );

	chk(
		'id 0 has none either',
		false === jpkcom_acf_jobs_detail_page_renders( 0 ),
		'get_post( 0 ) falls back to $GLOBALS[\'post\'] in real WordPress, so without the '
		. '$post_id < 1 guard this reports on whatever post the surrounding request left there.'
	);

	unset( $GLOBALS['post'] );
}

echo "\nget-job\n";

$GLOBALS['jpkcom_test_fields'][184]    = [];
$GLOBALS['jpkcom_test_meta_rows'][184] = [ 'job_featured' ];

// One layout row with an image and one without, because detail.layout[].image is
// declared [string,null] and no seeded job has ever had a row at all: the null
// half of that declaration has never been produced by anything.
$GLOBALS['jpkcom_test_rows'][184] = [
	'job_layout_content' => [
		[
			'acf_fc_layout' => 'text_image',
			'text_left'     => 'Wir suchen Verstärkung. [gallery ids="1,2"] https://example.com/stelle',
			'img_right'     => 55,
		],
		[
			'acf_fc_layout' => 'wysiwyg',
			'wysiwyg_full'  => '<p>Zweite Zeile</p>',
		],
	],
];

$GLOBALS['jpkcom_test_attachments'][55] = 'https://example.test/wp-content/uploads/row.jpg';

$job = jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] );

chk(
	'get-job answers with a record rather than the 501 placeholder',
	is_array( $job ),
	'The registration and the whole output schema were complete from Task 5 on; the callback '
	. 'answered 501 for every input.'
);
chk( 'it answers for the requested job', is_array( $job ) && 184 === ( $job['id'] ?? null ) );
chk(
	'it reports the resolved language',
	is_array( $job ) && 'en_US' === ( $job['language'] ?? null ),
	'The output schema declares it and the other two abilities return it. Without it a caller '
	. 'cannot tell which language the answer is in, and core answers ability_invalid_output '
	. 'for nothing at all.'
);
chk(
	'it carries the detail block query-jobs never returns',
	is_array( $job ) && is_array( $job['detail'] ?? null )
	&& is_array( $job['detail']['locations'] ?? null )
	&& array_key_exists( 'salary', $job['detail'] ?? [] )
	&& is_array( $job['detail']['application'] ?? null ),
	'This is the half of the reader that no ability reached until now: get-job has to call it '
	. 'with $full = true, or the postal address, the salary and the application data are absent '
	. 'from the only ability that exists to return them.'
);
chk(
	'the detail block carries the page content rows',
	is_array( $job )
	&& [ 'text_image', 'wysiwyg' ] === array_column( $job['detail']['layout'] ?? [], 'layout' )
	&& false === ( $job['detail']['layout_rows_truncated'] ?? null )
);
// Not ?? on the second row: the value under test IS null, and ?? cannot tell a
// present null from a missing key.
$rowless_row = $job['detail']['layout'][1] ?? [];

chk(
	'a content row with an image reports its URL and one without reports null',
	is_array( $job )
	&& 'https://example.test/wp-content/uploads/row.jpg' === ( $job['detail']['layout'][0]['image'] ?? null )
	&& array_key_exists( 'image', $rowless_row ) && null === $rowless_row['image'],
	'Both halves of the [string,null] declaration in one record. Until a job had a layout row '
	. 'at all, neither half of it had ever been produced by anything.'
);
chk(
	'a shortcode in a content row is returned as literal text and never executed',
	is_array( $job ) && str_contains( (string) ( $job['detail']['layout'][0]['text'] ?? '' ), '[gallery ids="1,2"]' ),
	'get_sub_field()\'s formatted mode pipes a wysiwyg value through acf_the_content, which '
	. 'carries do_shortcode at priority 11 and WP_Embed::autoembed at 8. A shortcode that echoes '
	. 'would put its bytes before the JSON body, and the bare URL on that same row would be '
	. 'fetched and written to the database as an oembed_cache post.'
);
chk(
	'a job with a job_featured row is listed and gives no reason',
	is_array( $job ) && true === ( $job['listed'] ?? null ) && ! array_key_exists( 'listed_reason', $job ),
	'A reason next to listed:true would be a contradiction in the same record.'
);
chk(
	'url is the permalink while no application URL is set',
	is_array( $job ) && 'https://example.test/job/184/' === ( $job['url'] ?? null )
	&& false === ( $job['redirects_externally'] ?? null )
);

$full_job_violations = schema_type_violations( $job, $defs['jpkcom-acf-jobs/get-job']['output_schema'], 'get-job' );

chk(
	'the whole get-job response satisfies its own output schema',
	// is_array() first: a WP_Error is an object, the schema root is an object, and
	// schema_type_violations() would happily report no violations for it.
	is_array( $job ) && [] === $full_job_violations,
	'Core validates every ability result against output_schema and answers ability_invalid_output '
	. 'in its place, so a type the reader can produce but the schema does not declare is a total '
	. 'outage of this ability rather than a documentation defect. '
	. implode( '; ', $full_job_violations )
);

$GLOBALS['jpkcom_test_meta_rows'][184] = [];

// Stated as a fixture rather than derived, because that is the whole point: two
// independent clauses of the shared rule exclude a job with no job_featured row —
// the EXISTS clause and the meta_key the ordering needs — and neither of them is
// visible to PHP.
$GLOBALS['jpkcom_test_unlisted'] = [ 184 ];

$unlisted = jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] );

chk(
	'a job with no job_featured ROW is still resolvable, and says why it is unlisted',
	is_array( $unlisted ) && false === ( $unlisted['listed'] ?? null )
	&& 'missing_job_featured' === ( $unlisted['listed_reason'] ?? null ),
	'Two independent causes keep such a job out of every listing and nothing on the site reports '
	. 'either. get-job deliberately does not apply the visibility rule as an existence test, '
	. 'because "why does job 42 appear in no list" is the question it exists to answer.'
);
chk(
	'and being unlisted does not withhold its detail block',
	is_array( $unlisted ) && is_array( $unlisted['detail'] ?? null ),
	'Listed and "has a detail page" are different questions: this job\'s page renders for any '
	. 'visitor who has the link.'
);

// The row exists and holds zero, so the rule's EXISTS clause is satisfied and the
// site lists the job — it just does not sort it first.
$GLOBALS['jpkcom_test_unlisted']                    = [];
$GLOBALS['jpkcom_test_meta_rows'][184]              = [ 'job_featured' ];
$GLOBALS['jpkcom_test_fields'][184]['job_featured'] = false;

$stored_zero = jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] );

chk(
	'a job_featured row storing 0 is listed all the same',
	is_array( $stored_zero ) && true === ( $stored_zero['listed'] ?? null )
	&& false === ( $stored_zero['is_featured'] ?? null )
	&& ! array_key_exists( 'listed_reason', $stored_zero ),
	'listed is an EXISTS test on the meta ROW, not on its value, and get_field() cannot tell a '
	. 'missing row from a stored zero. Reading the value instead reports every unfeatured job as '
	. 'unlisted — and a reason next to listed:true is a contradiction in the same record.'
);

echo "\nNo detail page, no detail data\n";

$GLOBALS['jpkcom_test_fields'][184] = [ 'job_url' => [ 'url' => 'https://ats.example.test/apply/1' ] ];

$redirected = jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] );

chk(
	'url is the effective destination when one is set',
	is_array( $redirected ) && 'https://ats.example.test/apply/1' === ( $redirected['url'] ?? null )
	&& true === ( $redirected['redirects_externally'] ?? null ),
	'job_url is \'\' or false when unset, never null, so ?? does not catch it and a permalink '
	. 'would be reported for a page that answers 307 to somewhere else entirely.'
);
chk(
	'a job that 307s every visitor away carries no detail block',
	is_array( $redirected ) && ! array_key_exists( 'detail', $redirected ),
	'Salary, postal address, attributes and application data are public only as a side effect of '
	. 'a detail page rendering. For this job no such page exists, so emitting them to any '
	. 'logged-in subscriber publishes data the site has deliberately never shown.'
);
chk( 'and says why', is_array( $redirected ) && 'redirects_externally' === ( $redirected['detail_omitted_reason'] ?? null ) );
chk(
	'while the compact record is answered in full',
	is_array( $redirected ) && 184 === ( $redirected['id'] ?? null ) && '' !== ( $redirected['title'] ?? '' )
	&& array_key_exists( 'is_closed', $redirected ) && array_key_exists( 'listed', $redirected ),
	'Withholding the record entirely would leave an agent unable to answer why the job appears '
	. 'in no list, which is what get-job is for.'
);

// A spelling the rule and PHP both read the same way, so both exclude it. That
// agreement is the normal case and the fixture has to say so: an "expired but
// listed" record is a state no site can produce.
$GLOBALS['jpkcom_test_fields'][184] = [ 'job_expiry_date' => '2025-11-30' ];
$GLOBALS['jpkcom_test_unlisted']    = [ 184 ];

$expired = jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] );

chk(
	'an expired job carries no detail block either',
	is_array( $expired ) && ! array_key_exists( 'detail', $expired )
	&& 'expired' === ( $expired['detail_omitted_reason'] ?? null )
);
chk(
	'and is resolvable with both of its reasons',
	is_array( $expired ) && true === ( $expired['is_expired'] ?? null )
	&& false === ( $expired['listed'] ?? null ) && 'expired' === ( $expired['listed_reason'] ?? null )
);

echo "\nlisted is answered by the visibility query, not by a paraphrase of it\n";

// The state where a PHP re-derivation and the query part company. MariaDB casts
// '2025-11-30 00:00:00' to the DATE 2025-11-30 — measured on the reference
// instance — so the rule's own comparison finds the job expired and drops it,
// while jpkcom_acf_jobs_normalise_date() refuses the value outright and every
// PHP reading of it concludes "not expired".
$GLOBALS['jpkcom_test_fields'][184]     = [ 'job_expiry_date' => '2025-11-30 00:00:00' ];
$GLOBALS['jpkcom_test_meta_rows'][184]  = [ 'job_featured' ];
$GLOBALS['jpkcom_test_unlisted']        = [ 184 ];
$GLOBALS['jpkcom_test_queries']         = [];

$verdict       = jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] );
$verdict_query = $GLOBALS['jpkcom_test_queries'][0] ?? [];
$verdict_cost  = count( $GLOBALS['jpkcom_test_queries'] );
// Read through a variable and guarded with is_array(): query-jobs answers with a
// WP_Error whenever its own guards refuse, and indexing one as an array is a
// fatal that takes every later assertion in this file with it.
$listing         = jpkcom_acf_jobs_ability_query_jobs( [ 'per_page' => 50 ] );
$listed_by_query = is_array( $listing )
	&& in_array( 184, array_column( (array) ( $listing['jobs'] ?? [] ), 'id' ), true );

chk(
	'get-job and query-jobs agree about whether a job is listed',
	is_array( $verdict ) && ( $verdict['listed'] ?? null ) === $listed_by_query,
	'Two abilities in one feature answering the same question differently is the defect this '
	. 'assertion exists for. listed is defined as "does this job satisfy the visibility rule", and '
	. 'the visibility rule is jpkcom_acf_jobs_build_job_query_args() — not a PHP paraphrase of it, '
	. 'which cannot see a DATE cast and drifts the moment the rule changes. get-job said '
	. var_export( $verdict['listed'] ?? null, true ) . ', query-jobs said '
	. var_export( $listed_by_query, true )
);
chk(
	'get-job asks the shared rule about this one job',
	[ 184 ] === ( $verdict_query['post__in'] ?? null )
	&& 'job' === ( $verdict_query['post_type'] ?? null )
	&& 'publish' === ( $verdict_query['post_status'] ?? null )
	&& false === ( $verdict_query['has_password'] ?? null )
	&& 'job_featured' === ( $verdict_query['meta_key'] ?? null )
	&& is_array( $verdict_query['meta_query'] ?? null ),
	'It has to be the same arguments the archive and the shortcode run, restricted to one id. A '
	. 'locally assembled meta_query would be a second copy of the rule and would drift from it '
	. 'exactly as the PHP paraphrase did.'
);
chk(
	'and the verdict query fetches one id and no total',
	'ids' === ( $verdict_query['fields'] ?? null ) && 1 === ( $verdict_query['posts_per_page'] ?? null )
	&& true === ( $verdict_query['no_found_rows'] ?? null ) && 1 === $verdict_cost,
	'One bounded query on a single-record ability. Nothing here needs a total, no post row has to '
	. 'come back, and the presence or absence of the id IS the answer.'
);
chk(
	'a reason PHP cannot establish is reported as unknown rather than guessed',
	is_array( $verdict ) && false === ( $verdict['listed'] ?? null )
	&& 'unknown' === ( $verdict['listed_reason'] ?? null ),
	'listed_reason stays a PHP determination because it is an explanation rather than a verdict, '
	. 'but it may never contradict the verdict. Here the rule excluded the job for a reason no PHP '
	. 'reading of the stored value can see, and an honest "unknown" beats a confident wrong one.'
);

// What the verdict is NOT: agreement with whatever query-jobs happens to return on
// a site that filters that response. The filter must not reach the query that
// decides the verdict at all — asserted on the query that ran rather than on a
// narrowed listing, because query-jobs no longer carries a query var a callback
// hands it and post__in would never have arrived either way.
$GLOBALS['jpkcom_test_meta_rows'][184] = [ 'job_featured' ];
$GLOBALS['jpkcom_test_fields'][184]    = [];
$GLOBALS['jpkcom_test_unlisted']       = [];
$GLOBALS['jpkcom_test_queries']        = [];

$GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] = static function ( array $args ): array {
	$args['post__in']   = [ 999999 ];
	$args['meta_query'] = [];

	return $args;
};

$verdict_stands = jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] );
$verdict_ran    = $GLOBALS['jpkcom_test_queries'][0] ?? [];

unset( $GLOBALS['jpkcom_test_filters']['jpkcom_acf_jobs_ability_query_args'] );

chk(
	'the filter never reaches the query the verdict comes from',
	is_array( $verdict_stands ) && true === ( $verdict_stands['listed'] ?? null )
	&& [ 184 ] === ( $verdict_ran['post__in'] ?? null )
	&& is_array( $verdict_ran['meta_query'] ?? null ) && [] !== $verdict_ran['meta_query'],
	'jpkcom_acf_jobs_ability_query_args exists so a site can shape what query-jobs lists. Applying '
	. 'it to a verdict ABOUT the site\'s rule would let a callback make that verdict disagree with '
	. 'the rule it reports on. The callback above empties the rule and redirects the lookup; '
	. 'neither may show up in the query that answers listed.'
);

// The mirror image: the rule returns the job while the PHP reading found a reason
// it should not be listed. The verdict wins, and the contradiction is removed
// rather than published.
$GLOBALS['jpkcom_test_meta_rows'][184] = [];
$GLOBALS['jpkcom_test_unlisted']       = [];

$contradiction = jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] );

chk(
	'a reason is never published beside listed:true',
	is_array( $contradiction ) && true === ( $contradiction['listed'] ?? null )
	&& ! array_key_exists( 'listed_reason', $contradiction ),
	'The reason is an explanation of the verdict. Carrying one that explains the opposite verdict '
	. 'is a record that contradicts itself in two adjacent properties, and a client has no way to '
	. 'tell which half to believe.'
);

$GLOBALS['jpkcom_test_meta_rows'][184] = [ 'job_featured' ];

echo "\nA stored date nobody can read is not a date that says \"never expires\"\n";

// Every one of these is a value redirects.php compares as a raw string and finds
// expired, and every one of them is a value jpkcom_acf_jobs_normalise_date()
// refuses: it accepts Ymd and Y-m-d and nothing else, by design. The gap between
// those two facts is where the detail block of an expired job would be published.
$unreadable_dates = [
	'a datetime'          => '2025-11-30 00:00:00',
	'a padded date'       => ' 2025-11-30 ',
	'slashes'             => '2025/11/30',
	'a German date'       => '30.11.2025',
	'an unreadable value' => 'irgendwann',
];

foreach ( $unreadable_dates as $label => $stored ) {

	$GLOBALS['jpkcom_test_fields'][184] = [ 'job_expiry_date' => $stored ];

	chk(
		"an expiry stored as {$label} withholds the detail page",
		false === jpkcom_acf_jobs_detail_page_renders( 184 ),
		'normalise_date() answers null both for "this job does not expire" and for "I cannot read '
		. 'this", and only the first of those means the page renders. Reading the second as the '
		. 'first publishes the postal address, the salary and the application data of a job that '
		. 'redirects every non-editor to the archive — the exact state the rule exists for. '
		. 'Stored value: ' . var_export( $stored, true )
	);

}

// A value in the FUTURE that still does not parse. The site would render this
// page, so withholding is a real cost — and it is still the only safe answer,
// because the same string comparison that decides it correctly here is the one
// that decides '30.11.2025' incorrectly.
$GLOBALS['jpkcom_test_fields'][184] = [ 'job_expiry_date' => '2027-11-30 00:00:00' ];

chk(
	'an unreadable future date is withheld too, rather than guessed at',
	false === jpkcom_acf_jobs_detail_page_renders( 184 ),
	'Undecidable is not the same as "not expired". Falling back to the raw string comparison '
	. 'redirects.php uses would decide this one correctly and 30.11.2025 wrongly, which is the '
	. 'guess this predicate exists to avoid making.'
);

$GLOBALS['jpkcom_test_fields'][184] = [ 'job_expiry_date' => '' ];

chk(
	'while a genuinely absent expiry still renders',
	true === jpkcom_acf_jobs_detail_page_renders( 184 ),
	'Without this the whole group above would pass against a predicate that withholds every job '
	. 'that carries the field at all.'
);

// The one state in which the reader and the site still disagree after this round,
// and therefore the only one that can show whether get-job re-decides the
// disclosure question rather than inheriting the reader's answer.
$GLOBALS['jpkcom_test_fields'][184] = [ 'job_expiry_date' => '2025-11-30 00:00:00' ];

$reader_would = jpkcom_acf_jobs_get_job_data( 184, true );
$ability_says = jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] );

chk(
	'the reader on its own would publish the detail block for an unreadable expiry',
	is_array( $reader_would['detail'] ?? null ) && false === ( $reader_would['is_expired'] ?? null ),
	'Not a statement about desired behaviour — it is what makes the next assertion a test rather '
	. 'than a restatement. is_expired answers a question of fact and has no safe default, so the '
	. 'reader reports "not expired" for a date it cannot read.'
);
chk(
	'get-job withholds it anyway',
	is_array( $ability_says ) && ! array_key_exists( 'detail', $ability_says ),
	'The disclosure question does have a safe default, and this is it. includes/jobs-data.php is '
	. 'resolved through the plugin\'s file override chain, so a site can replace it outright; the '
	. 'decision is therefore re-taken here rather than inherited, and it can only ever narrow '
	. 'what the reader returned.'
);
chk(
	'and says that the page does not render',
	is_array( $ability_says ) && 'no_public_detail_page' === ( $ability_says['detail_omitted_reason'] ?? null )
);

echo "\nThe withheld values, not only the withheld key\n";

// A salary and a street nobody may see, on a job whose page does not render.
$GLOBALS['jpkcom_test_fields'][183] = [
	'job_location_place'  => 'Teststadt',
	'job_location_street' => 'Geheimstrasse 7',
	'job_location_zip'    => '01067',
];

$secret_fields = [
	'job_company'           => [ 182 ],
	'job_location'          => [ 183 ],
	'job_base_salary_group' => [
		'job_salary'          => 424242,
		'job_salary_currency' => 'EUR',
		'job_salary_period'   => 'MONTH',
	],
];

$GLOBALS['jpkcom_test_fields'][184] = $secret_fields;

$published = (string) json_encode( jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] ) );

chk(
	'a job whose page renders does publish the salary and the street',
	str_contains( $published, '424242' ) && str_contains( $published, 'Geheimstrasse 7' ),
	'The control for the three assertions below. Without it they would all pass against a reader '
	. 'that never emits an address or a salary at all.'
);

$suppressed = [
	'a job that redirects externally' => [ 'job_url' => [ 'url' => 'https://ats.example.test/apply/1' ] ],
	'an expired job'                  => [ 'job_expiry_date' => '2025-11-30' ],
	'a job with an unreadable expiry' => [ 'job_expiry_date' => '2025-11-30 00:00:00' ],
];

foreach ( $suppressed as $label => $extra ) {

	$GLOBALS['jpkcom_test_fields'][184] = array_merge( $secret_fields, $extra );

	$response = jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] );
	$encoded  = (string) json_encode( $response );

	chk(
		"{$label} publishes neither the salary nor the street anywhere in the response",
		is_array( $response ) && ! str_contains( $encoded, '424242' ) && ! str_contains( $encoded, 'Geheimstrasse 7' ),
		'Asserting that the "detail" KEY is gone is not the same statement. A record that carried '
		. 'the same values under another key, or in a diagnostic field, would satisfy the weaker '
		. 'assertion and publish exactly what the rule exists to withhold.'
	);
	chk(
		"and {$label} still says why",
		is_array( $response ) && is_string( $response['detail_omitted_reason'] ?? null )
		&& '' !== $response['detail_omitted_reason'],
		'Withholding without saying so leaves an agent unable to tell "this job has no salary" '
		. 'from "you were not shown its salary".'
	);

}

echo "\nurl is the destination a visitor is actually sent to\n";

$destinations = [
	'an absolute url'      => [ 'https://ats.example.test/apply/1', 'https://ats.example.test/apply/1', true ],
	// redirects.php runs a value carrying neither the site URL nor a scheme
	// through home_url() before dispatching it.
	'a site-relative url'  => [ '/bewerben', 'https://example.test/bewerben', true ],
	'a whitespace url'     => [ "\t \n", "https://example.test/\t \n", true ],
	// empty() is false for "0", so redirects.php does NOT redirect and the page
	// renders like any other.
	'a url of "0"'         => [ '0', 'https://example.test/job/184/', false ],
];

foreach ( $destinations as $label => $case ) {

	$GLOBALS['jpkcom_test_fields'][184] = [ 'job_url' => [ 'url' => $case[0] ] ];

	$record = jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] );

	chk(
		"{$label} is reported as the effective destination",
		is_array( $record ) && $case[1] === ( $record['url'] ?? null )
		&& $case[2] === ( $record['redirects_externally'] ?? null ),
		'Spec 5.3: url is the effective destination. A permalink reported for a page that answers '
		. '307, or a relative fragment reported as a URL, is a destination an agent forwards to a '
		. 'user and that goes somewhere else entirely. Got '
		. var_export( $record['url'] ?? null, true ) . ' / '
		. var_export( $record['redirects_externally'] ?? null, true )
	);
	chk(
		"{$label} carries a detail block only when its page renders",
		is_array( $record ) && $case[2] === ! array_key_exists( 'detail', $record ),
		'The two answers are one decision: the page either renders for a visitor or it redirects, '
		. 'and the URL half and the disclosure half may not disagree about which it is.'
	);

}

// The pair is one statement about one job, so no stored value of any type may
// make its two halves disagree. Case-by-case expectations cannot say that: they
// only cover the shapes somebody thought of, and the shape that broke this was
// the one nobody did.
$permalink = 'https://example.test/job/184/';

$hostile_urls = [
	'an absolute url'        => [ 'url' => 'https://ats.example.test/apply/1' ],
	'a relative url'         => [ 'url' => '/bewerben' ],
	'a whitespace url'       => [ 'url' => "\t \n" ],
	'a url of "0"'           => [ 'url' => '0' ],
	'an empty url'           => [ 'url' => '' ],
	'a nested array'         => [ 'url' => [ 'nested' ] ],
	'an integer'             => [ 'url' => 42 ],
	'an object'              => [ 'url' => new stdClass() ],
	'a missing url key'      => [ 'title' => 'Bewerben' ],
	'an empty array'         => [],
	'a bare string'          => 'https://bare-string.example/',
	'the ACF unset value'    => '',
	'the other unset value'  => false,
	'a null url'             => [ 'url' => null ],
];

$incoherent = [];

foreach ( $hostile_urls as $label => $stored ) {

	$GLOBALS['jpkcom_test_fields'][184] = [ 'job_url' => $stored ];

	$record = jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] );

	if ( ! is_array( $record ) ) {
		$incoherent[] = $label . ' produced no record at all';
		continue;
	}

	$says_redirect = $record['redirects_externally'] ?? null;
	$says_url      = $record['url'] ?? null;

	if ( ! is_bool( $says_redirect ) || ! is_string( $says_url ) ) {
		$incoherent[] = $label . ' produced ' . var_export( $says_url, true ) . ' / ' . var_export( $says_redirect, true );
		continue;
	}

	// No row in this table names the job's own address, so a permalink here can
	// only be a fallback rather than a destination that happens to be the same.
	$coherent = $says_redirect ? ( $says_url !== $permalink ) : ( $says_url === $permalink );

	if ( ! $coherent ) {
		$incoherent[] = $label . ' says url=' . var_export( $says_url, true )
			. ' beside redirects_externally=' . var_export( $says_redirect, true );
	}
}

chk(
	'url and redirects_externally never disagree, whatever is stored',
	[] === $incoherent,
	'A record claiming the job redirects while handing out its own permalink sends an agent to a '
	. 'page that answers 307, and one claiming it does not redirect while handing out a foreign '
	. 'address does the opposite. Both halves come from one decision and have to stay one. '
	. implode( '; ', $incoherent )
);

// The shape of the answer for a target this plugin cannot resolve to a URL, so
// the assertion above cannot be satisfied by inventing any old string.
$GLOBALS['jpkcom_test_fields'][184] = [ 'job_url' => [ 'url' => [ 'nested' ] ] ];

$unusable = jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] );

chk(
	'an unresolvable redirect target is reported as empty, not as the permalink',
	is_array( $unusable ) && '' === ( $unusable['url'] ?? null )
	&& true === ( $unusable['redirects_externally'] ?? null )
	&& ! array_key_exists( 'detail', $unusable ),
	'! empty() is true for this value, so redirects.php enters its redirect branch and hands an '
	. 'array to strpos() under strict_types: that page answers 500 and serves nothing. An empty '
	. 'url says "it goes somewhere I cannot name"; the permalink would say "go here", which is the '
	. 'one place it demonstrably does not go.'
);

// A job whose external URL resolves to its own address. redirects_externally is
// true and url equals the permalink, and that is not the contradiction above: it
// is a true statement about a job that redirects to itself.
$GLOBALS['jpkcom_test_fields'][184] = [ 'job_url' => [ 'url' => '/job/184/' ] ];

$self_referential = jpkcom_acf_jobs_ability_get_job( [ 'id' => 184 ] );

chk(
	'a job that redirects to its own address reports that, rather than being normalised away',
	is_array( $self_referential ) && $permalink === ( $self_referential['url'] ?? null )
	&& true === ( $self_referential['redirects_externally'] ?? null ),
	'The equality here is the resolved destination, not a fallback. Forcing the pair apart to '
	. 'satisfy a naive invariant would hide a job the site 307s to itself in a loop.'
);

$GLOBALS['jpkcom_test_fields'][183] = [];
$GLOBALS['jpkcom_test_fields'][184] = [];

echo "\nOne answer for absent and for unreadable\n";

$GLOBALS['jpkcom_test_fields'][184] = [];

// Every one of these is a different reason, and each of them is a fact about this
// site that get-job must not disclose to a subscriber probing IDs.
$probes = [
	'an id no post carries'    => 999999,
	'a draft job'              => 5,
	'a post of another type'   => 700,
	'a password-protected job' => 701,
	'a published company'      => 182,
	'id 0'                     => 0,
	'a negative id'            => -7,
	'a non-numeric string'     => 'abc',
	'a fractional id'          => 184.5,
	'an array'                 => [ 184 ],
	'a boolean'                => true,
	'null'                     => null,
	// Never cast, only inspected: a cast of an object is a warning at best and a
	// Throwable at worst, and a Throwable out of an ability callback is an
	// uncaught fatal on the 6.9 floor.
	'an object'                => new stdClass(),
];

$fingerprints = [];

foreach ( $probes as $label => $probe ) {

	$answer = jpkcom_acf_jobs_ability_get_job( [ 'id' => $probe ] );

	chk(
		"{$label} is refused with a 404",
		$answer instanceof WP_Error && 'jpkcom_acf_jobs_job_not_found' === $answer->get_error_code()
		&& 404 === ( $answer->get_error_data()['status'] ?? null ),
		'Not a 500 and not a 200 with an empty record: an agent has to be able to tell "there is '
		. 'nothing here" from "this site is broken" in one turn.'
	);

	if ( $answer instanceof WP_Error ) {
		$fingerprints[ $label ] = $answer->get_error_code() . '|' . $answer->get_error_message()
			. '|' . var_export( $answer->get_error_data(), true );
	}
}

chk(
	'all thirteen refusals are indistinguishable from one another',
	1 === count( array_unique( $fingerprints ) ) && count( $probes ) === count( $fingerprints )
	// Named explicitly, or twelve identical 501 placeholders would satisfy this.
	&& str_contains( (string) reset( $fingerprints ), 'jpkcom_acf_jobs_job_not_found' ),
	'Any difference at all — a different code, a different word, a different status — turns '
	. 'get-job into a way for every logged-in subscriber to find out which post IDs this site '
	. 'holds and what type they are. Distinct answers: ' . implode( ' / ', array_keys( array_unique( $fingerprints ) ) )
);

$GLOBALS['jpkcom_test_queries'] = [];

$GLOBALS['jpkcom_test_get_post_calls'] = 0;

jpkcom_acf_jobs_ability_get_job( [ 'id' => 5 ] );

$cost_of_a_draft = $GLOBALS['jpkcom_test_get_post_calls'];

$GLOBALS['jpkcom_test_get_post_calls'] = 0;

jpkcom_acf_jobs_ability_get_job( [ 'id' => 999999 ] );

$cost_of_nothing = $GLOBALS['jpkcom_test_get_post_calls'];

chk(
	'and neither of them costs more work than the other',
	1 === $cost_of_a_draft && 1 === $cost_of_nothing && [] === $GLOBALS['jpkcom_test_queries'],
	'An answer is given away by what it costs as readily as by what it says. Both paths stop at '
	. 'the reader\'s gate after exactly one post lookup and run no query at all — a second lookup '
	. 'on one of the two branches is a timing signal that says the post is there. Measured: '
	. $cost_of_a_draft . ' and ' . $cost_of_nothing . ' lookups.'
);

// The three integral spellings a JSON id can arrive in. Core's own integer check
// accepts all of them, and over REST it sanitises them to int before the callback
// runs — but an in-process caller (WP-CLI, another plugin, the MCP adapter's own
// path) reaches the callback with whatever it sent, and answering 404 for a job
// that exists is indistinguishable from answering 404 for one that does not.
$spellings = [
	'an integer'         => 184,
	'an integral float'  => 184.0,
	'a numeric string'   => '184',
	'a padded numeric string' => ' 184 ',
];

foreach ( $spellings as $label => $spelling ) {

	$resolved = jpkcom_acf_jobs_ability_get_job( [ 'id' => $spelling ] );

	chk(
		"{$label} resolves to the job",
		is_array( $resolved ) && 184 === ( $resolved['id'] ?? null ),
		'Deleting the branch that handles this leaves the suite green and every caller sending '
		. 'that spelling with a 404 for a job that is right there. Sent: '
		. var_export( $spelling, true )
	);

}

chk(
	'a missing id is a 400 naming the parameter, not a 404',
	( static function (): bool {
		$answer = jpkcom_acf_jobs_ability_get_job( [] );

		return $answer instanceof WP_Error && 400 === ( $answer->get_error_data()['status'] ?? null )
			&& str_contains( $answer->get_error_message(), 'id' );
	} )(),
	'This one says nothing about which IDs exist, and answering "no such job" for a call that '
	. 'named no job at all would send an agent looking for a better ID instead of a better call.'
);
chk(
	'input that is not an object at all is a 400 too',
	jpkcom_acf_jobs_ability_get_job( 'nonsense' ) instanceof WP_Error
	&& 400 === ( jpkcom_acf_jobs_ability_get_job( 'nonsense' )->get_error_data()['status'] ?? null )
);

$GLOBALS['jpkcom_test_fields'][184]     = [];
$GLOBALS['jpkcom_test_meta_rows'][184]  = [];
$GLOBALS['jpkcom_test_rows'][184]       = [];
$GLOBALS['jpkcom_test_attachments']     = [];

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
	'no message in the ability path distinguishes absent from unreadable',
	'/does not exist|no such job/i',
	'Two different messages for "absent" and "not readable" turn get-job into a probe for which '
	. 'IDs exist, callable by any logged-in subscriber. One message, one code — and the wording '
	. 'that would break it is the wording that comes most naturally.'
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

/**
 * Assert that the verdict path CALLS the shared builder instead of reproducing it.
 *
 * Every other assertion about that query checks its OUTPUT, and an accurate
 * hand-written copy of the rule produces the same output — measured: a complete
 * inline copy inside jpkcom_acf_jobs_ability_job_is_listed() left the whole suite
 * green. So the property the design actually claims, that a later change to the
 * rule reaches this answer automatically, was unguarded.
 *
 * PHP cannot redefine a function in place, so the check runs in a child process
 * where a spy is defined BEFORE includes/jobs-data.php: that file wraps its own
 * definition in function_exists(), so the spy becomes the builder for that process
 * and returns a marker no copy of the rule contains. The marker reaching WP_Query
 * is proof of the call itself rather than of the shape of its result.
 *
 * The child runs its program through eval(). That program is the constant heredoc
 * below — no input of any kind reaches it, and the base64 is there only so the
 * source survives shell quoting intact, not as any kind of protection. A temp file
 * would be the alternative and would leave one behind on a failed run.
 */
function the_verdict_query_calls_the_builder(): void {
	global $pass, $fail, $root;

	$tests = __DIR__;

	if ( ! function_exists( 'shell_exec' ) ) {
		$fail++;
		echo "  FAIL  the verdict query calls the shared builder\n";
		echo "        shell_exec() is unavailable, so this could not be checked. Reported as a\n";
		echo "        failure on purpose: an unverifiable guarantee is the thing this assertion\n";
		echo "        exists to prevent.\n";
		return;
	}

	$code = <<<SPY
	function jpkcom_acf_jobs_build_job_query_args( array \$args = [] ): array {
		\$GLOBALS['spy_calls'][] = \$args;

		return [
			'post_type'             => 'job',
			'post_status'           => 'publish',
			'meta_key'              => 'job_featured',
			'meta_query'            => [],
			'jpkcom_builder_marker' => 'called',
		];
	}

	require_once '{$tests}/lib.php';
	define( 'JPKCOM_ACFJOBS_ABILITIES', true );
	require_once '{$root}/includes/jobs-data.php';
	require_once '{$root}/includes/abilities.php';

	\$GLOBALS['jpkcom_test_meta_rows'][184] = [ 'job_featured' ];
	\$GLOBALS['jpkcom_test_queries']        = [];

	jpkcom_acf_jobs_ability_job_is_listed( 184 );

	\$asked = \$GLOBALS['jpkcom_test_queries'][0] ?? [];

	echo count( \$GLOBALS['spy_calls'] ?? [] ), '|', ( \$asked['jpkcom_builder_marker'] ?? 'absent' );
	SPY;

	$command = escapeshellarg( PHP_BINARY ) . ' -r '
		. escapeshellarg( 'eval(base64_decode("' . base64_encode( $code ) . '"));' ) . ' 2>&1';

	$observed = trim( (string) shell_exec( $command ) );

	if ( '1|called' === $observed ) {
		$pass++;
		echo "  PASS  the verdict query calls the shared builder\n";
		return;
	}

	$fail++;
	echo "  FAIL  the verdict query calls the shared builder\n";
	echo "        Expected the builder to be called exactly once and its result to reach WP_Query\n";
	echo "        (\"1|called\"), got \"{$observed}\". A second copy of the visibility rule answers\n";
	echo "        correctly until the rule changes, and then answers a question nobody asked.\n";
}

the_verdict_query_calls_the_builder();

// ---------------------------------------------------------------------------
// The clause guarantee has to be checked against what RAN, not what was passed
// ---------------------------------------------------------------------------
//
// jpkcom_acf_jobs_ability_query_divergence() compares the committed clauses
// against $args — before new WP_Query( $args ). pre_get_posts fires INSIDE
// get_posts(), holds the query by reference, and WP_Query::set() replaces rather
// than merges, so a site callback that does $q->set( 'meta_query', ... ) without
// an is_main_query() guard removed every clause the ability built and the check
// above could not see it. Measured: HTTP 200, filters.job_type still
// ["FULL_TIME"], the executed statement carrying no job_type LIKE and no expiry
// clause, and both "read what came back" checks passing by construction —
// count(posts) 5 is not greater than per_page 5, and total 42 is not less than 5.
// That is verbatim the defect the feature exists to prevent.
//
// The fix is the same comparison against the query object's own query_vars after
// the run, which do reflect the hook. Not a new mechanism and not a list of vars
// to watch: the previous four attempts each enumerated a property and each was
// broken one layer deeper, and the fifth replaced the list with one question —
// is the query that ran the query this ability built. This asks that question of
// the right artifact.

$GLOBALS['jpkcom_test_pre_get_posts'] = static function ( array $args ): array {
	// The ordinary additive idiom, without an is_main_query() guard. A benign
	// site snippet, not a misbehaving plugin — the plugin's own archive.php
	// registers a callback of this shape.
	$args['meta_query'] = [ [ 'key' => 'something_else', 'compare' => 'EXISTS' ] ];

	return $args;
};

$hijacked = jpkcom_acf_jobs_ability_query_jobs( [ 'job_type' => [ 'FULL_TIME' ], 'per_page' => 5 ] );

$GLOBALS['jpkcom_test_pre_get_posts'] = null;

chk(
	'a pre_get_posts that replaces meta_query is refused, not answered',
	$hijacked instanceof WP_Error,
	'Answering would hand an MCP client the whole corpus labelled as a filtered result. The '
	. 'existing argument-level check passes here by construction, because the argument array '
	. 'it inspects is not what ran.'
);

chk(
	'and it is reported as a site condition the caller cannot fix',
	$hijacked instanceof WP_Error
		&& 500 === ( $hijacked->get_error_data()['status'] ?? null )
		&& 'jpkcom_acf_jobs_filter_not_applied' === $hijacked->get_error_code(),
	'Same code and status as the argument-level divergence: it is the same guarantee failing, '
	. 'and nothing the caller sends can cause or avoid it.'
);

$GLOBALS['jpkcom_test_pre_get_posts'] = static function ( array $args ): array {
	// Adding is explicitly allowed — the contract is that a site may add to the
	// query and may not alter what it already asks.
	$args['meta_query'][] = [ 'key' => 'extra', 'compare' => 'EXISTS' ];

	return $args;
};

$added = jpkcom_acf_jobs_ability_query_jobs( [ 'job_type' => [ 'FULL_TIME' ], 'per_page' => 5 ] );

$GLOBALS['jpkcom_test_pre_get_posts'] = null;

chk(
	'a pre_get_posts that only ADDS a clause is still answered',
	is_array( $added ),
	'The guarantee is that the ability\'s own clauses survive unchanged, not that the site may '
	. 'not participate. A guard that refused any site callback would break the documented '
	. 'jpkcom_acf_jobs_ability_query_args contract as a side effect.'
);


// ---------------------------------------------------------------------------
// What the surface declares and what it will accept must be the same thing
// ---------------------------------------------------------------------------

$d = jpkcom_acf_jobs_get_ability_definitions();

// 1. An input schema that is not valid JSON Schema. The helper for exactly this
//    hazard exists in this file and was applied to `default` — the one key core's
//    REST list controller already repairs — and not to `properties`, the one key
//    only the plugin can repair. The MCP adapter publishes get_input_schema()
//    verbatim, so a client that validates a tool's inputSchema before offering it
//    rejects list-filters, and a client that rejects the whole tools/list on one
//    bad entry loses query-jobs and get-job with it — the two abilities whose
//    vocabulary list-filters exists to supply.
$lf_schema = json_encode( $d['jpkcom-acf-jobs/list-filters']['input_schema'] );

chk(
	'list-filters declares no properties key at all, and refuses extras',
	! str_contains( (string) $lf_schema, '"properties"' )
		&& str_contains( (string) $lf_schema, '"additionalProperties":false' ),
	'This assertion used to demand "properties":{} — an empty stdClass — which is what made any '
	. 'unauthenticated request carrying any input key answer HTTP 500: core does an array offset '
	. 'on that value inside rest_validate_object_value_from_schema(), and an array offset on a '
	. 'plain object is a hard Error in PHP 8. stdClass PLUS additionalProperties:false still '
	. 'fatals; omitting the key is the only combination measured to yield a clean WP_Error. The '
	. 'previous version of this check did not merely miss the defect, it mandated it.'
);

foreach ( $d as $name => $def ) {
	// This assertion used to check the ENCODED SHAPE of the schema — that
	// `properties` serialised as {} rather than []. It passed, 489 green, while
	// the release answered HTTP 500 to any unauthenticated request carrying any
	// input key: the stdClass that produces "{}" is an array offset target inside
	// core's rest_validate_object_value_from_schema(), and an array offset on a
	// plain object is a hard Error in PHP 8. The guard did not merely miss the
	// defect, it MANDATED the shape that caused it.
	//
	// So the question is no longer what the schema looks like. It is whether the
	// ability answers an unknown key with a value.
	$probe = null;
	$threw = false;

	try {
		$probe = call_user_func( $def['execute_callback'], [ 'zzz_no_such_key' => 1 ] );
	} catch ( Throwable $e ) {
		$threw = true;
	}

	chk(
		"{$name}: an unknown input key produces a value, not a Throwable",
		! $threw,
		'The failure this replaces happened inside check_ability_permissions(), two frames '
		. 'above jpkcom_acf_jobs_ability_boundary(), which is therefore structurally unable to '
		. 'catch it — and it needed no credentials.'
	);

	chk(
		"{$name}: and that value is a refusal the caller can act on",
		$probe instanceof WP_Error && 400 === ( $probe->get_error_data()['status'] ?? null ),
		'An unknown key must be a caller error naming what was rejected, on every ability — a '
		. 'guard on one of three is a trap.'
	);
}

// 2. The only switch that changes which jobs come back could not be sent at all.
//    The run route is GET-only because readonly is true, GET carries strings, and
//    the callback demanded a strict bool — so the declared default itself was a
//    400, and the message named a form the caller had no way to produce. An agent
//    retries true, "true", 1, on and exhausts its budget: the correction loop
//    cannot terminate. Measured: all four spellings 400, POST 405.
foreach ( [ 'true' => true, '1' => true, 'false' => false, '0' => false ] as $sent => $meant ) {
	$answer = jpkcom_acf_jobs_ability_query_jobs( [ 'include_closed' => $sent ] );

	chk(
		"include_closed accepts the string \"{$sent}\" that GET can actually carry",
		is_array( $answer ) && $meant === ( $answer['filters']['include_closed'] ?? null ),
		'A boolean the only supported method cannot express is not a parameter, it is a trap. '
		. 'These are core\'s own rest_sanitize_boolean spellings, so the two consumers stop '
		. 'disagreeing about what the ability can do.'
	);
}

chk(
	'a real boolean still works, for the MCP consumer that can send one',
	is_array( $ic = jpkcom_acf_jobs_ability_query_jobs( [ 'include_closed' => false ] ) )
		&& false === ( $ic['filters']['include_closed'] ?? null )
);

chk(
	'and a value that means nothing is still refused',
	jpkcom_acf_jobs_ability_query_jobs( [ 'include_closed' => 'perhaps' ] ) instanceof WP_Error,
	'Widening the accepted spellings must not turn into accepting anything. "perhaps" has no '
	. 'boolean reading and guessing one would be worse than the 400.'
);

// 3. search is WP_Query's `s`: post_title, post_excerpt, post_content. Every field
//    this plugin holds job text in lives in ACF meta and post_content is empty on
//    its own fixtures, so "job content" named a corpus that does not exist. A model
//    asked "which job mentions a company car" got total 0 with unknown {} — and the
//    unknown description tells it that an empty result is honest. Four jobs matched.
$search_desc = $d['jpkcom-acf-jobs/query-jobs']['input_schema']['properties']['search']['description'] ?? '';

chk(
	'the search description does not claim to reach job content',
	! str_contains( (string) $search_desc, 'job content' ),
	'The one phrase that would have prevented the wrong answer — that search covers titles — '
	. 'is the phrase the description replaced with a broader claim.'
);

chk(
	'and it says where the job text actually is',
	str_contains( (string) $search_desc, 'attribute' ) || str_contains( (string) $search_desc, 'ACF' )
		|| str_contains( (string) $search_desc, 'title' ),
	'A caller told what search does NOT cover still needs to be told which axis does.'
);

// 4. The output schema instructs the model to send a work_type filter, and the
//    input schema has no such property. An unrecognised axis was swallowed whole:
//    same 200, same total as an unfiltered call, unknown {}. A model that trusts
//    total reports the entire corpus as "7 remote-work jobs".
$unknown_axis = jpkcom_acf_jobs_ability_query_jobs( [ 'work_type' => [ 'TELECOMMUTE' ] ] );

chk(
	'an input axis that does not exist is refused rather than ignored',
	$unknown_axis instanceof WP_Error,
	'Returning the unfiltered corpus behind a 200 is the worst of the three possible answers: '
	. 'the caller cannot tell it from a successful filter.'
);

chk(
	'that refusal is a caller mistake and names the axes that do exist',
	$unknown_axis instanceof WP_Error
		&& 400 === ( $unknown_axis->get_error_data()['status'] ?? null )
		&& str_contains( $unknown_axis->get_error_message(), 'work_type' ),
	'Trap 10: a caller mistake is 400. And the message has to name the rejected key, or the '
	. 'correction loop has nothing to work with.'
);

chk(
	'a typo in a real axis is refused too',
	jpkcom_acf_jobs_ability_query_jobs( [ 'compnay' => [ 182 ] ] ) instanceof WP_Error,
	'A single transposed letter had the same silent outcome as a missing feature.'
);

chk(
	'the documented axes all still pass together',
	! ( jpkcom_acf_jobs_ability_query_jobs(
		[ 'job_type' => [ 'FULL_TIME' ], 'page' => 1, 'per_page' => 5, 'order' => 'ASC', 'include_closed' => true ]
	) instanceof WP_Error ),
	'A guard that rejects the documented input would be worse than the defect it closes.'
);

chk(
	'the output no longer advertises an input axis that does not exist',
	! str_contains(
		(string) json_encode( $d['jpkcom-acf-jobs/query-jobs']['output_schema'] ),
		'only form accepted as filter input'
	),
	'"This is the only form accepted as filter input" sat on work_type.value, which is not an '
	. 'input at all. The wording is what put the caller there.'
);

// ---------------------------------------------------------------------------
// The visibility counts must come from the rule, not from a paraphrase of it
// ---------------------------------------------------------------------------
//
// hidden_expired used to be its own meta_query: job_featured EXISTS AND
// job_expiry_date < today, with a comment claiming it was "the mirror image of
// the visibility rule's". It was not. The rule's expiry clause is an OR group —
// >= today, OR the row is absent, OR the value is the empty string — and the
// count carried only the first branch negated. MariaDB casts '' to '0000-00-00',
// which is less than today, so every job whose expiry date is EMPTY was counted
// as expired while the same response listed it. Empty is the ordinary case: ACF
// stores '' once the date field has been saved and cleared. Measured on test2,
// that was 2 of 2 jobs, and list-filters reported 25 listed + 13 expired against
// 37 published — a partition the output schema promises is exact.
//
// This is the same lesson trap 7 already records for `listed`: a PHP or SQL
// paraphrase of the rule disagrees with the rule. The fix is not a better
// paraphrase. Among jobs that have the job_featured row, expiry is the ONLY other
// exclusion the rule applies, so the count is a difference and no second
// implementation of the expiry semantics exists to disagree with the first.
//
// No stub can reproduce the original defect — it lives in MariaDB's cast of an
// empty string — so what is asserted here is the structural property that makes
// it impossible: no query issued for the counts mentions job_expiry_date at all.

$GLOBALS['jpkcom_test_queries'] = [];

// The fixture's own answer to "how many published jobs carry a job_featured
// row" is whatever the stub yields; the assertions below are about the
// arithmetic around it, so they read it rather than assume it.
$with_featured = ( new WP_Query(
	[
		'post_type'     => 'job',
		'post_status'   => 'publish',
		'has_password'  => false,
		'fields'        => 'ids',
		'posts_per_page' => 1,
		'no_found_rows' => false,
		'meta_query'    => [ [ 'key' => 'job_featured', 'compare' => 'EXISTS' ] ],
	]
) )->found_posts;

$GLOBALS['jpkcom_test_queries'] = [];

$vis = jpkcom_acf_jobs_ability_visibility_counts( $with_featured + 2, $with_featured - 1 );

$expiry_clauses = 0;

foreach ( recorded_queries( 'job' ) as $args ) {
	if ( str_contains( json_encode( $args['meta_query'] ?? [] ), 'job_expiry_date' ) ) {
		$expiry_clauses++;
	}
}

chk(
	'the visibility counts issue no expiry comparison of their own',
	0 === $expiry_clauses,
	'A second SQL statement of "expired" is what produced the wrong count, and any rewrite of '
	. 'it would be a third. The rule already answers this question; the counts subtract.'
);

chk(
	'hidden_expired is the shortfall among jobs that have the featured row',
	1 === ( $vis['hidden_expired'] ?? null ),
	'One fewer job is listed than carries the featured row, so exactly one is held back by '
	. 'expiry — derived from the rule rather than re-derived beside it.'
);

chk(
	'hidden_missing_featured is the rest of the shortfall',
	2 === ( $vis['hidden_missing_featured'] ?? null ),
	'10 published, 8 with the featured row. Two independent causes exclude a job with no '
	. 'job_featured row — the EXISTS clause and the meta_key used for ordering — so this '
	. 'number cannot be read off the listing query either.'
);

chk(
	'and the two causes partition the shortfall exactly, as the schema promises',
	( $with_featured + 2 ) === ( $with_featured - 1 )
		+ ( $vis['hidden_missing_featured'] ?? -1 )
		+ ( $vis['hidden_expired'] ?? -1 ),
	'published_total - listed_total - hidden_missing_featured - hidden_expired must be 0. It '
	. 'used to go negative, which is what an agent computing the residual would have seen.'
);

// The block is a statement about the site, and it belongs on the site-level
// ability. Sitting next to a filtered total it invited exactly one misreading,
// measured at every filter: total moved 12 -> 6 -> 2 -> 0 while visibility never
// moved at all, so a model adds the two and reports jobs that do not exist. No
// wording fixes that reliably, because the numbers are simply about something
// else. list-filters carries the same block, correctly worded, and a caller has
// to call it anyway to learn the filter vocabulary.

$listing_now = jpkcom_acf_jobs_ability_query_jobs( null );

chk(
	'query-jobs no longer carries a site-wide visibility block beside a filtered total',
	is_array( $listing_now ) && ! array_key_exists( 'visibility', $listing_now ),
	'It never moved with the filters, while its own schema called it "excluded from this '
	. 'answer". Removing it costs nothing: 1.4.0 has never shipped.'
);

$defs = jpkcom_acf_jobs_get_ability_definitions();

chk(
	'and its output schema does not promise one either',
	! isset( $defs['jpkcom-acf-jobs/query-jobs']['output_schema']['properties']['visibility'] ),
	'A schema that declares a field the response never sends is the same defect pointing the '
	. 'other way.'
);

chk(
	'list-filters still reports it, where it is a statement about the site',
	isset( $defs['jpkcom-acf-jobs/list-filters']['output_schema']['properties']['visibility'] ),
	'The audit of the visibility rule is worth having — on the ability whose whole answer is '
	. 'site-level, and whose wording already said so.'
);

// ---------------------------------------------------------------------------
// A stored value ACF cannot read must not take the ability down
// ---------------------------------------------------------------------------
//
// The throw happens INSIDE ACF, while it reads: acf_maybe_get() for checkbox and
// select, acf_field_flexible_content->load_value() for layout content. Nothing on
// the plugin's side of that call can inspect the value first, and the file's
// "read unformatted" rule does not help — have_rows() has no formatted argument
// at all. So the class cannot be closed by checking shapes; it is closed by not
// letting a Throwable leave a callback, which is the wrapper core only gained in
// 7.0 and this plugin's declared 6.9 floor therefore has to bring itself.
//
// Measured on a checksum-verified 6.9.4 core: one postmeta row holding
// [['FULL_TIME']] made query-jobs a blank 500 for every caller with no input at
// all, permanently, on whichever page the job fell — page 1 answering 200 and
// page 3 answering 500 on the same corpus.

$GLOBALS['jpkcom_test_posts'][ 8801 ] = new WP_Post( 8801, 'Readable job', 'publish', 'job' );
$GLOBALS['jpkcom_test_posts'][ 8802 ] = new WP_Post( 8802, 'Corrupt job', 'publish', 'job' );

$GLOBALS['jpkcom_test_fields'][ 8801 ] = [ 'job_featured' => 1 ];
$GLOBALS['jpkcom_test_fields'][ 8802 ] = [
	'job_featured' => 1,
	// What an importer, a migration or one UPDATE writes, and what CLAUDE.md
	// already warns a WPML base64 round trip produces.
	'job_type'     => new JPKCom_Test_Unreadable_Value(),
];

$threw    = false;
$listing  = null;

try {
	$listing = jpkcom_acf_jobs_ability_query_jobs( null );
} catch ( Throwable $e ) {
	$threw = true;
}

chk(
	'query-jobs does not throw when a job on the page cannot be read',
	! $threw,
	'On the declared 6.9 floor there is no Throwable-to-WP_Error wrapper in core, so an '
	. 'exception out of an ability callback is an uncaught fatal: a blank 500 with no body, '
	. 'no code and nothing an agent can act on, triggerable by any logged-in subscriber.'
);

chk(
	'and it still answers, rather than failing the whole call',
	is_array( $listing ),
	'One corrupt row must not remove the ability for the whole corpus. The failure follows '
	. 'whichever page the job falls on, so a paginating client hits a wall it cannot skip.'
);

$returned_ids = is_array( $listing ) ? array_column( $listing['jobs'] ?? [], 'id' ) : [];

chk(
	'the readable job beside it is still returned',
	in_array( 8801, $returned_ids, true ),
	'Degrading the corrupt record is only worth doing if the rest of the page survives it.'
);

chk(
	'the unreadable job is left out rather than half-built',
	! in_array( 8802, $returned_ids, true ),
	'A partial record would be a job whose type silently disappeared — a wrong answer is '
	. 'worse than a missing one, because nothing marks it as wrong.'
);

chk(
	'and the answer says how many it dropped',
	1 === ( $listing['unreadable_total'] ?? null ),
	'Silently omitting rows reads as "these are all the jobs". The count is scoped to this '
	. 'answer, not to the site, and it names no ID — which one it was is exactly what must '
	. 'not be disclosed, see the get-job assertion below.'
);

// get-job must answer identically for "corrupt" and for "does not exist". The
// gate's guarantee is that the two are indistinguishable, so the ability cannot
// be used to find out which IDs are real.
$unknown_id = jpkcom_acf_jobs_ability_get_job( [ 'id' => 987654 ] );
$corrupt_id = jpkcom_acf_jobs_ability_get_job( [ 'id' => 8802 ] );

chk(
	'get-job on an unreadable job answers exactly as it does on an unknown id',
	$unknown_id instanceof WP_Error
		&& $corrupt_id instanceof WP_Error
		&& $unknown_id->get_error_code() === $corrupt_id->get_error_code()
		&& $unknown_id->get_error_message() === $corrupt_id->get_error_message(),
	'"Does not exist" and "not readable" return the same thing so get-job cannot be used to '
	. 'probe which IDs exist. Giving corrupt rows their own error would repair one defect by '
	. 'reopening another.'
);

chk(
	'get-job does not throw on an unreadable job',
	$corrupt_id instanceof WP_Error,
	'It has to come back as a value rather than as a Throwable, whatever the value says.'
);

// The boundary itself. list-filters has no per-job guard — it reads the company
// and location fields of every job to build its vocabulary — so it is where an
// unforeseen throw actually lands, and it is the right probe for the backstop.
// The backstop exists precisely for paths nobody enumerated; testing it through
// a path we did enumerate would prove less than it appears to.
$GLOBALS['jpkcom_test_fields'][ 184 ]['job_company'] = new JPKCom_Test_Unreadable_Value( 'boundary probe' );

$threw_list = false;
$filters    = null;

try {
	$filters = jpkcom_acf_jobs_ability_list_filters( null );
} catch ( Throwable $e ) {
	$threw_list = true;
}

chk(
	'list-filters does not throw when a stored value cannot be read',
	! $threw_list,
	'This is the path with no per-job guard, so it is the one the callback boundary has to '
	. 'catch. On 6.9 an escaping Throwable is a blank 500; the guarantee the file\'s own '
	. 'docblock already makes — "every callback returns a WP_Error rather than throwing" — '
	. 'was not true before this.'
);

chk(
	'and it reports a server-side condition rather than a caller mistake',
	$filters instanceof WP_Error
		&& 500 === ( $filters->get_error_data()['status'] ?? null ),
	'A corrupt stored value is the site\'s problem and no change to the request fixes it, so '
	. '400 would send an agent into a correction loop that cannot terminate. Without '
	. 'data[status] rest_ensure_response() defaults to 500 anyway — but silently, so the '
	. 'status is set on purpose to survive a refactor.'
);

chk(
	'the message does not hand back the raw engine string',
	$filters instanceof WP_Error
		&& ! str_contains( $filters->get_error_message(), 'Cannot access offset' )
		&& ! str_contains( $filters->get_error_message(), 'boundary probe' ),
	'Before this, the exception text reached any logged-in subscriber over REST and every '
	. 'MCP client as an isError block. A PHP engine message is not something a caller can '
	. 'act on and not something a site owner wants published.'
);

unset( $GLOBALS['jpkcom_test_fields'][ 184 ]['job_company'] );

unset(
	$GLOBALS['jpkcom_test_posts'][ 8801 ],
	$GLOBALS['jpkcom_test_posts'][ 8802 ],
	$GLOBALS['jpkcom_test_fields'][ 8801 ],
	$GLOBALS['jpkcom_test_fields'][ 8802 ],
	$GLOBALS['jpkcom_test_fields'][ 184 ]['job_type']
);

summary();
