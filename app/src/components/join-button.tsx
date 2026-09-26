import * as Linking from 'expo-linking';
import { StyleSheet, View } from 'react-native';

import { Button, T } from './ui';

import { Colors, Radius, Spacing } from '@/constants/theme';

/** Online bookings: the Zoom link (null = not online; '' = not open yet, 60 minutes before). */
export function JoinButton({ url }: { url: string | null }) {
  if (url === null) return null;
  if (!url) {
    return (
      <View style={styles.soon}>
        <T variant="small" style={{ color: Colors.moss }}>
          The “Join live” button appears here 60 minutes before class.
        </T>
      </View>
    );
  }
  return <Button testID="join-live" kind="live" title="Join the live class" onPress={() => Linking.openURL(url)} />;
}

const styles = StyleSheet.create({
  soon: { backgroundColor: Colors.mist, borderRadius: Radius.md, padding: Spacing.md },
});
