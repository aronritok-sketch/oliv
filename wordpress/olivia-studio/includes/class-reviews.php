<?php
/**
 * Guest reviews (Olívia, 17 November: „szeretnék valamilyen formában véleménykérést, hogy később a véleményeket lehessen
 * megjeleníteni. Lehessen névvel és név nélkül véleményezni.” – Áron: both, our own and Google).
 *
 * - Who can write: someone who has attended a class (a signed link from the email, or logged in). One review each; it
 *   can be changed later (then it waits for approval again).
 * - How it appears: with the full name, the first name only, or without a name – the guest chooses, and agrees that it
 *   is shown that way.
 * - Nothing appears before the studio approves it: Studio → Reviews (Publish / Hide), with a push to the teacher's phone.
 * - Shown with [oys_reviews] (the online site's home page, before the FAQ): only approved ones, newest first, with the
 *   line that they were written by people who attended a class. Nothing at all while there is none.
 * - The request: the review_request email (Studio → Emails, on by default), once per guest, after the first class they
 *   attended since this feature was switched on (no mass email to past guests), between 10:00 and 19:00.
 * - Google: after sending, everyone – whatever they wrote – gets the Google review button when the studio's Google
 *   review link is set (option oys_google_review_url, REST /wp/v2/settings). No review gating (HelloProVision rule).
 */

defined( 'ABSPATH' ) || exit;

class OYS_Reviews {

	const CPT    = 'oys_review';
	const SINCE  = 'oys_reviews_since';
	const GOOGLE = 'oys_google_review_url';
	const MAX    = 1500;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_shortcode( 'oys_review_form', array( __CLASS__, 'form' ) );
		add_shortcode( 'oys_reviews', array( __CLASS__, 'display' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ) );
		add_action( 'oys_hourly', array( __CLASS__, 'ask' ) );
		add_filter( 'oys_email_templates', array( __CLASS__, 'templates' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_oys_review_status', array( __CLASS__, 'set_status' ) );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'ensure_page' ) );
		add_action( 'oys_hourly', array( __CLASS__, 'ensure_page' ), 5 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'privacy' ), 6 );
	}

	/** The form's page (/velemeny/), once; kept out of search engines. */
	public static function ensure_page() {
		if ( get_option( 'oys_review_page' ) && get_post( (int) get_option( 'oys_review_page' ) ) ) {
			return;
		}
		$page = get_page_by_path( 'velemeny' );
		$id   = $page ? $page->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'velemeny', 'post_title' => __( 'Your review', 'olivia-studio' ), 'post_content' => '[oys_review_form]' ) );
		if ( $id && ! is_wp_error( $id ) ) {
			update_option( 'oys_review_page', (int) $id, false );
		}
	}

	public static function robots( $robots ) {
		$id = (int) get_option( 'oys_review_page' );
		if ( $id && is_page( $id ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	public static function sitemap( $args, $post_type ) {
		$id = (int) get_option( 'oys_review_page' );
		if ( 'page' === $post_type && $id ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), array( $id ) );
		}
		return $args;
	}

	/** The privacy page tells about reviews too (added on the fly, the page's own text is not touched). */
	public static function privacy( $html ) {
		$page = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( ! $page || get_the_ID() !== $page || str_contains( $html, 'oys-privacy-reviews' ) ) {
			return $html;
		}
		$li  = '<li class="oys-privacy-reviews"><strong>' . esc_html__( 'Review:', 'olivia-studio' ) . '</strong> ' . esc_html__( 'only if you write one – its text, the name you chose to show (full name, first name or none) and when you agreed to it. It appears on the site after the studio has read it; you can change or withdraw it at any time.', 'olivia-studio' ) . '</li>';
		$out = preg_replace( '~(<li><strong>(?:Hírlevél|Newsletter)[^<]*</strong>.*?</li>)~su', '$1' . "\n" . $li, $html, 1, $n );
		return $n ? $out : $html;
	}

	public static function register() {
		register_post_type( self::CPT, array(
			'labels'          => array(
				'name'          => __( 'Reviews', 'olivia-studio' ),
				'singular_name' => __( 'Review', 'olivia-studio' ),
				'menu_name'     => __( 'Reviews', 'olivia-studio' ),
				'edit_item'     => __( 'Edit review', 'olivia-studio' ),
				'search_items'  => __( 'Search reviews', 'olivia-studio' ),
				'not_found'     => __( 'No reviews yet.', 'olivia-studio' ),
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'oys',
			'supports'        => array( 'editor' ),
			'capability_type' => 'post',
			'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
			'map_meta_cap'    => true,
		) );
		register_setting( 'oys_reviews', self::GOOGLE, array(
			'type'              => 'string',
			'description'       => 'Google review link (Google Business Profile → Ask for reviews)',
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => fn( $v ) => preg_match( '#^https://#', (string) $v ) ? esc_url_raw( (string) $v ) : '',
		) );
		if ( ! get_option( self::SINCE ) ) {
			update_option( self::SINCE, oys_now(), false ); // the first classes that count: from now on
		}
	}

	/* ---------- who ---------- */

	/** The guest's own link to the form (works without logging in). */
	public static function url( $user_id ) {
		$page = get_page_by_path( 'velemeny' );
		$base = $page ? get_permalink( $page ) : home_url( '/velemeny/' );
		return add_query_arg( 't', self::token( $user_id ), $base );
	}

	private static function token( $user_id ) {
		return (int) $user_id . '-' . oys_sig( 'oys-review|' . (int) $user_id ); // bound to the site (a network has one salt)
	}

	/** The person the form is for: the signed link's, or the logged-in user. 0 when nobody. */
	public static function who() {
		$t = isset( $_REQUEST['t'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$legacy = fn( $id ) => $id . '-' . substr( hash_hmac( 'sha256', 'oys-review|' . $id, wp_salt( 'auth' ) ), 0, 20 ); // links sent with 1.23.x
		if ( preg_match( '/^(\d+)-[a-f0-9]{20}$/', $t, $m ) && ( hash_equals( self::token( (int) $m[1] ), $t ) || ( ! is_multisite() && hash_equals( $legacy( (int) $m[1] ), $t ) ) ) && get_userdata( (int) $m[1] ) ) {
			return (int) $m[1];
		}
		return get_current_user_id();
	}

	/** The last class the person attended (null if none). */
	public static function last_attended( $user_id ) {
		global $wpdb;
		$b = OYS_Install::table( 'bookings' );
		$s = OYS_Install::table( 'sessions' );
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT s.id FROM $b b JOIN $s s ON s.id = b.session_id WHERE b.user_id = %d AND b.guest_of = 0 AND b.status = 'attended' ORDER BY s.starts_at DESC LIMIT 1", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names
		return $id ? OYS_Schedule::get( (int) $id ) : null;
	}

	public static function can_write( $user_id ) {
		return $user_id && ( self::last_attended( $user_id ) || user_can( $user_id, 'oys_manage' ) );
	}

	/** The person's review (any status but trash), or null. */
	public static function of( $user_id ) {
		$p = get_posts( array( 'post_type' => self::CPT, 'author' => (int) $user_id, 'post_status' => array( 'pending', 'publish', 'draft' ), 'numberposts' => 1 ) );
		return $p ? $p[0] : null;
	}

	/* ---------- names ---------- */

	public static function names( $user_id ) {
		$u     = get_userdata( $user_id );
		$first = $u ? trim( (string) $u->first_name ) : '';
		$full  = $u ? trim( (string) $u->display_name ) : '';
		if ( $u && $u->first_name && $u->last_name ) {
			$full = OYS_I18n::current() === 'hu' ? trim( $u->last_name . ' ' . $u->first_name ) : trim( $u->first_name . ' ' . $u->last_name );
		}
		if ( '' === $first ) {
			$first = strtok( $full, ' ' ) ?: '';
		}
		if ( is_email( $full ) ) { // an account without a name: never show the email address
			$full = $first = '';
		}
		return array( 'full' => $full, 'first' => $first );
	}

	/** The name the review is shown with. */
	public static function label( WP_Post $p ) {
		$how = get_post_meta( $p->ID, '_oys_display', true );
		$n   = self::names( (int) $p->post_author );
		if ( 'full' === $how && '' !== $n['full'] ) {
			return $n['full'];
		}
		if ( 'first' === $how && '' !== $n['first'] ) {
			return $n['first'];
		}
		return __( 'A student', 'olivia-studio' );
	}

	/* ---------- the form ---------- */

	public static function form() {
		$uid = self::who();
		if ( isset( $_GET['sent'] ) && $uid ) { // phpcs:ignore WordPress.Security.NonceVerification
			return self::thanks();
		}
		if ( ! $uid ) {
			return '<div class="oys-review-form oys-card"><p>' . esc_html__( 'Reviews can be written by students who have attended a class. Open the link from our email, or log in.', 'olivia-studio' ) . '</p><p><a class="btn btn--orchid" href="' . esc_url( oys_account_url() ) . '">' . esc_html__( 'Log in', 'olivia-studio' ) . '</a></p></div>';
		}
		if ( ! self::can_write( $uid ) ) {
			return '<div class="oys-review-form oys-card"><p>' . esc_html__( 'Reviews can be written by students who have attended a class. After your first class you can write one here.', 'olivia-studio' ) . '</p></div>';
		}
		$mine  = self::of( $uid );
		$how   = $mine ? (string) get_post_meta( $mine->ID, '_oys_display', true ) : 'first';
		$names = self::names( $uid );
		$err   = isset( $_GET['oys_err'] ) ? sanitize_key( wp_unslash( $_GET['oys_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$opt   = function ( $value, $text ) use ( $how ) {
			return '<label class="oys-review-form__opt"><input type="radio" name="oys_review_name" value="' . esc_attr( $value ) . '"' . checked( $how, $value, false ) . ' required> <span>' . $text . '</span></label>';
		};
		$opts = '';
		if ( '' !== $names['full'] ) {
			/* translators: %s: the full name */
			$opts .= $opt( 'full', sprintf( esc_html__( 'With my full name (%s)', 'olivia-studio' ), '<b>' . esc_html( $names['full'] ) . '</b>' ) );
		}
		if ( '' !== $names['first'] ) {
			/* translators: %s: the first name */
			$opts .= $opt( 'first', sprintf( esc_html__( 'With my first name only (%s)', 'olivia-studio' ), '<b>' . esc_html( $names['first'] ) . '</b>' ) );
		}
		$opts .= $opt( 'anon', esc_html__( 'Without a name', 'olivia-studio' ) );
		$out  = '<form class="oys-review-form oys-card" method="post" action="">';
		if ( $mine ) {
			$out .= '<p class="oys-review-form__note">' . esc_html( 'publish' === $mine->post_status ? __( 'Your review is on the site. If you change it, it appears again after the studio has read it.', 'olivia-studio' ) : __( 'You have written a review already – you can change it here.', 'olivia-studio' ) ) . '</p>';
		}
		if ( 'short' === $err ) {
			$out .= '<p class="oys-notice oys-notice--error" role="alert">' . esc_html__( 'Please write at least a sentence.', 'olivia-studio' ) . '</p>';
		}
		$out .= '<label class="oys-review-form__label" for="oys-review-text">' . esc_html__( 'How was the class? What did it give you?', 'olivia-studio' ) . '</label>'
			. '<textarea id="oys-review-text" name="oys_review_text" rows="6" maxlength="' . self::MAX . '" required>' . esc_textarea( $mine ? $mine->post_content : '' ) . '</textarea>'
			. '<fieldset class="oys-review-form__names"><legend>' . esc_html__( 'How should it appear?', 'olivia-studio' ) . '</legend>' . $opts . '</fieldset>'
			. '<label class="oys-review-form__opt oys-review-form__consent"><input type="checkbox" name="oys_review_ok" value="1" required> <span>' . esc_html__( 'I agree that my review appears on the website the way I chose above. I can change or withdraw it at any time.', 'olivia-studio' ) . '</span></label>'
			. '<input type="hidden" name="t" value="' . esc_attr( self::token( $uid ) ) . '">'
			. wp_nonce_field( 'oys_review', '_oys_review', true, false )
			. '<p class="btn-row"><button type="submit" class="btn btn--orchid" name="oys_review_send" value="1">' . esc_html( $mine ? __( 'Save my review', 'olivia-studio' ) : __( 'Send my review', 'olivia-studio' ) ) . '</button></p></form>';
		return $out;
	}

	private static function thanks() {
		$google = (string) get_option( self::GOOGLE, '' );
		$out    = '<div class="oys-review-form oys-card oys-review-thanks"><h2>' . esc_html__( 'Thank you!', 'olivia-studio' ) . '</h2><p>' . esc_html__( 'The studio reads it, and it appears on the site soon.', 'olivia-studio' ) . '</p>';
		if ( $google ) {
			$out .= '<p>' . esc_html__( 'If you would like to, you can also share it on Google – it helps others find the classes.', 'olivia-studio' ) . '</p><p class="btn-row"><a class="btn btn--ghost" href="' . esc_url( $google ) . '" target="_blank" rel="noopener">' . esc_html__( 'Review on Google', 'olivia-studio' ) . '</a></p>';
		}
		return $out . '</div>';
	}

	/** The form sent: save (waits for approval), tell the studio, back to the thank-you. */
	public static function handle() {
		if ( empty( $_POST['oys_review_send'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$uid = self::who();
		if ( ! $uid || ! isset( $_POST['_oys_review'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_oys_review'] ) ), 'oys_review' ) || ! self::can_write( $uid ) ) {
			wp_die( esc_html__( 'This link is not valid any more. Please open it again from the email.', 'olivia-studio' ), '', array( 'response' => 403 ) );
		}
		$back = remove_query_arg( array( 'sent', 'oys_err' ), wp_get_referer() ?: self::url( $uid ) );
		$id   = self::save( $uid, wp_unslash( (string) ( $_POST['oys_review_text'] ?? '' ) ), sanitize_key( wp_unslash( $_POST['oys_review_name'] ?? 'anon' ) ), ! empty( $_POST['oys_review_ok'] ) );
		if ( is_wp_error( $id ) ) {
			wp_safe_redirect( add_query_arg( array( 'oys_err' => 'short', 't' => self::token( $uid ) ), $back ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( array( 'sent' => 1, 't' => self::token( $uid ) ), $back ) );
		exit;
	}

	/** Save the person's review (new, or their one changed): it waits for approval; the studio gets a push. */
	public static function save( $uid, $text, $how, $agreed ) {
		$text = trim( preg_replace( "/\n{3,}/", "\n\n", str_replace( "\r", '', wp_strip_all_tags( (string) $text ) ) ) );
		$text = mb_substr( $text, 0, self::MAX );
		if ( mb_strlen( $text ) < 10 || ! $agreed || ! self::can_write( $uid ) ) {
			return new WP_Error( 'oys_review', 'short' );
		}
		$how  = in_array( $how, array( 'full', 'first', 'anon' ), true ) ? $how : 'anon';
		$mine = self::of( $uid );
		$data = array( 'post_type' => self::CPT, 'post_status' => 'pending', 'post_author' => $uid, 'post_content' => $text, 'post_title' => wp_trim_words( $text, 8, '…' ) );
		$id   = $mine ? wp_update_post( wp_slash( $data + array( 'ID' => $mine->ID ) ), true ) : wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new WP_Error( 'oys_review', 'save' );
		}
		update_post_meta( $id, '_oys_display', $how );
		update_post_meta( $id, '_oys_consent', time() );
		$s = self::last_attended( $uid );
		update_post_meta( $id, '_oys_class', $s ? oys_session_title( $s ) : '' );
		foreach ( get_users( array( 'capability' => 'oys_manage', 'fields' => 'ID' ) ) as $teacher ) {
			OYS_Studio_App::notify( (int) $teacher, __( 'New review', 'olivia-studio' ), wp_trim_words( $text, 18, '…' ), admin_url( 'edit.php?post_type=' . self::CPT . '&post_status=pending' ), 'review' );
		}
		return (int) $id;
	}

	/* ---------- on the site ---------- */

	public static function display( $atts = array() ) {
		$a     = shortcode_atts( array( 'limit' => 6 ), $atts );
		$posts = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'publish', 'numberposts' => max( 1, (int) $a['limit'] ), 'orderby' => 'date', 'order' => 'DESC' ) );
		if ( ! $posts ) {
			return '';
		}
		$cards = '';
		foreach ( $posts as $p ) {
			$class  = (string) get_post_meta( $p->ID, '_oys_class', true );
			$cards .= '<figure class="oys-review"><blockquote>' . wpautop( esc_html( $p->post_content ) ) . '</blockquote><figcaption><b>' . esc_html( self::label( $p ) ) . '</b>' . ( '' !== $class ? '<span>' . esc_html( $class ) . '</span>' : '' ) . '</figcaption></figure>';
		}
		return '<section class="section section--pale oys-reviews" id="velemenyek" aria-labelledby="reviews-title"><div class="container">'
			. '<header class="section__head"><p class="kicker">' . esc_html__( 'Reviews', 'olivia-studio' ) . '</p><h2 id="reviews-title">' . esc_html__( 'What students say', 'olivia-studio' ) . '</h2></header>'
			. '<div class="oys-reviews__grid">' . $cards . '</div>'
			. '<p class="oys-reviews__note">' . esc_html__( 'Written by students who have attended a class.', 'olivia-studio' ) . '</p></div></section>';
	}

	/* ---------- the request ---------- */

	/** Hourly, 10:00–19:00: the review request to those whose first attended class (since the switch-on) is over. */
	public static function ask() {
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		if ( (int) $now->format( 'G' ) < 10 || (int) $now->format( 'G' ) >= 19 || ! OYS_Email_Templates::enabled( 'review_request' ) ) {
			return 0;
		}
		return self::ask_now();
	}

	public static function ask_now() {
		global $wpdb;
		$b     = OYS_Install::table( 'bookings' );
		$s     = OYS_Install::table( 'sessions' );
		$since = (string) get_option( self::SINCE, oys_now() );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT b.user_id FROM $b b JOIN $s s ON s.id = b.session_id WHERE b.status = 'attended' AND b.guest_of = 0 AND b.user_id > 0 AND s.ends_at >= %s AND s.ends_at <= %s LIMIT 200", $since, oys_utc_plus( -3 * HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names
		$sent  = 0;
		foreach ( $ids as $uid ) {
			$uid = (int) $uid;
			if ( get_user_meta( $uid, '_oys_review_asked', true ) || self::of( $uid ) || user_can( $uid, 'oys_manage' ) ) {
				continue;
			}
			update_user_meta( $uid, '_oys_review_asked', time() );
			$sent += OYS_Emails::review_request( $uid, self::url( $uid ) ) ? 1 : 0;
			if ( $sent >= 50 ) {
				break;
			}
		}
		return $sent;
	}

	public static function templates( $all ) {
		$all['review_request'] = array(
			'label'    => __( 'Review request (after the first class)', 'olivia-studio' ),
			'group'    => 'reminders',
			'to'       => 'customer',
			'when'     => __( 'Once per student, a few hours after the first class they attended', 'olivia-studio' ),
			'editable' => true,
			'subject'  => 'How was the class, {first_name}?',
			'heading'  => 'How was it?',
			'message'  => "Hi {first_name}, thank you for practising with me. Would you write a few words about the class? You choose whether it appears with your full name, your first name only, or without a name.",
			'closing'  => 'Thank you!',
			'button'   => 'Write a review',
			'vars'     => OYS_Email_Templates::COMMON,
		);
		return $all;
	}

	/* ---------- Studio → Reviews ---------- */

	public static function columns( $cols ) {
		return array( 'cb' => $cols['cb'] ?? '', 'title' => __( 'Review', 'olivia-studio' ), 'oys_name' => __( 'Appears as', 'olivia-studio' ), 'oys_who' => __( 'Student', 'olivia-studio' ), 'date' => $cols['date'] ?? __( 'Date', 'olivia-studio' ) );
	}

	public static function column( $col, $post_id ) {
		$p = get_post( $post_id );
		if ( 'oys_name' === $col ) {
			echo esc_html( self::label( $p ) );
		} elseif ( 'oys_who' === $col ) {
			$u = get_userdata( (int) $p->post_author );
			echo $u ? esc_html( $u->display_name . ' · ' . $u->user_email ) : '—';
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( self::CPT !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$go = fn( $to ) => wp_nonce_url( admin_url( 'admin-post.php?action=oys_review_status&to=' . $to . '&id=' . $post->ID ), 'oys_review_status_' . $post->ID );
		$add = 'publish' === $post->post_status
			? array( 'oys_hide' => '<a href="' . esc_url( $go( 'draft' ) ) . '">' . esc_html__( 'Hide from the site', 'olivia-studio' ) . '</a>' )
			: array( 'oys_publish' => '<a href="' . esc_url( $go( 'publish' ) ) . '"><b>' . esc_html__( 'Show on the site', 'olivia-studio' ) . '</b></a>' );
		unset( $actions['inline hide-if-no-js'] );
		return $add + $actions;
	}

	public static function set_status() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$to = isset( $_GET['to'] ) && 'publish' === $_GET['to'] ? 'publish' : 'draft';
		check_admin_referer( 'oys_review_status_' . $id );
		if ( ! $id || self::CPT !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'olivia-studio' ), '', array( 'response' => 403 ) );
		}
		wp_update_post( array( 'ID' => $id, 'post_status' => $to ) );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=' . self::CPT ) );
		exit;
	}
}

OYS_Reviews::init();
