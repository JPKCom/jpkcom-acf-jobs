<?php
/**
 * Custom post type registration
 *
 * Registers three interconnected custom post types:
 * - job: Main job postings (public, has archive)
 * - job_location: Work locations (nested under jobs in admin)
 * - job_company: Hiring companies (nested under jobs in admin)
 *
 * Post Type Features:
 * - All support revisions
 * - job_location and job_company are queryable but not public (no frontend URLs by default)
 * - All are available in REST API for block editor
 * - WPML translatable (see wpml-config.xml)
 * - Nested menu structure for better organization
 *
 * @package   JPKCom_ACF_Jobs
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'WPINC' ) ) {
    die;
}

/**
 * Register custom post types for job management system
 *
 * Registers job_location, job_company, and job post types.
 * Location and company types are nested under the job menu in admin.
 *
 * @since 1.0.0
 * @return void
 */
add_action( 'init', function(): void {
	register_post_type( 'job_location', array(
	'labels' => array(
		'name' => 'Arbeitsorte',
		'singular_name' => 'Arbeitsort',
		'menu_name' => 'Arbeitsorte',
		'all_items' => 'Alle Arbeitsorte',
		'edit_item' => 'Arbeitsort bearbeiten',
		'view_item' => 'Arbeitsort anzeigen',
		'view_items' => 'Arbeitsorte anzeigen',
		'add_new_item' => 'Neu hinzufügen: Arbeitsort',
		'add_new' => 'Neu hinzufügen: Arbeitsort',
		'new_item' => 'Neuer Inhaltstyp Arbeitsort',
		'parent_item_colon' => 'Arbeitsort, übergeordnet:',
		'search_items' => 'Arbeitsorte suchen',
		'not_found' => 'Arbeitsorte konnten nicht gefunden werden',
		'not_found_in_trash' => 'Arbeitsorte konnten nicht im Papierkorb gefunden werden',
		'archives' => 'Arbeitsort-Archive',
		'attributes' => 'Arbeitsort-Attribute',
		'insert_into_item' => 'In arbeitsort einfügen',
		'uploaded_to_this_item' => 'Zu diesem Arbeitsort hochgeladen',
		'filter_items_list' => 'Arbeitsorte-Liste filtern',
		'filter_by_date' => 'Arbeitsorte nach Datum filtern',
		'items_list_navigation' => 'Arbeitsorte-Listen-Navigation',
		'items_list' => 'Arbeitsorte-Liste',
		'item_published' => 'Arbeitsort wurde veröffentlicht.',
		'item_published_privately' => 'Arbeitsort wurde privat veröffentlicht.',
		'item_reverted_to_draft' => 'Arbeitsort wurde auf Entwurf zurückgesetzt.',
		'item_scheduled' => 'Arbeitsort wurde geplant.',
		'item_updated' => 'Arbeitsort wurde aktualisiert.',
		'item_link' => 'Arbeitsort-Link',
		'item_link_description' => 'Ein Link zu einem Inhaltstyp Arbeitsort',
	),
	'description' => 'Geben Sie hier Details zum Arbeitsort an.',
	'public' => false,
	'publicly_queryable' => true,
	'show_ui' => true,
	'show_in_menu' => 'edit.php?post_type=job',
	'show_in_admin_bar' => false,
	'show_in_rest' => true,
	'menu_icon' => 'dashicons-admin-post',
	'supports' => array(
		0 => 'title',
		1 => 'revisions',
	),
	'delete_with_user' => false,
) );

	register_post_type( 'job_company', array(
	'labels' => array(
		'name' => 'Firmen',
		'singular_name' => 'Firma',
		'menu_name' => 'Firmen',
		'all_items' => 'Alle Firmen',
		'edit_item' => 'Firma bearbeiten',
		'view_item' => 'Firma anzeigen',
		'view_items' => 'Firmen anzeigen',
		'add_new_item' => 'Neu hinzufügen: Firma',
		'add_new' => 'Neu hinzufügen: Firma',
		'new_item' => 'Neuer Inhaltstyp Firma',
		'parent_item_colon' => 'Firma, übergeordnet:',
		'search_items' => 'Firmen suchen',
		'not_found' => 'Firmen konnten nicht gefunden werden',
		'not_found_in_trash' => 'Firmen konnten nicht im Papierkorb gefunden werden',
		'archives' => 'Firmen-Archive',
		'attributes' => 'Firmen-Attribute',
		'insert_into_item' => 'In Firma einfügen',
		'uploaded_to_this_item' => 'Zu dieser Firma hochgeladen',
		'filter_items_list' => 'Firmen-Liste filtern',
		'filter_by_date' => 'Firmen nach Datum filtern',
		'items_list_navigation' => 'Firmen-Listen-Navigation',
		'items_list' => 'Firmen-Liste',
		'item_published' => 'Firma wurde veröffentlicht.',
		'item_published_privately' => 'Firma wurde privat veröffentlicht.',
		'item_reverted_to_draft' => 'Firma wurde auf Entwurf zurückgesetzt.',
		'item_scheduled' => 'Firma wurde geplant.',
		'item_updated' => 'Firma wurde aktualisiert.',
		'item_link' => 'Firmen-Link',
		'item_link_description' => 'Ein Link zu einem Inhaltstyp Firma',
	),
	'description' => 'Firmendaten für Stellenangebote.',
	'public' => false,
	'publicly_queryable' => true,
	'show_ui' => true,
	'show_in_menu' => 'edit.php?post_type=job',
	'show_in_admin_bar' => false,
	'show_in_rest' => true,
	'menu_icon' => 'dashicons-admin-post',
	'supports' => array(
		0 => 'title',
		1 => 'revisions',
	),
	'delete_with_user' => false,
) );

	register_post_type( 'job', array(
	'labels' => array(
		'name' => 'Jobs',
		'singular_name' => 'Job',
		'menu_name' => 'Jobs',
		'all_items' => 'Alle Jobs',
		'edit_item' => 'Job bearbeiten',
		'view_item' => 'Job anzeigen',
		'view_items' => 'Jobs anzeigen',
		'add_new_item' => 'Neu hinzufügen: Job',
		'add_new' => 'Neu hinzufügen: Job',
		'new_item' => 'Neuer Inhaltstyp Job',
		'parent_item_colon' => 'Job, übergeordnet:',
		'search_items' => 'Jobs suchen',
		'not_found' => 'Job konnten nicht gefunden werden',
		'not_found_in_trash' => 'Job konnten nicht im Papierkorb gefunden werden',
		'archives' => 'Job-Archive',
		'attributes' => 'Job-Attribute',
		'insert_into_item' => 'In job einfügen',
		'uploaded_to_this_item' => 'Zu diesem job hochgeladen',
		'filter_items_list' => 'Jobs-Liste filtern',
		'filter_by_date' => 'Jobs nach Datum filtern',
		'items_list_navigation' => 'Jobs-Listen-Navigation',
		'items_list' => 'Jobs-Liste',
		'item_published' => 'Job wurde veröffentlicht.',
		'item_published_privately' => 'Job wurde privat veröffentlicht.',
		'item_reverted_to_draft' => 'Job wurde auf Entwurf zurückgesetzt.',
		'item_scheduled' => 'Job wurde geplant.',
		'item_updated' => 'Job wurde aktualisiert.',
		'item_link' => 'Job-Link',
		'item_link_description' => 'Ein Link zu einem Inhaltstyp job',
	),
	'description' => 'Stellenangebot',
	'public' => true,
	'show_in_rest' => true,
	'rest_base' => 'jobs',
	'menu_icon' => 'dashicons-businessman',
	'supports' => array(
		0 => 'title',
		1 => 'author',
		2 => 'excerpt',
		3 => 'revisions',
		4 => 'thumbnail',
		5 => 'custom-fields',
	),
	'has_archive' => 'jobs',
	'delete_with_user' => false,
) );
} );


/**
 * Customize title placeholder text for custom post types
 *
 * Changes the default "Add title" placeholder in the post editor
 * to context-specific placeholders for each post type.
 *
 * @since 1.0.0
 *
 * @param string  $default Default placeholder text.
 * @param WP_Post $post    Current post object.
 * @return string Modified placeholder text or original default.
 */
add_filter( 'enter_title_here', function( string $default, \WP_Post $post ): string {
	switch ( $post->post_type ) {
		case 'job_location':
			return 'Standortname';
		case 'job_company':
			return 'Firmenname';
		case 'job':
			return 'Position';
	}

	return $default;
}, 10, 2 );
