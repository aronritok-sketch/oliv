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
		return 'Hatha flow and slow flow yoga classes in Fort Myers, FL, plus private one-on-one yoga at your home, lanai or on the beach. Beginner-friendly. Book with Olivia Kovács.';
	}
	if ( is_singular() ) {
		$id   = get_queried_object_id();
		$desc = get_post_meta( $id, 'oy_seo_desc', true ) ?: get_the_excerpt( $id );
		return wp_strip_all_tags( $desc );
	}
	return get_bloginfo( 'description' );
}

add_filter( 'pre_get_document_title', function ( $title ) {
	if ( oy_seo_plugin_active() ) {
		return $title;
	}
	if ( is_front_page() ) {
		return 'Yoga in Fort Myers, FL — Hatha Flow & Private Yoga | Olivia Kovács';
	}
	if ( is_singular() ) {
		$custom = get_post_meta( get_queried_object_id(), 'oy_seo_title', true );
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
	echo '<meta property="og:site_name" content="Olivia Kovács Yoga"><meta property="og:locale" content="en_US">';
	echo '<meta property="og:type" content="' . ( is_singular( 'post' ) ? 'article' : 'website' ) . '">';
	echo '<meta property="og:title" content="' . esc_attr( wp_get_document_title() ) . '"><meta property="og:description" content="' . esc_attr( $desc ) . '"><meta property="og:url" content="' . esc_url( $url ) . '">';
	if ( is_singular() && has_post_thumbnail() ) {
		echo '<meta property="og:image" content="' . esc_url( get_the_post_thumbnail_url( null, 'large' ) ) . '">';
	}
	echo '<meta name="twitter:card" content="summary_large_image"><meta name="geo.region" content="US-FL"><meta name="geo.placename" content="Fort Myers">' . "\n";

	$site  = home_url( '/' );
	$graph = array(
		array( '@type' => 'WebSite', '@id' => $site . '#website', 'url' => $site, 'name' => 'Olivia Kovács Yoga', 'inLanguage' => 'en-US' ),
		array(
			'@type'       => array( 'LocalBusiness', 'HealthAndBeautyBusiness' ),
			'@id'         => $site . '#business',
			'name'        => 'Olivia Kovács Yoga',
			'url'         => $site,
			'email'       => OY_EMAIL,
			'priceRange'  => '$$',
			'sameAs'      => array( OY_INSTAGRAM, OY_FACEBOOK ),
			'description' => 'Hatha flow, slow flow and private yoga in Fort Myers, FL.',
			'address'     => array( '@type' => 'PostalAddress', 'addressLocality' => 'Fort Myers', 'addressRegion' => 'FL', 'addressCountry' => 'US' ),
			'areaServed'  => array_merge( array( array( '@type' => 'City', 'name' => 'Fort Myers, FL' ) ), array_map( fn( $a ) => array( '@type' => 'Place', 'name' => $a ), OY_AREAS ) ),
			'founder'     => array( '@id' => $site . '#olivia' ),
		),
		array(
			'@type'         => 'Person',
			'@id'           => $site . '#olivia',
			'name'          => 'Olivia Kovács',
			'jobTitle'      => 'Hatha yoga teacher',
			'sameAs'        => array( OY_INSTAGRAM, OY_FACEBOOK ),
			'hasCredential' => array( '@type' => 'EducationalOccupationalCredential', 'name' => 'Hatha yoga teacher certification (2023)', 'recognizedBy' => array( '@type' => 'Organization', 'name' => 'Yoga Alliance International' ) ),
		),
	);
	if ( is_page( 'faq' ) ) {
		$faqs    = get_posts( array( 'post_type' => 'oy_faq', 'numberposts' => 50, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
		$graph[] = array( '@type' => 'FAQPage', 'mainEntity' => array_map( fn( $f ) => array( '@type' => 'Question', 'name' => $f->post_title, 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_strip_all_tags( $f->post_content ) ) ), $faqs ) );
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
