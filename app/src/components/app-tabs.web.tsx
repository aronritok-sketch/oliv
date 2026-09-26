import { Tabs, TabList, TabSlot, TabTrigger, type TabTriggerSlotProps } from 'expo-router/ui';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { Colors, Fonts, MaxContentWidth, Radius, Spacing } from '@/constants/theme';

/** Web preview: the same four tabs as a floating bar. */
export default function AppTabs() {
  return (
    <Tabs>
      <TabSlot style={{ height: '100%' }} />
      <TabList style={styles.bar}>
        <TabTrigger name="index" href="/" asChild>
          <TabButton>Schedule</TabButton>
        </TabTrigger>
        <TabTrigger name="bookings" href="/bookings" asChild>
          <TabButton>My classes</TabButton>
        </TabTrigger>
        <TabTrigger name="passes" href="/passes" asChild>
          <TabButton>Passes</TabButton>
        </TabTrigger>
        <TabTrigger name="profile" href="/profile" asChild>
          <TabButton>Profile</TabButton>
        </TabTrigger>
      </TabList>
    </Tabs>
  );
}

function TabButton({ children, isFocused, ...props }: TabTriggerSlotProps) {
  return (
    <Pressable {...props} accessibilityRole="tab" accessibilityState={{ selected: !!isFocused }} style={styles.tab}>
      <View style={[styles.dot, isFocused && styles.dotOn]} />
      <Text style={[styles.label, isFocused && styles.labelOn]}>{children}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  bar: {
    position: 'absolute',
    bottom: Spacing.md,
    left: Spacing.md,
    right: Spacing.md,
    maxWidth: MaxContentWidth,
    marginHorizontal: 'auto',
    flexDirection: 'row',
    backgroundColor: 'rgba(255,255,255,0.94)',
    borderRadius: Radius.pill,
    borderWidth: 1,
    borderColor: Colors.line,
    paddingVertical: Spacing.sm,
    boxShadow: '0 6px 24px rgba(18,35,26,0.12)',
  },
  tab: { flex: 1, alignItems: 'center', gap: 4, paddingVertical: 4 },
  dot: { width: 6, height: 6, borderRadius: 3, backgroundColor: 'transparent' },
  dotOn: { backgroundColor: Colors.orchid },
  label: { fontFamily: Fonts.medium, fontSize: 13, color: Colors.muted },
  labelOn: { fontFamily: Fonts.semibold, color: Colors.forest },
});
