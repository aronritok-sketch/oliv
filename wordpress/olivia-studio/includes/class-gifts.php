<?php
/**
 * Gift cards: someone buys a giftable product for a friend, the friend gets an email
 * with a code and redeems it in their account, which adds the pass to it.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Gifts {

	public static function init() {
		add_action( 'admin_post_oys_gift_buy', array( __CLASS__, 'handle_buy' ) );
		add_action( 'admin_post_oys_gift_redeem', array( __CLASS__, 'handle_redeem' ) );
	}

	public static function generate_code() {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		do {
			$code = 'OY-';
			for ( $i = 0; $i < 8; $i++ ) {
				$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
				if ( 3 === $i ) {
					$code .= '-';
				}
			}
		} while ( self::by_code( $code ) );
		return $code;
	}

	public static function by_code( $code ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'gift_cards' ) . ' WHERE code = %s', strtoupper( trim( $code ) ) ) );
	}

	public static function query() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . OYS_Install::table( 'gift_cards' ) . " WHERE status <> 'pending' ORDER BY created_at DESC LIMIT 300" );
	}

	public static function handle_buy() {
		check_admin_referer( 'oys_gift_buy' );
		$user_id = get_current_user_id();
		$back    = wp_get_referer() ?: oys_page_url( 'gifts' );
		if ( ! $user_id ) {
			oys_redirect( $back );
		}
		$product = OYS_Products::get( sanitize_key( $_POST['product'] ?? '' ) );
		$name    = sanitize_text_field( wp_unslash( $_POST['recipient_name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['recipient_email'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		if ( ! $product || empty( $product['giftable'] ) || empty( $product['active'] ) ) {
			oys_flash( __( 'Choose a gift.', 'olivia-studio' ), 'error' );
			oys_redirect( $back );
		}
		if ( ! is_email( $email ) ) {
			oys_flash( __( 'Enter the recipient\'s email address.', 'olivia-studio' ), 'error' );
			oys_redirect( $back );
		}
		$order_id = OYS_Orders::create( array(
			'user_id'      => $user_id,
			'type'         => 'gift',
			'product_id'   => $product['id'],
			'description'  => sprintf( __( 'Gift card: %s', 'olivia-studio' ), $product['name'] ),
			'amount_cents' => (int) $product['price_cents'],
			'meta'         => array( 'recipient_name' => $name, 'recipient_email' => $email, 'message' => $message ),
		) );
		$url = OYS_Stripe::start_checkout( $order_id, sprintf( __( 'Gift card: %s', 'olivia-studio' ), $product['name'] ), sprintf( __( 'For %s', 'olivia-studio' ), $name ?: $email ) );
		if ( is_wp_error( $url ) ) {
			oys_flash( $url->get_error_message(), 'error' );
			oys_redirect( $back );
		}
		wp_redirect( $url ); // Stripe-hosted page.
		exit;
	}

	public static function issue_for_order( $order ) {
		global $wpdb;
		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . OYS_Install::table( 'gift_cards' ) . ' WHERE order_id = %d', $order->id ) );
		if ( $existing ) {
			return (int) $existing;
		}
		$wpdb->insert( OYS_Install::table( 'gift_cards' ), array(
			'code'            => self::generate_code(),
			'product_id'      => $order->product_id,
			'order_id'        => $order->id,
			'purchaser_id'    => $order->user_id,
			'recipient_name'  => $order->meta['recipient_name'] ?? '',
			'recipient_email' => $order->meta['recipient_email'] ?? '',
			'message'         => $order->meta['message'] ?? '',
			'status'          => 'active',
			'created_at'      => oys_now(),
		) );
		$id   = (int) $wpdb->insert_id;
		$gift = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'gift_cards' ) . ' WHERE id = %d', $id ) );
		OYS_Emails::gift_card( $gift );
		OYS_Emails::gift_receipt( $gift, $order );
		return $id;
	}

	/** @return int|WP_Error pass id */
	public static function redeem( $code, $user_id ) {
		global $wpdb;
		$gift = self::by_code( $code );
		if ( ! $gift || 'active' !== $gift->status ) {
			return new WP_Error( 'oys_gift', __( 'That code isn\'t valid or has already been used. Check for typos: codes look like OY-ABCD-EFGH.', 'olivia-studio' ) );
		}
		$product = OYS_Products::get( $gift->product_id );
		if ( ! $product ) {
			return new WP_Error( 'oys_gift', __( 'This gift can\'t be redeemed online. Please contact us.', 'olivia-studio' ) );
		}
		$t  = OYS_Install::table( 'gift_cards' );
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'redeemed', redeemed_by = %d, redeemed_at = %s WHERE id = %d AND status = 'active'", $user_id, oys_now(), $gift->id ) );
		if ( 1 !== (int) $ok ) {
			return new WP_Error( 'oys_gift', __( 'This code has already been used.', 'olivia-studio' ) );
		}
		return OYS_Passes::grant_product( $user_id, $product, $gift->order_id, 'gift' );
	}

	public static function handle_redeem() {
		check_admin_referer( 'oys_gift_redeem' );
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$res = self::redeem( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ), $user_id );
			if ( is_wp_error( $res ) ) {
				oys_flash( $res->get_error_message(), 'error' );
			} else {
				$pass = OYS_Passes::get( $res );
				oys_flash( sprintf( __( 'Gift redeemed: %s is now in your account.', 'olivia-studio' ), esc_html( $pass->name ) ) );
			}
		}
		oys_redirect( oys_account_url( 'passes' ) );
	}
}

OYS_Gifts::init();
