<?php
/**
 * Teachers other than Olivia: a public profile (name, short headline, bio, photos), the classes
 * they teach (`teacher_id` on weekly classes and dates; 0 = the studio's own), a login of their
 * own (role `oys_teacher`, capability `oys_teach`: Teaching → their classes, rosters, messages,
 * profile, statement), their share of the class income and their own Stripe account
 * (see OYS_Connect).
 *
 * Shortcode [oys_teachers] lists the active teachers; the booking page and emails show who
 * teaches each class.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Teachers {

	private static $cache = array();

	public static function init() {
		add_shortcode( 'oys_teachers', array( __CLASS__, 'shortcode' ) );
	}

	public static function table() {
		return OYS_Install::table( 'teachers' );
	}

	public static function all( $active_only = false ) {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ( $active_only ? ' WHERE active = 1' : '' ) . ' ORDER BY active DESC, sort, name' );
	}

	public static function get( $id ) {
		$id = (int) $id;
		if ( ! $id ) {
			return null;
		}
		if ( ! array_key_exists( $id, self::$cache ) ) {
			global $wpdb;
			self::$cache[ $id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		}
		return self::$cache[ $id ];
	}

	public static function flush() {
		self::$cache = array();
	}

	public static function by_user( $user_id ) {
		global $wpdb;
		return $user_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d', $user_id ) ) : null;
	}

	public static function by_slug( $slug ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE slug = %s', sanitize_title( $slug ) ) );
	}

	/** The teacher id if it exists, else 0 (the studio's own class). */
	public static function valid_id( $id ) {
		return self::get( $id ) ? (int) $id : 0;
	}

	/** The teacher of the logged-in user (a teacher login), or null. */
	public static function current() {
		return current_user_can( 'oys_teach' ) ? self::by_user( get_current_user_id() ) : null;
	}

	public static function for_session( $s ) {
		return $s && ! empty( $s->teacher_id ) ? self::get( $s->teacher_id ) : null;
	}

	/** Who teaches it: the teacher's name, or '' for the studio's own classes. */
	public static function name_for( $s ) {
		$t = self::for_session( $s );
		return $t ? $t->name : '';
	}

	/** The name to show for any class: the teacher, or the owner (Olivia). */
	public static function display_name( $s ) {
		return self::name_for( $s ) ?: (string) OYS_Settings::get( 'owner_name' );
	}

	public static function owns( $teacher, $session ) {
		return $teacher && $session && (int) $session->teacher_id === (int) $teacher->id;
	}

	/**
	 * Create or update. Fields: name, email, headline, bio, share_percent, cash_by, notify, active, sort.
	 * @return int|WP_Error teacher id
	 */
	public static function save( array $d, $id = 0 ) {
		global $wpdb;
		$old  = $id ? self::get( $id ) : null;
		$name = sanitize_text_field( $d['name'] ?? ( $old->name ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'oys_teacher', __( 'Add the teacher\'s name.', 'olivia-studio' ) );
		}
		$email = sanitize_email( $d['email'] ?? ( $old->email ?? '' ) );
		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'oys_teacher', __( 'That email address doesn\'t look right.', 'olivia-studio' ) );
		}
		$row = array(
			'name'          => $name,
			'email'         => $email,
			'headline'      => sanitize_text_field( $d['headline'] ?? ( $old->headline ?? '' ) ),
			'bio'           => sanitize_textarea_field( $d['bio'] ?? ( $old->bio ?? '' ) ),
			'share_percent' => min( 100, max( 0, (int) ( $d['share_percent'] ?? ( $old->share_percent ?? OYS_Settings::get( 'teacher_share_default' ) ) ) ) ),
			'cash_by'       => 'studio' === ( $d['cash_by'] ?? ( $old->cash_by ?? 'teacher' ) ) ? 'studio' : 'teacher',
			'notify'        => array_key_exists( 'notify', $d ) ? ( empty( $d['notify'] ) ? 0 : 1 ) : (int) ( $old->notify ?? 1 ),
			'active'        => array_key_exists( 'active', $d ) ? ( empty( $d['active'] ) ? 0 : 1 ) : (int) ( $old->active ?? 1 ),
			'sort'          => (int) ( $d['sort'] ?? ( $old->sort ?? 0 ) ),
		);
		if ( ! $old ) {
			$base = sanitize_title( $name ) ?: 'teacher';
			$slug = $base;
			for ( $i = 2; self::by_slug( $slug ); $i++ ) {
				$slug = $base . '-' . $i;
			}
			$row['slug']       = $slug;
			$row['created_at'] = oys_now();
			$wpdb->insert( self::table(), $row );
			$id = (int) $wpdb->insert_id;
		} else {
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		}
		self::flush();
		return (int) $id;
	}

	/** Only the profile fields a teacher may change themselves. */
	public static function save_profile( $id, array $d ) {
		return self::save( array_intersect_key( $d, array_flip( array( 'headline', 'bio', 'notify' ) ) ) + array( 'notify' => 0 ), $id );
	}

	/* ---------- Photos ---------- */

	public static function photo_ids( $t ) {
		return $t ? array_values( array_filter( array_map( 'intval', explode( ',', (string) $t->photos ) ) ) ) : array();
	}

	public static function photo_urls( $t, $size = 'large' ) {
		$out = array();
		foreach ( self::photo_ids( $t ) as $pid ) {
			$url = wp_get_attachment_image_url( $pid, $size );
			if ( $url ) {
				$out[] = $url;
			}
		}
		return $out;
	}

	public static function photo( $t, $size = 'medium' ) {
		return self::photo_urls( $t, $size )[0] ?? '';
	}

	private static function set_photos( $id, array $ids ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'photos' => implode( ',', array_slice( array_unique( array_map( 'intval', $ids ) ), 0, 8 ) ) ), array( 'id' => (int) $id ) );
		self::flush();
	}

	/**
	 * Upload photos from a multiple file field (e.g. $_FILES['photos']) into the media library.
	 * @return int|WP_Error photos added
	 */
	public static function add_photos( $id, $files ) {
		$t = self::get( $id );
		if ( ! $t || empty( $files['name'] ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$ids   = self::photo_ids( $t );
		$added = 0;
		foreach ( (array) $files['name'] as $i => $name ) {
			if ( '' === $name || UPLOAD_ERR_OK !== (int) ( (array) $files['error'] )[ $i ] ) {
				continue;
			}
			$type = wp_check_filetype_and_ext( ( (array) $files['tmp_name'] )[ $i ], $name );
			if ( ! $type['type'] || ! str_starts_with( $type['type'], 'image/' ) ) {
				return new WP_Error( 'oys_photo', __( 'Photos must be JPG, PNG, GIF or WebP images.', 'olivia-studio' ) );
			}
			$_FILES['oys_photo'] = array(
				'name'     => $name,
				'type'     => $type['type'],
				'tmp_name' => ( (array) $files['tmp_name'] )[ $i ],
				'error'    => 0,
				'size'     => ( (array) $files['size'] )[ $i ],
			);
			$pid = media_handle_upload( 'oys_photo', 0, array( 'post_title' => $t->name ), array( 'test_form' => false, 'action' => 'oys_teacher_photo' ) );
			if ( is_wp_error( $pid ) ) {
				return $pid;
			}
			$ids[] = (int) $pid;
			$added++;
		}
		self::set_photos( $id, $ids );
		return $added;
	}

	public static function remove_photo( $id, $photo_id ) {
		self::set_photos( $id, array_diff( self::photo_ids( self::get( $id ) ), array( (int) $photo_id ) ) );
	}

	/** Make a photo the first (main) one. */
	public static function main_photo( $id, $photo_id ) {
		$ids = self::photo_ids( self::get( $id ) );
		if ( in_array( (int) $photo_id, $ids, true ) ) {
			self::set_photos( $id, array_merge( array( (int) $photo_id ), array_diff( $ids, array( (int) $photo_id ) ) ) );
		}
	}

	/* ---------- Login ---------- */

	/**
	 * Give the teacher a login (a new account, or the teacher role added to an existing one) and
	 * email them a link to set their password. @return int|WP_Error user id
	 */
	public static function give_access( $id ) {
		global $wpdb;
		$t = self::get( $id );
		if ( ! $t || ! is_email( $t->email ) ) {
			return new WP_Error( 'oys_teacher', __( 'Add the teacher\'s email address first.', 'olivia-studio' ) );
		}
		$user = get_user_by( 'email', $t->email );
		if ( $user && user_can( $user, 'oys_manage' ) ) {
			return new WP_Error( 'oys_teacher', __( 'This email belongs to a studio manager account.', 'olivia-studio' ) );
		}
		$other = $user ? self::by_user( $user->ID ) : null;
		if ( $other && (int) $other->id !== (int) $t->id ) {
			return new WP_Error( 'oys_teacher', __( 'This account is already linked to another teacher.', 'olivia-studio' ) );
		}
		if ( ! $user ) {
			$parts   = explode( ' ', $t->name, 2 );
			$user_id = wp_insert_user( array(
				'user_login'   => sanitize_user( strtolower( strtok( $t->email, '@' ) ) . '-' . wp_generate_password( 4, false, false ), true ),
				'user_email'   => $t->email,
				'user_pass'    => wp_generate_password( 24 ),
				'first_name'   => $parts[0],
				'last_name'    => $parts[1] ?? '',
				'display_name' => $t->name,
				'role'         => 'oys_teacher',
			) );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			$user = get_user_by( 'id', $user_id );
		} else {
			$user->add_role( 'oys_teacher' );
		}
		$wpdb->update( self::table(), array( 'user_id' => (int) $user->ID ), array( 'id' => (int) $t->id ) );
		self::flush();
		$key = get_password_reset_key( $user );
		$url = is_wp_error( $key ) ? wp_login_url() : network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		OYS_Emails::teacher_access( $t, $url );
		return (int) $user->ID;
	}

	public static function remove_access( $id ) {
		global $wpdb;
		$t = self::get( $id );
		if ( $t && $t->user_id ) {
			$user = get_user_by( 'id', $t->user_id );
			if ( $user ) {
				$user->remove_role( 'oys_teacher' );
				if ( ! $user->roles ) {
					$user->add_role( 'oys_customer' );
				}
			}
			$wpdb->update( self::table(), array( 'user_id' => 0 ), array( 'id' => (int) $t->id ) );
			self::flush();
		}
	}

	/* ---------- Public ---------- */

	/** Profile for the app and the class page. */
	public static function public_data( $t ) {
		if ( ! $t ) {
			return null;
		}
		return array(
			'id'       => (int) $t->id,
			'name'     => $t->name,
			'headline' => $t->headline,
			'bio'      => (string) $t->bio,
			'photo'    => self::photo( $t, 'medium' ),
			'photos'   => self::photo_urls( $t, 'large' ),
		);
	}

	/** "with Anna" line for a class card (nothing for the studio's own classes). */
	public static function card_line( $s ) {
		$t = self::for_session( $s );
		if ( ! $t ) {
			return '';
		}
		$img = self::photo( $t, 'thumbnail' );
		return '<span class="oys-teacher-line">' . ( $img ? '<img src="' . esc_url( $img ) . '" alt="" width="22" height="22" loading="lazy">' : '' ) . esc_html( sprintf( __( 'with %s', 'olivia-studio' ), $t->name ) ) . '</span>';
	}

	/** Teacher box on the class page: photo, name, headline, bio. */
	public static function block( $s ) {
		$t = self::for_session( $s );
		return $t ? self::profile_html( $t, 'h3' ) : '';
	}

	public static function profile_html( $t, $tag = 'h2' ) {
		$photos = self::photo_urls( $t, 'large' );
		$out    = '<article class="oys-teacher" id="teacher-' . esc_attr( $t->slug ) . '">';
		if ( $photos ) {
			$out .= '<div class="oys-teacher__photos">';
			foreach ( $photos as $i => $url ) {
				$out .= '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $i ? '' : $t->name ) . '" loading="lazy">';
			}
			$out .= '</div>';
		}
		$out .= '<div class="oys-teacher__text"><' . $tag . ' class="oys-teacher__name">' . esc_html( $t->name ) . '</' . $tag . '>';
		if ( $t->headline ) {
			$out .= '<p class="oys-teacher__headline">' . esc_html( $t->headline ) . '</p>';
		}
		if ( $t->bio ) {
			$out .= wpautop( esc_html( $t->bio ) );
		}
		return $out . '</div></article>';
	}

	public static function shortcode() {
		$list = self::all( true );
		if ( ! $list ) {
			return '';
		}
		$out = '<div class="oys-teachers">';
		foreach ( $list as $t ) {
			$out .= self::profile_html( $t );
			$next = OYS_Schedule::query( array( 'teacher' => $t->id, 'from' => oys_now(), 'to' => oys_utc_plus( 21 * DAY_IN_SECONDS ), 'status' => 'scheduled', 'kind' => array( 'group', 'event' ), 'limit' => 4 ) );
			if ( $next ) {
				$out .= '<ul class="oys-teacher__next">';
				foreach ( $next as $s ) {
					$out .= '<li><a href="' . esc_url( oys_book_url( $s->id ) ) . '">' . esc_html( oys_session_title( $s ) . ' · ' . oys_date( $s->starts_at, 'D M j, g:i a' ) ) . '</a></li>';
				}
				$out .= '</ul>';
			}
		}
		return $out . '</div>';
	}
}
