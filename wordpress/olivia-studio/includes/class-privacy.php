<?php
/**
 * Hooks into WordPress's personal data tools (Tools → Export / Erase Personal Data).
 * Erasing removes profile and health details; payment records are kept for accounting.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Privacy {

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', function ( $e ) {
			$e['olivia-studio'] = array( 'exporter_friendly_name' => __( 'Yoga bookings and passes', 'olivia-studio' ), 'callback' => array( __CLASS__, 'export' ) );
			return $e;
		} );
		add_filter( 'wp_privacy_personal_data_erasers', function ( $e ) {
			$e['olivia-studio'] = array( 'eraser_friendly_name' => __( 'Yoga profile details', 'olivia-studio' ), 'callback' => array( __CLASS__, 'erase' ) );
			return $e;
		} );
		add_action( 'admin_init', function () {
			if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
				wp_add_privacy_policy_content( 'Olivia Studio', wp_kses_post( '<p>' . __( 'When you create an account we store your name, email, phone, optional emergency contact and health notes, your bookings, passes and payments. Health notes are only visible to the teacher. Card payments are processed by Stripe; we never see or store card numbers.', 'olivia-studio' ) . '</p>' ) );
			}
		} );
	}

	public static function export( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$items   = array();
		$profile = array();
		foreach ( OYS_Customers::PROFILE_FIELDS as $k ) {
			$profile[] = array( 'name' => $k, 'value' => (string) get_user_meta( $user->ID, $k, true ) );
		}
		$profile[] = array( 'name' => 'waiver_accepted_at', 'value' => (string) get_user_meta( $user->ID, 'oys_waiver_at', true ) );
		$items[]   = array( 'group_id' => 'oys-profile', 'group_label' => __( 'Yoga profile', 'olivia-studio' ), 'item_id' => 'profile-' . $user->ID, 'data' => $profile );
		foreach ( OYS_Bookings::for_user( $user->ID, 'all' ) as $b ) {
			$items[] = array( 'group_id' => 'oys-bookings', 'group_label' => __( 'Bookings', 'olivia-studio' ), 'item_id' => 'booking-' . $b->id, 'data' => array(
				array( 'name' => __( 'Class', 'olivia-studio' ), 'value' => $b->title ?: oys_class_title( $b->class_slug ) ),
				array( 'name' => __( 'Date', 'olivia-studio' ), 'value' => oys_date( $b->starts_at ) ),
				array( 'name' => __( 'Status', 'olivia-studio' ), 'value' => $b->status ),
			) );
		}
		foreach ( OYS_Orders::for_user( $user->ID ) as $o ) {
			$items[] = array( 'group_id' => 'oys-orders', 'group_label' => __( 'Payments', 'olivia-studio' ), 'item_id' => 'order-' . $o->id, 'data' => array(
				array( 'name' => __( 'What', 'olivia-studio' ), 'value' => $o->description ),
				array( 'name' => __( 'Amount', 'olivia-studio' ), 'value' => oys_money( $o->amount_cents, $o->currency ) ),
				array( 'name' => __( 'Date', 'olivia-studio' ), 'value' => oys_date( $o->paid_at ?: $o->created_at ) ),
			) );
		}
		return array( 'data' => $items, 'done' => true );
	}

	public static function erase( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		foreach ( array_merge( OYS_Customers::PROFILE_FIELDS, array( 'oys_waiver_ip' ) ) as $k ) {
			delete_user_meta( $user->ID, $k );
		}
		global $wpdb;
		$wpdb->update( OYS_Install::table( 'private_requests' ), array( 'address' => '', 'notes' => '', 'preferred' => '' ), array( 'user_id' => $user->ID ) );
		return array(
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => array( __( 'Payment and booking records were kept for accounting.', 'olivia-studio' ) ),
			'done'           => true,
		);
	}
}
