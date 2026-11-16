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
					return self::import( $rows, (string) ( $req->get_param( 'source' ) ?: 'motibro' ), (bool) $req->get_param( 'dry_run' ) );
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
