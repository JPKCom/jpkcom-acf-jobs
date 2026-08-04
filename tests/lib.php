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

function sanitize_text_field( string $str ): string {
	return trim( strip_tags( $str ) );
}

function absint( mixed $maybeint ): int {
	return abs( (int) $maybeint );
}

function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
	return trim( strip_tags( $text ) );
}
