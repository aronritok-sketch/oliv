<?php
/**
 * Titles, meta descriptions, Open Graph and LocalBusiness structured data.
 * Steps aside when an SEO plugin (Yoast, Rank Math, SEOPress, AIOSEO) is active.
 */

defined( 'ABSPATH' ) || exit;

function oy_seo_plugin_active() {
	return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' );
}

function oy_meta_description() {
	if ( is_front_page() ) {
		return __( 'Yoga, mobility and prenatal fitness in Fort Myers, FL: hatha flow and slow flow classes, private yoga at your home, lanai or on the beach, meditation and singing bowl massage with Olivia Kovacs.', 'olivia-yoga' );
	}
	if ( is_singular() ) {
		$id   = get_queried_object_id();
		$desc = oy_tr( $id, 'seo_desc' ) ?: ( oy_translating() ? get_the_excerpt( $id ) : ( get_post_meta( $id, 'oy_seo_desc', true ) ?: get_the_excerpt( $id ) ) );
		return wp_strip_all_tags( $desc );
	}
	return get_bloginfo( 'description' );
}

add_filter( 'pre_get_document_title', function ( $title ) {
	if ( oy_seo_plugin_active() ) {
		return $title;
	}
	if ( is_front_page() ) {
		return __( 'Yoga in Fort Myers, FL: Hatha Flow, Private Yoga & Prenatal Fitness | Olivia Kovacs', 'olivia-yoga' );
	}
	if ( is_singular() ) {
		$id     = get_queried_object_id();
		$custom = oy_tr( $id, 'seo_title' ) ?: ( oy_translating() ? '' : get_post_meta( $id, 'oy_seo_title', true ) );
		if ( $custom ) {
			return $custom;
		}
	}
	return $title;
} );

add_action( 'wp_head', function () {
	if ( oy_seo_plugin_active() ) {
		return;
	}
	$desc = oy_meta_description();
	$url  = is_singular() ? get_permalink() : home_url( add_query_arg( array() ) );
	echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	echo '<meta property="og:site_name" content="Olivia Kovacs Yoga"><meta property="og:locale" content="' . esc_attr( oy_languages()[ oy_lang() ]['locale'] ?? 'en_US' ) . '">';
	echo '<meta property="og:type" content="' . ( is_singular( 'post' ) ? 'article' : 'website' ) . '">';
	echo '<meta property="og:title" content="' . esc_attr( wp_get_document_title() ) . '"><meta property="og:description" content="' . esc_attr( $desc ) . '"><meta property="og:url" content="' . esc_url( $url ) . '">';
	if ( is_singular() && has_post_thumbnail() ) {
		echo '<meta property="og:image" content="' . esc_url( get_the_post_thumbnail_url( null, 'large' ) ) . '">';
	}
	echo '<meta name="twitter:card" content="summary_large_image"><meta name="geo.region" content="US-FL"><meta name="geo.placename" content="Fort Myers">' . "\n";

	$site  = home_url( '/' );
	$graph = array(
		array( '@type' => 'WebSite', '@id' => $site . '#website', 'url' => $site, 'name' => 'Olivia Kovacs Yoga', 'inLanguage' => oy_languages()[ oy_lang() ]['html'] ?? 'en-US' ),
		array(
			'@type'       => array( 'LocalBusiness', 'HealthAndBeautyBusiness' ),
			'@id'         => $site . '#business',
			'name'        => 'Olivia Kovacs Yoga',
			'url'         => $site,
			'email'       => OY_EMAIL,
			'priceRange'  => '$$',
			'sameAs'      => array( OY_INSTAGRAM, OY_FACEBOOK ),
			'description' => 'Yoga, mobility, prenatal fitness, meditation and private singing bowl massage in Fort Myers, FL.',
			'address'     => array( '@type' => 'PostalAddress', 'addressLocality' => 'Fort Myers', 'addressRegion' => 'FL', 'addressCountry' => 'US' ),
			'areaServed'  => array_merge( array( array( '@type' => 'City', 'name' => 'Fort Myers, FL' ) ), array_map( fn( $a ) => array( '@type' => 'Place', 'name' => $a ), OY_AREAS ) ),
			'founder'     => array( '@id' => $site . '#olivia' ),
		),
		array(
			'@type'         => 'Person',
			'@id'           => $site . '#olivia',
			'name'          => 'Olivia Kovacs',
			'jobTitle'      => 'Yoga Teacher | Movement & Wellness Coach',
			'knowsAbout'    => array( 'Yoga', 'Mobility', 'Prenatal Fitness', 'Meditation', 'Singing Bowl Massage' ),
			'sameAs'        => array( OY_INSTAGRAM, OY_FACEBOOK ),
			'hasCredential' => array( '@type' => 'EducationalOccupationalCredential', 'name' => 'Hatha yoga teacher certification (2023)', 'recognizedBy' => array( '@type' => 'Organization', 'name' => 'Yoga Alliance International' ) ),
		),
	);
	if ( is_page( 'faq' ) ) {
		$faqs    = get_posts( array( 'post_type' => 'oy_faq', 'numberposts' => 50, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
		$graph[] = array( '@type' => 'FAQPage', 'mainEntity' => array_map( fn( $f ) => array( '@type' => 'Question', 'name' => get_the_title( $f ), 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_strip_all_tags( oy_post_content( $f ) ) ) ), $faqs ) );
	}
	if ( is_singular( 'post' ) ) {
		$graph[] = array( '@type' => 'BlogPosting', 'headline' => get_the_title(), 'datePublished' => get_the_date( 'c' ), 'author' => array( '@id' => $site . '#olivia' ), 'mainEntityOfPage' => get_permalink() );
	}
	echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}, 1 );

// Account, booking and checkout-return pages don't belong in search results.
add_filter( 'wp_robots', function ( $robots ) {
	if ( function_exists( 'oy_is_studio_page' ) && in_array( oy_is_studio_page(), array( 'book', 'account' ), true ) ) {
		$robots['noindex'] = true;
	}
	return $robots;
} );
