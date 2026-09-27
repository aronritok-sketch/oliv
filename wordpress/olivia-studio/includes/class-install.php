<?php
/**
 * Database tables, roles, default settings and the pages the booking flow needs.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Install {

	public static function activate() {
		self::create_tables();
		self::add_roles();
		OYS_Settings::ensure_defaults();
		OYS_Products::ensure_defaults();
		self::create_pages();
		update_option( 'oys_db_version', OYS_DB_VERSION );
		OYS_Cron::schedule();
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'oys_db_version' ) !== OYS_DB_VERSION ) {
			self::activate();
			OYS_Products::add_missing_kinds();
			// v3: weekly dates remember the slot they were created for, so moving one date doesn't re-create it.
			global $wpdb;
			$wpdb->query( 'UPDATE ' . self::table( 'sessions' ) . ' SET tpl_slot = starts_at WHERE template_id > 0 AND tpl_slot IS NULL' );
			// v4: every booking of an online class is an online booking.
			$wpdb->query( 'UPDATE ' . self::table( 'bookings' ) . ' b JOIN ' . self::table( 'sessions' ) . " s ON s.id = b.session_id SET b.mode = 'online' WHERE s.format = 'online' AND b.mode <> 'online'" );
		}
	}

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'oys_' . $name;
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		// Weekly recurring timetable; sessions are generated from it several weeks ahead.
		dbDelta( 'CREATE TABLE ' . self::table( 'templates' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			class_slug varchar(100) NOT NULL,
			weekday tinyint(1) unsigned NOT NULL,
			start_time char(5) NOT NULL,
			duration_min smallint(5) unsigned NOT NULL DEFAULT 60,
			capacity smallint(5) unsigned NOT NULL DEFAULT 12,
			location varchar(255) NOT NULL DEFAULT '',
			format varchar(10) NOT NULL DEFAULT 'studio',
			online_url varchar(255) NOT NULL DEFAULT '',
			price_cents int(10) unsigned NOT NULL DEFAULT 0,
			online_capacity smallint(5) unsigned NOT NULL DEFAULT 0,
			online_price_cents int(10) unsigned NOT NULL DEFAULT 0,
			pricing varchar(10) NOT NULL DEFAULT 'fixed',
			pay_later tinyint(1) unsigned NOT NULL DEFAULT 1,
			note varchar(255) NOT NULL DEFAULT '',
			active tinyint(1) unsigned NOT NULL DEFAULT 1,
			valid_from date NULL,
			PRIMARY KEY  (id)
		) $c;" );

		// One bookable occurrence. `booked` counts confirmed seats plus unexpired payment holds.
		dbDelta( 'CREATE TABLE ' . self::table( 'sessions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(20) NOT NULL DEFAULT 'group',
			class_slug varchar(100) NOT NULL DEFAULT '',
			title varchar(255) NOT NULL DEFAULT '',
			description text NULL,
			starts_at datetime NOT NULL,
			ends_at datetime NOT NULL,
			capacity smallint(5) unsigned NOT NULL DEFAULT 12,
			booked smallint(5) unsigned NOT NULL DEFAULT 0,
			location varchar(255) NOT NULL DEFAULT '',
			format varchar(10) NOT NULL DEFAULT 'studio',
			online_url varchar(255) NOT NULL DEFAULT '',
			price_cents int(10) unsigned NOT NULL DEFAULT 0,
			online_capacity smallint(5) unsigned NOT NULL DEFAULT 0,
			online_booked smallint(5) unsigned NOT NULL DEFAULT 0,
			online_price_cents int(10) unsigned NOT NULL DEFAULT 0,
			zoom_meeting_id varchar(32) NOT NULL DEFAULT '',
			zoom_join_url varchar(500) NOT NULL DEFAULT '',
			zoom_password varchar(64) NOT NULL DEFAULT '',
			credits_allowed tinyint(1) unsigned NOT NULL DEFAULT 1,
			pricing varchar(10) NOT NULL DEFAULT 'fixed',
			pay_later tinyint(1) unsigned NOT NULL DEFAULT 1,
			note varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'scheduled',
			template_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tpl_slot datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY starts_at (starts_at),
			KEY template_start (template_id,starts_at)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'bookings' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			paid_with varchar(20) NOT NULL DEFAULT '',
			pass_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			membership_id bigint(20) unsigned NOT NULL DEFAULT 0,
			guest_of bigint(20) unsigned NOT NULL DEFAULT 0,
			guest_name varchar(190) NOT NULL DEFAULT '',
			guest_email varchar(190) NOT NULL DEFAULT '',
			mode varchar(10) NOT NULL DEFAULT 'studio',
			join_url varchar(500) NOT NULL DEFAULT '',
			zoom_registrant_id varchar(64) NOT NULL DEFAULT '',
			due_cents int(10) unsigned NOT NULL DEFAULT 0,
			collected_with varchar(20) NOT NULL DEFAULT '',
			hold_expires datetime NULL,
			reminder_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
			reminder2_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
			join_reminder_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
			note text NULL,
			created_at datetime NOT NULL,
			cancelled_at datetime NULL,
			checked_in_at datetime NULL,
			PRIMARY KEY  (id),
			KEY session_status (session_id,status),
			KEY user_status (user_id,status),
			KEY guest_of (guest_of),
			KEY order_id (order_id)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'waitlist' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			notified_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_user (session_id,user_id)
		) $c;" );

		// Class passes and studio credit. kind = class | online | private.
		dbDelta( 'CREATE TABLE ' . self::table( 'passes' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			product_id varchar(60) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			kind varchar(20) NOT NULL DEFAULT 'class',
			credits_total smallint(5) unsigned NOT NULL DEFAULT 1,
			credits_left smallint(5) unsigned NOT NULL DEFAULT 1,
			expires_at datetime NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source varchar(20) NOT NULL DEFAULT 'purchase',
			expiry_notice_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_kind (user_id,kind)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'orders' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			type varchar(20) NOT NULL,
			product_id varchar(60) NOT NULL DEFAULT '',
			session_id bigint(20) unsigned NOT NULL DEFAULT 0,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			description varchar(255) NOT NULL DEFAULT '',
			amount_cents int(10) unsigned NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT 'usd',
			status varchar(20) NOT NULL DEFAULT 'pending',
			stripe_session_id varchar(255) NULL,
			stripe_payment_intent varchar(255) NOT NULL DEFAULT '',
			stripe_invoice_id varchar(255) NULL,
			receipt_url varchar(500) NOT NULL DEFAULT '',
			meta longtext NULL,
			created_at datetime NOT NULL,
			paid_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_session_id (stripe_session_id),
			UNIQUE KEY stripe_invoice_id (stripe_invoice_id),
			KEY user_id (user_id),
			KEY status (status)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'gift_cards' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(32) NOT NULL,
			product_id varchar(60) NOT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			purchaser_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recipient_name varchar(190) NOT NULL DEFAULT '',
			recipient_email varchar(190) NOT NULL DEFAULT '',
			message text NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			redeemed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			redeemed_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'private_requests' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			duration_min smallint(5) unsigned NOT NULL DEFAULT 60,
			people tinyint(3) unsigned NOT NULL DEFAULT 1,
			location_type varchar(30) NOT NULL DEFAULT '',
			address varchar(255) NOT NULL DEFAULT '',
			preferred text NULL,
			notes text NULL,
			status varchar(20) NOT NULL DEFAULT 'new',
			session_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			price_cents int(10) unsigned NOT NULL DEFAULT 0,
			admin_message text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status)
		) $c;" );

		// Recurring memberships (Stripe subscriptions). Periods mirror the subscription.
		dbDelta( 'CREATE TABLE ' . self::table( 'memberships' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			product_id varchar(60) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			classes_per_period smallint(5) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'incomplete',
			cancel_at_period_end tinyint(1) unsigned NOT NULL DEFAULT 0,
			current_period_start datetime NULL,
			current_period_end datetime NULL,
			stripe_subscription_id varchar(255) NULL,
			stripe_customer_id varchar(255) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NULL,
			ended_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_subscription_id (stripe_subscription_id),
			KEY user_status (user_id,status)
		) $c;" );

		// Processed Stripe webhook events: makes webhook handling idempotent.
		dbDelta( 'CREATE TABLE ' . self::table( 'stripe_events' ) . " (
			event_id varchar(255) NOT NULL,
			type varchar(100) NOT NULL DEFAULT '',
			received_at datetime NOT NULL,
			PRIMARY KEY  (event_id)
		) $c;" );

		// Messages the studio sent to the people booked into a class (Studio → roster).
		dbDelta( 'CREATE TABLE ' . self::table( 'messages' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sender_id bigint(20) unsigned NOT NULL DEFAULT 0,
			subject varchar(255) NOT NULL DEFAULT '',
			body text NULL,
			recipients int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id)
		) $c;" );
	}

	public static function add_roles() {
		add_role( 'oys_customer', __( 'Studio customer', 'olivia-studio' ), array( 'read' => true ) );
		// A manager can run the studio (schedule, rosters, customers) without full site admin rights.
		add_role( 'oys_manager', __( 'Studio manager', 'olivia-studio' ), array( 'read' => true, 'oys_manage' => true, 'upload_files' => true ) );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'oys_manage' );
		}
	}

	/** Pages the booking flow links to. Existing pages (by option) are kept. */
	public static function create_pages() {
		$pages = array(
			'book'    => array( __( 'Book', 'olivia-studio' ), 'book', '[oys_book]' ),
			'account' => array( __( 'My account', 'olivia-studio' ), 'account', '[oys_account]' ),
			'gifts'   => array( __( 'Gift cards', 'olivia-studio' ), 'gift-cards', "<p>Give someone a class pass or a private session. The gift card arrives by email with a code they redeem in their account.</p>\n[oys_gift_cards]" ),
		);
		foreach ( $pages as $key => $p ) {
			$id = (int) get_option( 'oys_page_' . $key );
			if ( $id && get_post( $id ) ) {
				continue;
			}
			$existing = get_page_by_path( $p[1] );
			if ( $existing ) {
				update_option( 'oys_page_' . $key, $existing->ID );
				continue;
			}
			$id = wp_insert_post( array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $p[0],
				'post_name'    => $p[1],
				'post_content' => $p[2],
			) );
			if ( $id && ! is_wp_error( $id ) ) {
				update_option( 'oys_page_' . $key, $id );
			}
		}
	}
}
