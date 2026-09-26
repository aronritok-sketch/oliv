/*
 * End-to-end test of the mobile app (web build, iPhone viewport) against a local WordPress.
 *   cd app && EXPO_PUBLIC_API_BASE=http://127.0.0.1:8080 npx expo export --platform web
 *   WP_DIR=/path/to/wordpress SHOTS=/tmp/app-shots node e2e/app.e2e.js
 * Needs the same local site as wordpress/dev/e2e.js: WordPress on :8080 with the plugin,
 * and wordpress/dev/mock-stripe.php (Stripe + Zoom mock) on :8090.
 */
const { execSync } = require('child_process');
const fs = require('fs');
const http = require('http');
const path = require('path');
const { chromium, devices } = require(execSync('npm root -g').toString().trim() + '/playwright');

const WP_DIR = process.env.WP_DIR;
const SHOTS = process.env.SHOTS || '/tmp/app-shots';
const DIST = path.resolve(__dirname, '..', process.env.APP_DIST || 'dist');
const PORT = 8100;
const APP = `http://127.0.0.1:${PORT}`;
fs.mkdirSync(SHOTS, { recursive: true });

let failures = 0;
function check(cond, msg) {
  console.log((cond ? 'PASS ' : 'FAIL ') + msg);
  if (!cond) failures++;
}
function php(code) {
  const file = path.join(WP_DIR, '_app_e2e.php');
  fs.writeFileSync(file, '<?php require __DIR__ . "/wp-load.php"; ' + code);
  return execSync('php ' + file, { cwd: WP_DIR }).toString().trim();
}

/** The exported web app, with every unknown path falling back to index.html (client routing). */
function serve() {
  const types = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.ttf': 'font/ttf', '.ico': 'image/x-icon', '.json': 'application/json' };
  return http
    .createServer((req, res) => {
      const clean = decodeURIComponent(req.url.split('?')[0]);
      let file = path.join(DIST, clean);
      if (!file.startsWith(DIST) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
        file = path.join(DIST, 'index.html');
      }
      res.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'application/octet-stream' });
      fs.createReadStream(file).pipe(res);
    })
    .listen(PORT);
}

const tid = (id) => `[data-testid="${id}"]`;

(async () => {
  const server = serve();
  const stamp = Date.now();
  const email = `appy${stamp}@example.com`;
  const password = 'yoga-pass-123';

  // A customer with a 5-class pass (no waiver yet) and four classes to play with.
  const setup = JSON.parse(
    php(`
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%oys_rl_%'");
    $u = wp_insert_user( array( 'user_login' => '${email}', 'user_email' => '${email}', 'user_pass' => '${password}', 'first_name' => 'Appy', 'last_name' => 'Tester', 'role' => 'oys_customer' ) );
    OYS_Passes::grant( $u, array( 'credits' => 5, 'name' => '5-class pass' ) );
    $mk = function ( $hours, $extra = array() ) {
      $start = time() + (int) ( $hours * HOUR_IN_SECONDS );
      return OYS_Schedule::save( array_merge( array(
        'kind' => 'group', 'class_slug' => 'hatha-flow', 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ),
        'ends_at' => gmdate( 'Y-m-d H:i:s', $start + 3600 ), 'capacity' => 10, 'format' => 'studio',
        'price_cents' => 2500, 'credits_allowed' => 1, 'status' => 'scheduled', 'location' => 'Studio 1',
      ), $extra ) );
    };
    $studio = $mk( 26 );
    $hybrid = $mk( 0.8, array( 'format' => 'hybrid', 'online_price_cents' => 600, 'online_capacity' => 0 ) );
    $paid   = $mk( 50, array( 'credits_allowed' => 0, 'price_cents' => 1800 ) );
    $full   = $mk( 28, array( 'capacity' => 1 ) );
    OYS_Bookings::book_manual( OYS_Customers::find_or_create( 'full${stamp}@example.com', 'Full Up' ), $full, 'comp', false );
    echo json_encode( compact( 'u', 'studio', 'hybrid', 'paid', 'full' ) );
  `),
  );

  const browser = await chromium.launch(
    process.env.HTTPS_PROXY
      ? { args: ['--proxy-server=' + process.env.HTTPS_PROXY, '--proxy-bypass-list=127.0.0.1;localhost', '--ignore-certificate-errors'] }
      : {},
  );
  const ctx = await browser.newContext({ ...devices['iPhone 13'], viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => console.log('JS error:', e.message));
  const shot = (name) => page.screenshot({ path: path.join(SHOTS, `app-${name}.png`) });

  // 1. Login: a wrong password is refused, the right one opens the schedule.
  await page.goto(APP + '/');
  await page.waitForSelector(tid('login-email'));
  await shot('01-login');
  await page.fill(tid('login-email'), email);
  await page.fill(tid('login-password'), 'wrong-password');
  await page.click(tid('login-submit'));
  await page.waitForSelector(tid('login-error'));
  check(/not right/i.test(await page.textContent(tid('login-error'))), 'wrong password shows an error');
  await page.fill(tid('login-password'), password);
  await page.click(tid('login-submit'));
  await page.waitForSelector(tid(`session-${setup.studio}`), { timeout: 15000 });
  check(await page.isVisible('text=Hi Appy'), 'schedule greets the customer by name');
  check(await page.isVisible(`${tid(`session-${setup.hybrid}`)} >> text=Studio + online`), 'hybrid class is marked studio + online');
  check(await page.isVisible(`${tid(`session-${setup.full}`)} >> text=Full · join the waitlist`), 'full class says so on the schedule');
  const tokens = () => Number(php(`echo count( (array) get_user_meta( ${setup.u}, 'oys_app_tokens', true ) );`));
  check(tokens() === 1, 'login stored one app token');
  await shot('02-schedule');

  // 2. Book a studio class with the pass; the participation agreement comes first.
  await page.click(tid(`session-${setup.studio}`));
  await page.waitForSelector(tid('pay-credit'));
  check(await page.isVisible('text=5 classes left'), 'pass option shows the credits left');
  check(await page.isVisible(tid('pay-card')), 'card option offered too');
  check(await page.isDisabled(tid('book-submit')), 'booking waits for the agreement');
  await shot('03-class');
  await page.click(tid('waiver-check'));
  await page.click(tid('book-submit'));
  await page.waitForSelector(tid('booked-title'));
  check(await page.isVisible('text=You’re booked! See you on the mat.'), 'studio booking confirmed');
  const credits = () => Number(php(`echo OYS_Passes::balance( ${setup.u}, 'class' );`));
  check(credits() === 4, 'one credit used');
  check(php(`echo OYS_Customers::has_waiver( ${setup.u} ) ? 'y' : 'n';`) === 'y', 'agreement recorded');
  await shot('04-booked');

  // 3. Hybrid class, joining live online with the pass (converted to online classes).
  await page.goto(`${APP}/class/${setup.hybrid}`);
  await page.waitForSelector(tid('mode-online'));
  await page.click(tid('mode-online'));
  await page.waitForSelector(`${tid('pay-card')} >> text=$6`);
  check(await page.isVisible(`${tid('pay-credit')} >> text=/online classes/`), 'pass converts to online classes');
  check(!(await page.isVisible(tid('waiver-check'))), 'agreement not asked twice');
  await shot('05-hybrid-online');
  await page.click(tid('pay-credit'));
  await page.click(tid('book-submit'));
  await page.waitForSelector(tid('booked-title'));
  check(await page.isVisible('text=Joining live online'), 'online booking confirmed');
  await page.waitForSelector(tid('join-live'));
  check(await page.isVisible(tid('join-live')), 'join button shows within an hour of class');
  const hb = JSON.parse(php(`global $wpdb; echo json_encode( $wpdb->get_row( $wpdb->prepare( "SELECT mode, paid_with FROM {$wpdb->prefix}oys_bookings WHERE user_id = %d AND session_id = %d AND status = 'confirmed'", ${setup.u}, ${setup.hybrid} ) ) );`));
  check(hb && hb.mode === 'online' && hb.paid_with === 'credit', 'hybrid booking stored as online, paid with the pass');
  check(credits() === 3, 'studio credit converted for the online class');
  await shot('06-join-live');

  // 4. Card payment through the web checkout (Stripe mock), then back in the app.
  await page.goto(`${APP}/class/${setup.paid}`);
  await page.waitForSelector(tid('pay-card'));
  check(!(await page.isVisible(tid('pay-credit'))), 'no pass option where passes are not accepted');
  check(await page.isVisible('text=Pay $18 and book'), 'card total on the button');
  const [pay] = await Promise.all([ctx.waitForEvent('page'), page.click(tid('book-submit'))]);
  await pay.waitForURL(/127\.0\.0\.1:8090\/pay\//);
  check(true, 'Stripe checkout opens in the browser');
  await Promise.all([pay.waitForNavigation({ url: /oys_return=success/ }), pay.click('#pay')]);
  check(/app=1/.test(pay.url()), 'return page knows it came from the app');
  check(await pay.isVisible('text=Back to the app'), 'return page offers the way back to the app');
  await pay.close();
  await page.reload();
  await page.waitForSelector(tid('booked-title'));
  check(await page.isVisible('text=In the studio · Card'), 'card booking shows as paid by card');

  // 5. Waitlist on a full class.
  await page.goto(`${APP}/class/${setup.full}`);
  await page.waitForSelector(tid('waitlist'));
  await page.click(tid('waitlist'));
  await page.waitForSelector('text=You’re #1 on the waitlist');
  check(true, 'joined the waitlist');
  await shot('07-waitlist');
  await page.click(tid('waitlist'));
  await page.waitForSelector('text=This class is full');
  check(true, 'left the waitlist');

  // 6. My classes: all three bookings, the online one with its join button.
  await page.goto(`${APP}/bookings`);
  await page.waitForSelector(tid('bookings-upcoming'));
  await page.waitForSelector('text=Hatha Flow');
  const cards = await page.$$('[data-testid^="booking-"]');
  const ids = await Promise.all(cards.map((c) => c.getAttribute('data-testid')));
  const mine = JSON.parse(php(`global $wpdb; echo json_encode( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}oys_bookings WHERE user_id = %d AND status = 'confirmed' AND guest_of = 0", ${setup.u} ) ) ) );`));
  check(mine.length === 3 && mine.every((id) => ids.includes(`booking-${id}`)), 'my classes lists the three bookings');
  check(await page.isVisible(tid('join-live')), 'join button on the online booking');
  await shot('08-my-classes');

  // 7. Cancel the studio class (outside the window: the credit comes back).
  await page.goto(`${APP}/class/${setup.studio}`);
  await page.waitForSelector(tid('cancel-start'));
  await page.click(tid('cancel-start'));
  await page.click(tid('cancel-confirm'));
  await page.waitForSelector(tid('class-message'));
  check(/back on your pass/i.test(await page.textContent(tid('class-message'))), 'cancel message says the class is back on the pass');
  check(credits() === 4, 'credit returned');
  check(await page.isVisible(tid('pay-credit')), 'class can be booked again');

  // 8. Passes and profile.
  await page.goto(`${APP}/passes`);
  await page.waitForSelector(tid('balance-class'));
  check((await page.textContent(tid('balance-class'))).startsWith('4'), 'passes tab shows 4 studio classes');
  check(await page.isVisible('text=5-class pass'), 'pass listed');
  await shot('09-passes');
  await page.goto(`${APP}/profile`);
  await page.waitForSelector(tid('logout'));
  await page.waitForSelector(`text=${email}`, { timeout: 10000 }).catch(() => {});
  check(await page.isVisible(`text=${email}`), 'profile shows the email');
  check(await page.isVisible('text=Accepted. Thank you!'), 'agreement shown as accepted');
  await shot('10-profile');

  // 9. The token survives a reload; logging out revokes it on the server.
  await page.reload();
  await page.waitForSelector(tid('logout'));
  check(true, 'still logged in after reload');
  await page.click(tid('logout'));
  await page.waitForSelector(tid('login-email'));
  check(tokens() === 0, 'logout revoked the token');

  // Clean up so the dev schedule stays tidy.
  php(`global $wpdb; foreach ( array( ${setup.studio}, ${setup.hybrid}, ${setup.paid}, ${setup.full} ) as $id ) { $wpdb->update( $wpdb->prefix . 'oys_sessions', array( 'status' => 'cancelled' ), array( 'id' => $id ) ); }`);
  fs.rmSync(path.join(WP_DIR, '_app_e2e.php'), { force: true });

  await browser.close();
  server.close();
  console.log(failures ? `\n${failures} app check(s) failed` : '\nAll app checks passed');
  process.exit(failures ? 1 : 0);
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
