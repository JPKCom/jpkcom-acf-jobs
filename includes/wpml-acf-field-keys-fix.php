<?php
/**
 * WPML + ACF Field Keys Fix
 *
 * Ensures ACF field keys (_field_name) are copied to WPML translations.
 * This is required for ACF to properly format field values in translations.
 *
 * @package   JPKCom_ACF_Jobs
 * @since     1.2.2
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'WPINC' ) ) {
	die;
}

/**
 * Copy ACF field keys to WPML translation
 *
 * Hooks into WPML's save_post_translation action to ensure all ACF field keys
 * are copied from the original post to the translation. Without these keys,
 * ACF cannot properly format field values (e.g., returns IDs instead of post objects).
 *
 * @param int    $post_id     The translated post ID
 * @param array  $fields      The fields being saved
 * @param object $job         The WPML translation job object
 *
 * @since 1.2.2
 * @return void
 */
add_action( 'wpml_pro_translation_completed', function( $new_post_id, $fields, $job ) {

	// Get the original post ID
	$original_post_id = $job->original_doc_id ?? null;

	if ( ! $original_post_id || ! $new_post_id ) {
		return;
	}

	// Get all meta from original post
	$original_meta = get_post_meta( $original_post_id );

	if ( empty( $original_meta ) ) {
		return;
	}

	$copied = 0;

	// Loop through all meta and copy ACF field keys
	foreach ( $original_meta as $meta_key => $meta_values ) {

		// Only copy ACF internal fields (starting with underscore)
		// Exclude WordPress internal fields (_edit_, _wp_)
		if (
			strpos( $meta_key, '_' ) === 0 &&
			strpos( $meta_key, '_edit_' ) === false &&
			strpos( $meta_key, '_wp_' ) === false &&
			strpos( $meta_key, '_wpml_' ) === false
		) {

			$meta_value = $meta_values[0] ?? '';

			// Only copy if it's an ACF field key (starts with field_)
			if ( strpos( $meta_value, 'field_' ) === 0 || empty( $meta_value ) === false ) {

				// Check if already exists in translation
				$existing = get_post_meta( $new_post_id, $meta_key, true );

				if ( empty( $existing ) ) {
					update_post_meta( $new_post_id, $meta_key, $meta_value );
					$copied++;

					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( "WPML ACF Fix: Copied $meta_key = $meta_value to post $new_post_id" );
					}
				}
			}
		}
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $copied > 0 ) {
		error_log( "WPML ACF Fix: Copied $copied ACF field keys from post $original_post_id to post $new_post_id" );
	}

}, 10, 3 );

/**
 * Alternative hook: wpml_translation_job_saved
 *
 * This runs earlier in the translation process and might be more reliable
 * for some WPML versions.
 */
add_action( 'wpml_translation_job_saved', function( $new_post_id, $data, $job ) {

	if ( ! isset( $job->original_doc_id ) || ! $new_post_id ) {
		return;
	}

	$original_post_id = $job->original_doc_id;

	// Get all ACF field keys from original
	$original_meta = get_post_meta( $original_post_id );

	foreach ( $original_meta as $meta_key => $meta_values ) {

		if (
			strpos( $meta_key, '_' ) === 0 &&
			strpos( $meta_key, '_edit_' ) === false &&
			strpos( $meta_key, '_wp_' ) === false &&
			strpos( $meta_key, '_wpml_' ) === false
		) {

			$meta_value = $meta_values[0] ?? '';

			// Only copy if field key doesn't exist yet
			if ( ! get_post_meta( $new_post_id, $meta_key, true ) ) {
				update_post_meta( $new_post_id, $meta_key, $meta_value );
			}
		}
	}

}, 10, 3 );
