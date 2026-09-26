import type { ReactNode } from 'react';
import { Platform, RefreshControl, ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { T } from './ui';

import { Colors, MaxContentWidth, Spacing } from '@/constants/theme';

/** Room for the tab bar at the bottom (native tabs on iOS, our own bar on web). */
const TAB_BAR = Platform.select({ ios: 64, web: 76, default: 72 });

/**
 * A tab screen: brand header, scrolling content centred to a readable width,
 * and pull to refresh.
 */
export function Screen({
  eyebrow,
  title,
  right,
  children,
  refreshing,
  onRefresh,
  tabs = true,
  testID,
}: {
  eyebrow?: string;
  title?: string;
  right?: ReactNode;
  children: ReactNode;
  refreshing?: boolean;
  onRefresh?: () => void;
  tabs?: boolean;
  testID?: string;
}) {
  const insets = useSafeAreaInsets();
  return (
    <ScrollView
      testID={testID}
      style={styles.scroll}
      contentInsetAdjustmentBehavior="never"
      keyboardShouldPersistTaps="handled"
      contentContainerStyle={[
        styles.content,
        { paddingTop: (title ? insets.top : 0) + Spacing.lg, paddingBottom: insets.bottom + (tabs ? TAB_BAR : 0) + Spacing.xl },
      ]}
      refreshControl={
        onRefresh ? <RefreshControl refreshing={!!refreshing} onRefresh={onRefresh} tintColor={Colors.forest} /> : undefined
      }>
      <View style={styles.inner}>
        {title ? (
          <View style={styles.header}>
            <View style={{ flex: 1, gap: 2 }}>
              {eyebrow ? <T variant="label">{eyebrow}</T> : null}
              <T variant="display" accessibilityRole="header">
                {title}
              </T>
            </View>
            {right}
          </View>
        ) : null}
        {children}
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  scroll: { flex: 1, backgroundColor: Colors.paper },
  content: { paddingHorizontal: Spacing.lg },
  inner: { width: '100%', maxWidth: MaxContentWidth, alignSelf: 'center', gap: Spacing.lg },
  header: { flexDirection: 'row', alignItems: 'flex-end', gap: Spacing.md, marginBottom: Spacing.xs },
});
