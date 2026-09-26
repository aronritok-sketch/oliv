import { useMemo, useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { Screen } from '@/components/screen';
import { SessionCard } from '@/components/session-card';
import { Button, Empty, Loading, Notice, T } from '@/components/ui';
import { Colors, Fonts, Radius, Spacing } from '@/constants/theme';
import { useAuth } from '@/lib/auth';
import { dayTitle, groupByDay } from '@/lib/format';
import { useLoad } from '@/lib/use-load';

type Filter = 'all' | 'studio' | 'online';

export default function Schedule() {
  const { api, me } = useAuth();
  const { data, error, loading, refreshing, refresh, reload } = useLoad(() => api.schedule(14));
  const [filter, setFilter] = useState<Filter>('all');

  const days = useMemo(() => {
    const all = data?.sessions ?? [];
    const shown = all.filter((s) =>
      filter === 'all' ? true : filter === 'online' ? s.format !== 'studio' : s.format !== 'online',
    );
    return groupByDay(shown);
  }, [data, filter]);

  const hasOnline = (data?.sessions ?? []).some((s) => s.format !== 'studio');
  const first = me?.user.first_name;

  return (
    <Screen eyebrow={first ? `Hi ${first}` : 'Olivia Kovács Yoga'} title="Schedule" refreshing={refreshing} onRefresh={refresh} testID="schedule">
      {hasOnline ? (
        <View style={styles.segment} accessibilityRole="tablist">
          {(['all', 'studio', 'online'] as Filter[]).map((f) => (
            <Pressable
              key={f}
              testID={`filter-${f}`}
              accessibilityRole="tab"
              accessibilityState={{ selected: filter === f }}
              onPress={() => setFilter(f)}
              style={[styles.seg, filter === f && styles.segOn]}>
              <T style={[styles.segText, filter === f && styles.segTextOn]}>
                {f === 'all' ? 'All' : f === 'studio' ? 'In studio' : 'Live online'}
              </T>
            </Pressable>
          ))}
        </View>
      ) : null}

      {loading ? <Loading /> : null}
      {error ? (
        <View style={{ gap: Spacing.md }}>
          <Notice tone="error" text={error} />
          <Button kind="secondary" title="Try again" onPress={() => reload()} />
        </View>
      ) : null}
      {!loading && !error && days.length === 0 ? (
        <Empty title="No classes yet" body="New classes appear here as soon as Olivia opens them for booking." />
      ) : null}

      {days.map((day) => (
        <View key={day.date} style={{ gap: Spacing.sm }}>
          <T variant="label" style={styles.day}>
            {dayTitle(day)}
          </T>
          {day.sessions.map((s) => (
            <SessionCard key={s.id} session={s} mode={filter === 'online' && s.format === 'hybrid' ? 'online' : undefined} />
          ))}
        </View>
      ))}
    </Screen>
  );
}

const styles = StyleSheet.create({
  segment: {
    flexDirection: 'row',
    backgroundColor: Colors.mist,
    borderRadius: Radius.pill,
    padding: 4,
  },
  seg: { flex: 1, paddingVertical: 10, borderRadius: Radius.pill, alignItems: 'center' },
  segOn: { backgroundColor: Colors.card, boxShadow: '0 1px 4px rgba(18,35,26,0.12)' },
  segText: { fontFamily: Fonts.medium, fontSize: 14, color: Colors.muted },
  segTextOn: { fontFamily: Fonts.semibold, color: Colors.forest },
  day: { marginTop: Spacing.xs, color: Colors.forest },
});
