<?php
/**
 * Site languages: English (no prefix), Hungarian (/hu/) and Spanish (/es/).
 *
 * The language comes from the first path segment after the site's home path. The prefix is taken
 * off the request before WordPress parses it, so every page, class and post exists in all three
 * languages under the same slug. Links built with home_url() keep the prefix, the locale follows
 * the language (so the theme's and this plugin's texts come from languages/*.mo), and forms posted
 * to admin-post.php or admin-ajax.php keep the language of the page they were sent from.
 *
 * A signed-in customer's language is remembered (user meta `oys_lang`) so emails, reminders and
 * receipts go out in the language they use the site in.
 */

defined( 'ABSPATH' ) || exit;

class OYS_I18n {

	const LANGS = array(
		'en' => array( 'locale' => 'en_US', 'name' => 'English', 'native' => 'English', 'short' => 'EN', 'html' => 'en-US' ),
		'hu' => array( 'locale' => 'hu_HU', 'name' => 'Hungarian', 'native' => 'Magyar', 'short' => 'HU', 'html' => 'hu-HU' ),
		'es' => array( 'locale' => 'es_ES', 'name' => 'Spanish', 'native' => 'Español', 'short' => 'ES', 'html' => 'es-ES' ),
	);
	const DEFAULT_LANG = 'en';
	const META         = 'oys_lang';

	private static $lang      = self::DEFAULT_LANG;
	private static $uri       = '';
	private static $home_path = '';
	private static $active    = false;

	/** Runs as soon as the plugin file loads: before the theme, before WordPress parses the request. */
	public static function boot() {
		self::$uri       = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		self::$home_path = rtrim( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ), '/' );
		if ( defined( 'WP_CLI' ) || wp_doing_cron() ) {
			return;
		}
		$script = basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) );
		if ( is_admin() ) {
			// Real admin screens stay in the user's admin language; form handlers follow the page they came from.
			if ( in_array( $script, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
				$from = self::lang_from_url( (string) wp_get_raw_referer() );
				$post = sanitize_key( $_POST['oys_lang'] ?? ( $_POST['lang'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
				self::set( isset( self::LANGS[ $post ] ) ? $post : $from );
			}
			return;
		}
		$path = (string) wp_parse_url( self::$uri, PHP_URL_PATH );
		$rest = substr( $path, strlen( self::$home_path ) );
		if ( preg_match( '#^/(' . implode( '|', array_keys( self::others() ) ) . ')(/|$)#', $rest, $m ) ) {
			$_SERVER['REQUEST_URI'] = self::$home_path . '/' . ltrim( substr( self::$uri, strlen( self::$home_path ) + strlen( $m[1] ) + 1 ), '/' );
			self::set( $m[1] );
		} elseif ( 'wp-login.php' === $script ) {
			self::set( self::lang_from_url( (string) wp_get_raw_referer() ) );
		}
	}

	private static function set( $lang ) {
		if ( ! isset( self::LANGS[ $lang ] ) || self::DEFAULT_LANG === $lang ) {
			return;
		}
		self::$lang   = $lang;
		self::$active = true;
		add_filter( 'locale', array( __CLASS__, 'locale_filter' ), 1 );
		add_filter( 'home_url', array( __CLASS__, 'home_url_filter' ), 10, 2 );
		add_filter( 'redirect_canonical', '__return_false' );
		add_filter( 'language_attributes', fn() => 'lang="' . esc_attr( self::LANGS[ self::$lang ]['html'] ) . '"' );
	}

	public static function locale_filter() {
		return self::LANGS[ self::$lang ]['locale'];
	}

	public static function home_url_filter( $url, $path ) {
		// The REST API, feeds and sitemaps stay on the plain address.
		if ( preg_match( '#^/?(wp-json|feed|wp-sitemap|xmlrpc\.php)(/|$|\?|-|\.)#', (string) $path ) ) {
			return $url;
		}
		return self::prefix( $url, self::$lang );
	}

	/* ---------- Public API ---------- */

	public static function current() {
		return self::$lang;
	}

	public static function languages() {
		return self::LANGS;
	}

	/** Languages other than the default (the ones with a URL prefix). */
	public static function others() {
		return array_diff_key( self::LANGS, array( self::DEFAULT_LANG => 1 ) );
	}

	public static function locale_of( $lang ) {
		return self::LANGS[ $lang ]['locale'] ?? 'en_US';
	}

	/** The language a URL on this site is in. */
	public static function lang_from_url( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host && wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) !== $host ) {
			return self::DEFAULT_LANG;
		}
		if ( preg_match( '#^' . preg_quote( self::$home_path, '#' ) . '/(' . implode( '|', array_keys( self::others() ) ) . ')(/|$)#', $path, $m ) ) {
			return $m[1];
		}
		return self::DEFAULT_LANG;
	}

	/** Put a URL on this site into the given language (adds, swaps or removes the prefix). */
	public static function prefix( $url, $lang ) {
		$home  = untrailingslashit( (string) get_option( 'home' ) );
		$plain = preg_replace( '#^' . preg_quote( $home, '#' ) . '/(' . implode( '|', array_keys( self::others() ) ) . ')(?=/|$|\?|\#)#', $home, $url );
		if ( 0 !== strpos( $plain, $home ) || self::DEFAULT_LANG === $lang || ! isset( self::LANGS[ $lang ] ) ) {
			return $plain;
		}
		$rest = substr( $plain, strlen( $home ) );
		return $home . '/' . $lang . ( '' === $rest ? '/' : $rest );
	}

	/** This page in another language. */
	public static function url_in( $lang ) {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = (string) ( $_SERVER['HTTP_HOST'] ?? wp_parse_url( home_url(), PHP_URL_HOST ) );
		return self::prefix( $scheme . '://' . $host . self::$uri, $lang );
	}

	/** The address the visitor actually asked for, language prefix included. */
	public static function request_url() {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = (string) ( $_SERVER['HTTP_HOST'] ?? wp_parse_url( home_url(), PHP_URL_HOST ) );
		return $scheme . '://' . $host . self::$uri;
	}

	/* ---------- Customers ---------- */

	/** Remember the language a signed-in customer uses the site in. */
	public static function remember( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( $user_id && ! oys_is_admin_screen() && get_user_meta( $user_id, self::META, true ) !== self::$lang ) {
			update_user_meta( $user_id, self::META, self::$lang );
		}
	}

	/** The language to write to a user (or an email address that belongs to a user) in. */
	public static function lang_for( $user_or_email ) {
		$user = is_numeric( $user_or_email ) ? get_userdata( (int) $user_or_email ) : get_user_by( 'email', (string) $user_or_email );
		$lang = $user ? (string) get_user_meta( $user->ID, self::META, true ) : '';
		// Not known yet (a brand-new account, a guest): the language of the page they are using.
		return isset( self::LANGS[ $lang ] ) ? $lang : self::$lang;
	}

	/**
	 * Run $fn with the site switched to a language: texts, dates and links come out in it.
	 * Used for emails, which are often sent from a webhook, a cron job or the admin.
	 */
	public static function with( $lang, callable $fn ) {
		$lang = isset( self::LANGS[ $lang ] ) ? $lang : self::DEFAULT_LANG;
		if ( $lang === self::$lang ) {
			return $fn();
		}
		$prev_lang   = self::$lang;
		$prev_active = self::$active;
		self::$lang  = $lang;
		$locale      = self::LANGS[ $lang ]['locale'];
		$switched    = switch_to_locale( $locale );
		// Without WordPress's own files for the language, switch_to_locale() refuses: switch the
		// theme's and the plugin's texts by hand instead.
		$forced = $switched ? null : fn() => $locale;
		if ( $forced ) {
			add_filter( 'locale', $forced, PHP_INT_MAX );
			add_filter( 'determine_locale', $forced, PHP_INT_MAX );
			self::reload_texts();
		}
		$add_prefix = ! has_filter( 'home_url', array( __CLASS__, 'home_url_filter' ) );
		if ( $add_prefix ) {
			add_filter( 'home_url', array( __CLASS__, 'home_url_filter' ), 10, 2 );
		}
		try {
			return $fn();
		} finally {
			if ( $add_prefix ) {
				remove_filter( 'home_url', array( __CLASS__, 'home_url_filter' ), 10 );
			}
			self::$lang   = $prev_lang;
			self::$active = $prev_active;
			if ( $switched ) {
				restore_previous_locale();
			}
			if ( $forced ) {
				remove_filter( 'locale', $forced, PHP_INT_MAX );
				remove_filter( 'determine_locale', $forced, PHP_INT_MAX );
				self::reload_texts();
			}
		}
	}

	/** Forget the loaded theme and plugin texts; the next one loads in the current locale. */
	private static function reload_texts() {
		foreach ( array( 'olivia-studio', 'olivia-yoga' ) as $domain ) {
			unload_textdomain( $domain, true );
		}
	}
}

/** Is this a wp-admin screen (not a form handler)? */
function oys_is_admin_screen() {
	if ( ! is_admin() ) {
		return false;
	}
	return ! in_array( basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ), array( 'admin-post.php', 'admin-ajax.php' ), true );
}

function oys_lang() {
	return OYS_I18n::current();
}

function oys_languages() {
	return OYS_I18n::languages();
}

function oys_url_in( $lang ) {
	return OYS_I18n::url_in( $lang );
}
