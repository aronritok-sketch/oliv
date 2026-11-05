<?php
/**
 * Editions. The same theme and plugin serve two versions of the site:
 *
 *  - fort-myers (default): Olivia Kovacs Yoga, the Fort Myers site in English, Hungarian and Spanish,
 *    with studio, beach, private and online classes.
 *  - hu-online: Olivia Jóga – Online, in Hungarian only, for live online classes from Hungary. Hungarian
 *    addresses (/orarend-es-arak/ …), prices in forints, Budapest time, no Fort Myers content.
 *
 * The edition is chosen in Appearance → Olivia setup (option `oy_edition`); OY_EDITION in wp-config.php
 * overrides it. Switching sets the booking plugin's languages, currency, prices and the site's time
 * zone from the edition's content file (demo/content.json or demo/content-hu-online.json); it is meant
 * for a fresh site, before the content is imported.
 */

defined( 'ABSPATH' ) || exit;

const OY_EDITIONS = array(
	'fort-myers' => 'Olivia Kovacs Yoga: Fort Myers (English, Hungarian, Spanish)',
	'hu-online'  => 'Olivia Jóga – Online (Hungarian, live online classes only)',
);

/** Page addresses of the online edition, by the theme's own page slug. */
const OY_ONLINE_SLUGS = array(
	'about'            => 'rolam',
	'yoga-classes'     => 'online-jogaorak',
	'private-yoga'     => 'online-maganora',
	'schedule-pricing' => 'orarend-es-arak',
	'faq'              => 'gyik',
	'contact'          => 'kapcsolat',
	'journal'          => 'naplo',
);

function oy_edition() {
	$edition = defined( 'OY_EDITION' ) ? (string) OY_EDITION : (string) get_option( 'oy_edition', 'fort-myers' );
	return isset( OY_EDITIONS[ $edition ] ) ? $edition : 'fort-myers';
}

/** Is this the Hungarian, online-only edition? */
function oy_online_only() {
	return 'hu-online' === oy_edition();
}

function oy_site_name() {
	return oy_online_only() ? 'Olivia Jóga – Online' : 'Olivia Kovacs Yoga';
}

/** The address of one of the theme's pages in this edition (about → rolam). */
function oy_page_slug( $slug ) {
	return oy_online_only() ? ( OY_ONLINE_SLUGS[ $slug ] ?? $slug ) : $slug;
}

/** The theme's own slug for a page address (rolam → about). */
function oy_page_key( $slug ) {
	if ( oy_online_only() ) {
		$key = array_search( (string) $slug, OY_ONLINE_SLUGS, true );
		if ( false !== $key ) {
			return $key;
		}
	}
	return (string) $slug;
}

/** The approved copy of this edition. */
function oy_content_file( $edition = null ) {
	return get_template_directory() . '/demo/' . ( 'hu-online' === ( $edition ?? oy_edition() ) ? 'content-hu-online.json' : 'content.json' );
}

/**
 * Switch the site to an edition: the booking plugin's settings and prices and WordPress's time zone and
 * date formats come from the edition's content file ("setup" block). Returns a short log.
 */
function oy_switch_edition( $edition ) {
	if ( ! isset( OY_EDITIONS[ $edition ] ) ) {
		return array();
	}
	update_option( 'oy_edition', $edition );
	$data  = json_decode( (string) file_get_contents( oy_content_file( $edition ) ), true );
	$setup = (array) ( $data['setup'] ?? array() );
	foreach ( (array) ( $setup['options'] ?? array() ) as $key => $value ) {
		update_option( $key, $value );
	}
	if ( class_exists( 'OYS_Settings' ) && ! empty( $setup['studio'] ) ) {
		OYS_Settings::update( (array) $setup['studio'] );
	}
	if ( class_exists( 'OYS_Products' ) && ! empty( $setup['products'] ) ) {
		update_option( OYS_Products::OPTION, (array) $setup['products'] );
	}
	if ( class_exists( 'OYS_Locations' ) && isset( $setup['locations'] ) ) {
		update_option( OYS_Locations::OPTION, (array) $setup['locations'], false );
	}
	oy_register_content_types(); // Class pages move under the edition's classes page.
	return array( sprintf( 'edition: %s', $edition ) );
}
