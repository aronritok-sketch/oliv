import type { ReactNode } from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
  type PressableProps,
  type StyleProp,
  type TextProps,
  type TextStyle,
  type ViewStyle,
} from 'react-native';

import { Colors, Fonts, Radius, Spacing } from '@/constants/theme';

type Variant = 'display' | 'title' | 'heading' | 'body' | 'small' | 'label' | 'strong';

export function T({ variant = 'body', style, ...props }: TextProps & { variant?: Variant }) {
  return <Text {...props} style={[styles.base, text[variant], style]} />;
}

export function Button({
  title,
  onPress,
  kind = 'primary',
  loading,
  disabled,
  style,
  testID,
  icon,
}: {
  title: string;
  onPress?: PressableProps['onPress'];
  kind?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'live';
  loading?: boolean;
  disabled?: boolean;
  style?: StyleProp<ViewStyle>;
  testID?: string;
  icon?: ReactNode;
}) {
  const k = buttons[kind];
  const off = disabled || loading;
  return (
    <Pressable
      testID={testID}
      accessibilityRole="button"
      accessibilityState={{ disabled: !!off, busy: !!loading }}
      onPress={onPress}
      disabled={off}
      style={({ pressed }) => [styles.button, k.box, off && styles.off, pressed && styles.pressed, style]}>
      {loading ? (
        <ActivityIndicator color={k.text.color} />
      ) : (
        <View style={styles.buttonRow}>
          {icon}
          <Text style={[styles.buttonText, k.text]}>{title}</Text>
        </View>
      )}
    </Pressable>
  );
}

export function Card({
  children,
  style,
  tone = 'plain',
  testID,
}: {
  children: ReactNode;
  style?: StyleProp<ViewStyle>;
  tone?: 'plain' | 'forest' | 'mist' | 'lilac';
  testID?: string;
}) {
  return (
    <View testID={testID} style={[styles.card, cardTones[tone], style]}>
      {children}
    </View>
  );
}

export function Pill({ text: label, tone = 'neutral', testID }: { text: string; tone?: 'neutral' | 'ok' | 'low' | 'full' | 'closed' | 'mine' | 'online'; testID?: string }) {
  const t = pills[tone];
  return (
    <View testID={testID} style={[styles.pill, { backgroundColor: t[0] }]}>
      <Text style={[styles.pillText, { color: t[1] }]}>{label}</Text>
    </View>
  );
}

export function Notice({ text: body, tone = 'info', testID }: { text: string; tone?: 'info' | 'error' | 'success'; testID?: string }) {
  const bg = tone === 'error' ? Colors.dangerSoft : tone === 'success' ? Colors.successSoft : Colors.mist;
  const fg = tone === 'error' ? Colors.danger : Colors.moss;
  return (
    <View testID={testID} accessibilityRole={tone === 'error' ? 'alert' : undefined} style={[styles.notice, { backgroundColor: bg }]}>
      <Text style={[styles.base, text.small, { color: fg }]}>{body}</Text>
    </View>
  );
}

export function Empty({ title, body, action }: { title: string; body?: string; action?: ReactNode }) {
  return (
    <View style={styles.empty}>
      <T variant="heading" style={{ textAlign: 'center' }}>{title}</T>
      {body ? <T variant="small" style={{ textAlign: 'center', color: Colors.muted }}>{body}</T> : null}
      {action}
    </View>
  );
}

export function Row({ children, style }: { children: ReactNode; style?: StyleProp<ViewStyle> }) {
  return <View style={[styles.row, style]}>{children}</View>;
}

export function Loading() {
  return (
    <View style={styles.loading}>
      <ActivityIndicator color={Colors.forest} size="large" />
    </View>
  );
}

const styles = StyleSheet.create({
  base: { color: Colors.ink, fontFamily: Fonts.regular },
  button: {
    minHeight: 52,
    borderRadius: Radius.pill,
    paddingHorizontal: Spacing.xl,
    alignItems: 'center',
    justifyContent: 'center',
  },
  buttonRow: { flexDirection: 'row', alignItems: 'center', gap: Spacing.sm },
  buttonText: { fontFamily: Fonts.semibold, fontSize: 16, letterSpacing: 0.2 },
  off: { opacity: 0.45 },
  pressed: { opacity: 0.8, transform: [{ scale: 0.99 }] },
  card: {
    borderRadius: Radius.lg,
    padding: Spacing.lg,
    gap: Spacing.sm,
  },
  pill: { alignSelf: 'flex-start', borderRadius: Radius.pill, paddingHorizontal: 10, paddingVertical: 4 },
  pillText: { fontFamily: Fonts.semibold, fontSize: 12, letterSpacing: 0.2 },
  notice: { borderRadius: Radius.md, padding: Spacing.md },
  empty: { alignItems: 'center', gap: Spacing.sm, paddingVertical: Spacing.xxl, paddingHorizontal: Spacing.lg },
  row: { flexDirection: 'row', alignItems: 'center', gap: Spacing.sm },
  loading: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: Spacing.xxl },
});

const text: Record<Variant, TextStyle> = {
  display: { fontFamily: Fonts.display, fontSize: 40, lineHeight: 44, textTransform: 'uppercase', color: Colors.moss },
  title: { fontFamily: Fonts.display, fontSize: 26, lineHeight: 30, textTransform: 'uppercase', color: Colors.moss },
  heading: { fontFamily: Fonts.bold, fontSize: 18, lineHeight: 24 },
  body: { fontSize: 16, lineHeight: 23 },
  small: { fontSize: 14, lineHeight: 20 },
  label: { fontFamily: Fonts.semibold, fontSize: 12, letterSpacing: 1.2, textTransform: 'uppercase', color: Colors.muted },
  strong: { fontFamily: Fonts.semibold, fontSize: 16, lineHeight: 22 },
};

const buttons: Record<string, { box: ViewStyle; text: TextStyle }> = {
  primary: { box: { backgroundColor: Colors.forest }, text: { color: Colors.paper } },
  secondary: { box: { backgroundColor: Colors.card, borderWidth: 1.5, borderColor: Colors.forest }, text: { color: Colors.forest } },
  ghost: { box: { backgroundColor: 'transparent', minHeight: 44 }, text: { color: Colors.forest } },
  danger: { box: { backgroundColor: Colors.card, borderWidth: 1.5, borderColor: Colors.danger }, text: { color: Colors.danger } },
  live: { box: { backgroundColor: Colors.orchid }, text: { color: '#fff' } },
};

const cardTones: Record<string, ViewStyle> = {
  plain: { backgroundColor: Colors.card, borderWidth: 1, borderColor: Colors.line },
  forest: { backgroundColor: Colors.forest },
  mist: { backgroundColor: Colors.mist },
  lilac: { backgroundColor: Colors.lilac },
};

const pills: Record<string, [string, string]> = {
  neutral: [Colors.mist, Colors.moss],
  ok: [Colors.mist, Colors.moss],
  low: ['#FFE6D4', '#9A4A10'],
  full: ['#EFEDE6', Colors.muted],
  closed: ['#EFEDE6', Colors.muted],
  mine: [Colors.forest, Colors.paper],
  online: ['#F1E6FC', '#6B3FA0'],
};
