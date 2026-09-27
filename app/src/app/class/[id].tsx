import { Stack, useLocalSearchParams } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useState } from 'react';
import { Pressable, StyleSheet, TextInput, View } from 'react-native';

import { JoinButton } from '@/components/join-button';
import { Screen } from '@/components/screen';
import { Button, Card, Loading, Notice, Pill, Row, T } from '@/components/ui';
import { Colors, Fonts, Radius, Spacing } from '@/constants/theme';
import { ApiError, type Guest, type Mode, type PayOption, type SessionDetail } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatLabel, money, plainText, plural } from '@/lib/format';
import { useLoad } from '@/lib/use-load';

export default function ClassDetail() {
  const params = useLocalSearchParams<{ id: string; mode?: string }>();
  const id = Number(params.id);
  const [mode, setMode] = useState<Mode>(params.mode === 'online' ? 'online' : 'studio');
  const { api, me, refreshMe } = useAuth();
  const { data, setData, error, loading, reload } = useLoad(() => api.session(id, mode), [id, mode]);
  const [message, setMessage] = useState<{ tone: 'success' | 'error' | 'info'; text: string } | null>(null);

  const currency = me?.studio.currency ?? 'usd';

  if (loading && !data) {
    return (
      <>
        <Stack.Screen options={{ title: '' }} />
        <Loading />
      </>
    );
  }
  if (!data) {
    return (
      <Screen tabs={false}>
        <Notice tone="error" text={error ?? 'This class could not be found.'} />
        <Button kind="secondary" title="Try again" onPress={() => reload()} />
      </Screen>
    );
  }

  const s = data.session;
  const mine = s.my_booking;
  const hybrid = s.format === 'hybrid';

  return (
    <Screen tabs={false} testID="class-detail">
      <Stack.Screen options={{ title: s.local.day + ' ' + s.local.start }} />

      <View style={{ gap: Spacing.sm }}>
        <Row style={{ flexWrap: 'wrap' }}>
          <Pill text={formatLabel(s.format)} tone={s.format === 'studio' ? 'neutral' : 'online'} />
          {s.kind === 'workshop' ? <Pill text="Workshop" tone="low" /> : null}
          {s.kind === 'private' ? <Pill text="Private session" tone="low" /> : null}
        </Row>
        <T variant="display" accessibilityRole="header" testID="class-title">
          {s.title}
        </T>
        <T variant="strong">{s.local.label}</T>
        <T style={{ color: Colors.muted }}>
          {s.local.start} – {s.local.end} · {s.duration} min
          {s.format !== 'online' && s.location ? ` · ${s.location}` : ''}
        </T>
      </View>

      {s.description ? <T>{plainText(s.description)}</T> : null}
      {s.note ? <Notice text={plainText(s.note)} /> : null}
      {s.minimum ? <Notice text={s.minimum} testID="minimum" /> : null}
      {message ? <Notice tone={message.tone} text={message.text} testID="class-message" /> : null}

      {hybrid && !mine ? <ModeSwitch data={data} mode={mode} onChange={(m) => { setMessage(null); setMode(m); }} currency={currency} /> : null}

      {mine ? (
        <Booked
          data={data}
          currency={currency}
          onChanged={(text, tone) => {
            setMessage({ text, tone });
            void reload();
            void refreshMe();
          }}
        />
      ) : s.closed_reason ? (
        <Notice text={s.closed_reason} />
      ) : data.spots_left <= 0 ? (
        <Waitlist
          data={data}
          onChange={(next) => setData({ ...data, session: next })}
          onError={(text) => setMessage({ tone: 'error', text })}
        />
      ) : (
        <BookForm
          key={mode}
          data={data}
          currency={currency}
          onBooked={(text) => {
            setMessage({ tone: 'success', text });
            void reload();
            void refreshMe();
          }}
          onError={(text) => setMessage({ tone: 'error', text })}
          onRefresh={() => reload()}
        />
      )}

      {data.policy ? (
        <View style={{ gap: 4 }}>
          <T variant="label">Cancellation</T>
          <T variant="small" style={{ color: Colors.muted }}>
            {plainText(data.policy)}
          </T>
        </View>
      ) : null}
    </Screen>
  );
}

/* ---------- Studio / live online switch (hybrid classes) ---------- */

function ModeSwitch({ data, mode, onChange, currency }: { data: SessionDetail; mode: Mode; onChange: (m: Mode) => void; currency: string }) {
  const s = data.session;
  const items: { m: Mode; title: string; price: number; spots: string }[] = [
    {
      m: 'studio',
      title: 'In the studio',
      price: s.price_cents,
      spots: s.spots_left > 0 ? plural(s.spots_left, 'spot', 'spots') + ' left' : 'Full',
    },
    {
      m: 'online',
      title: 'Live online',
      price: s.online_price_cents ?? 0,
      spots: s.online_spots_left === null ? 'Zoom' : s.online_spots_left > 0 ? plural(s.online_spots_left, 'spot', 'spots') + ' left' : 'Full',
    },
  ];
  return (
    <View style={{ gap: Spacing.sm }}>
      <T variant="label">How will you join?</T>
      <Row>
        {items.map((it) => {
          const on = it.m === mode;
          return (
            <Pressable
              key={it.m}
              testID={`mode-${it.m}`}
              accessibilityRole="radio"
              accessibilityState={{ checked: on }}
              onPress={() => onChange(it.m)}
              style={[styles.mode, on && styles.modeOn]}>
              <T style={[styles.modeTitle, on && { color: Colors.paper }]}>{it.title}</T>
              <T variant="small" style={{ color: on ? Colors.mist : Colors.muted }}>
                {it.price ? money(it.price, currency) : 'Free'} · {it.spots}
              </T>
            </Pressable>
          );
        })}
      </Row>
    </View>
  );
}

/* ---------- Booking form ---------- */

function BookForm({
  data,
  currency,
  onBooked,
  onError,
  onRefresh,
}: {
  data: SessionDetail;
  currency: string;
  onBooked: (text: string) => void;
  onError: (text: string) => void;
  onRefresh: () => void;
}) {
  const { api } = useAuth();
  const s = data.session;
  const [method, setMethod] = useState<PayOption['method'] | null>(data.options[0]?.method ?? null);
  const [guests, setGuests] = useState<Guest[]>([]);
  const [waiverOk, setWaiverOk] = useState(false);
  const [showWaiver, setShowWaiver] = useState(false);
  const [busy, setBusy] = useState(false);
  const [paying, setPaying] = useState(false);
  const donation = data.donation ?? null;
  const [amount, setAmount] = useState<number>(donation?.suggested_cents ?? 0);
  const [otherText, setOtherText] = useState('');

  const option = data.options.find((o) => o.method === method) ?? null;
  const people = 1 + guests.length;
  // Donation classes: card and "at the studio" follow the amount chosen per person.
  const each = (o: PayOption) => (donation && (o.method === 'card' || o.method === 'door') ? amount : o.price_cents);
  const total = option && (option.method === 'card' || option.method === 'door') ? each(option) * people : 0;
  const belowMin = !!donation && (option?.method === 'card' || option?.method === 'door') && amount < donation.min_cents;
  const creditsShort = option?.method === 'credit' && (option.available ?? 0) < people;
  const guestsIncomplete = guests.some((g) => !g.name.trim() || (data.guest_email_required && !g.email?.trim()));
  const tooMany = people > data.spots_left;

  async function book() {
    if (!option) return;
    setBusy(true);
    try {
      const res = await api.book(s.id, {
        method: option.method,
        mode: data.mode,
        guests: guests.map((g) => ({ name: g.name.trim(), email: g.email?.trim() })),
        accept_waiver: data.waiver !== null ? waiverOk : undefined,
        amount_cents: donation ? amount : undefined,
      });
      if (res.status === 'booked') {
        onBooked(
          data.mode === 'online'
            ? 'You’re booked for the live class! Join from this page or My classes.'
            : option.method === 'door'
              ? `You’re booked! Pay ${money(total, currency)} at the studio.`
              : 'You’re booked! See you on the mat.',
        );
      } else {
        setPaying(true);
        await WebBrowser.openBrowserAsync(res.url, { dismissButtonStyle: 'close', presentationStyle: WebBrowser.WebBrowserPresentationStyle.PAGE_SHEET });
        // Back from the browser: the booking is confirmed once Stripe tells the site.
        setPaying(false);
        onRefresh();
      }
    } catch (e) {
      onError(e instanceof ApiError ? e.message : 'Something went wrong. Please try again.');
    } finally {
      setBusy(false);
    }
  }

  if (data.options.length === 0) {
    return <Notice text="Booking in the app isn't available for this class. Please book on the website." />;
  }

  return (
    <View style={{ gap: Spacing.lg }}>
      {donation ? (
        <View style={{ gap: Spacing.sm }}>
          <T variant="label">Pay what you like</T>
          <Row style={{ flexWrap: 'wrap' }}>
            {donation.amounts.map((a) => {
              const on = a === amount && !otherText;
              return (
                <Pressable
                  key={a}
                  testID={`amount-${a}`}
                  accessibilityRole="radio"
                  accessibilityState={{ checked: on }}
                  onPress={() => {
                    setOtherText('');
                    setAmount(a);
                  }}
                  style={[styles.chip, on && styles.chipOn]}>
                  <T style={[styles.chipText, on && { color: Colors.paper }]}>{money(a, currency)}</T>
                </Pressable>
              );
            })}
            <TextInput
              testID="amount-other"
              style={[styles.input, styles.other]}
              placeholder="Other"
              placeholderTextColor="#9AA39C"
              keyboardType="decimal-pad"
              value={otherText}
              onChangeText={(t) => {
                setOtherText(t);
                const c = Math.round(parseFloat(t.replace(',', '.') || '0') * 100);
                setAmount(c > 0 ? c : donation.suggested_cents);
              }}
            />
          </Row>
          <T variant="small" style={{ color: Colors.muted }}>
            By donation: give what feels right, from {money(donation.min_cents, currency)} per person.
          </T>
        </View>
      ) : null}

      <View style={{ gap: Spacing.sm }}>
        <T variant="label">Pay with</T>
        {data.options.map((o) => {
          const on = o.method === method;
          return (
            <Pressable
              key={o.method}
              testID={`pay-${o.method}`}
              accessibilityRole="radio"
              accessibilityState={{ checked: on }}
              onPress={() => setMethod(o.method)}
              style={[styles.option, on && styles.optionOn]}>
              <View style={[styles.radio, on && styles.radioOn]}>{on ? <View style={styles.radioDot} /> : null}</View>
              <View style={{ flex: 1, gap: 2 }}>
                <T variant="strong">{o.label}</T>
                <T variant="small" style={{ color: Colors.muted }}>
                  {o.detail}
                </T>
              </View>
              {o.price_cents ? <T style={styles.price}>{money(each(o), currency)}</T> : null}
            </Pressable>
          );
        })}
      </View>

      {data.max_guests > 0 ? (
        <View style={{ gap: Spacing.sm }}>
          <T variant="label">Bringing a friend?</T>
          {guests.map((g, i) => (
            <Card key={i} style={{ gap: Spacing.sm }}>
              <Row>
                <T variant="strong" style={{ flex: 1 }}>
                  Guest {i + 1}
                </T>
                <Pressable accessibilityRole="button" onPress={() => setGuests(guests.filter((_, j) => j !== i))} hitSlop={8}>
                  <T variant="small" style={{ color: Colors.danger }}>
                    Remove
                  </T>
                </Pressable>
              </Row>
              <TextInput
                testID={`guest-name-${i}`}
                style={styles.input}
                placeholder="Name"
                placeholderTextColor="#9AA39C"
                value={g.name}
                onChangeText={(name) => setGuests(guests.map((x, j) => (j === i ? { ...x, name } : x)))}
              />
              <TextInput
                testID={`guest-email-${i}`}
                style={styles.input}
                placeholder={data.guest_email_required ? 'Email (for their own Zoom link)' : 'Email (optional)'}
                placeholderTextColor="#9AA39C"
                autoCapitalize="none"
                keyboardType="email-address"
                value={g.email ?? ''}
                onChangeText={(email) => setGuests(guests.map((x, j) => (j === i ? { ...x, email } : x)))}
              />
            </Card>
          ))}
          {guests.length < data.max_guests ? (
            <Button testID="guest-add" kind="secondary" title="+ Add a guest" onPress={() => setGuests([...guests, { name: '', email: '' }])} />
          ) : null}
          {guests.length > 0 && option?.method === 'membership' ? (
            <T variant="small" style={{ color: Colors.muted }}>
              Guests use class credits from your pass, one each.
            </T>
          ) : null}
        </View>
      ) : null}

      {data.waiver !== null ? (
        <Card tone="mist">
          <Pressable
            testID="waiver-check"
            accessibilityRole="checkbox"
            accessibilityState={{ checked: waiverOk }}
            onPress={() => setWaiverOk(!waiverOk)}
            style={styles.check}>
            <View style={[styles.box, waiverOk && styles.boxOn]}>{waiverOk ? <T style={styles.tick}>✓</T> : null}</View>
            <T variant="small" style={{ flex: 1 }}>
              I have read and accept the participation agreement.
            </T>
          </Pressable>
          <Pressable accessibilityRole="button" onPress={() => setShowWaiver(!showWaiver)}>
            <T variant="small" style={{ color: Colors.forest, fontFamily: Fonts.semibold }}>
              {showWaiver ? 'Hide the agreement' : 'Read the agreement'}
            </T>
          </Pressable>
          {showWaiver ? <T variant="small">{plainText(data.waiver)}</T> : null}
        </Card>
      ) : null}

      {belowMin && donation ? <Notice tone="error" text={`The minimum is ${money(donation.min_cents, currency)} per person.`} /> : null}
      {creditsShort ? <Notice tone="error" text={`Your pass has ${plural(option?.available ?? 0, 'class', 'classes')} left for ${plural(people, 'person', 'people')}.`} /> : null}
      {tooMany ? <Notice tone="error" text={`Only ${plural(data.spots_left, 'spot is', 'spots are')} left.`} /> : null}
      {paying ? <Notice text="Finish the payment in the browser. Your spot is held for a few minutes." /> : null}

      <Button
        testID="book-submit"
        title={
          option?.method === 'card'
            ? `Pay ${money(total, currency)} and book`
            : option?.method === 'door'
              ? `Book · pay ${money(total, currency)} there`
              : guests.length
                ? `Book for ${plural(people, 'person', 'people')}`
                : 'Book my spot'
        }
        onPress={book}
        loading={busy}
        disabled={!option || creditsShort || belowMin || guestsIncomplete || tooMany || (data.waiver !== null && !waiverOk)}
      />
    </View>
  );
}

/* ---------- Already booked ---------- */

function Booked({
  data,
  currency,
  onChanged,
}: {
  data: SessionDetail;
  currency: string;
  onChanged: (text: string, tone: 'success' | 'error' | 'info') => void;
}) {
  const { api } = useAuth();
  const b = data.session.my_booking!;
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);

  async function cancel() {
    setBusy(true);
    try {
      const res = await api.cancel(b.id);
      onChanged(res.message, 'success');
    } catch (e) {
      onChanged(e instanceof ApiError ? e.message : 'Could not cancel. Please try again.', 'error');
    } finally {
      setBusy(false);
      setConfirming(false);
    }
  }

  return (
    <Card tone="forest" style={{ gap: Spacing.md }}>
      <T variant="title" style={{ color: Colors.paper }} testID="booked-title">
        You’re booked
      </T>
      <T style={{ color: Colors.mist }}>
        {b.mode === 'online' ? 'Joining live online' : 'In the studio'} · {b.paid_with}
        {b.guests.length ? ` · with ${b.guests.join(', ')}` : ''}
      </T>

      {b.due_cents ? (
        <View style={styles.due} testID="due">
          <T variant="strong" style={{ color: Colors.moss }}>
            To pay at the studio: {money(b.due_cents, currency)}
          </T>
        </View>
      ) : null}

      {b.mode === 'online' ? <JoinButton url={b.join_url} /> : null}

      {b.can_cancel && !confirming ? (
        <Button testID="cancel-start" kind="secondary" title="Cancel my booking" onPress={() => setConfirming(true)} />
      ) : null}
      {confirming ? (
        <View style={styles.confirm}>
          <T variant="strong">Cancel this booking?</T>
          <T variant="small" style={{ color: Colors.muted }}>
            {b.in_window
              ? 'This is inside the cancellation window, so the class will count as used.'
              : 'You’ll get the class back on your pass or as a credit.'}
          </T>
          <Button testID="cancel-confirm" kind="danger" title="Yes, cancel" onPress={cancel} loading={busy} />
          <Button kind="ghost" title="Keep my spot" onPress={() => setConfirming(false)} />
        </View>
      ) : null}
    </Card>
  );
}

/* ---------- Full: waitlist ---------- */

function Waitlist({ data, onChange, onError }: { data: SessionDetail; onChange: (s: SessionDetail['session']) => void; onError: (t: string) => void }) {
  const { api } = useAuth();
  const [busy, setBusy] = useState(false);
  const pos = data.session.waitlist_position;

  async function toggle() {
    setBusy(true);
    try {
      const res = await api.waitlist(data.session.id, pos > 0);
      onChange(res.session);
    } catch (e) {
      onError(e instanceof ApiError ? e.message : 'Something went wrong. Please try again.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card tone="mist" style={{ gap: Spacing.md }}>
      <T variant="heading">{pos > 0 ? `You’re #${pos} on the waitlist` : 'This class is full'}</T>
      <T variant="small">
        {pos > 0
          ? 'If a spot opens up, we’ll email you straight away so you can grab it.'
          : 'Join the waitlist and we’ll email you as soon as a spot opens up.'}
      </T>
      <Button testID="waitlist" kind={pos > 0 ? 'secondary' : 'primary'} title={pos > 0 ? 'Leave the waitlist' : 'Join the waitlist'} onPress={toggle} loading={busy} />
    </Card>
  );
}

const styles = StyleSheet.create({
  mode: {
    flex: 1,
    borderRadius: Radius.md,
    borderWidth: 1.5,
    borderColor: Colors.line,
    backgroundColor: Colors.card,
    padding: Spacing.md,
    gap: 2,
  },
  modeOn: { backgroundColor: Colors.forest, borderColor: Colors.forest },
  modeTitle: { fontFamily: Fonts.semibold, fontSize: 16, color: Colors.ink },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.md,
    backgroundColor: Colors.card,
    borderRadius: Radius.md,
    borderWidth: 1.5,
    borderColor: Colors.line,
    padding: Spacing.lg,
  },
  optionOn: { borderColor: Colors.forest, backgroundColor: '#F7FAF5' },
  radio: { width: 22, height: 22, borderRadius: 11, borderWidth: 2, borderColor: Colors.sage, alignItems: 'center', justifyContent: 'center' },
  radioOn: { borderColor: Colors.forest },
  radioDot: { width: 10, height: 10, borderRadius: 5, backgroundColor: Colors.forest },
  price: { fontFamily: Fonts.bold, fontSize: 17, color: Colors.forest },
  input: {
    minHeight: 46,
    borderRadius: Radius.sm,
    borderWidth: 1,
    borderColor: Colors.line,
    backgroundColor: Colors.paper,
    paddingHorizontal: Spacing.md,
    fontFamily: Fonts.regular,
    fontSize: 16,
    color: Colors.ink,
  },
  check: { flexDirection: 'row', alignItems: 'center', gap: Spacing.md },
  box: { width: 24, height: 24, borderRadius: 6, borderWidth: 2, borderColor: Colors.forest, alignItems: 'center', justifyContent: 'center', backgroundColor: Colors.card },
  boxOn: { backgroundColor: Colors.forest },
  tick: { color: Colors.paper, fontFamily: Fonts.bold, fontSize: 14, lineHeight: 16 },
  confirm: { backgroundColor: Colors.paper, borderRadius: Radius.md, padding: Spacing.lg, gap: Spacing.sm },
  due: { backgroundColor: Colors.mist, borderRadius: Radius.md, padding: Spacing.md },
  chip: { minHeight: 44, paddingHorizontal: Spacing.lg, borderRadius: Radius.pill, borderWidth: 1.5, borderColor: Colors.forest, backgroundColor: Colors.card, alignItems: 'center', justifyContent: 'center' },
  chipOn: { backgroundColor: Colors.forest },
  chipText: { fontFamily: Fonts.semibold, fontSize: 16, color: Colors.forest },
  other: { width: 96, minHeight: 44, borderRadius: Radius.pill, textAlign: 'center' },
});
