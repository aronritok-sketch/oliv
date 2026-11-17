<?php
/**
 * A HelloProVision csomag próbájához (dev/tests/kit.php): az iu_theme frissítőjének az a része, amit a csomag használ –
 * a környezet „live”, van token, a bővítmények „kiadása” helyi zip (HPV_KIT_TEST_ZIP_<SLUG>).
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

	/** A próba zipjei: HPV_KIT_TEST_ZIP_<SLUG> (pl. HPV_KIT_TEST_ZIP_HELLOPROVISION_SETUP); ami nincs, annak nincs kiadása. */
	static function zip( $slug ) {
		return (string) getenv( 'HPV_KIT_TEST_ZIP_' . strtoupper( str_replace( '-', '_', $slug ) ) );
	}

	static function versions( $slug ) {
		return '' !== self::zip( $slug ) ? array( '1.0.0', '9.9.9' ) : array();
	}

	static function asset( $slug, $version ) {
		return '9.9.9' === $version ? self::zip( $slug ) : '';
	}
}
