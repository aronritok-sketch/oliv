<?php
/**
 * Bringing customers over from another booking system (first: Motibro's „Vendégek” export, Olívia, 16 November: „ezek
 * olivia vendégei. az onlinera importáld őket, húzz be minden adatot. minden mező feleljen meg a listának, legyen minden
 * infó meg”).
 *
 * Every column of the export is kept: the name, nickname, email and phone go where the site keeps them; the rest (Motibro
 * id, gender, date of birth, registration, first and last class, note, courses, membership, billing name and address,
 * tax number) as the customer's data, shown on their page in the admin. The customer's registration date becomes the
 * account's own.
 *
 * Nobody is emailed, nobody is subscribed to the newsletter (the export says nothing about consent) and no agreement is
 * marked as accepted; a guest becomes a customer of the site the day they log in (the password is set through „Forgot
 * your password?”). A guest who already has an account here keeps everything they entered; only empty fields are filled.
 * A guest without an email is kept too, with an account they can't log in to until an email is added. Running the same
 * file again changes nothing (the Motibro id finds the guest).
 *
 * The data never goes through the code repository: the file is uploaded in the admin (Studio → Customers → Import), or
 * sent to the REST route by a logged-in manager (application password).
 */

defined( 'ABSPATH' ) || exit;

class OYS_Import {

	/** Customers this request created while importing passes or visits. */
	private static $created = 0;

	/** The columns of the Motibro export → the field they fill. */
	const MOTIBRO = array(
		'Id'                           => 'ext_id',
		'Vezetéknév'                   => 'last_name',
		'Keresztnév'                   => 'first_name',
		'Becenév'                      => 'nickname',
		'Email'                        => 'email',
		'Telefonszám'                  => 'phone',
		'Nem'                          => 'gender',
		'Születési idő'                => 'birth_date',
		'Regisztráció időpontja'       => 'registered',
		'Első óralátogatás időpontja'  => 'first_visit',
		'Utolsó óralátogatás időpontja' => 'last_visit',
		'Megjegyzés'                   => 'note',
		'Kurzusok'                     => 'courses',
		'Aktív tanfolyamtagság'        => 'ext_membership',
		'Számlázási név'               => 'billing_name',
		'Ország'                       => 'billing_country',
		'Irányítószám'                 => 'billing_zip',
		'Város'                        => 'billing_city',
		'Számlázási cím'               => 'billing_address',
		'Adószám'                      => 'tax_number',
	);

	/** The imported fields kept as the customer's data (user meta oys_<field>), in the order the admin shows them. */
	const FIELDS = array( 'ext_source', 'ext_id', 'gender', 'birth_date', 'registered', 'first_visit', 'last_visit', 'courses', 'ext_membership', 'note', 'billing_name', 'billing_country', 'billing_zip', 'billing_city', 'billing_address', 'tax_number' );

	/** Dates of these fields: date only, or date and time. */
	const DATES = array( 'birth_date' => 'Y-m-d', 'registered' => 'Y-m-d', 'first_visit' => 'Y-m-d H:i', 'last_visit' => 'Y-m-d H:i' );

	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'oys/v1', '/customers/import', array(
				'methods'             => 'POST',
				'permission_callback' => fn() => current_user_can( 'oys_manage' ) || current_user_can( 'manage_options' ),
				'callback'            => function ( WP_REST_Request $req ) {
					$rows = $req->get_param( 'rows' );
					if ( ! is_array( $rows ) ) {
						return new WP_Error( 'oys_rows', 'rows: a list of rows (column name → value) is needed.', array( 'status' => 400 ) );
					}
					return self::import_any( $rows, (string) ( $req->get_param( 'source' ) ?: 'motibro' ), (bool) $req->get_param( 'dry_run' ), sanitize_key( (string) $req->get_param( 'kind' ) ), sanitize_text_field( (string) $req->get_param( 'online_until' ) ) );
				},
			) );
		} );
	}

	/** The labels of the imported fields in the admin. */
	public static function labels() {
		return array(
			'ext_source'      => __( 'Imported from', 'olivia-studio' ),
			'ext_id'          => __( 'ID there', 'olivia-studio' ),
			'gender'          => __( 'Gender', 'olivia-studio' ),
			'birth_date'      => __( 'Date of birth', 'olivia-studio' ),
			'registered'      => __( 'Registered there', 'olivia-studio' ),
			'first_visit'     => __( 'First class', 'olivia-studio' ),
			'last_visit'      => __( 'Last class', 'olivia-studio' ),
			'courses'         => __( 'Courses', 'olivia-studio' ),
			'ext_membership'  => __( 'Active course membership', 'olivia-studio' ),
			'note'            => __( 'Note', 'olivia-studio' ),
			'billing_name'    => __( 'Billing name', 'olivia-studio' ),
			'billing_country' => __( 'Country', 'olivia-studio' ),
			'billing_zip'     => __( 'Postcode', 'olivia-studio' ),
			'billing_city'    => __( 'City', 'olivia-studio' ),
			'billing_address' => __( 'Billing address', 'olivia-studio' ),
			'tax_number'      => __( 'Tax number', 'olivia-studio' ),
		);
	}

	/**
	 * Rows (column name → value, as in the export) → customers. Returns what happened: created, updated, unchanged,
	 * skipped (with the reason), and per row the email (or the id) and the outcome.
	 */
	public static function import( array $rows, $source = 'motibro', $dry_run = false ) {
		$source = sanitize_key( $source ) ?: 'motibro';
		$out    = array( 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'dry_run' => $dry_run, 'rows' => array() );
		foreach ( $rows as $i => $raw ) {
			$row = self::map( (array) $raw );
			$key = $row['email'] ?: ( $row['ext_id'] ? '#' . $row['ext_id'] : '' );
			if ( '' === trim( $row['first_name'] . $row['last_name'] ) && ! $row['email'] ) {
				$out['skipped']++;
				$out['rows'][] = array( $i + 2, $key, 'skipped: no name and no email' );
				continue;
			}
			$res = self::upsert( $row, $source, $dry_run );
			if ( is_wp_error( $res ) ) {
				$out['skipped']++;
				$out['rows'][] = array( $i + 2, $key, 'skipped: ' . $res->get_error_message() );
				continue;
			}
			$out[ $res ]++;
			$out['rows'][] = array( $i + 2, $key, $res );
		}
		return $out;
	}

	/** One row of the export → the fields, each cleaned; columns the import does not know are ignored. */
	public static function map( array $raw ) {
		$row = array_fill_keys( array_merge( array_values( self::MOTIBRO ), array( 'ext_source' ) ), '' );
		foreach ( $raw as $col => $val ) {
			$col = trim( (string) $col );
			if ( ! isset( self::MOTIBRO[ $col ] ) ) {
				continue;
			}
			$field         = self::MOTIBRO[ $col ];
			$row[ $field ] = self::clean( $field, $val );
		}
		return $row;
	}

	public static function clean( $field, $val ) {
		if ( null === $val || is_array( $val ) ) {
			return '';
		}
		$val = trim( (string) $val );
		if ( '' === $val ) {
			return '';
		}
		if ( 'email' === $field ) {
			$val = strtolower( sanitize_email( $val ) );
			return is_email( $val ) ? $val : '';
		}
		if ( in_array( $field, array( 'ext_id', 'billing_zip' ), true ) && is_numeric( $val ) ) {
			return (string) (int) round( (float) $val ); // 3534.0 → 3534
		}
		if ( 'phone' === $field && preg_match( '/^(\d{8,12})\.0+$/', $val, $m ) ) {
			// Motibro wrote some numbers as decimals: the leading 0 lost and „.0” added (6301234567.0 = 06301234567)
			return ( preg_match( '/^[1-9]\d{9}$/', $m[1] ) && '6' === $m[1][0] ? '0' : '' ) . $m[1];
		}
		if ( isset( self::DATES[ $field ] ) ) {
			return self::date( $val, self::DATES[ $field ] );
		}
		return 'courses' === $field || 'note' === $field ? sanitize_textarea_field( $val ) : sanitize_text_field( $val );
	}

	/** „2025.08.29.”, „2025-08-29 18:30:00”, an Excel day number (45898.77) → 2025-08-29 (18:30); else as it is. */
	public static function date( $val, $format ) {
		if ( is_numeric( $val ) && (float) $val > 0 && (float) $val < 80000 ) { // a date cell of a spreadsheet
			$ts = (int) round( ( (float) $val - 25569 ) * 86400 ); // Excel's day 0 is 1899-12-30
			return gmdate( $format, $ts );
		}
		$norm = preg_replace( '/^(\d{4})\.\s*(\d{1,2})\.\s*(\d{1,2})\.?/', '$1-$2-$3', $val );
		$ts   = strtotime( (string) $norm . ( str_contains( (string) $norm, ':' ) ? '' : ' 00:00:00' ) . ' UTC' );
		return false === $ts ? sanitize_text_field( $val ) : gmdate( $format, $ts );
	}

	/** Create the customer, or fill an existing one; returns created / updated / unchanged, or an error. */
	private static function upsert( array $row, $source, $dry_run ) {
		$user = null;
		if ( $row['ext_id'] ) {
			$found = get_users( array( 'meta_key' => 'oys_ext_id', 'meta_value' => $row['ext_id'], 'number' => 1, 'fields' => 'ID' ) );
			$user  = $found ? get_userdata( (int) $found[0] ) : null;
			$user  = $user && get_user_meta( $user->ID, 'oys_ext_source', true ) === $source ? $user : null;
		}
		if ( ! $user && $row['email'] ) {
			$user = get_user_by( 'email', $row['email'] ) ?: null;
		}
		$row['ext_source'] = $source;
		if ( $user && ( user_can( $user, 'manage_options' ) || user_can( $user, 'oys_manage' ) ) ) {
			return new WP_Error( 'oys_staff', 'the email belongs to a staff account' );
		}

		if ( ! $user ) {
			if ( $dry_run ) {
				return 'created';
			}
			$login = $row['email'] ?: $source . '-' . ( $row['ext_id'] ?: wp_generate_password( 8, false ) );
			$args  = array(
				'user_login'   => $login,
				'user_email'   => $row['email'],
				'user_pass'    => wp_generate_password( 24 ),
				'first_name'   => $row['first_name'],
				'last_name'    => $row['last_name'],
				'nickname'     => $row['nickname'] ?: trim( $row['first_name'] . ' ' . $row['last_name'] ),
				'display_name' => trim( $row['first_name'] . ' ' . $row['last_name'] ) ?: $login,
				'role'         => 'oys_customer',
			);
			if ( $row['registered'] && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $row['registered'] ) ) {
				$args['user_registered'] = get_gmt_from_date( $row['registered'] . ' 12:00:00' );
			}
			$id = wp_insert_user( wp_slash( $args ) );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			if ( $row['phone'] ) {
				update_user_meta( $id, 'oys_phone', $row['phone'] );
			}
			foreach ( self::FIELDS as $f ) {
				if ( '' !== $row[ $f ] ) {
					update_user_meta( $id, 'oys_' . $f, $row[ $f ] );
				}
			}
			update_user_meta( $id, 'oys_imported_at', current_time( 'mysql', true ) );
			return 'created';
		}

		// an existing account: what the guest entered here stays; empty fields are filled, the imported data is kept
		$changes = array();
		$core    = array();
		foreach ( array( 'first_name', 'last_name' ) as $f ) {
			if ( '' === (string) get_user_meta( $user->ID, $f, true ) && $row[ $f ] ) {
				$core[ $f ] = $row[ $f ];
			}
		}
		if ( $row['nickname'] && in_array( (string) get_user_meta( $user->ID, 'nickname', true ), array( '', $user->user_login, $user->user_email ), true ) ) {
			$core['nickname'] = $row['nickname'];
		}
		if ( ! $user->user_email && $row['email'] && ! email_exists( $row['email'] ) ) {
			$core['user_email'] = $row['email'];
		}
		if ( '' === (string) get_user_meta( $user->ID, 'oys_phone', true ) && $row['phone'] ) {
			$changes['oys_phone'] = $row['phone'];
		}
		foreach ( self::FIELDS as $f ) {
			if ( '' !== $row[ $f ] && (string) get_user_meta( $user->ID, 'oys_' . $f, true ) !== $row[ $f ] ) {
				$changes[ 'oys_' . $f ] = $row[ $f ];
			}
		}
		if ( ! $core && ! $changes ) {
			return 'unchanged';
		}
		if ( $dry_run ) {
			return 'updated';
		}
		if ( $core ) {
			$res = wp_update_user( wp_slash( array_merge( array( 'ID' => $user->ID ), $core ) ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		foreach ( $changes as $k => $v ) {
			update_user_meta( $user->ID, $k, $v );
		}
		update_user_meta( $user->ID, 'oys_imported_at', current_time( 'mysql', true ) );
		return 'updated';
	}

	/* ------------------------------------------------------------------------------------------------------------ *
	 * Áron, 16 November: „Ezt meg a vendégekhez dobd hozzá” – Motibro's „Érvényes bérletek” (valid passes) and
	 * „Óralátogatások” (class visits) exports.
	 *
	 * Visits: every class becomes a past class here (same time, name and place), every visit a booking marked as came
	 * („Earlier system”, the Motibro pass and price in its note). So each customer's history is here: how many classes,
	 * the first and last one, the regulars, those drifting away, „N. óra” and „Első óra” on the attendance sheet, the
	 * monthly reports. Written straight into the tables: no email, no notification, no seat or pass logic.
	 *
	 * Passes: what is left on each valid pass is collected for the studio, not bookable online (OYS_Held); with „online
	 * until” it becomes online credits until that day. No email. Importing a newer export updates the same entries.
	 *
	 * The guest is found by the Motibro id (the customer import keeps it), else by the email; a guest not here yet is
	 * created as in the customer import. Running the same file again changes nothing.
	 * ------------------------------------------------------------------------------------------------------------ */

	/** Which export this is, from its columns: 'passes', 'visits' or 'customers'. */
	public static function detect( array $rows ) {
		$cols = $rows ? array_map( 'trim', array_keys( (array) reset( $rows ) ) ) : array();
		if ( in_array( 'Hátralévő alkalmak', $cols, true ) ) {
			return 'passes';
		}
		if ( in_array( 'Óra időpontja', $cols, true ) ) {
			return 'visits';
		}
		return 'customers';
	}

	/** Any of the three exports. */
	public static function import_any( array $rows, $source = 'motibro', $dry_run = false, $kind = '', $online_until = '' ) {
		$kind = $kind ?: self::detect( $rows );
		if ( 'passes' === $kind ) {
			return array_merge( array( 'kind' => 'passes' ), self::passes( $rows, $source, $dry_run, $online_until ) );
		}
		if ( 'visits' === $kind ) {
			return array_merge( array( 'kind' => 'visits' ), self::visits( $rows, $source, $dry_run ) );
		}
		return array_merge( array( 'kind' => 'customers' ), self::import( $rows, $source, $dry_run ) );
	}

	/** The guest of a row: by the id there, else the email; created (as in the customer import) when $create. */
	private static function guest( $ext_id, $email, $name, $source, $dry_run, $create = true, $registered = '' ) {
		static $cache = array();
		$key = $source . '|' . $ext_id . '|' . $email . '|' . ( $dry_run ? 'd' : 'w' ) . ( $create ? 'c' : 'n' );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$user = null;
		if ( $ext_id ) {
			$found = get_users( array( 'meta_key' => 'oys_ext_id', 'meta_value' => $ext_id, 'number' => 5, 'fields' => 'ID' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( $found as $id ) {
				if ( get_user_meta( (int) $id, 'oys_ext_source', true ) === $source ) {
					$user = get_userdata( (int) $id );
					break;
				}
			}
		}
		if ( ! $user && $email ) {
			$user = get_user_by( 'email', $email ) ?: null;
		}
		if ( $user && ( user_can( $user, 'manage_options' ) || user_can( $user, 'oys_manage' ) ) ) {
			return $cache[ $key ] = new WP_Error( 'oys_staff', 'the email belongs to a staff account' );
		}
		if ( $user ) {
			return $cache[ $key ] = (int) $user->ID;
		}
		if ( ! $create ) {
			return $cache[ $key ] = new WP_Error( 'oys_guest', 'no customer with this email' );
		}
		if ( $dry_run ) {
			return $cache[ $key ] = 0; // would be created
		}
		// „Vezetéknév Keresztnév” as Motibro writes it
		$parts = preg_split( '/\s+/u', trim( (string) $name ), 2 );
		$res   = self::upsert( array_merge( array_fill_keys( array_merge( array_values( self::MOTIBRO ), array( 'ext_source' ) ), '' ), array(
			'ext_id'     => (string) $ext_id,
			'email'      => (string) $email,
			'last_name'  => count( $parts ) > 1 ? $parts[0] : '',
			'first_name' => count( $parts ) > 1 ? $parts[1] : (string) ( $parts[0] ?? '' ),
			'registered' => (string) $registered, // their first class there, so the reports don't count them as new today
		) ), $source, false );
		if ( is_wp_error( $res ) ) {
			return $cache[ $key ] = $res;
		}
		self::$created++;
		unset( $cache[ $key ] );
		return self::guest( $ext_id, $email, $name, $source, false, false );
	}

	/** „Óralátogatások” → past classes and the visits as bookings marked as came. */
	public static function visits( array $rows, $source = 'motibro', $dry_run = false ) {
		global $wpdb;
		$source = sanitize_key( $source ) ?: 'motibro';
		$st     = OYS_Install::table( 'sessions' );
		$bt     = OYS_Install::table( 'bookings' );
		$out    = array( 'classes_created' => 0, 'classes_found' => 0, 'visits_added' => 0, 'visits_there' => 0, 'customers_created' => 0, 'skipped' => 0, 'dry_run' => $dry_run, 'rows' => array() );
		$groups = array();
		$before = self::$created;
		$new    = array();
		$since  = array();
		foreach ( $rows as $i => $r ) {
			$r     = array_map( fn( $v ) => is_scalar( $v ) ? trim( (string) $v ) : '', (array) $r );
			$when  = self::date( $r['Óra időpontja'] ?? '', 'Y-m-d H:i' );
			$title = sanitize_text_field( $r['Óra elnevezése'] ?? '' ) ?: sanitize_text_field( $r['Kurzus neve'] ?? '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $when ) || '' === $title ) {
				$out['skipped']++;
				$out['rows'][] = array( $i + 2, '', 'skipped: no class time or name' );
				continue;
			}
			$place                     = sanitize_text_field( $r['Helyszín'] ?? '' );
			$who                       = ( $r['Vendég ID'] ?? '' ) . '|' . strtolower( (string) ( $r['Email'] ?? '' ) );
			$since[ $who ]             = min( $since[ $who ] ?? '9999', substr( $when, 0, 10 ) );
			$groups[ "$when|$title|$place" ][] = array( $i, $r, $when, $title, $place );
		}
		foreach ( $groups as $list ) {
			[ , $first, $when, $title, $place ] = $list[0];
			$room    = sanitize_text_field( $first['Terem'] ?? '' );
			$online  = 'online' === strtolower( $place );
			$minutes = preg_match( '/slow\s*flow|90\s*p/iu', $title . ' ' . ( $first['Bérletfelhasználás'] ?? '' ) ) ? 90 : 60;
			$start   = oys_local_to_utc( $when . ':00' );
			$loc     = $online ? '' : trim( $place . ( $room ? ' · ' . $room : '' ) );
			$sid     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $st WHERE starts_at = %s AND title = %s AND location = %s AND format = %s LIMIT 1", $start, $title, $loc, $online ? 'online' : 'studio' ) );
			if ( $sid ) {
				$out['classes_found']++;
			} elseif ( ! $dry_run ) {
				$event = ! preg_match( '/hatha|slow\s*flow|online\s*jóga/iu', $title ) || preg_match( '/\d{4}\.\d{2}\.\d{2}|workshop|hangfürdő|nőnapi/iu', $title );
				$wpdb->insert( $st, array(
					'kind'            => $event ? 'event' : 'group',
					'class_slug'      => '',
					'title'           => $title,
					'starts_at'       => $start,
					'ends_at'         => gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC' ) + $minutes * MINUTE_IN_SECONDS ),
					'capacity'        => count( $list ),
					'booked'          => 0,
					'location'        => $loc,
					'format'          => $online ? 'online' : 'studio',
					'price_cents'     => 0,
					'credits_allowed' => 0,
					'pay_later'       => 0,
					'teacher_id'      => 0,
					'status'          => 'scheduled',
					'created_at'      => oys_now(),
				) );
				$sid = (int) $wpdb->insert_id;
				$out['classes_created']++;
			} else {
				$out['classes_created']++;
			}
			foreach ( $list as [ $i, $r ] ) {
				$ext   = is_numeric( $r['Vendég ID'] ?? '' ) ? (string) (int) round( (float) $r['Vendég ID'] ) : sanitize_text_field( $r['Vendég ID'] ?? '' );
				$email = self::clean( 'email', $r['Email'] ?? '' );
				$known = $dry_run ? self::guest( $ext, $email, '', $source, true, false ) : null;
				$uid   = $dry_run ? $known : self::guest( $ext, $email, $r['Vendég neve'] ?? '', $source, false, true, $since[ ( $r['Vendég ID'] ?? '' ) . '|' . strtolower( (string) ( $r['Email'] ?? '' ) ) ] ?? '' );
				if ( is_wp_error( $uid ) && ! ( $dry_run && 'oys_guest' === $uid->get_error_code() ) ) {
					$out['skipped']++;
					$out['rows'][] = array( $i + 2, $email ?: '#' . $ext, 'skipped: ' . $uid->get_error_message() );
					continue;
				}
				if ( $dry_run && is_wp_error( $uid ) ) {
					$new[ $ext . '|' . $email ] = 1; // would be created
					$out['visits_added']++;
					continue;
				}
				$there = $sid ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $bt WHERE session_id = %d AND user_id = %d AND guest_of = 0 LIMIT 1", $sid, $uid ) ) : 0;
				if ( $there ) {
					$out['visits_there']++;
					continue;
				}
				$out['visits_added']++;
				if ( $dry_run ) {
					continue;
				}
				$used  = sanitize_text_field( $r['Bérletfelhasználás'] ?? '' );
				$money = (int) round( (float) ( $r['Órára jutó bevétel (HUF)'] ?? 0 ) );
				$wpdb->insert( $bt, array(
					'session_id'    => $sid,
					'user_id'       => (int) $uid,
					'status'        => 'attended',
					'paid_with'     => 'import',
					'mode'          => $online ? 'online' : 'studio',
					'note'          => trim( ucfirst( $source ) . ': ' . $used . ( $money > 0 ? ' · ' . $money . ' Ft' : '' ) ),
					'created_at'    => $start,
					'checked_in_at' => $start,
				) );
			}
			if ( ! $dry_run && $sid ) {
				OYS_Schedule::recount( $sid );
				$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT booked FROM $st WHERE id = %d", $sid ) );
				$wpdb->query( $wpdb->prepare( "UPDATE $st SET capacity = GREATEST(capacity, %d) WHERE id = %d", $n, $sid ) );
			}
		}
		$out['customers_created'] = $dry_run ? count( $new ) : self::$created - $before;
		return $out;
	}

	/**
	 * „Érvényes bérletek” → the sessions left, collected for the studio and not bookable online (OYS_Held; Olívia,
	 * 16 November). With $online_until (Y-m-d) what is left becomes online credits until that day, or the pass's own
	 * later day (the export of the end of November: „ha abban az exportban még marad alkalmuk, hosszabbítsd meg a
	 * bérletüket január 27-ig”). Entries of earlier exports not in this one are flagged.
	 */
	public static function passes( array $rows, $source = 'motibro', $dry_run = false, $online_until = '' ) {
		global $wpdb;
		$source = sanitize_key( $source ) ?: 'motibro';
		$online = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $online_until ) ? (string) $online_until : '';
		$t      = OYS_Install::table( 'passes' );
		$out    = array( 'mode' => $online ? 'online' : 'held', 'online_until' => $online, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'credits' => 0, 'locked' => 0, 'missing' => 0, 'dry_run' => $dry_run, 'rows' => array() );
		$seen   = array();
		$keys   = array();
		foreach ( $rows as $i => $r ) {
			$r     = array_map( fn( $v ) => is_scalar( $v ) ? trim( (string) $v ) : '', (array) $r );
			$email = self::clean( 'email', $r['Email'] ?? '' );
			$name  = sanitize_text_field( $r['Bérlet neve'] ?? '' ) ?: __( 'Class pass', 'olivia-studio' );
			$exp   = self::date( $r['Lejárat'] ?? '', 'Y-m-d' );
			$left  = max( 0, (int) ( $r['Hátralévő alkalmak'] ?? 0 ) );
			$used  = max( 0, (int) ( $r['Felhasznált alkalmak'] ?? 0 ) );
			$lock  = max( 0, (int) ( $r['Zárolt alkalmak'] ?? 0 ) );
			if ( ! $email ) {
				$out['skipped']++;
				$out['rows'][] = array( $i + 2, '', 'skipped: no email' );
				continue;
			}
			$uid = self::guest( '', $email, $r['Vendég'] ?? '', $source, $dry_run );
			if ( is_wp_error( $uid ) ) {
				$out['skipped']++;
				$out['rows'][] = array( $i + 2, $email, 'skipped: ' . $uid->get_error_message() );
				continue;
			}
			$base          = $email . '|' . $name . '|' . $exp;
			$seen[ $base ] = ( $seen[ $base ] ?? 0 ) + 1;
			$key           = $source . '-' . substr( md5( $base . '|' . $seen[ $base ] ), 0, 16 );
			$keys[]        = $key;
			$out['credits'] += $left;
			$out['locked']  += $lock;
			if ( ! $uid ) { // check only, a customer to be created
				$out['created']++;
				$out['rows'][] = array( $i + 2, $email, 'created' );
				continue;
			}
			$res = OYS_Held::put( $key, $uid, array( 'name' => $name, 'used' => $used, 'locked' => $lock, 'left' => $left, 'expires' => $exp, 'source' => $source ), $dry_run );
			if ( $online ) {
				$pass = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d AND product_id = %s LIMIT 1", $uid, $key ) );
				$res  = $pass ? ( (int) $pass->credits_left === $left && wp_date( 'Y-m-d', oys_ts( (string) $pass->expires_at ) ) === max( $online, $exp ) ? 'unchanged' : 'updated' ) : ( $left > 0 ? 'created' : 'unchanged' );
				if ( ! $dry_run && 'unchanged' !== $res ) {
					if ( $left > 0 ) {
						OYS_Held::to_online( $key, $online );
					} elseif ( $pass ) {
						$wpdb->update( $t, array( 'credits_left' => 0 ), array( 'id' => (int) $pass->id ) );
					}
				}
			}
			$out[ $res ]++;
			$out['rows'][] = array( $i + 2, $email, $res );
		}
		if ( ! $dry_run ) {
			$out['missing'] = OYS_Held::flag_missing( $keys, $source );
		}
		return $out;
	}

	/* ------------------------------------------------------------------------------------------------------------ *
	 * Reading the file: .xlsx (the first sheet) or .csv, the first row is the header.
	 * ------------------------------------------------------------------------------------------------------------ */

	public static function read_file( $path, $name = '' ) {
		$ext = strtolower( pathinfo( $name ?: $path, PATHINFO_EXTENSION ) );
		return 'csv' === $ext ? self::read_csv( $path ) : self::read_xlsx( $path );
	}

	public static function read_csv( $path ) {
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			return new WP_Error( 'oys_file', __( 'The file could not be read.', 'olivia-studio' ) );
		}
		$first = (string) fgets( $fh );
		$sep   = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';
		rewind( $fh );
		$head = null;
		$rows = array();
		while ( ( $r = fgetcsv( $fh, 0, $sep, '"', '\\' ) ) !== false ) {
			if ( null === $head ) {
				$head = array_map( fn( $h ) => trim( (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ), $r );
				continue;
			}
			if ( array_filter( $r, fn( $v ) => '' !== trim( (string) $v ) ) ) {
				$rows[] = array_combine( $head, array_pad( array_slice( $r, 0, count( $head ) ), count( $head ), '' ) );
			}
		}
		fclose( $fh );
		return $rows;
	}

	/** The first sheet of an .xlsx, without any library: the shared strings and the cells of sheet1.xml. */
	public static function read_xlsx( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'oys_zip', __( 'This server cannot open .xlsx files; save the sheet as .csv and upload that.', 'olivia-studio' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'oys_file', __( 'The file could not be read.', 'olivia-studio' ) );
		}
		$strings = array();
		$ss      = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( $ss ) {
			$x = simplexml_load_string( $ss, 'SimpleXMLElement', LIBXML_NONET );
			foreach ( $x ? $x->si : array() as $si ) {
				$t = '';
				if ( isset( $si->t ) ) {
					$t = (string) $si->t;
				}
				foreach ( $si->r ?? array() as $run ) {
					$t .= (string) $run->t;
				}
				$strings[] = $t;
			}
		}
		$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();
		$x = $sheet ? simplexml_load_string( $sheet, 'SimpleXMLElement', LIBXML_NONET ) : null;
		if ( ! $x ) {
			return new WP_Error( 'oys_file', __( 'The file could not be read.', 'olivia-studio' ) );
		}
		$grid = array();
		foreach ( $x->sheetData->row as $r ) {
			$line = array();
			foreach ( $r->c as $c ) {
				$ref = (string) $c['r'];
				$col = self::col_index( preg_replace( '/\d+/', '', $ref ) );
				$t   = (string) $c['t'];
				$v   = isset( $c->v ) ? (string) $c->v : '';
				if ( 's' === $t ) {
					$v = $strings[ (int) $v ] ?? '';
				} elseif ( 'inlineStr' === $t ) {
					$v = (string) $c->is->t;
				}
				$line[ $col ] = $v;
			}
			$grid[] = $line;
		}
		if ( ! $grid ) {
			return array();
		}
		$head = $grid[0];
		$rows = array();
		foreach ( array_slice( $grid, 1 ) as $line ) {
			if ( ! array_filter( $line, fn( $v ) => '' !== trim( (string) $v ) ) ) {
				continue;
			}
			$row = array();
			foreach ( $head as $i => $h ) {
				$row[ trim( (string) $h ) ] = $line[ $i ] ?? '';
			}
			$rows[] = $row;
		}
		return $rows;
	}

	private static function col_index( $letters ) {
		$n = 0;
		foreach ( str_split( strtoupper( (string) $letters ) ) as $ch ) {
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}
		return $n - 1;
	}
}
