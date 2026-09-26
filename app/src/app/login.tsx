import * as WebBrowser from 'expo-web-browser';
import { useRef, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button, Notice, T } from '@/components/ui';
import { Colors, Fonts, MaxContentWidth, Radius, Spacing } from '@/constants/theme';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { apiBase } from '@/lib/config';

export default function Login() {
  const { login } = useAuth();
  const insets = useSafeAreaInsets();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const passwordRef = useRef<TextInput>(null);

  async function submit() {
    if (!email.trim() || !password) {
      setError('Enter your email and password.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await login(email, password);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Something went wrong. Please try again.');
      setBusy(false);
    }
  }

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: Colors.forest }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView
        contentContainerStyle={[styles.wrap, { paddingTop: insets.top + Spacing.xxl, paddingBottom: insets.bottom + Spacing.xl }]}
        keyboardShouldPersistTaps="handled">
        <View style={styles.hero}>
          <T variant="label" style={{ color: Colors.sage }}>Olivia Kovács</T>
          <T variant="display" style={styles.brand}>
            Yoga
          </T>
          <T style={{ color: Colors.mist }}>Book classes, join live online and keep your passes in your pocket.</T>
        </View>

        <View style={styles.form}>
          <T variant="title">Log in</T>
          <T variant="small" style={{ color: Colors.muted }}>
            Use the same email and password as on the website.
          </T>
          {error ? <Notice tone="error" text={error} testID="login-error" /> : null}
          <View style={{ gap: 6 }}>
            <T variant="label">Email</T>
            <TextInput
              testID="login-email"
              style={styles.input}
              value={email}
              onChangeText={setEmail}
              autoCapitalize="none"
              autoCorrect={false}
              autoComplete="email"
              keyboardType="email-address"
              textContentType="username"
              returnKeyType="next"
              onSubmitEditing={() => passwordRef.current?.focus()}
              placeholder="you@example.com"
              placeholderTextColor="#9AA39C"
            />
          </View>
          <View style={{ gap: 6 }}>
            <T variant="label">Password</T>
            <TextInput
              testID="login-password"
              ref={passwordRef}
              style={styles.input}
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              autoComplete="current-password"
              textContentType="password"
              returnKeyType="go"
              onSubmitEditing={submit}
            />
          </View>
          <Button testID="login-submit" title="Log in" onPress={submit} loading={busy} />
          <Button
            kind="ghost"
            title="Forgot your password?"
            onPress={() => WebBrowser.openBrowserAsync(`${apiBase()}/wp-login.php?action=lostpassword`)}
          />
          <View style={styles.divider} />
          <T variant="small" style={{ textAlign: 'center', color: Colors.muted }}>
            New to the studio?
          </T>
          <Button kind="secondary" title="Create an account" onPress={() => WebBrowser.openBrowserAsync(`${apiBase()}/account/`)} />
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  wrap: { flexGrow: 1, paddingHorizontal: Spacing.lg, gap: Spacing.xl, justifyContent: 'space-between' },
  hero: { gap: Spacing.sm, width: '100%', maxWidth: MaxContentWidth, alignSelf: 'center' },
  brand: { color: Colors.paper, fontSize: 76, lineHeight: 80 },
  form: {
    width: '100%',
    maxWidth: MaxContentWidth,
    alignSelf: 'center',
    backgroundColor: Colors.paper,
    borderRadius: Radius.lg,
    padding: Spacing.xl,
    gap: Spacing.md,
  },
  input: {
    minHeight: 50,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Colors.line,
    backgroundColor: Colors.card,
    paddingHorizontal: Spacing.lg,
    fontFamily: Fonts.regular,
    fontSize: 16,
    color: Colors.ink,
  },
  divider: { height: 1, backgroundColor: Colors.line, marginVertical: Spacing.xs },
});
