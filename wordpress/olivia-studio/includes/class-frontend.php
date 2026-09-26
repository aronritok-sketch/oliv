<?php
/**
 * Public side: shortcodes and form handlers.
 *
 *   [oys_schedule days="14" kind="group,event" class=""]  bookable timetable
 *   [oys_events]                                            upcoming workshops and events
 *   [oys_pricing]                                           prices with buy buttons
 *   [oys_book]                                              booking / checkout page
 *   [oys_account]                                           customer account (log in / sign up when logged out)
 *   [oys_gift_cards]                                        buy a gift card
 *   [oys_private_request]                                   private session request form
 */

defined( 'ABSPATH' ) || exit;

class OYS_Frontend {

	public static function init() {
		foreach ( array( 'schedule', 'events', 'pricing', 'book', 'account', 'gift_cards', 'private_request' ) as $sc ) {
			add_shortcode( 'oys_' . $sc, array( __CLASS__, 'sc_' . $sc ) );
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_oys_checkout', array( __CLASS__, 'handle_checkout' ) );
		add_action( 'admin_post_nopriv_oys_checkout', array( __CLASS__, 'handle_checkout_guest' ) );
		add_action( 'admin_post_oys_buy', array( __CLASS__, 'handle_buy' ) );
		add_action( 'admin_post_nopriv_oys_buy', array( __CLASS__, 'handle_checkout_guest' ) );
		add_action( 'admin_post_oys_cancel', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_oys_waitlist', array( __CLASS__, 'handle_waitlist' ) );
		add_action( 'admin_post_oys_ics', array( __CLASS__, 'handle_ics' ) );
		add_action( 'template_redirect', array( __CLASS__, 'return_from_stripe' ) );
		add_filter( 'body_class', function ( $c ) {
			$c[] = is_user_logged_in() ? 'oys-logged-in' : 'oys-logged-out';
			return $c;
		} );
	}

	public static function assets() {
		wp_enqueue_style( 'olivia-studio', OYS_URL . 'assets/oys.css', array(), OYS_VERSION );
	}

	private static function form_open( $action, $extra = '' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $extra . '>'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. wp_nonce_field( $action, '_wpnonce', true, false );
	}

	private static function current_url() {
		return home_url( add_query_arg( array() ) );
	}

	/* ======================================================================
	   Timetable
	   ====================================================================== */

	public static function sc_schedule( $atts ) {
		$atts  = shortcode_atts( array( 'days' => 14, 'kind' => 'group,event', 'class' => '', 'empty_days' => 'yes' ), $atts );
		$kinds = array_filter( array_map( 'trim', explode( ',', $atts['kind'] ) ) );
		$items = OYS_Schedule::query( array(
			'kind'       => $kinds,
			'from'       => oys_now(),
			'to'         => oys_utc_plus( (int) $atts['days'] * DAY_IN_SECONDS ),
			'status'     => 'scheduled',
			'class_slug' => sanitize_title( $atts['class'] ),
		) );
		$by_day = array();
		foreach ( $items as $s ) {
			$by_day[ wp_date( 'Y-m-d', oys_ts( $s->starts_at ) ) ][] = $s;
		}
		$user_id = get_current_user_id();
		$tones   = array( 'lilac', 'pink', 'sun', 'sage' );
		$classes = array_keys( oys_class_options() );

		$out   = array( '<div class="schedule oys-schedule">' );
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		for ( $d = 0; $d < (int) $atts['days']; $d++ ) {
			$day  = $today->modify( "+$d days" );
			$key  = $day->format( 'Y-m-d' );
			$list = $by_day[ $key ] ?? array();
			$head = '<h3><abbr title="' . esc_attr( wp_date( 'l, F j', $day->getTimestamp() ) ) . '">' . esc_html( wp_date( 'D', $day->getTimestamp() ) ) . '</abbr><small>' . esc_html( wp_date( 'M j', $day->getTimestamp() ) ) . '</small></h3>';
			if ( ! $list ) {
				if ( 'yes' === $atts['empty_days'] ) {
					$out[] = '<div class="day is-empty">' . $head . '<p>' . esc_html__( 'No group classes — private sessions available on request.', 'olivia-studio' ) . '</p></div>';
				}
				continue;
			}
			$out[] = '<div class="day">' . $head . '<ul>';
			foreach ( $list as $s ) {
				$idx   = array_search( $s->class_slug, $classes, true );
				$tone  = 'event' === $s->kind ? 'pink' : $tones[ ( false === $idx ? 0 : $idx ) % 4 ];
				$left  = OYS_Schedule::spots_left( $s );
				$mine  = $user_id ? OYS_Bookings::active_for( $user_id, $s->id ) : null;
				$wait  = $user_id && ! $mine ? OYS_Bookings::waitlist_position( $user_id, $s->id ) : 0;
				$class_url = apply_filters( 'oys_class_url', '', $s->class_slug );
				$plain     = esc_html( oys_session_title( $s ) );
				$title     = $class_url && 'group' === $s->kind ? '<a href="' . esc_url( $class_url ) . '">' . $plain . '</a>' : $plain;

				if ( $mine && 'pending' !== $mine->status ) {
					$action = '<span class="session__status is-booked">' . oys_icon( 'check' ) . esc_html__( 'You\'re booked', 'olivia-studio' ) . '</span>';
				} elseif ( $wait ) {
					$action = '<span class="session__status">' . sprintf( esc_html__( 'Waitlist #%d', 'olivia-studio' ), $wait ) . '</span>';
				} elseif ( OYS_Schedule::closed_reason( $s ) ) {
					$action = '<span class="session__status">' . esc_html__( 'Booking closed', 'olivia-studio' ) . '</span>';
				} elseif ( $left < 1 ) {
					$action = '<a class="session__book" href="' . esc_url( oys_book_url( $s->id ) ) . '">' . esc_html__( 'Full · join waitlist', 'olivia-studio' ) . oys_icon( 'arrow' ) . '</a>';
				} else {
					$action = '<a class="session__book" href="' . esc_url( oys_book_url( $s->id ) ) . '">' . esc_html__( 'Book', 'olivia-studio' ) . '<span class="sr-only"> ' . $plain . ', ' . esc_html( oys_date( $s->starts_at, 'l g:i a' ) ) . '</span>' . oys_icon( 'arrow' ) . '</a>';
				}
				$spots = $left < 1 ? __( 'Full', 'olivia-studio' ) : ( $left <= 3 ? sprintf( _n( '%d spot left', '%d spots left', $left, 'olivia-studio' ), $left ) : '' );
				$online = oys_is_online( $s );
				$out[] = '<li class="session session--' . esc_attr( $tone ) . ( $online ? ' session--online' : '' ) . '">'
					. '<span class="session__time">' . esc_html( oys_time( $s->starts_at ) . ' – ' . oys_time( $s->ends_at ) ) . '</span>'
					. '<span class="session__class">' . $title . '</span>'
					. '<span class="session__place">' . ( $online ? '<span class="session__online">' . esc_html__( 'Online', 'olivia-studio' ) . '</span> ' . esc_html( $s->price_cents ? oys_money( $s->price_cents ) : '' ) : esc_html( $s->location ) . ( oys_is_hybrid( $s ) ? ' <span class="session__online">' . esc_html__( '+ Live online', 'olivia-studio' ) . '</span>' : '' ) ) . '</span>'
					. ( $s->note ? '<span class="session__note">' . esc_html( $s->note ) . '</span>' : '' )
					. ( $spots ? '<span class="session__spots' . ( $left < 1 ? ' is-full' : '' ) . '">' . esc_html( $spots ) . '</span>' : '' )
					. $action . '</li>';
			}
			$out[] = '</ul></div>';
		}
		if ( ! $items && 'yes' !== $atts['empty_days'] ) {
			$out[] = '<p class="oys-empty">' . esc_html__( 'New dates are coming soon.', 'olivia-studio' ) . '</p>';
		}
		$out[] = '</div>';
		return implode( '', $out );
	}

	public static function sc_events( $atts ) {
		$events = OYS_Schedule::query( array( 'kind' => 'event', 'from' => oys_now(), 'status' => 'scheduled', 'limit' => 12 ) );
		if ( ! $events ) {
			return '<p class="oys-empty">' . esc_html__( 'The next events will be announced here and on Instagram first.', 'olivia-studio' ) . '</p>';
		}
		$tones = array( 'pink', 'lilac', 'sun' );
		$out   = '<ul class="events oys-events">';
		foreach ( $events as $i => $s ) {
			$left = OYS_Schedule::spots_left( $s );
			$out .= '<li class="event"><a href="' . esc_url( oys_book_url( $s->id ) ) . '"><span class="event__date event__date--' . $tones[ $i % 3 ] . '"><span>' . esc_html( wp_date( 'M', oys_ts( $s->starts_at ) ) ) . '</span><b>' . esc_html( wp_date( 'j', oys_ts( $s->starts_at ) ) ) . '</b></span>'
				. '<span><span class="event__title">' . esc_html( oys_session_title( $s ) ) . '</span>'
				. '<span class="event__meta">' . esc_html( oys_date( $s->starts_at, 'l, g:i a' ) . ' – ' . oys_time( $s->ends_at ) . ( $s->location ? ' · ' . $s->location : '' ) . ' · ' . ( $s->price_cents ? oys_money( $s->price_cents ) : __( 'Free', 'olivia-studio' ) ) ) . ( $left < 1 ? ' · ' . __( 'Full, waitlist open', 'olivia-studio' ) : '' ) . '</span></span>'
				. '<span class="event__go" aria-hidden="true">' . oys_icon( 'arrow' ) . '</span></a></li>';
		}
		return $out . '</ul>';
	}

	/* ======================================================================
	   Pricing
	   ====================================================================== */


	public static function sc_pricing( $atts ) {
		$dropin = (int) apply_filters( 'oys_dropin_display_price', 2500 );
		$cards  = array();
		$online_price = (int) OYS_Settings::get( 'online_price_cents' );
		$ratio        = OYS_Passes::online_per_credit();
		$cards[] = array( 'tone' => 'paper', 'title' => __( 'Drop-in class', 'olivia-studio' ), 'price' => oys_money( $dropin ), 'unit' => __( 'per class', 'olivia-studio' ), 'features' => array_filter( array( __( 'Any group class in person', 'olivia-studio' ), $online_price ? sprintf( __( 'Online classes: %s', 'olivia-studio' ), oys_money( $online_price ) ) : '', __( 'Bring friends: add guests when you book', 'olivia-studio' ) ) ), 'cta' => array( __( 'Book a class', 'olivia-studio' ), oys_page_url( 'book' ) ), 'featured' => false );
		$tones = array( 'lilac', 'sun', 'pink' );
		$i     = 0;
		foreach ( OYS_Products::all( true ) as $p ) {
			if ( 'private_single' === $p['kind'] && 60 !== (int) $p['duration_min'] ) {
				continue;
			}
			$features = array_filter( array_map( 'trim', explode( "\n", $p['features'] ) ) );
			if ( 'private_single' === $p['kind'] ) {
				$cta  = array( __( 'Request a private session', 'olivia-studio' ), oys_account_url( 'private' ) );
				$unit = sprintf( __( '%d minutes', 'olivia-studio' ), (int) $p['duration_min'] );
				foreach ( OYS_Products::all( true ) as $q ) {
					if ( 'private_single' === $q['kind'] && 60 !== (int) $q['duration_min'] ) {
						$features[] = sprintf( '%d minutes: %s', (int) $q['duration_min'], oys_money( $q['price_cents'] ) );
					}
				}
				if ( (int) $p['online_price_cents'] && (int) $p['online_price_cents'] !== (int) $p['price_cents'] ) {
					$features[] = sprintf( __( 'Online: %s (60 minutes)', 'olivia-studio' ), oys_money( $p['online_price_cents'] ) );
				}
			} elseif ( 'membership' === $p['kind'] ) {
				$cta  = array( __( 'Join', 'olivia-studio' ), oys_page_url( 'book', array( 'product' => $p['id'] ) ) );
				$unit = OYS_Products::period_label( $p );
			} else {
				$cta  = array( 'intro' === $p['kind'] ? __( 'Claim the intro offer', 'olivia-studio' ) : __( 'Buy now', 'olivia-studio' ), oys_page_url( 'book', array( 'product' => $p['id'] ) ) );
				$unit = 'private_pack' === $p['kind'] ? __( 'per pack', 'olivia-studio' ) : sprintf( 'online_pack' === $p['kind'] ? _n( '%d online class', '%d online classes', (int) $p['credits'], 'olivia-studio' ) : _n( '%d class', '%d classes', (int) $p['credits'], 'olivia-studio' ), (int) $p['credits'] );
				if ( in_array( $p['kind'], array( 'pack', 'intro' ), true ) && $ratio > 1 ) {
					$features[] = sprintf( __( 'Online classes: 1 class = %d online', 'olivia-studio' ), $ratio );
				}
			}
			$cards[] = array( 'tone' => $p['featured'] ? 'forest' : $tones[ $i++ % 3 ], 'title' => $p['name'], 'price' => oys_money( $p['price_cents'] ), 'unit' => $unit, 'features' => $features, 'cta' => $cta, 'featured' => (bool) $p['featured'] );
		}
		$cards[] = array( 'tone' => 'pink', 'title' => __( 'Teams & companies', 'olivia-studio' ), 'price' => __( 'Custom', 'olivia-studio' ), 'unit' => __( 'quote', 'olivia-studio' ), 'features' => array( __( 'On-site, outdoors or online', 'olivia-studio' ), __( 'One-off or weekly', 'olivia-studio' ), __( 'Sports teams and clubs too', 'olivia-studio' ) ), 'cta' => array( __( 'Request a proposal', 'olivia-studio' ), apply_filters( 'oys_corporate_contact_url', home_url( '/contact/?topic=corporate#book' ) ) ), 'featured' => false );

		$out = '<div class="pricing oys-pricing">';
		foreach ( $cards as $c ) {
			$out .= '<article class="price price--' . esc_attr( $c['tone'] ) . '">'
				. ( $c['featured'] ? apply_filters( 'oys_featured_badge', '<span class="price__tag">' . esc_html__( 'Most popular', 'olivia-studio' ) . '</span>' ) : '' )
				. '<h3>' . esc_html( $c['title'] ) . '</h3><p class="price__amount">' . esc_html( $c['price'] ) . '</p><p class="price__unit">' . esc_html( $c['unit'] ) . '</p>'
				. '<ul>' . implode( '', array_map( fn( $f ) => '<li>' . esc_html( $f ) . '</li>', $c['features'] ) ) . '</ul>'
				. '<a class="btn ' . ( $c['featured'] ? 'btn--orchid' : 'btn--dark' ) . '" href="' . esc_url( $c['cta'][1] ) . '">' . esc_html( $c['cta'][0] ) . '</a></article>';
		}
		return $out . '</div>';
	}

	/* ======================================================================
	   Booking / checkout page
	   ====================================================================== */

	/** Returning from Stripe: fulfil straight away if paid (the webhook may still be on its way). */
	public static function return_from_stripe() {
		if ( empty( $_GET['oys_order'] ) || empty( $_GET['oys_return'] ) ) {
			return;
		}
		$order_id = (int) $_GET['oys_order'];
		if ( ! hash_equals( OYS_Stripe::order_key( $order_id ), (string) ( $_GET['oys_key'] ?? '' ) ) ) {
			return;
		}
		$order = OYS_Orders::get( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( 'success' === $_GET['oys_return'] && 'pending' === $order->status && $order->stripe_session_id ) {
			OYS_Stripe::sync_session( $order->stripe_session_id );
		}
		if ( 'cancel' === $_GET['oys_return'] && 'pending' === $order->status ) {
			// Close the Stripe page so it can't be paid later, and free the seat now.
			if ( $order->stripe_session_id ) {
				OYS_Stripe::request( 'POST', '/v1/checkout/sessions/' . rawurlencode( $order->stripe_session_id ) . '/expire' );
			}
			OYS_Orders::mark_unpaid( $order_id, 'expired' );
		}
	}

	public static function sc_book() {
		$out = oys_render_flash();
		if ( ! empty( $_GET['oys_order'] ) ) {
			return $out . self::render_return();
		}
		if ( ! empty( $_GET['session'] ) ) {
			return $out . self::render_session_checkout( (int) $_GET['session'] );
		}
		if ( ! empty( $_GET['product'] ) ) {
			return $out . self::render_product_checkout( sanitize_key( $_GET['product'] ) );
		}
		return $out . '<div class="oys-intro"><p class="lead">' . esc_html__( 'Choose a class from the timetable below. Beginners are welcome in every class.', 'olivia-studio' ) . '</p></div>' . self::sc_schedule( array( 'days' => 21 ) );
	}

	private static function session_card( $s ) {
		$rows = array(
			array( 'calendar', oys_date( $s->starts_at, 'l, F j' ) ),
			array( 'clock', oys_time( $s->starts_at ) . ' – ' . oys_time( $s->ends_at ) ),
		);
		if ( oys_is_online( $s ) ) {
			$rows[] = array( 'pin', __( 'Online, live. The link is in your confirmation email and your account.', 'olivia-studio' ) );
		} elseif ( $s->location ) {
			$rows[] = array( 'pin', $s->location );
		}
		if ( oys_is_hybrid( $s ) ) {
			$rows[] = array( 'screen', __( 'Also streamed live: join from home if you prefer.', 'olivia-studio' ) );
		}
		if ( 'private' !== $s->kind ) {
			$left   = OYS_Schedule::spots_left( $s );
			$rows[] = array( 'users', ( oys_is_hybrid( $s ) ? __( 'Studio:', 'olivia-studio' ) . ' ' : '' ) . ( $left < 1 ? __( 'Full', 'olivia-studio' ) : sprintf( _n( '%d spot left', '%d spots left', $left, 'olivia-studio' ), $left ) ) );
		}
		$html = '<div class="oys-card oys-summary"><p class="kicker">' . esc_html( ( OYS_Schedule::kinds()[ $s->kind ] ?? '' ) . ( oys_is_online( $s ) ? ' · ' . __( 'Online', 'olivia-studio' ) : ( oys_is_hybrid( $s ) ? ' · ' . __( 'Studio + live online', 'olivia-studio' ) : '' ) ) ) . '</p><h2>' . esc_html( oys_session_title( $s ) ) . '</h2><ul class="oys-facts">';
		foreach ( $rows as $r ) {
			$html .= '<li>' . oys_icon( $r[0] ) . '<span>' . esc_html( $r[1] ) . '</span></li>';
		}
		$html .= '</ul>';
		if ( $s->description ) {
			$html .= '<div class="oys-desc">' . wpautop( esc_html( $s->description ) ) . '</div>';
		}
		if ( $s->note ) {
			$html .= '<p class="session__note">' . esc_html( $s->note ) . '</p>';
		}
		return $html . '</div>';
	}

	private static function auth_block( $redirect, $intro ) {
		return '<div class="oys-card oys-auth"><h2>' . esc_html__( 'Log in or create an account', 'olivia-studio' ) . '</h2><p>' . esc_html( $intro ) . '</p>' . self::auth_forms( $redirect ) . '</div>';
	}

	private static function waiver_field() {
		if ( is_user_logged_in() && OYS_Customers::has_waiver( get_current_user_id() ) ) {
			return '';
		}
		return '<details class="oys-waiver"><summary>' . esc_html__( 'Participation agreement', 'olivia-studio' ) . '</summary><p>' . esc_html( OYS_Settings::get( 'waiver_text' ) ) . '</p></details>'
			. '<label class="oys-check"><input type="checkbox" name="waiver" value="1" required> <span>' . esc_html__( 'I have read and accept the participation agreement.', 'olivia-studio' ) . '</span></label>';
	}


	/** Guest name/email rows. Visible rows = $open; the rest appear with "Add a guest". */
	private static function guest_fields( $max, $open = 0 ) {
		if ( $max < 1 ) {
			return '';
		}
		$rows = '';
		for ( $i = 0; $i < $max; $i++ ) {
			$rows .= '<div class="oys-guestrow" data-guest' . ( $i >= $open ? ' hidden' : '' ) . '><span class="oys-guestrow__n">' . sprintf( esc_html__( 'Guest %d', 'olivia-studio' ), $i + 1 ) . '</span>'
				. '<label class="field"><span>' . esc_html__( 'Name', 'olivia-studio' ) . '</span><input type="text" name="guest_name[]" id="oys-guest-name-' . $i . '" autocomplete="off"></label>'
				. '<label class="field"><span>' . esc_html__( 'Email (optional, for their invite)', 'olivia-studio' ) . '</span><input type="email" name="guest_email[]" id="oys-guest-email-' . $i . '" autocomplete="off"></label>'
				. '<button type="button" class="oys-guestrow__remove" data-guest-remove aria-label="' . esc_attr__( 'Remove guest', 'olivia-studio' ) . '">×</button></div>';
		}
		return '<fieldset class="oys-guests" data-guests data-max="' . (int) $max . '"><legend>' . esc_html__( 'Bringing friends?', 'olivia-studio' ) . '</legend>'
			. '<p class="oys-small">' . sprintf( esc_html__( 'Add up to %d guests. Each guest takes one spot and is paid from your pass or by card, together with your booking.', 'olivia-studio' ), (int) $max ) . '</p>'
			. $rows . '<button type="button" class="btn btn--ghost btn--sm" data-guest-add>' . esc_html__( '+ Add a guest', 'olivia-studio' ) . '</button></fieldset>';
	}

	/** Small script: show/hide guest rows and update the party size and card total on each option. */
	private static function party_script() {
		return '<script>(function(){document.querySelectorAll("[data-party]").forEach(function(f){var box=f.querySelector("[data-guests]");var rows=box?[].slice.call(box.querySelectorAll("[data-guest]")):[];var add=box&&box.querySelector("[data-guest-add]");var host=parseInt(f.getAttribute("data-host"),10);'
			. 'function count(){return rows.filter(function(r){return !r.hidden&&r.querySelector("input[type=text]").value.trim()!=="";}).length;}'
			. 'function upd(){var n=count(),people=host+n;f.querySelectorAll("[data-each]").forEach(function(el){var each=parseInt(el.getAttribute("data-each"),10),fixed=parseInt(el.getAttribute("data-fixed")||"0",10),mult=el.getAttribute("data-guests-only")?n:people;el.textContent="$"+((fixed+each*mult)/100).toFixed((fixed+each*mult)%100?2:0);});'
			. 'f.querySelectorAll("[data-needs]").forEach(function(el){var need=el.getAttribute("data-guests-only")?n:people,have=parseInt(el.getAttribute("data-needs"),10),opt=el.closest(".oys-option"),inp=opt.querySelector("input");var ok=have>=need;opt.classList.toggle("is-disabled",!ok);inp.disabled=!ok;if(!ok&&inp.checked){var o=f.querySelector(".oys-option:not(.is-disabled) input");if(o)o.checked=true;}});'
			. 'var ps=f.querySelector("[data-party-size]");if(ps)ps.textContent=people;if(add)add.hidden=rows.every(function(r){return !r.hidden;});}'
			. 'if(add)add.addEventListener("click",function(){var r=rows.find(function(r){return r.hidden;});if(r){r.hidden=false;r.querySelector("input").focus();}upd();});'
			. 'rows.forEach(function(r){r.querySelector("[data-guest-remove]").addEventListener("click",function(){r.hidden=true;r.querySelectorAll("input").forEach(function(i){i.value="";});upd();});r.querySelectorAll("input").forEach(function(i){i.addEventListener("input",upd);});});upd();});})();</script>';
	}

	private static function render_session_checkout( $session_id ) {
		$s       = OYS_Schedule::get( $session_id );
		$user_id = get_current_user_id();
		$here    = $s ? oys_book_url( $s->id ) : '';
		if ( $s && 'private' === $s->kind && ! $user_id ) {
			// Private sessions show the customer's address: log in first, then check it's theirs.
			return self::auth_block( $here, __( 'Log in to see and confirm your private session.', 'olivia-studio' ) );
		}
		if ( ! $s || ! OYS_Privates::may_book( $user_id, $s ) ) {
			return '<p class="oys-notice oys-notice--error">' . esc_html__( 'This class could not be found. Please pick another from the timetable.', 'olivia-studio' ) . '</p>';
		}
		$out = '<div class="oys-checkout">' . self::session_card( $s ) . '<div class="oys-checkout__main">';

		if ( ! $user_id ) {
			return $out . self::auth_block( $here, __( 'You need an account to book, so you can manage or cancel your spot, bring guests and keep track of your passes. It takes a minute.', 'olivia-studio' ) ) . '</div></div>';
		}
		$closed    = OYS_Schedule::closed_reason( $s );
		$mine      = OYS_Bookings::active_for( $user_id, $s->id );
		// Hybrid classes: in the studio or live online (?mode=online). A full studio offers online first.
		$asked     = sanitize_key( $_GET['mode'] ?? '' );
		$mode      = oys_mode_for( $s, $mine ? $mine->mode : ( $asked ?: 'studio' ) );
		if ( ! $mine && ! $asked && oys_is_hybrid( $s ) && OYS_Schedule::spots_left( $s, 'studio' ) < 1 && OYS_Schedule::spots_left( $s, 'online' ) > 0 ) {
			$mode = 'online';
		}
		$left      = OYS_Schedule::spots_left( $s, $mode );
		$held      = $mine ? array() : OYS_Bookings::unfinished_for( $user_id, $s->id );
		$left     += PHP_INT_MAX === $left ? 0 : count( $held ); // Seats held for this customer's own unfinished payment are theirs to use again.
		$kind      = OYS_Bookings::credit_kind( $s, $mode );
		$credits   = $s->credits_allowed ? OYS_Passes::available_for( $user_id, $s, $mode ) : 0;
		// Online class and no online credits yet: studio pass classes are converted (1 → online_per_credit).
		$converts  = 'online' === $kind && $credits > 0 && ! OYS_Passes::balance( $user_id, 'online' );
		$conv_note = $converts ? sprintf( __( 'Online classes cost less: one class from your pass covers %d online classes, and the rest stay on your account.', 'olivia-studio' ), OYS_Passes::online_per_credit() ) : '';
		$max_g     = 'private' === $s->kind ? 0 : (int) OYS_Settings::get( 'max_guests' );
		$ready     = OYS_Settings::payments_ready();
		$price     = OYS_Schedule::price_for( $s, $mode );

		// Already booked: confirmation, guests, and "bring more guests".
		if ( $mine && 'pending' !== $mine->status ) {
			$guests = OYS_Bookings::guests_of( $mine->id, array( 'confirmed' ) );
			$out   .= '<div class="oys-card oys-done"><h2>' . esc_html__( 'You\'re booked', 'olivia-studio' ) . '</h2><p>' . ( 'online' === $mode ? esc_html__( 'You\'re joining live online. The link is here and in your account; it\'s also in your reminder email.', 'olivia-studio' ) : esc_html__( 'See you on the mat. You\'ll find this booking in your account.', 'olivia-studio' ) ) . '</p>';
			$join   = 'online' === $mode ? OYS_Bookings::join_link( $mine, $s ) : '';
			if ( $join ) {
				$out .= '<p><a class="btn btn--orchid oys-join" href="' . esc_url( $join ) . '" target="_blank" rel="noopener">' . esc_html__( 'Join the live class', 'olivia-studio' ) . '</a></p>';
			}
			if ( $guests ) {
				$out .= '<p><b>' . esc_html( sprintf( _n( 'Your guest: %s', 'Your guests: %s', count( $guests ), 'olivia-studio' ), implode( ', ', wp_list_pluck( $guests, 'guest_name' ) ) ) ) . '</b></p>';
			}
			$out .= '<p class="btn-row"><a class="btn btn--primary" href="' . esc_url( oys_account_url() ) . '">' . esc_html__( 'My bookings', 'olivia-studio' ) . '</a>' . self::ics_link( $mine->id ) . '</p></div>';
			$room = min( $left, $max_g - count( $guests ) );
			if ( ! $closed && $room > 0 ) {
				$opts = array();
				if ( $credits > 0 ) {
					$opts[] = '<label class="oys-option"><input type="radio" name="method" value="credit" checked><span class="oys-option__label">' . esc_html__( 'From my pass', 'olivia-studio' ) . ' <small>' . sprintf( esc_html__( '(%d left)', 'olivia-studio' ), $credits ) . '</small></span><span class="oys-option__price" data-needs="' . (int) $credits . '" data-guests-only="1">' . esc_html__( '1 class each', 'olivia-studio' ) . '</span></label>';
				}
				if ( $price > 0 && $ready ) {
					$opts[] = '<label class="oys-option"><input type="radio" name="method" value="card"' . ( $credits > 0 ? '' : ' checked' ) . '><span class="oys-option__label">' . esc_html__( 'Pay by card', 'olivia-studio' ) . '</span><span class="oys-option__price" data-each="' . $price . '" data-guests-only="1">' . esc_html( oys_money( $price ) ) . '</span></label>';
				}
				if ( ! $price ) {
					$opts[] = '<label class="oys-option"><input type="radio" name="method" value="free" checked><span class="oys-option__label">' . esc_html__( 'Reserve spots', 'olivia-studio' ) . '</span><span class="oys-option__price">' . esc_html__( 'Free', 'olivia-studio' ) . '</span></label>';
				}
				if ( $opts ) {
					$out .= self::form_open( 'oys_checkout', 'class="oys-card oys-pay" data-party data-host="0"' ) . '<h2>' . esc_html__( 'Bring guests', 'olivia-studio' ) . '</h2>'
						. '<input type="hidden" name="session" value="' . (int) $s->id . '"><input type="hidden" name="add_guests" value="' . (int) $mine->id . '">'
						. self::guest_fields( $room, 1 )
						. '<fieldset class="oys-options"><legend class="sr-only">' . esc_html__( 'Payment option', 'olivia-studio' ) . '</legend>' . implode( '', $opts ) . '</fieldset>'
						. '<button class="btn btn--primary oys-submit" type="submit">' . esc_html__( 'Add guests', 'olivia-studio' ) . '</button></form>' . self::party_script();
				}
			}
			return $out . '</div></div>';
		}
		if ( $closed ) {
			return $out . '<p class="oys-notice oys-notice--error">' . esc_html( $closed ) . '</p></div></div>';
		}

		// A card payment was started but not finished: offer to continue it, or book again below.
		if ( $held ) {
			$order = OYS_Orders::get( (int) $held[0]->order_id );
			$url   = $order && 'pending' === $order->status ? (string) ( $order->meta['checkout_url'] ?? '' ) : '';
			$out  .= '<div class="oys-card oys-unfinished"><h2>' . esc_html__( 'Your payment wasn\'t finished', 'olivia-studio' ) . '</h2>'
				. '<p>' . esc_html__( 'You started paying for this class but didn\'t complete it, so you\'re not booked yet and nothing was charged.', 'olivia-studio' ) . '</p>'
				. ( $url ? '<p class="btn-row"><a class="btn btn--primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Continue to payment', 'olivia-studio' ) . '</a></p>' : '' )
				. '<p class="oys-small">' . esc_html__( 'Or choose again below: the unfinished payment is cancelled automatically.', 'olivia-studio' ) . '</p></div>';
		}

		// Hybrid: choose between the studio and joining live online.
		if ( oys_is_hybrid( $s ) ) {
			$out .= '<div class="oys-modes" role="group" aria-label="' . esc_attr__( 'How would you like to join?', 'olivia-studio' ) . '">';
			foreach ( array( 'studio' => __( 'In the studio', 'olivia-studio' ), 'online' => __( 'Live online', 'olivia-studio' ) ) as $m => $label ) {
				$free  = OYS_Schedule::spots_left( $s, $m );
				$state = $free < 1 ? __( 'Full', 'olivia-studio' ) : ( PHP_INT_MAX === $free ? __( 'Open', 'olivia-studio' ) : sprintf( _n( '%d spot left', '%d spots left', $free, 'olivia-studio' ), $free ) );
				$cost  = OYS_Schedule::price_for( $s, $m );
				$out  .= '<a class="oys-mode' . ( $m === $mode ? ' is-active' : '' ) . '" href="' . esc_url( add_query_arg( 'mode', $m, $here ) ) . '"' . ( $m === $mode ? ' aria-current="true"' : '' ) . ' data-mode="' . esc_attr( $m ) . '"><b>' . esc_html( $label ) . '</b><span>' . esc_html( ( $cost ? oys_money( $cost ) : __( 'Free', 'olivia-studio' ) ) . ' · ' . $state ) . '</span></a>';
			}
			$out .= '</div>';
		}

		if ( $left < 1 && oys_is_hybrid( $s ) && 'online' === $mode ) {
			return $out . '<div class="oys-card"><h2>' . esc_html__( 'Online spots are taken', 'olivia-studio' ) . '</h2><p>' . esc_html__( 'All online spots for this class are booked.', 'olivia-studio' ) . '</p></div></div></div>';
		}
		if ( $left < 1 ) {
			$pos = OYS_Bookings::waitlist_position( $user_id, $s->id );
			$out .= '<div class="oys-card"><h2>' . esc_html( oys_is_hybrid( $s ) ? __( 'The studio is full', 'olivia-studio' ) : __( 'This class is full', 'olivia-studio' ) ) . '</h2>';
			if ( oys_is_hybrid( $s ) && OYS_Schedule::spots_left( $s, 'online' ) > 0 ) {
				$out .= '<p><a class="btn btn--orchid" href="' . esc_url( add_query_arg( 'mode', 'online', $here ) ) . '">' . esc_html__( 'Join live online instead', 'olivia-studio' ) . '</a></p>';
			}
			if ( $pos ) {
				$out .= '<p>' . sprintf( esc_html__( 'You\'re number %d on the waitlist. If a spot opens and you have a pass or membership, you\'re booked in automatically and we email you. Otherwise we email you so you can book first.', 'olivia-studio' ), $pos ) . '</p>'
					. self::form_open( 'oys_waitlist' ) . '<input type="hidden" name="session" value="' . (int) $s->id . '"><input type="hidden" name="do" value="leave"><button class="btn btn--ghost">' . esc_html__( 'Leave the waitlist', 'olivia-studio' ) . '</button></form>';
			} else {
				$out .= '<p>' . esc_html__( 'Join the waitlist. If a spot opens and you have a pass or membership, you\'re booked in automatically. Otherwise we email you straight away.', 'olivia-studio' ) . '</p>'
					. self::form_open( 'oys_waitlist' ) . '<input type="hidden" name="session" value="' . (int) $s->id . '"><input type="hidden" name="do" value="join"><button class="btn btn--primary">' . esc_html__( 'Join the waitlist', 'olivia-studio' ) . '</button></form>';
			}
			return $out . '</div></div></div>';
		}

		// Payment options (prices update with the number of guests).
		$member  = OYS_Memberships::current_for( $user_id );
		$covered = $member && true === OYS_Memberships::covers( $member, $s, $mode );
		$options = array();
		if ( $covered ) {
			$note = (int) $member->classes_per_period ? sprintf( __( '%d left this period', 'olivia-studio' ), OYS_Memberships::remaining( $member ) ) : __( 'unlimited', 'olivia-studio' );
			$options[] = array( 'membership', sprintf( __( 'Use my membership (%s)', 'olivia-studio' ), $note ), '<span class="oys-option__price">' . esc_html__( 'Included', 'olivia-studio' ) . '</span>', __( 'Guests are paid from your pass if you have enough classes, otherwise by card.', 'olivia-studio' ) );
		}
		if ( $credits > 0 ) {
			$label     = 'online' === $kind
				? sprintf( _n( 'Use my pass (%d online class)', 'Use my pass (%d online classes)', $credits, 'olivia-studio' ), $credits )
				: sprintf( _n( 'Use my pass (%d class left)', 'Use my pass (%d classes left)', $credits, 'olivia-studio' ), $credits );
			$options[] = array( 'credit', $label, '<span class="oys-option__price" data-needs="' . (int) $credits . '">' . esc_html( 'online' === $kind ? __( '1 online class each', 'olivia-studio' ) : __( '1 class each', 'olivia-studio' ) ) . '</span>', $conv_note );
		}
		if ( $price > 0 ) {
			$options[] = array( 'card', 'private' === $s->kind ? __( 'Pay for this session', 'olivia-studio' ) : ( 'online' === $mode ? __( 'Pay by card (online ticket)', 'olivia-studio' ) : __( 'Pay by card (drop-in)', 'olivia-studio' ) ), '<span class="oys-option__price" data-each="' . $price . '">' . esc_html( oys_money( $price ) ) . '</span>', '' );
		} elseif ( ! $credits || ! $s->credits_allowed ) {
			$options[] = array( 'free', __( 'Reserve my spot', 'olivia-studio' ), '<span class="oys-option__price">' . esc_html__( 'Free', 'olivia-studio' ) . '</span>', '' );
		}
		if ( 'private' !== $s->kind && $s->credits_allowed && $ready ) {
			foreach ( OYS_Products::purchasable() as $p ) {
				if ( $kind !== OYS_Products::credit_kind( $p ) || ( 'intro' === $p['kind'] && ! OYS_Orders::is_new_customer( $user_id ) ) ) {
					continue;
				}
				$options[] = array( 'pack:' . $p['id'], sprintf( __( 'Buy %s and use it for this booking', 'olivia-studio' ), $p['name'] ), '<span class="oys-option__price" data-needs="' . (int) $p['credits'] . '">' . esc_html( oys_money( $p['price_cents'] ) ) . '</span>', '' );
			}
		}

		$out .= self::form_open( 'oys_checkout', 'class="oys-card oys-pay" data-party data-host="1"' ) . '<h2>' . esc_html__( 'How would you like to book?', 'olivia-studio' ) . '</h2>'
			. '<input type="hidden" name="session" value="' . (int) $s->id . '"><input type="hidden" name="mode" value="' . esc_attr( $mode ) . '">'
			. self::guest_fields( min( $max_g, $left - 1 ) )
			. '<p class="oys-party">' . esc_html__( 'People in this booking:', 'olivia-studio' ) . ' <b data-party-size>1</b></p>'
			. '<fieldset class="oys-options"><legend class="sr-only">' . esc_html__( 'Payment option', 'olivia-studio' ) . '</legend>';
		foreach ( $options as $i => $o ) {
			$needs_card = 'card' === $o[0] || str_starts_with( $o[0], 'pack:' );
			$disabled   = $needs_card && ! $ready;
			$out       .= '<label class="oys-option' . ( $disabled ? ' is-disabled' : '' ) . '"><input type="radio" name="method" value="' . esc_attr( $o[0] ) . '"' . checked( 0, $i, false ) . disabled( $disabled, true, false ) . '><span class="oys-option__label">' . esc_html( $o[1] ) . ( $o[3] ? '<small>' . esc_html( $o[3] ) . '</small>' : '' ) . '</span>' . $o[2] . '</label>';
		}
		$out .= '</fieldset>';
		if ( ! $ready && ! $credits && ! $covered ) {
			$out .= '<p class="oys-notice">' . esc_html__( 'Online payment is being set up. To book now, send a message and I\'ll hold your spot.', 'olivia-studio' ) . '</p>';
		}
		$out .= self::waiver_field()
			. '<p class="oys-policy">' . esc_html( OYS_Settings::get( 'cancel_policy' ) ) . '</p>'
			. '<button class="btn btn--primary oys-submit" type="submit">' . esc_html__( 'Continue', 'olivia-studio' ) . '</button>'
			. '<p class="oys-secure">' . esc_html__( 'Card payments are processed securely by Stripe. Apple Pay and Google Pay are accepted.', 'olivia-studio' ) . '</p></form>' . self::party_script();
		return $out . '</div></div>';
	}


	private static function render_product_checkout( $product_id ) {
		$p = OYS_Products::get( $product_id );
		if ( ! $p || empty( $p['active'] ) || ! in_array( $p['kind'], array( 'pack', 'intro', 'online_pack', 'private_pack', 'membership' ), true ) ) {
			return '<p class="oys-notice oys-notice--error">' . esc_html__( 'This pass is not available.', 'olivia-studio' ) . '</p>';
		}
		$is_member = 'membership' === $p['kind'];
		$here      = oys_page_url( 'book', array( 'product' => $p['id'] ) );
		$feat      = array_filter( array_map( 'trim', explode( "\n", $p['features'] ) ) );
		$card      = '<div class="oys-card oys-summary"><p class="kicker">' . esc_html( $is_member ? __( 'Membership', 'olivia-studio' ) : __( 'Pass', 'olivia-studio' ) ) . '</p><h2>' . esc_html( $p['name'] ) . '</h2>'
			. '<p class="oys-bigprice">' . esc_html( oys_money( $p['price_cents'] ) ) . ( $is_member ? '<small> ' . esc_html( OYS_Products::period_label( $p ) ) . '</small>' : '' ) . '</p>'
			. ( $p['description'] ? '<p>' . esc_html( $p['description'] ) . '</p>' : '' )
			. '<ul class="oys-facts">' . implode( '', array_map( fn( $f ) => '<li>' . oys_icon( 'check' ) . '<span>' . esc_html( $f ) . '</span></li>', $feat ) ) . '</ul></div>';
		$out = '<div class="oys-checkout">' . $card . '<div class="oys-checkout__main">';
		if ( ! is_user_logged_in() ) {
			return $out . self::auth_block( $here, $is_member ? __( 'Your membership lives in your account, where you book classes, see renewals and manage billing.', 'olivia-studio' ) : __( 'Your pass lives in your account, where you can see how many classes are left and book with one click.', 'olivia-studio' ) ) . '</div></div>';
		}
		if ( 'intro' === $p['kind'] && ! OYS_Orders::is_new_customer( get_current_user_id() ) ) {
			return $out . '<p class="oys-notice oys-notice--error">' . esc_html__( 'The intro offer is for first-time students. Have a look at the class passes instead.', 'olivia-studio' ) . '</p></div></div>';
		}
		if ( $is_member && OYS_Memberships::current_for( get_current_user_id() ) ) {
			return $out . '<p class="oys-notice">' . esc_html__( 'You already have an active membership.', 'olivia-studio' ) . ' <a class="text-link" href="' . esc_url( oys_account_url( 'membership' ) ) . '">' . esc_html__( 'Manage it in your account', 'olivia-studio' ) . '</a></p></div></div>';
		}
		if ( ! OYS_Settings::payments_ready() ) {
			return $out . '<p class="oys-notice">' . esc_html__( 'Online payment is being set up. Please send a message to buy a pass.', 'olivia-studio' ) . '</p></div></div>';
		}
		if ( $is_member ) {
			$out .= self::form_open( 'oys_join', 'class="oys-card oys-pay"' ) . '<h2>' . esc_html__( 'Join', 'olivia-studio' ) . '</h2><input type="hidden" name="product" value="' . esc_attr( $p['id'] ) . '">'
				. '<p>' . sprintf( esc_html__( 'You pay %1$s today, then %1$s %2$s until you cancel. Cancel any time in your account; you keep access until the end of the paid period.', 'olivia-studio' ), esc_html( oys_money( $p['price_cents'] ) ), esc_html( OYS_Products::period_label( $p ) ) ) . '</p>'
				. self::waiver_field()
				. '<button class="btn btn--primary oys-submit" type="submit">' . sprintf( esc_html__( 'Join for %s', 'olivia-studio' ), esc_html( oys_money( $p['price_cents'] ) ) ) . '</button>'
				. '<p class="oys-secure">' . esc_html__( 'Recurring payments are processed securely by Stripe. Update your card or cancel from your account.', 'olivia-studio' ) . '</p></form>';
			return $out . '</div></div>';
		}
		$out .= self::form_open( 'oys_buy', 'class="oys-card oys-pay"' ) . '<h2>' . esc_html__( 'Buy this pass', 'olivia-studio' ) . '</h2><input type="hidden" name="product" value="' . esc_attr( $p['id'] ) . '">'
			. '<p>' . esc_html__( 'After paying, book any class from the timetable in one click. You can use your pass for guests you bring, too.', 'olivia-studio' ) . '</p>'
			. self::waiver_field()
			. '<button class="btn btn--primary oys-submit" type="submit">' . sprintf( esc_html__( 'Pay %s', 'olivia-studio' ), esc_html( oys_money( $p['price_cents'] ) ) ) . '</button>'
			. '<p class="oys-secure">' . esc_html__( 'Card payments are processed securely by Stripe. Apple Pay and Google Pay are accepted.', 'olivia-studio' ) . '</p></form>';
		return $out . '</div></div>';
	}


	private static function render_return() {
		$order_id = (int) $_GET['oys_order'];
		if ( ! hash_equals( OYS_Stripe::order_key( $order_id ), (string) ( $_GET['oys_key'] ?? '' ) ) ) {
			return '<p class="oys-notice oys-notice--error">' . esc_html__( 'This link is not valid.', 'olivia-studio' ) . '</p>';
		}
		$order = OYS_Orders::get( $order_id );
		if ( ! $order ) {
			return '';
		}
		if ( 'cancel' === ( $_GET['oys_return'] ?? '' ) && 'paid' !== $order->status ) {
			$back = $order->session_id ? oys_book_url( $order->session_id ) : ( $order->product_id ? oys_page_url( 'book', array( 'product' => $order->product_id ) ) : oys_page_url( 'book' ) );
			return '<div class="oys-card oys-done"><h2>' . esc_html__( 'Payment cancelled', 'olivia-studio' ) . '</h2><p>' . esc_html__( 'Nothing was charged and your spot was released.', 'olivia-studio' ) . '</p><p class="btn-row"><a class="btn btn--primary" href="' . esc_url( $back ) . '">' . esc_html__( 'Try again', 'olivia-studio' ) . '</a></p></div>';
		}
		if ( 'paid' !== $order->status ) {
			return '<div class="oys-card oys-done"><h2>' . esc_html__( 'Payment processing', 'olivia-studio' ) . '</h2><p>' . esc_html__( 'Your payment is being confirmed. You\'ll get an email as soon as it goes through, and it will show in your account.', 'olivia-studio' ) . '</p><p class="btn-row"><a class="btn btn--primary" href="' . esc_url( oys_account_url() ) . '">' . esc_html__( 'My account', 'olivia-studio' ) . '</a></p></div>';
		}
		$html = '<div class="oys-card oys-done oys-done--paid">';
		$rows = OYS_Bookings::for_order( $order->id, array( 'confirmed' ) );
		if ( $rows ) {
			$s      = OYS_Schedule::get( $rows[0]->session_id );
			$host   = $rows[0]->guest_of ? (int) $rows[0]->guest_of : (int) $rows[0]->id;
			$guests = array_filter( $rows, fn( $r ) => $r->guest_of );
			$html  .= '<h2>' . esc_html( count( $guests ) === count( $rows ) ? _n( 'Guest added!', 'Guests added!', count( $guests ), 'olivia-studio' ) : __( 'You\'re booked!', 'olivia-studio' ) ) . '</h2><p>' . sprintf( esc_html__( '%1$s on %2$s. A confirmation with a calendar invite is on its way to your inbox.', 'olivia-studio' ), '<b>' . esc_html( oys_session_title( $s ) ) . '</b>', esc_html( oys_date( $s->starts_at, 'l, F j \a\t g:i a' ) ) ) . '</p>';
			if ( $guests ) {
				$html .= '<p>' . esc_html( sprintf( _n( 'Guest: %s', 'Guests: %s', count( $guests ), 'olivia-studio' ), implode( ', ', wp_list_pluck( $guests, 'guest_name' ) ) ) ) . '</p>';
			}
			$html .= '<p class="btn-row"><a class="btn btn--primary" href="' . esc_url( oys_account_url() ) . '">' . esc_html__( 'My bookings', 'olivia-studio' ) . '</a>' . self::ics_link( $host ) . '</p>';
		} elseif ( 'gift' === $order->type ) {
			$html .= '<h2>' . esc_html__( 'Gift sent!', 'olivia-studio' ) . '</h2><p>' . sprintf( esc_html__( 'We emailed the gift card to %s and sent you a receipt.', 'olivia-studio' ), esc_html( $order->meta['recipient_email'] ?? '' ) ) . '</p>';
		} elseif ( 'membership' === $order->type ) {
			$html .= '<h2>' . esc_html__( 'Welcome, member!', 'olivia-studio' ) . '</h2><p>' . esc_html__( 'Your membership is active. Choose "Use my membership" when you book a class.', 'olivia-studio' ) . '</p><p class="btn-row"><a class="btn btn--primary" href="' . esc_url( oys_page_url( 'book' ) ) . '">' . esc_html__( 'Book a class', 'olivia-studio' ) . '</a><a class="btn btn--ghost" href="' . esc_url( oys_account_url( 'membership' ) ) . '">' . esc_html__( 'My membership', 'olivia-studio' ) . '</a></p>';
		} else {
			$html .= '<h2>' . esc_html__( 'Your pass is ready', 'olivia-studio' ) . '</h2><p>' . esc_html__( 'Book any class from the timetable in one click.', 'olivia-studio' ) . '</p><p class="btn-row"><a class="btn btn--primary" href="' . esc_url( oys_page_url( 'book' ) ) . '">' . esc_html__( 'Book a class', 'olivia-studio' ) . '</a></p>';
		}
		if ( $order->receipt_url ) {
			$html .= '<p><a class="text-link" href="' . esc_url( $order->receipt_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View receipt', 'olivia-studio' ) . '</a></p>';
		}
		return $html . '</div>';
	}

	private static function ics_link( $booking_id ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=oys_ics&booking=' . (int) $booking_id ), 'oys_ics_' . (int) $booking_id );
		return '<a class="btn btn--ghost" href="' . esc_url( $url ) . '">' . esc_html__( 'Add to calendar', 'olivia-studio' ) . '</a>';
	}

	/* ---------- Handlers ---------- */

	public static function handle_checkout_guest() {
		oys_redirect( wp_get_referer() ?: oys_page_url( 'book' ) );
	}

	private static function accept_waiver_or_back( $back ) {
		$user_id = get_current_user_id();
		if ( OYS_Customers::has_waiver( $user_id ) ) {
			return;
		}
		if ( empty( $_POST['waiver'] ) ) {
			oys_flash( __( 'Please accept the participation agreement to continue.', 'olivia-studio' ), 'error' );
			oys_redirect( $back );
		}
		OYS_Customers::record_waiver( $user_id );
	}


	public static function handle_checkout() {
		check_admin_referer( 'oys_checkout' );
		$user_id = get_current_user_id();
		$s       = OYS_Schedule::get( (int) ( $_POST['session'] ?? 0 ) );
		$back    = $s ? oys_book_url( $s->id ) : oys_page_url( 'book' );
		if ( ! $s || ! OYS_Privates::may_book( $user_id, $s ) ) {
			oys_flash( __( 'This class could not be found.', 'olivia-studio' ), 'error' );
			oys_redirect( oys_page_url( 'book' ) );
		}
		// Booking again replaces a card payment for this class that was started but not finished.
		if ( 'paid' === OYS_Bookings::abandon_unfinished( $user_id, $s->id ) ) {
			oys_flash( __( 'Your earlier payment for this class went through, so you\'re already booked.', 'olivia-studio' ) );
			oys_redirect( $back );
		}
		$method = sanitize_text_field( wp_unslash( $_POST['method'] ?? '' ) );
		$guests = 'private' === $s->kind ? array() : OYS_Bookings::clean_guests( $_POST['guest_name'] ?? array(), $_POST['guest_email'] ?? array() );
		$n      = count( $guests );
		// Hybrid classes: in the studio or live online (guests join the way their host does).
		$host_id = (int) ( $_POST['add_guests'] ?? 0 );
		$host    = $host_id ? OYS_Bookings::get( $host_id ) : null;
		$mode    = oys_mode_for( $s, $host ? $host->mode : sanitize_key( $_POST['mode'] ?? 'studio' ) );
		if ( oys_is_hybrid( $s ) && 'online' === $mode ) {
			$back = add_query_arg( 'mode', 'online', $back );
		}
		$kind   = OYS_Bookings::credit_kind( $s, $mode );
		$price  = OYS_Schedule::price_for( $s, $mode );
		$title  = oys_session_title( $s ) . ( oys_is_hybrid( $s ) && 'online' === $mode ? ' ' . __( '(live online)', 'olivia-studio' ) : '' );
		$fail   = function ( $res ) use ( $back ) {
			oys_flash( $res->get_error_message(), 'error' );
			oys_redirect( $back );
		};
		// With a personal Zoom link for everyone, online guests need an email to receive theirs.
		if ( 'online' === $mode && $n && OYS_Zoom::personal_links() && array_filter( $guests, fn( $g ) => ! $g['email'] ) ) {
			$fail( new WP_Error( 'oys_guest_email', __( 'Add an email for each online guest, so they get their own link to join.', 'olivia-studio' ) ) );
		}

		// Adding guests to an existing booking.
		if ( $host_id ) {
			if ( ! $host || (int) $host->user_id !== $user_id || (int) $host->session_id !== (int) $s->id ) {
				oys_redirect( $back );
			}
			if ( ! $n ) {
				$fail( new WP_Error( 'oys_guests', __( 'Add at least one guest name.', 'olivia-studio' ) ) );
			}
			if ( 'card' === $method && $price > 0 ) {
				self::start_paid_booking( $user_id, $s, array(
					'type'         => 'dropin',
					'description'  => sprintf( _n( 'Guest ticket: %1$s, %2$s', 'Guest tickets (%3$d): %1$s, %2$s', $n, 'olivia-studio' ), $title, oys_date( $s->starts_at, 'M j, g:i a' ), $n ),
					'amount_cents' => $price * $n,
					'meta'         => array( 'guests' => wp_list_pluck( $guests, 'name' ) ),
				), array( array( sprintf( __( 'Guest ticket: %s', 'olivia-studio' ), $title ), implode( ', ', wp_list_pluck( $guests, 'name' ) ) . ' · ' . oys_date( $s->starts_at, 'l, F j · g:i a' ), $price, $n ) ), $back, $guests, false, $host_id, $mode );
			}
			$res = OYS_Bookings::book_party( $user_id, $s, array( 'method' => $method, 'guest_method' => 'free' === $method && ! $price ? 'free' : 'credit', 'guests' => $guests, 'host_booking' => $host_id, 'mode' => $mode ) );
			if ( is_wp_error( $res ) ) {
				$fail( $res );
			}
			oys_flash( sprintf( _n( '%d guest added.', '%d guests added.', $n, 'olivia-studio' ), $n ) );
			oys_redirect( $back );
		}

		self::accept_waiver_or_back( $back );

		if ( 'credit' === $method || ( 'free' === $method && ! $price ) ) {
			$res = OYS_Bookings::book_party( $user_id, $s, array( 'method' => $method, 'guests' => $guests, 'mode' => $mode ) );
			if ( is_wp_error( $res ) ) {
				$fail( $res );
			}
			oys_redirect( $back );
		}

		if ( 'membership' === $method ) {
			// The member is covered; guests use pass credits if there are enough, otherwise they are paid by card.
			$guest_credit = $n && OYS_Passes::available_for( $user_id, $s, $mode ) >= $n;
			$res          = OYS_Bookings::book_party( $user_id, $s, array( 'method' => 'membership', 'guests' => $guest_credit ? $guests : array(), 'guest_method' => 'credit', 'mode' => $mode ) );
			if ( is_wp_error( $res ) ) {
				$fail( $res );
			}
			if ( $n && ! $guest_credit ) {
				if ( $price < 1 ) {
					OYS_Bookings::book_party( $user_id, $s, array( 'method' => 'free', 'guests' => $guests, 'host_booking' => $res, 'mode' => $mode ) );
					oys_redirect( $back );
				}
				self::start_paid_booking( $user_id, $s, array(
					'type'         => 'dropin',
					'description'  => sprintf( _n( 'Guest ticket: %1$s, %2$s', 'Guest tickets (%3$d): %1$s, %2$s', $n, 'olivia-studio' ), $title, oys_date( $s->starts_at, 'M j, g:i a' ), $n ),
					'amount_cents' => $price * $n,
					'meta'         => array( 'guests' => wp_list_pluck( $guests, 'name' ) ),
				), array( array( sprintf( __( 'Guest ticket: %s', 'olivia-studio' ), $title ), implode( ', ', wp_list_pluck( $guests, 'name' ) ) . ' · ' . oys_date( $s->starts_at, 'l, F j · g:i a' ), $price, $n ) ), $back, $guests, false, $res, $mode );
			}
			oys_redirect( $back );
		}

		if ( 'card' === $method ) {
			if ( $price < 1 ) {
				oys_redirect( $back );
			}
			$type  = 'private' === $s->kind ? 'private' : 'dropin';
			$when  = oys_date( $s->starts_at, 'l, F j · g:i a' );
			$meta  = $n ? array( 'guests' => wp_list_pluck( $guests, 'name' ) ) : array();
			if ( 'private' === $s->kind ) {
				$r                  = OYS_Privates::for_session( $s->id );
				$meta['request_id'] = $r ? (int) $r->id : 0;
			}
			$lines = array( array( $title, $when, $price, 1 ) );
			if ( $n ) {
				$lines[] = array( sprintf( __( 'Guest ticket: %s', 'olivia-studio' ), $title ), implode( ', ', wp_list_pluck( $guests, 'name' ) ) . ' · ' . $when, $price, $n );
			}
			self::start_paid_booking( $user_id, $s, array(
				'type'         => $type,
				'description'  => $title . ', ' . oys_date( $s->starts_at, 'M j, g:i a' ) . ( $n ? ' ' . sprintf( _n( '(+%d guest)', '(+%d guests)', $n, 'olivia-studio' ), $n ) : '' ),
				'amount_cents' => $price * ( 1 + $n ),
				'meta'         => $meta,
			), $lines, $back, $guests, true, 0, $mode );
		}

		if ( str_starts_with( $method, 'pack:' ) ) {
			$p = OYS_Products::get( substr( $method, 5 ) );
			if ( ! $p || empty( $p['active'] ) || $kind !== OYS_Products::credit_kind( $p ) || ! in_array( $p['kind'], array( 'pack', 'intro', 'online_pack' ), true ) ) {
				oys_redirect( $back );
			}
			if ( 'intro' === $p['kind'] && ! OYS_Orders::is_new_customer( $user_id ) ) {
				$fail( new WP_Error( 'oys_intro', __( 'The intro offer is for first-time students.', 'olivia-studio' ) ) );
			}
			if ( (int) $p['credits'] < 1 + $n ) {
				$fail( new WP_Error( 'oys_pack', sprintf( __( '%1$s has %2$d classes, which isn\'t enough for %3$d people. Choose a bigger pass or pay by card.', 'olivia-studio' ), $p['name'], (int) $p['credits'], 1 + $n ) ) );
			}
			$desc = sprintf( __( 'Includes your spot in %s', 'olivia-studio' ), $title . ', ' . oys_date( $s->starts_at, 'M j, g:i a' ) ) . ( $n ? ' ' . sprintf( _n( 'and %d guest', 'and %d guests', $n, 'olivia-studio' ), $n ) : '' );
			self::start_paid_booking( $user_id, $s, array(
				'type'         => 'pack',
				'product_id'   => $p['id'],
				'description'  => $p['name'] . ' + ' . $title . ', ' . oys_date( $s->starts_at, 'M j' ) . ( $n ? ' ' . sprintf( _n( '(+%d guest)', '(+%d guests)', $n, 'olivia-studio' ), $n ) : '' ),
				'amount_cents' => (int) $p['price_cents'],
				'meta'         => $n ? array( 'guests' => wp_list_pluck( $guests, 'name' ) ) : array(),
			), array( array( $p['name'], $desc, (int) $p['price_cents'], 1 ) ), $back, $guests, true, 0, $mode );
		}
		oys_redirect( $back );
	}


	/** Hold the seats (customer and/or guests), create the order, send the customer to Stripe. */
	private static function start_paid_booking( $user_id, $s, array $order, array $lines, $back, array $guests = array(), $include_host = true, $host_booking = 0, $mode = 'studio' ) {
		$order_id = OYS_Orders::create( array_merge( array( 'user_id' => $user_id, 'session_id' => $s->id ), $order ) );
		$first    = OYS_Bookings::hold( $user_id, $s, $order_id, $guests, $include_host, $host_booking, $mode );
		if ( is_wp_error( $first ) ) {
			OYS_Orders::update( $order_id, array( 'status' => 'failed' ) );
			oys_flash( $first->get_error_message(), 'error' );
			oys_redirect( $back );
		}
		OYS_Orders::update( $order_id, array( 'booking_id' => $include_host ? $first : (int) $host_booking ) );
		$url = OYS_Stripe::start_checkout( $order_id, $lines[0][0], $lines[0][1], $lines );
		if ( is_wp_error( $url ) ) {
			OYS_Orders::mark_unpaid( $order_id, 'failed' );
			oys_flash( $url->get_error_message(), 'error' );
			oys_redirect( $back );
		}
		wp_redirect( $url );
		exit;
	}

	public static function handle_buy() {
		check_admin_referer( 'oys_buy' );
		$user_id = get_current_user_id();
		$p       = OYS_Products::get( sanitize_key( $_POST['product'] ?? '' ) );
		$back    = $p ? oys_page_url( 'book', array( 'product' => $p['id'] ) ) : oys_page_url( 'book' );
		if ( ! $p || empty( $p['active'] ) || ! in_array( $p['kind'], array( 'pack', 'intro', 'online_pack', 'private_pack' ), true ) ) {
			oys_redirect( $back );
		}
		if ( 'intro' === $p['kind'] && ! OYS_Orders::is_new_customer( $user_id ) ) {
			oys_flash( __( 'The intro offer is for first-time students.', 'olivia-studio' ), 'error' );
			oys_redirect( $back );
		}
		self::accept_waiver_or_back( $back );
		$order_id = OYS_Orders::create( array(
			'user_id'      => $user_id,
			'type'         => 'pack',
			'product_id'   => $p['id'],
			'description'  => $p['name'],
			'amount_cents' => (int) $p['price_cents'],
		) );
		$url = OYS_Stripe::start_checkout( $order_id, $p['name'], $p['description'] );
		if ( is_wp_error( $url ) ) {
			OYS_Orders::update( $order_id, array( 'status' => 'failed' ) );
			oys_flash( $url->get_error_message(), 'error' );
			oys_redirect( $back );
		}
		wp_redirect( $url );
		exit;
	}

	public static function handle_cancel() {
		check_admin_referer( 'oys_cancel' );
		$b = OYS_Bookings::get( (int) ( $_POST['booking'] ?? 0 ) );
		if ( ! $b || (int) $b->user_id !== get_current_user_id() ) {
			oys_redirect( oys_account_url() );
		}
		$res = OYS_Bookings::cancel( $b->id );
		if ( is_wp_error( $res ) ) {
			oys_flash( $res->get_error_message(), 'error' );
		} else {
			$msg = array(
				'returned' => __( 'Cancelled. The class is back on your pass.', 'olivia-studio' ),
				'credit'   => __( 'Cancelled. You have a class credit to use for another class.', 'olivia-studio' ),
				'late'       => __( 'Cancelled. Because it was inside the cancellation window, the class counts as used.', 'olivia-studio' ),
				'membership' => __( 'Cancelled. It won\'t count towards your membership.', 'olivia-studio' ),
				'none'     => __( 'Cancelled.', 'olivia-studio' ),
			);
			oys_flash( $msg[ $res ] ?? $msg['none'] );
		}
		oys_redirect( oys_account_url() );
	}

	public static function handle_waitlist() {
		check_admin_referer( 'oys_waitlist' );
		$s = OYS_Schedule::get( (int) ( $_POST['session'] ?? 0 ) );
		if ( $s && get_current_user_id() ) {
			if ( 'leave' === ( $_POST['do'] ?? '' ) ) {
				OYS_Bookings::leave_waitlist( get_current_user_id(), $s->id );
				oys_flash( __( 'You left the waitlist.', 'olivia-studio' ) );
			} else {
				OYS_Bookings::join_waitlist( get_current_user_id(), $s->id );
				oys_flash( __( 'You\'re on the waitlist. We\'ll email you if a spot opens.', 'olivia-studio' ) );
			}
		}
		oys_redirect( wp_get_referer() ?: oys_account_url() );
	}

	public static function handle_ics() {
		$id = (int) ( $_GET['booking'] ?? 0 );
		check_admin_referer( 'oys_ics_' . $id );
		$b = OYS_Bookings::get( $id );
		if ( ! $b || ( (int) $b->user_id !== get_current_user_id() && ! current_user_can( 'oys_manage' ) ) ) {
			wp_die( esc_html__( 'Not found.', 'olivia-studio' ), 404 );
		}
		$s = OYS_Schedule::get( $b->session_id );
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="yoga-' . $id . '.ics"' );
		echo OYS_Emails::ics( $s, $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- calendar file.
		exit;
	}

	/* ======================================================================
	   Account
	   ====================================================================== */

	public static function auth_forms( $redirect ) {
		$view  = sanitize_key( $_GET['oys_view'] ?? 'register' );
		$email = sanitize_email( wp_unslash( $_GET['oys_email'] ?? '' ) );
		$first = sanitize_text_field( wp_unslash( $_GET['oys_first'] ?? '' ) );
		$login = self::form_open( 'oys_login', 'class="oys-form" id="oys-login"' )
			. '<input type="hidden" name="redirect_to" value="' . esc_attr( $redirect ) . '">'
			. '<label class="field"><span>' . esc_html__( 'Email', 'olivia-studio' ) . '</span><input type="email" name="email" id="oys-login-email" required autocomplete="email" value="' . esc_attr( $email ) . '"></label>'
			. '<label class="field"><span>' . esc_html__( 'Password', 'olivia-studio' ) . '</span><input type="password" name="password" id="oys-login-password" required autocomplete="current-password"></label>'
			. '<button class="btn btn--primary" type="submit">' . esc_html__( 'Log in', 'olivia-studio' ) . '</button>'
			. '<p class="oys-small"><a href="' . esc_url( wp_lostpassword_url( $redirect ) ) . '">' . esc_html__( 'Forgot your password?', 'olivia-studio' ) . '</a></p></form>';
		$register = self::form_open( 'oys_register', 'class="oys-form" id="oys-register"' )
			. '<input type="hidden" name="redirect_to" value="' . esc_attr( $redirect ) . '">'
			. '<div class="oys-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
			. '<div class="form__row"><label class="field"><span>' . esc_html__( 'First name', 'olivia-studio' ) . '</span><input type="text" name="first_name" id="oys-reg-first" required autocomplete="given-name" value="' . esc_attr( $first ) . '"></label>'
			. '<label class="field"><span>' . esc_html__( 'Last name', 'olivia-studio' ) . '</span><input type="text" name="last_name" id="oys-reg-last" autocomplete="family-name"></label></div>'
			. '<div class="form__row"><label class="field"><span>' . esc_html__( 'Email', 'olivia-studio' ) . '</span><input type="email" name="email" id="oys-reg-email" required autocomplete="email" value="' . esc_attr( $email ) . '"></label>'
			. '<label class="field"><span>' . esc_html__( 'Phone (optional)', 'olivia-studio' ) . '</span><input type="tel" name="phone" id="oys-reg-phone" autocomplete="tel"></label></div>'
			. '<label class="field"><span>' . esc_html__( 'Password (8+ characters)', 'olivia-studio' ) . '</span><input type="password" name="password" id="oys-reg-password" required minlength="8" autocomplete="new-password"></label>'
			. '<details class="oys-waiver"><summary>' . esc_html__( 'Participation agreement', 'olivia-studio' ) . '</summary><p>' . esc_html( OYS_Settings::get( 'waiver_text' ) ) . '</p></details>'
			. '<label class="oys-check"><input type="checkbox" name="waiver" value="1" required> <span>' . esc_html__( 'I have read and accept the participation agreement.', 'olivia-studio' ) . '</span></label>'
			. '<label class="oys-check"><input type="checkbox" name="marketing" value="1"> <span>' . esc_html__( 'Email me about new classes and events (a few times a month at most).', 'olivia-studio' ) . '</span></label>'
			. '<button class="btn btn--primary" type="submit">' . esc_html__( 'Create account', 'olivia-studio' ) . '</button></form>';
		$is_login = 'login' === $view;
		return '<div class="oys-tabs" data-oys-tabs><div class="oys-tabs__nav" role="tablist">'
			. '<button type="button" role="tab" aria-selected="' . ( $is_login ? 'false' : 'true' ) . '" data-tab="oys-register">' . esc_html__( 'I\'m new here', 'olivia-studio' ) . '</button>'
			. '<button type="button" role="tab" aria-selected="' . ( $is_login ? 'true' : 'false' ) . '" data-tab="oys-login">' . esc_html__( 'I have an account', 'olivia-studio' ) . '</button></div>'
			. '<div class="oys-tabs__panel" data-panel="oys-register"' . ( $is_login ? ' hidden' : '' ) . '>' . $register . '</div>'
			. '<div class="oys-tabs__panel" data-panel="oys-login"' . ( $is_login ? '' : ' hidden' ) . '>' . $login . '</div></div>'
			. '<script>(function(){document.querySelectorAll("[data-oys-tabs]").forEach(function(t){t.querySelectorAll("[data-tab]").forEach(function(b){b.addEventListener("click",function(){t.querySelectorAll("[data-tab]").forEach(function(x){x.setAttribute("aria-selected",x===b?"true":"false")});t.querySelectorAll("[data-panel]").forEach(function(p){p.hidden=p.getAttribute("data-panel")!==b.getAttribute("data-tab")});});});});})();</script>';
	}


	public static function sc_account() {
		$flash = oys_render_flash();
		if ( ! is_user_logged_in() ) {
			return $flash . '<div class="oys-card oys-auth oys-auth--page"><p class="lead">' . esc_html__( 'Book and cancel classes, bring guests, see your passes, membership and receipts, and request private sessions.', 'olivia-studio' ) . '</p>' . self::auth_forms( oys_account_url() ) . '</div>';
		}
		$user_id = get_current_user_id();
		$user    = wp_get_current_user();
		$tabs    = array(
			'bookings'   => __( 'Bookings', 'olivia-studio' ),
			'passes'     => __( 'Passes', 'olivia-studio' ),
			'membership' => __( 'Membership', 'olivia-studio' ),
			'private'    => __( 'Private sessions', 'olivia-studio' ),
			'history'    => __( 'History', 'olivia-studio' ),
			'payments'   => __( 'Payments', 'olivia-studio' ),
			'profile'    => __( 'Profile', 'olivia-studio' ),
		);
		if ( ! OYS_Products::memberships() && ! OYS_Memberships::for_user( $user_id ) ) {
			unset( $tabs['membership'] );
		}
		$tab = sanitize_key( $_GET['tab'] ?? 'bookings' );
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'bookings';

		$credits = OYS_Passes::balance( $user_id, 'class' );
		$private = OYS_Passes::balance( $user_id, 'private' );
		$online  = OYS_Passes::balance( $user_id, 'online' );
		$member  = OYS_Memberships::current_for( $user_id );
		$next    = OYS_Bookings::for_user( $user_id, 'upcoming', array( 'confirmed' ) );
		$out     = $flash . '<div class="oys-account"><header class="oys-account__head"><div><h2>' . sprintf( esc_html__( 'Hi, %s', 'olivia-studio' ), esc_html( $user->first_name ?: $user->display_name ) ) . '</h2></div><dl class="oys-stats">';
		if ( $member ) {
			$left = (int) $member->classes_per_period ? OYS_Memberships::remaining( $member ) . ' / ' . (int) $member->classes_per_period : '∞';
			$out .= '<div class="oys-stat oys-stat--sun"><dt>' . esc_html__( 'Membership', 'olivia-studio' ) . '</dt><dd>' . esc_html( $left ) . '</dd></div>';
		}
		$out .= '<div class="oys-stat oys-stat--lilac"><dt>' . esc_html__( 'Classes on passes', 'olivia-studio' ) . '</dt><dd>' . (int) $credits . '</dd></div>'
			. ( $online ? '<div class="oys-stat oys-stat--lilac"><dt>' . esc_html__( 'Online classes', 'olivia-studio' ) . '</dt><dd>' . (int) $online . '</dd></div>' : '' )
			. ( $private ? '<div class="oys-stat oys-stat--sun"><dt>' . esc_html__( 'Private sessions', 'olivia-studio' ) . '</dt><dd>' . (int) $private . '</dd></div>' : '' )
			. '<div class="oys-stat oys-stat--pink"><dt>' . esc_html__( 'Upcoming', 'olivia-studio' ) . '</dt><dd>' . count( $next ) . '</dd></div></dl></header>';
		$out .= '<nav class="oys-account__nav" aria-label="' . esc_attr__( 'Account', 'olivia-studio' ) . '">';
		foreach ( $tabs as $k => $label ) {
			$out .= '<a href="' . esc_url( oys_account_url( $k ) ) . '"' . ( $k === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		$out .= '<a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '" class="oys-logout">' . esc_html__( 'Log out', 'olivia-studio' ) . '</a></nav><div class="oys-account__body">';
		$out .= call_user_func( array( __CLASS__, 'tab_' . $tab ), $user_id );
		return $out . '</div></div>';
	}


	private static function tab_bookings( $user_id ) {
		$items = OYS_Bookings::for_user( $user_id, 'upcoming', array( 'confirmed' ) );
		$paid  = OYS_Bookings::paid_with_labels();
		$out   = '<h3>' . esc_html__( 'Upcoming bookings', 'olivia-studio' ) . '</h3>';
		if ( ! $items ) {
			$out .= '<p class="oys-empty">' . esc_html__( 'No upcoming classes yet.', 'olivia-studio' ) . ' <a class="text-link" href="' . esc_url( oys_page_url( 'book' ) ) . '">' . esc_html__( 'See the timetable', 'olivia-studio' ) . '</a></p>';
		} else {
			$out .= '<ul class="oys-list">';
			foreach ( $items as $b ) {
				$s       = OYS_Schedule::get( $b->session_id );
				$in_time = OYS_Bookings::in_cancel_window( $b, $s );
				$msg     = $in_time ? __( 'Cancel this booking?', 'olivia-studio' ) : __( 'It\'s inside the cancellation window, so this class will count as used. Cancel anyway?', 'olivia-studio' );
				if ( $b->guests ) {
					$msg = $in_time ? __( 'Cancel your booking and your guests\' spots?', 'olivia-studio' ) : __( 'It\'s inside the cancellation window, so your and your guests\' classes count as used. Cancel anyway?', 'olivia-studio' );
				}
				$cancel = self::form_open( 'oys_cancel', 'class="oys-inline" onsubmit="return confirm(this.dataset.msg)" data-msg="' . esc_attr( $msg ) . '"' )
					. '<input type="hidden" name="booking" value="' . (int) $b->id . '"><button class="btn btn--ghost btn--sm" type="submit">' . esc_html__( 'Cancel', 'olivia-studio' ) . '</button></form>';
				$guests = '';
				if ( $b->guests ) {
					$guests = '<ul class="oys-guestlist">';
					foreach ( $b->guests as $g ) {
						$guests .= '<li><span>' . oys_icon( 'user' ) . esc_html( $g->guest_name ) . ' <small>' . esc_html( $paid[ $g->paid_with ] ?? $g->paid_with ) . '</small></span>'
							. self::form_open( 'oys_cancel', 'class="oys-inline" onsubmit="return confirm(this.dataset.msg)" data-msg="' . esc_attr( sprintf( __( 'Remove %s from this class?', 'olivia-studio' ), $g->guest_name ) ) . '"' )
							. '<input type="hidden" name="booking" value="' . (int) $g->id . '"><button class="oys-linkbtn" type="submit">' . esc_html__( 'Remove', 'olivia-studio' ) . '</button></form></li>';
					}
					$guests .= '</ul>';
				}
				$add = 'private' !== $b->kind && ! OYS_Schedule::closed_reason( $s ) && count( $b->guests ) < (int) OYS_Settings::get( 'max_guests' ) && OYS_Schedule::spots_left( $s ) > 0
					? '<a class="btn btn--ghost btn--sm" href="' . esc_url( oys_book_url( $b->session_id ) ) . '">' . esc_html__( '+ Guests', 'olivia-studio' ) . '</a>' : '';
				$out .= '<li class="oys-item"><div class="oys-item__when"><b>' . esc_html( wp_date( 'j', oys_ts( $b->starts_at ) ) ) . '</b><span>' . esc_html( wp_date( 'M', oys_ts( $b->starts_at ) ) ) . '</span></div>'
					. '<div class="oys-item__main"><h4>' . esc_html( oys_session_title( $s ) ) . ' <span class="badge">' . esc_html( $paid[ $b->paid_with ] ?? $b->paid_with ) . '</span>' . ( oys_is_hybrid( $s ) && 'online' === $b->mode ? ' <span class="badge badge--online">' . esc_html__( 'Online', 'olivia-studio' ) . '</span>' : '' ) . '</h4><p>' . esc_html( oys_date( $b->starts_at, 'l, g:i a' ) . ' – ' . oys_time( $b->ends_at ) . ( $b->location ? ' · ' . $b->location : '' ) ) . '</p>'
					. ( ( $join = OYS_Bookings::join_link( $b, $s ) ) ? '<p><a class="btn btn--orchid btn--sm oys-join" href="' . esc_url( $join ) . '" target="_blank" rel="noopener">' . esc_html__( 'Join live online', 'olivia-studio' ) . '</a></p>' : ( 'online' === $b->mode ? '<p class="oys-small">' . esc_html__( 'Joining online: the link arrives before the class.', 'olivia-studio' ) . '</p>' : '' ) )
					. $guests
					. ( ! $in_time ? '<p class="oys-small">' . esc_html__( 'Inside the cancellation window', 'olivia-studio' ) . '</p>' : '' ) . '</div>'
					. '<div class="oys-item__actions">' . $add . self::ics_link( $b->id ) . $cancel . '</div></li>';
			}
			$out .= '</ul>';
		}
		$waits = OYS_Bookings::waitlists_for_user( $user_id );
		if ( $waits ) {
			$out .= '<h3>' . esc_html__( 'Waitlists', 'olivia-studio' ) . '</h3><ul class="oys-list">';
			foreach ( $waits as $w ) {
				$out .= '<li class="oys-item"><div class="oys-item__main"><h4>' . esc_html( $w->title ?: oys_class_title( $w->class_slug ) ) . '</h4><p>' . esc_html( oys_date( $w->starts_at, 'l, M j · g:i a' ) ) . ' · ' . sprintf( esc_html__( 'You\'re #%d', 'olivia-studio' ), OYS_Bookings::waitlist_position( $user_id, $w->session_id ) ) . '</p></div>'
					. '<div class="oys-item__actions">' . self::form_open( 'oys_waitlist', 'class="oys-inline"' ) . '<input type="hidden" name="session" value="' . (int) $w->session_id . '"><input type="hidden" name="do" value="leave"><button class="btn btn--ghost btn--sm">' . esc_html__( 'Leave', 'olivia-studio' ) . '</button></form></div></li>';
			}
			$out .= '</ul>';
		}
		$out .= '<p class="oys-policy">' . esc_html( OYS_Settings::get( 'cancel_policy' ) ) . '</p>';
		return $out;
	}

	private static function tab_membership( $user_id ) {
		$all = OYS_Memberships::for_user( $user_id );
		$st  = OYS_Memberships::statuses();
		$out = '';
		$cur = OYS_Memberships::current_for( $user_id );
		if ( $cur ) {
			$p     = OYS_Products::get( $cur->product_id );
			$used  = OYS_Memberships::used_in_period( $cur );
			$limit = (int) $cur->classes_per_period;
			$out  .= '<div class="oys-card oys-membership"><p class="kicker">' . esc_html__( 'Your membership', 'olivia-studio' ) . '</p><h3>' . esc_html( $cur->name ) . ' <span class="badge">' . esc_html( $st[ $cur->status ] ?? $cur->status ) . '</span></h3>';
			if ( 'past_due' === $cur->status ) {
				$out .= '<p class="oys-notice oys-notice--error">' . esc_html__( 'Your last payment didn\'t go through. Please update your card to keep your membership.', 'olivia-studio' ) . '</p>';
			}
			$out .= '<dl class="oys-dl"><div><dt>' . esc_html__( 'Classes this period', 'olivia-studio' ) . '</dt><dd>' . ( $limit ? sprintf( esc_html__( '%1$d of %2$d used', 'olivia-studio' ), $used, $limit ) : sprintf( esc_html__( '%d · unlimited', 'olivia-studio' ), $used ) ) . '</dd></div>'
				. '<div><dt>' . esc_html__( 'Current period', 'olivia-studio' ) . '</dt><dd>' . esc_html( oys_date( $cur->current_period_start, 'M j' ) . ' – ' . oys_date( $cur->current_period_end, 'M j, Y' ) ) . '</dd></div>'
				. '<div><dt>' . esc_html( $cur->cancel_at_period_end ? __( 'Ends on', 'olivia-studio' ) : __( 'Renews on', 'olivia-studio' ) ) . '</dt><dd>' . esc_html( oys_date( $cur->current_period_end, get_option( 'date_format' ) ) ) . ( $p && ! $cur->cancel_at_period_end ? ' · ' . esc_html( oys_money( $p['price_cents'] ) ) : '' ) . '</dd></div></dl>';
			if ( $limit ) {
				$out .= '<span class="oys-meter"><span style="width:' . (int) min( 100, round( 100 * $used / max( 1, $limit ) ) ) . '%"></span></span>';
			}
			$btn  = fn( $do, $label, $cls = 'btn--ghost', $confirm = '' ) => self::form_open( 'oys_membership', 'class="oys-inline"' . ( $confirm ? ' onsubmit="return confirm(this.dataset.msg)" data-msg="' . esc_attr( $confirm ) . '"' : '' ) ) . '<input type="hidden" name="membership" value="' . (int) $cur->id . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><button class="btn ' . $cls . ' btn--sm" type="submit">' . esc_html( $label ) . '</button></form>';
			$out .= '<p class="btn-row">' . $btn( 'portal', __( 'Update card & invoices', 'olivia-studio' ), 'btn--primary' )
				. ( $cur->cancel_at_period_end ? $btn( 'resume', __( 'Keep my membership', 'olivia-studio' ) ) : $btn( 'cancel', __( 'Cancel membership', 'olivia-studio' ), 'btn--ghost', sprintf( __( 'Your membership will end on %s and won\'t renew. Continue?', 'olivia-studio' ), oys_date( $cur->current_period_end, get_option( 'date_format' ) ) ) ) ) . '</p>'
				. '<p class="oys-small">' . esc_html__( 'Your membership covers you. Guests you bring are paid from your pass or by card.', 'olivia-studio' ) . '</p></div>';
		} else {
			$plans = OYS_Products::memberships();
			$out  .= '<h3>' . esc_html__( 'Become a member', 'olivia-studio' ) . '</h3>';
			if ( $plans ) {
				$out .= '<ul class="oys-buy">';
				foreach ( $plans as $p ) {
					$out .= '<li><span><b>' . esc_html( $p['name'] ) . '</b> ' . esc_html( $p['description'] ) . '</span><a class="btn btn--dark btn--sm" href="' . esc_url( oys_page_url( 'book', array( 'product' => $p['id'] ) ) ) . '">' . esc_html( oys_money( $p['price_cents'] ) . ' ' . OYS_Products::period_label( $p ) ) . '</a></li>';
				}
				$out .= '</ul>';
			} else {
				$out .= '<p class="oys-empty">' . esc_html__( 'Memberships are not available right now.', 'olivia-studio' ) . '</p>';
			}
		}
		$past = array_filter( $all, fn( $m ) => ! $cur || (int) $m->id !== (int) $cur->id );
		if ( $past ) {
			$out .= '<h3>' . esc_html__( 'Earlier memberships', 'olivia-studio' ) . '</h3><table class="oys-table"><thead><tr><th>' . esc_html__( 'Plan', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Started', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Ended', 'olivia-studio' ) . '</th></tr></thead><tbody>';
			foreach ( $past as $m ) {
				$out .= '<tr><td>' . esc_html( $m->name ) . '</td><td>' . esc_html( oys_date( $m->created_at, 'M j, Y' ) ) . '</td><td>' . esc_html( $m->ended_at ? oys_date( $m->ended_at, 'M j, Y' ) : ( $st[ $m->status ] ?? $m->status ) ) . '</td></tr>';
			}
			$out .= '</tbody></table>';
		}
		return $out;
	}

	private static function tab_history( $user_id ) {
		$items = OYS_Bookings::for_user( $user_id, 'past', array( 'confirmed', 'attended', 'no_show', 'late_cancelled' ) );
		$st    = OYS_Bookings::statuses();
		if ( ! $items ) {
			return '<h3>' . esc_html__( 'Class history', 'olivia-studio' ) . '</h3><p class="oys-empty">' . esc_html__( 'Your past classes will show here.', 'olivia-studio' ) . '</p>';
		}
		$out = '<h3>' . sprintf( esc_html__( 'Class history (%d)', 'olivia-studio' ), count( $items ) ) . '</h3><table class="oys-table"><thead><tr><th>' . esc_html__( 'Date', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Class', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Status', 'olivia-studio' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $b ) {
			$label = 'confirmed' === $b->status ? __( 'Booked', 'olivia-studio' ) : ( $st[ $b->status ] ?? $b->status );
			$out  .= '<tr><td>' . esc_html( oys_date( $b->starts_at, 'M j, Y' ) ) . '</td><td>' . esc_html( $b->title ?: oys_class_title( $b->class_slug ) ) . '</td><td>' . esc_html( $label ) . '</td></tr>';
		}
		return $out . '</tbody></table>';
	}

	private static function tab_passes( $user_id ) {
		$passes = OYS_Passes::for_user( $user_id );
		$out    = '<h3>' . esc_html__( 'Passes and credits', 'olivia-studio' ) . '</h3>';
		if ( ! $passes ) {
			$out .= '<p class="oys-empty">' . esc_html__( 'No passes yet.', 'olivia-studio' ) . '</p>';
		} else {
			$out .= '<div class="oys-passes">';
			foreach ( $passes as $p ) {
				$expired = $p->expires_at && oys_ts( $p->expires_at ) < time();
				$state   = $expired ? __( 'Expired', 'olivia-studio' ) : ( $p->credits_left < 1 ? __( 'Used up', 'olivia-studio' ) : '' );
				$pct     = $p->credits_total ? round( 100 * $p->credits_left / $p->credits_total ) : 0;
				$out    .= '<article class="oys-pass' . ( $state ? ' is-done' : '' ) . ' oys-pass--' . esc_attr( $p->kind ) . '"><p class="kicker">' . esc_html( OYS_Passes::kinds()[ $p->kind ] ?? $p->kind ) . '</p>'
					. '<h4>' . esc_html( $p->name ) . '</h4><p class="oys-pass__count"><b>' . (int) $p->credits_left . '</b> / ' . (int) $p->credits_total . '</p>'
					. '<span class="oys-meter"><span style="width:' . (int) $pct . '%"></span></span>'
					. '<p class="oys-small">' . ( $state ? esc_html( $state ) : ( $p->expires_at ? sprintf( esc_html__( 'Use by %s', 'olivia-studio' ), esc_html( oys_date( $p->expires_at, get_option( 'date_format' ) ) ) ) : esc_html__( 'No expiry', 'olivia-studio' ) ) ) . '</p></article>';
			}
			$out .= '</div>';
		}
		$out .= '<div class="oys-card oys-redeem"><h3>' . esc_html__( 'Redeem a gift card', 'olivia-studio' ) . '</h3>' . self::form_open( 'oys_gift_redeem', 'class="oys-form oys-form--row"' )
			. '<label class="field"><span>' . esc_html__( 'Gift code', 'olivia-studio' ) . '</span><input type="text" name="code" id="oys-gift-code" placeholder="OY-ABCD-EFGH" required autocapitalize="characters"></label>'
			. '<button class="btn btn--primary" type="submit">' . esc_html__( 'Redeem', 'olivia-studio' ) . '</button></form></div>';
		$buy = array_filter( OYS_Products::purchasable(), fn( $p ) => 'intro' !== $p['kind'] || OYS_Orders::is_new_customer( $user_id ) );
		if ( $buy ) {
			$out .= '<h3>' . esc_html__( 'Buy a pass', 'olivia-studio' ) . '</h3><ul class="oys-buy">';
			foreach ( $buy as $p ) {
				$out .= '<li><span><b>' . esc_html( $p['name'] ) . '</b> ' . esc_html( $p['description'] ) . '</span><a class="btn btn--dark btn--sm" href="' . esc_url( oys_page_url( 'book', array( 'product' => $p['id'] ) ) ) . '">' . esc_html( oys_money( $p['price_cents'] ) ) . '</a></li>';
			}
			$out .= '</ul>';
		}
		return $out;
	}

	private static function tab_payments( $user_id ) {
		$orders = OYS_Orders::for_user( $user_id );
		if ( ! $orders ) {
			return '<h3>' . esc_html__( 'Payments', 'olivia-studio' ) . '</h3><p class="oys-empty">' . esc_html__( 'No payments yet.', 'olivia-studio' ) . '</p>';
		}
		$st  = OYS_Orders::statuses();
		$out = '<h3>' . esc_html__( 'Payments', 'olivia-studio' ) . '</h3><table class="oys-table"><thead><tr><th>' . esc_html__( 'Date', 'olivia-studio' ) . '</th><th>' . esc_html__( 'What', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Amount', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Receipt', 'olivia-studio' ) . '</th></tr></thead><tbody>';
		foreach ( $orders as $o ) {
			$out .= '<tr><td>' . esc_html( oys_date( $o->paid_at ?: $o->created_at, 'M j, Y' ) ) . '</td><td>' . esc_html( $o->description ) . ( 'paid' !== $o->status ? ' <span class="badge">' . esc_html( $st[ $o->status ] ?? $o->status ) . '</span>' : '' ) . '</td><td class="num">' . esc_html( oys_money( $o->amount_cents, $o->currency ) ) . '</td><td>' . ( $o->receipt_url ? '<a class="text-link" href="' . esc_url( $o->receipt_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Receipt', 'olivia-studio' ) . '</a>' : '—' ) . '</td></tr>';
		}
		return $out . '</tbody></table>';
	}

	private static function tab_private( $user_id ) {
		$out = '';
		$reqs = OYS_Privates::for_user( $user_id );
		$st   = OYS_Privates::statuses();
		$types = OYS_Privates::location_types();
		if ( $reqs ) {
			$out .= '<h3>' . esc_html__( 'Your requests', 'olivia-studio' ) . '</h3><ul class="oys-list">';
			foreach ( $reqs as $r ) {
				$s      = $r->session_id ? OYS_Schedule::get( $r->session_id ) : null;
				$detail = $s ? oys_date( $s->starts_at, 'l, M j · g:i a' ) . ' · ' . oys_money( $r->price_cents ) : sprintf( __( 'Requested %s', 'olivia-studio' ), oys_date( $r->created_at, get_option( 'date_format' ) ) );
				$action = '';
				if ( 'offered' === $r->status && $s && 'scheduled' === $s->status ) {
					$action = '<a class="btn btn--primary btn--sm" href="' . esc_url( oys_book_url( $s->id ) ) . '">' . esc_html__( 'Confirm and pay', 'olivia-studio' ) . '</a>';
				}
				$out .= '<li class="oys-item"><div class="oys-item__main"><h4>' . sprintf( esc_html__( 'Private session, %d min', 'olivia-studio' ), (int) $r->duration_min ) . ' <span class="badge">' . esc_html( $st[ $r->status ] ?? $r->status ) . '</span></h4>'
					. '<p>' . esc_html( ( $types[ $r->location_type ] ?? '' ) . ' · ' . $detail ) . '</p>'
					. ( $r->admin_message ? '<p class="oys-small">' . esc_html( $r->admin_message ) . '</p>' : '' ) . '</div><div class="oys-item__actions">' . $action . '</div></li>';
			}
			$out .= '</ul>';
		}
		return $out . '<h3>' . esc_html__( 'Request a private session', 'olivia-studio' ) . '</h3>' . self::private_form();
	}

	private static function private_form() {
		$types = OYS_Privates::location_types();
		$opts  = '';
		foreach ( $types as $k => $v ) {
			$opts .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';
		}
		$dur = '';
		foreach ( array( 60, 75, 90 ) as $d ) {
			$price  = OYS_Products::private_price_for( $d );
			$oprice = OYS_Products::private_price_for( $d, true );
			$dur   .= '<option value="' . $d . '">' . sprintf( esc_html__( '%d minutes', 'olivia-studio' ), $d ) . ( $price ? ' · ' . esc_html( oys_money( $price ) ) : '' ) . ( $oprice && $oprice !== $price ? ' · ' . esc_html( sprintf( __( 'online %s', 'olivia-studio' ), oys_money( $oprice ) ) ) : '' ) . '</option>';
		}
		return self::form_open( 'oys_private_request', 'class="oys-form oys-card"' )
			. '<div class="form__row"><label class="field"><span>' . esc_html__( 'Length', 'olivia-studio' ) . '</span><select name="duration_min" id="oys-pr-duration">' . $dur . '</select></label>'
			. '<label class="field"><span>' . esc_html__( 'How many people', 'olivia-studio' ) . '</span><input type="number" name="people" id="oys-pr-people" min="1" max="10" value="1"></label></div>'
			. '<div class="form__row"><label class="field"><span>' . esc_html__( 'Where', 'olivia-studio' ) . '</span><select name="location_type" id="oys-pr-type">' . $opts . '</select></label>'
			. '<label class="field"><span>' . esc_html__( 'Address or area', 'olivia-studio' ) . '</span><input type="text" name="address" id="oys-pr-address" placeholder="' . esc_attr__( 'e.g. McGregor Blvd, Fort Myers Beach', 'olivia-studio' ) . '"></label></div>'
			. '<label class="field"><span>' . esc_html__( 'Days and times that suit you', 'olivia-studio' ) . '</span><textarea name="preferred" id="oys-pr-preferred" rows="3" required placeholder="' . esc_attr__( 'e.g. Tuesday or Thursday mornings, or Saturday at sunrise', 'olivia-studio' ) . '"></textarea></label>'
			. '<label class="field"><span>' . esc_html__( 'Anything I should know? (goals, injuries, experience)', 'olivia-studio' ) . '</span><textarea name="notes" id="oys-pr-notes" rows="4"></textarea></label>'
			. '<button class="btn btn--primary" type="submit">' . esc_html__( 'Send request', 'olivia-studio' ) . '</button>'
			. '<p class="oys-small">' . esc_html__( 'Nothing is charged now. You\'ll get a suggested time and price to confirm.', 'olivia-studio' ) . '</p></form>';
	}

	private static function tab_profile( $user_id ) {
		$u   = get_userdata( $user_id );
		$m   = fn( $k ) => esc_attr( get_user_meta( $user_id, $k, true ) );
		$out = self::form_open( 'oys_profile', 'class="oys-form oys-card"' ) . '<h3>' . esc_html__( 'Your details', 'olivia-studio' ) . '</h3>'
			. '<div class="form__row"><label class="field"><span>' . esc_html__( 'First name', 'olivia-studio' ) . '</span><input type="text" name="first_name" id="oys-p-first" value="' . esc_attr( $u->first_name ) . '" autocomplete="given-name"></label>'
			. '<label class="field"><span>' . esc_html__( 'Last name', 'olivia-studio' ) . '</span><input type="text" name="last_name" id="oys-p-last" value="' . esc_attr( $u->last_name ) . '" autocomplete="family-name"></label></div>'
			. '<div class="form__row"><label class="field"><span>' . esc_html__( 'Email', 'olivia-studio' ) . '</span><input type="email" id="oys-p-email" value="' . esc_attr( $u->user_email ) . '" disabled></label>'
			. '<label class="field"><span>' . esc_html__( 'Phone', 'olivia-studio' ) . '</span><input type="tel" name="oys_phone" id="oys-p-phone" value="' . $m( 'oys_phone' ) . '" autocomplete="tel"></label></div>'
			. '<label class="field"><span>' . esc_html__( 'Your area', 'olivia-studio' ) . '</span><input type="text" name="oys_area" id="oys-p-area" value="' . $m( 'oys_area' ) . '"></label>'
			. '<div class="form__row"><label class="field"><span>' . esc_html__( 'Emergency contact', 'olivia-studio' ) . '</span><input type="text" name="oys_emergency_name" id="oys-p-ename" value="' . $m( 'oys_emergency_name' ) . '"></label>'
			. '<label class="field"><span>' . esc_html__( 'Emergency phone', 'olivia-studio' ) . '</span><input type="tel" name="oys_emergency_phone" id="oys-p-ephone" value="' . $m( 'oys_emergency_phone' ) . '"></label></div>'
			. '<label class="field"><span>' . esc_html__( 'Injuries, pregnancy or health notes (only Olivia sees this)', 'olivia-studio' ) . '</span><textarea name="oys_health_notes" id="oys-p-health" rows="4">' . esc_textarea( get_user_meta( $user_id, 'oys_health_notes', true ) ) . '</textarea></label>'
			. '<label class="oys-check"><input type="checkbox" name="oys_marketing" value="1"' . checked( '1', get_user_meta( $user_id, 'oys_marketing', true ), false ) . '> <span>' . esc_html__( 'Email me about new classes and events', 'olivia-studio' ) . '</span></label>'
			. '<label class="field"><span>' . esc_html__( 'New password (leave empty to keep the current one)', 'olivia-studio' ) . '</span><input type="password" name="new_password" id="oys-p-pass" minlength="8" autocomplete="new-password"></label>'
			. '<button class="btn btn--primary" type="submit">' . esc_html__( 'Save', 'olivia-studio' ) . '</button></form>';
		$at   = get_user_meta( $user_id, 'oys_waiver_at', true );
		$out .= '<div class="oys-card"><h3>' . esc_html__( 'Participation agreement', 'olivia-studio' ) . '</h3>';
		if ( OYS_Customers::has_waiver( $user_id ) ) {
			$out .= '<p>' . sprintf( esc_html__( 'Accepted on %s.', 'olivia-studio' ), esc_html( oys_date( $at, get_option( 'date_format' ) ) ) ) . '</p><details class="oys-waiver"><summary>' . esc_html__( 'Read it again', 'olivia-studio' ) . '</summary><p>' . esc_html( OYS_Settings::get( 'waiver_text' ) ) . '</p></details>';
		} else {
			$out .= self::form_open( 'oys_waiver' ) . '<input type="hidden" name="redirect_to" value="' . esc_attr( oys_account_url( 'profile' ) ) . '">' . self::waiver_field() . '<button class="btn btn--primary" type="submit">' . esc_html__( 'Accept', 'olivia-studio' ) . '</button></form>';
		}
		return $out . '</div>';
	}

	/* ======================================================================
	   Gift cards and private request (standalone shortcodes)
	   ====================================================================== */

	public static function sc_gift_cards() {
		$flash = oys_render_flash();
		$gifts = OYS_Products::giftable();
		if ( ! $gifts ) {
			return $flash;
		}
		$cards = '<div class="oys-gifts">';
		$first = true;
		foreach ( $gifts as $p ) {
			$cards .= '<label class="oys-gift"><input type="radio" name="product" value="' . esc_attr( $p['id'] ) . '"' . checked( $first, true, false ) . '><span class="oys-gift__card"><span class="kicker">' . esc_html__( 'Gift card', 'olivia-studio' ) . '</span><b>' . esc_html( $p['name'] ) . '</b><span class="oys-gift__price">' . esc_html( oys_money( $p['price_cents'] ) ) . '</span></span></label>';
			$first  = false;
		}
		$cards .= '</div>';
		if ( ! is_user_logged_in() ) {
			return $flash . '<div class="oys-gifts oys-gifts--preview">' . implode( '', array_map( fn( $p ) => '<div class="oys-gift"><span class="oys-gift__card"><span class="kicker">' . esc_html__( 'Gift card', 'olivia-studio' ) . '</span><b>' . esc_html( $p['name'] ) . '</b><span class="oys-gift__price">' . esc_html( oys_money( $p['price_cents'] ) ) . '</span></span></div>', $gifts ) ) . '</div>'
				. self::auth_block( oys_page_url( 'gifts' ), __( 'Log in or create an account to buy a gift card. Your receipt and the gift code are kept there.', 'olivia-studio' ) );
		}
		if ( ! OYS_Settings::payments_ready() ) {
			return $flash . $cards . '<p class="oys-notice">' . esc_html__( 'Online gift cards are coming soon. Send a message to order one now.', 'olivia-studio' ) . '</p>';
		}
		return $flash . self::form_open( 'oys_gift_buy', 'class="oys-form"' ) . '<fieldset><legend class="kicker">' . esc_html__( 'Choose a gift', 'olivia-studio' ) . '</legend>' . $cards . '</fieldset>'
			. '<div class="oys-card"><div class="form__row"><label class="field"><span>' . esc_html__( 'Recipient\'s name', 'olivia-studio' ) . '</span><input type="text" name="recipient_name" id="oys-g-name" required></label>'
			. '<label class="field"><span>' . esc_html__( 'Recipient\'s email', 'olivia-studio' ) . '</span><input type="email" name="recipient_email" id="oys-g-email" required></label></div>'
			. '<label class="field"><span>' . esc_html__( 'Personal message (optional)', 'olivia-studio' ) . '</span><textarea name="message" id="oys-g-msg" rows="3"></textarea></label>'
			. '<button class="btn btn--primary" type="submit">' . esc_html__( 'Continue to payment', 'olivia-studio' ) . '</button>'
			. '<p class="oys-small">' . esc_html__( 'The gift card is emailed to the recipient right after payment. You get the receipt.', 'olivia-studio' ) . '</p></div></form>';
	}

	public static function sc_private_request() {
		$flash = oys_render_flash();
		if ( ! is_user_logged_in() ) {
			$here = get_permalink() ?: oys_account_url( 'private' );
			return $flash . self::auth_block( $here, __( 'Create a free account to request a private session. You\'ll get a suggested time and price, and confirm with one click.', 'olivia-studio' ) );
		}
		return $flash . self::private_form();
	}
}
