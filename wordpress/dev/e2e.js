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
  // Rate-limit counters from earlier runs (all test sign-ups come from 127.0.0.1).
  php(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%oys_rl_%'");`);
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


  // ---------- Guests ----------
  const used = [s1, s2, s3, s4];
  let freshN = 0;
  const fresh = () => {
    // A new group class 2+ days ahead, just for this test step.
    freshN++;
    const id = php(`$t = time() + (2 * DAY_IN_SECONDS) + (${freshN} * 3 * HOUR_IN_SECONDS); echo OYS_Schedule::save(array('kind'=>'group','class_slug'=>'hatha-flow','starts_at'=>gmdate('Y-m-d H:i:s',$t),'ends_at'=>gmdate('Y-m-d H:i:s',$t+3600),'capacity'=>12,'location'=>'Test studio','price_cents'=>2500,'credits_allowed'=>1,'status'=>'scheduled'));`);
    used.push(id);
    return id;
  };
  const rowsOf = (sid) => JSON.parse(q(`SELECT id, status, paid_with, guest_of, guest_name, guest_email, order_id FROM wp_oys_bookings WHERE session_id=${sid} ORDER BY id`));
  async function addGuests(page, names, emails = []) {
    for (let i = 0; i < names.length; i++) {
      if (!(await page.isVisible(`#oys-guest-name-${i}`))) await page.click('[data-guest-add]');
      await page.fill(`#oys-guest-name-${i}`, names[i]);
      if (emails[i]) await page.fill(`#oys-guest-email-${i}`, emails[i]);
    }
  }

  // 12. Card: Anna + 2 guests, one receipt with separate guest line.
  const g1 = fresh();
  await a.goto(`${BASE}/book/?session=${g1}`);
  await addGuests(a, ['Bea Guest', 'Cora Guest'], [`bea.guest${stamp}@example.com`]);
  const partySize = (await a.textContent('[data-party-size]')).trim();
  check(partySize === '3', 'party size shows 3 people (got ' + partySize + ')');
  await a.check('input[value="card"]');
  await a.screenshot({ path: `${SHOTS}/13-guests-book.png`, fullPage: true });
  await a.click('.oys-submit');
  await a.waitForURL(/8090\/pay/);
  const lines = await a.$$eval('tr.line', rs => rs.map(r => r.textContent.replace(/\s+/g, ' ')));
  check(lines.length === 2 && /Guest ticket/.test(lines[1]) && /× 2/.test(lines[1]), 'Stripe receipt: own spot + "Guest ticket × 2" line');
  check(/Total \$75/.test(await a.textContent('#total')), 'total is 3 × $25');
  await a.screenshot({ path: `${SHOTS}/14-guests-stripe.png` });
  await Promise.all([a.waitForNavigation({ url: /oys_return=success/ }), a.click('#pay')]);
  check(await a.isVisible('text=Guests: Bea Guest, Cora Guest'), 'return page lists the guests');
  let rows = rowsOf(g1);
  check(rows.length === 3 && rows.every(r => r.status === 'confirmed' && r.paid_with === 'card'), 'three confirmed rows, all paid by card');
  check(rows.filter(r => r.guest_of == rows[0].id).length === 2, 'guest rows linked to the host booking');
  const o12 = JSON.parse(q(`SELECT amount_cents, description FROM wp_oys_orders WHERE id=${rows[0].order_id}`))[0];
  check(o12.amount_cents == 7500 && /\+2 guests/.test(o12.description), 'order: $75, description mentions +2 guests');
  check(mailSubjects().some(s => s && /booked you into/.test(s)), 'guest with email got an invite');
  check(JSON.parse(q(`SELECT booked FROM wp_oys_sessions WHERE id=${g1}`))[0].booked == 3, 'three seats taken');

  // 13. Pass: Anna brings 2 guests on her pass.
  const g2 = fresh();
  const before13 = bal();
  await a.goto(`${BASE}/book/?session=${g2}`);
  await addGuests(a, ['Dora Guest', 'Emil Guest']);
  await a.check('input[value="credit"]');
  await Promise.all([a.waitForNavigation(), a.click('.oys-submit')]);
  rows = rowsOf(g2);
  check(rows.length === 3 && rows.every(r => r.paid_with === 'credit'), 'party booked from the pass');
  check(bal() === before13 - 3, 'three classes taken from the pass');

  // 14. Remove one guest from the account: their class comes back.
  await a.goto(`${BASE}/account/`);
  await a.screenshot({ path: `${SHOTS}/15-account-guests.png`, fullPage: true });
  a.once('dialog', d => d.accept());
  await Promise.all([a.waitForNavigation(), a.locator('.oys-guestlist li', { hasText: 'Emil Guest' }).locator('button').click()]);
  check(rowsOf(g2).find(r => r.guest_name === 'Emil Guest').status === 'cancelled', 'guest removed');
  check(bal() === before13 - 2, 'removed guest\'s class returned');

  // 15. Add a guest later to the same booking.
  await a.goto(`${BASE}/book/?session=${g2}`);
  check(await a.isVisible('text=Bring guests'), 'booked page offers "Bring guests"');
  await a.fill('#oys-guest-name-0', 'Flora Guest');
  await a.check('input[value="credit"]');
  await Promise.all([a.waitForNavigation(), a.click('.oys-submit')]);
  rows = rowsOf(g2);
  check(rows.some(r => r.guest_name === 'Flora Guest' && r.status === 'confirmed' && r.guest_of == rows[0].id), 'guest added to existing booking');
  check(bal() === before13 - 3, 'added guest paid from the pass');

  // 16. Cancelling the host cancels the guests and returns all classes.
  const hostId = rows[0].id;
  php(`OYS_Bookings::cancel(${hostId});`);
  rows = rowsOf(g2);
  check(rows.filter(r => r.status === 'confirmed').length === 0, 'host cancel cancels guests too');
  check(bal() === before13, 'all classes returned');
  check(JSON.parse(q(`SELECT booked FROM wp_oys_sessions WHERE id=${g2}`))[0].booked == 0, 'all seats released');

  // 17. Not enough spots for the whole party.
  const g3 = fresh();
  php(`global $wpdb; $wpdb->update($wpdb->prefix.'oys_sessions', array('capacity'=>2), array('id'=>${g3}));`);
  await a.goto(`${BASE}/book/?session=${g3}`);
  check(await a.locator('[data-guest]').count() === 1, 'guest rows limited by spots left');

  // ---------- Memberships ----------
  const ctxD = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const d = await ctxD.newPage();
  const emailD = `dani${stamp}@example.com`;
  await d.goto(`${BASE}/book/?product=four-a-month`);
  await register(d, 'Dani', emailD);
  await d.screenshot({ path: `${SHOTS}/16-join.png`, fullPage: true });
  await d.click('.oys-submit');
  await d.waitForURL(/8090\/pay/);
  check(/SUBSCRIPTION/.test(await d.textContent('body')), 'Stripe checkout in subscription mode');
  await Promise.all([d.waitForNavigation({ url: /oys_return=success/ }), d.click('#pay')]);
  check(await d.isVisible('text=Welcome, member!'), 'membership welcome page');
  const uidD = php(`echo get_user_by('email','${emailD}')->ID;`);
  let mem = JSON.parse(q(`SELECT * FROM wp_oys_memberships WHERE user_id=${uidD}`))[0];
  check(mem && mem.status === 'active' && mem.current_period_end && mem.classes_per_period == 4, 'membership active with period and 4-class limit');
  check(mailSubjects().some(s => s === 'Your membership is active'), 'membership welcome email');

  // 18. Book with the membership + a guest (no pass → guest paid by card).
  const m1 = fresh();
  await d.goto(`${BASE}/book/?session=${m1}`);
  check(await d.isVisible('text=Use my membership'), 'membership option offered');
  await addGuests(d, ['Gabi Guest']);
  await d.check('input[value="membership"]');
  await d.click('.oys-submit');
  await d.waitForURL(/8090\/pay/);
  check(/Guest ticket/.test(await d.textContent('body')) && /Total \$25/.test(await d.textContent('#total')), 'only the guest is charged');
  await Promise.all([d.waitForNavigation({ url: /oys_return=success/ }), d.click('#pay')]);
  rows = rowsOf(m1);
  check(rows.length === 2 && rows[0].paid_with === 'membership' && rows[1].paid_with === 'card' && rows[1].guest_of == rows[0].id, 'member on membership, guest on card');

  // 19. Class limit per period.
  for (let i = 0; i < 3; i++) {
    const sid = fresh();
    await d.goto(`${BASE}/book/?session=${sid}`);
    await d.check('input[value="membership"]');
    await Promise.all([d.waitForNavigation(), d.click('.oys-submit')]);
  }
  mem = JSON.parse(q(`SELECT * FROM wp_oys_memberships WHERE user_id=${uidD}`))[0];
  check(php(`echo OYS_Memberships::used_in_period(OYS_Memberships::get(${mem.id}));`) === '4', '4 classes used this period');
  const m5 = fresh();
  await d.goto(`${BASE}/book/?session=${m5}`);
  check(!(await d.isVisible('input[value="membership"]')), 'membership no longer offered after 4 classes');
  const direct = php(`$r = OYS_Bookings::book_with_membership(${uidD}, ${m5}); echo is_wp_error($r) ? $r->get_error_code() : 'booked';`);
  check(direct === 'oys_membership', 'a 5th class is refused');

  // 20. Account membership tab, portal.
  await d.goto(`${BASE}/account/?tab=membership`);
  await d.screenshot({ path: `${SHOTS}/17-membership-tab.png`, fullPage: true });
  check(await d.isVisible('text=4 of 4 used'), 'membership tab shows usage');
  await Promise.all([d.waitForNavigation(), d.click('button:has-text("Update card & invoices")')]);
  check(/MOCK STRIPE CUSTOMER PORTAL/.test(await d.textContent('body')), 'billing portal opens');
  await Promise.all([d.waitForNavigation(), d.click('#portal-return')]);

  // 21. Renewal: new period, renewal recorded as a payment, usage resets.
  const sub = mem.stripe_subscription_id;
  execSync(`curl -s "http://127.0.0.1:8090/_renew?sub=${sub}"`);
  const mem2 = JSON.parse(q(`SELECT * FROM wp_oys_memberships WHERE id=${mem.id}`))[0];
  check(mem2.current_period_start > mem.current_period_start, 'period advanced after renewal');
  check(JSON.parse(q(`SELECT COUNT(*) AS n FROM wp_oys_orders WHERE user_id=${uidD} AND type='membership' AND status='paid'`))[0].n == 2, 'renewal recorded as a second membership payment');

  // 22. Failed payment → past due + email; then cancel at period end and resume from the account.
  execSync(`curl -s "http://127.0.0.1:8090/_fail?sub=${sub}"`);
  check(JSON.parse(q(`SELECT status FROM wp_oys_memberships WHERE id=${mem.id}`))[0].status === 'past_due', 'failed renewal → past due');
  check(mailSubjects().some(s => s && /payment failed/.test(s)), 'payment failed email');
  await d.goto(`${BASE}/account/?tab=membership`);
  d.once('dialog', x => x.accept());
  await Promise.all([d.waitForNavigation(), d.click('button:has-text("Cancel membership")')]);
  check(JSON.parse(q(`SELECT cancel_at_period_end FROM wp_oys_memberships WHERE id=${mem.id}`))[0].cancel_at_period_end == 1, 'cancel at period end scheduled');
  check(await d.isVisible('text=Keep my membership'), 'resume offered');
  await Promise.all([d.waitForNavigation(), d.click('button:has-text("Keep my membership")')]);
  check(JSON.parse(q(`SELECT cancel_at_period_end FROM wp_oys_memberships WHERE id=${mem.id}`))[0].cancel_at_period_end == 0, 'membership resumed');

  // 23. Subscription ends: status ended, future membership bookings cancelled.
  execSync(`curl -s "http://127.0.0.1:8090/_end?sub=${sub}"`);
  check(JSON.parse(q(`SELECT status FROM wp_oys_memberships WHERE id=${mem.id}`))[0].status === 'cancelled', 'membership ended');
  check(JSON.parse(q(`SELECT COUNT(*) AS n FROM wp_oys_bookings WHERE membership_id=${mem.id} AND status='confirmed'`))[0].n == 0, 'future membership bookings cancelled');

  // 24. Rate limiting: repeated wrong passwords lock the login.
  const ctxE = await browser.newContext();
  const e = await ctxE.newPage();
  for (let i = 0; i < 7; i++) {
    await e.goto(`${BASE}/account/?oys_view=login`);
    await e.fill('#oys-login-email', emailD);
    await e.fill('#oys-login-password', 'wrong-' + i);
    await Promise.all([e.waitForNavigation(), e.click('#oys-login button[type="submit"]')]);
  }
  check(await e.isVisible('text=Too many failed attempts'), 'login locked after repeated failures');
  php(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%oys_rl_%'");`);

  // 25. No page scrolls sideways (logged out and logged in, desktop and phone).
  const overflow = async (page, url) => { await page.goto(url); return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth); };
  const anon = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  const phone = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  for (const path of ['/', '/schedule-pricing/', '/book/', '/account/', '/gift-cards/', '/private-yoga/', `/book/?session=${g1}`]) {
    check(await overflow(anon, BASE + path) <= 0, `no sideways scroll, logged out: ${path}`);
    check(await overflow(phone, BASE + path) <= 0, `no sideways scroll, phone: ${path}`);
    check(await overflow(a, BASE + path) <= 0, `no sideways scroll, logged in: ${path}`);
  }

  // 26. Started paying, then left Stripe with the browser's back button (not Stripe's cancel link).
  //     The class must not count as booked; booking again closes the old Stripe page and starts a new one.
  const u1 = fresh();
  await a.goto(`${BASE}/book/?session=${u1}`);
  await a.check('input[value="card"]');
  await a.click('.oys-submit');
  await a.waitForURL(/127\.0\.0\.1:8090\/pay\//);
  const firstPay = a.url();
  await a.goBack();
  await a.goto(`${BASE}/book/?session=${u1}`);
  check(!(await a.isVisible("text=You're booked")), 'unfinished payment is not shown as booked');
  check(await a.isVisible("text=Your payment wasn't finished"), 'unfinished payment notice shown');
  check(await a.isVisible('a:has-text("Continue to payment")'), 'can continue the unfinished payment');
  await a.goto(`${BASE}/schedule-pricing/`);
  check(!(await a.locator(`a[href*="session=${u1}"]`).first().locator('xpath=ancestor::li[1]').locator("text=You're booked").count()), 'timetable does not say booked');
  await a.goto(`${BASE}/book/?session=${u1}`);
  await a.check('input[value="card"]');
  await a.click('.oys-submit');
  await a.waitForURL(/127\.0\.0\.1:8090\/pay\//);
  check(a.url() !== firstPay, 'a new Stripe page was started');
  const oldStripe = await (await fetch(firstPay.replace('/pay/', '/v1/checkout/sessions/'), { headers: { Authorization: 'Bearer sk_test_mock' } })).json();
  check(oldStripe.status === 'expired', 'the old Stripe page was closed so it cannot be paid');
  await Promise.all([a.waitForNavigation({ url: /oys_return=success/ }), a.click('#pay')]);
  const uRows = JSON.parse(q(`SELECT status FROM wp_oys_bookings WHERE session_id=${u1} ORDER BY id`));
  check(uRows.map(r => r.status).join(',') === 'expired,confirmed', 'one booking confirmed, the abandoned hold expired');
  check(parseInt(JSON.parse(q(`SELECT booked FROM wp_oys_sessions WHERE id=${u1}`))[0].booked, 10) === 1, 'only one seat taken');
  // Paid in the first tab after all, then clicked Book again from a stale page: no second charge.
  const u2 = fresh();
  await a.goto(`${BASE}/book/?session=${u2}`);
  await a.check('input[value="card"]');
  await a.click('.oys-submit');
  await a.waitForURL(/127\.0\.0\.1:8090\/pay\//);
  // Pay "in another tab" that never makes it back to the site (and no webhook yet).
  await fetch(a.url(), { method: 'POST', body: new URLSearchParams({ skip_webhook: '1' }), redirect: 'manual' });
  check(JSON.parse(q(`SELECT status FROM wp_oys_orders WHERE session_id=${u2}`))[0].status === 'pending', 'paid on Stripe, site not told yet');
  await a.goBack();
  await a.goto(`${BASE}/book/?session=${u2}`);
  await a.check('input[value="card"]');
  await Promise.all([a.waitForNavigation(), a.click('.oys-submit')]);
  check(await a.isVisible('text=went through'), 'an earlier payment that went through is recognised');
  check(JSON.parse(q(`SELECT COUNT(*) AS n FROM wp_oys_orders WHERE session_id=${u2} AND status='paid'`))[0].n == 1, 'charged once');

  // 27. Online classes: own price, a studio pass class converts into 4 online classes, online pass.
  const on1 = fresh(), on2 = fresh();
  php(`global $wpdb; $wpdb->query("UPDATE {$wpdb->prefix}oys_sessions SET format='online', price_cents=600, location='', online_url='https://zoom.example/j/1' WHERE id IN (${on1},${on2})");`);
  const zoe = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  await zoe.goto(`${BASE}/book/?session=${on1}`);
  await register(zoe, 'Zoe', `zoe${stamp}@example.com`);
  const zoeId = php(`echo get_user_by('email','zoe${stamp}@example.com')->ID;`);
  check(await zoe.isVisible('.oys-option__price:has-text("$6")'), 'online class costs $6 by card');
  php(`OYS_Passes::grant(${zoeId}, array('credits'=>1,'name'=>'5-class pass','validity_days'=>60));`);
  await zoe.goto(`${BASE}/book/?session=${on1}`);
  check(await zoe.isVisible('text=Use my pass (4 online classes)'), 'one studio class shows as 4 online classes');
  check(await zoe.isVisible('text=covers 4 online classes'), 'conversion is explained');
  await zoe.screenshot({ path: `${SHOTS}/13-online-book.png`, fullPage: true });
  await zoe.check('input[value="credit"]');
  await Promise.all([zoe.waitForNavigation(), zoe.click('.oys-submit')]);
  check(await zoe.isVisible("text=You're booked"), 'online class booked with the pass');
  const zbal = k => parseInt(php(`echo OYS_Passes::balance(${zoeId},'${k}');`), 10);
  check(zbal('class') === 0 && zbal('online') === 3, 'studio class converted: 3 online classes left');
  await zoe.goto(`${BASE}/account/?tab=passes`);
  check(await zoe.isVisible('text=Online classes (from 5-class pass)'), 'converted online classes show in the account');
  await zoe.goto(`${BASE}/book/?session=${on2}`);
  check(await zoe.isVisible('text=Use my pass (3 online classes)'), 'next online class uses the online classes');
  await zoe.goto(`${BASE}/book/?product=online-10`);
  check(await zoe.isVisible('text=Online 10-class pass'), 'online pass can be bought');
  await zoe.click('.oys-submit');
  await payOnMockStripe(zoe);
  check(zbal('online') === 13, 'online pass adds 10 online classes');
  await zoe.goto(`${BASE}/schedule-pricing/`);
  check(await zoe.isVisible('text=Online classes: $6'), 'pricing shows the online price');
  await zoe.goto(`${BASE}/account/?tab=private`);
  check(await zoe.locator('#oys-pr-duration option', { hasText: 'online' }).count() > 0, 'private sessions show an online price');

  // 28. Admin calendar: add a class by clicking, move it by dragging, weekly class, change the series, stop it.
  const adm = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
  adm.on('pageerror', e => console.log('Calendar JS error:', e.message));
  // Straight to the studio pages: the WordPress dashboard fetches news from the internet, which can be slow.
  await adm.goto(`${BASE}/wp-login.php?redirect_to=${encodeURIComponent(BASE + "/wp-admin/admin.php?page=oys")}`);
  await adm.fill('#user_login', 'admin');
  await adm.fill('#user_pass', 'admin12345');
  await Promise.all([adm.waitForNavigation(), adm.click('#wp-submit')]);
  const week = php(`echo (new DateTimeImmutable('monday this week', wp_timezone()))->modify('+35 days')->format('Y-m-d');`);
  const wed = php(`echo (new DateTimeImmutable('${week}', wp_timezone()))->modify('+2 days')->format('Y-m-d');`);
  const thu = php(`echo (new DateTimeImmutable('${week}', wp_timezone()))->modify('+3 days')->format('Y-m-d');`);
  const fri = php(`echo (new DateTimeImmutable('${week}', wp_timezone()))->modify('+4 days')->format('Y-m-d');`);
  // Leftovers of earlier runs would sit in the slots this test clicks.
  php(`global $wpdb; $p = $wpdb->prefix; $wpdb->query("UPDATE {$p}oys_templates SET active = 0 WHERE location = 'Calendar test studio'"); $wpdb->query("UPDATE {$p}oys_sessions SET status = 'cancelled' WHERE location = 'Calendar test studio' OR online_url = 'https://zoom.example/j/2'");`);
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-calendar&week=${week}`);
  await adm.waitForSelector('.oys-cal__grid');
  const slotY = async (date, time) => {
    const col = adm.locator(`.oys-cal__col[data-date="${date}"]`);
    const box = await col.boundingBox();
    const h0 = parseInt(await col.getAttribute('data-h0'), 10);
    const [h, m] = time.split(':').map(Number);
    return { x: box.x + box.width / 2, y: box.y + ((h * 60 + m) - h0 * 60) / 60 * 64 + 10 };
  };
  let pt = await slotY(wed, '06:00');
  await adm.mouse.click(pt.x, pt.y);
  await adm.waitForSelector('.oys-drawer');
  check((await adm.inputValue('.oys-drawer [name=start]')) === '06:00', 'clicked time is filled in');
  await adm.selectOption('.oys-drawer [name=class_slug]', { index: 1 });
  await adm.click('.oys-drawer .oys-seg label:has-text("Online")');
  check((await adm.inputValue('.oys-drawer [name=price]')) === '6', 'online price filled in');
  await adm.fill('.oys-drawer [name=online_url]', 'https://zoom.example/j/2');
  await adm.screenshot({ path: `${SHOTS}/14-calendar-new.png` });
  await adm.click('.oys-drawer [data-save]');
  await adm.waitForSelector('.oys-toast');
  const created = JSON.parse(q(`SELECT id, format, price_cents FROM wp_oys_sessions WHERE online_url='https://zoom.example/j/2' ORDER BY id DESC LIMIT 1`))[0];
  check(created && created.format === 'online' && +created.price_cents === 600, 'online class created from the calendar');
  // Drag it to Thursday 07:00.
  await adm.waitForSelector(`.oys-ev[data-id="${created.id}"]`);
  const evBox = await adm.locator(`.oys-ev[data-id="${created.id}"]`).boundingBox();
  pt = await slotY(thu, '07:00');
  await adm.mouse.move(evBox.x + 20, evBox.y + 10);
  await adm.mouse.down();
  await adm.mouse.move(evBox.x + 40, evBox.y + 30, { steps: 4 });
  await adm.mouse.move(pt.x, pt.y, { steps: 12 });
  await adm.mouse.up();
  await adm.waitForSelector('.oys-toast:has-text("Saved")', { timeout: 5000 }).catch(() => {});
  const movedRow = JSON.parse(q(`SELECT starts_at FROM wp_oys_sessions WHERE id=${created.id}`))[0];
  check(php(`echo wp_date('Y-m-d H:i', oys_ts('${movedRow.starts_at}'));`) === `${thu} 07:00`, 'dragging moves the class');
  // A weekly class on Friday 08:00.
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-calendar&week=${week}`);
  await adm.waitForSelector('.oys-cal__grid');
  pt = await slotY(fri, '08:00');
  await adm.mouse.click(pt.x, pt.y);
  await adm.waitForSelector('.oys-drawer');
  await adm.selectOption('.oys-drawer [name=class_slug]', { index: 1 });
  await adm.fill('.oys-drawer [name=location]', 'Calendar test studio');
  await adm.selectOption('.oys-drawer [name=repeat]', 'weekly');
  await adm.click('.oys-drawer [data-save]');
  await adm.waitForSelector('.oys-toast:has-text("Weekly class created")');
  const tplRow = JSON.parse(q(`SELECT id, valid_from FROM wp_oys_templates WHERE location='Calendar test studio' ORDER BY id DESC LIMIT 1`))[0];
  check(tplRow && tplRow.valid_from === fri, 'weekly class starts on the chosen Friday');
  const series = JSON.parse(q(`SELECT id FROM wp_oys_sessions WHERE template_id=${tplRow.id} ORDER BY starts_at`));
  check(series.length >= 1, 'weekly dates created');
  // Book a customer on it, then change the time for this and following weeks and email them.
  php(`OYS_Bookings::book_manual(${zoeId}, ${series[0].id}, 'comp', false);`);
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-calendar&week=${week}&open=${series[0].id}`);
  await adm.waitForSelector('.oys-drawer');
  check(await adm.isVisible('.oys-drawer__people >> text=Zoe'), 'drawer lists who is booked');
  await adm.fill('.oys-drawer [name=start]', '08:30');
  const mailsBefore = mailSubjects().filter(x => x && x.startsWith('Changed:')).length;
  await adm.click('.oys-drawer [data-save]');
  await adm.waitForSelector('.oys-modal');
  await adm.screenshot({ path: `${SHOTS}/15-calendar-scope.png` });
  check(await adm.isVisible('.oys-modal >> text=Email the 1 person booked'), 'asks to email the person booked');
  await adm.click('.oys-modal button:has-text("This and following weeks")');
  await adm.waitForSelector('.oys-toast:has-text("Weekly class updated")');
  check(JSON.parse(q(`SELECT start_time FROM wp_oys_templates WHERE id=${tplRow.id}`))[0].start_time === '08:30', 'weekly time changed');
  check(mailSubjects().filter(x => x && x.startsWith('Changed:')).length === mailsBefore + 1, 'booked customer emailed about the new time');
  // Stop the weekly class.
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-calendar&week=${week}&open=${series[0].id}`);
  await adm.waitForSelector('.oys-drawer');
  await adm.click('.oys-drawer [data-cancel]');
  await adm.fill('.oys-modal [name=reason]', 'Schedule change');
  await adm.click('.oys-modal button:has-text("Stop the weekly class")');
  await adm.waitForSelector('.oys-toast:has-text("Weekly class stopped")');
  check(JSON.parse(q(`SELECT active FROM wp_oys_templates WHERE id=${tplRow.id}`))[0].active == 0, 'weekly class stopped');
  check(JSON.parse(q(`SELECT COUNT(*) AS n FROM wp_oys_sessions WHERE template_id=${tplRow.id} AND status='scheduled'`))[0].n == 0, 'its dates are cancelled');
  await adm.screenshot({ path: `${SHOTS}/16-calendar.png`, fullPage: true });
  const admPhone = await (await browser.newContext({ viewport: { width: 390, height: 844 }, storageState: await adm.context().storageState() })).newPage();
  await admPhone.goto(`${BASE}/wp-admin/admin.php?page=oys-calendar&week=${week}`);
  await admPhone.waitForSelector('.oys-cal__daytabs');
  check(await admPhone.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) <= 0, 'calendar fits a phone');

  // 29. Hybrid class with automatic Zoom: studio full → join live online ($6), Zoom link in email,
  //     account and booking page; the host starts the meeting from the roster; cancelling deletes it.
  const zoomMeetings = async () => (await (await fetch('http://127.0.0.1:8090/zoom/_meetings')).json());
  const hy = fresh();
  php(`global $wpdb; $wpdb->query("UPDATE {$wpdb->prefix}oys_sessions SET format='hybrid', capacity=1, online_capacity=0, online_price_cents=600, online_url='' WHERE id=${hy}"); OYS_Bookings::book_manual(OYS_Customers::find_or_create('studio${stamp}@example.com','Stu Dio'), ${hy}, 'comp', false);`);
  await zoe.goto(`${BASE}/book/?session=${hy}`);
  check(await zoe.isVisible('.oys-mode.is-active:has-text("Live online")'), 'full studio: live online is offered');
  check(await zoe.isVisible('.oys-mode:has-text("In the studio"):has-text("Full")'), 'studio shown as full');
  check(await zoe.isVisible('.oys-option__price:has-text("$6")'), 'online ticket price $6');
  await zoe.screenshot({ path: `${SHOTS}/17-hybrid-book.png`, fullPage: true });
  await zoe.check('input[value="card"]');
  await zoe.click('.oys-submit');
  await payOnMockStripe(zoe);
  await zoe.goto(`${BASE}/book/?session=${hy}`);
  const joinHref = await zoe.getAttribute('.oys-join', 'href').catch(() => '');
  check(joinHref && joinHref.startsWith('http://127.0.0.1:8090/zoom/j/'), 'booking page shows the Zoom join link');
  const hyRow = JSON.parse(q(`SELECT b.mode, s.online_booked, s.booked, s.zoom_meeting_id FROM wp_oys_bookings b JOIN wp_oys_sessions s ON s.id=b.session_id WHERE b.session_id=${hy} AND b.user_id=${zoeId}`))[0];
  check(hyRow && hyRow.mode === 'online' && +hyRow.online_booked === 1 && +hyRow.booked === 1, 'online seat taken, studio seat untouched');
  check(hyRow && hyRow.zoom_meeting_id && (await zoomMeetings())[hyRow.zoom_meeting_id], 'Zoom meeting created automatically');
  const hyMail = mails().map(f => fs.readFileSync(path.join(WP_DIR, 'wp-content/mail-log', f), 'utf8')).filter(h => h.includes('Join the live class') && h.includes(`/zoom/j/${hyRow.zoom_meeting_id}`));
  check(hyMail.length > 0, 'confirmation email has the Zoom link');
  await zoe.goto(`${BASE}/account/`);
  check(await zoe.isVisible('a.oys-join:has-text("Join live online")'), 'account shows the join button');
  // Host side
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-schedule&session=${hy}`);
  check(await adm.isVisible('.oys-online-tag'), 'roster marks the online participant');
  const [hostPage] = await Promise.all([adm.context().waitForEvent('page'), adm.click('a:has-text("Start the Zoom class (host)")')]);
  await hostPage.waitForLoadState();
  check(await hostPage.isVisible('#zoom-host'), 'host start link opens the Zoom meeting');
  await hostPage.close();
  await adm.screenshot({ path: `${SHOTS}/18-roster-live.png`, fullPage: true });
  // Moving the class moves the meeting; cancelling deletes it.
  php(`$s = OYS_Schedule::get(${hy}); OYS_Schedule::save(array('starts_at'=>gmdate('Y-m-d H:i:s', oys_ts($s->starts_at)+1800), 'ends_at'=>gmdate('Y-m-d H:i:s', oys_ts($s->ends_at)+1800)), ${hy});`);
  const moved = (await zoomMeetings())[hyRow.zoom_meeting_id];
  const hyStart = JSON.parse(q(`SELECT starts_at FROM wp_oys_sessions WHERE id=${hy}`))[0].starts_at.replace(' ', 'T') + 'Z';
  check(moved && moved.start_time === hyStart, 'moving the class moves the Zoom meeting');
  php(`OYS_Schedule::cancel_session(${hy}, 'Test');`);
  check(!(await zoomMeetings())[hyRow.zoom_meeting_id], 'cancelling the class deletes the Zoom meeting');
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-settings`);
  await Promise.all([adm.waitForNavigation(), adm.click('button:has-text("Test the connection")')]);
  check(await adm.isVisible('text=Zoom is connected (olivia@example.com)'), 'Zoom connection test works');

  // 30. Studio → Emails & reminders: switch an email off, edit a text, send a test, reset.
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-emails`);
  check(await adm.isVisible('h2:has-text("Reminders")'), 'emails page lists the reminders');
  check(await adm.locator('table.oys-emails tbody tr').count() >= 25, 'every automatic email is listed');
  await adm.fill('input[name="s[reminder2_hours]"]', '2');
  await adm.uncheck('input[type=checkbox][name="on[studio_payment]"]');
  await adm.screenshot({ path: `${SHOTS}/19-emails.png`, fullPage: true });
  await Promise.all([adm.waitForNavigation(), adm.click('input[type=submit][value="Save emails and reminders"]')]);
  check(php(`echo (int) OYS_Settings::get('reminder2_hours');`) === '2', 'second reminder saved');
  check(php(`echo OYS_Email_Templates::enabled('studio_payment') ? 'on' : 'off';`) === 'off', 'studio payment email switched off');
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-emails&edit=booking_confirmed`);
  await adm.fill('#oys-e-subject', 'Yay {first_name}: {class} on {date_short}');
  await Promise.all([adm.waitForNavigation(), adm.click('#submit')]);
  check(await adm.isVisible('text=Saved.'), 'email text saved');
  const frame = adm.frameLocator('.oys-email-preview iframe');
  check(await frame.locator('text=You\'re booked').count() > 0, 'preview shows the email');
  await Promise.all([adm.waitForNavigation(), adm.click('button:has-text("Send a test to")')]);
  check(mailSubjects().some(x => x && x.startsWith('[Test] Yay ')), 'test email sent with the new subject');
  await adm.screenshot({ path: `${SHOTS}/20-email-edit.png`, fullPage: true });
  const eu = fresh();
  php(`OYS_Bookings::book_manual(${zoeId}, ${eu}, 'comp', true);`);
  check(mailSubjects().some(x => x && x.startsWith('Yay Zoe: Hatha Flow on ')), 'real booking email uses the edited subject');
  adm.once('dialog', d => d.accept());
  await Promise.all([adm.waitForNavigation(), adm.click('button:has-text("Reset to the original text")')]);
  check(php(`echo OYS_Email_Templates::get('booking_confirmed')['subject'];`) === 'Booked: {class}, {date_short}', 'reset to the original text');
  php(`OYS_Email_Templates::save('studio_payment', array('enabled'=>1)); OYS_Settings::update(array('reminder2_hours'=>0));`);

  // 31. Pay at the studio: book without paying, the roster marks it paid, message everyone booked.
  php(`OYS_Settings::update(array('pay_later'=>'all','pay_later_max_no_shows'=>2,'donation_min_cents'=>500,'donation_suggestions'=>'5,10,15,20','fb_group_url'=>'https://www.facebook.com/groups/485915674202871'));`);
  const ps = fresh();
  const rae = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  await rae.goto(`${BASE}/book/?session=${ps}`);
  check(await rae.isVisible('.oys-fb a[href*="facebook.com/groups/"]'), 'Facebook group link next to the newsletter sign-up');
  await register(rae, 'Rae', `rae${stamp}@example.com`);
  const raeId = php(`echo get_user_by('email','rae${stamp}@example.com')->ID;`);
  check(await rae.isVisible('.oys-option:has-text("Pay at the studio")'), 'pay at the studio offered');
  check(await rae.isVisible('text=Pay at the studio with cash, Venmo or Zelle.'), 'how to pay at the studio is explained');
  await rae.check('input[value="door"]');
  await rae.screenshot({ path: `${SHOTS}/21-pay-at-studio.png`, fullPage: true });
  await Promise.all([rae.waitForNavigation(), rae.click('.oys-submit')]);
  check(await rae.isVisible("text=You're booked"), 'booked without paying online');
  check(await rae.isVisible('.oys-due:has-text("$25")'), 'booking page says $25 to pay at the studio');
  const pb = JSON.parse(q(`SELECT paid_with, due_cents FROM {$wpdb->prefix}oys_bookings WHERE session_id=${ps} AND user_id=${raeId} AND status='confirmed'`))[0];
  check(pb && pb.paid_with === 'door' && +pb.due_cents === 2500, 'stored as due at the studio');
  check(mailSubjects().some(x => x && x.startsWith('Booked: Hatha Flow')), 'confirmation email sent');
  await rae.goto(`${BASE}/account/`);
  check(await rae.isVisible('.oys-due:has-text("$25")') && await rae.isVisible('.oys-account .oys-fb'), 'account shows what to pay and the Facebook group');
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-schedule&session=${ps}`);
  check(await adm.isVisible('text=To collect at the studio: $25'), 'roster: $25 to collect');
  await Promise.all([adm.waitForNavigation(), adm.click('button:has-text("Paid: cash")')]);
  check(await adm.isVisible('text=Paid at the studio (cash)'), 'roster: marked paid in cash');
  check(JSON.parse(q(`SELECT collected_with FROM {$wpdb->prefix}oys_bookings WHERE session_id=${ps} AND user_id=${raeId}`))[0].collected_with === 'cash', 'payment recorded');
  const msgMark = mails().length;
  await adm.fill('.oys-message textarea[name="body"]', 'Hi {first_name}, the parking lot is closed today, park on the street.');
  await Promise.all([adm.waitForNavigation(), adm.click('button:has-text("Send the message")')]);
  check(await adm.isVisible('text=Message sent to 1 person'), 'message sent to everyone booked');
  const msg = mails().slice(msgMark).map(f => fs.readFileSync(path.join(WP_DIR, 'wp-content/mail-log', f), 'utf8')).find(h => h.includes('parking lot'));
  check(!!msg && msg.includes('Hi Rae'), 'message email greets by name');
  check(await adm.isVisible('.oys-sent:has-text("About Hatha Flow")'), 'sent message listed on the roster');
  await adm.screenshot({ path: `${SHOTS}/22-roster-pay-message.png`, fullPage: true });

  // 32. Donation class: pick an amount, minimum $5, card total follows the amount.
  const dn = fresh();
  php(`global $wpdb; $wpdb->update($wpdb->prefix.'oys_sessions', array('pricing'=>'donation','price_cents'=>1000,'credits_allowed'=>0), array('id'=>${dn}));`);
  await rae.goto(`${BASE}/book/`);
  check(await rae.isVisible('.session__donation'), 'timetable says "By donation"');
  await rae.goto(`${BASE}/book/?session=${dn}`);
  check(await rae.isVisible('text=Pay what you like'), 'donation amounts shown');
  check(await rae.isVisible('.oys-option:has-text("Give by card")') && await rae.isVisible('.oys-option:has-text("Give at the studio")'), 'give by card or at the studio');
  await rae.click('.oys-amount:has-text("$20")');
  check((await rae.textContent('.oys-option:has(input[value="card"]) .oys-option__price')).trim() === '$20', 'card total follows the chosen amount');
  await rae.fill('#oys-amount-other', '3');
  check(await rae.$eval('#oys-amount-other', el => !el.checkValidity()), 'the browser stops amounts under $5');
  await rae.$eval('#oys-amount-other', el => el.removeAttribute('min'));
  await rae.check('input[value="card"]');
  await Promise.all([rae.waitForNavigation(), rae.click('.oys-submit')]);
  check(await rae.isVisible('text=The minimum is $5 per person.'), 'below the minimum refused');
  await rae.click('.oys-amount:has-text("$15")');
  await rae.check('input[value="card"]');
  await rae.screenshot({ path: `${SHOTS}/23-donation.png`, fullPage: true });
  await rae.click('.oys-submit');
  await payOnMockStripe(rae);
  check(await rae.isVisible("text=You're booked!"), 'donation paid by card');
  check(+JSON.parse(q(`SELECT amount_cents FROM {$wpdb->prefix}oys_orders WHERE session_id=${dn} AND user_id=${raeId} AND status='paid'`))[0].amount_cents === 1500, 'order is the chosen $15');

  // 33. Private sessions: the first-session note is on the request form.
  await rae.goto(`${BASE}/account/?tab=private`);
  check(await rae.isVisible('.oys-private-note:has-text("extra minutes to talk through your goals")'), 'private session note shown');

  // 34. Locations and the minimum number of people: set per place, shown on the booking page,
  //     the calendar knows the rule, and a short class is cancelled with other dates.
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-locations`);
  const locRows = await adm.$$('.oys-locations tbody tr');
  const blank = locRows.length - 2;
  await adm.fill(`input[name="loc[${blank}][name]"]`, 'E2E Beach');
  await adm.fill(`input[name="loc[${blank}][address]"]`, 'Fort Myers Beach');
  await adm.fill(`input[name="loc[${blank}][min_people]"]`, '3');
  await adm.fill(`input[name="loc[${blank}][decide_hours]"]`, '12');
  await Promise.all([adm.waitForNavigation(), adm.click('button:has-text("Save locations"), input[value="Save locations"]')]);
  check(await adm.isVisible('text=Locations saved.'), 'location saved');
  check(php(`$l = OYS_Locations::all()['e2e-beach'] ?? null; echo $l ? $l['min_people'] . '/' . $l['decide_hours'] : '';`) === '3/12', 'minimum 3, decided 12 hours before');
  await adm.screenshot({ path: `${SHOTS}/24-locations.png`, fullPage: true });
  const mn = fresh();
  php(`global $wpdb; $wpdb->update($wpdb->prefix.'oys_sessions', array('location'=>'E2E Beach'), array('id'=>${mn}));`);
  await rae.goto(`${BASE}/book/?session=${mn}`);
  check(await rae.isVisible('.oys-min-note:has-text("goes ahead with 3 or more people")'), 'booking page explains the minimum');
  const mnDay = php(`echo wp_date('Y-m-d', oys_ts(OYS_Schedule::get(${mn})->starts_at));`);
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-calendar&week=${mnDay}&open=${mn}`);
  await adm.waitForSelector('.oys-drawer');
  check(await adm.isVisible('.oys-drawer__min:has-text("Needs 3 to go ahead")'), 'calendar shows the rule');
  check((await adm.getAttribute('.oys-drawer [name=min_people]', 'placeholder')) === '3', 'calendar: the place default as placeholder');
  check(await adm.isVisible('.oys-drawer .f-min-hint:has-text("12 hours")'), 'calendar explains when it is decided');
  await adm.screenshot({ path: `${SHOTS}/25-calendar-minimum.png` });
  await adm.click('.oys-drawer__x');
  // Rae books it, then the decision time comes with only her booked.
  await rae.goto(`${BASE}/book/?session=${mn}`);
  await rae.check('input[value="door"]');
  await Promise.all([rae.waitForNavigation(), rae.click('.oys-submit')]);
  php(`global $wpdb; $t = time() + 5 * HOUR_IN_SECONDS; $wpdb->update($wpdb->prefix.'oys_sessions', array('starts_at'=>gmdate('Y-m-d H:i:s',$t),'ends_at'=>gmdate('Y-m-d H:i:s',$t+3600)), array('id'=>${mn}));`);
  const cmark = mails().length;
  php(`OYS_Locations::run();`);
  check(php(`echo OYS_Schedule::get(${mn})->status;`) === 'cancelled', 'too few people: cancelled automatically');
  const cmail = mails().slice(cmark).map(f => fs.readFileSync(path.join(WP_DIR, 'wp-content/mail-log', f), 'utf8')).find(h => h.includes('not enough people signed up'));
  check(!!cmail && cmail.includes('Join another class instead') && cmail.includes('bring a friend'), 'email offers other dates and a bring-a-friend tip');
  check(mailSubjects().some(x => x && x.startsWith('[Studio] Cancelled automatically:')), 'the studio is told');
  php(`delete_option('oys_locations');`);

  // 35. Discount code made in the admin, used on a pass by card.
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-rewards`);
  check(await adm.isVisible('h2:has-text("Loyalty draw")'), 'rewards page: loyalty draw');
  const couponCode = `E2E${String(stamp).slice(-6)}`;
  await adm.fill('input[name="code"]', couponCode);
  await adm.fill('input[name="value"]', '10');
  await adm.selectOption('select[name="applies"]', 'passes');
  await Promise.all([adm.waitForNavigation(), adm.click('button:has-text("Create code")')]);
  check(await adm.isVisible(`text=Code ${couponCode} created.`), 'discount code created');
  await rae.goto(`${BASE}/book/?product=pack-5`);
  await rae.click('.oys-coupon summary').catch(() => {});
  await rae.fill('#oys-coupon', couponCode.toLowerCase());
  await rae.click('.oys-submit');
  await payOnMockStripe(rae);
  const po = JSON.parse(q(`SELECT amount_cents FROM {$wpdb->prefix}oys_orders WHERE user_id=${raeId} AND product_id='pack-5' AND status='paid' ORDER BY id DESC LIMIT 1`))[0];
  check(po && +po.amount_cents === 9900, 'pass bought with 10% off ($99)');
  check(php(`echo OYS_Coupons::by_code('${couponCode}')->used;`) === '1', 'code use counted');
  await rae.goto(`${BASE}/book/?product=pack-5`);
  await rae.click('.oys-coupon summary').catch(() => {});
  await rae.fill('#oys-coupon', 'NOPE-123');
  await Promise.all([rae.waitForNavigation(), rae.click('.oys-submit')]);
  check(await rae.isVisible("text=That code isn't valid."), 'wrong code explained');

  // 36. Birthday: set in the profile (optional), gift code on the day, filled in at checkout.
  await rae.goto(`${BASE}/account/?tab=profile`);
  const today = php(`echo wp_date('n') . '|' . wp_date('j');`).split('|');
  await rae.selectOption('#oys-p-bmonth', today[0]);
  await rae.selectOption('#oys-p-bday', today[1]);
  await rae.check('input[name="oys_marketing"]');
  await Promise.all([rae.waitForNavigation(), rae.click('form:has(#oys-p-bmonth) button[type="submit"]')]);
  check(php(`echo get_user_meta(${raeId}, 'oys_birthday', true);`) === `${today[0].padStart(2, '0')}-${today[1].padStart(2, '0')}`, 'birthday saved in the profile');
  php(`OYS_Settings::update(array('birthday_on'=>1,'birthday_percent'=>20,'birthday_days'=>30)); delete_user_meta(${raeId}, 'oys_birthday_sent'); OYS_Rewards::send_birthdays();`);
  check(mailSubjects().some(x => x === 'Happy birthday, Rae!'), 'birthday email with a gift code');
  await rae.goto(`${BASE}/account/?tab=passes`);
  check(await rae.isVisible('.oys-gift-code:has-text("20% off")'), 'account shows the birthday code');
  check(await rae.isVisible('.oys-raffle:has-text("ticket")'), 'account shows the loyalty draw tickets');
  await rae.goto(`${BASE}/book/?product=pack-5`);
  check((await rae.inputValue('#oys-coupon')).startsWith('BDAY-'), 'birthday code filled in at checkout');
  await rae.screenshot({ path: `${SHOTS}/26-birthday-code.png`, fullPage: true });

  // 37. Newsletter written with AI (Claude stand-in), tested, sent in batches, unsubscribe.
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-newsletter`);
  await adm.fill('input[name="ai_api_key"]', 'sk-ant-mock');
  await Promise.all([adm.waitForNavigation(), adm.click('#submit, button:has-text("Save AI settings"), input[value="Save AI settings"]')]);
  check(await adm.isVisible('text=AI settings saved.'), 'AI key saved');
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-newsletter&edit=0`);
  await adm.fill('textarea[name="brief"]', 'Our new Sunday sunrise class starts next week.');
  await Promise.all([adm.waitForNavigation({ timeout: 60000 }), adm.click('button:has-text("Write a new draft")')]);
  check(await adm.isVisible('text=Draft written.'), 'AI draft written');
  check((await adm.inputValue('input[name="subject"]')) === 'News from the studio', 'subject from the AI');
  check((await adm.inputValue('textarea[name="body"]')).includes('Our new Sunday sunrise class starts next week.'), 'body follows the brief');
  const nlFrame = await adm.$('iframe.oys-preview');
  check(!!nlFrame && (await adm.getAttribute('iframe.oys-preview', 'srcdoc')).includes('Coming up'), 'email preview');
  await adm.screenshot({ path: `${SHOTS}/27-newsletter-ai.png`, fullPage: true });
  await Promise.all([adm.waitForNavigation(), adm.click('button:has-text("Send a test")')]);
  check(mailSubjects().some(x => x === '[Test] News from the studio'), 'test newsletter sent');
  adm.once('dialog', d => d.accept());
  await Promise.all([adm.waitForNavigation(), adm.click('button:has-text("Send to")')]);
  check(await adm.isVisible('text=goes out over the next few minutes'), 'newsletter queued');
  const nmark = mails().length;
  php(`OYS_Newsletter::process_queue(1000);`);
  const nl = mails().slice(nmark).map(f => fs.readFileSync(path.join(WP_DIR, 'wp-content/mail-log', f), 'utf8')).find(h => h.includes('Hi Rae') && h.includes('Sunday sunrise'));
  check(!!nl, 'subscriber got the newsletter, by name');
  const unsub = ((nl || '').match(/href="([^"]*oys_unsub=[^"]+)"/) || [])[1];
  check(!!unsub, 'with an unsubscribe link');
  if (unsub) await rae.goto(unsub.replace(/&amp;/g, '&'));
  check(await rae.isVisible("text=You're unsubscribed"), 'one-click unsubscribe');
  check(php(`echo get_user_meta(${raeId}, 'oys_marketing', true);`) === '', 'no longer subscribed');
  php(`OYS_Settings::update(array('ai_api_key'=>''));`);

  // 38. Teachers: profile with a photo, a login, their class in the calendar, their roster and
  //     messages, Stripe Connect; a customer pays by card straight to the teacher, the studio gets its fee.
  php(`OYS_Settings::update(array('stripe_test_connect_webhook'=>'whsec_connect_mock','teacher_share_default'=>50));`);
  const tEmail = `maya${stamp}@example.com`;
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-teachers`);
  await Promise.all([adm.waitForNavigation(), adm.click('a.page-title-action:has-text("Add a teacher")')]);
  await adm.fill('#t-name', 'Maya Green');
  await adm.fill('#t-email', tEmail);
  await adm.fill('#t-headline', 'Yin, restorative and breathwork');
  await adm.fill('#t-bio', 'Maya has taught slow, quiet yoga for ten years.');
  await Promise.all([adm.waitForNavigation(), adm.click('#submit')]);
  check(await adm.isVisible('text=Teacher saved.'), 'teacher added');
  const tId = adm.url().match(/edit=(\d+)/)[1];
  const png = path.join(SHOTS, 'maya.png');
  fs.writeFileSync(png, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAEUlEQVR4nGPQDjDDihiGlgQASAcsQciwrK8AAAAASUVORK5CYII=', 'base64'));
  await adm.setInputFiles('input[name="photos[]"]', png);
  await Promise.all([adm.waitForNavigation(), adm.click('button:has-text("Upload photos")')]);
  check(await adm.isVisible('text=1 photo added.') && await adm.isVisible('.oys-photo img'), 'photo uploaded');
  await Promise.all([adm.waitForNavigation(), adm.click('button:has-text("Give them a login")')]);
  check(await adm.isVisible('text=they got an email to set their password'), 'teacher login created');
  check(mailSubjects().some(x => x && x.startsWith('Your teacher login at')), 'login email sent');
  await adm.screenshot({ path: `${SHOTS}/28-teacher-edit.png`, fullPage: true });
  php(`$u = get_user_by('email','${tEmail}'); wp_set_password('teach-pass-123', $u->ID);`);

  // Olivia puts Maya on a class in the calendar.
  const tc = fresh();
  const tcDay = php(`echo wp_date('Y-m-d', oys_ts(OYS_Schedule::get(${tc})->starts_at));`);
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-calendar&week=${tcDay}&open=${tc}`);
  await adm.waitForSelector('.oys-drawer');
  await adm.selectOption('.oys-drawer [name=teacher_id]', tId);
  await adm.click('.oys-drawer [data-save]');
  await adm.waitForSelector('.oys-modal button:has-text("Save change"), .oys-toast', { timeout: 10000 });
  if (await adm.isVisible('.oys-modal button:has-text("Save change")')) await adm.click('.oys-modal button:has-text("Save change")');
  await adm.waitForSelector('.oys-toast:has-text("Saved")');
  check(php(`echo OYS_Schedule::get(${tc})->teacher_id;`) === tId, 'calendar: class assigned to the teacher');
  check(!!(await adm.waitForSelector(`.oys-ev[data-id="${tc}"] .oys-ev__tag--teacher:has-text("Maya Green")`, { timeout: 10000 }).catch(() => null)), 'calendar shows the teacher on the class');

  // The class page shows who teaches it.
  const cust = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  await cust.goto(`${BASE}/book/?session=${tc}`);
  check(await cust.isVisible('.oys-teacher-mini:has-text("with Maya Green")') && await cust.isVisible('.oys-teacher-mini img'), 'class page: teacher with photo');
  await cust.click('.oys-teacher-mini__bio summary');
  check(await cust.isVisible('text=Maya has taught slow, quiet yoga'), 'class page: about the teacher');

  // Maya logs in: she lands on her classes and connects Stripe.
  const tctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const tp = await tctx.newPage();
  await tp.goto(`${BASE}/wp-login.php`);
  await tp.fill('#user_login', tEmail);
  await tp.fill('#user_pass', 'teach-pass-123');
  await Promise.all([tp.waitForNavigation(), tp.click('#wp-submit')]);
  check(tp.url().includes('page=oys-teach'), 'teacher lands on Teaching');
  check(await tp.isVisible(`.oys-teach-classes a[href*="session=${tc}"]`), 'her class is listed');
  await tp.goto(`${BASE}/wp-admin/admin.php?page=oys-teach-profile`);
  await tp.fill('#t-bio', 'Maya has taught slow, quiet yoga for ten years. She loves long holds.');
  await Promise.all([tp.waitForNavigation(), tp.click('#submit')]);
  check(await tp.isVisible('text=Profile saved.'), 'teacher edits her profile');
  await Promise.all([tp.waitForNavigation(), tp.click('button:has-text("Connect Stripe")')]);
  check(tp.url().includes('127.0.0.1:8090/connect/acct_'), 'Stripe onboarding opens');
  await Promise.all([tp.waitForNavigation(), tp.click('#connect-finish')]);
  check(await tp.isVisible('text=Your Stripe account is connected'), 'Stripe connected');
  await tp.screenshot({ path: `${SHOTS}/29-teacher-profile.png`, fullPage: true });
  const acct = php(`echo OYS_Teachers::get(${tId})->stripe_account;`);
  check(php(`echo OYS_Connect::ready(OYS_Teachers::get(${tId})) ? 'yes' : 'no';`) === 'yes', 'account ready');

  // A customer pays by card: charged on Maya's account, Olivia's 50% as a fee.
  await register(cust, 'Lena', `lena${stamp}@example.com`);
  await cust.check('input[value="card"]');
  await cust.click('.oys-submit');
  await payOnMockStripe(cust);
  check(await cust.isVisible("text=You're booked!"), 'paid and booked');
  const cs = Object.values(JSON.parse(execSync('curl -s http://127.0.0.1:8090/_state').toString()).sessions).filter(x => x.account === acct).pop();
  check(cs && cs.application_fee_amount === 1250 && cs.amount_total === 2500, 'charged on the teacher\'s account with a $12.50 fee');
  const tOrder = JSON.parse(q(`SELECT o.id, o.status, o.meta FROM {$wpdb->prefix}oys_orders o JOIN {$wpdb->prefix}oys_bookings b ON b.order_id=o.id WHERE b.session_id=${tc} ORDER BY o.id DESC LIMIT 1`))[0];
  check(tOrder.status === 'paid' && JSON.parse(tOrder.meta).stripe_account === acct, 'order paid, on the teacher\'s account');
  check(fs.readFileSync('/tmp/mock-stripe-webhooks.log', 'utf8').trim().split('\n').filter(l => l.includes('checkout.session.completed')).pop().includes(' 200 '), 'Connect webhook accepted');
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-orders`);
  check(await adm.isVisible("text=to Maya Green's Stripe · your fee $12.50"), 'Payments: paid to the teacher, the studio fee shown');

  // Her roster: the customer, attendance, a message (replies go to her); other classes stay closed.
  php(`OYS_Bookings::book_manual(${raeId}, ${tc}, 'door', false, true);`);
  await tp.goto(`${BASE}/wp-admin/admin.php?page=oys-teach&session=${tc}`);
  check(await tp.isVisible('.oys-teach-roster >> text=Lena') && await tp.isVisible('.oys-teach-roster >> text=Rae'), 'teacher sees who is booked');
  check(await tp.isVisible('.oys-teach-roster .oys-pill:has-text("First class")'), 'first-timers flagged');
  await Promise.all([tp.waitForNavigation(), tp.click('.oys-teach-roster tr:has-text("Rae") button:has-text("Paid: cash")')]);
  check(await tp.isVisible('text=Marked as paid.'), 'teacher marks a studio payment');
  await Promise.all([tp.waitForNavigation(), tp.click('.oys-teach-roster tr:has-text("Lena") button:has-text("Here")')]);
  const tmark = mails().length;
  await tp.fill('.oys-message textarea[name="body"]', 'Hi {first_name}, bring a blanket for the long holds.');
  await Promise.all([tp.waitForNavigation(), tp.click('button:has-text("Send the message")')]);
  check(await tp.isVisible('text=Message sent to 2 people'), 'teacher messages everyone booked');
  check(mails().slice(tmark).some(f => fs.readFileSync(path.join(WP_DIR, 'wp-content/mail-log', f), 'utf8').includes('bring a blanket')), 'message emailed');
  await tp.screenshot({ path: `${SHOTS}/30-teacher-roster.png`, fullPage: true });
  await tp.goto(`${BASE}/wp-admin/admin.php?page=oys-teach&session=${fresh()}`);
  check(await tp.isVisible("text=This class isn't one of yours."), 'other classes stay closed');
  const denied = await tp.goto(`${BASE}/wp-admin/admin.php?page=oys-schedule`);
  check(denied.status() === 403 || await tp.isVisible('text=Sorry, you are not allowed'), 'studio pages stay closed');

  // Month statement: a finished class where Maya collected $20 at the door → she owes the studio $10.
  php(`$t = time() - 2 * DAY_IN_SECONDS; $sid = OYS_Schedule::save(array('kind'=>'group','class_slug'=>'hatha-flow','starts_at'=>gmdate('Y-m-d H:i:s',$t),'ends_at'=>gmdate('Y-m-d H:i:s',$t+3600),'capacity'=>12,'price_cents'=>2000,'status'=>'scheduled','teacher_id'=>${tId})); global $wpdb; $wpdb->insert($wpdb->prefix.'oys_bookings', array('session_id'=>$sid,'user_id'=>${raeId},'status'=>'attended','paid_with'=>'door','due_cents'=>2000,'collected_with'=>'cash','created_at'=>oys_now()));`);
  const stMonth = php(`echo wp_date('Y-m', time() - 2 * DAY_IN_SECONDS);`);
  await tp.goto(`${BASE}/wp-admin/admin.php?page=oys-teach-statement&month=${stMonth}`);
  check(await tp.isVisible('.oys-kpi:has-text("You pay the studio $10")'), 'teacher statement: she owes the studio $10');
  const [csvDl] = await Promise.all([tp.waitForEvent('download'), tp.click('a:has-text("Download CSV")')]);
  check(fs.readFileSync(await csvDl.path(), 'utf8').includes('-10.00'), 'statement CSV');
  await adm.goto(`${BASE}/wp-admin/admin.php?page=oys-teachers&month=${stMonth}`);
  check(await adm.isVisible('.oys-statement-summary tr:has-text("Maya Green") >> text=They pay you $10'), 'studio summary: Maya pays $10');
  await adm.screenshot({ path: `${SHOTS}/31-teachers-list.png`, fullPage: true });

  // Teachers page on the website.
  if (await adm.isVisible('button:has-text("Create the Teachers page")')) await Promise.all([adm.waitForNavigation(), adm.click('button:has-text("Create the Teachers page")')]);
  const tpage = php(`global $wpdb; echo get_permalink($wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_content LIKE '%[oys_teachers%' LIMIT 1"));`);
  await cust.goto(tpage);
  check(await cust.isVisible('.oys-teacher h2:has-text("Maya Green")') && await cust.isVisible('.oys-teacher__photos img'), 'Teachers page with name and photo');
  check(await cust.isVisible(`.oys-teacher__next a[href*="session=${tc}"]`), 'her next classes are linked');
  await cust.screenshot({ path: `${SHOTS}/32-teachers-page.png`, fullPage: true });

  // Cancelled in time: the card is refunded on Maya's account, with the studio fee returned.
  const lenaBooking = JSON.parse(q(`SELECT b.id FROM {$wpdb->prefix}oys_bookings b JOIN {$wpdb->users} u ON u.ID=b.user_id WHERE b.session_id=${tc} AND u.user_email='lena${stamp}@example.com'`))[0].id;
  php(`global $wpdb; $wpdb->update($wpdb->prefix.'oys_bookings', array('status'=>'confirmed'), array('id'=>${lenaBooking}));`);
  check(php(`echo OYS_Bookings::cancel(${lenaBooking});`) === 'refunded', 'cancelled in time: refunded');
  const refund = JSON.parse(execSync('curl -s http://127.0.0.1:8090/_state').toString()).refunds.pop();
  check(refund && refund.account === acct && refund.refund_application_fee === 'true' && refund.amount === 2500, 'refund on the teacher\'s account, studio fee returned');

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
