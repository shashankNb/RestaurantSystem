import "../global.css";

import { QueryClientProvider } from '@tanstack/react-query';
import { useFonts } from 'expo-font';
import { DarkTheme, DefaultTheme, Stack, ThemeProvider, type NativeStackNavigationOptions, type Theme } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { Platform } from 'react-native';

import { useSession } from '@/auth/session';
import { useColorScheme } from '@/hooks/use-color-scheme';
import { useNotificationTaps } from '@/lib/push';
import { connectQueryManagers, queryClient } from '@/lib/query-client';
import { StripeRoot } from '@/payments/StripeRoot';
import { INK, PAGE } from '@/theme/brand';
import { BrandTheme } from '@/theme/brand-theme';
import { fonts } from '@/theme/fonts';

export { ErrorBoundary } from 'expo-router';

// Opening /item/3 directly (a shared link) still has the menu underneath.
export const unstable_settings = { initialRouteName: 'index' };

void SplashScreen.preventAutoHideAsync().catch(() => undefined);

/**
 * An item's options: a bottom sheet on iOS and Android; on the web, a dialog over the menu
 * (the screen draws its own backdrop, see src/components/sheet-frame.web.tsx).
 */
const itemSheet: NativeStackNavigationOptions =
  Platform.OS === 'web'
    ? { presentation: 'transparentModal', animation: 'fade' }
    : { presentation: 'formSheet', sheetAllowedDetents: [1], sheetGrabberVisible: true, sheetCornerRadius: 16 };

/** Screen backgrounds during navigation match the page, so dark mode never flashes white. */
const navigationThemes: Record<'light' | 'dark', Theme> = {
  light: {
    ...DefaultTheme,
    colors: { ...DefaultTheme.colors, background: PAGE.light, card: PAGE.light, text: INK.light },
  },
  dark: {
    ...DarkTheme,
    colors: { ...DarkTheme.colors, background: PAGE.dark, card: PAGE.dark, text: INK.dark },
  },
};

export default function RootLayout() {
  const [fontsLoaded, fontError] = useFonts(fonts);
  const sessionRestored = useSession((state) => state.status !== 'restoring');
  const scheme = useColorScheme() === 'dark' ? 'dark' : 'light';
  const ready = (fontsLoaded || fontError !== null) && sessionRestored;

  useEffect(() => connectQueryManagers(), []);

  useEffect(() => {
    void useSession.getState().restore();
  }, []);

  useEffect(() => {
    if (ready) {
      void SplashScreen.hideAsync().catch(() => undefined);
    }
  }, [ready]);

  // iOS and Android keep the splash screen up until the fonts and any saved sign-in are
  // ready. The web renders straight away (also on the server) and swaps the fonts in.
  if (!ready && Platform.OS !== 'web') {
    return null;
  }

  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider value={navigationThemes[scheme]}>
        <BrandTheme>
          <StripeRoot>
            <StatusBar style="auto" />
            <Stack screenOptions={{ headerShown: false }}>
              <Stack.Screen name="item/[id]" options={itemSheet} />
              {/* A stray edge swipe on a kitchen tablet mustn't leave the order board. */}
              <Stack.Screen name="staff" options={{ gestureEnabled: false }} />
            </Stack>
            {/* After the navigator, so it has mounted before a notification tap navigates. */}
            <NotificationTaps />
          </StripeRoot>
        </BrandTheme>
      </ThemeProvider>
    </QueryClientProvider>
  );
}

function NotificationTaps() {
  useNotificationTaps();

  return null;
}
