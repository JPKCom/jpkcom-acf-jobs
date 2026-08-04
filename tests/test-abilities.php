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

summary();
