<?php
/**
 * Where a photo sits in its frame (Áron, 2026-11-12: „hol helyezkedjen el a kép, melyik része látszódjon”). The
 * frames and crops stay the theme's; in Media → a photo → „Photo position” the editor clicks the point that must
 * always stay in view. Stored as the attachment's `oy_pos` meta ("40% 30%"), which oy_photo() writes out as
 * object-position (the theme's own photos get their starting point from assets/img/images.json on import).
 */

defined( 'ABSPATH' ) || exit;

/** "40% 30%", or '' when not valid. */
function oy_pos_clean( $v ) {
	return preg_match( '/^\s*(\d{1,3})%?\s+(\d{1,3})%?\s*$/', (string) $v, $m ) && (int) $m[1] <= 100 && (int) $m[2] <= 100 ? (int) $m[1] . '% ' . (int) $m[2] . '%' : '';
}

add_filter( 'attachment_fields_to_edit', function ( $fields, $post ) {
	if ( ! wp_attachment_is_image( $post ) ) {
		return $fields;
	}
	$pos       = oy_pos_clean( get_post_meta( $post->ID, 'oy_pos', true ) ) ?: '50% 50%';
	[ $x, $y ] = array_map( 'intval', explode( ' ', str_replace( '%', '', $pos ) ) );
	$src       = (string) wp_get_attachment_image_url( $post->ID, 'medium' );
	$prev      = fn( $cls ) => '<span class="oy-focus__prev oy-focus__prev--' . $cls . '"><img src="' . esc_url( $src ) . '" alt="" style="object-position:' . esc_attr( $pos ) . '"></span>';
	$fields['oy_pos'] = array(
		'label' => __( 'Photo position', 'olivia-yoga' ),
		'input' => 'html',
		'html'  => '<div class="oy-focus" data-oy-focus><div class="oy-focus__pick"><img src="' . esc_url( $src ) . '" alt=""><span class="oy-focus__dot" style="left:' . $x . '%;top:' . $y . '%"></span></div>'
			. '<div class="oy-focus__prevs">' . $prev( 'sq' ) . $prev( 'tall' ) . $prev( 'wide' ) . '</div>'
			. '<input type="text" class="oy-focus__val" id="attachments-' . (int) $post->ID . '-oy_pos" name="attachments[' . (int) $post->ID . '][oy_pos]" value="' . esc_attr( $pos ) . '">'
			. ' <button type="button" class="button-link oy-focus__reset">' . esc_html__( 'Centre', 'olivia-yoga' ) . '</button></div>',
		'helps' => __( 'Click the part of the photo that must always stay in view (a face, for example). The frames and crops stay the same.', 'olivia-yoga' ),
	);
	return $fields;
}, 10, 2 );

add_filter( 'attachment_fields_to_save', function ( $post, $attachment ) {
	if ( isset( $attachment['oy_pos'] ) ) {
		$v = oy_pos_clean( $attachment['oy_pos'] );
		if ( $v && '50% 50%' !== $v ) {
			update_post_meta( (int) $post['ID'], 'oy_pos', $v );
		} else {
			delete_post_meta( (int) $post['ID'], 'oy_pos' );
		}
	}
	return $post;
}, 10, 2 );

add_action( 'admin_enqueue_scripts', function () {
	wp_register_style( 'oy-focus', false, array(), OY_VERSION );
	wp_enqueue_style( 'oy-focus' );
	wp_add_inline_style( 'oy-focus', '.oy-focus{max-width:260px}.oy-focus__pick{position:relative;cursor:crosshair;line-height:0}.oy-focus__pick img{width:100%;height:auto;border-radius:3px}'
		. '.oy-focus__dot{position:absolute;width:16px;height:16px;margin:-8px 0 0 -8px;border-radius:50%;border:3px solid #fff;background:#d92b86;box-shadow:0 0 0 1px rgba(0,0,0,.4);pointer-events:none}'
		. '.oy-focus__prevs{display:flex;gap:6px;align-items:flex-end;margin:8px 0}.oy-focus__prev{display:block;overflow:hidden;border-radius:3px;background:#eee}.oy-focus__prev img{width:100%;height:100%;object-fit:cover;display:block}'
		. '.oy-focus__prev--sq{width:56px;height:56px}.oy-focus__prev--tall{width:48px;height:60px}.oy-focus__prev--wide{width:100px;height:56px}.oy-focus__val{width:7.5em!important}' );
	wp_register_script( 'oy-focus', false, array(), OY_VERSION, true );
	wp_enqueue_script( 'oy-focus' );
	// Delegated: the media modal draws its fields later. A change event lets the modal save the field by itself.
	wp_add_inline_script( 'oy-focus', "(function(){function set(b,x,y,save){x=Math.max(0,Math.min(100,Math.round(x)));y=Math.max(0,Math.min(100,Math.round(y)));var v=x+'% '+y+'%',i=b.querySelector('.oy-focus__val');i.value=v;var d=b.querySelector('.oy-focus__dot');d.style.left=x+'%';d.style.top=y+'%';b.querySelectorAll('.oy-focus__prev img').forEach(function(m){m.style.objectPosition=v;});if(save)i.dispatchEvent(new Event('change',{bubbles:true}));}"
		. "document.addEventListener('click',function(e){var p=e.target.closest&&e.target.closest('.oy-focus__pick');if(p){var r=p.getBoundingClientRect();set(p.closest('[data-oy-focus]'),(e.clientX-r.left)/r.width*100,(e.clientY-r.top)/r.height*100,true);}var c=e.target.closest&&e.target.closest('.oy-focus__reset');if(c){set(c.closest('[data-oy-focus]'),50,50,true);}});"
		. "document.addEventListener('input',function(e){if(!e.target.classList||!e.target.classList.contains('oy-focus__val'))return;var m=e.target.value.match(/^\\s*(\\d{1,3})%?\\s+(\\d{1,3})%?\\s*$/);if(m)set(e.target.closest('[data-oy-focus]'),+m[1],+m[2],false);});})();" );
} );
