<?php
/**
 * Editions. The same theme and plugin serve two versions of the site:
 *
 *  - fort-myers (default): Olivia Kovacs Yoga, the Fort Myers site in English, Hungarian and Spanish,
 *    with studio, beach, private and online classes.
 *  - hu-online: Olivia Jóga – Online, in Hungarian only, for live online classes from Hungary. Hungarian
 *    addresses (/bejelentkezes-es-arak/ …), prices in forints, Budapest time, no Fort Myers content.
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
	// Olívia, 15 November: „maradt az aloldal neve … de már online órák”, „… de már bejelentkezés és árak” – the addresses
	// follow the pages' names (the old ones lead here, see OY_ONLINE_MOVED)
	'yoga-classes'     => 'online-orak',
	'private-yoga'     => 'online-orak', // Olivia, 11.12: the private sessions page went into the classes page
	'schedule-pricing' => 'bejelentkezes-es-arak',
	'faq'              => 'gyakori-kerdesek', // Olívia, 15 November: „aloldal urleket ellenőrizd mindenhol” – after its title
	'contact'          => 'kapcsolat',
	'journal'          => 'jogarol', // „Jógáról” (Olivia, 11.12; was „Napló”)
);

/**
 * Addresses of the online site that moved (Olivia's notes of 12 November 2026): the old ones lead to the new.
 * Keys: the path (without slashes); values: [ kind, target ] – page key, class slug, or the classes page with an anchor.
 */
const OY_ONLINE_MOVED = array(
	'naplo'                         => array( 'page', 'journal' ),
	'online-maganora'               => array( 'anchor', 'egyeni-orak' ),
	'online-jogaorak/slow-flow'     => array( 'page', 'yoga-classes' ),
	'online-jogaorak/private-yoga'  => array( 'class', 'egyeni-online-joga' ),
	'hatha-flow-vs-slow-flow'       => array( 'page', 'journal' ),
	'orarend-es-arak'               => array( 'page', 'schedule-pricing' ),
	'online-jogaorak'               => array( 'page', 'yoga-classes' ),
	'gyik'                          => array( 'page', 'faq' ),
);

/**
 * Olívia, 15 November: „aloldal urleket ellenőrizd mindenhol hogy átírtad-e arra ami az oldalcím/h1” – the public addresses
 * of the class pages follow their titles. The class keeps its own name inside (the bookings and the booking plugin know the
 * classes by it), only the address it is shown at changes; the old address leads to the new one.
 */
const OY_ONLINE_CLASS_PATHS = array(
	'hatha-flow'       => 'csoportos-online-flow-joga',
	'kismamatorna'     => 'online-kismamatorna',
	'stretching'       => 'online-stretching-es-mobilizacio',
	'relaxacio-legzes' => 'relaxacio-es-legzes',
);

/**
 * Olívia, 19 November: she renamed the class types and gave two of them addresses of her own („keresőbarát kifejezésekkel”).
 * Inside, a class keeps its name (the timetable, the bookings and the booking plugin know it by that, and a missing name made
 * the update put the old class in again: „nagyon sok duplikáció lett”). So an address she types in the editor becomes the
 * class's public address (option oy_class_paths); every earlier address leads there.
 */
function oy_class_paths() {
	$own = get_option( 'oy_class_paths', array() );
	return array_merge( OY_ONLINE_CLASS_PATHS, is_array( $own ) ? array_filter( $own, 'is_string' ) : array() );
}

/** The class an address belongs to (its public address now, the theme's, or one it had before); '' when none. */
function oy_class_key_for_path( $path ) {
	$path = (string) $path;
	$key  = array_search( $path, oy_class_paths(), true );
	if ( false === $key ) {
		$key = array_search( $path, OY_ONLINE_CLASS_PATHS, true );
	}
	if ( false === $key ) {
		$old = get_option( 'oy_class_paths_old', array() );
		foreach ( is_array( $old ) ? $old : array() as $k => $list ) {
			if ( in_array( $path, (array) $list, true ) ) {
				$key = $k;
				break;
			}
		}
	}
	return false === $key ? '' : (string) $key;
}

add_filter( 'post_type_link', function ( $link, $post ) {
	$paths = oy_online_only() && 'oy_class' === $post->post_type ? oy_class_paths() : array();
	if ( isset( $paths[ $post->post_name ] ) && $paths[ $post->post_name ] !== $post->post_name ) {
		$link = str_replace( '/' . $post->post_name . '/', '/' . $paths[ $post->post_name ] . '/', $link );
	}
	return $link;
}, 10, 2 );

add_filter( 'request', function ( $vars ) {
	if ( ! empty( $vars['oy_class'] ) && is_string( $vars['oy_class'] ) && oy_online_only() ) {
		$key = oy_class_key_for_path( $vars['oy_class'] );
		if ( '' !== $key && $key !== $vars['oy_class'] ) {
			$vars['oy_class'] = $key;
			$vars['name']     = $key;
		}
	}
	return $vars;
} );

add_action( 'template_redirect', function () {
	if ( ! is_singular( 'oy_class' ) || ! oy_online_only() ) {
		return;
	}
	$name = (string) get_post_field( 'post_name', get_queried_object_id() );
	$want = oy_class_paths()[ $name ] ?? $name;
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	if ( $want === $name || str_contains( $path, '/' . $want . '/' ) ) {
		return;
	}
	$old   = get_option( 'oy_class_paths_old', array() );
	$alias = array_merge( array( $name ), isset( OY_ONLINE_CLASS_PATHS[ $name ] ) ? array( OY_ONLINE_CLASS_PATHS[ $name ] ) : array(), (array) ( is_array( $old ) ? ( $old[ $name ] ?? array() ) : array() ) );
	foreach ( $alias as $a ) {
		if ( '' !== (string) $a && str_contains( $path, '/' . $a . '/' ) ) {
			$query = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_QUERY );
			wp_safe_redirect( get_permalink() . ( '' !== $query ? '?' . $query : '' ), 301 );
			exit;
		}
	}
}, 1 );

/** A class's address changed in the editor: the new one becomes its public address, the name inside stays. */
add_filter( 'wp_insert_post_data', function ( $data, $postarr ) {
	if ( 'oy_class' !== ( $data['post_type'] ?? '' ) || empty( $postarr['ID'] ) || ! empty( $GLOBALS['oy_class_rename_ok'] ) || ! oy_online_only() ) {
		return $data;
	}
	$id  = (int) $postarr['ID'];
	$old = (string) get_post_field( 'post_name', $id );
	$new = (string) ( $data['post_name'] ?? '' );
	if ( '' === $old || '' === $new || $old === $new || 'publish' !== get_post_status( $id ) ) {
		return $data;
	}
	$paths = get_option( 'oy_class_paths', array() );
	$paths = is_array( $paths ) ? $paths : array();
	$now   = oy_class_paths()[ $old ] ?? $old;
	if ( $now !== $new ) {
		$hist         = get_option( 'oy_class_paths_old', array() );
		$hist         = is_array( $hist ) ? $hist : array();
		$hist[ $old ] = array_values( array_unique( array_merge( (array) ( $hist[ $old ] ?? array() ), array( $now ) ) ) );
		update_option( 'oy_class_paths_old', $hist, false );
	}
	$paths[ $old ] = $new;
	update_option( 'oy_class_paths', $paths, true );
	$data['post_name'] = $old;
	return $data;
}, 99, 2 );

// The journal posts of the online edition first had their English addresses (2026-11-12, first day live): the
// Hungarian ones are better for search, the old ones redirect.
const OY_ONLINE_POST_SLUGS = array(
	'hatha-class-elements-stretching' => 'hatha-jogaora-elemei-nyujtas',
	'hatha-class-elements-strengthening' => 'hatha-jogaora-elemei-erosites',
	'hatha-class-elements-vinyasas' => 'hatha-jogaora-elemei-vinyasza',
	'hatha-class-elements-balance' => 'hatha-jogaora-elemei-egyensuly',
	'hatha-class-elements-relaxation' => 'hatha-jogaora-elemei-relaxacio',
	'the-effects-of-stretching' => 'a-nyujtas-hatasa',
	'yoga-and-positive-thinking' => 'joga-es-pozitiv-gondolkodas',
	'world-mental-health-day' => 'lelki-egeszseg-vilagnapja',
	'which-way-of-thinking-is-healthy' => 'melyik-gondolkodas-egeszseges',
	'you-can-achieve-anything-with-your-breath' => 'legzessel-barmit-elerhetsz',
	'music-in-a-yoga-class' => 'zene-a-jogaoran',
	'stop-for-a-moment' => 'allj-meg-egy-pillanatra',
	'yoga-for-thyroid-balance' => 'pajzsmirigy-harmonizalas-jogaval',
	'what-yoga-gives' => 'mit-ad-a-joga',
	'do-you-do-anything-besides-yoga' => 'torodni-magaddal-nem-luxus',
	'meditation' => 'meditacio',
	'eight-limbs-yama' => 'a-joga-8-aga-yama',
	'eight-limbs-niyama' => 'a-joga-8-aga-niyama',
	'eight-limbs-asana' => 'a-joga-8-aga-aszana',
	'eight-limbs-pranayama' => 'a-joga-8-aga-pranajama',
	'eight-limbs-pratyahara' => 'a-joga-8-aga-pratyahara',
	'eight-limbs-dharana' => 'a-joga-8-aga-dharana',
	'eight-limbs-dhyana' => 'a-joga-8-aga-dhjana',
	'eight-limbs-samadhi' => 'a-joga-8-aga-szamadhi',
);

// Later moves of the online journal posts, from their Hungarian address to a new one (the old one redirects). Olívia, 15
// November: „itt is javítsd az url slugot” – the article got her own title, its address follows it.
const OY_ONLINE_POST_MOVED = array(
	'foglalkozol-massal-is' => 'torodni-magaddal-nem-luxus',
);

add_action( 'template_redirect', function () {
	if ( ! is_404() || ! oy_online_only() ) {
		return;
	}
	$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$path = trim( substr( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), strlen( rtrim( $home, '/' ) ) ), '/' );
	$to   = OY_ONLINE_MOVED[ $path ] ?? null;
	if ( ! $to && isset( OY_ONLINE_POST_SLUGS[ $path ] ) ) {
		$to = array( 'path', OY_ONLINE_POST_SLUGS[ $path ] );
	}
	if ( ! $to && isset( OY_ONLINE_POST_MOVED[ $path ] ) ) {
		$to = array( 'path', OY_ONLINE_POST_MOVED[ $path ] );
	}
	if ( ! $to && str_starts_with( $path, 'naplo/' ) ) {
		$to = array( 'path', 'jogarol/' . substr( $path, 6 ) );
	}
	if ( ! $to && str_starts_with( $path, 'online-jogaorak/' ) ) { // the class pages: /online-jogaorak/{class}/ → /online-orak/{class}/
		$cls = substr( $path, 16 );
		$to  = array( 'path', 'online-orak/' . ( OY_ONLINE_CLASS_PATHS[ $cls ] ?? $cls ) );
	}
	if ( $to ) {
		$url = 'class' === $to[0] ? oy_class_url( $to[1] ) : ( 'anchor' === $to[0] ? oy_page_link( 'yoga-classes', $to[1] ) : ( 'path' === $to[0] ? home_url( '/' . $to[1] . '/' ) : oy_page_link( $to[1] ) ) );
		$qs = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_QUERY );
		wp_safe_redirect( '' === $qs || str_contains( $url, '?' ) ? $url : $url . '?' . $qs, 301 );
		exit;
	}
} );

function oy_edition() {
	$edition = defined( 'OY_EDITION' ) ? (string) OY_EDITION : (string) get_option( 'oy_edition', 'fort-myers' );
	return isset( OY_EDITIONS[ $edition ] ) ? $edition : 'fort-myers';
}

/** Is this the Hathaflow product demo (a made-up studio)? */
function oy_is_demo() {
	return 'hathaflow' === oy_edition();
}

/**
 * Should the "Demo site" bar show? On the HathaFlow platform every teacher's site runs this edition, and the
 * platform decides per site (filter `oy_demo_bar`; text: `oy_demo_bar_text`).
 */
function oy_demo_bar() {
	return (bool) apply_filters( 'oy_demo_bar', oy_is_demo() );
}

/**
 * The edition's brand: name, short name (logo), logo line, place, teacher, About label, e-mail, social links.
 * The labels (sub, place, about) are translated; the rest is plain, so the contact constants can be set before the
 * text domain loads.
 */
function oy_brand( $key ) {
	/**
	 * The HathaFlow platform gives every teacher's site its own brand (name, logo text, teacher, e-mail, place …):
	 * return a string from `oy_brand` to replace the edition's value, null to keep it.
	 */
	$custom = apply_filters( 'oy_brand', null, $key, oy_edition() );
	if ( is_string( $custom ) ) {
		return $custom;
	}
	$plain = array(
		'fort-myers' => array( 'name' => 'Olivia Kovacs Yoga', 'short' => 'Olivia Kovacs', 'teacher' => 'Olivia Kovacs', 'email' => 'olivia.kovacs6@gmail.com', 'instagram' => 'https://www.instagram.com/oliivia_yoga/', 'facebook' => 'https://www.facebook.com/oliviajogaoktato', 'place' => 'Fort Myers, FL' ),
		'hu-online'  => array( 'name' => 'Olívia Jóga – Online', 'short' => 'Olívia Jóga', 'teacher' => 'Kovács Olívia', 'email' => 'info@oliviajoga.online', 'instagram' => 'https://www.instagram.com/oliivia_yoga/', 'facebook' => 'https://www.facebook.com/oliviajogaoktato' ), // Hungarian name order
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
			return __( 'Online · live classes', 'olivia-yoga' );
		case 'hu-online/place':
			return __( 'Online, from anywhere', 'olivia-yoga' );
		case 'hathaflow/sub':
			return __( 'Yoga studio · booking demo', 'olivia-yoga' );
		case 'hathaflow/place':
			return __( 'Studio & online', 'olivia-yoga' );
		case 'hathaflow/about':
			return __( 'About the studio', 'olivia-yoga' );
		case 'fort-myers/about':
			return __( 'About Olivia', 'olivia-yoga' );
		case 'hu-online/about':
			return __( 'About me', 'olivia-yoga' ); // Olívia, 14 November: „footerben ez legyen inkább Rólam”
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
	$file  = get_template_directory() . '/demo/' . ( $files[ $edition ?? oy_edition() ] ?? 'content.json' );
	/** A site's own starter content (the HathaFlow platform writes one per teacher from the sign-up answers). */
	$custom = (string) apply_filters( 'oy_content_file', $file, $edition ?? oy_edition() );
	return '' !== $custom && is_readable( $custom ) ? $custom : $file;
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
	if ( class_exists( 'OYS_Services' ) ) {
		update_option( OYS_Services::OPTION, (array) ( $setup['services'] ?? array() ) );
	}
	if ( class_exists( 'OYS_Locations' ) && isset( $setup['locations'] ) ) {
		update_option( OYS_Locations::OPTION, (array) $setup['locations'], false );
	}
	oy_register_content_types(); // Class pages move under the edition's classes page.
	return array( sprintf( 'edition: %s', $edition ) );
}
