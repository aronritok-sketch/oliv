import * as WebBrowser from 'expo-web-browser';
import { StyleSheet, View } from 'react-native';

import { Screen } from '@/components/screen';
import { Button, Card, Loading, Notice, Row, T } from '@/components/ui';
import { Colors, Fonts, Radius, Spacing } from '@/constants/theme';
import type { Pass } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { plural, shortDate } from '@/lib/format';
import { useLoad } from '@/lib/use-load';

const KIND: Record<string, string> = { class: 'Studio classes', online: 'Online classes', private: 'Private sessions' };

export default function Passes() {
  const { refreshMe, me } = useAuth();
  const { error, loading, refreshing, refresh } = useLoad(refreshMe);

  const open = (url?: string) => url && WebBrowser.openBrowserAsync(url).then(() => refreshMe());

  return (
    <Screen eyebrow="Passes & membership" title="Passes" refreshing={refreshing} onRefresh={refresh} testID="passes">
      {loading && !me ? <Loading /> : null}
      {error ? <Notice tone="error" text={error} /> : null}

      {me ? (
        <>
          <Row style={{ alignItems: 'stretch' }}>
            <Balance n={me.balances.class} label="Studio classes" testID="balance-class" />
            <Balance n={me.balances.online} label="Online classes" testID="balance-online" tone="lilac" />
            {me.balances.private ? <Balance n={me.balances.private} label="Private" /> : null}
          </Row>
          {me.balances.class > 0 ? (
            <T variant="small" style={{ color: Colors.muted }}>
              No online pass? One studio class covers {plural(me.studio.online_per_credit, 'online class', 'online classes')}.
            </T>
          ) : null}

          {me.membership ? (
            <Card tone="forest" style={{ gap: Spacing.xs }}>
              <T variant="label" style={{ color: Colors.sage }}>
                Membership
              </T>
              <T variant="title" style={{ color: Colors.paper }} testID="membership-name">
                {me.membership.name}
              </T>
              <T style={{ color: Colors.mist }}>
                {me.membership.remaining === null
                  ? 'Unlimited classes'
                  : `${plural(me.membership.remaining, 'class', 'classes')} left this period`}
                {me.membership.period_end ? ` · ${me.membership.cancel_at_period_end ? 'ends' : 'renews'} ${shortDate(me.membership.period_end)}` : ''}
              </T>
              {me.membership.status === 'past_due' ? (
                <Notice tone="error" text="The last payment didn’t go through. Please update your card on the website." />
              ) : null}
              <Button kind="secondary" title="Manage membership" onPress={() => open(me.links.membership)} style={{ marginTop: Spacing.sm }} />
            </Card>
          ) : null}

          <View style={{ gap: Spacing.sm }}>
            <T variant="label">Your passes</T>
            {me.passes.length === 0 ? (
              <Card>
                <T variant="strong">No active passes</T>
                <T variant="small" style={{ color: Colors.muted }}>
                  Class packs are the easiest way to practise regularly, and they save you money.
                </T>
              </Card>
            ) : (
              me.passes.map((p) => <PassCard key={p.id} pass={p} />)
            )}
          </View>

          {(me.coupons ?? []).map((c) => (
            <Card key={c.code} tone="lilac" style={{ gap: 2 }}>
              <T variant="label" style={{ color: Colors.moss }}>
                {c.birthday ? 'Birthday gift' : 'Your code'}
              </T>
              <T style={styles.code} testID="coupon-code">
                {c.code}
              </T>
              <T variant="small">
                {c.label}
                {c.expires ? ` · until ${shortDate(c.expires)}` : ''} · filled in when you pay by card on the website
              </T>
            </Card>
          ))}

          {me.raffle ? (
            <Card testID="raffle">
              <T variant="label">Loyalty draw</T>
              <Row>
                <T style={styles.balanceN}>{me.raffle.tickets}</T>
                <T variant="strong">{me.raffle.tickets === 1 ? 'ticket' : 'tickets'}</T>
              </Row>
              <T variant="small" style={{ color: Colors.muted }}>
                Every class you come to in {me.raffle.label} is one ticket. On {me.raffle.draw} one is drawn for {me.raffle.prize || 'a free pass'}.
              </T>
            </Card>
          ) : null}

          <Button title="Buy a pass" onPress={() => open(me.links.passes)} testID="buy-pass" />
          {!me.membership ? <Button kind="secondary" title="Become a member" onPress={() => open(me.links.membership)} /> : null}
          <Button kind="ghost" title="Gift a class to a friend" onPress={() => open(me.links.gifts)} />
        </>
      ) : null}
    </Screen>
  );
}

function Balance({ n, label, tone = 'mist', testID }: { n: number; label: string; tone?: 'mist' | 'lilac'; testID?: string }) {
  return (
    <View style={[styles.balance, tone === 'lilac' && { backgroundColor: '#EFE4FB' }]} testID={testID}>
      <T style={styles.balanceN}>{n}</T>
      <T variant="small" style={{ color: Colors.moss }}>
        {label}
      </T>
    </View>
  );
}

function PassCard({ pass: p }: { pass: Pass }) {
  const pct = p.credits_total ? Math.max(0, Math.min(1, p.credits_left / p.credits_total)) : 0;
  return (
    <Card>
      <Row>
        <View style={{ flex: 1, gap: 2 }}>
          <T variant="strong">{p.name}</T>
          <T variant="small" style={{ color: Colors.muted }}>
            {KIND[p.kind] ?? p.kind}
            {p.expires ? ` · valid until ${shortDate(p.expires)}` : ''}
          </T>
        </View>
        <T style={styles.left}>
          {p.credits_left}
          <T variant="small" style={{ color: Colors.muted }}>
            /{p.credits_total}
          </T>
        </T>
      </Row>
      <View style={styles.bar}>
        <View style={[styles.fill, { width: `${pct * 100}%` }, p.kind === 'online' && { backgroundColor: Colors.lilac }]} />
      </View>
    </Card>
  );
}

const styles = StyleSheet.create({
  balance: { flex: 1, backgroundColor: Colors.mist, borderRadius: Radius.lg, padding: Spacing.lg, gap: 2 },
  balanceN: { fontFamily: Fonts.display, fontSize: 44, lineHeight: 50, color: Colors.forest },
  left: { fontFamily: Fonts.bold, fontSize: 22, color: Colors.forest },
  code: { fontFamily: Fonts.display, fontSize: 28, letterSpacing: 2, color: Colors.moss },
  bar: { height: 8, borderRadius: 4, backgroundColor: Colors.mist, overflow: 'hidden' },
  fill: { height: 8, borderRadius: 4, backgroundColor: Colors.forest },
});
