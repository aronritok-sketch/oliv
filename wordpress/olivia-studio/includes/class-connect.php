<?php
/**
 * Sharing class income with other teachers, without the studio taking in their money.
 *
 * Card payments: a teacher connects their own Stripe account (Stripe Connect, a Standard-type
 * account with its own dashboard). A drop-in or donation paid by card for their class is then
 * charged on the teacher's account (a "direct charge"): the teacher is the seller, and the
 * studio's part comes to the studio as an application fee (100 − the teacher's share %).
 * Refunds go back through the teacher's account, returning the studio's fee proportionally.
 *
 * Everything else (passes, memberships, pay at the studio) is settled once a month with the
 * statement below: every booking in a teacher's class that month, what it was worth and who owes
 * whom. A pass class is worth what the customer paid per class (free passes: the class price), a
 * membership visit `settle_membership_cents`; cash is kept by whoever collects it (`cash_by`).
 */

defined( 'ABSPATH' ) || exit;

class OYS_Connect {

	/** Charged on the teacher's account when paid by card: single classes, not passes or memberships. */
	const DIRECT_TYPES = array( 'dropin', 'private' );

	public static function ready( $t ) {
		return $t && $t->stripe_account && (int) $t->stripe_ready && (int) $t->stripe_live === ( OYS_Settings::is_live() ? 1 : 0 );
	}

	/** Stripe status in words, for the admin screens. */
	public static function status_label( $t ) {
		if ( ! $t->stripe_account || (int) $t->stripe_live !== ( OYS_Settings::is_live() ? 1 : 0 ) ) {
			return __( 'Not connected', 'olivia-studio' );
		}
		return (int) $t->stripe_ready ? __( 'Connected: card payments go to their Stripe', 'olivia-studio' ) : __( 'Started, not finished', 'olivia-studio' );
	}

	/**
	 * Where a card payment for this order goes. @return array|null [ account, fee_cents, teacher_id ]
	 * or null when the studio is paid (studio classes, passes, teacher without Stripe).
	 */
	public static function split_for_order( $order ) {
		if ( ! $order || ! in_array( $order->type, self::DIRECT_TYPES, true ) || ! $order->session_id ) {
			return null;
		}
		$t = OYS_Teachers::for_session( OYS_Schedule::get( $order->session_id ) );
		if ( ! self::ready( $t ) ) {
			return null;
		}
		$fee = (int) round( (int) $order->amount_cents * ( 100 - (int) $t->share_percent ) / 100 );
		return array(
			'account'    => $t->stripe_account,
			'fee_cents'  => min( (int) $order->amount_cents, max( 0, $fee ) ),
			'teacher_id' => (int) $t->id,
		);
	}

	/** The connected account an order was charged on ('' = the studio's own). */
	public static function account_for_order( $order ) {
		return $order ? (string) ( $order->meta['stripe_account'] ?? '' ) : '';
	}

	/* ---------- Onboarding ---------- */

	/**
	 * A Stripe page where the teacher creates or finishes their account.
	 * The account is created on first use (their own dashboard; Stripe collects their details).
	 * @return string|WP_Error
	 */
	public static function onboarding_url( $teacher_id, $return_url ) {
		global $wpdb;
		$t    = OYS_Teachers::get( $teacher_id );
		$live = OYS_Settings::is_live() ? 1 : 0;
		if ( ! $t ) {
			return new WP_Error( 'oys_teacher', __( 'Teacher not found.', 'olivia-studio' ) );
		}
		if ( ! OYS_Settings::payments_ready() ) {
			return new WP_Error( 'oys_stripe_keys', __( 'Online payments are not set up yet.', 'olivia-studio' ) );
		}
		$account = ( $t->stripe_account && (int) $t->stripe_live === $live ) ? $t->stripe_account : '';
		if ( ! $account ) {
			$res = OYS_Stripe::request( 'POST', '/v1/accounts', array_filter( array(
				'email'      => is_email( $t->email ) ? $t->email : '',
				'country'    => 'US',
				'controller' => array(
					'stripe_dashboard'       => array( 'type' => 'full' ),
					'fees'                   => array( 'payer' => 'account' ),
					'losses'                 => array( 'payments' => 'stripe' ),
					'requirement_collection' => 'stripe',
				),
				'business_profile' => array( 'product_description' => sprintf( 'Yoga classes taught at %s', OYS_Settings::get( 'email_from_name' ) ) ),
				'metadata'   => array( 'teacher_id' => (int) $t->id, 'site' => home_url() ),
			) ), 'oys-teacher-account-' . $t->id . '-' . ( $live ? 'live' : 'test' ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$account = $res['id'];
			$wpdb->update( OYS_Teachers::table(), array( 'stripe_account' => $account, 'stripe_live' => $live, 'stripe_ready' => 0 ), array( 'id' => (int) $t->id ) );
			OYS_Teachers::flush();
		}
		$link = OYS_Stripe::request( 'POST', '/v1/account_links', array(
			'account'     => $account,
			'refresh_url' => add_query_arg( 'oys_connect', 'refresh', $return_url ),
			'return_url'  => add_query_arg( 'oys_connect', 'return', $return_url ),
			'type'        => 'account_onboarding',
		) );
		return is_wp_error( $link ) ? $link : $link['url'];
	}

	/** Ask Stripe whether the account can take payments now. @return bool|WP_Error ready */
	public static function refresh( $teacher_id ) {
		$t = OYS_Teachers::get( $teacher_id );
		if ( ! $t || ! $t->stripe_account ) {
			return false;
		}
		$acct = OYS_Stripe::request( 'GET', '/v1/accounts/' . rawurlencode( $t->stripe_account ) );
		if ( is_wp_error( $acct ) ) {
			return $acct;
		}
		return self::apply_account( $acct );
	}

	/** Webhook account.updated, or a fresh read: remember whether card payments can go there. */
	public static function apply_account( array $acct ) {
		global $wpdb;
		$ready = ! empty( $acct['charges_enabled'] ) && ! empty( $acct['details_submitted'] ) ? 1 : 0;
		$wpdb->update( OYS_Teachers::table(), array( 'stripe_ready' => $ready ), array( 'stripe_account' => (string) ( $acct['id'] ?? '-' ) ) );
		OYS_Teachers::flush();
		return (bool) $ready;
	}

	/** Forget the account (card payments go to the studio again). The account itself stays the teacher's. */
	public static function disconnect( $teacher_id ) {
		global $wpdb;
		$wpdb->update( OYS_Teachers::table(), array( 'stripe_account' => '', 'stripe_ready' => 0 ), array( 'id' => (int) $teacher_id ) );
		OYS_Teachers::flush();
	}

	/* ---------- Monthly statement ---------- */

	/** 'Y-m' → [ start UTC, end UTC, label ] in studio time. */
	public static function month_range( $month ) {
		$month = preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ? $month : wp_date( 'Y-m' );
		$tz    = wp_timezone();
		$a     = new DateTimeImmutable( $month . '-01 00:00:00', $tz );
		$b     = $a->modify( '+1 month' );
		$utc   = new DateTimeZone( 'UTC' );
		return array( $a->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $b->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $a->format( 'F Y' ) );
	}

	/** What one class from a pass is worth: paid price per class, or the class price for free credits. */
	public static function credit_value( $pass, $session, $mode = 'studio' ) {
		$list = OYS_Schedule::price_for( $session, $mode );
		if ( ! $pass || ! $pass->order_id ) {
			return $list;
		}
		$o = OYS_Orders::get( $pass->order_id );
		if ( ! $o || ! in_array( $o->status, array( 'paid', 'partially_refunded' ), true ) ) {
			return $list;
		}
		if ( 'cancel' === $pass->source ) {
			return self::seat_value( $o );
		}
		$product = $pass->product_id ? OYS_Products::get( $pass->product_id ) : null;
		$credits = max( 1, (int) ( $product['credits'] ?? $pass->credits_total ) );
		$value   = (int) $o->amount_cents / $credits;
		if ( 'convert' === $pass->source ) {
			$value /= max( 1, OYS_Passes::online_per_credit() );
		}
		return (int) round( $value );
	}

	/** One seat of a card order (the customer and each guest paid the same). */
	public static function seat_value( $order ) {
		global $wpdb;
		$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . OYS_Install::table( 'bookings' ) . " WHERE order_id = %d AND status NOT IN ('expired','pending')", $order->id ) );
		return (int) round( (int) $order->amount_cents / max( 1, $rows ) );
	}

	/**
	 * Every paid visit in a teacher's classes in a month, and who owes whom.
	 * Row: date, class, person, paid_with, how (card_direct|card|credit|membership|cash|free|due),
	 * value, teacher_part, studio_part, owed (+ studio owes the teacher, − the teacher owes the studio).
	 */
	public static function statement( $teacher_id, $month ) {
		global $wpdb;
		$t = OYS_Teachers::get( $teacher_id );
		[ $start, $end, $label ] = self::month_range( $month );
		$out = array( 'teacher' => $t, 'month' => substr( $start, 0, 7 ), 'label' => $label, 'rows' => array(), 'classes' => 0, 'totals' => array_fill_keys( array( 'value', 'teacher', 'direct', 'direct_fee', 'studio_owes', 'teacher_owes', 'balance', 'uncollected' ), 0 ) );
		if ( ! $t ) {
			return $out;
		}
		$share    = (int) $t->share_percent;
		$sessions = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . OYS_Install::table( 'sessions' ) . " WHERE teacher_id = %d AND status = 'scheduled' AND starts_at >= %s AND starts_at < %s AND ends_at <= %s ORDER BY starts_at",
			$t->id, $start, $end, oys_now()
		) );
		$labels = OYS_Bookings::paid_with_labels();
		$tot    = &$out['totals'];
		foreach ( $sessions as $s ) {
			$out['classes']++;
			foreach ( OYS_Bookings::for_session( $s->id, array( 'confirmed', 'attended', 'no_show', 'late_cancelled' ) ) as $b ) {
				$how   = 'free';
				$value = 0;
				$owed  = 0;
				$order = $b->order_id ? OYS_Orders::get( $b->order_id ) : null;
				switch ( $b->paid_with ) {
					case 'card':
						$value = $order ? self::seat_value( $order ) : 0;
						$how   = self::account_for_order( $order ) ? 'card_direct' : 'card';
						break;
					case 'credit':
						$value = self::credit_value( $b->pass_id ? OYS_Passes::get( $b->pass_id ) : null, $s, $b->mode );
						$how   = 'credit';
						break;
					case 'membership':
						$value = (int) OYS_Settings::get( 'settle_membership_cents' );
						$how   = 'membership';
						break;
					case 'door':
						$value = $b->collected_with ? (int) $b->due_cents : 0;
						$how   = $b->collected_with ? 'cash' : 'due';
						if ( ! $b->collected_with ) {
							$tot['uncollected'] += (int) $b->due_cents;
						}
						break;
					case 'cash':
						$value = OYS_Schedule::price_for( $s, $b->mode );
						$how   = 'cash';
						break;
				}
				$teacher_part = (int) round( $value * $share / 100 );
				$studio_part  = $value - $teacher_part;
				if ( 'card_direct' === $how ) {
					$tot['direct']     += $value;
					$tot['direct_fee'] += $studio_part;
				} elseif ( 'cash' === $how && 'teacher' === $t->cash_by ) {
					$owed                 = -$studio_part;
					$tot['teacher_owes'] += $studio_part;
				} elseif ( $value ) {
					$owed                += $teacher_part;
					$tot['studio_owes'] += $teacher_part;
				}
				$tot['value']   += $value;
				$tot['teacher'] += $teacher_part;
				$out['rows'][]   = array(
					'date'         => oys_date( $s->starts_at, 'M j, g:i a' ),
					'class'        => oys_session_title( $s ),
					'session_id'   => (int) $s->id,
					'person'       => $b->guest_of ? sprintf( __( '%s (guest)', 'olivia-studio' ), $b->guest_name ) : OYS_Bookings::person_label( $b ),
					'status'       => $b->status,
					'paid_with'    => $labels[ $b->paid_with ] ?? $b->paid_with,
					'how'          => $how,
					'value'        => $value,
					'teacher_part' => $teacher_part,
					'studio_part'  => $studio_part,
					'owed'         => $owed,
				);
			}
		}
		$tot['balance'] = $tot['studio_owes'] - $tot['teacher_owes'];
		return $out;
	}

	public static function how_labels() {
		return array(
			'card_direct' => __( 'Card, paid to the teacher\'s Stripe', 'olivia-studio' ),
			'card'        => __( 'Card, paid to the studio', 'olivia-studio' ),
			'credit'      => __( 'Class pass', 'olivia-studio' ),
			'membership'  => __( 'Membership', 'olivia-studio' ),
			'cash'        => __( 'Paid at the studio', 'olivia-studio' ),
			'due'         => __( 'Not collected yet', 'olivia-studio' ),
			'free'        => __( 'Free', 'olivia-studio' ),
		);
	}

	/** Statement as CSV (for the accountant). */
	public static function csv( array $st ) {
		$h = fopen( 'php://temp', 'r+' );
		fputcsv( $h, array( 'Date', 'Class', 'Person', 'Status', 'Paid with', 'How', 'Value', 'Teacher part', 'Studio part', 'Studio owes teacher (+) / teacher owes studio (-)' ), ',', '"', '' );
		$how = self::how_labels();
		foreach ( $st['rows'] as $r ) {
			fputcsv( $h, array( $r['date'], $r['class'], $r['person'], $r['status'], $r['paid_with'], $how[ $r['how'] ] ?? $r['how'], self::num( $r['value'] ), self::num( $r['teacher_part'] ), self::num( $r['studio_part'] ), self::num( $r['owed'] ) ), ',', '"', '' );
		}
		fputcsv( $h, array(), ',', '"', '' );
		fputcsv( $h, array( 'Balance', '', '', '', '', '', '', '', '', self::num( $st['totals']['balance'] ) ), ',', '"', '' );
		rewind( $h );
		$csv = stream_get_contents( $h );
		fclose( $h );
		return $csv;
	}

	private static function num( $cents ) {
		return number_format( $cents / 100, 2, '.', '' );
	}
}
