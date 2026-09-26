import { Link } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { JoinButton } from '@/components/join-button';
import { Screen } from '@/components/screen';
import { Button, Empty, Loading, Notice, Pill, Row, T } from '@/components/ui';
import { Colors, Fonts, Radius, Spacing } from '@/constants/theme';
import type { BookingItem } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { dayTitle } from '@/lib/format';
import { useLoad } from '@/lib/use-load';

const STATUS: Record<string, string> = {
  attended: 'Attended',
  no_show: 'Missed',
  late_cancelled: 'Late cancel',
  confirmed: 'Booked',
};

export default function Bookings() {
  const { api } = useAuth();
  const [when, setWhen] = useState<'upcoming' | 'past'>('upcoming');
  const { data, error, loading, refreshing, refresh, reload } = useLoad(() => api.bookings(when), [when]);
  const items = data?.bookings ?? [];

  return (
    <Screen eyebrow="Your practice" title="My classes" refreshing={refreshing} onRefresh={refresh} testID="bookings">
      <View style={styles.segment}>
        {(['upcoming', 'past'] as const).map((w) => (
          <Pressable
            key={w}
            testID={`bookings-${w}`}
            accessibilityRole="tab"
            accessibilityState={{ selected: when === w }}
            onPress={() => setWhen(w)}
            style={[styles.seg, when === w && styles.segOn]}>
            <T style={[styles.segText, when === w && styles.segTextOn]}>{w === 'upcoming' ? 'Upcoming' : 'Past'}</T>
          </Pressable>
        ))}
      </View>

      {loading && !data ? <Loading /> : null}
      {error ? (
        <View style={{ gap: Spacing.md }}>
          <Notice tone="error" text={error} />
          <Button kind="secondary" title="Try again" onPress={() => reload()} />
        </View>
      ) : null}
      {data && items.length === 0 ? (
        when === 'upcoming' ? (
          <Empty
            title="Nothing booked yet"
            body="Pick a class on the schedule and it shows up here."
            action={
              <Link href="/" asChild>
                <Button title="See the schedule" style={{ marginTop: Spacing.sm }} />
              </Link>
            }
          />
        ) : (
          <Empty title="No past classes yet" />
        )
      ) : null}

      {items.map((b) => (
        <BookingCard key={b.booking.id} item={b} past={when === 'past'} />
      ))}
    </Screen>
  );
}

function BookingCard({ item: b, past }: { item: BookingItem; past: boolean }) {
  const online = b.booking.mode === 'online';
  return (
    <View style={[styles.card, !past && online && styles.cardOnline]} testID={`booking-${b.booking.id}`}>
      <Link href={{ pathname: '/class/[id]', params: { id: String(b.id) } }} asChild>
        <Pressable accessibilityRole="button" style={{ gap: 4 }}>
          <T variant="label" style={{ color: Colors.forest }}>
            {dayTitle({ date: b.local.date, label: b.local.label })} · {b.local.start}
          </T>
          <T variant="heading">{b.title}</T>
          <T variant="small" style={{ color: Colors.muted }}>
            {online ? 'Live online' : b.location || 'Studio'} · {b.booking.paid_with}
            {b.booking.guests.length ? ` · +${b.booking.guests.length} guest${b.booking.guests.length > 1 ? 's' : ''}` : ''}
          </T>
          <Row style={{ marginTop: 4 }}>
            {past ? <Pill text={STATUS[b.booking.status] ?? b.booking.status} tone={b.booking.status === 'attended' ? 'ok' : 'closed'} /> : null}
            {online ? <Pill text="Online" tone="online" /> : null}
          </Row>
        </Pressable>
      </Link>
      {!past && online ? <JoinButton url={b.booking.join_url} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  segment: { flexDirection: 'row', backgroundColor: Colors.mist, borderRadius: Radius.pill, padding: 4 },
  seg: { flex: 1, paddingVertical: 10, borderRadius: Radius.pill, alignItems: 'center' },
  segOn: { backgroundColor: Colors.card, boxShadow: '0 1px 4px rgba(18,35,26,0.12)' },
  segText: { fontFamily: Fonts.medium, fontSize: 14, color: Colors.muted },
  segTextOn: { fontFamily: Fonts.semibold, color: Colors.forest },
  card: {
    backgroundColor: Colors.card,
    borderRadius: Radius.lg,
    borderWidth: 1,
    borderColor: Colors.line,
    padding: Spacing.lg,
    gap: Spacing.md,
  },
  cardOnline: { borderColor: Colors.lilac, borderWidth: 1.5 },
});
