import { Image } from 'expo-image';
import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { T } from './ui';

import { Colors, Fonts, Radius, Spacing } from '@/constants/theme';
import type { Teacher } from '@/lib/api';

/** Who teaches the class: photo, name, headline, and the bio behind "About …". */
export function TeacherCard({ teacher: t }: { teacher: Teacher }) {
  const [open, setOpen] = useState(false);
  const first = t.name.split(' ')[0];
  return (
    <View style={styles.card} testID="teacher">
      <View style={styles.row}>
        {t.photo ? <Image source={{ uri: t.photo }} style={styles.photo} contentFit="cover" accessibilityIgnoresInvertColors /> : null}
        <View style={{ flex: 1, gap: 2 }}>
          <T variant="strong" testID="teacher-name">
            with {t.name}
          </T>
          {t.headline ? <T variant="small" style={{ color: Colors.muted }}>{t.headline}</T> : null}
        </View>
      </View>
      {t.bio ? (
        <>
          <Pressable onPress={() => setOpen((o) => !o)} accessibilityRole="button" accessibilityState={{ expanded: open }} testID="teacher-more">
            <T style={styles.more}>{open ? 'Less' : `About ${first}`}</T>
          </Pressable>
          {open ? <T testID="teacher-bio">{t.bio}</T> : null}
        </>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  card: { gap: Spacing.sm, padding: Spacing.lg, borderRadius: Radius.lg, borderWidth: 1, borderColor: Colors.line, backgroundColor: Colors.card },
  row: { flexDirection: 'row', gap: Spacing.md, alignItems: 'center' },
  photo: { width: 56, height: 56, borderRadius: 28, backgroundColor: Colors.line },
  more: { fontFamily: Fonts.bold, color: Colors.forest, textDecorationLine: 'underline' },
});
