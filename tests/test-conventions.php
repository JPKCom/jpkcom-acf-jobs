<?php
/**
 * Source-level regression guards.
 *
 * The two fixes in 1.3.7 are one-liners whose effect depends on WordPress's own
 * timezone and capability handling, so a behavioural unit test would mostly be
 * testing stubs. What actually protects them is making the wrong form
 * impossible to reintroduce unnoticed — that is what this file does, and it is
 * honest about being a source check rather than a behavioural one.
 *
 * Run with:
 *     php tests/test-conventions.php
 *
 * @package JPKCom_ACF_Jobs
 * @since 1.3.7
 */

declare(strict_types=1);

$root     = dirname( __DIR__ );
$includes = $root . '/includes';

$pass = 0;
$fail = 0;

/**
 * Assert that no PHP file under the given roots matches a pattern.
 *
 * @param string $label   Human-readable check name.
 * @param array  $roots   Directories and single files to scan.
 * @param string $pattern PCRE pattern that must not match.
 * @param string $why     Explanation printed on failure.
 * @param string $unless  Optional substring; a matching line is skipped when it also contains this.
 */
function forbid( string $label, array $roots, string $pattern, string $why, string $unless = '' ): void {
	global $pass, $fail;

	$hits  = [];
	$files = [];

	foreach ( $roots as $root ) {
		if ( is_file( $root ) ) {
			$files[] = $root;
			continue;
		}

		if ( ! is_dir( $root ) ) {
			continue;
		}

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $file ) {
			$files[] = $file->getPathname();
		}
	}

	foreach ( $files as $path ) {
		if ( ! str_ends_with( $path, '.php' ) ) {
			continue;
		}

		foreach ( explode( "\n", (string) file_get_contents( $path ) ) as $no => $line ) {
			// Skip comments so the explanatory notes next to each fix do not
			// trip their own guard.
			$trimmed = ltrim( $line );

			if ( str_starts_with( $trimmed, '//' ) || str_starts_with( $trimmed, '*' ) || str_starts_with( $trimmed, '/*' ) ) {
				continue;
			}

			if ( '' !== $unless && str_contains( $line, $unless ) ) {
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
	echo "  FAIL  {$label}\n";
	echo "        {$why}\n";

	foreach ( $hits as $hit ) {
		echo "        {$hit}\n";
	}
}

$scan = [
	$includes,
	$root . '/templates',
	$root . '/debug-templates',
	$root . '/tools',
	$root . '/jpkcom-acf-jobs.php',
];

echo "\nDate handling\n";

forbid(
	'no bare date()/gmdate() for date-only comparisons',
	$scan,
	'/\b(gm)?date\(\s*(format:\s*)?[\'"]Y-m-d[\'"]/',
	'WordPress sets the PHP timezone to UTC (wp-settings.php), so date( \'Y-m-d\' ) and '
	. 'gmdate( \'Y-m-d\' ) return the UTC date and expiry checks lag the site timezone by '
	. 'the offset. A second argument does not help: date( \'Y-m-d\', time() ) is the same '
	. 'bug. Use current_time( \'Y-m-d\' ).',
	// Deliberate exemption, carried over from 1.3.7: schema.php formats a *stored*
	// date with date( 'Y-m-d', strtotime( $expiry ) ). That is a pure round-trip of a
	// date-only string with no reference to "now", so the timezone rule does not apply.
	// It has a separate latent defect — strtotime() returns false for an unparseable
	// value and date() then throws a TypeError under strict_types — which is tracked in
	// the abilities spec §11 and is why no new code may copy that construct.
	'strtotime('
);

echo "\nCapability checks\n";

forbid(
	'no role names passed to current_user_can()',
	$scan,
	'/current_user_can\(\s*[\'"](administrator|editor|author|contributor|subscriber)[\'"]/',
	'current_user_can() accepts a role only by accident — the role is a key in the '
	. 'capability array — which bypasses map_meta_cap and misses differently named '
	. 'roles holding the same rights. Check a capability such as manage_options.'
);

echo "\nTaxonomy slugs\n";

/**
 * Assert that every literal taxonomy argument names a registered taxonomy.
 *
 * The field is named `job_attribute`, the taxonomy `job-attribute`. Passing the
 * former where the latter belongs makes the WordPress term functions return
 * false or an empty array — no error, no warning, the output is just silently
 * missing. A regex cannot catch that; comparing against the slugs actually
 * passed to register_taxonomy() can.
 *
 * @param string $label Human-readable check name.
 * @param array  $dirs  Directories to scan.
 */
function taxonomy_slugs_exist( string $label, array $dirs ): void {
	global $pass, $fail;

	$root = dirname( __DIR__ );

	preg_match_all(
		'/register_taxonomy\(\s*[\'"]([^\'"]+)[\'"]/',
		(string) file_get_contents( $root . '/includes/acf-taxonomies.php' ),
		$m
	);

	$registered = $m[1];

	if ( empty( $registered ) ) {
		$fail++;
		echo "  FAIL  {$label}\n        Found no register_taxonomy() call to compare against.\n";
		return;
	}

	// Every literal taxonomy argument shape used in this plugin. The array-key
	// form is the one list-filters needs — get_terms( [ 'taxonomy' => … ] ) — and
	// it was invisible to this guard until 1.4.0.
	$patterns = [
		'/get_term_by\(\s*[^,]+,\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/',
		'/wp_get_object_terms\(\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/',
		'/get_the_terms\(\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/',
		'/wp_get_post_terms\(\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/',
		'/get_term\(\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/',
		'/term_exists\(\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/',
		'/taxonomy_exists\(\s*[\'"]([^\'"]+)[\'"]/',
		// The array-key form is restricted to values that could be a taxonomy
		// name at all. register_taxonomy() accepts [a-z0-9_-] only, and
		// includes/helpers.php:89 has a 'taxonomy' => '🏷️' entry in an icon map
		// keyed by ACF field type — an unrestricted pattern reports that emoji
		// as an unregistered taxonomy.
		'/[\'"]taxonomy[\'"]\s*=>\s*[\'"]([a-z0-9_-]{1,32})[\'"]/',
	];

	$hits  = [];
	$files = [];

	foreach ( $dirs as $dir ) {
		if ( is_file( $dir ) ) {
			$files[] = $dir;
			continue;
		}

		if ( ! is_dir( $dir ) ) {
			continue;
		}

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) ) as $file ) {
			$files[] = $file->getPathname();
		}
	}

	foreach ( $files as $path ) {
		if ( ! str_ends_with( $path, '.php' ) ) {
			continue;
		}

		foreach ( explode( "\n", (string) file_get_contents( $path ) ) as $no => $line ) {
			$trimmed = ltrim( $line );

			if ( str_starts_with( $trimmed, '//' ) || str_starts_with( $trimmed, '*' ) || str_starts_with( $trimmed, '/*' ) ) {
				continue;
			}

			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $line, $found ) && ! in_array( $found[1], $registered, true ) ) {
					$hits[] = sprintf(
						'%s:%d  "%s" is not registered (registered: %s)',
						basename( $path ),
						$no + 1,
						$found[1],
						implode( ', ', $registered )
					);
				}
			}
		}
	}

	if ( empty( $hits ) ) {
		$pass++;
		echo "  PASS  {$label}\n";
		return;
	}

	$fail++;
	echo "  FAIL  {$label}\n";
	echo "        WordPress term functions return false for an unknown taxonomy — the\n";
	echo "        value is dropped from the output with no error anywhere.\n";

	foreach ( $hits as $hit ) {
		echo "        {$hit}\n";
	}
}

taxonomy_slugs_exist(
	'term lookups use registered taxonomy slugs',
	$scan
);

printf( "\n  %d passed, %d failed\n", $pass, $fail );

exit( $fail > 0 ? 1 : 0 );
