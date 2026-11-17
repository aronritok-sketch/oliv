<?php
/**
 * A HelloProVision csomag próbájához (dev/tests/kit.php): az iu_theme frissítőjének az a része, amit a csomag használ –
 * a környezet „live”, van token, a beállító varázsló „kiadása” egy helyi zip (HPV_KIT_TEST_ZIP).
 */

namespace IUTheme;

class Updater {
	static function token() {
		return 'test-token';
	}
}

class Site {
	static function env( $slug = '', $home = '' ) {
		return getenv( 'HPV_KIT_TEST_ENV' ) ?: 'live';
	}

	static function versions( $slug ) {
		return 'helloprovision-setup' === $slug ? array( '1.0.0', '9.9.9' ) : array();
	}

	static function asset( $slug, $version ) {
		return 'helloprovision-setup' === $slug && '9.9.9' === $version ? (string) getenv( 'HPV_KIT_TEST_ZIP' ) : '';
	}
}
