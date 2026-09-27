#!/usr/bin/env bash
# Full test run from scratch: WordPress + plugin + theme on a fresh database, then
# PHP lint, integration tests and the browser end-to-end suite.
# Used by .github/workflows/ci.yml; runs the same locally:
#
#   DB_NAME=oywp_ci DB_USER=root DB_PASSWORD= DB_HOST=127.0.0.1 WP_DIR=/tmp/wp-ci wordpress/dev/ci.sh
#
# Needs: php 8.1+ (mysqli, curl, gd), git, node + playwright (for the E2E part; SKIP_E2E=1 to skip).
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
WPD="$REPO/wordpress"
WP_DIR="${WP_DIR:-/tmp/wp-ci}"
WP_VERSION="${WP_VERSION:-6.8.3}"
DB_NAME="${DB_NAME:-oywp_ci}"; DB_USER="${DB_USER:-root}"; DB_PASSWORD="${DB_PASSWORD:-}"; DB_HOST="${DB_HOST:-127.0.0.1}"
ARTIFACTS="${ARTIFACTS:-$WP_DIR/../oys-artifacts}"

echo "== PHP lint"
find "$WPD/olivia-studio" "$WPD/olivia-yoga" "$WPD/dev" -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true
if find "$WPD/olivia-studio" "$WPD/olivia-yoga" "$WPD/dev" -name '*.php' -print0 | xargs -0 -n1 php -l 2>&1 | grep -q 'Parse error'; then
	echo "PHP syntax errors found"; exit 1
fi

echo "== WordPress $WP_VERSION in $WP_DIR"
if [ ! -f "$WP_DIR/wp-load.php" ]; then
	git clone -q --depth 1 -b "$WP_VERSION" https://github.com/WordPress/WordPress.git "$WP_DIR"
fi
ln -sfn "$WPD/olivia-studio" "$WP_DIR/wp-content/plugins/olivia-studio"
ln -sfn "$WPD/olivia-yoga" "$WP_DIR/wp-content/themes/olivia-yoga"
mkdir -p "$WP_DIR/wp-content/mu-plugins"
ln -sfn "$WPD/dev/mu-plugins/dev-mail-catcher.php" "$WP_DIR/wp-content/mu-plugins/dev-mail-catcher.php"
cp "$WPD/dev/router.php" "$WP_DIR/router.php"
cat > "$WP_DIR/wp-config.php" <<EOF
<?php
define( 'DB_NAME', '$DB_NAME' ); define( 'DB_USER', '$DB_USER' ); define( 'DB_PASSWORD', '$DB_PASSWORD' ); define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'ci1' ); define( 'SECURE_AUTH_KEY', 'ci2' ); define( 'LOGGED_IN_KEY', 'ci3' ); define( 'NONCE_KEY', 'ci4' );
define( 'AUTH_SALT', 'ci5' ); define( 'SECURE_AUTH_SALT', 'ci6' ); define( 'LOGGED_IN_SALT', 'ci7' ); define( 'NONCE_SALT', 'ci8' );
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HOME', 'http://127.0.0.1:8080' ); define( 'WP_SITEURL', 'http://127.0.0.1:8080' );
define( 'OYS_STRIPE_API_BASE', 'http://127.0.0.1:8090' );
define( 'OYS_ZOOM_API_BASE', 'http://127.0.0.1:8090/zoom/v2' ); define( 'OYS_ZOOM_OAUTH_URL', 'http://127.0.0.1:8090/zoom/oauth/token' );
define( 'OYS_ANTHROPIC_API_URL', 'http://127.0.0.1:8090/anthropic/v1/messages' );
define( 'DISABLE_WP_CRON', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
EOF

echo "== Site setup"
cd "$WP_DIR"
php "$WPD/dev/setup-site.php" install
php "$WPD/dev/setup-site.php"
php "$WPD/dev/setup-site.php"

echo "== Integration tests"
WP_DIR="$WP_DIR" php "$WPD/dev/tests/run.php"

if [ "${SKIP_E2E:-}" = "1" ]; then exit 0; fi

echo "== End-to-end tests"
mkdir -p "$ARTIFACTS"
rm -f "$(php -r 'echo sys_get_temp_dir();')/mock-stripe.json"
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 router.php > "$ARTIFACTS/wp-server.log" 2>&1 &)
(cd "$WPD/dev" && PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8090 mock-stripe.php > "$ARTIFACTS/mock-stripe.log" 2>&1 &)
for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8080/ && break; sleep 1; done
set +e
WP_DIR="$WP_DIR" SHOTS="$ARTIFACTS/screenshots" node "$WPD/dev/e2e.js"
status=$?
# The mobile app (web build, iPhone viewport) against the same site, when it was exported.
if [ -n "${APP_DIST:-}" ] && [ -f "$APP_DIST/index.html" ]; then
	echo "== Mobile app end-to-end tests"
	WP_DIR="$WP_DIR" SHOTS="$ARTIFACTS/app-screenshots" APP_DIST="$APP_DIST" node "$WPD/../app/e2e/app.e2e.js" || status=1
fi
set -e
pkill -f '^php -S 127.0.0.1:80[89]0' || true
cp "$WP_DIR/wp-content/debug.log" "$ARTIFACTS/" 2>/dev/null || true
if grep -v 'Deprecated\|WordPress.org\|wordpress.org' "$WP_DIR/wp-content/debug.log" 2>/dev/null | grep -q 'PHP \(Fatal\|Warning\|Notice\)'; then
	echo "PHP warnings or errors were logged:"; grep 'PHP \(Fatal\|Warning\|Notice\)' "$WP_DIR/wp-content/debug.log" | grep -v 'WordPress.org' | head -20
	status=1
fi
exit $status
