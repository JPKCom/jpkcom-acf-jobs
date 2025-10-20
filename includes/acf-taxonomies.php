<?php
/**
 * Custom Taxonomies
 */

if ( ! defined( constant_name: 'WPINC' ) ) {
    die;
}

add_action( 'init', function(): void {
	register_taxonomy( 'job-attribute', array(
	0 => 'job',
), array(
	'labels' => array(
		'name' => 'Job Attribute',
		'singular_name' => 'Job Attribut',
		'menu_name' => 'Job Attribute',
		'all_items' => 'Alle Job Attribute',
		'edit_item' => 'Job Attribut bearbeiten',
		'view_item' => 'Job Attribut anzeigen',
		'update_item' => 'Job Attribut aktualisieren',
		'add_new_item' => 'Neu hinzufügen: Job Attribut',
		'new_item_name' => 'Neuer Job Attribut-Name',
		'search_items' => 'Job Attribut suchen',
		'popular_items' => 'Beliebte Job Attribute',
		'separate_items_with_commas' => 'Trenne Job Attribute durch Kommas',
		'add_or_remove_items' => 'Job Attribut hinzufügen oder entfernen',
		'choose_from_most_used' => 'Wähle aus den meistgenutzten Job Attributen',
		'not_found' => 'Job Attribute konnten nicht gefunden werden',
		'no_terms' => 'Keine Job Attribute-Taxonomien',
		'items_list_navigation' => 'Job Attribut-Listen-Navigation',
		'items_list' => 'Job Attribute-Liste',
		'back_to_items' => '← Zu Job Attributen gehen',
		'item_link' => 'Job Attribut-Link',
		'item_link_description' => 'Ein Link zu einer Taxonomie Job Attribut',
	),
	'description' => 'Job Attribute, Benefits etc. wie z. B. Parkplatz, Firmenwagen, 4-Tage-Woche etc.',
	'public' => false,
	'show_ui' => true,
	'show_in_menu' => true,
	'show_in_rest' => true,
	'show_tagcloud' => false,
	'meta_box_cb' => false,
	'rewrite' => array(
		'with_front' => false,
	),
	'sort' => true,
) );
} );
