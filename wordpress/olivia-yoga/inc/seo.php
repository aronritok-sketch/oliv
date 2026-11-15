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
	if ( is_front_page() && oy_is_demo() ) {
		return __( 'Hathaflow is a booking system for yoga studios and teachers: timetable, online booking and payment, class passes, memberships, private sessions, online classes and gift cards. This is a demo studio.', 'olivia-yoga' );
	}
	if ( is_front_page() && oy_online_only() ) {
		return __( 'Live online yoga classes with Olivia Kovacs: group flow yoga on Wednesdays, individual online yoga, prenatal fitness, stretching, relaxation and breathing from home. Beginners welcome.', 'olivia-yoga' );
	}
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

// A title returned here is printed as it is (WordPress escapes only its own): every one goes through esc_html (hlprv
// KB 2026-11-12: on a multi-teacher site an editor's class title must not become markup).
add_filter( 'pre_get_document_title', function ( $title ) {
	if ( oy_seo_plugin_active() || '' === (string) $title ) {
		return $title;
	}
	return esc_html( $title );
}, 99 );
add_filter( 'pre_get_document_title', function ( $title ) {
	if ( oy_seo_plugin_active() ) {
		return $title;
	}
	if ( is_front_page() && oy_is_demo() ) {
		return __( 'Hathaflow: yoga studio booking demo – timetable, passes, memberships and online classes', 'olivia-yoga' );
	}
	if ( is_front_page() ) {
		return oy_online_only()
			? __( 'Online Yoga, Live from Home: Flow Yoga, Prenatal & One-to-One Classes | Olivia Jóga', 'olivia-yoga' )
			: __( 'Yoga in Fort Myers, FL: Hatha Flow, Private Yoga & Prenatal Fitness | Olivia Kovacs', 'olivia-yoga' );
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
	echo '<meta property="og:site_name" content="' . esc_attr( oy_site_name() ) . '"><meta property="og:locale" content="' . esc_attr( oy_languages()[ oy_lang() ]['locale'] ?? 'en_US' ) . '">';
	echo '<meta property="og:type" content="' . ( is_singular( 'post' ) ? 'article' : 'website' ) . '">';
	echo '<meta property="og:title" content="' . esc_attr( wp_get_document_title() ) . '"><meta property="og:description" content="' . esc_attr( $desc ) . '"><meta property="og:url" content="' . esc_url( $url ) . '">';
	if ( is_singular() && has_post_thumbnail() ) {
		echo '<meta property="og:image" content="' . esc_url( get_the_post_thumbnail_url( null, 'large' ) ) . '">';
	}
	echo '<meta name="twitter:card" content="summary_large_image">' . ( 'fort-myers' === oy_edition() ? '<meta name="geo.region" content="US-FL"><meta name="geo.placename" content="Fort Myers">' : '' ) . "\n";
	if ( oy_is_demo() ) { // A demo studio: no business or person data for search engines.
		echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'WebSite', 'url' => home_url( '/' ), 'name' => oy_site_name() ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>' . "\n";
		return;
	}

	$site     = home_url( '/' );
	$business = oy_online_only()
		? array(
			'@type'       => 'Organization',
			'@id'         => $site . '#business',
			'name'        => oy_site_name(),
			'url'         => $site,
			'email'       => OY_EMAIL,
			'sameAs'      => array( OY_INSTAGRAM, OY_FACEBOOK ),
			'description' => __( 'Live online yoga classes with Olivia Kovacs: group flow yoga, individual online yoga, prenatal fitness, stretching, relaxation and breathing.', 'olivia-yoga' ),
			'areaServed'  => array( '@type' => 'Country', 'name' => 'Hungary' ),
			'knowsLanguage' => 'hu',
			'founder'     => array( '@id' => $site . '#olivia' ),
			'makesOffer'  => array(
				'@type'       => 'Offer',
				'itemOffered' => array( '@type' => 'Service', 'name' => __( 'Live online yoga classes', 'olivia-yoga' ), 'serviceType' => 'Online yoga class', 'areaServed' => array( '@type' => 'Country', 'name' => 'Hungary' ), 'availableChannel' => array( '@type' => 'ServiceChannel', 'name' => __( 'Live online, in the browser', 'olivia-yoga' ), 'serviceUrl' => oy_page_link( 'schedule-pricing' ) ) ),
			),
		)
		: null;
	$graph    = array(
		array( '@type' => 'WebSite', '@id' => $site . '#website', 'url' => $site, 'name' => oy_site_name(), 'inLanguage' => oy_languages()[ oy_lang() ]['html'] ?? 'en-US' ),
		$business ?? array(
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
			'name'          => oy_teacher_name(),
			'jobTitle'      => 'Yoga Teacher | Movement & Wellness Coach',
			'knowsAbout'    => oy_online_only() ? array( 'Yoga', 'Hatha Yoga', 'Prenatal Fitness', 'Meditation' ) : array( 'Yoga', 'Mobility', 'Prenatal Fitness', 'Meditation', 'Singing Bowl Massage' ),
			'sameAs'        => array( OY_INSTAGRAM, OY_FACEBOOK ),
			'hasCredential' => array( '@type' => 'EducationalOccupationalCredential', 'name' => 'Hatha yoga teacher certification (2023)', 'recognizedBy' => array( '@type' => 'Organization', 'name' => 'Yoga Alliance International' ) ),
		),
	);
	if ( oy_online_only() && function_exists( 'oy_llms_enabled' ) ) {
		$graph = oy_seo_online_graph( $graph, $site );
	}
	if ( is_page( oy_page_slug( 'faq' ) ) ) {
		$faqs    = get_posts( array( 'post_type' => 'oy_faq', 'numberposts' => 50, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
		$graph[] = array( '@type' => 'FAQPage', 'mainEntity' => array_map( fn( $f ) => array( '@type' => 'Question', 'name' => get_the_title( $f ), 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_strip_all_tags( oy_post_content( $f ) ) ) ), $faqs ) );
	}
	if ( is_singular( 'post' ) ) {
		$graph[] = array( '@type' => 'BlogPosting', 'headline' => get_the_title(), 'datePublished' => get_the_date( 'c' ), 'author' => array( '@id' => $site . '#olivia' ), 'mainEntityOfPage' => get_permalink() );
	}
	echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>' . "\n";
}, 1 );

// No author archives and no users in the sitemap: they would show the admin's login name (and there is one author).
add_filter( 'wp_sitemaps_add_provider', fn( $provider, $name ) => 'users' === $name ? false : $provider, 10, 2 );
add_action( 'template_redirect', function () {
	if ( is_author() ) {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
} );

/**
 * Pages that don't belong in search results: account, booking and checkout-return; on the online site also the booking
 * plugin's „Teachers” page (only Olívia teaches there). Áron, 15 November, before adding the sitemap to Search Console:
 * a page marked noindex must not be in the sitemap either (Search Console reports it as an error).
 */
function oy_noindex_page_ids() {
	$ids = array();
	foreach ( array( 'book', 'account' ) as $k ) {
		$ids[] = (int) get_option( 'oys_page_' . $k );
	}
	if ( oy_online_only() ) {
		$t     = get_page_by_path( 'teachers' );
		$ids[] = $t ? (int) $t->ID : 0;
	}
	return array_values( array_filter( $ids ) );
}

add_filter( 'wp_robots', function ( $robots ) {
	if ( is_page() && in_array( (int) get_queried_object_id(), oy_noindex_page_ids(), true ) ) {
		$robots['noindex'] = true;
	}
	return $robots;
} );

add_filter( 'wp_sitemaps_posts_query_args', function ( $args, $post_type ) {
	if ( 'page' === $post_type && oy_noindex_page_ids() ) {
		$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), oy_noindex_page_ids() );
	}
	return $args;
}, 10, 2 );

/**
 * The online site's structured data in Hungarian, with everything an assistant or a search engine needs to recommend her
 * (Áron, 15 November: „a fő kulcsszavakra Magyarországon … Oliviát ajánlják online”): Olívia with her real qualifications,
 * the classes as services with their HUF prices (all on the home page and the classes page, each on its own page with its
 * breadcrumb), and the next live group classes as online events. The prices and times come from the booking plugin.
 */
function oy_seo_online_graph( array $graph, $site ) {
	$about = get_page_by_path( oy_page_slug( 'about' ) );
	foreach ( $graph as &$node ) {
		if ( 'Person' === ( $node['@type'] ?? '' ) ) {
			$node['jobTitle']      = 'Jógaoktató, pre- és postnatális tréner';
			$node['description']   = 'Hatha jógaoktató (Yoga Alliance International, 2023), pre- és postnatális tréner (Fitmummy), tibeti hangtál- és hangmasszázs-terapeuta; 2015-től főállású edző, 2018-tól masszőr. Élő online jógaórákat és online kismamatornát tart magyarul.';
			$node['url']           = $about ? get_permalink( $about ) : $site;
			$node['knowsLanguage'] = 'hu';
			$node['knowsAbout']    = array( 'Online jóga', 'Hatha jóga', 'Flow jóga', 'Kismamatorna', 'Várandós torna', 'Gátizomtorna', 'Stretching', 'Mobilizáció', 'Légzőgyakorlatok', 'Relaxáció', 'Meditáció', 'Yoga', 'Prenatal fitness' );
			$node['hasCredential'] = array(
				array( '@type' => 'EducationalOccupationalCredential', 'name' => 'Hatha jógaoktató (2023)', 'credentialCategory' => 'certificate', 'recognizedBy' => array( '@type' => 'Organization', 'name' => 'Yoga Alliance International' ) ),
				array( '@type' => 'EducationalOccupationalCredential', 'name' => 'Pre- és postnatális tréner', 'credentialCategory' => 'certificate', 'recognizedBy' => array( '@type' => 'Organization', 'name' => 'Fitmummy' ) ),
				array( '@type' => 'EducationalOccupationalCredential', 'name' => 'Tibeti hangtál- és hangmasszázs-terapeuta', 'credentialCategory' => 'certificate' ),
			);
			$node['worksFor']      = array( '@id' => $site . '#business' );
		}
		if ( 'Organization' === ( $node['@type'] ?? '' ) ) {
			$node['description']       = 'Élő, magyar nyelvű online jógaórák Kovács Olíviával: csoportos online flow jóga szerdánként 18:30–20:00, egyéni online jóga, online kismamatorna, online stretching és mobilizáció, relaxáció és légzőgyakorlatok otthonról, a böngészőben.';
			$node['logo']              = get_template_directory_uri() . '/assets/img/logo-online.svg';
			$node['knowsAbout']        = array( 'Online jóga', 'Online kismamatorna', 'Flow jóga', 'Egyéni jógaóra', 'Stretching', 'Légzőgyakorlatok', 'Relaxáció' );
			$node['availableLanguage'] = 'hu';
			$catalog                   = array();
			foreach ( oy_classes() as $c ) {
				$catalog[] = array( '@type' => 'Offer', 'itemOffered' => array( '@id' => get_permalink( $c ) . '#service' ) );
			}
			$node['hasOfferCatalog'] = array( '@type' => 'OfferCatalog', 'name' => 'Online órák', 'itemListElement' => $catalog );
			unset( $node['makesOffer'] );
		}
	}
	unset( $node );
	$single = is_singular( 'oy_class' ) ? get_queried_object() : null;
	$all    = is_front_page() || is_page( oy_page_slug( 'yoga-classes' ) );
	foreach ( oy_classes() as $c ) {
		if ( ! $all && ( ! $single || $single->ID !== $c->ID ) ) {
			continue;
		}
		$service = oy_llms_service_of( $c->post_name );
		$range   = null === $service ? array() : oy_llms_price_range( $service );
		$node    = array(
			'@type'             => 'Service',
			'@id'               => get_permalink( $c ) . '#service',
			'name'              => get_the_title( $c ),
			'description'       => oy_llms_excerpt( $c ),
			'url'               => get_permalink( $c ),
			'serviceType'       => get_the_title( $c ),
			'provider'          => array( '@id' => $site . '#business' ),
			'areaServed'        => array( '@type' => 'Country', 'name' => 'Magyarország' ),
			'availableLanguage' => 'hu',
			'availableChannel'  => array( '@type' => 'ServiceChannel', 'name' => 'Élőben, online, a böngészőben', 'serviceUrl' => oy_page_link( 'schedule-pricing' ) ),
			'image'             => has_post_thumbnail( $c ) ? get_the_post_thumbnail_url( $c, 'large' ) : null,
		);
		if ( $range ) {
			$node['offers'] = array( '@type' => 'AggregateOffer', 'priceCurrency' => 'HUF', 'lowPrice' => $range[0], 'highPrice' => $range[1], 'offerCount' => count( oy_llms_products( $service ) ), 'url' => get_permalink( $c ) );
		}
		$graph[] = $node;
		if ( $single ) {
			$graph[] = array(
				'@type'           => 'BreadcrumbList',
				'itemListElement' => array(
					array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Főoldal', 'item' => $site ),
					array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Online órák', 'item' => oy_page_link( 'yoga-classes' ) ),
					array( '@type' => 'ListItem', 'position' => 3, 'name' => get_the_title( $c ), 'item' => get_permalink( $c ) ),
				),
			);
		}
	}
	// the next live group classes: on the home page, the group class page and the prices page
	if ( is_front_page() || is_page( oy_page_slug( 'schedule-pricing' ) ) || ( $single && 'hatha-flow' === $single->post_name ) ) {
		$flow = get_page_by_path( 'hatha-flow', OBJECT, 'oy_class' );
		foreach ( oy_llms_sessions( 4 ) as $s ) {
			$graph[] = array(
				'@type'               => 'Event',
				'name'                => $s['title'] . ' – élő online jógaóra Kovács Olíviával',
				'description'         => $flow ? oy_llms_excerpt( $flow ) : null,
				'startDate'           => $s['start'],
				'endDate'             => $s['end'],
				'eventStatus'         => 'https://schema.org/EventScheduled',
				'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
				'location'            => array( '@type' => 'VirtualLocation', 'url' => $s['url'] ),
				'inLanguage'          => 'hu',
				'image'               => $flow && has_post_thumbnail( $flow ) ? get_the_post_thumbnail_url( $flow, 'large' ) : null,
				'organizer'           => array( '@id' => $site . '#business' ),
				'performer'           => array( '@id' => $site . '#olivia' ),
				'offers'              => array( '@type' => 'Offer', 'price' => $s['price'], 'priceCurrency' => 'HUF', 'availability' => 'https://schema.org/InStock', 'url' => $s['url'] ),
			);
		}
	}
	return array_values( array_map( fn( $n ) => array_filter( $n, fn( $v ) => null !== $v && '' !== $v ), $graph ) );
}
