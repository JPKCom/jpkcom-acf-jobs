# ACF Jobs Abilities API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship three read-only WordPress Abilities (`list-filters`, `query-jobs`, `get-job`) on top of two extracted shared functions — the job visibility rule, which currently exists three times, and a job reader, which does not exist at all.

**Architecture:** A new `includes/jobs-data.php` holds `jpkcom_acf_jobs_build_job_query_args()` (the visibility rule, consumed by the shortcode, the archive and the abilities) plus `jpkcom_acf_jobs_get_job_data()` and a set of small pure normalisers. A new `includes/abilities.php` holds the registration and the three callbacks. Rendering is untouched.

**Tech Stack:** WordPress 6.9+ (Abilities API in core), PHP 8.3+, ACF Pro 6.7+, no build step, no Composer, no PHPUnit — tests are standalone PHP scripts run by `php tests/test-*.php`.

**Spec:** `.claude/specs/2026-08-04-acf-jobs-abilities-api-design.md`. Read it before Task 1; every section below refers to it by number.

## Global Constraints

- **Nothing may throw.** The declared floor is WP 6.9, where a `Throwable` escaping an ability callback is an **uncaught fatal** — the `Throwable → WP_Error` wrapper only landed in 7.0. Every external value crosses `is_array()` / `is_scalar()` / `instanceof` before use. Every callback returns `WP_Error` on failure.
- **Long-form fields are read unformatted:** `get_field( $name, $post_id, false )`. Never the two-argument form for a wysiwyg or a flexible-content sub-field. Reason in spec §10.
- **Never emit a `WP_Post`. Never call `get_fields()`. Never call `get_term()` without its taxonomy argument.**
- **Every `get_field()` call passes `$post_id` explicitly.** There is no global `$post` in an ability callback.
- **`current_time( 'Y-m-d' )` is the only permitted source of "today".** Not `date()`, not `gmdate()`.
- **Indentation:** new files under `includes/` use **4 spaces** (matching `shortcodes.php` and `schema.php`, the files the code is lifted from). New files under `tests/` use **tabs** (matching `test-conventions.php`).
- **Naming:** new functions and filters use `jpkcom_acf_jobs_`; new constants use `JPKCOM_ACFJOBS_` (no underscore between `acf` and `jobs`). `jpkcom_acfjobs_` is frozen and must not be extended.
- **Docblocks:** every new `includes/` file gets `@package JPKCom_ACF_Jobs` and `@since 1.4.0`; every new function gets `@since`, `@param`, `@return`. phpDocumentor publishes `includes/*.php` publicly.
- **CI trap:** `.github/workflows/ci.yml:95-105` cannot tell a named argument from a ternary colon, because `null`, `true`, `false` and constants all tokenise as `T_STRING`. A ternary whose branch is a bare identifier, sitting directly inside an **internal** PHP function call, fails the build. Assign the ternary to a variable first, or wrap it in parentheses.
- **Language:** all code, comments, commit messages and CI output in English. German only in user-facing ACF/UI strings.
- **Commits:** no `Co-Authored-By` trailer, ever. Work happens on the `abilities-api` branch.
- **Text domain:** `jpkcom-acf-jobs`.

---

### Task 1: Widen the three existing source guards

The new code walks straight into three holes in `tests/test-conventions.php`. Widen them **before** the feature lands, so the guards are real when the feature arrives.

**Files:**
- Modify: `tests/test-conventions.php:20-21, 79-97, 132-136, 188-191`

**Interfaces:**
- Consumes: nothing.
- Produces: `forbid( string $label, array $dirs, string $pattern, string $why, string $unless = '' )` — note `$dirs` becomes an **array**, and the new `$unless` parameter skips any line containing that substring. `taxonomy_slugs_exist( string $label, array $dirs )` keeps its signature.

- [ ] **Step 1: Prove the current date guard has the hole**

Append a temporary line to `includes/media.php`, immediately before its final `?>`-less end of file:

```php
$jpkcom_guard_probe = gmdate( 'Y-m-d' );
```

- [ ] **Step 2: Run the guard and watch it pass anyway**

Run: `php tests/test-conventions.php`
Expected: `PASS  no bare date() for date-only comparisons` — the guard does not see `gmdate()`. This is the hole. Leave the probe line in place for Step 5.

- [ ] **Step 3: Widen `forbid()` to take a list of directories and an exemption**

Replace the function at `tests/test-conventions.php:34-75` with:

```php
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
```

- [ ] **Step 4: Widen the date and capability guards**

Replace `tests/test-conventions.php:77-97` with:

```php
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
```

- [ ] **Step 5: Run the guard and watch the probe now fail**

Run: `php tests/test-conventions.php`
Expected: `FAIL  no bare date()/gmdate() for date-only comparisons` naming `media.php` and the probe line. The guard now sees what it could not see before.

- [ ] **Step 6: Remove the probe and confirm green**

Delete the `$jpkcom_guard_probe` line from `includes/media.php`.
Run: `php tests/test-conventions.php`
Expected: all checks PASS. The exemption keeps `schema.php:71` unflagged.

- [ ] **Step 7: Widen the taxonomy-slug guard**

Replace the `$patterns` array at `tests/test-conventions.php:132-136` with:

```php
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
			'/[\'"]taxonomy[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/',
		];
```

and widen its call site at `:188-191`:

```php
taxonomy_slugs_exist(
	'term lookups use registered taxonomy slugs',
	$scan
);
```

- [ ] **Step 8: Prove the widened taxonomy guard bites**

Temporarily change `includes/shortcodes.php:421` from `'taxonomy' => 'job-attribute',` to `'taxonomy' => 'job_attribute',` (the underscore — the exact confusion 1.3.8 fixed elsewhere).
Run: `php tests/test-conventions.php`
Expected: `FAIL  term lookups use registered taxonomy slugs` naming `shortcodes.php:421`.
Revert the change.
Run: `php tests/test-conventions.php`
Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add tests/test-conventions.php
git commit -m "Widen the source guards to cover what the abilities will do

The date guard matched one exact spelling, so gmdate( 'Y-m-d' ) and
date( 'Y-m-d', time() ) both evaded it while carrying the same UTC bug the
rule exists to prevent. The taxonomy guard knew two call shapes, and the one
list-filters needs — get_terms( [ 'taxonomy' => … ] ) — was not among them;
the plugin's own literal at shortcodes.php:421 was unguarded as a result.
Both guards scanned includes/ only, so the same mistakes in templates/ or in
the root plugin file were invisible.

The schema.php round-trip stays exempt, now by a named rule rather than by
the pattern happening not to reach it."
```

---

### Task 2: Extract the visibility rule into `includes/jobs-data.php`

**Files:**
- Create: `includes/jobs-data.php`
- Create: `tests/test-jobs-data.php`
- Modify: `jpkcom-acf-jobs.php` (add the include, before the `shortcodes.php` block at `:334`)
- Modify: `includes/shortcodes.php:117-238` (replace the inline construction with a call)

**Interfaces:**
- Consumes: nothing.
- Produces: `jpkcom_acf_jobs_build_job_query_args( array $args = [] ): array`. Recognised keys — `posts_per_page` (`int|null`), `paged` (`int|null`), `order` (`string`, `'ASC'|'DESC'`, default `'DESC'`), `post_status` (`string|null`), `job_type` (`string[]`), `company` (`int[]`), `location` (`int[]`), `attribute` (`int[]`, term IDs), `search` (`string`), `exclude_password_protected` (`bool`). Keys whose value is `null` are omitted from the result entirely.

- [ ] **Step 1: Write the failing test**

Create `tests/test-jobs-data.php` (tabs):

```php
<?php
/**
 * Behavioural guards for includes/jobs-data.php.
 *
 * This repo has no PHPUnit and no tests/bootstrap.php, so the file defines the
 * handful of WordPress functions jobs-data.php touches and then requires it.
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

$root = dirname( __DIR__ );

define( 'ABSPATH', $root . '/' );

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

// Minimal WordPress surface. Deliberately not a framework: every stub here is a
// function jobs-data.php actually calls, and nothing else.
function current_time( string $type, int|bool $gmt = 0 ): string {
	return '2026-01-15';
}

function sanitize_text_field( string $str ): string {
	return trim( strip_tags( $str ) );
}

function absint( mixed $maybeint ): int {
	return abs( (int) $maybeint );
}

require_once $root . '/includes/jobs-data.php';

echo "\nLoad\n";

chk(
	'jobs-data.php defines the query builder',
	function_exists( 'jpkcom_acf_jobs_build_job_query_args' ),
	'The require produced nothing. Without this check the run would exit 0 and CI would be green.'
);

if ( ! function_exists( 'jpkcom_acf_jobs_build_job_query_args' ) ) {
	printf( "\n  %d passed, %d failed\n", $pass, $fail );
	exit( 1 );
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

printf( "\n  %d passed, %d failed\n", $pass, $fail );

exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php tests/test-jobs-data.php`
Expected: `FAIL  jobs-data.php defines the query builder`, then exit code 1. Confirm with `echo $?` that it is `1` — a test that cannot fail is worth nothing.

- [ ] **Step 3: Create `includes/jobs-data.php` with the builder**

Create the file (4 spaces) with the standard header and this function. The `meta_query` body is a **verbatim move** from `includes/shortcodes.php:130-231` — do not reindent it beyond the enclosing level, and do not reword the comments:

```php
<?php
/**
 * Job data access
 *
 * One place for the two things the plugin previously did in several: deciding
 * which jobs are visible, and reading a job as data rather than as markup.
 *
 * @package   JPKCom_ACF_Jobs
 * @since     1.4.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( function: 'jpkcom_acf_jobs_build_job_query_args' ) ) {

    /**
     * Build WP_Query arguments for the job visibility rule.
     *
     * This is the single source for "which jobs does this site show". It is called
     * from three places that previously each carried their own copy: the
     * [jpkcom_acf_jobs_list] shortcode, the job archive's pre_get_posts handler, and
     * the Abilities API callbacks.
     *
     * Keys whose value is null are omitted from the result, because the archive sets
     * its query through WP_Query::set() and must not inherit a page size or a status.
     *
     * @since 1.4.0
     *
     * @param array $args {
     *     Optional. Query parameters.
     *
     *     @type int|null    $posts_per_page             Page size. Omitted when null. Never defaulted to -1.
     *     @type int|null    $paged                      Page number. Omitted when null.
     *     @type string      $order                      'ASC' or 'DESC' for the date component. Default 'DESC'.
     *     @type string|null $post_status                Post status. Omitted when null.
     *     @type string[]    $job_type                   job_type values (not labels).
     *     @type int[]       $company                    job_company post IDs.
     *     @type int[]       $location                   job_location post IDs.
     *     @type int[]       $attribute                  job-attribute term IDs.
     *     @type string      $search                     Free-text search.
     *     @type bool        $exclude_password_protected Whether to drop password-protected jobs.
     * }
     * @return array WP_Query arguments.
     */
    function jpkcom_acf_jobs_build_job_query_args( array $args = [] ): array {

        $order = ( isset( $args['order'] ) && strtoupper( string: (string) $args['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';

        $query_args = [
            'post_type' => 'job',
            'meta_key'  => 'job_featured',
            'orderby'   => [
                'meta_value_num' => 'DESC',
                'date'           => $order,
            ],
        ];

        if ( isset( $args['post_status'] ) ) {

            $query_args['post_status'] = $args['post_status'];

        }

        if ( isset( $args['posts_per_page'] ) ) {

            $query_args['posts_per_page'] = (int) $args['posts_per_page'];

        }

        if ( isset( $args['paged'] ) ) {

            $query_args['paged'] = (int) $args['paged'];

        }

        if ( ! empty( $args['search'] ) && is_string( value: $args['search'] ) ) {

            $query_args['s'] = sanitize_text_field( $args['search'] );

        }

        if ( ! empty( $args['exclude_password_protected'] ) ) {

            // get_the_title() prepends the protected-title format while ACF has no
            // notion of a post password and returns the salary and address in full,
            // so such a record would contradict itself.
            $query_args['has_password'] = false;

        }

        // DO NOT SIMPLIFY THIS BLOCK. Two plausible cleanups each shrink the result
        // set with no error and no log line:
        //
        // 1. The NOT EXISTS clause looks redundant next to the empty-string clause.
        //    It is not: WP_Meta_Query rewrites every INNER JOIN to LEFT JOIN as soon
        //    as one NOT EXISTS clause is present. Remove it and every job that has no
        //    job_expiry_date row at all disappears from the list.
        // 2. The comparison looks like it could move to PHP. It cannot: ACF stores
        //    dates as Ymd, and the SQL side compares the raw column through
        //    type => 'DATE'. Measured on WP 7.0.2, CAST('20251130' AS DATE) yields
        //    '2025-11-30', so this comparison is correct as written; a PHP string
        //    comparison against the raw value would not be.
        $meta_query = [
            'relation' => 'AND',
            [
                'key'     => 'job_featured',
                'compare' => 'EXISTS',
            ],
            [
                'relation' => 'OR',
                [
                    'key'     => 'job_expiry_date',
                    // Site timezone, not UTC: WordPress sets the PHP timezone to UTC
                    // (wp-settings.php), so date() would keep an expired job listing
                    // visible for the length of the UTC offset after local midnight.
                    'value'   => current_time( 'Y-m-d' ),
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
                [
                    'key'     => 'job_expiry_date',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => 'job_expiry_date',
                    'value'   => '',
                    'compare' => '=',
                ],
            ],
        ];

        // job_type: checkbox values stored as a serialized array, matched with quotes.
        $types = array_values( array_filter( array_map( 'strval', (array) ( $args['job_type'] ?? [] ) ) ) );

        if ( ! empty( $types ) ) {

            $type_clauses = [ 'relation' => 'OR' ];

            foreach ( $types as $val ) {

                $type_clauses[] = [
                    'key'     => 'job_type',
                    'value'   => '"' . sanitize_text_field( $val ) . '"',
                    'compare' => 'LIKE',
                ];

            }

            $meta_query[] = $type_clauses;

        }

        // job_company / job_location: post objects stored as a serialized array of IDs.
        foreach ( [ 'company' => 'job_company', 'location' => 'job_location' ] as $key => $meta_key ) {

            $ids = array_values( array_filter( array_map( 'absint', (array) ( $args[ $key ] ?? [] ) ) ) );

            if ( empty( $ids ) ) {

                continue;

            }

            $clauses = [ 'relation' => 'OR' ];

            foreach ( $ids as $id ) {

                $clauses[] = [
                    'key'     => $meta_key,
                    'value'   => '"' . $id . '"',
                    'compare' => 'LIKE',
                ];

            }

            $meta_query[] = $clauses;

        }

        $query_args['meta_query'] = $meta_query;

        // job_attribute is the one taxonomy-backed field, and load_terms => 1 makes
        // ACF discard the stored meta and read wp_get_object_terms() instead. The
        // meta is therefore write-only from a reader's point of view and must not be
        // filtered with the LIKE pattern the three fields above use.
        $terms = array_values( array_filter( array_map( 'absint', (array) ( $args['attribute'] ?? [] ) ) ) );

        if ( ! empty( $terms ) ) {

            $query_args['tax_query'] = [
                [
                    'taxonomy' => 'job-attribute',
                    'field'    => 'term_id',
                    'terms'    => $terms,
                    'operator' => 'IN',
                ],
            ];

        }

        return $query_args;

    }

}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/test-jobs-data.php`
Expected: all PASS, exit code 0.

- [ ] **Step 5: Load the new file from the plugin bootstrap**

In `jpkcom-acf-jobs.php`, immediately before the `shortcodes.php` block that begins at `:327`, insert:

```php
/**
 * Load job data access
 *
 * Provides the shared visibility rule and the job reader used by the shortcode,
 * the archive query and the Abilities API.
 *
 * @since 1.4.0
 */
$jpkcomAcfJobData = jpkcom_acfjobs_locate_file( filename: 'jobs-data.php' );

if ( $jpkcomAcfJobData ) {

    require_once $jpkcomAcfJobData;

}

```

- [ ] **Step 6: Capture the shortcode's current SQL, before touching it**

Write `/home/jpk/ddev/posts/probe-sql.php`:

```php
<?php
$q = new WP_Query( apply_filters( 'jpkcom_acf_jobs_list_query_args', [
	'post_type'      => 'job',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'meta_key'       => 'job_featured',
	'orderby'        => [ 'meta_value_num' => 'DESC', 'date' => 'DESC' ],
	'meta_query'     => [
		'relation' => 'AND',
		[ 'key' => 'job_featured', 'compare' => 'EXISTS' ],
		[
			'relation' => 'OR',
			[ 'key' => 'job_expiry_date', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' ],
			[ 'key' => 'job_expiry_date', 'compare' => 'NOT EXISTS' ],
			[ 'key' => 'job_expiry_date', 'value' => '', 'compare' => '=' ],
		],
	],
], [] ) );
echo "ids: " . implode( ',', wp_list_pluck( $q->posts, 'ID' ) ) . "\n";
echo "sql: " . preg_replace( '/\s+/', ' ', $q->request ) . "\n";
```

Run: `cd /home/jpk/ddev/posts && ddev wp eval-file probe-sql.php > /tmp/before.txt && cat /tmp/before.txt`
Record the output. This is the reference the conversion must reproduce.

- [ ] **Step 7: Convert the shortcode to call the builder**

In `includes/shortcodes.php`, replace lines `117-238` (from the `// Build WP_Query args` comment through the closing brace of the `count( value: $meta_query ) > 1` block) with:

```php
        // The visibility rule and the three filters now live in
        // includes/jobs-data.php, shared with the archive query and the Abilities
        // API. Everything the shortcode passes here reproduces its previous
        // behaviour exactly, including the unbounded default: the builder itself
        // refuses to default to -1 so that an API caller cannot reach it.
        $query_args = jpkcom_acf_jobs_build_job_query_args( [
            'post_status'    => 'publish',
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'order'          => $sort,
            'job_type'       => $type_csv !== '' ? array_map( 'trim', explode( separator: ',', string: $type_csv ) ) : [],
            'company'        => $company_csv !== '' ? explode( separator: ',', string: $company_csv ) : [],
            'location'       => $location_csv !== '' ? explode( separator: ',', string: $location_csv ) : [],
        ] );
```

Leave the `apply_filters( 'jpkcom_acf_jobs_list_query_args', $query_args, $atts )` call and everything after it untouched — the filter keeps firing at the shortcode's own call site with an unchanged signature.

- [ ] **Step 8: Prove the conversion changed nothing**

Copy the plugin to the instance and re-run the same probe through the real code path. Write `/home/jpk/ddev/posts/probe-shortcode.php`:

```php
<?php
$args = jpkcom_acf_jobs_build_job_query_args( [
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'order'          => 'DESC',
] );
$q = new WP_Query( apply_filters( 'jpkcom_acf_jobs_list_query_args', $args, [] ) );
echo "ids: " . implode( ',', wp_list_pluck( $q->posts, 'ID' ) ) . "\n";
echo "sql: " . preg_replace( '/\s+/', ' ', $q->request ) . "\n";
```

```bash
rsync -a --delete --exclude='.git' --exclude='.claude' \
  /home/jpk/wp/jpkcom-acf-jobs/ /home/jpk/ddev/posts/wp-content/plugins/jpkcom-acf-jobs/
cd /home/jpk/ddev/posts && ddev wp eval-file probe-shortcode.php > /tmp/after.txt
diff /tmp/before.txt /tmp/after.txt && echo "IDENTICAL"
```

Expected: `IDENTICAL`. If the SQL differs at all, the extraction changed the rule — stop and reconcile before continuing.

- [ ] **Step 9: Confirm the rendered shortcode output is unchanged**

```bash
cd /home/jpk/ddev/posts
ddev wp eval 'echo md5( do_shortcode( "[jpkcom_acf_jobs_list]" ) );'
```

Compare against the same command run from a `git stash` of the working tree. Expected: identical hashes.

- [ ] **Step 10: Clean up the probes and commit**

```bash
rm -f /home/jpk/ddev/posts/probe-sql.php /home/jpk/ddev/posts/probe-shortcode.php
cd /home/jpk/wp/jpkcom-acf-jobs
php tests/test-conventions.php && php tests/test-jobs-data.php
git add includes/jobs-data.php tests/test-jobs-data.php includes/shortcodes.php jpkcom-acf-jobs.php
git commit -m "Extract the job visibility rule into includes/jobs-data.php

The rule that decides which jobs a site shows existed twice in full and was
about to exist a third time. It now has one home, and the shortcode is its
first caller.

Two things the extraction deliberately does not carry over. The builder never
defaults posts_per_page to -1, because an API caller must not be able to reach
an unbounded query across three unindexable LIKE scans; the shortcode passes
its own -1 explicitly, so its behaviour is unchanged. And the builder does not
apply jpkcom_acf_jobs_list_query_args: that filter receives shortcode
attributes as its second argument and is documented as shortcode-only, so it
stays at the shortcode's call site.

Verified on WP 7.0.2 that the generated SQL and the returned post IDs are
byte-identical before and after."
```

---

### Task 3: Convert the archive query to the shared builder

**Files:**
- Modify: `includes/archive.php:39-79`

**Interfaces:**
- Consumes: `jpkcom_acf_jobs_build_job_query_args()` from Task 2.
- Produces: nothing new.

- [ ] **Step 1: Capture the archive's current output**

```bash
cd /home/jpk/ddev/posts
ddev wp eval 'global $wp_query; $q = new WP_Query( [ "post_type" => "job", "posts_per_page" => -1, "meta_key" => "job_featured", "orderby" => [ "meta_value_num" => "DESC", "date" => "DESC" ], "meta_query" => [ "relation" => "AND", [ "key" => "job_featured", "compare" => "EXISTS" ], [ "relation" => "OR", [ "key" => "job_expiry_date", "value" => current_time( "Y-m-d" ), "compare" => ">=", "type" => "DATE" ], [ "key" => "job_expiry_date", "compare" => "NOT EXISTS" ], [ "key" => "job_expiry_date", "value" => "", "compare" => "=" ] ] ] ] ); echo implode( ",", wp_list_pluck( $q->posts, "ID" ) );' > /tmp/archive-before.txt
curl -sk https://posts.ddev.site/jobs/ | md5sum > /tmp/archive-html-before.txt
```

- [ ] **Step 2: Replace the inline rule with a call**

Replace `includes/archive.php:43-76` with:

```php
        // The rule itself lives in includes/jobs-data.php, shared with the
        // [jpkcom_acf_jobs_list] shortcode and the Abilities API. No post_status is
        // set here on purpose: a front-end main query defers to core visibility, so
        // an editor still sees their own drafts and private jobs where core allows
        // it. Only the shortcode and the abilities pin 'publish'.
        $shared = jpkcom_acf_jobs_build_job_query_args();

        $query->set( 'meta_query', $shared['meta_query'] );
        $query->set( 'meta_key', $shared['meta_key'] );
        $query->set( 'orderby', $shared['orderby'] );
```

- [ ] **Step 3: Verify nothing changed**

```bash
rsync -a --delete --exclude='.git' --exclude='.claude' \
  /home/jpk/wp/jpkcom-acf-jobs/ /home/jpk/ddev/posts/wp-content/plugins/jpkcom-acf-jobs/
cd /home/jpk/ddev/posts
curl -sk https://posts.ddev.site/jobs/ | md5sum > /tmp/archive-html-after.txt
diff /tmp/archive-html-before.txt /tmp/archive-html-after.txt && echo "ARCHIVE HTML IDENTICAL"
```

Expected: `ARCHIVE HTML IDENTICAL`.

- [ ] **Step 4: Commit**

```bash
cd /home/jpk/wp/jpkcom-acf-jobs
php tests/test-conventions.php && php tests/test-jobs-data.php
git add includes/archive.php
git commit -m "Point the archive query at the shared visibility rule

archive.php carried a byte-identical copy of the shortcode's meta_query. With
three callers about to exist, a third copy would have let /jobs/ and the
Abilities API disagree about which jobs a site has, with nothing to notice it.

The archive still sets no post_status: a front-end main query defers to core
visibility on purpose. Verified that the rendered /jobs/ page is unchanged."
```

---

### Task 4: The job reader and its normalisers

**Files:**
- Modify: `includes/jobs-data.php` (append)
- Modify: `tests/test-jobs-data.php` (append)

**Interfaces:**
- Consumes: `jpkcom_acf_jobs_build_job_query_args()`.
- Produces:
  - `jpkcom_acf_jobs_normalise_choices( mixed $value ): array` → `[ [ 'value' => string, 'label' => string ], … ]`
  - `jpkcom_acf_jobs_normalise_choice( mixed $value ): ?array` → `[ 'value' => string, 'label' => string ]` or `null`
  - `jpkcom_acf_jobs_normalise_related( mixed $value ): array` → `[ [ 'id' => int, 'title' => string ], … ]`
  - `jpkcom_acf_jobs_normalise_date( mixed $raw ): ?string` → `Y-m-d` or `null`
  - `jpkcom_acf_jobs_plain_text( mixed $value ): string`
  - `jpkcom_acf_jobs_get_job_data( int $post_id, bool $full = false ): array` → `[]` when not readable

- [ ] **Step 1: Write the failing tests**

Append to `tests/test-jobs-data.php`, immediately before the final `printf`:

```php
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
```

Add these stubs to the stub block at the top of the file, after `absint()`:

```php
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

$GLOBALS['jpkcom_test_posts'] = [
	184 => new WP_Post( 184, 'Stelle 01', 'publish', 'job' ),
	182 => new WP_Post( 182, 'Testfirma GmbH', 'publish' ),
	5   => new WP_Post( 5, 'Unannounced GmbH', 'draft', 'job' ),
	700 => new WP_Post( 700, 'A page', 'publish', 'page' ),
	701 => new WP_Post( 701, 'Secret job', 'publish', 'job', 'hunter2' ),
];

function get_post( mixed $id = null ): ?WP_Post {
	return $GLOBALS['jpkcom_test_posts'][ (int) $id ] ?? null;
}

function get_the_title( mixed $post = null ): string {
	$p = is_object( $post ) ? $post : get_post( $post );
	return $p instanceof WP_Post ? $p->post_title : '';
}

function get_field( string $selector, mixed $post_id = false, bool $format_value = true, bool $escape_html = false ): mixed {
	return null;
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

function is_wp_error( mixed $thing ): bool {
	return false;
}

function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
	return trim( strip_tags( $text ) );
}

function wp_get_attachment_image_src( int $id, string $size = 'thumbnail' ): array|false {
	return false;
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php tests/test-jobs-data.php; echo "exit=$?"`
Expected: `FAIL  normalisers exist` plus fatals for the undefined functions, `exit=255` or `exit=1`. Either way it must not be 0.

- [ ] **Step 3: Implement the normalisers**

Append to `includes/jobs-data.php` (4 spaces, each wrapped in `function_exists`, each with a full docblock). Bodies:

```php
    /**
     * Normalise an ACF choice-list value to {value,label} pairs.
     *
     * A checkbox with return_format 'array' yields [ ['value'=>…, 'label'=>…], … ].
     * The same field yields bare strings when ACF falls back to the raw meta, which
     * happens whenever the field group is not registered — a theme replacing
     * acf-field_groups.php through the override system is enough. Both shapes are
     * the contract, not a defensive afterthought.
     *
     * @since 1.4.0
     *
     * @param mixed $value Raw ACF value.
     * @return array List of [ 'value' => string, 'label' => string ].
     */
    function jpkcom_acf_jobs_normalise_choices( mixed $value ): array {

        if ( is_scalar( value: $value ) ) {

            $value = [ $value ];

        }

        if ( ! is_array( value: $value ) ) {

            return [];

        }

        $out = [];

        foreach ( $value as $item ) {

            if ( is_array( value: $item ) && isset( $item['value'] ) && is_scalar( value: $item['value'] ) ) {

                $label = ( isset( $item['label'] ) && is_scalar( value: $item['label'] ) ) ? (string) $item['label'] : (string) $item['value'];

                $out[] = [
                    'value' => (string) $item['value'],
                    'label' => $label,
                ];

                continue;

            }

            if ( is_scalar( value: $item ) && (string) $item !== '' ) {

                $out[] = [
                    'value' => (string) $item,
                    'label' => (string) $item,
                ];

            }

        }

        return $out;

    }
```

```php
    /**
     * Normalise a single-choice ACF value (button_group, select) to one pair.
     *
     * @since 1.4.0
     *
     * @param mixed $value Raw ACF value.
     * @return array|null [ 'value' => string, 'label' => string ], or null when empty.
     */
    function jpkcom_acf_jobs_normalise_choice( mixed $value ): ?array {

        if ( is_array( value: $value ) && isset( $value['value'] ) ) {

            $value = [ $value ];

        }

        $pairs = jpkcom_acf_jobs_normalise_choices( $value );

        return $pairs[0] ?? null;

    }
```

```php
    /**
     * Normalise a stored date to Y-m-d.
     *
     * ACF stores date fields as Ymd and formats them on read, so both spellings
     * reach this function depending on whether the field's key reference row exists.
     * Anything else yields null rather than a guess.
     *
     * Deliberately not written as date( 'Y-m-d', strtotime( $raw ) ), the form used in
     * includes/schema.php:71: under strict_types a false from strtotime() makes date()
     * throw a TypeError, and on the WP 6.9 floor a Throwable out of an ability callback
     * is an uncaught fatal.
     *
     * @since 1.4.0
     *
     * @param mixed $raw Stored value.
     * @return string|null Date as Y-m-d, or null.
     */
    function jpkcom_acf_jobs_normalise_date( mixed $raw ): ?string {

        if ( ! is_string( value: $raw ) || $raw === '' ) {

            return null;

        }

        foreach ( [ 'Ymd', 'Y-m-d' ] as $format ) {

            $date = DateTimeImmutable::createFromFormat( $format, $raw );

            if ( $date instanceof DateTimeImmutable && $date->format( $format ) === $raw ) {

                return $date->format( 'Y-m-d' );

            }

        }

        return null;

    }
```

```php
    /**
     * Reduce stored markup to plain text without executing anything.
     *
     * job_short_description is a textarea with new_lines => 'br', so its stored value
     * is HTML. This turns it back into text. It expands nothing: shortcode expansion
     * is exactly what get_field()'s formatted mode does and what every caller of this
     * function exists to avoid.
     *
     * @since 1.4.0
     *
     * @param mixed $value Stored value.
     * @return string Plain text, empty when the value was not a string.
     */
    function jpkcom_acf_jobs_plain_text( mixed $value ): string {

        if ( ! is_string( value: $value ) ) {

            return '';

        }

        $value = preg_replace( '#<br\s*/?>#i', "\n", $value );

        return wp_strip_all_tags( (string) $value );

    }
```

```php
    /**
     * Project ACF post-object values to {id,title}, dropping anything unpublished.
     *
     * Never returns a WP_Post. WP_Post implements no JsonSerializable and exposes
     * post_password, post_content and post_status as public properties, so encoding
     * one would publish a related company's plaintext password. ACF resolves
     * post_object fields through acf_get_posts() with post_status 'any', so drafts
     * and private records genuinely arrive here.
     *
     * Bare integers are accepted and re-resolved: when a translation's ACF key
     * reference row is missing — the case includes/wpml-acf-field-keys-fix.php exists
     * to repair — get_field() returns raw IDs, and every renderer in this repo
     * dereferences ->ID on them.
     *
     * @since 1.4.0
     *
     * @param mixed $value Raw ACF value.
     * @return array List of [ 'id' => int, 'title' => string ].
     */
    function jpkcom_acf_jobs_normalise_related( mixed $value ): array {

        if ( $value instanceof WP_Post || is_scalar( value: $value ) ) {

            $value = [ $value ];

        }

        if ( ! is_array( value: $value ) ) {

            return [];

        }

        $out = [];

        foreach ( $value as $item ) {

            $post = $item instanceof WP_Post ? $item : get_post( absint( $item ) );

            if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {

                continue;

            }

            $out[] = [
                'id'    => (int) $post->ID,
                'title' => (string) get_the_title( $post ),
            ];

        }

        return $out;

    }
```

- [ ] **Step 4: Implement the reader gate**

Append `jpkcom_acf_jobs_get_job_data()`. Its opening is the gate; the body is written in Task 5's step 4 once the callers exist, but the gate and an empty-safe return must exist now:

```php
    /**
     * Read one job as a JSON-serialisable array.
     *
     * The gate is the first act, because no field reader in this plugin has one:
     * schema.php checks the post type, nothing anywhere checks the status, and the
     * existing readers are safe only because the shortcode hands them posts a
     * post_type/post_status query already filtered. A function taking a bare int
     * inherits none of that, and current_user_can( 'read' ) is every logged-in user.
     *
     * Returns [] for "does not exist" and for "not readable" alike, so the ability
     * on top of it cannot be used to probe which IDs exist.
     *
     * @since 1.4.0
     *
     * @param int  $post_id Job post ID.
     * @param bool $full    Whether to include the detail fields (§5.3 of the spec).
     * @return array The record, or [] when the job is not readable.
     */
    function jpkcom_acf_jobs_get_job_data( int $post_id, bool $full = false ): array {

        if ( $post_id < 1 || ! function_exists( function: 'get_field' ) ) {

            return [];

        }

        $post = get_post( $post_id );

        if ( ! $post instanceof WP_Post ) {

            return [];

        }

        if ( $post->post_type !== 'job' || $post->post_status !== 'publish' || $post->post_password !== '' ) {

            return [];

        }

        return [ 'id' => (int) $post->ID, 'title' => (string) get_the_title( $post ) ];

    }
```

Note the test stubs define `get_field()`, so `function_exists()` is true under test.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php tests/test-jobs-data.php; echo "exit=$?"`
Expected: all PASS, `exit=0`.

- [ ] **Step 6: Prove each gate clause is load-bearing**

For each of the four conditions in the gate (`post_type`, `post_status`, `post_password`, `$post_id < 1`), delete it, run the suite, confirm the corresponding assertion goes red, restore it. A guard that is green with the check removed is not a guard.

- [ ] **Step 7: Commit**

```bash
php tests/test-conventions.php && php tests/test-jobs-data.php
git add includes/jobs-data.php tests/test-jobs-data.php
git commit -m "Add the job reader and its normalisers

The reader gates post type, status and password itself. Every existing field
reader in this plugin is safe only because the shortcode hands it posts a
post_type/post_status query already filtered; a function taking a bare int
inherits none of that, and the abilities on top of it answer to any logged-in
subscriber. It returns the same empty result for 'does not exist' and 'not
readable', so it cannot be used to enumerate IDs.

The normalisers accept both value shapes ACF can produce, because a theme
replacing acf-field_groups.php through the override system makes ACF fall back
to the raw meta. normalise_related() never returns a WP_Post: that class
exposes post_password as a public property and implements no JsonSerializable.
normalise_date() avoids date( ..., strtotime( ... ) ) because strtotime()
returning false makes date() throw under strict_types, which on the 6.9 floor
is an uncaught fatal."
```

---

### Task 5: `includes/abilities.php` — scaffolding and `list-filters`

**Files:**
- Create: `includes/abilities.php`
- Create: `tests/test-abilities.php`
- Modify: `jpkcom-acf-jobs.php` (add the include after the `jobs-data.php` block)
- Modify: `includes/jobs-data.php` (complete `jpkcom_acf_jobs_get_job_data()`)

**Interfaces:**
- Consumes: everything from Tasks 2 and 4.
- Produces: `jpkcom_acf_jobs_get_ability_definitions(): array` keyed by ability name; `jpkcom_acf_jobs_abilities_enabled(): bool`; `jpkcom_acf_jobs_ability_json_object( array $map ): array|stdClass`; `jpkcom_acf_jobs_ability_error( string $code, string $message, int $status ): WP_Error`.

- [ ] **Step 1: Add the constant**

In `jpkcom-acf-jobs.php`, after the `JPKCOM_ACFJOBS_PLUGIN_URL` block at `:46-48`:

```php
if ( ! defined( 'JPKCOM_ACFJOBS_ABILITIES' ) ) {
	define( 'JPKCOM_ACFJOBS_ABILITIES', true );
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/test-abilities.php` (tabs). It reuses the stub approach and asserts the definition shapes:

```php
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

$root = dirname( __DIR__ );

define( 'ABSPATH', $root . '/' );
define( 'JPKCOM_ACFJOBS_ABILITIES', true );

$pass = 0;
$fail = 0;

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

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	return $value;
}

function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return true;
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

require_once $root . '/includes/jobs-data.php';
require_once $root . '/includes/abilities.php';

echo "\nLoad\n";

chk(
	'abilities.php defines the definition builder',
	function_exists( 'jpkcom_acf_jobs_get_ability_definitions' ),
	'The require produced nothing. Without this check the run would exit 0 and CI would report green.'
);

if ( ! function_exists( 'jpkcom_acf_jobs_get_ability_definitions' ) ) {
	printf( "\n  %d passed, %d failed\n", $pass, $fail );
	exit( 1 );
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

printf( "\n  %d passed, %d failed\n", $pass, $fail );

exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 3: Run it to make sure it fails**

Run: `php tests/test-abilities.php; echo "exit=$?"`
Expected: `FAIL  abilities.php defines the definition builder` and `exit=1`.

- [ ] **Step 4: Create `includes/abilities.php`**

Implement, in this order (4 spaces, `function_exists` wrappers, full docblocks):

1. `jpkcom_acf_jobs_abilities_enabled(): bool` — `defined( 'JPKCOM_ACFJOBS_ABILITIES' ) && JPKCOM_ACFJOBS_ABILITIES && function_exists( 'wp_register_ability' ) && function_exists( 'get_field' )`.
2. `jpkcom_acf_jobs_ability_json_object( array $map ): array|stdClass` — returns `new stdClass()` for an empty map, the array otherwise. Used for `filters`, `unknown` and every top-level input `default`.
3. `jpkcom_acf_jobs_ability_error( string $code, string $message, int $status = 400 ): WP_Error` — always sets `[ 'status' => $status ]`, because the REST run controller returns the `WP_Error` verbatim and `rest_ensure_response()` defaults to 500 without it, which tells an agent "retry unchanged".
4. `jpkcom_acf_jobs_ability_capability( string $ability ): string` — `apply_filters( 'jpkcom_acf_jobs_ability_capability', 'read', $ability )`.
5. `jpkcom_acf_jobs_ability_meta( string $ability ): array` — the `show_in_rest` / `public` / `mcp.public` / `annotations` block through `apply_filters( 'jpkcom_acf_jobs_ability_meta', $meta, $ability )`.
6. `jpkcom_acf_jobs_job_type_choices(): array` — reads `acf_get_field( 'field_68de7a25cd78d' )['choices']` when `function_exists( 'acf_get_field' )`, falling back to the eight literals so the pure definition builder never depends on a live ACF registry. Returns `[ value => label ]`.
7. `jpkcom_acf_jobs_get_ability_definitions(): array` — the three registration arrays.
8. `jpkcom_acf_jobs_register_ability_category(): void` — guarded by `wp_has_ability_category( 'jpkcom-content' )`.
9. `jpkcom_acf_jobs_register_abilities(): void` — loops the definitions, checks each `wp_register_ability()` return for `null`.
10. `jpkcom_acf_jobs_ability_list_filters( mixed $input ): array|WP_Error`.
11. The two `add_action()` calls at the end of the file.

`list-filters`' callback:

- Job types from `jpkcom_acf_jobs_job_type_choices()` as `{value,label}`.
- Attributes from `get_terms( [ 'taxonomy' => 'job-attribute', 'hide_empty' => false ] )`, result checked with `is_wp_error()` **before** iteration and each entry with `instanceof WP_Term`.
- One visibility pass. The builder recognises no `fields` key — it returns only what the rule needs — so the caller merges it:

  ```php
  $args = jpkcom_acf_jobs_build_job_query_args( [
      'post_status'                => 'publish',
      'posts_per_page'             => 501,
      'exclude_password_protected' => true,
  ] );

  $args['fields']                 = 'ids';
  $args['ignore_sticky_posts']    = true;

  $visible = new WP_Query( $args );
  ```

  `WP_Query` rather than `get_posts()`, which silently overrides `posts_per_page` handling and suppresses filters. Above 500 results set `counts_omitted => true` and skip the tallies; the lists themselves stay complete.
- Companies and locations from the tallied IDs' `job_company` / `job_location` meta, projected through `jpkcom_acf_jobs_normalise_related()`.
- `hidden_missing_featured` from its own query (`post_type => job`, `post_status => publish`, `meta_query` = `[ [ 'key' => 'job_featured', 'compare' => 'NOT EXISTS' ] ]`, `fields => 'ids'`), because two independent causes exclude those jobs and removing one clause from the main rule changes nothing.

- [ ] **Step 5: Complete the reader body**

Replace the placeholder return in `jpkcom_acf_jobs_get_job_data()` with the full compact record per spec §5.2, and the `$full` branch per §5.3. Every `get_field()` call takes three arguments; the long-form ones take `false`.

- [ ] **Step 6: Load `abilities.php` from the plugin bootstrap**

Insert after the `jobs-data.php` block added in Task 2, mirroring its shape, with `@since 1.4.0`.

- [ ] **Step 7: Run both suites**

Run: `php tests/test-conventions.php && php tests/test-jobs-data.php && php tests/test-abilities.php`
Expected: all PASS.

- [ ] **Step 8: Prove the source guards bite**

For each of the five `forbid_in_ability_path()` checks, introduce the forbidden form in `includes/abilities.php`, run the suite, confirm that specific check goes red, revert. A guard that never fires is not a guard.

- [ ] **Step 9: Register against a real installation**

```bash
rsync -a --delete --exclude='.git' --exclude='.claude' \
  /home/jpk/wp/jpkcom-acf-jobs/ /home/jpk/ddev/posts/wp-content/plugins/jpkcom-acf-jobs/
cd /home/jpk/ddev/posts
ddev wp eval 'foreach ( [ "list-filters", "query-jobs", "get-job" ] as $n ) { $a = wp_get_ability( "jpkcom-acf-jobs/{$n}" ); echo $n . ": " . ( $a ? "registered" : "MISSING" ) . "\n"; }'
```

Expected: all three `registered`.

**Hook-timing caveat for any diagnostic script:** the registries are lazy singletons that fire their init action on first access, and Rank Math touches the registry during load on this stack — so `did_action( 'wp_abilities_api_init' )` is already 1 by the time a `wp eval` runs and a late `add_action()` never fires. A diagnostic must check `did_action()` and fall back to `WP_Abilities_Registry::get_instance()->register()`. Normal plugin registration at file-load time is unaffected.

- [ ] **Step 10: Verify the defensive category registration on the colliding install**

```bash
rsync -a --delete --exclude='.git' --exclude='.claude' \
  /home/jpk/wp/jpkcom-acf-jobs/ /home/jpk/ddev/jobs/wp-content/plugins/jpkcom-acf-jobs/
cd /home/jpk/ddev/jobs
ddev wp eval 'foreach ( [ "list-filters", "query-jobs", "get-job" ] as $n ) { $a = wp_get_ability( "jpkcom-acf-jobs/{$n}" ); echo $n . ": " . ( $a ? "registered" : "MISSING" ) . "\n"; }'
```

`jpkcom-post-filter` 1.3.0 is active there and registers `jpkcom-content` first. Categories are global and first-wins with a silent `null` for the loser, and an ability registered into an unregistered category fails outright. Expected: all three `registered`.

- [ ] **Step 11: Commit**

```bash
git add includes/abilities.php includes/jobs-data.php tests/test-abilities.php jpkcom-acf-jobs.php
git commit -m "Register three read-only abilities and implement list-filters

The category jpkcom-content is shared with jpkcom-post-filter and registered
defensively: categories are global and first-wins, the loser gets a silent
null, and an ability registered into an unregistered category is not
registered at all. Verified on an install where post-filter registers first.

list-filters derives the job-type enum from the field definition rather than
from stored data, so the vocabulary is complete even for a type nobody uses
today, and it sources attributes from the term relations because load_terms
makes ACF discard the meta. Its counts and its visibility summary come from
one pass over the visible IDs; above 500 the counts are dropped with a flag
rather than silently truncated.

hidden_missing_featured needs its own query: two independent causes exclude a
job with no job_featured row — the EXISTS clause and the meta_key the ordering
requires, whose condition lands in the WHERE clause — so removing one of them
changes nothing."
```

---

### Task 6: `query-jobs`

**Files:**
- Modify: `includes/abilities.php`
- Modify: `tests/test-abilities.php`

**Interfaces:**
- Consumes: Task 5's helpers.
- Produces: `jpkcom_acf_jobs_ability_query_jobs( mixed $input ): array|WP_Error`; `jpkcom_acf_jobs_ability_normalise_filter( mixed $raw, string $axis, int $max ): array|WP_Error`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/test-abilities.php`:

```php
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
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php tests/test-abilities.php; echo "exit=$?"`
Expected: fatal or FAIL on the undefined functions, exit non-zero.

- [ ] **Step 3: Implement**

`jpkcom_acf_jobs_ability_normalise_filter( mixed $raw, string $axis, int $max ): array|WP_Error`:

- `null` / `[]` → `[]` (no filter requested).
- Not an array → `WP_Error` `jpkcom_acf_jobs_invalid_filter`, status 400, message naming the axis and the expected shape.
- More than `$max` entries → `WP_Error`, status 400, stating the cap and the count received.
- Per axis: `company`/`location` require positive integers and are additionally checked with `get_post_type( $id )` against the matching post type at the call site; `attribute` accepts slugs, resolved with `get_term_by( 'slug', $slug, 'job-attribute' )`; `job_type` is already constrained by the schema `enum`.
- **Any requested value that survives normalisation as nothing → `WP_Error`.** A value that is well-formed but matches nothing is *not* an error; it goes into `unknown`.

`jpkcom_acf_jobs_ability_clamp_per_page( mixed $value ): int` — non-numeric → 10; otherwise `min( 50, max( 1, (int) $value ) )`. Never returns `-1` or `0`.

`jpkcom_acf_jobs_ability_query_jobs()`:

- Resolve every per-property default in the callback — core applies only the top-level one.
- Build args via `jpkcom_acf_jobs_build_job_query_args()`, apply `jpkcom_acf_jobs_ability_query_args`, then **re-assert** `post_type === 'job'` and `post_status === 'publish'` on the filtered result.
- Run `WP_Query`, iterate `$query->posts` directly (no loop, no `wp_reset_postdata()`), map each through `jpkcom_acf_jobs_get_job_data( $id, false )`.
- `$query->posts === [] && $page > 1` → re-run for page 1 and take `found_posts` / `max_num_pages` from there, keeping `jobs` empty and echoing the requested page back. Keep the first half of the guard: dropping it doubles the query count on every paginated call.
- `archive_url`: withheld (empty string) when `get_option( 'jpkcom_acf_job_disable_archive' )` is truthy.
- `filters` and `unknown` go through `jpkcom_acf_jobs_ability_json_object()`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/test-abilities.php`
Expected: all PASS.

- [ ] **Step 5: Prove filtering narrows, on a real install**

```bash
rsync -a --delete --exclude='.git' --exclude='.claude' \
  /home/jpk/wp/jpkcom-acf-jobs/ /home/jpk/ddev/posts/wp-content/plugins/jpkcom-acf-jobs/
cd /home/jpk/ddev/posts
ddev wp eval '
$a = wp_get_ability( "jpkcom-acf-jobs/query-jobs" );
$all = $a->execute( [] );
$one = $a->execute( [ "job_type" => [ "FULL_TIME" ] ] );
$bad = $a->execute( [ "company" => [ "acme" ] ] );
printf( "unfiltered: %d\nFULL_TIME:  %d\nbad filter: %s\n",
  $all["total"], $one["total"],
  is_wp_error( $bad ) ? $bad->get_error_code() . " / " . ( $bad->get_error_data()["status"] ?? "no status" ) : "RETURNED " . $bad["total"] . " JOBS" );'
```

Expected: unfiltered 6, FULL_TIME 2, and the bad filter a `jpkcom_acf_jobs_invalid_filter` with status 400 — **not** a count. A suite can pass while the filters never reach `WP_Query` at all; this is the check that catches it.

- [ ] **Step 6: Commit**

```bash
git add includes/abilities.php tests/test-abilities.php
git commit -m "Add query-jobs

The load-bearing guard is that a requested filter which normalises to nothing
is a 400 naming the valid form, not a dropped clause. The shortcode builds each
clause as array_filter( array_map( 'absint', … ) ) and skips it when empty, so
company=[\"acme\"] silently returns every job — the same class that answered
with 19 of 19 posts in jpkcom-post-filter. A well-formed value that matches
nothing stays a non-error and lands in unknown, because an honest empty result
is different from a wrong filter.

The status matters as much as the message: without data['status'] the REST run
controller answers 500, which tells an agent to retry unchanged — the opposite
of what these messages are for.

A page past the last one recovers its totals from a page-1 re-run, because
WP_Query::set_found_posts() returns early on an empty result and would
otherwise report a corpus of zero next to a page number of three."
```

---

### Task 7: `get-job`

**Files:**
- Modify: `includes/abilities.php`
- Modify: `tests/test-abilities.php`

**Interfaces:**
- Consumes: Tasks 4-6.
- Produces: `jpkcom_acf_jobs_ability_get_job( mixed $input ): array|WP_Error`; `jpkcom_acf_jobs_detail_page_renders( int $post_id ): bool`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/test-abilities.php`:

```php
echo "\nDetail-page rule\n";

chk( 'the detail-page test exists', function_exists( 'jpkcom_acf_jobs_detail_page_renders' ) );

forbid_in_ability_path(
	'get-job answers identically for absent and unreadable',
	'/does not exist|no such job/i',
	'Two different messages for "absent" and "not readable" turn get-job into a probe for '
	. 'which IDs exist. One message, one code.'
);
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php tests/test-abilities.php`
Expected: `FAIL  the detail-page test exists`.

- [ ] **Step 3: Implement**

`jpkcom_acf_jobs_detail_page_renders( int $post_id ): bool` — `false` when any of these holds, each mirroring a redirect in `includes/redirects.php`:

| State | Source |
|---|---|
| `job_url` is a non-empty array with a `url` | `redirects.php:32-86` — 307 for every non-`manage_options` caller |
| the job is expired | `redirects.php:132-173` — 307 for every non-`edit_post` caller |
| the post has a password | already excluded by the reader's gate |

`jpkcom_acf_jobs_ability_get_job()`:

- Resolve `id` as a positive int; anything else → `WP_Error` `jpkcom_acf_jobs_job_not_found`, status 404, one message for every reason.
- `jpkcom_acf_jobs_get_job_data( $id, jpkcom_acf_jobs_detail_page_renders( $id ) )`. Empty result → the same 404.
- Add `listed` (does the job satisfy the visibility rule) and `listed_reason` (`missing_featured`, `expired`, `closed`, or absent).
- Add `detail_withheld_reason` when `$full` was false.
- `url` is the effective destination — `job_url['url']` when set, else the permalink — with `redirects_externally`. `job_url` is `''` or `false` when unset, never `null`, so `??` does not catch it.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/test-abilities.php`
Expected: all PASS.

- [ ] **Step 5: Seed the edge-case fixtures**

The six existing jobs on `/home/jpk/ddev/posts` cover only the happy path — same company, same location, no expiry, none closed. Write `/home/jpk/ddev/posts/seed-jobs.php` creating, and printing the IDs of:

1. a job with **no** `job_featured` meta row (create with `wp_insert_post()` and set no ACF fields)
2. an expired job (`job_expiry_date` = `20250101`, `job_featured` = `0`)
3. a job with `job_closed` = `1`
4. a job with several `job_type` values
5. a second `job_company` and a second `job_location`, each attached to one job
6. a job with a `job_url` pointing at `https://example.com/apply`
7. a job whose `job_application_description` contains **both** `[gallery]` and a bare URL alone on its own line

Record the printed IDs in the plan's execution notes — Task 8 and Task 9 use them.

- [ ] **Step 6: Measure the read-only claim**

```bash
cd /home/jpk/ddev/posts
BEFORE=$(ddev wp db query "SELECT COUNT(*) FROM $(ddev wp db prefix --quiet 2>/dev/null || echo wp_)posts WHERE post_type='oembed_cache'" --skip-column-names)
ddev wp eval '$a = wp_get_ability( "jpkcom-acf-jobs/get-job" ); $r = $a->execute( [ "id" => <ID FROM STEP 5.7> ] ); echo is_wp_error( $r ) ? $r->get_error_code() : "ok";'
AFTER=$(ddev wp db query "SELECT COUNT(*) FROM $(ddev wp db prefix --quiet 2>/dev/null || echo wp_)posts WHERE post_type='oembed_cache'" --skip-column-names)
echo "oembed_cache before=$BEFORE after=$AFTER"
```

Expected: `before` equals `after`. This is the direct measurement of spec §10: a formatted read would have made an outbound HTTP request and inserted an `oembed_cache` post from a declared read-only ability.

- [ ] **Step 7: Prove the mitigation is load-bearing**

Temporarily change one long-form `get_field( $name, $post_id, false )` in `includes/jobs-data.php` to the two-argument form, re-run Step 6, and confirm `after` is now **greater** than `before`. Revert, re-run, confirm equality again. Without this the rule is an assertion, not a finding.

- [ ] **Step 8: Commit**

```bash
git add includes/abilities.php tests/test-abilities.php
git commit -m "Add get-job, with the detail-page rule

Detail fields are emitted only for a job whose detail page would actually
render for an anonymous visitor. Three states suppress it and all three are
redirects in redirects.php: a job_url that 307s every non-admin away, an
expired job that 307s to the archive, and a password-protected job that the
reader's gate already excludes. For those, salary, postal address, attributes
and application data have no public render path at all, so emitting them to
any logged-in subscriber would publish data the site has deliberately never
shown. The compact record stays available, so an agent can still answer why a
job appears in no list.

The read-only claim is measured, not asserted: the oembed_cache row count is
unchanged across a get-job call on a job whose description carries a bare URL,
and reverting the unformatted read makes that count rise."
```

---

### Task 8: Runtime verification across the three instances

**Files:** none changed. This task produces evidence.

**Interfaces:**
- Consumes: the finished feature.
- Produces: a written record of what was measured, to be pasted into the release notes discussion.

- [ ] **Step 1: Create an application password**

```bash
cd /home/jpk/ddev/posts
ddev wp user application-password create 1 abilities-check --porcelain
```

Record it. **Delete exactly this one entry when finished** — `--all` would take other people's credentials with it.

- [ ] **Step 2: Discovery over HTTP, anonymous must fail**

```bash
BASE=https://posts.ddev.site/wp-json/wp-abilities/v1/abilities
curl -skio /dev/null -w 'anon: %{http_code}\n' "$BASE"
curl -sk -u "admin:$APP_PW" "$BASE" | python3 -c 'import json,sys; d=json.load(sys.stdin); print("\n".join(a["name"] for a in d if a["name"].startswith("jpkcom-acf-jobs")))'
```

Expected: anonymous `401`; all three names listed.

- [ ] **Step 3: All three run routes answer on GET**

```bash
curl -sk -u "admin:$APP_PW" "$BASE/jpkcom-acf-jobs/list-filters/run"
curl -sk -u "admin:$APP_PW" "$BASE/jpkcom-acf-jobs/query-jobs/run?input%5Bper_page%5D=3"
curl -sk -u "admin:$APP_PW" "$BASE/jpkcom-acf-jobs/get-job/run?input%5Bid%5D=184"
```

Expected: HTTP 200 on all three. `readonly => true` is what makes the route a GET; without annotations it would be POST-only.

- [ ] **Step 4: Calling with no input at all must work**

```bash
curl -sk -u "admin:$APP_PW" "$BASE/jpkcom-acf-jobs/query-jobs/run"
```

Expected: 200 with a full result set. A failure here means the top-level input `default` is missing.

- [ ] **Step 5: A caller mistake must be 4xx, not 5xx**

```bash
curl -skio /dev/null -w '%{http_code}\n' -u "admin:$APP_PW" \
  "$BASE/jpkcom-acf-jobs/query-jobs/run?input%5Bcompany%5D%5B%5D=acme"
```

Expected: `400`.

- [ ] **Step 6: Check the second consumer**

The MCP Adapter reads `$ability->get_input_schema()` and hands it to clients **raw**, where core's REST list controller silently rewrites an empty-array default to `{}`. Verify in-process (the adapter's own discovery ability has `show_in_rest => false`, so probing it over REST returns 404):

```bash
cd /home/jpk/ddev/posts
ddev wp eval '
foreach ( [ "list-filters", "query-jobs" ] as $n ) {
  $a = wp_get_ability( "jpkcom-acf-jobs/{$n}" );
  echo $n . " raw default: " . json_encode( $a->get_input_schema()["default"] ?? null ) . "\n";
}'
```

Expected: `{}` for both, never `[]`.

- [ ] **Step 7: The declared floor**

```bash
rsync -a --delete --exclude='.git' --exclude='.claude' \
  /home/jpk/wp/jpkcom-acf-jobs/ /home/jpk/ddev/test2/wp-content/plugins/jpkcom-acf-jobs/
cd /home/jpk/ddev/test2
ddev wp core version
ddev wp eval 'foreach ( [ "list-filters", "query-jobs", "get-job" ] as $n ) { $a = wp_get_ability( "jpkcom-acf-jobs/{$n}" ); if ( ! $a ) { echo "{$n}: MISSING\n"; continue; } $r = $a->execute( [] ); echo "{$n}: " . ( is_wp_error( $r ) ? "WP_Error " . $r->get_error_code() : "ok" ) . "\n"; }'
```

Expected: WP 6.9.4, all three registered, none fatal. A `WP_Error` from `get-job` is correct — it requires an `id`.

- [ ] **Step 8: The kill switch**

```bash
cd /home/jpk/ddev/test2
ddev wp config set JPKCOM_ACFJOBS_ABILITIES false --raw --type=constant
ddev wp eval 'echo wp_get_ability( "jpkcom-acf-jobs/query-jobs" ) ? "STILL REGISTERED\n" : "suppressed\n";'
ddev wp config delete JPKCOM_ACFJOBS_ABILITIES
ddev wp eval 'echo wp_get_ability( "jpkcom-acf-jobs/query-jobs" ) ? "registered\n" : "MISSING\n";'
```

Expected: `suppressed`, then `registered`.

- [ ] **Step 9: Clean up**

```bash
cd /home/jpk/ddev/posts
ddev wp user application-password list 1 --fields=uuid,name | grep abilities-check
ddev wp user application-password delete 1 <THAT UUID>
rm -f /home/jpk/ddev/posts/seed-jobs.php
```

Delete the one entry created in Step 1, by UUID. Not `--all`.

- [ ] **Step 10: Record the evidence**

Write the measured numbers into the task notes: unfiltered total, each filtered total, the term counts `list-filters` reports, the `oembed_cache` before/after pair, and the floor-instance output. These are what the release notes are written from.

---

### Task 9: Acceptance gate

**Files:** none changed by this task itself; fixes it produces are separate commits.

This is the gate the user asked for explicitly, mirroring what `jpkcom-post-filter` 1.3.0 needed: 178 green checks were followed by nine defects, all at inputs no test had aimed at.

- [ ] **Step 1: Abuse round**

Run each of these against `/home/jpk/ddev/posts` and record the answer verbatim. The brief is to **misuse** the interface, not to confirm it:

1. `query-jobs` with no arguments at all, and with `null`
2. `page` = 999 against 6 jobs — check `total` and `total_pages`, not just that it answers
3. `per_page` = 0, `-1`, `9999`, `"ten"`
4. `company` = `["acme"]`, `[0]`, `[-1]`, `[]`, `["182"]`, and the ID of a **job_location** in the `company` field
5. `job_type` = `["Vollzeit"]` (the label), `["FULLTIME"]`, `[]`, and all eight at once
6. `attribute` = a slug that exists but sits on no job; a slug that does not exist; 21 slugs
7. `get-job` with the ID of: a draft job, a page, a revision, `0`, `-1`, a job_company, a nonexistent ID, the password-protected job, the `job_url` job, the expired job, the job with no `job_featured` row
8. `list-filters` on an install where the `job-attribute` taxonomy is not registered (temporarily rename the slug in `acf-taxonomies.php`)
9. Every one of the above **over HTTP** as well as in-process — the empty-map defect exists only at `json_encode` time

- [ ] **Step 2: Refute every finding**

Each confirmed finding goes to a second reviewer whose brief is to **disprove** it: does the cited code exist and say that; is the consequence real or does some other path prevent it; is the severity inflated; is it already covered elsewhere. Default to rejecting when uncertain. Of 25 claims in the previous round, 5 survived.

- [ ] **Step 3: Fix, then attack the fix**

For each surviving finding, ask **"which class of inputs leads here?"** rather than "which input was reported". Then run a fresh attack against the fix itself — not a re-review asking whether the finding was addressed. Four of five fixes were reopened last time, three with new production holes, always for that reason.

- [ ] **Step 4: Prove each fix by mutation**

Revert the fix, confirm the specific assertion goes red, restore it. A fix with no assertion that fails without it is not covered.

- [ ] **Step 5: Repeat until a round produces nothing new**

One clean round is not enough — the previous feature's second round is what found the nine.

---

### Task 10: Documentation and release 1.4.0

**Files:**
- Modify: `jpkcom-acf-jobs.php:6, 16, 35`
- Modify: `phpdoc.xml:12`
- Modify: `README.md:6, 16`, plus a new `### 1.4.0` block directly under `## Changelog` at `:319`
- Modify: `CLAUDE.md` — new "Abilities API" section, the new constant, and `:173-176` for the three new filters

- [ ] **Step 1: Write the CLAUDE.md section**

An "Abilities API (since 1.4.0)" section covering: the three abilities and what each returns; the shared `jobs-data.php` functions; and the traps a future maintainer will otherwise re-introduce — the `acf_the_content` chain and why long-form fields are read unformatted; why `get_fields()` and `WP_Post` are forbidden; the two independent causes behind `job_featured`; the `NOT EXISTS`-to-`LEFT JOIN` rewrite; the 400-vs-500 rule; the empty-map-as-`{}` rule; the three exposure switches. Backfill the missing `jpkcom_acf_jobs_schema_job_posting` in the filter list while there.

- [ ] **Step 2: Write the README section**

A user-facing section explaining what the abilities are, who can call them, what `read` means, and how to switch the feature off. The site owner deciding whether to disable it must be able to decide from the README alone — a missing section here was under-rated as "minor" in the previous release and was not.

**Formatting constraint:** no line may begin at column 0 with `**Label:**`. `release.yml:76-91` harvests exactly that form into the manifest, and step 8 publishes the ZIP **before** step 9 builds it — so a quote or backslash in such a line kills the job after the release is already public: no manifest, no `gh-pages` deploy, and every installed site keeps seeing the old version while the new ZIP is downloadable. Use headings, list items or fenced code. The `**Key:** value` form is reserved for the header block at `README.md:3-20`.

- [ ] **Step 3: Bump all seven version locations**

```bash
cd /home/jpk/wp/jpkcom-acf-jobs
command grep -n "1\.3\.11" jpkcom-acf-jobs.php phpdoc.xml README.md
```

Expected hits: `jpkcom-acf-jobs.php:6`, `:16`, `:35`; `phpdoc.xml:12`; `README.md:6`, `:16`. Change each to `1.4.0`, then add the `### 1.4.0` changelog block.

- [ ] **Step 4: Verify the version is consistent**

```bash
command grep -rn --exclude-dir=.git --exclude-dir=languages "1\.3\.11" . | grep -v '^./README.md:3[0-9][0-9]' || echo "no stale version outside the changelog"
```

- [ ] **Step 5: Run everything**

```bash
php -l includes/jobs-data.php && php -l includes/abilities.php
php tests/test-conventions.php && php tests/test-jobs-data.php && php tests/test-abilities.php
```

Expected: no syntax errors, all suites green.

- [ ] **Step 6: Commit, push the branch, open the PR, and wait for CI**

```bash
git add -A
git commit -m "Release 1.4.0: Abilities API"
git push -u origin abilities-api
gh pr create --fill
gh pr checks --watch
```

**CI must be green before the tag is pushed.** `release.yml` is not gated on `ci.yml`: pushing commit and tag together starts two independent runs, and step 8 publishes the ZIP and the SHA256 the auto-updater trusts before any PHP is parsed. See spec §11 — this is a defect in the release machinery of all 18 plugins and belongs in its own change, but until then the discipline is manual.

- [ ] **Step 7: Merge, tag, release**

```bash
gh pr merge --merge
git checkout main && git pull
git tag v1.4.0 && git push origin v1.4.0
gh run watch
```

- [ ] **Step 8: Verify at the published artefact, not at the repo**

```bash
gh release download v1.4.0 -p '*.zip' -D /tmp/rel && cd /tmp/rel
sha256sum *.zip
curl -s https://jpkcom.github.io/jpkcom-acf-jobs/plugin_jpkcom-acf-jobs.json | python3 -c 'import json,sys; print(json.load(sys.stdin)["checksum_sha256"])'
unzip -l *.zip | grep -E "abilities.php|jobs-data.php"
unzip -p *.zip jpkcom-acf-jobs/includes/abilities.php | grep -c "wp_register_ability"
unzip -l *.zip | grep -E "\.claude|tests/|tools/" && echo "LEAKED" || echo "excluded correctly"
```

Expected: the two hashes are identical; both new files are present in the ZIP; `.claude`, `tests/` and `tools/` are absent. The manifest's `checksum_sha256` is what the updater verifies on every installation, so ZIP and manifest must come from the same run.

---

## Self-review

**Spec coverage.** §3.1 → Tasks 2, 4, 5. §3.2 → Task 2. §3.3 → Task 2 Step 7. §3.4-3.5 → Task 4 and Task 5 Step 5. §4 → Task 5 Steps 1, 4, 9, 10. §5.1 → Task 5. §5.2 → Task 6. §5.3 → Task 7. §6 → Tasks 6 and 7. §7 → Task 5 and Task 8 Step 8. §8.1 → Tasks 1, 2, 4, 5, 6, 7. §8.2 → Task 8. §8.3 → Task 9. §9 → Task 10. §10 → Task 7 Steps 6-7, the one measurement that proves the design's central claim. §11 → carried into Task 1's exemption comment and Task 10 Step 6.

**Type consistency.** `jpkcom_acf_jobs_build_job_query_args()` is called with the same key names in Tasks 2, 3, 5 and 6. `jpkcom_acf_jobs_get_job_data( int, bool )` keeps its two-parameter signature from Task 4 through Task 7. `forbid()` gains its array-and-exemption signature in Task 1 and is used in that form afterwards. `chk()` is defined once per test file, deliberately — the two files never load together.

**Known gap, stated rather than hidden.** Task 5 Steps 4-5 describe `list-filters`' callback and the reader body as prose plus a field table rather than as finished code. They are the two largest bodies in the feature and are fully specified by spec §3.5 and §5.1, but an implementer will be writing rather than transcribing there. Everything else in this plan is transcribe-and-run.
