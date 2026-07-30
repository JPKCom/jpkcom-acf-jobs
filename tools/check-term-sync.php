<?php
/**
 * Consistency check: ACF meta values vs. real taxonomy term assignments.
 *
 * WHY THIS EXISTS
 * ---------------
 * The list shortcode filters references with `meta_query` + `LIKE '%"5"%'` over
 * the serialised ACF values. A leading wildcard cannot use an index, so every
 * clause scans the meta rows for that key — a fully populated filter produces
 * three such clauses.
 *
 * The taxonomy field is configured with `save_terms => 1`, so ACF also
 * writes real term relationships, which *are* indexed. Switching the shortcode
 * to `tax_query` would therefore be much cheaper — but only returns identical
 * results if meta and term assignments actually agree for every existing post.
 * They can drift: an import, a direct DB write, or a WPML duplication that
 * never ran ACF's save routine leaves the meta set and the relationship
 * missing (or the reverse).
 *
 * This script reports that drift. It only reads — nothing is modified.
 *
 * USAGE
 * -----
 *     wp eval-file wp-content/plugins/jpkcom-acf-jobs/tools/check-term-sync.php
 *
 * Note: on an empty site an exit code of 0 means only that nothing contradicts
 * itself — with no posts it is trivially true. Run it against real data.
 *
 * Optional: append `--` style arguments are not parsed; set the constants below
 * by editing them if you need a different batch size.
 *
 * EXIT CODE
 * ---------
 * 0 = meta and terms agree everywhere (safe to switch to tax_query)
 * 1 = drift found (listed per post)
 *
 * @package JPKCom_ACF_Jobs
 * @since 1.3.7
 */

// Deliberately NO declare(strict_types=1): `wp eval-file` runs the contents
// through eval(), where a strict_types declaration would have to be the very
// first statement - which it never is. The result was a fatal error on the
// documented invocation, so the script was never executable at all.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run inside WordPress, e.g. via:\n  wp eval-file " . __FILE__ . "\n" );
	exit( 1 );
}

/** Field name => taxonomy slug. Only taxonomy-backed fields can move to tax_query. */
const JPKCOM_ACFJOBS_FIELD_TAX_MAP = [
	'job_attribute' => 'job-attribute',
];

const JPKCOM_ACFJOBS_BATCH = 200;

/**
 * Normalise an ACF meta value into a sorted list of term IDs.
 *
 * The field stores a serialised array; depending on return format the entries
 * may be IDs, numeric strings, term objects or arrays.
 *
 * @param mixed $raw Raw meta value.
 * @return int[] Sorted, unique term IDs.
 */
function jpkcom_acfjobs_meta_to_term_ids( mixed $raw ): array {
	if ( is_string( $raw ) ) {
		$unserialised = @unserialize( $raw, [ 'allowed_classes' => false ] );
		$raw          = ( false !== $unserialised || 'b:0;' === $raw ) ? $unserialised : $raw;
	}

	if ( null === $raw || '' === $raw ) {
		return [];
	}

	$ids = [];

	foreach ( (array) $raw as $entry ) {
		if ( is_numeric( $entry ) ) {
			$ids[] = (int) $entry;
		} elseif ( is_array( $entry ) && isset( $entry['term_id'] ) ) {
			$ids[] = (int) $entry['term_id'];
		} elseif ( is_object( $entry ) && isset( $entry->term_id ) ) {
			$ids[] = (int) $entry->term_id;
		}
	}

	$ids = array_values( array_unique( array_filter( $ids, static fn( int $i ): bool => $i > 0 ) ) );
	sort( $ids );

	return $ids;
}

/**
 * Term IDs assigned to a post, read in that post's own language.
 *
 * WPML rewrites term IDs to the *current* language. This script runs in the
 * site's default language, so a translated post whose relation genuinely points
 * at its own translated term comes back carrying the default-language term —
 * while the meta value, which WPML does not touch, still holds the translated
 * ID. Without this, every translated post is reported as drifted and the check
 * is unusable on exactly the multilingual sites where it matters most.
 *
 * Verified on a real installation in the sibling plugin: raw relation [43],
 * meta ["43"], wp_get_object_terms() [22] in the default language and [43] in
 * the post's own. On a monolingual site this is a no-op.
 *
 * @param int    $post_id  Post to inspect.
 * @param string $taxonomy Taxonomy slug.
 * @return int[] Term IDs, in the post's language.
 */
function jpkcom_acfjobs_object_term_ids( $post_id, $taxonomy ) {

	$details = apply_filters( 'wpml_post_language_details', null, $post_id );
	$lang    = is_array( $details ) ? ( $details['language_code'] ?? '' ) : '';

	$previous = apply_filters( 'wpml_current_language', null );

	if ( $lang && $previous && $lang !== $previous ) {
		do_action( 'wpml_switch_language', $lang );
	}

	$term_ids = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
	$term_ids = is_wp_error( $term_ids ) ? [] : array_map( 'intval', $term_ids );

	if ( $lang && $previous && $lang !== $previous ) {
		do_action( 'wpml_switch_language', $previous );
	}

	return $term_ids;
}

// ---------------------------------------------------------------------------

$post_ids = get_posts( [
	'post_type'        => 'job',
	'post_status'      => 'any',
	'posts_per_page'   => -1,
	'fields'           => 'ids',
	'suppress_filters' => true,
	'no_found_rows'    => true,
] );

$total     = count( $post_ids );
$checked   = 0;
$drifted   = 0;
$problems  = [];

echo "Checking {$total} job posts …\n\n";

foreach ( array_chunk( $post_ids, JPKCOM_ACFJOBS_BATCH ) as $chunk ) {

	foreach ( $chunk as $post_id ) {
		$checked++;
		$post_issues = [];

		foreach ( JPKCOM_ACFJOBS_FIELD_TAX_MAP as $field => $taxonomy ) {

			if ( ! taxonomy_exists( $taxonomy ) ) {
				$post_issues[] = sprintf( 'taxonomy "%s" is not registered', $taxonomy );
				continue;
			}

			$meta_ids = jpkcom_acfjobs_meta_to_term_ids( get_post_meta( $post_id, $field, true ) );

			$term_ids = jpkcom_acfjobs_object_term_ids( $post_id, $taxonomy );
			sort( $term_ids );

			$only_meta = array_diff( $meta_ids, $term_ids );
			$only_term = array_diff( $term_ids, $meta_ids );

			if ( ! empty( $only_meta ) ) {
				// The dangerous direction: LIKE finds these today, tax_query would not.
				$post_issues[] = sprintf(
					'%s: in meta but NOT assigned as terms → [%s]',
					$field,
					implode( ', ', $only_meta )
				);
			}

			if ( ! empty( $only_term ) ) {
				// Harmless for the switch, but shows the two stores disagree.
				$post_issues[] = sprintf(
					'%s: assigned as terms but NOT in meta → [%s]',
					$field,
					implode( ', ', $only_term )
				);
			}
		}

		if ( ! empty( $post_issues ) ) {
			$drifted++;
			$problems[ $post_id ] = $post_issues;
		}
	}

	// Keep memory flat on large sites.
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
}

// ---------------------------------------------------------------------------

if ( empty( $problems ) ) {
	echo "Result: meta values and term assignments agree for all {$checked} posts.\n";
	echo "Switching the shortcode filters to tax_query would return identical results.\n";
	exit( 0 );
}

echo "Result: {$drifted} of {$checked} posts disagree between meta and term assignment.\n";
echo "Switching to tax_query would CHANGE results for these. Fix them first —\n";
echo "re-saving a post in the editor makes ACF rewrite both stores.\n\n";

foreach ( $problems as $post_id => $issues ) {
	printf( "  #%d  %s\n", $post_id, get_the_title( $post_id ) ?: '(no title)' );
	printf( "      %s\n", get_edit_post_link( $post_id, 'raw' ) ?: '(no edit link)' );

	foreach ( $issues as $issue ) {
		printf( "      - %s\n", $issue );
	}

	echo "\n";
}

echo "Note: job_company and job_location are post-object fields, not taxonomies.\n";
echo "They stay meta-based regardless and are not part of this check.\n";

exit( 1 );
