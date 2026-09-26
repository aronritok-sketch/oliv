/*
 * End-to-end test of the booking flows against a local WordPress + mock Stripe.
 *   WP_DIR=/path/to/wordpress SHOTS=/tmp/shots node e2e.js
 * Needs: WordPress at http://127.0.0.1:8080 with the theme + plugin, dev/mock-stripe.php on :8090,
 * and the dev mail catcher (emails land in wp-content/mail-log).
 */
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { chromium } = require(execSync('npm root -g').toString().trim() + '/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP_DIR = process.env.WP_DIR;
const SHOTS = process.env.SHOTS || '/tmp/oys-shots';
fs.mkdirSync(SHOTS, { recursive: true });

let failures = 0;
function check(cond, msg) {
  console.log((cond ? 'PASS ' : 'FAIL ') + msg);
  if (!cond) failures++;
}
function php(code) {
  const file = path.join(WP_DIR, '_e2e.php');
  fs.writeFileSync(file, '<?php require __DIR__ . "/wp-load.php"; ' + code);
  return execSync('php ' + file, { cwd: WP_DIR }).toString().trim();
}
const q = (sql) => php(`global $wpdb; echo json_encode($wpdb->get_results("${sql.replace(/"/g, '\\"')}"));`);
const mails = () => fs.existsSync(path.join(WP_DIR, 'wp-content/mail-log')) ? fs.readdirSync(path.join(WP_DIR, 'wp-content/mail-log')).filter(f => f.endsWith('.html')) : [];
const mailSubjects = () => mails().map(f => (fs.readFileSync(path.join(WP_DIR, 'wp-content/mail-log', f), 'utf8').match(/<!-- subject: (.*) -->/) || [])[1]);

async function register(page, first, email) {
  await page.click('button[data-tab="oys-register"]').catch(() => {});
  await page.fill('#oys-reg-first', first);
  await page.fill('#oys-reg-last', 'Tester');
  await page.fill('#oys-reg-email', email);
  await page.fill('#oys-reg-password', 'yoga-pass-123');
  await page.check('#oys-register input[name="waiver"]');
  await Promise.all([page.waitForNavigation(), page.click('#oys-register button[type="submit"]')]);
}

async function payOnMockStripe(page, button = '#pay') {
  await page.waitForURL(/127\.0\.0\.1:8090\/pay\//);
  await Promise.all([page.waitForNavigation({ url: /oys_return=success/ }), page.click(button)]);
}

(async () => {
  const stamp = Date.now();
  const browser = await chromium.launch(process.env.HTTPS_PROXY ? { args: ['--proxy-server=' + process.env.HTTPS_PROXY, '--proxy-bypass-list=127.0.0.1;localhost', '--ignore-certificate-errors'] } : {});
  const ctxA = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const a = await ctxA.newPage();
  a.on('pageerror', e => console.log('JS error:', e.message));

  // Pick the next two bookable group sessions.
  const sessions = JSON.parse(q("SELECT id, capacity FROM wp_oys_sessions WHERE kind='group' AND status='scheduled' AND booked=0 AND starts_at > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) ORDER BY starts_at LIMIT 3"));
  const [s1, s2, s3] = sessions.map(s => s.id);

  // 1. Guest books a drop-in: sign up on the booking page, pay by card.
  await a.goto(`${BASE}/book/?session=${s1}`);
  await a.screenshot({ path: `${SHOTS}/01-book-guest.png`, fullPage: true });
  const emailA = `anna${stamp}@example.com`;
  await register(a, 'Anna', emailA);
  check(await a.isVisible('text=How would you like to book?'), 'after sign-up the payment options show');
  await a.screenshot({ path: `${SHOTS}/02-book-options.png`, fullPage: true });
  await a.check('input[value="card"]');
  await a.click('.oys-submit');
  await a.screenshot({ path: `${SHOTS}/03-mock-stripe.png` });
  const held = JSON.parse(q(`SELECT status FROM wp_oys_bookings WHERE session_id=${s1} ORDER BY id DESC LIMIT 1`));
  check(held[0] && held[0].status === 'pending', 'seat is held while paying');
  await payOnMockStripe(a);
  await a.screenshot({ path: `${SHOTS}/04-booked.png`, fullPage: true });
  check(await a.isVisible("text=You're booked!"), 'return page confirms the booking');
  const b1 = JSON.parse(q(`SELECT b.status, b.paid_with, o.status AS ostatus, o.receipt_url FROM wp_oys_bookings b JOIN wp_oys_orders o ON o.id=b.order_id WHERE b.session_id=${s1} ORDER BY b.id DESC LIMIT 1`))[0];
  check(b1.status === 'confirmed' && b1.ostatus === 'paid', 'booking confirmed and order paid');
  check(b1.receipt_url.includes('receipts'), 'Stripe receipt link stored');
  check(mailSubjects().some(s => s && s.startsWith('Booked:')), 'confirmation email sent');
  check(fs.readdirSync(path.join(WP_DIR, 'wp-content/mail-log')).some(f => f.endsWith('.ics')), 'calendar invite attached');

  // 2. Buy a 5-class pass.
  await a.goto(`${BASE}/book/?product=pack-5`);
  await a.click('.oys-submit');
  await payOnMockStripe(a);
  check(await a.isVisible('text=Your pass is ready'), 'pass purchase confirmed');
  const bal = () => parseInt(php(`echo OYS_Passes::balance(get_user_by('email','${emailA}')->ID,'class');`), 10);
  check(bal() === 5, 'pass gives 5 classes');

  // 3. Book with the pass.
  await a.goto(`${BASE}/book/?session=${s2}`);
  await a.check('input[value="credit"]');
  await Promise.all([a.waitForNavigation(), a.click('.oys-submit')]);
  check(await a.isVisible("text=You're booked"), 'booked with a credit');
  check(bal() === 4, 'one credit used');

  // 4. Account page and cancellation (well before the class: credit comes back).
  await a.goto(`${BASE}/account/`);
  await a.screenshot({ path: `${SHOTS}/05-account.png`, fullPage: true });
  a.once('dialog', d => d.accept());
  const row = a.locator('.oys-item', { hasText: '' }).filter({ has: a.locator('button:has-text("Cancel")') }).last();
  await Promise.all([a.waitForNavigation(), row.locator('button:has-text("Cancel")').click()]);
  const cancelled = JSON.parse(q(`SELECT status FROM wp_oys_bookings WHERE session_id=${s2} ORDER BY id DESC LIMIT 1`))[0];
  check(cancelled.status === 'cancelled', 'booking cancelled');
  check(bal() === 5, 'credit returned after on-time cancel');
  await a.goto(`${BASE}/account/?tab=passes`);
  await a.screenshot({ path: `${SHOTS}/06-passes.png`, fullPage: true });

  // 5. Waitlist with auto-promotion. Fill s3, B (with a pass) joins the waitlist, A cancels.
  php(`global $wpdb; $wpdb->update($wpdb->prefix.'oys_sessions', array('capacity'=>1), array('id'=>${s3})); OYS_Schedule::recount(${s3});`);
  await a.goto(`${BASE}/book/?session=${s3}`);
  await a.check('input[value="credit"]');
  await Promise.all([a.waitForNavigation(), a.click('.oys-submit')]);
  const ctxB = await browser.newContext();
  const bpage = await ctxB.newPage();
  const emailB = `bea${stamp}@example.com`;
  await bpage.goto(`${BASE}/account/`);
  await register(bpage, 'Bea', emailB);
  php(`OYS_Passes::grant(get_user_by('email','${emailB}')->ID, array('credits'=>2,'name'=>'Test credits','source'=>'admin'));`);
  await bpage.goto(`${BASE}/book/?session=${s3}`);
  check(await bpage.isVisible('text=This class is full'), 'full class offers the waitlist');
  await Promise.all([bpage.waitForNavigation(), bpage.click('button:has-text("Join the waitlist")')]);
  await bpage.screenshot({ path: `${SHOTS}/07-waitlist.png`, fullPage: true });
  check(await bpage.isVisible('text=number 1 on the waitlist'), 'B is first on the waitlist');
  const aBooking = JSON.parse(q(`SELECT b.id FROM wp_oys_bookings b JOIN wp_users u ON u.ID=b.user_id WHERE b.session_id=${s3} AND b.status='confirmed' AND u.user_email='${emailA}'`))[0].id;
  php(`OYS_Bookings::cancel(${aBooking});`);
  const bIn = JSON.parse(q(`SELECT b.status FROM wp_oys_bookings b JOIN wp_users u ON u.ID=b.user_id WHERE b.session_id=${s3} AND u.user_email='${emailB}'`));
  check(bIn.length === 1 && bIn[0].status === 'confirmed', 'waitlisted customer with a pass moved in automatically');
  check(mailSubjects().some(s => s && s.startsWith("You're in")), 'waitlist promotion email sent');

  // 6. Private session: request → offer → pay.
  await a.goto(`${BASE}/account/?tab=private`);
  await a.selectOption('#oys-pr-type', 'beach');
  await a.fill('#oys-pr-address', 'Fort Myers Beach, near the pier');
  await a.fill('#oys-pr-preferred', 'Saturday sunrise or Sunday morning');
  await a.fill('#oys-pr-notes', 'Tight hamstrings, runner.');
  await Promise.all([a.waitForNavigation(), a.click('button:has-text("Send request")')]);
  const reqId = JSON.parse(q("SELECT id FROM wp_oys_private_requests ORDER BY id DESC LIMIT 1"))[0].id;
  const when = php(`echo wp_date('Y-m-d', time() + 5*DAY_IN_SECONDS) . 'T07:00';`);
  php(`OYS_Privates::offer(${reqId}, '${when}', 60, 9500, 'Fort Myers Beach, near the pier', '', 'See you on the sand!');`);
  await a.goto(`${BASE}/account/?tab=private`);
  await a.screenshot({ path: `${SHOTS}/08-private-offer.png`, fullPage: true });
  await Promise.all([a.waitForNavigation(), a.click('a:has-text("Confirm and pay")')]);
  await a.check('input[value="card"]');
  await a.click('.oys-submit');
  await payOnMockStripe(a);
  const req = JSON.parse(q(`SELECT status FROM wp_oys_private_requests WHERE id=${reqId}`))[0];
  check(req.status === 'booked', 'private session booked after payment');
  const ctxC = await browser.newContext();
  const cpage = await ctxC.newPage();
  const sessId = JSON.parse(q(`SELECT session_id FROM wp_oys_private_requests WHERE id=${reqId}`))[0].session_id;
  await cpage.goto(`${BASE}/book/?session=${sessId}`);
  check(!(await cpage.content()).includes('near the pier'), 'private address hidden from other visitors');

  // 7. Gift card: A buys for C; C redeems.
  await a.goto(`${BASE}/gift-cards/`);
  await a.screenshot({ path: `${SHOTS}/09-gift.png`, fullPage: true });
  const emailC = `cora${stamp}@example.com`;
  await a.fill('#oys-g-name', 'Cora');
  await a.fill('#oys-g-email', emailC);
  await a.fill('#oys-g-msg', 'Happy birthday!');
  await a.click('button:has-text("Continue to payment")');
  await payOnMockStripe(a);
  check(await a.isVisible('text=Gift sent!'), 'gift purchase confirmed');
  const code = JSON.parse(q("SELECT code FROM wp_oys_gift_cards ORDER BY id DESC LIMIT 1"))[0].code;
  await cpage.goto(`${BASE}/account/`);
  await register(cpage, 'Cora', emailC);
  await cpage.goto(`${BASE}/account/?tab=passes`);
  await cpage.fill('#oys-gift-code', code);
  await Promise.all([cpage.waitForNavigation(), cpage.click('button:has-text("Redeem")')]);
  check(await cpage.isVisible('text=Gift redeemed'), 'gift redeemed');
  check(php(`echo OYS_Passes::balance(get_user_by('email','${emailC}')->ID,'class');`) === '5', 'gift added 5 classes');
  await cpage.fill('#oys-gift-code', code);
  await Promise.all([cpage.waitForNavigation(), cpage.click('button:has-text("Redeem")')]);
  check(await cpage.isVisible("text=isn't valid or has already been used"), 'gift code cannot be used twice');

  // 8. Payment returns before the webhook: the return page fulfils; the late webhook is a no-op.
  const s4 = JSON.parse(q(`SELECT id FROM wp_oys_sessions WHERE kind='group' AND status='scheduled' AND booked=0 AND id NOT IN (${s1},${s2},${s3}) AND starts_at > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) ORDER BY starts_at LIMIT 1`))[0].id;
  await cpage.goto(`${BASE}/book/?session=${s4}`);
  await cpage.check('input[value="card"]');
  await cpage.click('.oys-submit');
  await payOnMockStripe(cpage, '#pay-no-webhook');
  check(await cpage.isVisible("text=You're booked!"), 'return page confirms even before the webhook');
  const o4 = JSON.parse(q("SELECT id, stripe_session_id FROM wp_oys_orders ORDER BY id DESC LIMIT 1"))[0];
  const before = mails().length;
  execSync(`curl -s "http://127.0.0.1:8090/_webhook?type=checkout.session.completed&id=${o4.stripe_session_id}"`);
  check(mails().length === before, 'late webhook does not fulfil twice');

  // 9. Abandoned checkout: going back from Stripe releases the seat.
  const booked = () => parseInt(JSON.parse(q(`SELECT booked FROM wp_oys_sessions WHERE id=${s4}`))[0].booked, 10);
  const beforeSeats = booked();
  await bpage.goto(`${BASE}/book/?session=${s4}`);
  await bpage.check('input[value="card"]');
  await bpage.click('.oys-submit');
  await bpage.waitForURL(/8090\/pay/);
  check(booked() === beforeSeats + 1, 'seat held during checkout');
  await Promise.all([bpage.waitForNavigation(), bpage.click('#cancel')]);
  check(await bpage.isVisible('text=Payment cancelled'), 'cancel page shown');
  check(booked() === beforeSeats, 'seat released after cancelling');

  // 10. Refund from the admin side.
  php(`OYS_Stripe::refund(${o4.id});`);
  const o4b = JSON.parse(q(`SELECT o.status, b.status AS bstatus FROM wp_oys_orders o JOIN wp_oys_bookings b ON b.id=o.booking_id WHERE o.id=${o4.id}`))[0];
  check(o4b.status === 'refunded' && o4b.bstatus === 'cancelled', 'full refund cancels the booking');

  // 11. Security: a forged webhook is rejected.
  const forged = execSync(`curl -s -o /dev/null -w "%{http_code}" -X POST -H "Stripe-Signature: t=${Math.floor(Date.now() / 1000)},v1=deadbeef" -d '{"id":"evt_x","type":"checkout.session.completed","data":{"object":{}}}' ${BASE}/wp-json/oys/v1/stripe-webhook`).toString();
  check(forged === '400', 'forged webhook rejected (400)');

  // Screens for review.
  await a.goto(`${BASE}/schedule-pricing/`);
  await a.screenshot({ path: `${SHOTS}/10-schedule-pricing.png`, fullPage: true });
  await a.goto(`${BASE}/account/?tab=payments`);
  await a.screenshot({ path: `${SHOTS}/11-payments.png`, fullPage: true });
  const m = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true });
  const mp = await m.newPage();
  await mp.goto(`${BASE}/book/?session=${s4}`);
  await mp.screenshot({ path: `${SHOTS}/12-mobile-book.png`, fullPage: true });

  await browser.close();
  console.log(failures ? `\n${failures} check(s) failed` : '\nAll checks passed');
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
