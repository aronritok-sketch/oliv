<?php
/**
 * Editions. The same theme and plugin serve two versions of the site:
 *
 *  - fort-myers (default): Olivia Kovacs Yoga, the Fort Myers site in English, Hungarian and Spanish,
 *    with studio, beach, private and online classes.
 *  - hu-online: Olivia Jóga – Online, in Hungarian only, for live online classes from Hungary. Hungarian
 *    addresses (/orarend-es-arak/ …), prices in forints, Budapest time, no Fort Myers content.
 *  - hathaflow: Hathaflow, the booking system as a product demo – a made-up studio in English with sample
 *    classes, timetable and prices, no Olivia content and none of her photos (the hathaflow child theme
 *    ships neutral pictures). A bar on every page says it is a demo.
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
	'hathaflow'  => 'Hathaflow: booking system demo (English, sample studio)',
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

/** Is this the Hathaflow product demo (a made-up studio)? */
function oy_is_demo() {
	return 'hathaflow' === oy_edition();
}

/**
 * The edition's brand: name, short name (logo), logo line, place, teacher, About label, e-mail, social links.
 * The labels (sub, place, about) are translated; the rest is plain, so the contact constants can be set before the
 * text domain loads.
 */
function oy_brand( $key ) {
	$plain = array(
		'fort-myers' => array( 'name' => 'Olivia Kovacs Yoga', 'short' => 'Olivia Kovacs', 'teacher' => 'Olivia Kovacs', 'email' => 'olivia.kovacs6@gmail.com', 'instagram' => 'https://www.instagram.com/oliivia_yoga/', 'facebook' => 'https://www.facebook.com/oliviajogaoktato', 'place' => 'Fort Myers, FL' ),
		'hu-online'  => array( 'name' => 'Olivia Jóga – Online', 'short' => 'Olivia Jóga', 'teacher' => 'Kovács Olívia', 'email' => 'olivia.kovacs6@gmail.com', 'instagram' => 'https://www.instagram.com/oliivia_yoga/', 'facebook' => 'https://www.facebook.com/oliviajogaoktato' ), // Hungarian name order
		'hathaflow'  => array( 'name' => 'Hathaflow', 'short' => 'Hathaflow', 'teacher' => 'Hathaflow Studio', 'email' => 'hello@example.com', 'instagram' => '', 'facebook' => '' ),
	);
	$edition = oy_edition();
	if ( isset( $plain[ $edition ][ $key ] ) ) {
		return $plain[ $edition ][ $key ];
	}
	switch ( $edition . '/' . $key ) {
		case 'fort-myers/sub':
			return __( 'Yoga & movement · Fort Myers', 'olivia-yoga' );
		case 'hu-online/sub':
			return __( 'Online · live classes in Hungarian', 'olivia-yoga' );
		case 'hu-online/place':
			return __( 'Online, from anywhere', 'olivia-yoga' );
		case 'hathaflow/sub':
			return __( 'Yoga studio · booking demo', 'olivia-yoga' );
		case 'hathaflow/place':
			return __( 'Studio & online', 'olivia-yoga' );
		case 'hathaflow/about':
			return __( 'About the studio', 'olivia-yoga' );
		case 'fort-myers/about':
		case 'hu-online/about':
			return __( 'About Olivia', 'olivia-yoga' );
	}
	return '';
}

/**
 * The teacher's name as the edition writes it.
 */
function oy_teacher_name() {
	return oy_brand( 'teacher' );
}

/** Is this the Hungarian, online-only edition? */
function oy_online_only() {
	return 'hu-online' === oy_edition();
}

/** The site's name in titles, the footer and structured data. */
function oy_site_name() {
	return oy_brand( 'name' );
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
	$files = array( 'hu-online' => 'content-hu-online.json', 'hathaflow' => 'content-hathaflow.json' );
	return get_template_directory() . '/demo/' . ( $files[ $edition ?? oy_edition() ] ?? 'content.json' );
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
