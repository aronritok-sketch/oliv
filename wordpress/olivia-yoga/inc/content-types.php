<?php
/**
 * Class types (oy_class) and FAQs (oy_faq), editable in wp-admin.
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
	foreach ( array( 'oy_duration', 'oy_level', 'oy_intensity', 'oy_link', 'oy_aside_photo', 'oy_seo_title' ) as $key ) {
		register_post_meta( 'oy_class', $key, array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ) );
	}
	register_post_meta( 'oy_faq', 'oy_home', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ) );
} );

/* ---------- Meta boxes ---------- */

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'oy_class_details', __( 'Class details', 'olivia-yoga' ), 'oy_class_meta_box', 'oy_class', 'side' );
	add_meta_box( 'oy_faq_details', __( 'Show on home page', 'olivia-yoga' ), function ( $post ) {
		wp_nonce_field( 'oy_meta', 'oy_meta_nonce' );
		echo '<label><input type="checkbox" name="oy_home" value="1"' . checked( '1', get_post_meta( $post->ID, 'oy_home', true ), false ) . '> ' . esc_html__( 'Show this question on the home page', 'olivia-yoga' ) . '</label>';
	}, 'oy_faq', 'side' );
} );

function oy_class_meta_box( $post ) {
	wp_nonce_field( 'oy_meta', 'oy_meta_nonce' );
	$f = function ( $key, $label, $placeholder = '' ) use ( $post ) {
		echo '<p><label for="' . esc_attr( $key ) . '"><b>' . esc_html( $label ) . '</b></label><br><input class="widefat" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( get_post_meta( $post->ID, $key, true ) ) . '" placeholder="' . esc_attr( $placeholder ) . '"></p>';
	};
	$f( 'oy_duration', __( 'Length', 'olivia-yoga' ), '60 min' );
	$f( 'oy_level', __( 'Level', 'olivia-yoga' ), 'All levels' );
	echo '<p><label for="oy_intensity"><b>' . esc_html__( 'Intensity', 'olivia-yoga' ) . '</b></label><br><select id="oy_intensity" name="oy_intensity">';
	foreach ( array( 1 => __( 'Gentle', 'olivia-yoga' ), 2 => __( 'Moderate', 'olivia-yoga' ), 3 => __( 'Dynamic', 'olivia-yoga' ) ) as $n => $l ) {
		echo '<option value="' . (int) $n . '"' . selected( (int) get_post_meta( $post->ID, 'oy_intensity', true ), $n, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select></p>';
	$f( 'oy_link', __( 'Link to a page instead (slug)', 'olivia-yoga' ), 'private-yoga' );
	$f( 'oy_seo_title', __( 'SEO title', 'olivia-yoga' ) );
}

add_action( 'save_post', function ( $post_id ) {
	if ( ! isset( $_POST['oy_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['oy_meta_nonce'] ), 'oy_meta' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$type = get_post_type( $post_id );
	if ( 'oy_class' === $type ) {
		foreach ( array( 'oy_duration', 'oy_level', 'oy_intensity', 'oy_link', 'oy_seo_title' ) as $key ) {
			update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
		}
	}
	if ( 'oy_faq' === $type ) {
		update_post_meta( $post_id, 'oy_home', empty( $_POST['oy_home'] ) ? '' : '1' );
	}
} );

/* ---------- Queries and links ---------- */

function oy_classes() {
	return get_posts( array( 'post_type' => 'oy_class', 'numberposts' => 50, 'orderby' => 'menu_order title', 'order' => 'ASC', 'post_status' => 'publish' ) );
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
		$titles[ $c->post_name ] = $c->post_title;
	}
	return $titles;
} );
add_filter( 'oys_class_url', function ( $url, $slug ) {
	return oy_class_url( $slug );
}, 10, 2 );
add_filter( 'oys_corporate_contact_url', fn() => oy_contact_url( 'corporate' ) );
add_filter( 'oys_featured_badge', fn() => oy_sticker( 'Most popular ✺ Most popular ✺ ', 'sticker--price' ) . '<span class="sr-only">' . esc_html__( 'Most popular', 'olivia-yoga' ) . '</span>' );
