<?php
/**
 * The password screens (wp-login.php: „Elfelejtetted a jelszavad?”, the link from the email, „Nézd meg a postafiókod”) in the
 * site's own look instead of WordPress's blue logo and grey box. Olívia, 15 November: „nézd át felhasználói szemmel … kérj
 * elfelejtett jelszót stb.” – on the online site the texts talk to the guest like the rest of the site (tegezve), the way
 * back leads to the account page, and the request ends on the „check your email” page instead of silently on the account
 * page. The admin's own login (wp-login.php without an action) only gets the look.
 *
 * @package olivia-yoga
 */

defined( 'ABSPATH' ) || exit;

/** True on the guest's password screens (not the admin's plain login). */
function oy_login_guest_screen() {
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	return in_array( $action, array( 'lostpassword', 'retrievepassword', 'rp', 'resetpass' ), true ) || isset( $_GET['checkemail'] ); // phpcs:ignore WordPress.Security.NonceVerification
}

add_action( 'login_enqueue_scripts', function () {
	$online = oy_online_only();
	$font   = $online ? 'Cabin:wght@400..700' : 'Quicksand:wght@400..700';
	wp_enqueue_style( 'oy-login-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=' . $font . '&display=swap', array(), null );
	$c = $online
		? array( 'bg' => '#F0EDEE', 'card' => '#FAF8F9', 'ink' => '#072624', 'text' => '#1E3432', 'muted' => '#4D5F5D', 'accent' => '#E63462', 'accent2' => '#B5214A', 'btn' => '#002E2C', 'sun' => '#FFDF22', 'soft' => '#FFF4C2' )
		: array( 'bg' => '#F3F2EC', 'card' => '#FBFBF8', 'ink' => '#12231A', 'text' => '#26352B', 'muted' => '#53635A', 'accent' => '#EE3F9A', 'accent2' => '#B8306F', 'btn' => '#2B5036', 'sun' => '#F2C14E', 'soft' => '#F8EBC8' );
	$text = $online ? 'Cabin' : 'Quicksand';
	$logo = $online ? 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( get_template_directory() . '/assets/img/logo-online.svg' ) ) : '';
	$css  = 'body.login{background:' . $c['bg'] . ';color:' . $c['text'] . ';font-family:"' . $text . '",system-ui,sans-serif;font-size:16px}'
		. '#login{width:min(400px,calc(100vw - 32px));box-sizing:border-box;padding:6vh 0 32px;margin:0 auto}.login form p,.login .user-pass-wrap{margin-bottom:14px}'
		. '.login h1 a{background:' . ( $logo ? 'url(' . $logo . ') center/contain no-repeat' : 'none' ) . ';width:220px;height:' . ( $logo ? '84px' : 'auto' ) . ';margin:0 auto 20px;' . ( $logo ? 'text-indent:-9999px;' : 'text-indent:0;font:400 28px/1 Anton,Impact,sans-serif;text-transform:uppercase;color:' . $c['ink'] . ';text-decoration:none;' ) . 'outline:0}'
		. '.login form{background:' . $c['card'] . ';border:0;border-radius:22px;box-shadow:0 1px 0 rgba(0,0,0,.04),0 10px 30px rgba(7,38,36,.07);padding:26px 22px 28px;margin:16px 0 0;width:auto;box-sizing:border-box}'
		. '.login label{color:' . $c['ink'] . ';font-weight:700;font-size:15px}'
		. '.login form .input,.login input[type=text],.login input[type=password],.login input[type=email]{font-size:17px;min-height:48px;border:1.5px solid rgba(7,38,36,.22);border-radius:14px;background:#fff;box-shadow:none;padding:6px 14px}'
		. '.login form .input:focus,.login input[type=password]:focus{border-color:' . $c['btn'] . ';box-shadow:0 0 0 3px ' . $c['soft'] . '}'
		. '.login .button-primary,.wp-core-ui .button-primary{float:none;display:block;width:100%;min-height:50px;margin-top:6px;border:0;border-radius:999px;background:' . $c['btn'] . ';color:#fff;font-weight:700;letter-spacing:.06em;text-transform:uppercase;font-size:14px;text-shadow:none;box-shadow:none}'
		. '.login .button-primary:hover,.login .button-primary:focus{background:' . $c['accent2'] . ';color:#fff}'
		. '.wp-core-ui .button-secondary,.login .wp-generate-pw{min-height:44px;border-radius:999px;border:1.5px solid ' . $c['btn'] . ';color:' . $c['btn'] . ';background:#fff}'
		. '.login .button.wp-hide-pw{min-height:44px;color:' . $c['btn'] . '}'
		. '.login #login_error,.login .message,.login .notice,.login .success{border:0;border-radius:16px;box-shadow:none;padding:14px 16px;line-height:1.55;font-size:15px;margin:16px 0 0}'
		. '.login .message,.login .notice-info,.login .success{background:' . $c['soft'] . ';color:' . $c['ink'] . '}'
		. '.login #login_error,.login .notice-error{background:#FCE4EA;color:' . $c['ink'] . ';border-left:4px solid ' . $c['accent'] . '}'
		. '.login .message a,.login #login_error a,.login .notice a{color:' . $c['accent2'] . ';font-weight:700}'
		. '.login #nav,.login #backtoblog{margin:18px 0 0;padding:0;text-align:center;font-size:15px}'
		. '.login #nav a,.login #backtoblog a{display:inline-flex;align-items:center;min-height:44px;color:' . $c['ink'] . ';font-weight:700;text-decoration:underline;text-decoration-color:' . $c['accent'] . ';text-decoration-thickness:2px;text-underline-offset:.3em}'
		. '.login #nav a:hover,.login #backtoblog a:hover{color:' . $c['accent2'] . '}'
		. '.login .privacy-policy-page-link{margin:18px 0 0}.login .privacy-policy-page-link a{color:' . $c['muted'] . '}'
		. '.language-switcher{display:none}'
		. '.login .reset-pass-submit{display:grid;gap:10px}.login .reset-pass-submit .button{width:100%;margin:0}'
		. '.login .pw-weak{margin-top:10px}.login .indicator-hint{color:' . $c['muted'] . '}'
		. '#pass-strength-result{border-radius:12px}';
	wp_add_inline_style( 'login', $css );
} );

add_filter( 'login_headerurl', fn() => home_url( '/' ) );
add_filter( 'login_headertext', fn() => oy_site_name() );
add_filter( 'login_display_language_dropdown', '__return_false' );

/** The way back on the guest's screens: the account page, where she logs in and registers. */
add_filter( 'login_site_html_link', function ( $html ) {
	if ( ! oy_online_only() ) {
		return $html;
	}
	return '<a href="' . esc_url( home_url( '/' ) ) . '">&larr; Vissza a főoldalra</a>';
} );

add_action( 'login_init', function () {
	if ( ! oy_login_guest_screen() ) {
		return;
	}
	// „Back to log in” opens the account page on its „I have an account” tab (Áron, 26 November: after the new password
	// the guest logs in there)
	$account = add_query_arg( 'oys_view', 'login', function_exists( 'oys_account_url' ) ? oys_account_url() : home_url( '/fiokom/' ) );
	add_filter( 'login_url', fn() => $account, 99 );
	// the request ends on „check your email”, not silently on the account page the link came from
	add_filter( 'lostpassword_redirect', '__return_empty_string', 99 );
	if ( oy_online_only() ) {
		add_filter( 'gettext', 'oy_login_text', 20, 3 );
		add_filter( 'login_title', fn( $title ) => preg_replace( '/\s*&#8212;\s*WordPress$/', '', (string) $title ) );
	}
} );

/** The guest's password screens talk like the rest of the site. */
function oy_login_text( $translation, $text, $domain ) {
	if ( 'default' !== $domain ) {
		return $translation;
	}
	$map = array(
		'Please enter your username or email address. You will receive an email message with instructions on how to reset your password.' => 'Add meg az e-mail címed, amivel regisztráltál. Kapsz egy levelet egy linkkel, ott megadhatod az új jelszavad.',
		'Username or Email Address'                       => 'E-mail cím',
		'Get New Password'                                => 'Új jelszót kérek',
		'Log in'                                          => 'Vissza a belépéshez',
		'Lost Password'                                   => 'Elfelejtett jelszó',
		'Check your email'                                => 'Nézd meg a postafiókod',
		'Check your email for the confirmation link, then visit the <a href="%s">login page</a>.' => 'Elküldtem a levelet „Új jelszó” tárggyal. Nézd meg a postafiókod (ha nem látod, a spam mappát is), és kattints a levélben az „Új jelszót adok meg” gombra: ott megadhatod az új jelszavad. Utána a „Van fiókom” fülön tudsz <a href="%s">belépni</a> az e-mail-címeddel és az új jelszavaddal.',
		'Enter your new password below or generate one.'  => 'Add meg az új jelszavad, vagy kérj egy erőset a gombbal.',
		'New password'                                    => 'Új jelszó',
		'Save Password'                                   => 'Elmentem',
		'Generate Password'                               => 'Kérek egy erős jelszót',
		'Reset Password'                                  => 'Új jelszó',
		'Your password has been reset.'                   => 'Kész, megvan az új jelszavad. Most lépj be vele: a lenti linkre kattintva a „Van fiókom” fülön írd be az e-mail-címed és az új jelszavad.',
		'<strong>Error:</strong> Please enter a username or email address.' => '<strong>Hoppá:</strong> írd be az e-mail címed.',
		'<strong>Error:</strong> There is no account with that username or email address.' => '<strong>Hoppá:</strong> ezzel az e-mail címmel nincs fiók. Nézd meg, jól írtad-e, vagy regisztrálj a Fiókom oldalon.',
		'<strong>Error:</strong> Your password reset link appears to be invalid. Please request a new link below.' => '<strong>Hoppá:</strong> ez a link már nem érvényes. Kérj lent egy újat.',
		'<strong>Error:</strong> Your password reset link has expired. Please request a new link below.' => '<strong>Hoppá:</strong> ez a link lejárt. Kérj lent egy újat.',
	);
	if ( isset( $map[ $text ] ) ) {
		return $map[ $text ];
	}
	if ( str_starts_with( $text, '<strong>Error:</strong> The email could not be sent.' ) ) {
		return '<strong>Hoppá:</strong> a levelet most nem sikerült elküldeni. Próbáld újra pár perc múlva, vagy írj nekem: ' . esc_html( OY_EMAIL ) . '.';
	}
	return $translation;
}

/** The reset email itself (online): short, tegezve, with the button in the studio's email look. */
add_filter( 'retrieve_password_notification_email', function ( $mail, $key, $user_login, $user ) {
	if ( ! oy_online_only() || ! class_exists( 'OYS_Emails' ) || ! class_exists( 'OYS_Mailer' ) ) {
		return $mail;
	}
	$url  = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user_login ), 'login' );
	$name = $user instanceof WP_User && $user->first_name ? $user->first_name : '';
	// Áron, 26 November: „írjuk le, hogyha módosította a jelszót, mit kell csináljon, hogy be tudjon lépni, tehát hogy
	// kattintson a van fiókomra” – the way in after the new password, with the account page on that tab
	$login = add_query_arg( 'oys_view', 'login', function_exists( 'oys_account_url' ) ? oys_account_url() : home_url( '/fiokom/' ) );
	// a guest's button opens the studio's own page: the password, and they are logged in (OYS_Welcome, „hülyebiztosra”)
	$guest = $user instanceof WP_User && class_exists( 'OYS_Welcome' ) && OYS_Customers::is_customer_only( $user );
	$body  = '<p>' . esc_html( $name ? 'Szia ' . $name . '!' : 'Szia!' ) . '</p>'
		. '<p>Új jelszót kértél az ' . esc_html( oy_site_name() ) . ' fiókodhoz. Kattints a lenti „Új jelszót adok meg” gombra, és add meg a jelszavad' . ( $guest ? ': utána rögtön be is lépsz, mást nem kell beírnod' : '' ) . '. A link egy napig érvényes.</p>'
		. '<p><b>Később így lépsz be:</b> a <a href="' . esc_url( $login ) . '">Fiókom</a> oldalon kattints a „Van fiókom” fülre, és írd be az e-mail-címed és a jelszavad.</p>'
		. '<p>Ha nem te kérted, nem kell tenned semmit: a jelszavad marad a régi.</p>';
	$mail['subject'] = 'Új jelszó – ' . oy_site_name();
	$mail['message'] = OYS_Emails::wrap_public( 'Új jelszó', $body, array( 'Új jelszót adok meg', $url ) );
	$mail['headers'] = array_merge( array( 'Content-Type: text/html; charset=UTF-8' ), OYS_Mailer::headers() );
	return $mail;
}, 10, 4 );
