import type { PropsWithChildren } from 'react';
import { View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { OfflineBanner } from '@/components/offline-banner';
import { cn } from '@/lib/utils';

/**
 * The frame every screen sits in: the page colour, clear of the status bar, with the
 * offline banner on top. Screens that draw under the status bar themselves (the menu's
 * header) pass `underStatusBar` and place the banner where it fits.
 */
export function Screen({
  children,
  className,
  underStatusBar = false,
}: PropsWithChildren<{ className?: string; underStatusBar?: boolean }>) {
  const insets = useSafeAreaInsets();

  return (
    <View
      className={cn('bg-background flex-1', className)}
      style={underStatusBar ? undefined : { paddingTop: insets.top }}
    >
      {underStatusBar ? null : <OfflineBanner />}
      {children}
    </View>
  );
}
