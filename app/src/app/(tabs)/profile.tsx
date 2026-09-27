import Constants from 'expo-constants';
import * as WebBrowser from 'expo-web-browser';
import { useState } from 'react';
import { Pressable, StyleSheet, Switch, View } from 'react-native';

import { Screen } from '@/components/screen';
import { Button, Card, Loading, Notice, T } from '@/components/ui';
import { Colors, Radius, Spacing } from '@/constants/theme';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { plainText } from '@/lib/format';
import { useLoad } from '@/lib/use-load';

export default function Profile() {
  const { me, api, setMe, logout, refreshMe } = useAuth();
  const { error: loadError, refreshing, refresh } = useLoad(refreshMe);
  const [showWaiver, setShowWaiver] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const open = (url?: string) => url && WebBrowser.openBrowserAsync(url);

  async function acceptWaiver() {
    setBusy(true);
    try {
      setMe(await api.acceptWaiver());
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Something went wrong.');
    } finally {
      setBusy(false);
    }
  }

  async function toggleNewsletter(on: boolean) {
    try {
      setMe(await api.newsletter(on));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Something went wrong.');
    }
  }

  const links: { label: string; url?: string }[] = [
    ...(me?.links.community ? [{ label: 'Join our Facebook group', url: me.links.community }] : []),
    { label: 'Private sessions', url: me?.links.private },
    { label: 'Prices & passes', url: me?.links.pricing },
    { label: 'Gift cards', url: me?.links.gifts },
    { label: 'My account on the website', url: me?.links.account },
    { label: 'Visit the website', url: me?.links.website },
  ];

  return (
    <Screen eyebrow="Profile" title={me?.user.first_name || 'Profile'} refreshing={refreshing} onRefresh={refresh} testID="profile">
      {!me && !loadError ? <Loading /> : null}
      {!me && loadError ? <Notice tone="error" text={loadError} /> : null}
      {me ? (
        <Card>
          <T variant="heading">{me.user.name}</T>
          <T variant="small" style={{ color: Colors.muted }}>
            {me.user.email}
            {me.user.phone ? ` · ${me.user.phone}` : ''}
          </T>
          <Button kind="ghost" title="Edit details on the website" onPress={() => open(me.links.account)} style={{ alignSelf: 'flex-start', paddingHorizontal: 0 }} />
        </Card>
      ) : null}

      {me ? (
        <Card tone={me.waiver.accepted ? 'plain' : 'mist'}>
          <T variant="strong">Participation agreement</T>
          <T variant="small" style={{ color: Colors.muted }}>
            {me.waiver.accepted ? 'Accepted. Thank you!' : 'Please read and accept it before your first class.'}
          </T>
          <Pressable accessibilityRole="button" onPress={() => setShowWaiver(!showWaiver)}>
            <T variant="small" style={{ color: Colors.forest }}>
              {showWaiver ? 'Hide' : 'Read the agreement'}
            </T>
          </Pressable>
          {showWaiver ? <T variant="small">{plainText(me.waiver.text)}</T> : null}
          {!me.waiver.accepted ? <Button title="I accept" onPress={acceptWaiver} loading={busy} testID="waiver-accept" /> : null}
          {error ? <Notice tone="error" text={error} /> : null}
        </Card>
      ) : null}

      {me ? (
        <Card>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: Spacing.md }}>
            <View style={{ flex: 1, gap: 2 }}>
              <T variant="strong">Newsletter</T>
              <T variant="small" style={{ color: Colors.muted }}>
                New classes and events, a few times a month at most.
              </T>
            </View>
            <Switch
              testID="newsletter"
              value={!!me.user.newsletter}
              onValueChange={toggleNewsletter}
              trackColor={{ true: Colors.forest, false: Colors.line }}
              accessibilityLabel="Newsletter"
            />
          </View>
        </Card>
      ) : null}

      <View style={styles.list}>
        {links.map((l, i) => (
          <Pressable
            key={l.label}
            accessibilityRole="link"
            onPress={() => open(l.url)}
            style={({ pressed }) => [styles.item, i > 0 && styles.itemLine, pressed && { backgroundColor: Colors.mist }]}>
            <T style={{ flex: 1 }}>{l.label}</T>
            <T style={{ color: Colors.sage }}>›</T>
          </Pressable>
        ))}
      </View>

      {me?.studio.cancel_policy ? (
        <View style={{ gap: 4 }}>
          <T variant="label">Cancellation policy</T>
          <T variant="small" style={{ color: Colors.muted }}>
            {plainText(me.studio.cancel_policy)}
          </T>
        </View>
      ) : null}

      <Button testID="logout" kind="danger" title="Log out" onPress={logout} />
      <T variant="small" style={{ textAlign: 'center', color: Colors.muted }}>
        Version {Constants.expoConfig?.version ?? '1.0.0'}
      </T>
    </Screen>
  );
}

const styles = StyleSheet.create({
  list: { backgroundColor: Colors.card, borderRadius: Radius.lg, borderWidth: 1, borderColor: Colors.line, overflow: 'hidden' },
  item: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: Spacing.lg, paddingVertical: 16 },
  itemLine: { borderTopWidth: 1, borderTopColor: Colors.line },
});
