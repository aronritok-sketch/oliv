<?php
/**
 * Newsletters to the customers who ticked "Email me the newsletter" (user meta oys_marketing).
 *
 * Written in Studio → Newsletter (by hand or drafted with AI, see OYS_AI), previewed, tested, then
 * sent in batches by the 5-minute cron (`newsletter_batch` emails per run), so a shared host
 * isn't asked to send hundreds of emails at once. Every email has a one-click unsubscribe link.
 * Use an SMTP / transactional email plugin (e.g. Brevo, Postmark, Amazon SES) on the live site.
 *
 * The text uses a tiny format: blank line = new paragraph, "## " heading, "- " bullet list,
 * **bold**, [link text](https://…); plain web addresses become links.
 *
 * Subscribers by email too (Áron, 26 November: „Szeretnék egy hírlevél feliratkozást a .hu események aloldalára és a
 * főoldalra … Építs egy közös hírlevélküldő szoftvert a .online-nal és a .online-ról mehessenek ki a levelek … És a
 * footerbe is rakd be a feliratkozást”): a form on any of the studio's sites posts here (admin-post.php,
 * action oys_nl_subscribe); the address waits until the link in the confirmation email is clicked (double opt-in), then
 * gets every newsletter with its own one-click unsubscribe link. An address that belongs to a customer becomes that
 * customer's newsletter tick, so nobody gets a letter twice.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Newsletter {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_unsubscribe' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_list_link' ) );
		add_action( 'admin_post_nopriv_oys_nl_subscribe', array( __CLASS__, 'handle_subscribe' ) );
		add_action( 'admin_post_oys_nl_subscribe', array( __CLASS__, 'handle_subscribe' ) );
		add_shortcode( 'oys_newsletter', fn( $a ) => self::form_html( shortcode_atts( array( 'source' => 'online', 'title' => '', 'text' => '', 'id' => 'hirlevel' ), (array) $a ) ) );
		add_action( 'oys_hourly', array( __CLASS__, 'list_cleanup' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'added_user_meta', array( __CLASS__, 'sync_customer_tick' ), 10, 4 );
		add_action( 'updated_user_meta', array( __CLASS__, 'sync_customer_tick' ), 10, 4 );
	}

	/**
	 * The studio switches a customer's newsletter on or off (someone asked by phone, or clicked the link in a preview):
	 * GET /oys/v1/customers/<id>/newsletter, POST with on=1|0.
	 */
	public static function routes() {
		// the drafts, for the studio's tools (Áron, 26 November: „olvasd el, hogy mi van a hírlevélszövegben … küldj ki egy
		// e-mailt a tesztfiókjára”): read, change a draft, send one test – never a send to the list
		$manage = fn() => current_user_can( 'oys_manage' ) || current_user_can( 'manage_options' );
		register_rest_route( 'oys/v1', '/newsletters', array(
			'methods'             => 'GET',
			'permission_callback' => $manage,
			'callback'            => fn() => rest_ensure_response( array_map( fn( $n ) => array( 'id' => (int) $n->id, 'subject' => $n->subject, 'status' => $n->status, 'audience' => $n->audience ?? '', 'created_at' => $n->created_at, 'sent' => (int) $n->sent, 'total' => (int) $n->total ), self::query( 50 ) ) ),
		) );
		register_rest_route( 'oys/v1', '/newsletters/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $manage,
				'callback'            => function ( WP_REST_Request $r ) {
					$n = self::get( (int) $r['id'] );
					return $n ? rest_ensure_response( array( 'id' => (int) $n->id, 'subject' => $n->subject, 'preheader' => $n->preheader, 'body' => $n->body, 'button_label' => $n->button_label, 'button_url' => $n->button_url, 'status' => $n->status ) ) : new WP_Error( 'oys_not_found', 'Not found', array( 'status' => 404 ) );
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $manage,
				'callback'            => function ( WP_REST_Request $r ) {
					$n = self::get( (int) $r['id'] );
					if ( ! $n || 'draft' !== $n->status ) {
						return new WP_Error( 'oys_newsletter', 'Only a draft can be changed.', array( 'status' => 409 ) );
					}
					$p = (array) ( $r->get_json_params() ?: $r->get_params() );
					self::save( array_merge( array( 'subject' => $n->subject, 'preheader' => $n->preheader, 'body' => $n->body, 'button_label' => $n->button_label, 'button_url' => $n->button_url ), array_intersect_key( $p, array_flip( array( 'subject', 'preheader', 'body', 'button_label', 'button_url' ) ) ) ), $n->id );
					return rest_ensure_response( array( 'saved' => true, 'id' => (int) $n->id ) );
				},
			),
		) );
		register_rest_route( 'oys/v1', '/newsletters/(?P<id>\d+)/test', array(
			'methods'             => 'POST',
			'permission_callback' => $manage,
			'callback'            => function ( WP_REST_Request $r ) {
				$u  = (int) $r->get_param( 'user' ) ? get_userdata( (int) $r->get_param( 'user' ) ) : null;
				$to = $u ? $u->user_email : sanitize_email( (string) $r->get_param( 'to' ) );
				return rest_ensure_response( array( 'sent' => (bool) self::send_test( (int) $r['id'], $to ), 'own_link' => $u && OYS_Customers::is_customer_only( $u ) ) );
			},
		) );
		register_rest_route( 'oys/v1', '/customers/(?P<id>\d+)/newsletter', array(
			'methods'             => array( 'GET', 'POST' ),
			'permission_callback' => fn() => current_user_can( 'oys_manage' ),
			'callback'            => function ( WP_REST_Request $r ) {
				$u = get_userdata( (int) $r['id'] );
				if ( ! $u ) {
					return new WP_Error( 'oys_nl', 'Not found.', array( 'status' => 404 ) );
				}
				if ( 'POST' === $r->get_method() ) {
					self::set_customer( $u->ID, (bool) $r->get_param( 'on' ) );
				}
				return rest_ensure_response( array( 'id' => (int) $u->ID, 'name' => $u->display_name, 'newsletter' => '1' === (string) get_user_meta( $u->ID, 'oys_marketing', true ) ) );
			},
		) );
	}

	/** A customer's newsletter on or off (their signup-form address follows). */
	public static function set_customer( $user_id, $on ) {
		OYS_Customers::set_marketing( (int) $user_id, $on );
		$u = get_userdata( (int) $user_id );
		$r = $u ? self::list_by_email( $u->user_email ) : null;
		if ( $on && $r && 'confirmed' !== $r->status ) {
			global $wpdb;
			$wpdb->update( self::list_table(), array( 'status' => 'confirmed', 'confirmed_at' => oys_now(), 'unsub_at' => null ), array( 'id' => (int) $r->id ) );
		}
	}

	/** A customer who unticks the newsletter in their account is off the signup list too (one choice for one address). */
	public static function sync_customer_tick( $meta_id, $user_id, $key, $value ) {
		if ( 'oys_marketing' !== $key || '1' === (string) $value ) {
			return;
		}
		$u = get_userdata( (int) $user_id );
		$r = $u ? self::list_by_email( $u->user_email ) : null;
		if ( $r && 'unsubscribed' !== $r->status ) {
			global $wpdb;
			$wpdb->update( self::list_table(), array( 'status' => 'unsubscribed', 'unsub_at' => oys_now() ), array( 'id' => (int) $r->id ) );
		}
	}

	/**
	 * Hourly: an unconfirmed signup and an unsubscribed address are deleted after 30 days (the privacy notice says so);
	 * a confirmed one stays until it unsubscribes.
	 * @return int rows deleted
	 */
	public static function list_cleanup() {
		global $wpdb;
		$cut = gmdate( 'Y-m-d H:i:s', strtotime( oys_now() ) - 30 * DAY_IN_SECONDS );
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::list_table() . " WHERE ( status = 'pending' AND COALESCE( mailed_at, created_at ) < %s ) OR ( status = 'unsubscribed' AND COALESCE( unsub_at, created_at ) < %s )", $cut, $cut ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			self::list_delete( (int) $id );
		}
		return count( $ids );
	}

	/* ---------- Subscribers by email (the signup forms) ---------- */

	private static function list_table() {
		return OYS_Install::table( 'subscribers' );
	}

	public static function list_get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::list_table() . ' WHERE id = %d', $id ) );
	}

	public static function list_by_email( $email ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::list_table() . ' WHERE email = %s', strtolower( trim( (string) $email ) ) ) );
	}

	/** @return object[] newest first; $status '' = all */
	public static function list_query( $status = '', $limit = 500 ) {
		global $wpdb;
		$where = $status ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
		return $wpdb->get_results( 'SELECT * FROM ' . self::list_table() . " $where ORDER BY id DESC LIMIT " . (int) $limit ); // phpcs:ignore
	}

	/** Confirmed list subscribers whose address is not a subscribed customer's (they get the customer's letter). */
	public static function list_recipients() {
		$customers = array();
		foreach ( self::subscribers() as $uid ) {
			$u = get_userdata( $uid );
			if ( $u ) {
				$customers[ strtolower( $u->user_email ) ] = true;
			}
		}
		return array_values( array_filter( self::list_query( 'confirmed', 100000 ), fn( $r ) => ! isset( $customers[ strtolower( $r->email ) ] ) ) );
	}

	/** Everyone a newsletter goes to: customers with the tick + confirmed addresses, each address once. */
	public static function audience_count() {
		return count( self::subscribers() ) + count( self::list_recipients() );
	}

	private static function link_sig( $kind, $id ) {
		return oys_sig( 'nl' . $kind . '|' . (int) $id );
	}

	public static function confirm_url( $id ) {
		return add_query_arg( 'oys_nl_confirm', (int) $id . '.' . self::link_sig( 'c', $id ), home_url( '/' ) );
	}

	public static function list_unsubscribe_url( $id ) {
		return add_query_arg( 'oys_nl_unsub', (int) $id . '.' . self::link_sig( 'u', $id ), home_url( '/' ) );
	}

	/**
	 * A signup: a new or returning address waits for its confirmation (the email goes out at most every 10 minutes).
	 * @return string|WP_Error 'sent' | 'already' | 'wait'
	 */
	public static function subscribe( $email, $name = '', $source = '', $page = '' ) {
		global $wpdb;
		$email = strtolower( trim( sanitize_email( (string) $email ) ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'oys_nl_email', __( 'Please give a valid email address.', 'olivia-studio' ) );
		}
		$name   = mb_substr( sanitize_text_field( (string) $name ), 0, 120 );
		$source = sanitize_key( (string) $source );
		$page   = esc_url_raw( mb_substr( (string) $page, 0, 255 ) );
		$u      = get_user_by( 'email', $email );
		if ( $u && '1' === (string) get_user_meta( $u->ID, 'oys_marketing', true ) ) {
			return 'already';
		}
		$row = self::list_by_email( $email );
		if ( $row && 'confirmed' === $row->status ) {
			return 'already';
		}
		if ( $row && 'pending' === $row->status && $row->mailed_at && oys_ts( $row->mailed_at ) > time() - 10 * MINUTE_IN_SECONDS ) {
			return 'wait';
		}
		$data = array( 'name' => $name ?: ( $row->name ?? '' ), 'source' => $source ?: ( $row->source ?? '' ), 'page' => $page ?: ( $row->page ?? '' ), 'status' => 'pending', 'mailed_at' => oys_now() );
		if ( $row ) {
			$wpdb->update( self::list_table(), $data, array( 'id' => (int) $row->id ) );
			$id = (int) $row->id;
		} else {
			$wpdb->insert( self::list_table(), array_merge( $data, array( 'email' => $email, 'created_at' => oys_now() ) ) );
			$id = (int) $wpdb->insert_id;
		}
		if ( ! $id ) {
			return new WP_Error( 'oys_nl_save', __( 'Something went wrong, please try again.', 'olivia-studio' ) );
		}
		self::send_confirmation( $id );
		return 'sent';
	}

	/** The double opt-in email (Studio → Emails: „Newsletter signup confirmation”). */
	public static function send_confirmation( $id ) {
		$r = self::list_get( $id );
		$t = OYS_Email_Templates::get( 'newsletter_confirm' );
		if ( ! $r || ! $t ) {
			return false;
		}
		$vars = array( 'first_name' => $r->name, 'studio' => OYS_Settings::get( 'email_from_name' ) );
		$body = OYS_Email_Templates::paragraphs( $t['message'], $vars ) . ( '' !== trim( (string) $t['closing'] ) ? OYS_Email_Templates::paragraphs( $t['closing'], $vars ) : '' );
		$html = OYS_Emails::wrap_public( OYS_Email_Templates::fill( $t['heading'], $vars ), $body, array( $t['button'] ?: __( 'Confirm my signup', 'olivia-studio' ), self::confirm_url( $id ) ) );
		return OYS_Emails::send_raw( $r->email, OYS_Email_Templates::fill( $t['subject'], $vars ), $html );
	}

	/** The confirmation: the address gets the newsletter (a customer's address: the customer's tick). */
	public static function confirm( $id ) {
		global $wpdb;
		$r = self::list_get( $id );
		if ( ! $r || 'unsubscribed' === $r->status ) {
			return false;
		}
		if ( 'confirmed' !== $r->status ) {
			$wpdb->update( self::list_table(), array( 'status' => 'confirmed', 'confirmed_at' => oys_now(), 'unsub_at' => null ), array( 'id' => (int) $id ) );
		}
		$u = get_user_by( 'email', $r->email );
		if ( $u ) {
			OYS_Customers::set_marketing( $u->ID, true );
		}
		return true;
	}

	/** Off the list (and a customer with the same address loses the tick: one choice for one address). */
	public static function list_unsubscribe( $id ) {
		global $wpdb;
		$r = self::list_get( $id );
		if ( ! $r ) {
			return false;
		}
		$wpdb->update( self::list_table(), array( 'status' => 'unsubscribed', 'unsub_at' => oys_now() ), array( 'id' => (int) $id ) );
		$u = get_user_by( 'email', $r->email );
		if ( $u ) {
			update_user_meta( $u->ID, 'oys_marketing', '' );
		}
		return true;
	}

	/** The studio adds an address by hand (someone who asked for it): confirmed at once. */
	public static function list_add( $email, $name = '' ) {
		global $wpdb;
		$email = strtolower( trim( sanitize_email( (string) $email ) ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'oys_nl_email', __( 'Please give a valid email address.', 'olivia-studio' ) );
		}
		$row = self::list_by_email( $email );
		if ( $row ) {
			$wpdb->update( self::list_table(), array( 'status' => 'confirmed', 'confirmed_at' => oys_now(), 'unsub_at' => null, 'name' => sanitize_text_field( (string) $name ) ?: $row->name ), array( 'id' => (int) $row->id ) );
			return (int) $row->id;
		}
		$wpdb->insert( self::list_table(), array( 'email' => $email, 'name' => sanitize_text_field( (string) $name ), 'source' => 'admin', 'status' => 'confirmed', 'created_at' => oys_now(), 'confirmed_at' => oys_now() ) );
		$u = get_user_by( 'email', $email );
		if ( $u ) {
			update_user_meta( $u->ID, 'oys_marketing', '1' );
		}
		return (int) $wpdb->insert_id;
	}

	public static function list_delete( $id ) {
		global $wpdb;
		$wpdb->delete( OYS_Install::table( 'newsletter_list_queue' ), array( 'subscriber_id' => (int) $id ) );
		$wpdb->delete( self::list_table(), array( 'id' => (int) $id ) );
	}

	/** Where a form may send the visitor back to: this site, and the studio's other sites (filter). */
	public static function back_url( $url ) {
		$url   = esc_url_raw( (string) $url );
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$hosts = array_map( 'strtolower', (array) apply_filters( 'oys_newsletter_back_hosts', array( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) );
		return $host && in_array( $host, $hosts, true ) && in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ? $url : home_url( '/' );
	}

	/**
	 * The form's post (from this site or another of the studio's sites): checks, the signup, back to the page with
	 * ?hirlevel=ok|mar|var|hiba#hirlevel. A hidden „website” field catches robots; at most 6 signups an hour from one
	 * connection.
	 */
	public static function handle_subscribe() {
		$back = self::back_url( wp_unslash( $_POST['back'] ?? ( $_SERVER['HTTP_REFERER'] ?? '' ) ) ); // phpcs:ignore
		$frag = preg_match( '/#([a-z][a-z0-9-]{0,39})$/', $back, $m ) ? $m[1] : 'hirlevel'; // back to the form that was used
		$go   = function ( $state ) use ( $back, $frag ) {
			$url = add_query_arg( 'hirlevel', $state, remove_query_arg( 'hirlevel', strtok( $back, '#' ) ) ) . '#' . $frag;
			add_filter( 'allowed_redirect_hosts', fn( $h ) => array_merge( $h, (array) apply_filters( 'oys_newsletter_back_hosts', array() ) ) );
			wp_safe_redirect( $url, 303 );
			exit;
		};
		if ( '' !== trim( (string) wp_unslash( $_POST['website'] ?? '' ) ) ) { // phpcs:ignore
			$go( 'ok' ); // a robot: it looks like it worked
		}
		$key = 'oys_nl_rl_' . md5( OYS_Security::ip() );
		$n   = (int) get_transient( $key );
		if ( $n >= 6 ) {
			$go( 'hiba' );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		if ( empty( $_POST['consent'] ) ) { // phpcs:ignore
			$go( 'hiba' );
		}
		$res = self::subscribe( wp_unslash( $_POST['email'] ?? '' ), wp_unslash( $_POST['name'] ?? '' ), wp_unslash( $_POST['source'] ?? '' ), $back ); // phpcs:ignore
		$go( is_wp_error( $res ) ? 'hiba' : ( 'already' === $res ? 'mar' : 'ok' ) );
	}

	/** The links in the emails: confirm, unsubscribe (signed, no login). */
	public static function maybe_list_link() {
		foreach ( array( 'oys_nl_confirm' => 'c', 'oys_nl_unsub' => 'u' ) as $param => $kind ) {
			if ( empty( $_GET[ $param ] ) ) { // phpcs:ignore
				continue;
			}
			[ $id, $sig ] = array_pad( explode( '.', sanitize_text_field( wp_unslash( $_GET[ $param ] ) ), 2 ), 2, '' ); // phpcs:ignore
			if ( ! (int) $id || ! hash_equals( self::link_sig( $kind, (int) $id ), $sig ) ) {
				wp_die( esc_html__( 'This link is not valid.', 'olivia-studio' ), '', array( 'response' => 400 ) );
			}
			$home = '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( OYS_Settings::get( 'email_from_name' ) ?: get_bloginfo( 'name' ) ) . '</a></p>';
			if ( 'c' === $kind ) {
				$ok = self::confirm( (int) $id );
				wp_die( $ok ? '<h1>' . esc_html__( 'Thank you, you\'re subscribed!', 'olivia-studio' ) . '</h1><p>' . esc_html__( 'From now on I\'ll let you know about new events and workshops. Every email has a link to unsubscribe.', 'olivia-studio' ) . '</p>' . $home
					: '<h1>' . esc_html__( 'This signup is no longer active', 'olivia-studio' ) . '</h1><p>' . esc_html__( 'Sign up again on the website if you would like the newsletter.', 'olivia-studio' ) . '</p>' . $home, esc_html__( 'Newsletter', 'olivia-studio' ), array( 'response' => 200 ) ); // phpcs:ignore
			}
			self::list_unsubscribe( (int) $id );
			wp_die( '<h1>' . esc_html__( 'You\'re unsubscribed', 'olivia-studio' ) . '</h1><p>' . esc_html__( 'You won\'t get the newsletter any more. You can sign up again any time on the website.', 'olivia-studio' ) . '</p>' . $home, esc_html__( 'Unsubscribed', 'olivia-studio' ), array( 'response' => 200 ) ); // phpcs:ignore
		}
	}

	/**
	 * The signup form ([oys_newsletter], the online site's footer). $a: source (where it was filled in), title, text,
	 * back (the page to come back to), action (where it posts: this site's admin-post.php), privacy (the notice's address).
	 */
	public static function form_html( array $a ) {
		$id      = 'oys-nl-' . wp_unique_id();
		$action  = $a['action'] ?? admin_url( 'admin-post.php' );
		$privacy = $a['privacy'] ?? get_privacy_policy_url();
		$state   = sanitize_key( wp_unslash( $_GET['hirlevel'] ?? '' ) ); // phpcs:ignore
		$msgs    = array(
			'ok'   => __( 'Almost there: I\'ve sent you an email, click the link in it to confirm your signup.', 'olivia-studio' ),
			'mar'  => __( 'You\'re already subscribed. Thank you!', 'olivia-studio' ),
			'hiba' => __( 'That didn\'t work: check the email address and tick the box, then try again.', 'olivia-studio' ),
		);
		$note = isset( $msgs[ $state ] ) ? '<p class="oys-nl__msg oys-nl__msg--' . esc_attr( $state ) . '" role="status">' . esc_html( $msgs[ $state ] ) . '</p>' : '';
		return '<form class="oys-nl" id="' . esc_attr( sanitize_key( $a['id'] ?? 'hirlevel' ) ?: 'hirlevel' ) . '" method="post" action="' . esc_url( $action ) . '" data-oys-nl>'
			. ( '' !== (string) ( $a['title'] ?? '' ) ? '<p class="oys-nl__title">' . esc_html( $a['title'] ) . '</p>' : '' )
			. ( '' !== (string) ( $a['text'] ?? '' ) ? '<p class="oys-nl__text">' . esc_html( $a['text'] ) . '</p>' : '' )
			. $note
			. '<input type="hidden" name="action" value="oys_nl_subscribe"><input type="hidden" name="source" value="' . esc_attr( sanitize_key( $a['source'] ?? '' ) ) . '">'
			. '<input type="hidden" name="back" value="' . esc_attr( $a['back'] ?? '' ) . '" data-oys-nl-back>'
			. '<p class="oys-nl__hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>'
			. '<p class="oys-nl__row"><label class="oys-nl__field"><span>' . esc_html__( 'Your name (optional)', 'olivia-studio' ) . '</span><input type="text" name="name" autocomplete="given-name" maxlength="120"></label>'
			. '<label class="oys-nl__field"><span>' . esc_html__( 'Email', 'olivia-studio' ) . '</span><input type="email" name="email" required autocomplete="email" maxlength="190"></label></p>'
			. '<label class="oys-nl__consent" for="' . esc_attr( $id ) . '"><input type="checkbox" name="consent" value="1" required id="' . esc_attr( $id ) . '"> <span>' . wp_kses( sprintf( __( 'I\'d like the newsletter (events, workshops). I can unsubscribe any time; <a href="%s" target="_blank" rel="noopener">privacy notice</a>.', 'olivia-studio' ), esc_url( $privacy ) ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ) . '</span></label>'
			. '<p class="oys-nl__go"><button type="submit" class="btn btn--orchid">' . esc_html__( 'Subscribe', 'olivia-studio' ) . '</button></p></form>';
	}

	/* ---------- Subscribers ---------- */

	/** @return int[] user ids */
	public static function subscribers() {
		return array_map( 'intval', get_users( array( 'fields' => 'ID', 'meta_key' => 'oys_marketing', 'meta_value' => '1' ) ) );
	}

	public static function unsubscribe_url( $user_id ) {
		return add_query_arg( 'oys_unsub', $user_id . '.' . self::sig( $user_id ), home_url( '/' ) );
	}

	private static function sig( $user_id ) {
		return oys_sig( 'unsub|' . (int) $user_id );
	}

	/** One click from the email: no login needed, the link is signed. */
	public static function maybe_unsubscribe() {
		if ( empty( $_GET['oys_unsub'] ) ) {
			return;
		}
		if ( 'preview' === $_GET['oys_unsub'] ) { // phpcs:ignore
			wp_die( '<h1>' . esc_html__( 'This is only a preview', 'olivia-studio' ) . '</h1><p>' . esc_html__( 'In the real newsletter this link unsubscribes the reader. In a preview or a test email it does nothing: you are still subscribed.', 'olivia-studio' ) . '</p>', esc_html__( 'Newsletter', 'olivia-studio' ), array( 'response' => 200 ) ); // phpcs:ignore
		}
		[ $uid, $sig ] = array_pad( explode( '.', sanitize_text_field( wp_unslash( $_GET['oys_unsub'] ) ), 2 ), 2, '' );
		if ( ! (int) $uid || ! hash_equals( self::sig( (int) $uid ), $sig ) ) {
			wp_die( esc_html__( 'This unsubscribe link is not valid.', 'olivia-studio' ), '', array( 'response' => 400 ) );
		}
		OYS_Customers::set_marketing( (int) $uid, false );
		$u = get_userdata( (int) $uid );
		$r = $u ? self::list_by_email( $u->user_email ) : null;
		if ( $r && 'unsubscribed' !== $r->status ) {
			global $wpdb;
			$wpdb->update( self::list_table(), array( 'status' => 'unsubscribed', 'unsub_at' => oys_now() ), array( 'id' => (int) $r->id ) );
		}
		wp_die( '<h1>' . esc_html__( 'You\'re unsubscribed', 'olivia-studio' ) . '</h1><p>' . esc_html__( 'You won\'t get the newsletter any more. Booking emails and reminders still arrive. Changed your mind? Tick the newsletter box in your account.', 'olivia-studio' ) . '</p><p><a href="' . esc_url( oys_account_url( 'profile' ) ) . '">' . esc_html__( 'My account', 'olivia-studio' ) . '</a></p>', esc_html__( 'Unsubscribed', 'olivia-studio' ), array( 'response' => 200 ) );
	}

	/* ---------- Newsletters ---------- */

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'newsletters' ) . ' WHERE id = %d', $id ) );
	}

	public static function query( $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . OYS_Install::table( 'newsletters' ) . ' ORDER BY id DESC LIMIT ' . (int) $limit );
	}

	/** Create or update a draft. @return int id */
	public static function save( array $d, $id = 0 ) {
		global $wpdb;
		$row = array(
			'subject'      => sanitize_text_field( $d['subject'] ?? '' ),
			'preheader'    => sanitize_text_field( $d['preheader'] ?? '' ),
			'body'         => sanitize_textarea_field( $d['body'] ?? '' ),
			'button_label' => sanitize_text_field( $d['button_label'] ?? '' ),
			'button_url'   => esc_url_raw( $d['button_url'] ?? '' ),
		);
		if ( $id ) {
			$wpdb->update( OYS_Install::table( 'newsletters' ), $row, array( 'id' => (int) $id, 'status' => 'draft' ) );
			return (int) $id;
		}
		$wpdb->insert( OYS_Install::table( 'newsletters' ), array_merge( $row, array( 'status' => 'draft', 'created_by' => get_current_user_id(), 'created_at' => oys_now() ) ) );
		return (int) $wpdb->insert_id;
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( OYS_Install::table( 'newsletters' ), array( 'id' => (int) $id, 'status' => 'draft' ) );
	}

	/** The body format → email HTML (escaped). */
	public static function render( $text ) {
		$out = '';
		foreach ( preg_split( "/\n\s*\n/", str_replace( "\r", '', trim( (string) $text ) ) ) as $block ) {
			$lines = array_values( array_filter( array_map( 'rtrim', explode( "\n", $block ) ), 'strlen' ) );
			if ( ! $lines ) {
				continue;
			}
			if ( str_starts_with( $lines[0], '## ' ) ) {
				$out .= '<h2 style="margin:26px 0 8px;font-size:20px;line-height:1.2;text-transform:uppercase;color:#2B5036">' . self::inline( substr( array_shift( $lines ), 3 ) ) . '</h2>';
				if ( ! $lines ) {
					continue;
				}
			}
			if ( array_filter( $lines, fn( $l ) => str_starts_with( ltrim( $l ), '- ' ) ) === $lines ) {
				$out .= '<ul style="margin:0 0 16px;padding-left:20px">' . implode( '', array_map( fn( $l ) => '<li style="margin:0 0 6px">' . self::inline( substr( ltrim( $l ), 2 ) ) . '</li>', $lines ) ) . '</ul>';
				continue;
			}
			$out .= '<p>' . implode( '<br>', array_map( array( __CLASS__, 'inline' ), $lines ) ) . '</p>';
		}
		return $out;
	}

	private static function inline( $s ) {
		$s = esc_html( $s );
		$s = preg_replace( '/\*\*(.+?)\*\*/', '<b>$1</b>', $s );
		$s = preg_replace_callback( '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', fn( $m ) => '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '" style="color:#2B5036;font-weight:700">' . $m[1] . '</a>', $s );
		return preg_replace_callback( '/(?<!["=>])\bhttps?:\/\/[^\s<]+/', fn( $m ) => '<a href="' . esc_url( html_entity_decode( $m[0] ) ) . '" style="color:#2B5036">' . $m[0] . '</a>', $s );
	}

	/** The placeholders of the one-tap password button (on a line of its own in the message). */
	const PASSWORD_BUTTON = array( '{password_button}', '{jelszo_gomb}' );

	/** The message with {first_name} filled in and the password button where the placeholder stands. */
	private static function body_html( $n, array $vars, callable $button ) {
		$mark = 'OYSPASSWORDBUTTON';
		$text = str_replace( self::PASSWORD_BUTTON, "\n\n" . $mark . "\n\n", OYS_Email_Templates::fill( $n->body, $vars ) );
		$html = self::render( $text );
		return str_contains( $html, '<p>' . $mark . '</p>' ) ? str_replace( '<p>' . $mark . '</p>', $button(), $html ) : $html;
	}

	/**
	 * „Jelszót állítok be” (Áron, 26 November: „ez az egy kattintásos, jelszógenerálós gomb ez legyen benne”): a guest's
	 * own link – one tap, a password, logged in (OYS_Welcome). $real false: a link that only says it is a preview (the
	 * admin's preview, a test to the studio). A signup-form address has no account yet: the button opens the account page.
	 */
	public static function password_button( $user_id, $real ) {
		$u = $user_id ? get_userdata( (int) $user_id ) : null;
		if ( ! $u && $real ) {
			return OYS_Emails::button_html( array( __( 'Create my account', 'olivia-studio' ), oys_account_url() ), '8px 0 24px' );
		}
		$own = $real && $u && OYS_Customers::is_customer_only( $u );
		return OYS_Emails::button_html( array( __( 'Set my password', 'olivia-studio' ), $own ? OYS_Welcome::link( $u->ID ) : OYS_Welcome::link( 0, true ) ), '8px 0 24px' );
	}

	/**
	 * The email as a subscriber sees it ($preview: the admin's preview or a test – the unsubscribe link does nothing).
	 * $real_link: the password button carries the reader's own link (default: not in a preview; a test to a guest's own
	 * account does, so the studio can try the whole way in). @return array [ subject, html ]
	 */
	public static function email_for( $n, $user_id = 0, $preview = false, $real_link = null ) {
		$u    = $user_id ? get_userdata( $user_id ) : null;
		$vars = array( 'first_name' => $u ? ( $u->first_name ?: $u->display_name ) : __( 'there', 'olivia-studio' ), 'studio' => OYS_Settings::get( 'email_from_name' ) );
		$body = self::body_html( $n, $vars, fn() => self::password_button( $u ? $u->ID : 0, null === $real_link ? ! $preview : (bool) $real_link ) );
		$cta  = $n->button_label && $n->button_url ? array( $n->button_label, $n->button_url ) : null;
		$foot = $preview ? add_query_arg( 'oys_unsub', 'preview', home_url( '/' ) ) : ( $user_id ? self::unsubscribe_url( $user_id ) : '#' );
		return array( OYS_Email_Templates::fill( $n->subject, $vars ), OYS_Emails::newsletter_html( $n->subject, $n->preheader, $body, $cta, $foot ) );
	}

	/**
	 * Everyone a newsletter can be sent to, for choosing one by one (Áron, 26 November: „Motibróból áthoztuk a usereket, és
	 * szeretnénk egyesével kijelölni … ki tudja választani az e-mail megfogalmazását követően, hogy kiknek küldi ki …
	 * és lássuk mellette, hogy hány alkalma is van”): the guests (with the newsletter tick or not) and the confirmed
	 * addresses from the signup forms. Each: type (user|sub), id, name, email, subscribed, sessions left online (valid
	 * passes), sessions from Motibro still to redeem, the soonest last day.
	 * @return array[] subscribers first, then by name
	 */
	public static function pick_list() {
		global $wpdb;
		$t     = OYS_Install::table( 'passes' );
		$left  = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT user_id, SUM(credits_left) n, MIN(expires_at) soon FROM $t WHERE credits_left > 0 AND ( expires_at IS NULL OR expires_at > %s ) GROUP BY user_id", oys_now() ) ) as $r ) { // phpcs:ignore
			$left[ (int) $r->user_id ] = array( (int) $r->n, $r->soon ? wp_date( 'Y-m-d', oys_ts( $r->soon ) ) : '' );
		}
		$held = array();
		if ( class_exists( 'OYS_Held' ) ) {
			foreach ( OYS_Held::all() as $e ) {
				if ( 'held' === $e['status'] && (int) $e['left'] > 0 ) {
					$held[ (int) $e['user'] ] = ( $held[ (int) $e['user'] ] ?? 0 ) + (int) $e['left'];
				}
			}
		}
		$out   = array();
		$seen  = array();
		$users = get_users( array( 'role__in' => array( 'oys_customer' ), 'number' => 5000, 'fields' => array( 'ID', 'user_email', 'display_name' ) ) );
		foreach ( $users as $u ) {
			$first  = (string) get_user_meta( $u->ID, 'first_name', true );
			$last   = (string) get_user_meta( $u->ID, 'last_name', true );
			$email  = strtolower( (string) $u->user_email );
			$out[]  = array(
				'type'       => 'user',
				'id'         => (int) $u->ID,
				'name'       => trim( OYS_Customers::full_name( $first, $last ) ) ?: (string) $u->display_name,
				'email'      => $email,
				'subscribed' => '1' === (string) get_user_meta( $u->ID, 'oys_marketing', true ),
				'online'     => $left[ (int) $u->ID ][0] ?? 0,
				'until'      => $left[ (int) $u->ID ][1] ?? '',
				'held'       => $held[ (int) $u->ID ] ?? 0,
				'motibro'    => '' !== (string) get_user_meta( $u->ID, 'oys_imported_at', true ),
			);
			$seen[ $email ] = true;
		}
		foreach ( self::list_query( 'confirmed', 100000 ) as $r ) {
			if ( ! isset( $seen[ strtolower( $r->email ) ] ) ) {
				$out[] = array( 'type' => 'sub', 'id' => (int) $r->id, 'name' => (string) $r->name, 'email' => strtolower( $r->email ), 'subscribed' => true, 'online' => 0, 'until' => '', 'held' => 0, 'motibro' => false );
			}
		}
		usort( $out, fn( $a, $b ) => array( ! $a['subscribed'], remove_accents( mb_strtolower( $a['name'] ?: $a['email'] ) ) ) <=> array( ! $b['subscribed'], remove_accents( mb_strtolower( $b['name'] ?: $b['email'] ) ) ) );
		return $out;
	}

	/**
	 * Queue a draft for every subscriber – or, with $pick ( [ 'users' => ids, 'subs' => ids ] ), for exactly the people
	 * chosen (a guest without the newsletter tick too: the studio chose them for this letter); the cron sends it in batches.
	 * @return int|WP_Error how many will receive it
	 */
	public static function send( $id, $pick = null ) {
		global $wpdb;
		$n = self::get( $id );
		if ( ! $n || 'draft' !== $n->status ) {
			return new WP_Error( 'oys_newsletter', __( 'This newsletter was already sent.', 'olivia-studio' ) );
		}
		if ( '' === trim( $n->subject ) || '' === trim( $n->body ) ) {
			return new WP_Error( 'oys_newsletter', __( 'Write a subject and a message first.', 'olivia-studio' ) );
		}
		$t = OYS_Install::table( 'newsletters' );
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'sending' WHERE id = %d AND status = 'draft'", $id ) ) ) {
			return new WP_Error( 'oys_newsletter', __( 'This newsletter was already sent.', 'olivia-studio' ) );
		}
		$q = OYS_Install::table( 'newsletter_queue' );
		if ( is_array( $pick ) ) {
			$users = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $pick['users'] ?? array() ) ), fn( $u ) => $u > 0 && get_userdata( $u ) ) ) );
			$mails = array_flip( array_map( fn( $u ) => strtolower( get_userdata( $u )->user_email ), $users ) );
			$list  = array();
			foreach ( array_unique( array_map( 'intval', (array) ( $pick['subs'] ?? array() ) ) ) as $sid ) {
				$r = self::list_get( $sid );
				if ( $r && 'confirmed' === $r->status && ! isset( $mails[ strtolower( $r->email ) ] ) ) {
					$list[] = (int) $r->id;
				}
			}
			if ( ! $users && ! $list ) {
				$wpdb->update( $t, array( 'status' => 'draft' ), array( 'id' => $id ) );
				return new WP_Error( 'oys_newsletter', __( 'Choose at least one person to send it to.', 'olivia-studio' ) );
			}
			$wpdb->update( $t, array( 'audience' => 'picked' ), array( 'id' => $id ) );
		} else {
			$users = self::subscribers();
			$list  = array_map( fn( $r ) => (int) $r->id, self::list_recipients() );
		}
		foreach ( array_chunk( $users, 200 ) as $chunk ) {
			$values = implode( ',', array_map( fn( $u ) => $wpdb->prepare( '(%d, %d, %s)', $id, $u, 'queued' ), $chunk ) );
			$wpdb->query( "INSERT IGNORE INTO $q (newsletter_id, user_id, status) VALUES $values" ); // phpcs:ignore
		}
		// and the confirmed addresses from the signup forms (not a customer's: they got theirs above)
		$lq = OYS_Install::table( 'newsletter_list_queue' );
		foreach ( array_chunk( $list, 200 ) as $chunk ) {
			$values = implode( ',', array_map( fn( $sid ) => $wpdb->prepare( '(%d, %d, %s)', $id, $sid, 'queued' ), $chunk ) );
			$wpdb->query( "INSERT IGNORE INTO $lq (newsletter_id, subscriber_id, status) VALUES $values" ); // phpcs:ignore
		}
		$total = count( $users ) + count( $list );
		$wpdb->update( $t, array( 'total' => $total, 'queued_at' => oys_now() ), array( 'id' => $id ) );
		if ( ! $total ) {
			$wpdb->update( $t, array( 'status' => 'sent', 'sent_at' => oys_now() ), array( 'id' => $id ) );
		}
		return $total;
	}

	/* ---------- Scheduling (Áron, 26 November: „A hírlevelekhez kellene egy időzítés is, mert már készített Olivia januári
	   hírlevelet”) ---------- */

	const SCHEDULE = 'oys_nl_schedule';

	/** @return array id => [ at (UTC), pick (null = every subscriber), by, count, warned ] */
	public static function schedules() {
		$s = get_option( self::SCHEDULE, array() );
		return is_array( $s ) ? $s : array();
	}

	public static function scheduled( $id ) {
		return self::schedules()[ (int) $id ] ?? null;
	}

	/**
	 * Send a draft later, to the people chosen now ($pick as in send(); null: every subscriber at that time). The text
	 * stays editable until then: the letter goes out as it is at that moment. @return true|WP_Error
	 */
	public static function schedule( $id, $utc, $pick = null, $by = 0 ) {
		$n = self::get( $id );
		if ( ! $n || 'draft' !== $n->status ) {
			return new WP_Error( 'oys_newsletter', __( 'This newsletter was already sent.', 'olivia-studio' ) );
		}
		if ( '' === trim( $n->subject ) || '' === trim( $n->body ) ) {
			return new WP_Error( 'oys_newsletter', __( 'Write a subject and a message first.', 'olivia-studio' ) );
		}
		if ( ! $utc || $utc <= oys_now() ) {
			return new WP_Error( 'oys_newsletter', __( 'Choose a day and time in the future.', 'olivia-studio' ) );
		}
		$count = null === $pick ? count( self::subscribers() ) + count( self::list_recipients() ) : count( (array) ( $pick['users'] ?? array() ) ) + count( (array) ( $pick['subs'] ?? array() ) );
		if ( ! $count ) {
			return new WP_Error( 'oys_newsletter', __( 'Choose at least one person to send it to.', 'olivia-studio' ) );
		}
		$all            = self::schedules();
		$all[ (int) $id ] = array( 'at' => $utc, 'pick' => $pick, 'by' => (int) ( $by ?: get_current_user_id() ), 'count' => $count, 'warned' => oys_ts( $utc ) - time() < DAY_IN_SECONDS );
		update_option( self::SCHEDULE, $all, false );
		return true;
	}

	public static function unschedule( $id ) {
		$all = self::schedules();
		unset( $all[ (int) $id ] );
		update_option( self::SCHEDULE, $all, false );
	}

	/**
	 * Cron: a day before, a word on the phone („tomorrow at 9 it goes out – change it now if you want”); at the time, the
	 * letter goes into the queue (sent in batches as usual) and a word that it is on its way.
	 */
	public static function run_schedule() {
		foreach ( self::schedules() as $id => $e ) {
			$n = self::get( $id );
			if ( ! $n || 'draft' !== $n->status ) {
				self::unschedule( $id ); // deleted or sent by hand meanwhile
				continue;
			}
			$link = admin_url( 'admin.php?page=oys-newsletter&edit=' . (int) $id );
			if ( empty( $e['warned'] ) && oys_ts( $e['at'] ) - time() <= DAY_IN_SECONDS && $e['at'] > oys_now() ) {
				$all                       = self::schedules();
				$all[ $id ]['warned']      = true;
				update_option( self::SCHEDULE, $all, false );
				if ( class_exists( 'OYS_Studio_App' ) && $e['by'] ) {
					OYS_Studio_App::notify( (int) $e['by'], __( 'Newsletter tomorrow', 'olivia-studio' ), sprintf( __( '„%1$s” goes out %2$s. If you want to change it, now is the time.', 'olivia-studio' ), $n->subject, oys_date( $e['at'], 'D M j, g:i a' ) ), $link, 'nl-' . (int) $id );
				}
			}
			if ( $e['at'] > oys_now() ) {
				continue;
			}
			self::unschedule( $id ); // first, so it never goes twice
			$res = self::send( $id, $e['pick'] );
			if ( class_exists( 'OYS_Studio_App' ) && $e['by'] ) {
				OYS_Studio_App::notify( (int) $e['by'], __( 'Newsletter on its way', 'olivia-studio' ), is_wp_error( $res ) ? $res->get_error_message() : sprintf( _n( '„%1$s” is going out to %2$d person.', '„%1$s” is going out to %2$d people.', (int) $res, 'olivia-studio' ), $n->subject, (int) $res ), $link, 'nl-' . (int) $id );
			}
		}
	}

	/** Cron: send the next batch. @return int emails sent */
	public static function process_queue( $batch = null ) {
		global $wpdb;
		$q     = OYS_Install::table( 'newsletter_queue' );
		$t     = OYS_Install::table( 'newsletters' );
		$batch = $batch ?: max( 1, (int) OYS_Settings::get( 'newsletter_batch' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $q WHERE status = 'queued' ORDER BY id LIMIT %d", $batch ) );
		$sent  = 0;
		$cache = array();
		foreach ( $rows as $r ) {
			// Claim the row, so two cron runs never send the same email twice.
			if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE $q SET status = 'sending' WHERE id = %d AND status = 'queued'", $r->id ) ) ) {
				continue;
			}
			$n = $cache[ $r->newsletter_id ] ??= self::get( $r->newsletter_id );
			$u = get_userdata( $r->user_id );
			$ok = false;
			// a letter to the people chosen one by one goes to each of them; one to the subscribers only while they are
			if ( $n && $u && ( 'picked' === ( $n->audience ?? '' ) || '1' === (string) get_user_meta( $u->ID, 'oys_marketing', true ) ) ) {
				[ $subject, $html ] = self::email_for( $n, $u->ID );
				$ok = OYS_Emails::send_raw( $u->user_email, $subject, $html, array( 'List-Unsubscribe: <' . self::unsubscribe_url( $u->ID ) . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' ) );
			}
			$wpdb->update( $q, array( 'status' => $ok ? 'sent' : 'skipped', 'sent_at' => oys_now() ), array( 'id' => $r->id ) );
			if ( $ok ) {
				$sent++;
				$wpdb->query( $wpdb->prepare( "UPDATE $t SET sent = sent + 1 WHERE id = %d", $r->newsletter_id ) );
			}
		}
		// the signup-form addresses, in the same batch size
		$lq   = OYS_Install::table( 'newsletter_list_queue' );
		$left = max( 0, $batch - count( $rows ) );
		$lrow = $left ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $lq WHERE status = 'queued' ORDER BY id LIMIT %d", $left ) ) : array();
		foreach ( $lrow as $r ) {
			if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE $lq SET status = 'sending' WHERE id = %d AND status = 'queued'", $r->id ) ) ) {
				continue;
			}
			$n   = $cache[ $r->newsletter_id ] ??= self::get( $r->newsletter_id );
			$sub = self::list_get( $r->subscriber_id );
			$ok  = false;
			if ( $n && $sub && 'confirmed' === $sub->status ) {
				[ $subject, $html ] = self::email_for_list( $n, $sub );
				$ok = OYS_Emails::send_raw( $sub->email, $subject, $html, array( 'List-Unsubscribe: <' . self::list_unsubscribe_url( $sub->id ) . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' ) );
			}
			$wpdb->update( $lq, array( 'status' => $ok ? 'sent' : 'skipped', 'sent_at' => oys_now() ), array( 'id' => $r->id ) );
			if ( $ok ) {
				$sent++;
				$wpdb->query( $wpdb->prepare( "UPDATE $t SET sent = sent + 1 WHERE id = %d", $r->newsletter_id ) );
			}
		}
		// Newsletters with nothing left to send are done.
		$wpdb->query( $wpdb->prepare( "UPDATE $t n SET n.status = 'sent', n.sent_at = %s WHERE n.status = 'sending' AND NOT EXISTS ( SELECT 1 FROM $q q WHERE q.newsletter_id = n.id AND q.status IN ('queued','sending') ) AND NOT EXISTS ( SELECT 1 FROM $lq l WHERE l.newsletter_id = n.id AND l.status IN ('queued','sending') )", oys_now() ) );
		return $sent;
	}

	/** The email to a signup-form address: their name if they gave one, their own unsubscribe link. */
	public static function email_for_list( $n, $sub ) {
		$vars = array( 'first_name' => '' !== (string) $sub->name ? $sub->name : __( 'there', 'olivia-studio' ), 'studio' => OYS_Settings::get( 'email_from_name' ) );
		$body = self::body_html( $n, $vars, fn() => self::password_button( 0, true ) );
		$cta  = $n->button_label && $n->button_url ? array( $n->button_label, $n->button_url ) : null;
		return array( OYS_Email_Templates::fill( $n->subject, $vars ), OYS_Emails::newsletter_html( $n->subject, $n->preheader, $body, $cta, self::list_unsubscribe_url( $sub->id ) ) );
	}

	/**
	 * One test email to the studio (or any address). To a guest's own account (e.g. Olívia's test guest) the password
	 * button is that account's real link, so the whole way in can be tried; to anyone else it only says it is a preview.
	 */
	public static function send_test( $id, $to ) {
		$n = self::get( $id );
		if ( ! $n || ! is_email( $to ) ) {
			return false;
		}
		$u = get_user_by( 'email', $to );
		[ $subject, $html ] = self::email_for( $n, $u ? $u->ID : 0, true, $u && OYS_Customers::is_customer_only( $u ) );
		return OYS_Emails::send_raw( $to, __( '[Test]', 'olivia-studio' ) . ' ' . $subject, $html );
	}

	/** The loyalty draw's announcement, from its email template, queued to every subscriber. */
	public static function announce_draw( $period, $tickets, $entrants, $product ) {
		$t = OYS_Email_Templates::get( 'raffle_announcement' );
		if ( ! $t || ! $t['enabled'] ) {
			return 0;
		}
		[ , , $end ] = OYS_Rewards::period();
		$vars = array(
			'period'   => $period,
			'prize'    => $product['name'] ?? __( 'a free pass', 'olivia-studio' ),
			'tickets'  => $tickets,
			'entrants' => $entrants,
			'next_draw' => wp_date( 'F j', oys_ts( $end ) ),
			'studio'   => OYS_Settings::get( 'email_from_name' ),
			'first_name' => '{first_name}',
		);
		$body = OYS_Email_Templates::fill( $t['message'], $vars ) . ( $t['closing'] ? "\n\n" . OYS_Email_Templates::fill( $t['closing'], $vars ) : '' );
		$id   = self::save( array(
			'subject'      => OYS_Email_Templates::fill( $t['subject'], $vars ),
			'preheader'    => OYS_Email_Templates::fill( $t['heading'], $vars ),
			'body'         => $body,
			'button_label' => $t['button'] ? OYS_Email_Templates::fill( $t['button'], $vars ) : '',
			'button_url'   => $t['button'] ? oys_page_url( 'book' ) : '',
		) );
		self::send( $id );
		return $id;
	}
}
