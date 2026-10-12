<?php
/**
 * "Translations" box under the editor: Hungarian and Spanish versions of a page, class, FAQ,
 * journal post or past event. Empty fields fall back to the English original on the site.
 */

defined( 'ABSPATH' ) || exit;

const OY_TR_TYPES = array( 'page', 'post', 'oy_class', 'oy_faq', 'oy_moment' );

/** Which fields a post type offers for translation. */
function oy_tr_fields_for( $type ) {
	$f = array(
		'title'    => __( 'Title', 'olivia-yoga' ),
		'excerpt'  => __( 'Summary (excerpt)', 'olivia-yoga' ),
		'content'  => __( 'Text', 'olivia-yoga' ),
	);
	if ( 'oy_faq' === $type ) {
		unset( $f['excerpt'] );
		$f['title']   = __( 'Question', 'olivia-yoga' );
		$f['content'] = __( 'Answer', 'olivia-yoga' );
	}
	if ( 'oy_class' === $type ) {
		$f['duration'] = __( 'Length', 'olivia-yoga' );
		$f['lang']     = __( 'Language', 'olivia-yoga' );
	}
	if ( 'oy_moment' === $type ) {
		$f['place'] = __( 'Place', 'olivia-yoga' );
	}
	if ( in_array( $type, array( 'page', 'post', 'oy_class' ), true ) ) {
		$f['seo_title'] = __( 'SEO title', 'olivia-yoga' );
		$f['seo_desc']  = __( 'SEO description', 'olivia-yoga' );
	}
	return $f;
}

add_action( 'add_meta_boxes', function () {
	foreach ( OY_TR_TYPES as $type ) {
		add_meta_box( 'oy_translations', __( 'Translations', 'olivia-yoga' ), 'oy_translations_box', $type, 'normal', 'default' );
	}
} );

function oy_translations_box( $post ) {
	wp_nonce_field( 'oy_tr', 'oy_tr_nonce' );
	$langs  = array_diff_key( oy_languages(), array( 'en' => 1 ) );
	$fields = oy_tr_fields_for( $post->post_type );
	echo '<p class="description">' . esc_html__( 'The Hungarian and Spanish site show these versions. Leave a field empty and the English original is shown there.', 'olivia-yoga' ) . '</p>';
	echo '<div class="oy-tr">';
	$first = true;
	foreach ( $langs as $code => $l ) {
		echo '<details class="oy-tr__lang"' . ( $first ? ' open' : '' ) . '><summary><b>' . esc_html( $l['native'] ) . '</b> <code>/' . esc_html( $code ) . '/</code></summary>';
		foreach ( $fields as $field => $label ) {
			$name  = 'oy_tr[' . $code . '][' . $field . ']';
			$id    = 'oy-tr-' . $code . '-' . $field;
			$value = (string) get_post_meta( $post->ID, oy_tr_key( $field, $code ), true );
			echo '<p><label for="' . esc_attr( $id ) . '"><b>' . esc_html( $label ) . '</b></label><br>';
			if ( 'content' === $field ) {
				echo '</p>';
				wp_editor( $value, $id, array( 'textarea_name' => $name, 'textarea_rows' => 10, 'media_buttons' => false ) );
			} elseif ( in_array( $field, array( 'excerpt', 'seo_desc' ), true ) ) {
				echo '<textarea class="widefat" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea></p>';
			} else {
				echo '<input class="widefat" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></p>';
			}
		}
		echo '</details>';
		$first = false;
	}
	echo '</div><style>.oy-tr__lang{border:1px solid #dcdcde;border-radius:6px;padding:.4rem .9rem;margin:.6rem 0}.oy-tr__lang summary{cursor:pointer;padding:.3rem 0}.oy-tr__lang code{font-size:11px}</style>';
}

add_action( 'save_post', function ( $post_id, $post ) {
	if ( ! in_array( $post->post_type, OY_TR_TYPES, true ) || ! isset( $_POST['oy_tr_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['oy_tr_nonce'] ), 'oy_tr' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$in     = (array) wp_unslash( $_POST['oy_tr'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$fields = oy_tr_fields_for( $post->post_type );
	foreach ( array_keys( array_diff_key( oy_languages(), array( 'en' => 1 ) ) ) as $code ) {
		foreach ( array_keys( $fields ) as $field ) {
			$raw   = (string) ( $in[ $code ][ $field ] ?? '' );
			$value = 'content' === $field ? wp_kses_post( $raw ) : ( in_array( $field, array( 'excerpt', 'seo_desc' ), true ) ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw ) );
			if ( '' === trim( $value ) ) {
				delete_post_meta( $post_id, oy_tr_key( $field, $code ) );
			} else {
				update_post_meta( $post_id, oy_tr_key( $field, $code ), $value );
			}
		}
	}
}, 10, 2 );
