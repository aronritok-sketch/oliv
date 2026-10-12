<?php
/**
 * Class types (oy_class), FAQs (oy_faq) and past events (oy_moment), editable in wp-admin.
 * Class pages live at /yoga-classes/{slug}/; the "Classes" overview is a normal page.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	register_post_type( 'oy_class', array(
		'labels'       => array(
			'name'          => __( 'Class types', 'olivia-yoga' ),
			'singular_name' => __( 'Class type', 'olivia-yoga' ),
			'add_new_item'  => __( 'Add class type', 'olivia-yoga' ),
			'edit_item'     => __( 'Edit class type', 'olivia-yoga' ),
		),
		'public'       => true,
		'show_in_rest' => true,
		'menu_icon'    => 'dashicons-heart',
		'menu_position'=> 21,
		'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes' ),
		'rewrite'      => array( 'slug' => 'yoga-classes', 'with_front' => false ),
		'has_archive'  => false,
	) );
	register_post_type( 'oy_faq', array(
		'labels'       => array(
			'name'          => __( 'FAQs', 'olivia-yoga' ),
			'singular_name' => __( 'FAQ', 'olivia-yoga' ),
			'add_new_item'  => __( 'Add question', 'olivia-yoga' ),
		),
		'public'       => false,
		'show_ui'      => true,
		'show_in_rest' => true,
		'menu_icon'    => 'dashicons-editor-help',
		'menu_position'=> 22,
		'supports'     => array( 'title', 'editor', 'page-attributes' ),
	) );
	register_post_type( 'oy_moment', array(
		'labels'       => array(
			'name'          => __( 'Past events', 'olivia-yoga' ),
			'singular_name' => __( 'Past event', 'olivia-yoga' ),
			'add_new_item'  => __( 'Add past event', 'olivia-yoga' ),
			'edit_item'     => __( 'Edit past event', 'olivia-yoga' ),
		),
		'public'       => false,
		'show_ui'      => true,
		'show_in_rest' => true,
		'menu_icon'    => 'dashicons-format-gallery',
		'menu_position'=> 23,
		'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
	) );
	foreach ( array( 'oy_duration', 'oy_lang', 'oy_link', 'oy_aside_photo', 'oy_seo_title' ) as $key ) {
		register_post_meta( 'oy_class', $key, array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ) );
	}
	register_post_meta( 'oy_faq', 'oy_home', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ) );
	foreach ( array( 'oy_date', 'oy_place' ) as $key ) {
		register_post_meta( 'oy_moment', $key, array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ) );
	}
} );

/* ---------- Meta boxes ---------- */

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'oy_class_details', __( 'Class details', 'olivia-yoga' ), 'oy_class_meta_box', 'oy_class', 'side' );
	add_meta_box( 'oy_faq_details', __( 'Show on home page', 'olivia-yoga' ), function ( $post ) {
		wp_nonce_field( 'oy_meta', 'oy_meta_nonce' );
		echo '<label><input type="checkbox" name="oy_home" value="1"' . checked( '1', get_post_meta( $post->ID, 'oy_home', true ), false ) . '> ' . esc_html__( 'Show this question on the home page', 'olivia-yoga' ) . '</label>';
	}, 'oy_faq', 'side' );
	add_meta_box( 'oy_moment_details', __( 'When and where', 'olivia-yoga' ), function ( $post ) {
		wp_nonce_field( 'oy_meta', 'oy_meta_nonce' );
		echo '<p><label for="oy_date"><b>' . esc_html__( 'Date', 'olivia-yoga' ) . '</b></label><br><input type="date" class="widefat" id="oy_date" name="oy_date" value="' . esc_attr( get_post_meta( $post->ID, 'oy_date', true ) ) . '"></p>';
		echo '<p><label for="oy_place"><b>' . esc_html__( 'Place', 'olivia-yoga' ) . '</b></label><br><input class="widefat" id="oy_place" name="oy_place" value="' . esc_attr( get_post_meta( $post->ID, 'oy_place', true ) ) . '" placeholder="MANAS Festival, Hungary"></p>';
		echo '<p class="description">' . esc_html__( 'The photo is the featured image. Past events show on the Events page, newest first.', 'olivia-yoga' ) . '</p>';
	}, 'oy_moment', 'side' );
} );

function oy_class_meta_box( $post ) {
	wp_nonce_field( 'oy_meta', 'oy_meta_nonce' );
	$f = function ( $key, $label, $placeholder = '', $help = '' ) use ( $post ) {
		echo '<p><label for="' . esc_attr( $key ) . '"><b>' . esc_html( $label ) . '</b></label><br><input class="widefat" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( get_post_meta( $post->ID, $key, true ) ) . '" placeholder="' . esc_attr( $placeholder ) . '">'
			. ( $help ? '<span class="description">' . esc_html( $help ) . '</span>' : '' ) . '</p>';
	};
	$f( 'oy_duration', __( 'Length', 'olivia-yoga' ), '60 to 90 min' );
	$f( 'oy_lang', __( 'Language', 'olivia-yoga' ), 'English', __( 'For example "English" or "English or Hungarian".', 'olivia-yoga' ) );
	$f( 'oy_link', __( 'Link to a page instead (slug)', 'olivia-yoga' ), 'private-yoga' );
	$f( 'oy_seo_title', __( 'SEO title', 'olivia-yoga' ) );
}

add_action( 'save_post', function ( $post_id ) {
	if ( ! isset( $_POST['oy_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['oy_meta_nonce'] ), 'oy_meta' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$type = get_post_type( $post_id );
	if ( 'oy_class' === $type ) {
		foreach ( array( 'oy_duration', 'oy_lang', 'oy_link', 'oy_seo_title' ) as $key ) {
			update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
		}
	}
	if ( 'oy_faq' === $type ) {
		update_post_meta( $post_id, 'oy_home', empty( $_POST['oy_home'] ) ? '' : '1' );
	}
	if ( 'oy_moment' === $type ) {
		$date = sanitize_text_field( wp_unslash( $_POST['oy_date'] ?? '' ) );
		update_post_meta( $post_id, 'oy_date', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '' );
		update_post_meta( $post_id, 'oy_place', sanitize_text_field( wp_unslash( $_POST['oy_place'] ?? '' ) ) );
	}
} );

/* ---------- Queries and links ---------- */

function oy_classes() {
	return get_posts( array( 'post_type' => 'oy_class', 'numberposts' => 50, 'orderby' => 'menu_order title', 'order' => 'ASC', 'post_status' => 'publish' ) );
}

function oy_moments( $limit = 60 ) {
	return get_posts( array( 'post_type' => 'oy_moment', 'numberposts' => $limit, 'post_status' => 'publish', 'meta_key' => 'oy_date', 'orderby' => 'meta_value', 'order' => 'DESC' ) );
}

function oy_class_url( $slug ) {
	$post = get_page_by_path( $slug, OBJECT, 'oy_class' );
	if ( $post ) {
		$link = get_post_meta( $post->ID, 'oy_link', true );
		return $link ? oy_page_link( $link ) : get_permalink( $post );
	}
	return oy_page_link( 'yoga-classes' );
}

// Classes that are really their own page (private yoga, corporate) send visitors there.
add_action( 'template_redirect', function () {
	if ( is_singular( 'oy_class' ) ) {
		$link = get_post_meta( get_queried_object_id(), 'oy_link', true );
		if ( $link ) {
			wp_safe_redirect( oy_page_link( $link ), 301 );
			exit;
		}
	}
} );

// The booking plugin shows class names and links from these posts.
add_filter( 'oys_class_titles', function ( $titles ) {
	foreach ( oy_classes() as $c ) {
		$titles[ $c->post_name ] = get_the_title( $c );
	}
	return $titles;
} );
add_filter( 'oys_class_url', function ( $url, $slug ) {
	return oy_class_url( $slug );
}, 10, 2 );
add_filter( 'oys_corporate_contact_url', fn() => oy_contact_url( 'corporate' ) );
add_filter( 'oys_featured_badge', fn() => '<span class="price__tag">' . esc_html__( 'Most popular', 'olivia-yoga' ) . '</span>' );
