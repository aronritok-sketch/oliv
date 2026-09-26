import { Anton_400Regular } from '@expo-google-fonts/anton';
import { Archivo_400Regular, Archivo_500Medium, Archivo_600SemiBold, Archivo_700Bold, useFonts } from '@expo-google-fonts/archivo';
import * as Linking from 'expo-linking';
import { DefaultTheme, Stack, ThemeProvider } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import * as WebBrowser from 'expo-web-browser';
import { useEffect } from 'react';

import { Colors, Fonts } from '@/constants/theme';
import { AuthProvider, useAuth } from '@/lib/auth';

SplashScreen.preventAutoHideAsync();

const theme = {
  ...DefaultTheme,
  colors: { ...DefaultTheme.colors, background: Colors.paper, card: Colors.paper, primary: Colors.forest, text: Colors.ink, border: Colors.line },
};

export default function RootLayout() {
  const [fontsLoaded, fontError] = useFonts({
    Anton_400Regular,
    Archivo_400Regular,
    Archivo_500Medium,
    Archivo_600SemiBold,
    Archivo_700Bold,
  });

  return (
    <ThemeProvider value={theme}>
      <AuthProvider>
        <StatusBar style="dark" />
        <Navigator fontsReady={fontsLoaded || !!fontError} />
      </AuthProvider>
    </ThemeProvider>
  );
}

function Navigator({ fontsReady }: { fontsReady: boolean }) {
  const { ready, token } = useAuth();
  const loaded = ready && fontsReady;

  useEffect(() => {
    if (loaded) SplashScreen.hideAsync();
  }, [loaded]);

  // The web checkout ends on a "Back to the app" button (oliviayoga://bookings): close the
  // in-app browser; Expo Router opens the screen itself.
  useEffect(() => {
    const sub = Linking.addEventListener('url', () => {
      try {
        WebBrowser.dismissBrowser();
      } catch {
        // Nothing open.
      }
    });
    return () => sub.remove();
  }, []);

  if (!loaded) return null;

  return (
    <Stack
      screenOptions={{
        headerTintColor: Colors.forest,
        headerTitleStyle: { fontFamily: Fonts.semibold, color: Colors.ink },
        headerBackButtonDisplayMode: 'minimal',
        headerShadowVisible: false,
        contentStyle: { backgroundColor: Colors.paper },
      }}>
      <Stack.Protected guard={!!token}>
        <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
        <Stack.Screen name="class/[id]" options={{ title: 'Class' }} />
      </Stack.Protected>
      <Stack.Protected guard={!token}>
        <Stack.Screen name="login" options={{ headerShown: false }} />
      </Stack.Protected>
    </Stack>
  );
}
