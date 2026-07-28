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
 * Assert that no PHP file under a directory matches a pattern.
 *
 * @param string $label   Human-readable check name.
 * @param string $dir     Directory to scan.
 * @param string $pattern PCRE pattern that must not match.
 * @param string $why     Explanation printed on failure.
 */
function forbid( string $label, string $dir, string $pattern, string $why ): void {
	global $pass, $fail;

	$hits = [];
	$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );

	foreach ( $it as $file ) {
		$path = $file->getPathname();

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

echo "\nDate handling\n";

forbid(
	'no bare date() for date-only comparisons',
	$includes,
	'/\bdate\(\s*(format:\s*)?[\'"]Y-m-d[\'"]\s*\)/',
	'WordPress sets the PHP timezone to UTC (wp-settings.php), so date( \'Y-m-d\' ) '
	. 'returns the UTC date and expiry checks lag the site timezone by the offset. '
	. 'Use current_time( \'Y-m-d\' ).'
);

echo "\nCapability checks\n";

forbid(
	'no role names passed to current_user_can()',
	$includes,
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

	// Third argument of get_term_by(), second of wp_get_object_terms().
	$patterns = [
		'/get_term_by\(\s*[^,]+,\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/',
		'/wp_get_object_terms\(\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/',
	];

	$hits = [];

	foreach ( $dirs as $dir ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );

		foreach ( $it as $file ) {
			$path = $file->getPathname();

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
	[ $includes, $root . '/templates' ]
);

printf( "\n  %d passed, %d failed\n", $pass, $fail );

exit( $fail > 0 ? 1 : 0 );
