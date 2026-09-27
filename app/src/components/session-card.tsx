import { Link } from 'expo-router';
import { Pressable, StyleSheet, View } from 'react-native';

import { Pill, T } from './ui';

import { Colors, Fonts, Radius, Spacing } from '@/constants/theme';
import type { Session } from '@/lib/api';
import { availability, formatLabel } from '@/lib/format';

export function SessionCard({ session: s, mode }: { session: Session; mode?: 'studio' | 'online' }) {
  const a = availability(s);
  const where = s.format === 'online' ? 'Live on Zoom' : s.location || 'Studio';
  return (
    <Link href={{ pathname: '/class/[id]', params: { id: String(s.id), ...(mode ? { mode } : {}) } }} asChild>
      <Pressable
        testID={`session-${s.id}`}
        accessibilityLabel={`${s.title}, ${s.local.start}, ${a.text}`}
        style={({ pressed }) => [styles.card, s.my_booking && styles.mine, pressed && { opacity: 0.85 }]}>
        <View style={styles.time}>
          <T style={styles.start} numberOfLines={1}>
            {s.local.start}
          </T>
          <T variant="small" style={{ color: Colors.muted }}>
            {s.duration} min
          </T>
        </View>
        <View style={styles.body}>
          <T variant="heading" numberOfLines={2}>
            {s.title}
          </T>
          <T variant="small" style={{ color: Colors.muted }} numberOfLines={1}>
            {where}
            {s.teacher ? ` · with ${s.teacher.name}` : ''}
          </T>
          <View style={styles.pills}>
            <Pill text={a.text} tone={a.tone} />
            {s.format !== 'studio' ? <Pill text={formatLabel(s.format)} tone="online" /> : null}
            {s.pricing === 'donation' ? <Pill text="By donation" tone="neutral" /> : null}
          </View>
        </View>
      </Pressable>
    </Link>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: 'row',
    gap: Spacing.lg,
    backgroundColor: Colors.card,
    borderRadius: Radius.lg,
    borderWidth: 1,
    borderColor: Colors.line,
    padding: Spacing.lg,
  },
  mine: { borderColor: Colors.forest, borderWidth: 1.5 },
  time: { width: 80, gap: 2 },
  start: { fontFamily: Fonts.bold, fontSize: 16, color: Colors.forest },
  body: { flex: 1, gap: 4 },
  pills: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 4 },
});
